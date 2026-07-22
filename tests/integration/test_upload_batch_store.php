<?php
/**
 * Integration tests for managed upload aggregate storage and capacity.
 *
 * Contract: Managed Aggregate Contract
 * Contract: Managed staged images
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';

function eforms_test_batch_secret( $byte ) {
    return rtrim( strtr( base64_encode( str_repeat( $byte, Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
}

function eforms_test_batch_binding( $token, $field_key, $accept_until ) {
    return array(
        'raw_token' => $token,
        'form_id' => 'virtual-quote',
        'instance_id' => 'instance-fixture-1',
        'field_key' => $field_key,
        'accept_until' => $accept_until,
    );
}

function eforms_upload_batch_store_assert_protected_dir( $dir, $label ) {
    foreach ( array( PrivateDir::INDEX_FILENAME, PrivateDir::HTACCESS_FILENAME, PrivateDir::WEBCONFIG_FILENAME ) as $file ) {
        eforms_test_assert( is_file( rtrim( $dir, '/\\' ) . '/' . $file ), 'Managed upload storage should protect ' . $label . ': ' . $file );
    }
}

function eforms_upload_batch_store_capacity_health( $uploads_dir ) {
    $lease = PrivateDir::acquire_write_lease( $uploads_dir );
    eforms_test_assert( $lease instanceof PrivateDirLease, 'Capacity health fixtures should acquire the lifecycle lease.' );
    try {
        return UploadBatchStore::capacity_health( $uploads_dir, $lease );
    } finally {
        $lease->release();
    }
}

function eforms_upload_batch_store_assert_capacity_first( $aggregate_lock_path, $capacity_lock_path, $operation, $result_path, $label ) {
    $aggregate_lock = fopen( $aggregate_lock_path, 'r+b' );
    eforms_test_assert( is_resource( $aggregate_lock ) && flock( $aggregate_lock, LOCK_EX ), $label . ' fixture should hold the aggregate lock.' );
    $pid = pcntl_fork();
    eforms_test_assert( $pid >= 0, $label . ' fixture should fork.' );
    if ( $pid === 0 ) {
        $result = call_user_func( $operation );
        file_put_contents( $result_path, json_encode( $result ) );
        exit( 0 );
    }

    $capacity_observed = false;
    $deadline = microtime( true ) + 2.0;
    do {
        $probe = ManagedCapacityStore::acquire_lock( $capacity_lock_path, true, true, true );
        if ( $probe === false ) {
            $capacity_observed = true;
            break;
        }
        flock( $probe, LOCK_UN );
        fclose( $probe );
        usleep( 10000 );
    } while ( microtime( true ) < $deadline );

    flock( $aggregate_lock, LOCK_UN );
    fclose( $aggregate_lock );
    pcntl_waitpid( $pid, $status );
    $result = is_file( $result_path ) ? json_decode( file_get_contents( $result_path ), true ) : null;
    @unlink( $result_path );
    eforms_test_assert( $capacity_observed, $label . ' should acquire the object-budget lock before waiting for the aggregate lock.' );
    eforms_test_assert( pcntl_wifexited( $status ) && pcntl_wexitstatus( $status ) === 0 && is_array( $result ) && ! empty( $result['ok'] ), $label . ' should finish after aggregate contention clears.' );
    return $result;
}

$uploads_dir = eforms_test_setup_uploads( 'eforms-upload-batch-store' );
$now = 1700000000;
$capacity_shape_uploads = eforms_test_setup_uploads( 'eforms-capacity-record-shapes' );
$capacity_shape_private = PrivateDir::ensure( $capacity_shape_uploads );
eforms_test_assert( ! empty( $capacity_shape_private['ok'] ), 'Capacity record shape fixtures should create private storage.' );
$capacity_shape_lock = $capacity_shape_private['path'] . '/' . UploadBatchStore::CAPACITY_LOCK_FILENAME;
$capacity_shape_path = $capacity_shape_private['path'] . '/' . UploadBatchStore::CAPACITY_FILENAME;
file_put_contents( $capacity_shape_lock, '' );
chmod( $capacity_shape_lock, 0600 );
mkdir( $capacity_shape_path, 0700 );
$nonregular_capacity = eforms_upload_batch_store_capacity_health( $capacity_shape_uploads );
eforms_test_assert(
    empty( $nonregular_capacity['ok'] ) && $nonregular_capacity['reason'] === 'capacity_invalid',
    'The managed-capacity facade should reject a non-regular record before attempting to read bytes.'
);
rmdir( $capacity_shape_path );
if ( function_exists( 'posix_mkfifo' ) ) {
    eforms_test_assert( posix_mkfifo( $capacity_shape_path, 0600 ), 'The capacity FIFO fixture should be created.' );
    $fifo_capacity = eforms_upload_batch_store_capacity_health( $capacity_shape_uploads );
    eforms_test_assert(
        empty( $fifo_capacity['ok'] ) && $fifo_capacity['reason'] === 'capacity_invalid',
        'The managed-capacity facade should reject a FIFO without opening its blocking stream.'
    );
    unlink( $capacity_shape_path );
}
eforms_test_remove_tree( $capacity_shape_uploads );
$field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 1048576,
    'max_files' => 3,
    'max_total_bytes' => 3145728,
);
$secret = eforms_test_batch_secret( "\x11" );
$other_secret = eforms_test_batch_secret( "\x22" );
$binding = eforms_test_batch_binding( 'token-fixture-01', 'project_photos', $now + 3600 );

$barrier_uploads = eforms_test_setup_uploads( 'eforms-upload-purge-barrier' );
$barrier_private = PrivateDir::ensure( $barrier_uploads );
file_put_contents( $barrier_private['path'] . '/' . PrivateDir::PURGE_MARKER_FILENAME, "purged\n" );
$barrier_create = UploadBatchStore::create_batch( $binding, $secret, $field, $barrier_uploads, $now );
eforms_test_assert( empty( $barrier_create['ok'] ) && $barrier_create['reason'] === 'upload_lifecycle_unavailable', 'A create request queued behind uninstall should stop at the durable purge barrier.' );
eforms_test_assert( PrivateDir::resume_after_install( $barrier_uploads ) === true, 'Activation should clear the purge barrier under the retained lifecycle lock.' );
eforms_test_assert( is_file( $barrier_private['path'] . '/' . PrivateDir::LIFECYCLE_LOCK_FILENAME ), 'Reactivation should retain the lifecycle synchronization inode.' );
$resumed_create = UploadBatchStore::create_batch( $binding, $secret, $field, $barrier_uploads, $now );
eforms_test_assert( ! empty( $resumed_create['ok'] ), 'Managed batch creation should resume only after activation clears the purge barrier.' );
file_put_contents( $barrier_private['path'] . '/' . PrivateDir::PURGE_MARKER_FILENAME, "purged\n" );
$barrier_status = UploadBatchStore::status( $resumed_create['batch']['batch_id'], $secret, $barrier_uploads, $now );
eforms_test_assert( empty( $barrier_status['ok'] ) && $barrier_status['reason'] === 'managed_purged', 'Aggregate-only staged reads should also stop at the purge barrier.' );
eforms_test_remove_tree( $barrier_uploads );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$health_uploads = eforms_test_setup_uploads( 'eforms-upload-capacity-health' );
$health_private = PrivateDir::ensure( $health_uploads );
eforms_test_assert( ! empty( $health_private['ok'] ), 'Capacity-health fixture should create the private root.' );
$health_lock_path = $health_private['path'] . '/' . UploadBatchStore::CAPACITY_LOCK_FILENAME;
eforms_test_assert( ! file_exists( $health_lock_path ), 'Capacity-health fixture should start without a managed capacity lock.' );
$health = eforms_upload_batch_store_capacity_health( $health_uploads );
eforms_test_assert(
    ! empty( $health['ok'] )
        && $health['capacity']['total_bytes'] === 0
        && $health['capacity']['file_bytes'] === 0
        && ! file_exists( $health_lock_path )
        && ! file_exists( $health_private['path'] . '/' . UploadBatchStore::CAPACITY_FILENAME ),
    'Inspect-only capacity health should return fresh-empty accounting without creating lock or capacity files.'
);
$health_capacity_path = $health_private['path'] . '/' . UploadBatchStore::CAPACITY_FILENAME;
file_put_contents( $health_capacity_path, '{"total_bytes":0,"reservations":[]}' );
$unlocked_health = eforms_upload_batch_store_capacity_health( $health_uploads );
eforms_test_assert( empty( $unlocked_health['ok'] ) && $unlocked_health['reason'] === 'capacity_lock_failed', 'Capacity health should not inspect an existing capacity record without its lock.' );
unlink( $health_capacity_path );
mkdir( $health_private['path'] . '/' . UploadBatchStore::STAGED_DIR, 0700 );
mkdir( $health_private['path'] . '/' . UploadBatchStore::STAGED_DIR . '/aa', 0700 );
$unlocked_tree_health = eforms_upload_batch_store_capacity_health( $health_uploads );
eforms_test_assert( empty( $unlocked_tree_health['ok'] ) && $unlocked_tree_health['reason'] === 'capacity_lock_failed', 'Capacity health should not scan managed trees without the capacity lock.' );
eforms_test_remove_tree( $health_uploads );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$expected_id = 'wwI-Pb508vu7ZfEWAhfoHhjVi5AvZgWnChbtwh-DUWo';
$fixture_field = array(
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 20971520,
    'max_files' => 24,
    'max_total_bytes' => 314572800,
);
eforms_test_assert(
    UploadBatchStore::derive_batch_id(
        $binding['raw_token'],
        $binding['form_id'],
        $binding['instance_id'],
        $binding['field_key'],
        UploadBatchStore::policy_fingerprint( $fixture_field )
    ) === $expected_id,
    'The store should reproduce the canonical full HMAC fixture.'
);
eforms_test_assert(
    UploadBatchStore::canonical_policy( $fixture_field )['accept'] === array( 'image' ),
    'The managed policy should retain the sole staged image token.'
);
$single_file_field = array(
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 20971520,
    'max_files' => 1,
    'max_total_bytes' => 20971520,
);
$single_file_policy = UploadBatchStore::canonical_policy( $single_file_field );
eforms_test_assert(
    $single_file_policy['max_file_bytes'] === Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' )
        && $single_file_policy['max_total_bytes'] === Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' ),
    'Capping the per-file artifact limit should cap the dependent aggregate limit to a valid policy.'
);
$single_file_created = UploadBatchStore::create_batch(
    eforms_test_batch_binding( 'token-single-capped-policy', 'single_capped_photos', $now + 3600 ),
    $secret,
    $single_file_field,
    $uploads_dir,
    $now
);
eforms_test_assert( ! empty( $single_file_created['ok'] ), 'A valid one-file policy above the product artifact ceiling should create with its effective capped limits.' );

$created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert( $created['ok'] === true, 'A valid exact binding should create one managed batch.' );
$staged_root = $uploads_dir . '/eforms-private/' . UploadBatchStore::STAGED_DIR;
eforms_upload_batch_store_assert_protected_dir( $staged_root, 'staged root' );
$created_batch_path = $staged_root . '/' . Helpers::h2( $created['batch']['batch_id'] ) . '/' . $created['batch']['batch_id'];
$created_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $created_batch_path );
eforms_test_assert(
    is_file( $created_lock_path )
        && dirname( $created_lock_path ) === dirname( $created_batch_path )
        && ! file_exists( $created_batch_path . '/' . UploadBatchStore::LOCK_FILENAME ),
    'Staged aggregates should use a sibling lock so finalization can rename the aggregate on Windows.'
);
$staged_index_path = $staged_root . '/' . PrivateDir::INDEX_FILENAME;
unlink( $staged_index_path );
$unprotected_status = UploadBatchStore::status( $created['batch']['batch_id'], $secret, $uploads_dir, $now );
eforms_test_assert( empty( $unprotected_status['ok'] ) && ! file_exists( $staged_index_path ), 'Status reads should fail closed without recreating missing staged protection files.' );
file_put_contents( $staged_index_path, PrivateDir::INDEX_CONTENT );
chmod( $staged_index_path, 0600 );
unlink( $created_lock_path );
$unlocked_status = UploadBatchStore::status( $created['batch']['batch_id'], $secret, $uploads_dir, $now );
eforms_test_assert( empty( $unlocked_status['ok'] ) && $unlocked_status['reason'] === 'batch_lock_failed' && ! file_exists( $created_lock_path ), 'Status reads should fail closed without recreating a missing aggregate lock.' );
file_put_contents( $created_lock_path, '' );
chmod( $created_lock_path, 0600 );
$removed_heic_field = $field;
$removed_heic_field['accept'] = array( 'image', 'heic' );
$removed_heic_created = UploadBatchStore::create_batch(
    eforms_test_batch_binding( 'token-heic-policy', 'heic_photos', $now + 3600 ),
    $secret,
    $removed_heic_field,
    $uploads_dir,
    $now
);
eforms_test_assert( empty( $removed_heic_created['ok'] ), 'A managed batch should reject the removed staged HEIC opt-in token.' );
$expired_create = UploadBatchStore::create_batch(
    eforms_test_batch_binding( 'token-expired-while-waiting', 'expired_photos', $now ),
    $secret,
    $field,
    $uploads_dir,
    $now
);
eforms_test_assert( empty( $expired_create['ok'] ) && $expired_create['reason'] === 'token_expired', 'A token that expires before lock-held creation should keep the terminal expiry reason.' );
$batch_id = $created['batch']['batch_id'];
eforms_test_assert( preg_match( FormProtocol::upload_batch_id_pattern(), $batch_id ) === 1, 'The batch ID should be a full unpadded 256-bit base64url value.' );
eforms_test_assert( $created['batch']['accept_until'] === $now + 3600, 'Batch acceptance should use the token expiry.' );
eforms_test_assert(
    $created['batch']['delete_after'] === $now + 3600 + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    'Open cleanup should use the fixed staged grace period.'
);

$retried = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert( $retried['ok'] === true && $retried['batch']['batch_id'] === $batch_id, 'A same-secret create retry should find the deterministic aggregate without an index.' );
$conflict = UploadBatchStore::create_batch( $binding, $other_secret, $field, $uploads_dir, $now );
eforms_test_assert( $conflict['ok'] === false && $conflict['code'] === 'EFORMS_ERR_TOKEN', 'A different create secret should conflict without replacing the aggregate.' );

$partial_binding = eforms_test_batch_binding( 'token-partial-create', 'partial_photos', $now + 3600 );
$partial_id = UploadBatchStore::derive_batch_id(
    $partial_binding['raw_token'],
    $partial_binding['form_id'],
    $partial_binding['instance_id'],
    $partial_binding['field_key'],
    UploadBatchStore::policy_fingerprint( $field )
);
$partial_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $partial_id ) . '/' . $partial_id;
mkdir( $partial_path, 0700, true );
file_put_contents( $partial_path . '.lock', '' );
$partial_created = UploadBatchStore::create_batch( $partial_binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert(
    ! empty( $partial_created['ok'] )
        && $partial_created['batch']['batch_id'] === $partial_id
        && is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $partial_path ) ),
    'A deterministic retry should safely finish a legacy adjacent-lock partial aggregate with its sibling lock.'
);

if ( function_exists( 'symlink' ) ) {
    $linked_shard_uploads = eforms_test_setup_uploads( 'eforms-upload-linked-shard' );
    $linked_binding = eforms_test_batch_binding( 'token-linked-shard', 'linked_photos', $now + 3600 );
    $linked_batch_id = UploadBatchStore::derive_batch_id(
        $linked_binding['raw_token'],
        $linked_binding['form_id'],
        $linked_binding['instance_id'],
        $linked_binding['field_key'],
        UploadBatchStore::policy_fingerprint( $field )
    );
    $linked_root = PrivateDir::protected_subdir( $linked_shard_uploads, UploadBatchStore::STAGED_DIR, true );
    $outside_shard = eforms_test_tmp_root( 'eforms-linked-managed-shard-target' );
    mkdir( $outside_shard, 0700, true );
    symlink( $outside_shard, $linked_root . '/' . Helpers::h2( $linked_batch_id ) );
    $linked_create = UploadBatchStore::create_batch( $linked_binding, $secret, $field, $linked_shard_uploads, $now );
    eforms_test_assert( empty( $linked_create['ok'] ) && $linked_create['reason'] === 'staged_shard_unavailable', 'Managed batch creation should reject symlinked staged shard directories.' );
    eforms_test_assert( count( scandir( $outside_shard ) ) === 2, 'Managed batch creation should not materialize aggregates through a symlinked staged shard.' );
    eforms_test_remove_tree( $linked_shard_uploads );
    eforms_test_remove_tree( $outside_shard );
}

$temp_partial_binding = eforms_test_batch_binding( 'token-partial-temp-create', 'partial_temp_photos', $now + 3600 );
$temp_partial_id = UploadBatchStore::derive_batch_id(
    $temp_partial_binding['raw_token'],
    $temp_partial_binding['form_id'],
    $temp_partial_binding['instance_id'],
    $temp_partial_binding['field_key'],
    UploadBatchStore::policy_fingerprint( $field )
);
$temp_partial_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $temp_partial_id ) . '/' . $temp_partial_id;
mkdir( $temp_partial_path, 0700, true );
file_put_contents( $temp_partial_path . '.lock', '' );
$abandoned_manifest_temp = $temp_partial_path . '/.manifest.json.0123456789abcdef.tmp';
file_put_contents( $abandoned_manifest_temp, '{"version":' );
$temp_partial_created = UploadBatchStore::create_batch( $temp_partial_binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert( ! empty( $temp_partial_created['ok'] ) && $temp_partial_created['batch']['batch_id'] === $temp_partial_id, 'A deterministic retry should recover an owner-created initial-manifest temp file.' );
eforms_test_assert( ! file_exists( $abandoned_manifest_temp ), 'A recovered initial-manifest temp file should not remain beside the committed manifest.' );

$unknown_partial_binding = eforms_test_batch_binding( 'token-partial-unknown-temp', 'partial_unknown_photos', $now + 3600 );
$unknown_partial_id = UploadBatchStore::derive_batch_id(
    $unknown_partial_binding['raw_token'],
    $unknown_partial_binding['form_id'],
    $unknown_partial_binding['instance_id'],
    $unknown_partial_binding['field_key'],
    UploadBatchStore::policy_fingerprint( $field )
);
$unknown_partial_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $unknown_partial_id ) . '/' . $unknown_partial_id;
mkdir( $unknown_partial_path, 0700, true );
file_put_contents( $unknown_partial_path . '.lock', '' );
$unknown_manifest_temp = $unknown_partial_path . '/.manifest.json.not-owner.tmp';
file_put_contents( $unknown_manifest_temp, 'residue' );
$unknown_partial_created = UploadBatchStore::create_batch( $unknown_partial_binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert( empty( $unknown_partial_created['ok'] ) && $unknown_partial_created['reason'] === 'batch_files_unavailable', 'A manifest-like filename outside the atomic writer format should still fail closed.' );
eforms_test_assert( is_file( $unknown_manifest_temp ), 'An unrecognized manifest-like file should be preserved for operator inspection.' );
eforms_test_remove_tree( $unknown_partial_path );

$no_lock_partial_binding = eforms_test_batch_binding( 'token-partial-no-lock', 'partial_no_lock_photos', $now + 3600 );
$no_lock_partial_id = UploadBatchStore::derive_batch_id(
    $no_lock_partial_binding['raw_token'],
    $no_lock_partial_binding['form_id'],
    $no_lock_partial_binding['instance_id'],
    $no_lock_partial_binding['field_key'],
    UploadBatchStore::policy_fingerprint( $field )
);
$no_lock_partial_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $no_lock_partial_id ) . '/' . $no_lock_partial_id;
mkdir( $no_lock_partial_path, 0700, true );
touch(
    $no_lock_partial_path,
    $now - Anchors::get( 'TOKEN_TTL_MAX' ) - Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ) - 10
);
$no_lock_gc = UploadBatchStore::gc_aggregates( 'staged', $uploads_dir, $now, 10 );
eforms_test_assert(
    ! empty( $no_lock_gc['ok'] )
        && $no_lock_gc['deleted'] >= 1
        && ! file_exists( $no_lock_partial_path )
        && ! file_exists( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $no_lock_partial_path ) ),
    'Staged GC should create the sibling lock needed to delete a stale crash partial that stopped before lock creation.'
);

$pagination_uploads = eforms_test_setup_uploads( 'eforms-staged-lock-pagination' );
$pagination_root = PrivateDir::protected_subdir( $pagination_uploads, UploadBatchStore::STAGED_DIR, true );
$pagination_binding = eforms_test_batch_binding( 'token-pagination-lock', 'pagination_photos', $now + 3600 );
$pagination_id = UploadBatchStore::derive_batch_id(
    $pagination_binding['raw_token'],
    $pagination_binding['form_id'],
    $pagination_binding['instance_id'],
    $pagination_binding['field_key'],
    UploadBatchStore::policy_fingerprint( $field )
);
$pagination_shard = $pagination_root . '/' . Helpers::h2( $pagination_id );
$pagination_path = $pagination_shard . '/' . $pagination_id;
mkdir( $pagination_path, 0700, true );
$malformed_lock_dir = $pagination_shard . '/.0000000000000000000000000000000000000000000' . UploadBatchStore::LOCK_FILENAME;
mkdir( $malformed_lock_dir, 0700 );
touch(
    $pagination_path,
    $now - Anchors::get( 'TOKEN_TTL_MAX' ) - Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ) - 10
);
$pagination_gc = UploadBatchStore::gc_aggregates( 'staged', $pagination_uploads, $now, 1 );
eforms_test_assert(
    ! empty( $pagination_gc['ok'] )
        && $pagination_gc['scanned'] === 1
        && $pagination_gc['deleted'] === 1
        && ! file_exists( $pagination_path )
        && is_dir( $malformed_lock_dir ),
    'Staged aggregate enumeration should not let sibling lock names consume a bounded cleanup page.'
);
eforms_test_remove_tree( $pagination_uploads );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$private_dir = $uploads_dir . '/eforms-private';
$batch_path = $private_dir . '/staged/' . Helpers::h2( $batch_id ) . '/' . $batch_id;
$manifest_path = $batch_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
$manifest_json = file_get_contents( $manifest_path );
$manifest = json_decode( $manifest_json, true );
eforms_test_assert( strpos( $manifest_json, $binding['raw_token'] ) === false && strpos( $manifest_json, $secret ) === false, 'The manifest must not store raw token or batch-secret credentials.' );
eforms_test_assert( $manifest['binding']['token_digest'] === hash( 'sha256', $binding['raw_token'] ), 'The manifest should store only the token digest.' );
eforms_test_assert( $manifest['batch_secret_digest'] === hash( 'sha256', str_repeat( "\x11", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), 'The manifest should store only the decoded secret digest.' );
eforms_test_assert( ( fileperms( $batch_path ) & 0777 ) === 0700, 'Managed aggregate directories should be private.' );
eforms_test_assert( ( fileperms( $manifest_path ) & 0777 ) === 0600, 'Managed manifests should be private.' );
$legacy_manifest = $manifest;
$legacy_manifest['version'] = 2;
file_put_contents( $manifest_path, json_encode( $legacy_manifest ) );
$legacy_status = UploadBatchStore::status( $batch_id, $secret, $uploads_dir, $now );
eforms_test_assert( empty( $legacy_status['ok'] ) && $legacy_status['reason'] === 'manifest_invalid', 'The target store should reject a version-2 manifest without a compatibility reader.' );
file_put_contents( $manifest_path, $manifest_json );

$unknown_manifest_fields = array();
$unknown_manifest_fields['provider URL'] = $manifest;
$unknown_manifest_fields['provider URL']['provider_url'] = 'https://objects.example.invalid/private';
$unknown_manifest_fields['preview state'] = $manifest;
$unknown_manifest_fields['preview state']['preview_state'] = 'ready';
$unknown_manifest_fields['processing state'] = $manifest;
$unknown_manifest_fields['processing state']['processing_state'] = 'pending';
$unknown_manifest_fields['binding field'] = $manifest;
$unknown_manifest_fields['binding field']['binding']['storage_provider'] = 'local';
$unknown_manifest_fields['policy field'] = $manifest;
$unknown_manifest_fields['policy field']['policy']['preview_profile'] = 'standard';
foreach ( $unknown_manifest_fields as $label => $unknown_manifest ) {
    file_put_contents( $manifest_path, json_encode( $unknown_manifest ) );
    $unknown_status = UploadBatchStore::status( $batch_id, $secret, $uploads_dir, $now );
    eforms_test_assert(
        empty( $unknown_status['ok'] ) && $unknown_status['reason'] === 'manifest_invalid',
        'The version-4 manifest reader should reject the unknown ' . $label . '.'
    );
}
file_put_contents( $manifest_path, $manifest_json );

$wrong_status = UploadBatchStore::status( $batch_id, $other_secret, $uploads_dir, $now );
eforms_test_assert( $wrong_status['ok'] === false, 'The batch ID alone should not authorize status.' );

$png_bytes = eforms_test_fixture_bytes( 'staged-landscape.png' );
$item_sequence = 0;
$new_item = function () use ( $uploads_dir, $png_bytes, &$item_sequence ) {
    $item_sequence++;
    $source = eforms_test_write_file( $uploads_dir, 'source-' . $item_sequence . '.png', $png_bytes );
    return array(
        'tmp_name' => $source,
        'original_name' => '../Customer Photo.png',
        'size' => filesize( $source ),
        'error' => UPLOAD_ERR_OK,
    );
};
$options = array(
    'now' => $now,
    'memory_limit' => -1,
    'execution_limit' => 0,
    'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
);
$unicode_uploads = eforms_test_setup_uploads( 'eforms-unicode-display-name' );
$unicode_binding = eforms_test_batch_binding( 'token-unicode-display-name', 'unicode_photos', $now + 3600 );
$unicode_secret = eforms_test_batch_secret( "\x29" );
$unicode_created = UploadBatchStore::create_batch( $unicode_binding, $unicode_secret, $field, $unicode_uploads, $now );
$unicode_name = str_repeat( "\u{754C}", Anchors::get( 'MANAGED_DISPLAY_NAME_MAX_CHARS' ) - 4 ) . '.png';
$unicode_intent = UploadBatchStore::authorize_intent(
    $unicode_created['batch']['batch_id'],
    $unicode_secret,
    'unicode_name_one',
    0,
    $unicode_name,
    strlen( $png_bytes ),
    'image/png',
    0,
    $unicode_uploads,
    array( 'now' => $now + 1, 'free_bytes' => $options['free_bytes'] )
);
$unicode_status = UploadBatchStore::status( $unicode_created['batch']['batch_id'], $unicode_secret, $unicode_uploads, $now + 2 );
eforms_test_assert(
    ! empty( $unicode_intent['ok'] )
        && ! empty( $unicode_status['ok'] )
        && $unicode_status['batch']['items'] === array(),
    'A valid display name at the character limit must remain readable after its multibyte UTF-8 intent is persisted.'
);
eforms_test_remove_tree( $unicode_uploads );
$transient_bytes = 100;
$transient_free_bytes = Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + ( 4 * $transient_bytes ) - 1;
$transient_record = array(
    'version' => UploadBatchStore::CAPACITY_VERSION,
    'total_bytes' => 0,
    'reservations' => array(),
    'releases' => array(),
    'updated_at' => $now,
);
$first_transient = ManagedCapacityStore::reserve(
    $transient_record,
    hash( 'sha256', "transient-one" ),
    'transient-intent-one',
    'local/aa/transient-one',
    'transient-batch-one',
    'transient-upload-one',
    $transient_bytes,
    $transient_free_bytes,
    Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
    Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ),
    $transient_bytes,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_LOCAL,
    UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY
);
eforms_test_assert(
    ! empty( $first_transient['ok'] )
        && $first_transient['record']['reservations'][ hash( 'sha256', "transient-one" ) ]['transient_bytes'] === $transient_bytes,
    'A pre-transfer local reservation must retain its temporary multipart allocation claim.'
);
$reused_transient = ManagedCapacityStore::reserve(
    $first_transient['record'],
    hash( 'sha256', "transient-one" ),
    'transient-intent-one',
    'local/aa/transient-one',
    'transient-batch-one',
    'transient-upload-one',
    $transient_bytes,
    Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + $transient_bytes,
    Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
    Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ),
    $transient_bytes,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_LOCAL,
    UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY
);
eforms_test_assert(
    empty( $reused_transient['ok'] ) && $reused_transient['reason'] === 'free_space_reserve',
    'An authorization retry without a materialized PHP temp file must retain the complete transient headroom claim.'
);
$materialized_transient = ManagedCapacityStore::reserve(
    $first_transient['record'],
    hash( 'sha256', "transient-one" ),
    'transient-intent-one',
    'local/aa/transient-one',
    'transient-batch-one',
    'transient-upload-one',
    $transient_bytes,
    Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + $transient_bytes,
    Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
    Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ),
    $transient_bytes,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_LOCAL,
    UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY,
    $transient_bytes
);
eforms_test_assert(
    ! empty( $materialized_transient['ok'] ),
    'An exact multipart retry may subtract only an explicitly materialized PHP temporary copy.'
);
$second_transient = ManagedCapacityStore::reserve(
    $first_transient['record'],
    hash( 'sha256', "transient-two" ),
    'transient-intent-two',
    'local/bb/transient-two',
    'transient-batch-two',
    'transient-upload-two',
    $transient_bytes,
    $transient_free_bytes,
    Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
    Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ),
    $transient_bytes,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_LOCAL,
    UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY
);
eforms_test_assert(
    empty( $second_transient['ok'] ) && $second_transient['reason'] === 'free_space_reserve',
    'Concurrent reservations must include every durable multipart temporary-byte claim when preserving free space.'
);
$remote_without_local_floor = ManagedCapacityStore::reserve(
    $transient_record,
    hash( 'sha256', "remote-without-local-floor" ),
    'remote-intent-without-local-floor',
    'worker/aa/remote-without-local-floor',
    'remote-batch-without-local-floor',
    'remote-upload-without-local-floor',
    $transient_bytes,
    0,
    Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
    Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ),
    0,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    str_repeat( 'a', 64 )
);
eforms_test_assert(
    ! empty( $remote_without_local_floor['ok'] )
        && $remote_without_local_floor['record']['total_bytes'] === $transient_bytes,
    'A direct Worker reservation must enforce the managed object budget without inheriting the WordPress filesystem floor.'
);
$local_after_remote = ManagedCapacityStore::reserve(
    $remote_without_local_floor['record'],
    hash( 'sha256', 'local-after-remote' ),
    'local-intent-after-remote',
    'local/cc/local-after-remote',
    'local-batch-after-remote',
    'local-upload-after-remote',
    $transient_bytes,
    Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + ( 2 * $transient_bytes ),
    Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
    Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ),
    $transient_bytes,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_LOCAL,
    UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY
);
eforms_test_assert(
    ! empty( $local_after_remote['ok'] ) && $local_after_remote['record']['total_bytes'] === 2 * $transient_bytes,
    'Remote reservations must remain in the global object budget without consuming the local filesystem projection.'
);
$remote_floor_uploads = eforms_test_setup_uploads( 'eforms-remote-without-local-floor' );
$remote_floor_binding = eforms_test_batch_binding( 'token-remote-without-local-floor', 'remote_without_local_floor', $now + 3600 );
$remote_floor_secret = eforms_test_batch_secret( "\x2b" );
$remote_floor_created = UploadBatchStore::create_batch(
    $remote_floor_binding,
    $remote_floor_secret,
    $field,
    $remote_floor_uploads,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    str_repeat( 'b', 64 )
);
$remote_floor_intent = UploadBatchStore::authorize_intent(
    $remote_floor_created['batch']['batch_id'],
    $remote_floor_secret,
    'remote_floor_one',
    0,
    'remote-floor.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $remote_floor_uploads,
    array(
        'now' => $now + 1,
        'free_bytes' => 0,
        'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
    )
);
eforms_test_assert(
    ! empty( $remote_floor_intent['ok'] ),
    'The Worker authorization path must not consult the WordPress filesystem floor.'
);
eforms_test_remove_tree( $remote_floor_uploads );
$mixed_health_uploads = eforms_test_setup_uploads( 'eforms-mixed-store-capacity-health' );
$mixed_local_binding = eforms_test_batch_binding( 'token-mixed-health-local', 'mixed_health_local', $now + 3600 );
$mixed_local_secret = eforms_test_batch_secret( "\x2c" );
$mixed_local_created = UploadBatchStore::create_batch(
    $mixed_local_binding,
    $mixed_local_secret,
    $field,
    $mixed_health_uploads,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_LOCAL
);
$mixed_local_source = eforms_test_write_file( $mixed_health_uploads, 'mixed-health-local.png', $png_bytes );
$mixed_local_put = UploadBatchStore::put_item(
    $mixed_local_created['batch']['batch_id'],
    $mixed_local_secret,
    'mixed_local_one',
    0,
    array(
        'tmp_name' => $mixed_local_source,
        'original_name' => 'mixed-health-local.png',
        'size' => strlen( $png_bytes ),
        'error' => UPLOAD_ERR_OK,
    ),
    $mixed_health_uploads,
    array( 'now' => $now + 1, 'completion_now' => $now + 1, 'free_bytes' => $options['free_bytes'] )
);
$mixed_remote_binding = eforms_test_batch_binding( 'token-mixed-health-remote', 'mixed_health_remote', $now + 3600 );
$mixed_remote_secret = eforms_test_batch_secret( "\x2d" );
$mixed_remote_created = UploadBatchStore::create_batch(
    $mixed_remote_binding,
    $mixed_remote_secret,
    $field,
    $mixed_health_uploads,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    str_repeat( 'c', 64 )
);
$mixed_remote_authorized = UploadBatchStore::authorize_intent(
    $mixed_remote_created['batch']['batch_id'],
    $mixed_remote_secret,
    'mixed_remote_one',
    0,
    'mixed-health-remote.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $mixed_health_uploads,
    array(
        'now' => $now + 1,
        'free_bytes' => 0,
        'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
    )
);
$mixed_remote_intent = $mixed_remote_authorized['intent'];
$mixed_remote_completed = UploadBatchStore::complete_receipt(
    $mixed_remote_created['batch']['batch_id'],
    $mixed_remote_secret,
    'mixed_remote_one',
    array(
        'intent_id' => $mixed_remote_intent['intent_id'],
        'batch_id' => $mixed_remote_created['batch']['batch_id'],
        'upload_id' => 'mixed_remote_one',
        'ordinal' => 0,
        'object_key' => $mixed_remote_intent['object_key'],
        'object_version' => 'mixed-health-remote-v1',
        'etag' => 'mixed-health-remote-etag-v1',
        'bytes' => strlen( $png_bytes ),
        'mime' => 'image/png',
        'width' => 3,
        'height' => 2,
        'policy_fingerprint' => $mixed_remote_intent['policy_fingerprint'],
        'expires_at' => $mixed_remote_intent['expires_at'] + Anchors::get( 'WORKER_RECEIPT_TTL_SECONDS' ),
    ),
    $mixed_health_uploads,
    $mixed_remote_intent['expires_at'] + 10
);
$mixed_health = eforms_upload_batch_store_capacity_health( $mixed_health_uploads );
eforms_test_assert(
    ! empty( $mixed_local_put['ok'] )
        && ! empty( $mixed_remote_completed['ok'] )
        && ! empty( $mixed_health['ok'] )
        && $mixed_health['capacity']['consistent']
        && $mixed_health['capacity']['file_bytes'] === strlen( $png_bytes )
        && $mixed_health['capacity']['authority_bytes'] === strlen( $png_bytes )
        && $mixed_health['capacity']['total_bytes'] === 2 * strlen( $png_bytes ),
    'Capacity health must reconcile coexisting local and Worker aggregates from each persisted artifact-store owner.'
);
$mixed_local_batch_id = $mixed_local_created['batch']['batch_id'];
$mixed_local_manifest_path = $mixed_health_uploads . '/eforms-private/staged/' . Helpers::h2( $mixed_local_batch_id ) . '/' . $mixed_local_batch_id . '/' . UploadBatchStore::MANIFEST_FILENAME;
$mixed_local_manifest = json_decode( file_get_contents( $mixed_local_manifest_path ), true );
$mixed_local_artifact = $mixed_local_manifest['items']['mixed_local_one'];
$mixed_local_path = LocalArtifactStore::locate(
    $mixed_health_uploads,
    $mixed_local_artifact['object_key'],
    $mixed_local_artifact['object_version']
);
eforms_test_assert( is_string( $mixed_local_path ) && unlink( $mixed_local_path ), 'The mixed-store fixture should remove only its local artifact.' );
$mixed_missing_local_health = eforms_upload_batch_store_capacity_health( $mixed_health_uploads );
eforms_test_assert(
    ! empty( $mixed_missing_local_health['ok'] )
        && empty( $mixed_missing_local_health['capacity']['consistent'] )
        && $mixed_missing_local_health['capacity']['authority_bytes'] === strlen( $png_bytes ),
    'A missing retained local artifact must fail mixed health even while the coexisting Worker authority remains valid.'
);
eforms_test_remove_tree( $mixed_health_uploads );
$local_pretransfer_uploads = eforms_test_setup_uploads( 'eforms-local-pretransfer-capacity' );
$local_pretransfer_binding = eforms_test_batch_binding( 'token-local-pretransfer', 'local_pretransfer_photos', $now + 3600 );
$local_pretransfer_secret = eforms_test_batch_secret( "\x2a" );
$local_pretransfer_created = UploadBatchStore::create_batch(
    $local_pretransfer_binding,
    $local_pretransfer_secret,
    $field,
    $local_pretransfer_uploads,
    $now
);
$local_pretransfer_bytes = strlen( $png_bytes );
$local_pretransfer_intent = UploadBatchStore::authorize_intent(
    $local_pretransfer_created['batch']['batch_id'],
    $local_pretransfer_secret,
    'local_pretransfer_one',
    0,
    'local-pretransfer.png',
    $local_pretransfer_bytes,
    'image/png',
    $local_pretransfer_bytes,
    $local_pretransfer_uploads,
    array(
        'now' => $now + 1,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + ( 2 * $local_pretransfer_bytes ),
    )
);
$local_pretransfer_capacity = eforms_test_managed_capacity_record( $local_pretransfer_uploads );
$local_pretransfer_reservation_id = hash( 'sha256', $local_pretransfer_created['batch']['batch_id'] . "\0local_pretransfer_one" );
eforms_test_assert(
    ! empty( $local_pretransfer_intent['ok'] )
        && $local_pretransfer_capacity['reservations'][ $local_pretransfer_reservation_id ]['transient_bytes'] === $local_pretransfer_bytes,
    'The aggregate facade must forward an explicit pre-transfer local multipart allocation into durable capacity accounting.'
);
$invalid_transient_intent = UploadBatchStore::authorize_intent(
    $local_pretransfer_created['batch']['batch_id'],
    $local_pretransfer_secret,
    'local_pretransfer_invalid',
    1,
    'local-pretransfer-invalid.png',
    $local_pretransfer_bytes,
    'image/png',
    $local_pretransfer_bytes - 1,
    $local_pretransfer_uploads,
    array(
        'now' => $now + 2,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + ( 2 * $local_pretransfer_bytes ),
    )
);
eforms_test_assert(
    empty( $invalid_transient_intent['ok'] ) && $invalid_transient_intent['reason'] === 'item_identity_invalid',
    'Pre-transfer transient allocation must be either absent or exactly one declared artifact copy.'
);
eforms_test_remove_tree( $local_pretransfer_uploads );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
if ( function_exists( 'symlink' ) ) {
    $unsafe_root_uploads = eforms_test_setup_uploads( 'eforms-artifact-unsafe-root' );
    $unsafe_private = PrivateDir::ensure( $unsafe_root_uploads );
    $unsafe_target = eforms_test_tmp_root( 'eforms-artifact-unsafe-root-target' );
    mkdir( $unsafe_target, 0700, true );
    file_put_contents( $unsafe_target . '/preserve.artifact', 'untrusted' );
    symlink( $unsafe_target, $unsafe_private['path'] . '/' . LocalArtifactStore::ROOT_DIR );
    $unsafe_reconcile = UploadBatchStore::reconcile_capacity( $unsafe_root_uploads, $now, $now );
    eforms_test_assert(
        empty( $unsafe_reconcile['ok'] )
            && $unsafe_reconcile['reason'] === 'capacity_reconcile_failed'
            && LocalArtifactStore::total_bytes( $unsafe_root_uploads ) === null
            && LocalArtifactStore::bytes_for_key( $unsafe_root_uploads, LocalArtifactStore::object_key( 'unsafe-batch', 'unsafe-intent' ) ) === null,
        'Unsafe existing artifact roots must fail reconciliation and accounting closed instead of reporting zero bytes.'
    );
    eforms_test_assert( is_file( $unsafe_target . '/preserve.artifact' ), 'Unsafe-root reconciliation must not traverse or mutate the linked target.' );
    eforms_test_remove_tree( $unsafe_root_uploads );
    eforms_test_remove_tree( $unsafe_target );
    $GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
}

$late_write_uploads = eforms_test_setup_uploads( 'eforms-artifact-late-write' );
$late_write_binding = eforms_test_batch_binding( 'token-artifact-late-write', 'late_write_photos', $now + 3600 );
$late_write_secret = eforms_test_batch_secret( "\x3a" );
$late_write_created = UploadBatchStore::create_batch( $late_write_binding, $late_write_secret, $field, $late_write_uploads, $now );
$late_write_batch_id = $late_write_created['batch']['batch_id'];
$late_write_intent = UploadBatchStore::authorize_intent(
    $late_write_batch_id,
    $late_write_secret,
    'late_write_one',
    0,
    'late-write.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $late_write_uploads,
    array( 'now' => $now + 10, 'free_bytes' => $options['free_bytes'] )
);
$late_write_source = eforms_test_write_file( $late_write_uploads, 'late-write-source.png', $png_bytes );
$late_write_deleted = null;
if ( function_exists( 'pcntl_fork' ) && function_exists( 'pcntl_waitpid' ) ) {
    $late_write_setup_lease = PrivateDir::acquire_write_lease( $late_write_uploads );
    $late_write_root = PrivateDir::leased_subdir( $late_write_setup_lease, LocalArtifactStore::ROOT_DIR, true, true );
    $late_write_parts = explode( '/', $late_write_intent['intent']['object_key'] );
    $late_write_shard = $late_write_root . '/' . $late_write_parts[1];
    $late_write_directory = $late_write_shard . '/' . $late_write_parts[2];
    mkdir( $late_write_shard, 0700 );
    mkdir( $late_write_directory, 0700 );
    $late_write_setup_lease->release();
    $late_write_lock_path = $late_write_directory . '/' . LocalArtifactStore::LOCK_FILENAME;
    $late_write_ready_path = $late_write_uploads . '/late-write-lock-ready';
    $late_write_release_path = $late_write_uploads . '/late-write-lock-release';
    $late_write_delete_result_path = $late_write_uploads . '/late-write-delete-result.json';
    $late_write_locker_pid = pcntl_fork();
    eforms_test_assert( $late_write_locker_pid >= 0, 'The in-flight artifact-lock fixture should fork its lock holder.' );
    if ( $late_write_locker_pid === 0 ) {
        $handle = fopen( $late_write_lock_path, 'c+b' );
        if ( ! is_resource( $handle ) || ! flock( $handle, LOCK_EX ) ) {
            exit( 1 );
        }
        chmod( $late_write_lock_path, 0600 );
        file_put_contents( $late_write_ready_path, 'ready' );
        $deadline = microtime( true ) + 2;
        while ( ! is_file( $late_write_release_path ) && microtime( true ) < $deadline ) {
            usleep( 10000 );
        }
        flock( $handle, LOCK_UN );
        fclose( $handle );
        exit( is_file( $late_write_release_path ) ? 0 : 2 );
    }
    $late_write_deadline = microtime( true ) + 2;
    while ( ! is_file( $late_write_ready_path ) && microtime( true ) < $late_write_deadline ) {
        usleep( 10000 );
    }
    eforms_test_assert( is_file( $late_write_ready_path ), 'The in-flight artifact-lock fixture should establish its object lock.' );
    $late_write_delete_pid = pcntl_fork();
    eforms_test_assert( $late_write_delete_pid >= 0, 'The in-flight deletion fixture should fork.' );
    if ( $late_write_delete_pid === 0 ) {
        $result = UploadBatchStore::delete_item( $late_write_batch_id, $late_write_secret, 'late_write_one', $late_write_uploads, $now + 20 );
        file_put_contents( $late_write_delete_result_path, json_encode( $result ) );
        exit( 0 );
    }
    usleep( 100000 );
    eforms_test_assert( ! is_file( $late_write_delete_result_path ), 'Deletion must not confirm absence while an in-flight writer owns the object lock.' );
    file_put_contents( $late_write_release_path, 'release' );
    pcntl_waitpid( $late_write_locker_pid, $late_write_locker_status );
    pcntl_waitpid( $late_write_delete_pid, $late_write_delete_status );
    $late_write_deleted = is_file( $late_write_delete_result_path ) ? json_decode( file_get_contents( $late_write_delete_result_path ), true ) : null;
    eforms_test_assert(
        pcntl_wifexited( $late_write_locker_status )
            && pcntl_wexitstatus( $late_write_locker_status ) === 0
            && pcntl_wifexited( $late_write_delete_status )
            && pcntl_wexitstatus( $late_write_delete_status ) === 0,
        'The in-flight writer and deletion fixtures should exit cleanly after object-lock release.'
    );
    @unlink( $late_write_ready_path );
    @unlink( $late_write_release_path );
    @unlink( $late_write_delete_result_path );
} else {
    $late_write_deleted = UploadBatchStore::delete_item( $late_write_batch_id, $late_write_secret, 'late_write_one', $late_write_uploads, $now + 20 );
}
$late_write_lease = PrivateDir::acquire_write_lease( $late_write_uploads );
$late_write_result = LocalArtifactStore::write(
    $late_write_lease,
    $late_write_intent['intent']['object_key'],
    $late_write_source,
    strlen( $png_bytes )
);
eforms_test_assert(
    ! empty( $late_write_intent['ok'] )
        && ! empty( $late_write_deleted['ok'] )
        && empty( $late_write_result['ok'] )
        && $late_write_result['reason'] === 'artifact_deleted'
        && LocalArtifactStore::bytes_for_key( $late_write_uploads, $late_write_intent['intent']['object_key'] ) === 0,
    'A delayed writer must observe the persistent deletion fence and cannot materialize bytes after capacity release.'
);
$late_write_redeleted = LocalArtifactStore::delete( $late_write_lease, $late_write_intent['intent']['object_key'] );
$late_write_lease->release();
eforms_test_assert( $late_write_redeleted, 'Repeated physical deletion should preserve the durable local deletion fence idempotently.' );
$late_write_health = eforms_upload_batch_store_capacity_health( $late_write_uploads );
eforms_test_assert( ! empty( $late_write_health['ok'] ) && $late_write_health['capacity']['consistent'], 'Deletion fencing must leave physical and capacity accounting consistent.' );
eforms_test_remove_tree( $late_write_uploads );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$retry_write_uploads = eforms_test_setup_uploads( 'eforms-artifact-write-retry' );
$retry_write_binding = eforms_test_batch_binding( 'token-artifact-write-retry', 'retry_write_photos', $now + 3600 );
$retry_write_secret = eforms_test_batch_secret( "\x3b" );
$retry_write_created = UploadBatchStore::create_batch( $retry_write_binding, $retry_write_secret, $field, $retry_write_uploads, $now );
$retry_write_authorized = UploadBatchStore::authorize_intent(
    $retry_write_created['batch']['batch_id'],
    $retry_write_secret,
    'retry_write_one',
    0,
    'retry-write.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $retry_write_uploads,
    array( 'now' => $now + 5, 'free_bytes' => $options['free_bytes'] )
);
$retry_write_private = PrivateDir::ensure( $retry_write_uploads );
$retry_write_root = $retry_write_private['path'] . '/' . LocalArtifactStore::ROOT_DIR;
file_put_contents( $retry_write_root, 'temporarily unavailable' );
$retry_write_source = eforms_test_write_file( $retry_write_uploads, 'retry-write-source.png', $png_bytes );
$retry_write_first = UploadBatchStore::put_item(
    $retry_write_created['batch']['batch_id'],
    $retry_write_secret,
    'retry_write_one',
    0,
    array( 'tmp_name' => $retry_write_source, 'original_name' => 'retry-write.png', 'size' => strlen( $png_bytes ), 'error' => UPLOAD_ERR_OK ),
    $retry_write_uploads,
    array( 'now' => $now + 10, 'free_bytes' => $options['free_bytes'] )
);
$retry_write_path = $retry_write_private['path'] . '/staged/' . Helpers::h2( $retry_write_created['batch']['batch_id'] ) . '/' . $retry_write_created['batch']['batch_id'];
$retry_write_manifest = json_decode( file_get_contents( $retry_write_path . '/' . UploadBatchStore::MANIFEST_FILENAME ), true );
eforms_test_assert(
    ! empty( $retry_write_authorized['ok'] )
        && empty( $retry_write_first['ok'] )
        && $retry_write_first['reason'] === 'artifact_root_unavailable'
        && isset( $retry_write_manifest['intents']['retry_write_one'] )
        && ! isset( $retry_write_manifest['tombstones']['retry_write_one'] ),
    'A transient artifact-write failure should preserve the exact retryable intent without tombstoning it.'
);
unlink( $retry_write_root );
$retry_write_source = eforms_test_write_file( $retry_write_uploads, 'retry-write-source-2.png', $png_bytes );
$retry_write_second = UploadBatchStore::put_item(
    $retry_write_created['batch']['batch_id'],
    $retry_write_secret,
    'retry_write_one',
    0,
    array( 'tmp_name' => $retry_write_source, 'original_name' => 'retry-write.png', 'size' => strlen( $png_bytes ), 'error' => UPLOAD_ERR_OK ),
    $retry_write_uploads,
    array( 'now' => $now + 20, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert( ! empty( $retry_write_second['ok'] ), 'The same upload ID should succeed after transient local storage readiness is restored.' );
eforms_test_remove_tree( $retry_write_uploads );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$reservation_retry_uploads = eforms_test_setup_uploads( 'eforms-reservation-intent-retry' );
$reservation_retry_binding = eforms_test_batch_binding( 'token-reservation-intent-retry', 'reservation_retry_photos', $now + 3600 );
$reservation_retry_secret = eforms_test_batch_secret( "\x3c" );
$reservation_retry_created = UploadBatchStore::create_batch( $reservation_retry_binding, $reservation_retry_secret, $field, $reservation_retry_uploads, $now );
$reservation_retry_batch_id = $reservation_retry_created['batch']['batch_id'];
$reservation_retry_first = UploadBatchStore::authorize_intent(
    $reservation_retry_batch_id,
    $reservation_retry_secret,
    'reservation_retry_one',
    0,
    'original-name.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $reservation_retry_uploads,
    array( 'now' => $now + 10, 'free_bytes' => $options['free_bytes'] )
);
$reservation_retry_private = $reservation_retry_uploads . '/eforms-private';
$reservation_retry_path = $reservation_retry_private . '/staged/' . Helpers::h2( $reservation_retry_batch_id ) . '/' . $reservation_retry_batch_id;
$reservation_retry_manifest_path = $reservation_retry_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
$reservation_retry_manifest = json_decode( file_get_contents( $reservation_retry_manifest_path ), true );
unset( $reservation_retry_manifest['intents']['reservation_retry_one'] );
file_put_contents( $reservation_retry_manifest_path, json_encode( $reservation_retry_manifest ) );
$reservation_retry_changed = UploadBatchStore::authorize_intent(
    $reservation_retry_batch_id,
    $reservation_retry_secret,
    'reservation_retry_one',
    0,
    'changed-name.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $reservation_retry_uploads,
    array( 'now' => $now + 20, 'free_bytes' => $options['free_bytes'] )
);
$reservation_retry_after = json_decode( file_get_contents( $reservation_retry_manifest_path ), true );
eforms_test_assert(
    ! empty( $reservation_retry_first['ok'] )
        && empty( $reservation_retry_changed['ok'] )
        && $reservation_retry_changed['reason'] === 'capacity_reservation_conflict'
        && empty( $reservation_retry_after['intents'] ),
    'A durable reservation whose manifest write was lost must reject a changed retry intent identity.'
);
eforms_test_remove_tree( $reservation_retry_uploads );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$missing_reservation_uploads = eforms_test_setup_uploads( 'eforms-missing-intent-reservation' );
$missing_reservation_binding = eforms_test_batch_binding( 'token-missing-intent-reservation', 'missing_reservation_photos', $now + 3600 );
$missing_reservation_secret = eforms_test_batch_secret( "\x3d" );
$missing_reservation_created = UploadBatchStore::create_batch( $missing_reservation_binding, $missing_reservation_secret, $field, $missing_reservation_uploads, $now );
$missing_reservation_batch_id = $missing_reservation_created['batch']['batch_id'];
$missing_reservation_control_source = eforms_test_write_file( $missing_reservation_uploads, 'missing-reservation-control.png', $png_bytes );
$missing_reservation_control = UploadBatchStore::put_item(
    $missing_reservation_batch_id,
    $missing_reservation_secret,
    'missing_reservation_control',
    1,
    array( 'tmp_name' => $missing_reservation_control_source, 'original_name' => 'control.png', 'size' => strlen( $png_bytes ), 'error' => UPLOAD_ERR_OK ),
    $missing_reservation_uploads,
    array( 'now' => $now + 5, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert( ! empty( $missing_reservation_control['ok'] ), 'The missing-reservation fixture should retain one normally accounted control artifact.' );
$missing_reservation_intent = UploadBatchStore::authorize_intent(
    $missing_reservation_batch_id,
    $missing_reservation_secret,
    'missing_reservation_one',
    0,
    'missing-reservation.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $missing_reservation_uploads,
    array( 'now' => $now + 10, 'free_bytes' => $options['free_bytes'] )
);
$missing_reservation_private = $missing_reservation_uploads . '/eforms-private';
$missing_reservation_capacity_path = $missing_reservation_private . '/' . UploadBatchStore::CAPACITY_FILENAME;
$missing_reservation_record = ManagedCapacityStore::read( $missing_reservation_capacity_path, UploadBatchStore::CAPACITY_VERSION, $now + 20 );
$missing_reservation_id = hash( 'sha256', $missing_reservation_batch_id . "\0" . 'missing_reservation_one' );
$missing_reservation_record['total_bytes'] -= $missing_reservation_record['reservations'][ $missing_reservation_id ]['bytes'];
unset( $missing_reservation_record['reservations'][ $missing_reservation_id ] );
eforms_test_assert( ManagedCapacityStore::write( $missing_reservation_capacity_path, $missing_reservation_record ), 'The missing-reservation fixture should persist a syntactically valid older capacity snapshot.' );
$missing_reservation_reauthorization = UploadBatchStore::authorize_intent(
    $missing_reservation_batch_id,
    $missing_reservation_secret,
    'missing_reservation_one',
    0,
    'missing-reservation.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $missing_reservation_uploads,
    array( 'now' => $now + 20, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert(
    empty( $missing_reservation_reauthorization['ok'] )
        && $missing_reservation_reauthorization['reason'] === 'capacity_reservation_missing',
    'Exact intent reauthorization must fail before transfer when its durable capacity reservation is absent.'
);
$missing_reservation_source = eforms_test_write_file( $missing_reservation_uploads, 'missing-reservation-source.png', $png_bytes );
$missing_reservation_lease = PrivateDir::acquire_write_lease( $missing_reservation_uploads );
$missing_reservation_written = LocalArtifactStore::write(
    $missing_reservation_lease,
    $missing_reservation_intent['intent']['object_key'],
    $missing_reservation_source,
    strlen( $png_bytes )
);
$missing_reservation_lease->release();
$missing_reservation_inspected = UploadPolicy::inspect_staged_artifact( $missing_reservation_written['path'], 'missing-reservation.png', $field );
$missing_reservation_completion = UploadBatchStore::complete_intent(
    $missing_reservation_batch_id,
    $missing_reservation_secret,
    'missing_reservation_one',
    $missing_reservation_intent['intent']['intent_id'],
    array(
        'object_key' => $missing_reservation_intent['intent']['object_key'],
        'object_version' => $missing_reservation_written['object_version'],
        'bytes' => $missing_reservation_inspected['bytes'],
        'mime' => $missing_reservation_inspected['mime'],
        'width' => $missing_reservation_inspected['width'],
        'height' => $missing_reservation_inspected['height'],
    ),
    $missing_reservation_uploads,
    $now + 30
);
$missing_reservation_manifest_path = $missing_reservation_private . '/staged/' . Helpers::h2( $missing_reservation_batch_id ) . '/' . $missing_reservation_batch_id . '/' . UploadBatchStore::MANIFEST_FILENAME;
$missing_reservation_manifest = json_decode( file_get_contents( $missing_reservation_manifest_path ), true );
eforms_test_assert(
    empty( $missing_reservation_completion['ok'] )
        && $missing_reservation_completion['reason'] === 'capacity_reservation_missing'
        && isset( $missing_reservation_manifest['intents']['missing_reservation_one'] )
        && ! isset( $missing_reservation_manifest['items']['missing_reservation_one'] )
        && $missing_reservation_manifest['artifact_bytes'] === strlen( $png_bytes ),
    'First completion must not commit an intent whose exact durable capacity reservation is absent.'
);
eforms_test_remove_tree( $missing_reservation_uploads );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

if ( function_exists( 'symlink' ) ) {
    $artifact_link_uploads = eforms_test_setup_uploads( 'eforms-artifact-linked-shard' );
    $artifact_link_lease = PrivateDir::acquire_write_lease( $artifact_link_uploads );
    $artifact_link_source = eforms_test_write_file( $artifact_link_uploads, 'linked-source.png', $png_bytes );
    $artifact_link_key = LocalArtifactStore::object_key( 'linked-batch', 'linked-intent' );
    $artifact_link_write = LocalArtifactStore::write( $artifact_link_lease, $artifact_link_key, $artifact_link_source, strlen( $png_bytes ) );
    $artifact_link_lease->release();
    eforms_test_assert( ! empty( $artifact_link_write['ok'] ), 'The linked-shard fixture should create one local artifact.' );
    $artifact_link_parts = explode( '/', $artifact_link_key );
    $artifact_link_root = $artifact_link_uploads . '/eforms-private/' . LocalArtifactStore::ROOT_DIR;
    $artifact_link_shard = $artifact_link_root . '/' . $artifact_link_parts[1];
    $artifact_link_outside = eforms_test_tmp_root( 'eforms-artifact-linked-shard-target' );
    mkdir( $artifact_link_outside, 0700, true );
    $artifact_link_target = $artifact_link_outside . '/shard';
    eforms_test_assert( rename( $artifact_link_shard, $artifact_link_target ) && symlink( $artifact_link_target, $artifact_link_shard ), 'The linked-shard fixture should replace only the artifact shard with a symlink.' );
    eforms_test_assert( LocalArtifactStore::locate( $artifact_link_uploads, $artifact_link_key, $artifact_link_write['object_version'] ) === '', 'Artifact reads must reject a symlinked shard before resolving the identity member.' );
    eforms_test_assert( LocalArtifactStore::bytes_for_key( $artifact_link_uploads, $artifact_link_key ) === null, 'Artifact accounting must fail closed on a symlinked shard.' );
    unlink( $artifact_link_shard );
    eforms_test_remove_tree( $artifact_link_uploads );
    eforms_test_remove_tree( $artifact_link_outside );
}

$temp_binding = eforms_test_batch_binding( 'token-temp-reconcile', 'temp_photos', $now + 3600 );
$temp_secret = eforms_test_batch_secret( "\x34" );
$temp_created = UploadBatchStore::create_batch( $temp_binding, $temp_secret, $field, $uploads_dir, $now );
$temp_intent = UploadBatchStore::authorize_intent(
    $temp_created['batch']['batch_id'],
    $temp_secret,
    'temp_one',
    0,
    'temp.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $uploads_dir,
    array( 'now' => $now + 10, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert( ! empty( $temp_intent['ok'] ), 'The temp-only reconciliation fixture should reserve one intent.' );
$temp_key_parts = explode( '/', $temp_intent['intent']['object_key'] );
$temp_root = PrivateDir::protected_subdir( $uploads_dir, LocalArtifactStore::ROOT_DIR, true );
$temp_directory = $temp_root . '/' . $temp_key_parts[1] . '/' . $temp_key_parts[2];
mkdir( $temp_directory, 0700, true );
file_put_contents( $temp_directory . '/' . LocalArtifactStore::LOCK_FILENAME, '' );
$temp_path = $temp_directory . '/.00000000-0000-4000-8000-000000000000.tmp';
file_put_contents( $temp_path, substr( $png_bytes, 0, 64 ) );
touch( $temp_path, $now );
$temp_health = eforms_upload_batch_store_capacity_health( $uploads_dir );
eforms_test_assert(
    ! empty( $temp_health['ok'] )
        && $temp_health['capacity']['consistent']
        && $temp_health['capacity']['orphaned_bytes'] === 64,
    'A recognized temp-only artifact should remain conservatively accounted before its cleanup grace expires.'
);
$temp_reconciled = UploadBatchStore::reconcile_capacity(
    $uploads_dir,
    $now + Anchors::get( 'MANAGED_UPLOAD_INTENT_TTL_SECONDS' ) + 1,
    $now + Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' ) + 1
);
eforms_test_assert(
    ! empty( $temp_reconciled['ok'] )
        && ! file_exists( $temp_path )
        && $temp_reconciled['stale_reservations_removed'] === 1,
    'Reconciliation should remove a grace-expired temp artifact and then release its stale reservation.'
);

$released_intent_uploads = eforms_test_setup_uploads( 'eforms-released-intent-delete' );
$released_intent_binding = eforms_test_batch_binding( 'token-released-intent', 'released_intent_photos', $now + 3600 );
$released_intent_secret = eforms_test_batch_secret( "\x35" );
$released_intent_created = UploadBatchStore::create_batch( $released_intent_binding, $released_intent_secret, $field, $released_intent_uploads, $now );
$released_control_source = eforms_test_write_file( $released_intent_uploads, 'released-control.png', $png_bytes );
$released_control = UploadBatchStore::put_item(
    $released_intent_created['batch']['batch_id'],
    $released_intent_secret,
    'released_control',
    0,
    array(
        'tmp_name' => $released_control_source,
        'original_name' => 'released-control.png',
        'size' => strlen( $png_bytes ),
        'error' => UPLOAD_ERR_OK,
    ),
    $released_intent_uploads,
    array( 'now' => $now + 1, 'free_bytes' => $options['free_bytes'] )
);
$released_stale_intent = UploadBatchStore::authorize_intent(
    $released_intent_created['batch']['batch_id'],
    $released_intent_secret,
    'released_stale',
    1,
    'released-stale.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $released_intent_uploads,
    array( 'now' => $now + 2, 'free_bytes' => $options['free_bytes'] )
);
$released_reconciled = UploadBatchStore::reconcile_capacity( $released_intent_uploads, $now + 3, $now + 3 );
$released_stale_deleted = UploadBatchStore::delete_item(
    $released_intent_created['batch']['batch_id'],
    $released_intent_secret,
    'released_stale',
    $released_intent_uploads,
    $now + 4
);
$released_intent_health = eforms_upload_batch_store_capacity_health( $released_intent_uploads );
eforms_test_assert(
    ! empty( $released_control['ok'] )
        && ! empty( $released_stale_intent['ok'] )
        && ! empty( $released_reconciled['ok'] )
        && ! empty( $released_stale_deleted['ok'] )
        && $released_intent_health['capacity']['consistent']
        && $released_intent_health['capacity']['total_bytes'] === strlen( $png_bytes )
        && $released_intent_health['capacity']['file_bytes'] === strlen( $png_bytes ),
    'Deleting an absent intent whose stale reservation was already reconciled must not release another committed artifact\'s bytes.'
);
eforms_test_remove_tree( $released_intent_uploads );

$put = UploadBatchStore::put_item(
    $batch_id,
    $secret,
    'upload_one',
    0,
    $new_item(),
    $uploads_dir,
    array( 'now' => $now + 10, 'completion_now' => $now + 20, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert( ! empty( $put['ok'] ), 'Local acceptance should commit one authoritative artifact without requiring image-processing readiness.' );
$manifest = json_decode( file_get_contents( $manifest_path ), true );
$stored = $manifest['items']['upload_one'];
eforms_test_assert(
    $manifest['version'] === UploadBatchStore::MANIFEST_VERSION
        && $manifest['intents'] === array()
        && $manifest['artifact_bytes'] === strlen( $png_bytes )
        && $stored['accepted_at'] === $now + 20,
    'The v3 manifest should commit one artifact and assign accepted_at from the completion clock.'
);
$artifact_path = LocalArtifactStore::locate( $uploads_dir, $stored['object_key'], $stored['object_version'] );
eforms_test_assert(
    is_file( $artifact_path )
        && filesize( $artifact_path ) === strlen( $png_bytes )
        && ( fileperms( $artifact_path ) & 0777 ) === 0600,
    'The local artifact owner should durably retain exactly one private immutable member.'
);
$committed_retry_capacity_path = $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME;
$committed_retry_record = ManagedCapacityStore::read( $committed_retry_capacity_path, UploadBatchStore::CAPACITY_VERSION, $now + 25 );
$committed_retry_reservation_id = hash( 'sha256', $batch_id . "\0upload_one" );
$committed_retry_record['reservations'][ $committed_retry_reservation_id ] = array(
    'batch_id' => $batch_id,
    'upload_id' => 'upload_one',
    'bytes' => $stored['bytes'],
    'transient_bytes' => 0,
    'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_LOCAL,
    'artifact_store_identity' => UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY,
    'cleanup_started' => false,
    'created_at' => $now + 10,
    'object_key' => LocalArtifactStore::object_key( $batch_id, 'conflicting-committed-retry' ),
);
eforms_test_assert(
    ManagedCapacityStore::write( $committed_retry_capacity_path, $committed_retry_record ),
    'The committed retry fixture should persist a syntactically valid reservation with a conflicting object identity.'
);
$conflicting_committed_retry = UploadBatchStore::authorize_intent(
    $batch_id,
    $secret,
    'upload_one',
    0,
    $stored['display_name'],
    strlen( $png_bytes ),
    $stored['mime'],
    0,
    $uploads_dir,
    array( 'now' => $now + 25, 'free_bytes' => $options['free_bytes'] )
);
$conflicting_committed_retry_after = ManagedCapacityStore::read( $committed_retry_capacity_path, UploadBatchStore::CAPACITY_VERSION, $now + 25 );
eforms_test_assert(
    empty( $conflicting_committed_retry['ok'] )
        && $conflicting_committed_retry['reason'] === 'capacity_settlement_failed'
        && isset( $conflicting_committed_retry_after['reservations'][ $committed_retry_reservation_id ] )
        && $conflicting_committed_retry_after['reservations'][ $committed_retry_reservation_id ]['object_key'] !== $stored['object_key'],
    'A committed retry must fail closed and preserve a retained reservation whose object identity conflicts with the committed artifact.'
);
$committed_retry_record['reservations'][ $committed_retry_reservation_id ]['object_key'] = $stored['object_key'];
eforms_test_assert(
    ManagedCapacityStore::write( $committed_retry_capacity_path, $committed_retry_record ),
    'The committed retry fixture should restore the exact reservation left by an interrupted capacity settlement.'
);

$retry = UploadBatchStore::put_item(
    $batch_id,
    $secret,
    'upload_one',
    0,
    $new_item(),
    $uploads_dir,
    array( 'now' => $now + 30, 'completion_now' => $now + 30, 'free_bytes' => $options['free_bytes'] )
);
$retried_manifest = json_decode( file_get_contents( $manifest_path ), true );
$artifact_members = glob( dirname( $artifact_path ) . '/*.artifact' );
$committed_retry_after = ManagedCapacityStore::read( $committed_retry_capacity_path, UploadBatchStore::CAPACITY_VERSION, $now + 30 );
eforms_test_assert(
    ! empty( $retry['ok'] )
        && $retried_manifest['items']['upload_one']['accepted_at'] === $now + 20
        && is_array( $artifact_members )
        && count( $artifact_members ) === 1
        && ! isset( $committed_retry_after['reservations'][ $committed_retry_reservation_id ] )
        && $committed_retry_after['total_bytes'] === $stored['bytes'],
    'An exact retry should be idempotent, preserve the first commit clock, and retain exactly one immutable artifact.'
);
$cleanup_uploads = eforms_test_setup_uploads( 'eforms-multipart-source-cleanup' );
$cleanup_binding = eforms_test_batch_binding( 'token-multipart-source-cleanup', 'cleanup_photos', $now + 3600 );
$cleanup_secret = eforms_test_batch_secret( "\x2b" );
$cleanup_created = UploadBatchStore::create_batch( $cleanup_binding, $cleanup_secret, $field, $cleanup_uploads, $now );
$cleanup_source_dir = $cleanup_uploads . '/locked-source';
mkdir( $cleanup_source_dir, 0700 );
$cleanup_source = eforms_test_write_file( $cleanup_source_dir, 'cleanup.png', $png_bytes );
$cleanup_permissions_changed = @chmod( $cleanup_source_dir, 0500 );
$cleanup_result = UploadBatchStore::put_item(
    $cleanup_created['batch']['batch_id'],
    $cleanup_secret,
    'cleanup_one',
    0,
    array( 'tmp_name' => $cleanup_source, 'original_name' => 'cleanup.png', 'size' => strlen( $png_bytes ), 'error' => UPLOAD_ERR_OK ),
    $cleanup_uploads,
    array( 'now' => $now + 25, 'completion_now' => $now + 25, 'free_bytes' => $options['free_bytes'] )
);
$cleanup_source_retained = file_exists( $cleanup_source );
@chmod( $cleanup_source_dir, 0700 );
eforms_test_assert(
    ! $cleanup_permissions_changed
        || ( $cleanup_source_retained
            ? empty( $cleanup_result['ok'] ) && $cleanup_result['reason'] === 'source_cleanup_failed'
            : ! empty( $cleanup_result['ok'] ) ),
    'Local multipart acceptance must not report success when its request-temporary source copy cannot be removed.'
);
eforms_test_remove_tree( $cleanup_uploads );
$changed_intent = UploadBatchStore::authorize_intent(
    $batch_id,
    $secret,
    'upload_one',
    0,
    'different.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $uploads_dir,
    array( 'now' => $now + 30, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert( empty( $changed_intent['ok'] ) && $changed_intent['reason'] === 'upload_id_conflict', 'Changed reauthorization bindings should fail without disclosing stored details.' );

if ( function_exists( 'pcntl_fork' ) && function_exists( 'pcntl_waitpid' ) ) {
    $lock_binding = eforms_test_batch_binding( 'token-lock-order', 'lock_photos', $now + 3600 );
    $lock_secret = eforms_test_batch_secret( "\x36" );
    $lock_created = UploadBatchStore::create_batch( $lock_binding, $lock_secret, $field, $uploads_dir, $now );
    $lock_batch_id = $lock_created['batch']['batch_id'];
    $lock_batch_path = $private_dir . '/staged/' . Helpers::h2( $lock_batch_id ) . '/' . $lock_batch_id;
    $lock_aggregate_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $lock_batch_path );
    $lock_capacity_path = $private_dir . '/' . UploadBatchStore::CAPACITY_LOCK_FILENAME;
    $lock_intent = eforms_upload_batch_store_assert_capacity_first(
        $lock_aggregate_path,
        $lock_capacity_path,
        function () use ( $lock_batch_id, $lock_secret, $uploads_dir, $png_bytes, $options, $now ) {
            return UploadBatchStore::authorize_intent(
                $lock_batch_id,
                $lock_secret,
                'lock_one',
                0,
                'lock.png',
                strlen( $png_bytes ),
                'image/png',
                0,
                $uploads_dir,
                array( 'now' => $now + 50, 'free_bytes' => $options['free_bytes'] )
            );
        },
        $uploads_dir . '/lock-order-authorize.json',
        'Intent authorization under contention'
    );
    $lock_source = eforms_test_write_file( $uploads_dir, 'lock-order-source.png', $png_bytes );
    $lock_lease = PrivateDir::acquire_write_lease( $uploads_dir );
    eforms_test_assert( $lock_lease instanceof PrivateDirLease, 'The lock-order fixture should acquire the lifecycle lease.' );
    $lock_written = LocalArtifactStore::write( $lock_lease, $lock_intent['intent']['object_key'], $lock_source, strlen( $png_bytes ) );
    eforms_test_assert( ! empty( $lock_written['ok'] ), 'The lock-order fixture should materialize its authorized artifact.' );
    $lock_inspected = UploadPolicy::inspect_staged_artifact( $lock_written['path'], 'lock.png', $field );
    eforms_test_assert( ! empty( $lock_inspected['ok'] ), 'The lock-order fixture should inspect its authoritative artifact.' );
    $lock_lease->release();
    $lock_facts = array(
        'object_key' => $lock_intent['intent']['object_key'],
        'object_version' => $lock_written['object_version'],
        'bytes' => $lock_inspected['bytes'],
        'mime' => $lock_inspected['mime'],
        'width' => $lock_inspected['width'],
        'height' => $lock_inspected['height'],
    );
    eforms_upload_batch_store_assert_capacity_first(
        $lock_aggregate_path,
        $lock_capacity_path,
        function () use ( $lock_batch_id, $lock_secret, $lock_intent, $lock_facts, $uploads_dir, $now ) {
            return UploadBatchStore::complete_intent( $lock_batch_id, $lock_secret, 'lock_one', $lock_intent['intent']['intent_id'], $lock_facts, $uploads_dir, $now + 60 );
        },
        $uploads_dir . '/lock-order-complete.json',
        'Intent completion under contention'
    );
    $lock_capacity_after_commit = eforms_test_managed_capacity_record( $uploads_dir );
    $lock_reservation_id = hash( 'sha256', $lock_batch_id . "\0" . 'lock_one' );
    $lock_completion_retry = UploadBatchStore::complete_intent(
        $lock_batch_id,
        $lock_secret,
        'lock_one',
        $lock_intent['intent']['intent_id'],
        $lock_facts,
        $uploads_dir,
        $now + 61
    );
    eforms_test_assert(
        ! isset( $lock_capacity_after_commit['reservations'][ $lock_reservation_id ] )
            && ! empty( $lock_completion_retry['ok'] ),
        'An exact already-committed completion retry should remain successful after its reservation was settled.'
    );
    eforms_upload_batch_store_assert_capacity_first(
        $lock_aggregate_path,
        $lock_capacity_path,
        function () use ( $lock_batch_id, $lock_secret, $uploads_dir, $now ) {
            return UploadBatchStore::delete_item( $lock_batch_id, $lock_secret, 'lock_one', $uploads_dir, $now + 70 );
        },
        $uploads_dir . '/lock-order-delete.json',
        'Item deletion under contention'
    );
}

$pending_binding = eforms_test_batch_binding( 'token-pending-intent', 'pending_photos', $now + 3600 );
$pending_secret = eforms_test_batch_secret( "\x35" );
$pending_created = UploadBatchStore::create_batch( $pending_binding, $pending_secret, $field, $uploads_dir, $now );
$pending_batch_id = $pending_created['batch']['batch_id'];
$pending = UploadBatchStore::authorize_intent(
    $pending_batch_id,
    $pending_secret,
    'pending_one',
    0,
    'pending.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $uploads_dir,
    array( 'now' => $now + 10, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert( ! empty( $pending['ok'] ) && empty( $pending['committed'] ), 'Authorization should durably reserve one bounded intent.' );
$malformed_completion = UploadBatchStore::complete_intent(
    $pending_batch_id,
    $pending_secret,
    'pending_one',
    $pending['intent']['intent_id'],
    array(
        'object_key' => array( $pending['intent']['object_key'] ),
        'object_version' => '00000000-0000-4000-8000-000000000000',
        'bytes' => strlen( $png_bytes ),
        'mime' => 'image/png',
        'width' => 3,
        'height' => 2,
    ),
    $uploads_dir,
    $now + 15
);
eforms_test_assert(
    empty( $malformed_completion['ok'] ) && $malformed_completion['reason'] === 'completion_conflict',
    'Malformed provider facts should fail closed at the completion boundary instead of throwing on a non-string object key.'
);
$pending_claim = UploadBatchStore::claim_finalization( $pending_batch_id, $pending_secret, $pending_binding, $field, array(), 'submission-pending', $uploads_dir, $now + 20 );
eforms_test_assert( empty( $pending_claim['ok'] ) && $pending_claim['reason'] === 'batch_uploads_pending', 'Finalization should reject an otherwise exact snapshot while an intent remains unresolved.' );
$pending_deleted = UploadBatchStore::delete_item( $pending_batch_id, $pending_secret, 'pending_one', $uploads_dir, $now + 30 );
eforms_test_assert( ! empty( $pending_deleted['ok'] ), 'Deleting an unresolved intent should settle its physical reservation through a tombstone.' );
$late_completion = UploadBatchStore::complete_intent(
    $pending_batch_id,
    $pending_secret,
    'pending_one',
    $pending['intent']['intent_id'],
    array(
        'object_key' => $pending['intent']['object_key'],
        'object_version' => '00000000-0000-4000-8000-000000000000',
        'bytes' => strlen( $png_bytes ),
        'mime' => 'image/png',
        'width' => 3,
        'height' => 2,
    ),
    $uploads_dir,
    $now + 40
);
eforms_test_assert( empty( $late_completion['ok'] ) && $late_completion['reason'] === 'item_deleted', 'A late completion must not resurrect a tombstoned intent.' );

$receipt_binding = eforms_test_batch_binding( 'token-receipt-window', 'receipt_photos', $now + 3600 );
$receipt_secret = eforms_test_batch_secret( "\x38" );
$receipt_uploads = eforms_test_setup_uploads( 'eforms-receipt-completion-window' );
$receipt_created = UploadBatchStore::create_batch(
    $receipt_binding,
    $receipt_secret,
    $field,
    $receipt_uploads,
    $now,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    str_repeat( 'd', 64 )
);
$receipt_batch_id = $receipt_created['batch']['batch_id'];
$receipt_authorized = UploadBatchStore::authorize_intent(
    $receipt_batch_id,
    $receipt_secret,
    'receipt_one',
    0,
    'receipt.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $receipt_uploads,
    array(
        'now' => $now,
        'free_bytes' => $options['free_bytes'],
        'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
    )
);
$receipt_intent = $receipt_authorized['intent'];
$receipt_completion = UploadBatchStore::complete_receipt(
    $receipt_batch_id,
    $receipt_secret,
    'receipt_one',
    array(
        'intent_id' => $receipt_intent['intent_id'],
        'batch_id' => $receipt_batch_id,
        'upload_id' => 'receipt_one',
        'ordinal' => 0,
        'object_key' => $receipt_intent['object_key'],
        'object_version' => 'remote-receipt-v1',
        'etag' => 'remote-receipt-etag-v1',
        'bytes' => strlen( $png_bytes ),
        'mime' => 'image/png',
        'width' => 3,
        'height' => 2,
        'policy_fingerprint' => $receipt_intent['policy_fingerprint'],
        'expires_at' => $receipt_intent['expires_at'] + Anchors::get( 'WORKER_RECEIPT_TTL_SECONDS' ),
    ),
    $receipt_uploads,
    $receipt_intent['expires_at'] + 10
);
$receipt_status = UploadBatchStore::status( $receipt_batch_id, $receipt_secret, $receipt_uploads, $receipt_intent['expires_at'] + 20 );
eforms_test_assert(
    ! empty( $receipt_completion['ok'] )
        && ! empty( $receipt_status['ok'] )
        && count( $receipt_status['batch']['items'] ) === 1,
    'A valid signed fact receipt should settle an already-stored object during its bounded post-intent completion window.'
);
eforms_test_remove_tree( $receipt_uploads );

$expired_binding = eforms_test_batch_binding( 'token-expired-intent', 'expired_photos', $now + 7200 );
$expired_secret = eforms_test_batch_secret( "\x39" );
$expired_created = UploadBatchStore::create_batch( $expired_binding, $expired_secret, $field, $uploads_dir, $now );
$expired_batch_id = $expired_created['batch']['batch_id'];
$expired_authorized = UploadBatchStore::authorize_intent(
    $expired_batch_id,
    $expired_secret,
    'expired_one',
    0,
    'expired.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $uploads_dir,
    array( 'now' => $now, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert( ! empty( $expired_authorized['ok'] ), 'The expired-intent recovery fixture should reserve one local artifact.' );
$expired_at = $now + Anchors::get( 'MANAGED_UPLOAD_INTENT_TTL_SECONDS' );
$expired_retry = UploadBatchStore::authorize_intent(
    $expired_batch_id,
    $expired_secret,
    'expired_one',
    0,
    'expired.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $uploads_dir,
    array( 'now' => $expired_at, 'free_bytes' => $options['free_bytes'] )
);
$expired_path = $private_dir . '/staged/' . Helpers::h2( $expired_batch_id ) . '/' . $expired_batch_id;
$expired_manifest = json_decode( file_get_contents( $expired_path . '/' . UploadBatchStore::MANIFEST_FILENAME ), true );
$expired_capacity = json_decode( file_get_contents( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME ), true );
$expired_reservation_id = hash( 'sha256', $expired_batch_id . "\0expired_one" );
eforms_test_assert(
    empty( $expired_retry['ok'] )
        && $expired_retry['reason'] === 'upload_id_conflict'
        && ! isset( $expired_manifest['intents']['expired_one'] )
        && ! empty( $expired_manifest['tombstones']['expired_one']['capacity_released'] )
        && ! isset( $expired_capacity['reservations'][ $expired_reservation_id ] ),
    'Exact reauthorization of an expired intent should keep the ID terminal while fencing deletion and releasing its invisible reservation.'
);
$expired_claim = UploadBatchStore::claim_finalization(
    $expired_batch_id,
    $expired_secret,
    $expired_binding,
    $field,
    array(),
    'submission-expired-intent',
    $uploads_dir,
    $expired_at + 1
);
eforms_test_assert( ! empty( $expired_claim['ok'] ), 'An expired interrupted transfer should no longer block finalization after its exact retry performs cleanup.' );

$bounded_binding = eforms_test_batch_binding( 'token-bounded-tombstones', 'bounded_photos', $now + 3600 );
$bounded_secret = eforms_test_batch_secret( "\x38" );
$bounded_created = UploadBatchStore::create_batch( $bounded_binding, $bounded_secret, $field, $uploads_dir, $now );
$bounded_batch_id = $bounded_created['batch']['batch_id'];
$bounded_intent = UploadBatchStore::authorize_intent(
    $bounded_batch_id,
    $bounded_secret,
    'bounded_active',
    0,
    'bounded.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $uploads_dir,
    array( 'now' => $now + 50, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert( ! empty( $bounded_intent['ok'] ), 'The bounded-lifetime fixture should retain one active intent.' );
$bounded_lifetime_limit = $field['max_files'] * 2;
$bounded_unknown_ids = array();
for ( $index = 0; $index < $bounded_lifetime_limit - 1; $index++ ) {
    $bounded_unknown_id = 'bounded_unknown_' . $index;
    $bounded_unknown_ids[] = $bounded_unknown_id;
    $bounded_delete = UploadBatchStore::delete_item( $bounded_batch_id, $bounded_secret, $bounded_unknown_id, $uploads_dir, $now + 60 + $index );
    eforms_test_assert( ! empty( $bounded_delete['ok'] ), 'Unknown cancellation within the lifetime bound should remain idempotently successful.' );
}
$bounded_overflow = UploadBatchStore::delete_item( $bounded_batch_id, $bounded_secret, 'bounded_unknown_overflow', $uploads_dir, $now + 70 );
$bounded_path = $private_dir . '/staged/' . Helpers::h2( $bounded_batch_id ) . '/' . $bounded_batch_id;
$bounded_manifest = json_decode( file_get_contents( $bounded_path . '/' . UploadBatchStore::MANIFEST_FILENAME ), true );
eforms_test_assert(
    ! empty( $bounded_overflow['ok'] )
        && count( $bounded_manifest['intents'] ) + count( $bounded_manifest['items'] ) + count( $bounded_manifest['tombstones'] ) === $bounded_lifetime_limit
        && ! isset( $bounded_manifest['tombstones']['bounded_unknown_overflow'] ),
    'An unknown cancellation at the combined lifetime bound should succeed without persisting another tombstone.'
);
$bounded_active_delete = UploadBatchStore::delete_item( $bounded_batch_id, $bounded_secret, 'bounded_active', $uploads_dir, $now + 80 );
$bounded_after_delete = json_decode( file_get_contents( $bounded_path . '/' . UploadBatchStore::MANIFEST_FILENAME ), true );
eforms_test_assert(
    ! empty( $bounded_active_delete['ok'] )
        && count( $bounded_after_delete['intents'] ) + count( $bounded_after_delete['items'] ) + count( $bounded_after_delete['tombstones'] ) === $bounded_lifetime_limit
        && empty( array_diff_key( array_flip( $bounded_unknown_ids ), $bounded_after_delete['tombstones'] ) ),
    'Deleting an active intent at the lifetime bound should replace that entry without rotating a released tombstone.'
);

$deleted = UploadBatchStore::delete_item( $batch_id, $secret, 'upload_one', $uploads_dir, $now + 40 );
$deleted_manifest = json_decode( file_get_contents( $manifest_path ), true );
eforms_test_assert(
    ! empty( $deleted['ok'] )
        && ! is_file( $artifact_path )
        && $deleted_manifest['artifact_bytes'] === 0
        && ! empty( $deleted_manifest['tombstones']['upload_one']['capacity_released'] ),
    'Removal should persist its tombstone before deleting bytes and release accounting only after deletion.'
);

$deletion_window_sequence = 0;
$new_deletion_window = function ( $label ) use ( &$deletion_window_sequence, $field, $uploads_dir, $now, $png_bytes, $options ) {
    $deletion_window_sequence++;
    $binding = eforms_test_batch_binding( 'token-delete-window-' . $label, 'delete_' . $label, $now + 3600 );
    $secret = eforms_test_batch_secret( "\x37" );
    $created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
    $upload_id = 'delete_' . $deletion_window_sequence;
    $source = eforms_test_write_file( $uploads_dir, 'delete-window-' . $deletion_window_sequence . '.png', $png_bytes );
    $put = UploadBatchStore::put_item(
        $created['batch']['batch_id'],
        $secret,
        $upload_id,
        0,
        array(
            'tmp_name' => $source,
            'original_name' => 'delete-window.png',
            'size' => strlen( $png_bytes ),
            'error' => UPLOAD_ERR_OK,
        ),
        $uploads_dir,
        array( 'now' => $now + 100 + $deletion_window_sequence, 'free_bytes' => $options['free_bytes'] )
    );
    $batch_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $created['batch']['batch_id'] ) . '/' . $created['batch']['batch_id'];
    $manifest_path = $batch_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $manifest = json_decode( file_get_contents( $manifest_path ), true );
    $item = $manifest['items'][ $upload_id ];
    return array(
        'batch_id' => $created['batch']['batch_id'],
        'secret' => $secret,
        'upload_id' => $upload_id,
        'manifest_path' => $manifest_path,
        'item' => $item,
        'artifact_path' => LocalArtifactStore::locate( $uploads_dir, $item['object_key'], $item['object_version'] ),
        'put_ok' => ! empty( $put['ok'] ),
    );
};
$prepare_deletion_window = function ( $fixture, $persist_tombstone, $delete_object, $settle_capacity ) use ( $private_dir, $uploads_dir, $now ) {
    $capacity_path = $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME;
    $record = ManagedCapacityStore::read( $capacity_path, UploadBatchStore::CAPACITY_VERSION, $now );
    $prepared = ManagedCapacityStore::prepare_item_release(
        $record,
        $fixture['batch_id'],
        $fixture['upload_id'],
        $fixture['item']['bytes'],
        $fixture['item']['object_key'],
        $now + 200,
        true,
        $now + 200,
        FormProtocol::UPLOAD_TRANSPORT_LOCAL,
        UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY
    );
    eforms_test_assert( ! empty( $prepared['ok'] ) && ManagedCapacityStore::write( $capacity_path, $prepared['record'] ), 'A deletion failure-window fixture should persist capacity release preparation.' );

    if ( $persist_tombstone ) {
        $manifest = json_decode( file_get_contents( $fixture['manifest_path'] ), true );
        unset( $manifest['items'][ $fixture['upload_id'] ] );
        $manifest['artifact_bytes'] -= $fixture['item']['bytes'];
        $manifest['tombstones'][ $fixture['upload_id'] ] = array(
            'deleted_at' => $now + 200,
            'bytes' => $fixture['item']['bytes'],
            'object_key' => $fixture['item']['object_key'],
            'object_version' => $fixture['item']['object_version'],
            'capacity_release_started' => true,
            'capacity_released' => false,
        );
        file_put_contents( $fixture['manifest_path'], json_encode( $manifest ) );
    }
    if ( $delete_object ) {
        $lease = PrivateDir::acquire_write_lease( $uploads_dir );
        $removed = LocalArtifactStore::delete( $lease, $fixture['item']['object_key'], $fixture['item']['object_version'] );
        $lease->release();
        eforms_test_assert( $removed && ! file_exists( $fixture['artifact_path'] ), 'A deletion failure-window fixture should remove physical bytes before interruption.' );
    }
    if ( $settle_capacity ) {
        $record = ManagedCapacityStore::read( $capacity_path, UploadBatchStore::CAPACITY_VERSION, $now );
        $settled = ManagedCapacityStore::finish_item_release( $record, $fixture['batch_id'], $fixture['upload_id'], $now + 210 );
        eforms_test_assert( ! empty( $settled['ok'] ) && ManagedCapacityStore::write( $capacity_path, $settled['record'] ), 'A deletion failure-window fixture should settle capacity before interruption.' );
    }
};

foreach (
    array(
        'capacity_prepared' => array( false, false, false ),
        'tombstone_persisted' => array( true, false, false ),
        'object_deleted' => array( true, true, false ),
        'capacity_settled' => array( true, true, true ),
    ) as $label => $window
) {
    $fixture = $new_deletion_window( $label );
    eforms_test_assert( $fixture['put_ok'] && is_file( $fixture['artifact_path'] ), 'The ' . $label . ' deletion retry fixture should begin with one committed artifact.' );
    $prepare_deletion_window( $fixture, $window[0], $window[1], $window[2] );
    if ( $label === 'tombstone_persisted' ) {
        $window_record_before = eforms_test_managed_capacity_record( $uploads_dir );
        $window_health = eforms_upload_batch_store_capacity_health( $uploads_dir );
        $window_reconciled = UploadBatchStore::reconcile_capacity( $uploads_dir, $now, $now + 215 );
        eforms_test_assert(
            ! empty( $window_health['ok'] )
                && $window_health['capacity']['consistent']
                && $window_health['capacity']['orphaned_bytes'] >= $fixture['item']['bytes']
                && ! empty( $window_reconciled['ok'] )
                && $window_reconciled['capacity']['total_bytes'] === $window_record_before['total_bytes'],
            'A tombstoned artifact awaiting physical deletion must remain singly attributed through health and reconciliation.'
        );
    }
    $retried_delete = UploadBatchStore::delete_item( $fixture['batch_id'], $fixture['secret'], $fixture['upload_id'], $uploads_dir, $now + 220 );
    $retried_manifest = json_decode( file_get_contents( $fixture['manifest_path'] ), true );
    eforms_test_assert(
        ! empty( $retried_delete['ok'] )
            && ! file_exists( $fixture['artifact_path'] )
            && ! empty( $retried_manifest['tombstones'][ $fixture['upload_id'] ]['capacity_released'] ),
        'Deletion should converge after the ' . $label . ' interruption without retaining bytes or an unreleased tombstone.'
    );
    $idempotent_delete = UploadBatchStore::delete_item( $fixture['batch_id'], $fixture['secret'], $fixture['upload_id'], $uploads_dir, $now + 230 );
    eforms_test_assert( ! empty( $idempotent_delete['ok'] ), 'Deletion should remain idempotent after recovering the ' . $label . ' interruption.' );
}
$health = eforms_upload_batch_store_capacity_health( $uploads_dir );
eforms_test_assert( ! empty( $health['ok'] ) && $health['capacity']['consistent'], 'Artifact accounting should remain consistent after commit and deletion.' );

$missing = UploadBatchStore::status( str_repeat( 'A', 43 ), $secret, $uploads_dir, $now );
eforms_test_assert( $missing['ok'] === false && ! empty( $missing['gone'] ), 'A nonexistent batch should share the generic terminal result.' );
$expired = UploadBatchStore::status( $batch_id, $secret, $uploads_dir, $created['batch']['delete_after'] );
eforms_test_assert( $expired['ok'] === false && ! empty( $expired['gone'] ), 'Cleanup expiry should share the generic terminal result before credential disclosure.' );

$corrupt_binding = eforms_test_batch_binding( 'token-corrupt-01', 'corrupt_photos', $now + 3600 );
$corrupt_created = UploadBatchStore::create_batch( $corrupt_binding, eforms_test_batch_secret( "\x44" ), $field, $uploads_dir, $now );
eforms_test_assert( $corrupt_created['ok'] === true, 'The corrupt-manifest fixture should create normally.' );
$corrupt_batch_path = $private_dir . '/staged/' . Helpers::h2( $corrupt_created['batch']['batch_id'] ) . '/' . $corrupt_created['batch']['batch_id'];
$corrupt_manifest_path = $corrupt_batch_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
$corrupt_intent = UploadBatchStore::authorize_intent(
    $corrupt_created['batch']['batch_id'],
    eforms_test_batch_secret( "\x44" ),
    'corrupt_active',
    0,
    'corrupt.png',
    strlen( $png_bytes ),
    'image/png',
    0,
    $uploads_dir,
    array( 'now' => $now + 1, 'free_bytes' => $options['free_bytes'] )
);
eforms_test_assert( ! empty( $corrupt_intent['ok'] ), 'The over-bound manifest fixture should begin with one valid intent.' );
$corrupt_manifest = json_decode( file_get_contents( $corrupt_manifest_path ), true );
$overbound_manifest = $corrupt_manifest;
for ( $index = 0; $index < $field['max_files'] * 2; $index++ ) {
    $overbound_manifest['tombstones']['corrupt_deleted_' . $index] = array(
        'bytes' => 0,
        'capacity_release_started' => true,
        'capacity_released' => true,
        'deleted_at' => $now + 2,
        'object_key' => '',
        'object_version' => '',
    );
}
file_put_contents( $corrupt_manifest_path, json_encode( $overbound_manifest ) );
$overbound_status = UploadBatchStore::status( $corrupt_created['batch']['batch_id'], eforms_test_batch_secret( "\x44" ), $uploads_dir, $now + 3 );
eforms_test_assert( $overbound_status['ok'] === false && $overbound_status['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'A manifest whose combined intent, item, and tombstone count exceeds the lifetime bound should fail closed.' );
$mismatched_batch_manifest = $corrupt_manifest;
$mismatched_batch_manifest['batch_id'] = str_repeat( 'Z', 43 );
file_put_contents( $corrupt_manifest_path, json_encode( $mismatched_batch_manifest ) );
$mismatched_batch_status = UploadBatchStore::status( $corrupt_created['batch']['batch_id'], eforms_test_batch_secret( "\x44" ), $uploads_dir, $now );
eforms_test_assert( $mismatched_batch_status['ok'] === false && $mismatched_batch_status['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'A staged manifest must match the batch directory through which it was opened.' );
$corrupt_manifest['batch_secret_digest'] = array( 'not-a-string' );
file_put_contents( $corrupt_manifest_path, json_encode( $corrupt_manifest ) );
$corrupt_status = UploadBatchStore::status( $corrupt_created['batch']['batch_id'], eforms_test_batch_secret( "\x44" ), $uploads_dir, $now );
eforms_test_assert( $corrupt_status['ok'] === false && $corrupt_status['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'A structurally malformed manifest should fail closed as storage unavailable without reaching credential comparison.' );

$capacity_path = $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME;
file_put_contents( $capacity_path, '{invalid' );
$corrupt_capacity = eforms_upload_batch_store_capacity_health( $uploads_dir );
eforms_test_assert( $corrupt_capacity['ok'] === false && $corrupt_capacity['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'A corrupt capacity record should fail closed.' );

eforms_test_remove_tree( $uploads_dir );
echo "All upload batch store tests passed.\n";
