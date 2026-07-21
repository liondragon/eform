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

$uploads_dir = eforms_test_setup_uploads( 'eforms-upload-batch-store' );
$now = 1700000000;
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

$expected_id = '5e1cTCrFgHMC_zzjhfT9FgKTLbR6HxAo2oWCvsrYu9U';
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
$heic_policy_field = $fixture_field;
$heic_policy_field['accept'] = array( 'image', 'heic' );
$reordered_heic_policy = $heic_policy_field;
$reordered_heic_policy['accept'] = array( 'heic', 'image' );
eforms_test_assert(
    UploadBatchStore::canonical_policy( $heic_policy_field )['accept'] === array( 'heic', 'image' )
        && UploadBatchStore::policy_fingerprint( $heic_policy_field ) === UploadBatchStore::policy_fingerprint( $reordered_heic_policy ),
    'The managed policy should canonicalize HEIC opt-in independently of template token order.'
);

$created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert( $created['ok'] === true, 'A valid exact binding should create one managed batch.' );
$staged_root = $uploads_dir . '/eforms-private/' . UploadBatchStore::STAGED_DIR;
eforms_upload_batch_store_assert_protected_dir( $staged_root, 'staged root' );
$created_batch_path = $staged_root . '/' . Helpers::h2( $created['batch']['batch_id'] ) . '/' . $created['batch']['batch_id'];
$created_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $created_batch_path );
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
$heic_field = $field;
$heic_field['accept'] = array( 'image', 'heic' );
$heic_created = UploadBatchStore::create_batch(
    eforms_test_batch_binding( 'token-heic-policy', 'heic_photos', $now + 3600 ),
    $secret,
    $heic_field,
    $uploads_dir,
    $now
);
eforms_test_assert( ! empty( $heic_created['ok'] ), 'A managed batch should accept the explicit staged HEIC policy.' );
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
mkdir( $partial_path . '/files', 0700, true );
file_put_contents( $partial_path . '/.lock', '' );
$partial_created = UploadBatchStore::create_batch( $partial_binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert( ! empty( $partial_created['ok'] ) && $partial_created['batch']['batch_id'] === $partial_id, 'A deterministic retry should safely finish an empty manifest-less partial aggregate.' );

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
mkdir( $temp_partial_path . '/files', 0700, true );
file_put_contents( $temp_partial_path . '/.lock', '' );
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
mkdir( $unknown_partial_path . '/files', 0700, true );
file_put_contents( $unknown_partial_path . '/.lock', '' );
$unknown_manifest_temp = $unknown_partial_path . '/.manifest.json.not-owner.tmp';
file_put_contents( $unknown_manifest_temp, 'residue' );
$unknown_partial_created = UploadBatchStore::create_batch( $unknown_partial_binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert( empty( $unknown_partial_created['ok'] ) && $unknown_partial_created['reason'] === 'batch_files_unavailable', 'A manifest-like filename outside the atomic writer format should still fail closed.' );
eforms_test_assert( is_file( $unknown_manifest_temp ), 'An unrecognized manifest-like file should be preserved for operator inspection.' );

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

$wrong_status = UploadBatchStore::status( $batch_id, $other_secret, $uploads_dir, $now );
eforms_test_assert( $wrong_status['ok'] === false, 'The batch ID alone should not authorize status.' );

$png_bytes = eforms_test_fixture_bytes( 'staged-landscape.png' );
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png_bytes );
$item = array(
    'tmp_name' => $source,
    'original_name' => '../Customer Photo.png',
    'size' => 1,
    'error' => UPLOAD_ERR_OK,
);
$options = array(
    'now' => $now,
    'memory_limit' => -1,
    'execution_limit' => 0,
    'editor_support' => function () {
        return true;
    },
    'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
);
$backend = UploadPolicy::staged_host_readiness( 'image/png', $options );
if ( $backend['ok'] ) {
    $put = UploadBatchStore::put_item( $batch_id, $secret, 'upload_one', 1, $item, $uploads_dir, $options );
    eforms_test_assert( $put['ok'] === true, 'A validated source and derivative should commit as one item.' );
    eforms_test_assert( ! isset( $put['item']['original_relpath'] ) && ! isset( $put['item']['preview_relpath'] ), 'Item responses should not expose private paths.' );

    $manifest = json_decode( file_get_contents( $manifest_path ), true );
    $stored = $manifest['items']['upload_one'];
    $original = $batch_path . '/' . $stored['original_relpath'];
    $preview = $batch_path . '/' . $stored['preview_relpath'];
    eforms_test_assert( is_file( $original ) && is_file( $preview ), 'The manifest item should own one original and one preview.' );
    eforms_test_assert( ( fileperms( $original ) & 0777 ) === 0600 && ( fileperms( $preview ) & 0777 ) === 0600, 'Managed originals and previews should be private.' );
    eforms_test_assert( UploadPolicy::detect_mime( $preview ) === 'image/jpeg', 'The managed preview should contain JPEG bytes.' );
    eforms_test_assert( $stored['managed_bytes'] === filesize( $original ) + filesize( $preview ), 'Managed item accounting should include original and preview bytes.' );

    $oriented_binding = eforms_test_batch_binding( 'token-oriented-preview', 'oriented_photos', $now + 3600 );
    $oriented_created = UploadBatchStore::create_batch( $oriented_binding, $secret, $field, $uploads_dir, $now );
    $oriented_source = eforms_test_write_file( $uploads_dir, 'oriented.jpg', eforms_test_fixture_bytes( 'oriented-landscape.jpg' ) );
    $oriented_put = UploadBatchStore::put_item(
        $oriented_created['batch']['batch_id'],
        $secret,
        'oriented_photo',
        0,
        array(
            'tmp_name' => $oriented_source,
            'original_name' => 'Phone Photo.jpg',
            'size' => filesize( $oriented_source ),
            'error' => UPLOAD_ERR_OK,
        ),
        $uploads_dir,
        $options
    );
    eforms_test_assert(
        ! empty( $oriented_put['ok'] ) && $oriented_put['item']['width'] === 60 && $oriented_put['item']['height'] === 120,
        'Managed item dimensions should describe the EXIF-oriented preview consumed by the gallery.'
    );
    $oriented_delete = UploadBatchStore::delete_item( $oriented_created['batch']['batch_id'], $secret, 'oriented_photo', $uploads_dir, $now );
    eforms_test_assert( ! empty( $oriented_delete['ok'] ), 'The oriented-preview fixture should release its managed capacity before later accounting checks.' );

    $capacity = eforms_test_managed_capacity_record( $uploads_dir );
    eforms_test_assert( is_array( $capacity ) && $capacity['total_bytes'] === $stored['managed_bytes'], 'Capacity should settle from reservation to exact committed bytes.' );
    eforms_test_assert( $capacity['reservations'] === array(), 'A completed item should clear its in-progress reservation.' );

    $committing_reservation_id = hash( 'sha256', $batch_id . "\0" . 'upload_one' );
    $committing_reserved_bytes = $stored['bytes'] + Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' );
    $capacity['total_bytes'] = $committing_reserved_bytes;
    $capacity['reservations'][ $committing_reservation_id ] = array(
        'batch_id' => $batch_id,
        'upload_id' => 'upload_one',
        'bytes' => $committing_reserved_bytes,
        'created_at' => $now,
    );
    file_put_contents( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME, json_encode( $capacity ) );
    $committing_health = eforms_upload_batch_store_capacity_health( $uploads_dir );
    eforms_test_assert(
        $committing_health['ok'] === true
            && $committing_health['capacity']['consistent'] === true
            && $committing_health['capacity']['committing_bytes'] === $stored['managed_bytes'],
        'Capacity health should treat committed files plus their unsettled reservation as one in-flight item.'
    );
    $committing_reconcile = UploadBatchStore::reconcile_capacity( $uploads_dir, $now - 1, $now );
    eforms_test_assert(
        $committing_reconcile['ok'] === true
            && $committing_reconcile['committed_reservations_settled'] === 1
            && $committing_reconcile['capacity']['total_bytes'] === $stored['managed_bytes']
            && $committing_reconcile['capacity']['reservations'] === array(),
        'Capacity repair should count a committed in-flight item once and settle its reservation.'
    );

    $delete_binding = $binding;
    $delete_binding['raw_token'] = 'token-delete-reservation';
    $delete_binding['instance_id'] = 'instance-delete-reservation';
    $delete_secret = eforms_test_batch_secret( "\x33" );
    $delete_created = UploadBatchStore::create_batch( $delete_binding, $delete_secret, $field, $uploads_dir, $now );
    $delete_batch_id = $delete_created['batch']['batch_id'];
    $delete_put = UploadBatchStore::put_item( $delete_batch_id, $delete_secret, 'delete_committing', 0, $item, $uploads_dir, $options );
    eforms_test_assert( ! empty( $delete_put['ok'] ), 'The committed-reservation deletion fixture should upload one item.' );
    $delete_capacity = eforms_test_managed_capacity_record( $uploads_dir );
    $delete_actual_bytes = $delete_capacity['total_bytes'] - $stored['managed_bytes'];
    $delete_reserved_bytes = $delete_put['item']['bytes'] + Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' );
    $delete_reservation_id = hash( 'sha256', $delete_batch_id . "\0" . 'delete_committing' );
    $delete_capacity['total_bytes'] = $delete_capacity['total_bytes'] - $delete_actual_bytes + $delete_reserved_bytes;
    $delete_capacity['reservations'][ $delete_reservation_id ] = array(
        'batch_id' => $delete_batch_id,
        'upload_id' => 'delete_committing',
        'bytes' => $delete_reserved_bytes,
        'created_at' => $now,
    );
    file_put_contents( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME, json_encode( $delete_capacity ) );
    $delete_committing = UploadBatchStore::delete_item( $delete_batch_id, $delete_secret, 'delete_committing', $uploads_dir, $now );
    eforms_test_assert( ! empty( $delete_committing['ok'] ), 'Deletion should settle a matching committed reservation atomically with capacity.' );
    $delete_capacity_after = eforms_test_managed_capacity_record( $uploads_dir );
    eforms_test_assert(
        $delete_capacity_after['total_bytes'] === $stored['managed_bytes'] && $delete_capacity_after['reservations'] === array(),
        'Committed-reservation deletion should preserve other items while removing its reservation contribution.'
    );

    $retry_delete_binding = $binding;
    $retry_delete_binding['raw_token'] = 'token-delete-write-retry';
    $retry_delete_binding['instance_id'] = 'instance-delete-write-retry';
    $retry_delete_secret = eforms_test_batch_secret( "\x34" );
    $retry_delete_created = UploadBatchStore::create_batch( $retry_delete_binding, $retry_delete_secret, $field, $uploads_dir, $now );
    $retry_delete_batch_id = $retry_delete_created['batch']['batch_id'];
    $retry_delete_put = UploadBatchStore::put_item( $retry_delete_batch_id, $retry_delete_secret, 'delete_write_retry', 0, $item, $uploads_dir, $options );
    eforms_test_assert( ! empty( $retry_delete_put['ok'] ), 'Capacity-write retry fixture should commit one managed item.' );
    $retry_delete_path = $private_dir . '/staged/' . Helpers::h2( $retry_delete_batch_id ) . '/' . $retry_delete_batch_id;
    $retry_delete_manifest_path = $retry_delete_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $retry_delete_manifest = json_decode( file_get_contents( $retry_delete_manifest_path ), true );
    $retry_delete_item = $retry_delete_manifest['items']['delete_write_retry'];
    unset( $retry_delete_manifest['items']['delete_write_retry'] );
    $retry_delete_manifest['original_bytes'] = 0;
    $retry_delete_manifest['managed_bytes'] = 0;
    $retry_delete_manifest['tombstones']['delete_write_retry'] = array(
        'deleted_at' => $now,
        'managed_bytes' => $retry_delete_item['managed_bytes'],
        'original_relpath' => $retry_delete_item['original_relpath'],
        'preview_relpath' => $retry_delete_item['preview_relpath'],
        'capacity_release_started' => true,
        'capacity_released' => false,
    );
    file_put_contents( $retry_delete_manifest_path, json_encode( $retry_delete_manifest ) );
    $retry_delete_capacity = eforms_test_managed_capacity_record( $uploads_dir );
    $retry_delete_reservation_id = hash( 'sha256', $retry_delete_batch_id . "\0" . 'delete_write_retry' );
    $retry_delete_capacity['reservations'][ $retry_delete_reservation_id ] = array(
        'batch_id' => $retry_delete_batch_id,
        'upload_id' => 'delete_write_retry',
        'bytes' => $retry_delete_item['managed_bytes'],
        'created_at' => $now,
    );
    file_put_contents( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME, json_encode( $retry_delete_capacity ) );
    unlink( $retry_delete_path . '/' . $retry_delete_item['original_relpath'] );
    unlink( $retry_delete_path . '/' . $retry_delete_item['preview_relpath'] );
    $retry_delete = UploadBatchStore::delete_item( $retry_delete_batch_id, $retry_delete_secret, 'delete_write_retry', $uploads_dir, $now );
    eforms_test_assert( ! empty( $retry_delete['ok'] ), 'A retry after files were removed but capacity persistence failed should converge.' );
    $retry_delete_capacity_after = eforms_test_managed_capacity_record( $uploads_dir );
    eforms_test_assert(
        $retry_delete_capacity_after['total_bytes'] === $stored['managed_bytes'] && $retry_delete_capacity_after['reservations'] === array(),
        'Capacity-write retry should release the exact tombstone bytes once and clear its durable reservation.'
    );
    $retry_delete_manifest = json_decode( file_get_contents( $retry_delete_manifest_path ), true );
    $retry_delete_manifest['tombstones']['delete_write_retry']['capacity_released'] = false;
    file_put_contents( $retry_delete_manifest_path, json_encode( $retry_delete_manifest ) );
    $manifest_retry = UploadBatchStore::delete_item( $retry_delete_batch_id, $retry_delete_secret, 'delete_write_retry', $uploads_dir, $now );
    eforms_test_assert( ! empty( $manifest_retry['ok'] ), 'A retry after capacity committed but the tombstone flag write failed should converge.' );
    $manifest_retry_capacity = eforms_test_managed_capacity_record( $uploads_dir );
    eforms_test_assert( $manifest_retry_capacity['total_bytes'] === $stored['managed_bytes'], 'Manifest-write retry must not release the same capacity twice.' );

    $retry_put = UploadBatchStore::put_item( $batch_id, $secret, 'upload_one', 1, $item, $uploads_dir, $options );
    eforms_test_assert( $retry_put['ok'] === true, 'A response-loss retry with the same logical ID and bytes should return the existing item.' );
    $capacity_retry = eforms_test_managed_capacity_record( $uploads_dir );
    eforms_test_assert( $capacity_retry['total_bytes'] === $stored['managed_bytes'], 'An idempotent item retry must not reserve capacity twice.' );

    $oversize_retry_path = eforms_test_write_file( $uploads_dir, 'oversize-retry.png', str_repeat( 'x', $field['max_file_bytes'] + 1 ) );
    $oversize_retry_item = $item;
    $oversize_retry_item['tmp_name'] = $oversize_retry_path;
    $oversize_retry_item['size'] = filesize( $oversize_retry_path );
    $oversize_retry = UploadBatchStore::put_item( $batch_id, $secret, 'upload_one', 1, $oversize_retry_item, $uploads_dir, $options );
    eforms_test_assert( empty( $oversize_retry['ok'] ) && $oversize_retry['reason'] === 'max_file_bytes_exceeded', 'A committed-ID retry must enforce the item bound before hashing its body.' );

    $ordinal_retry = UploadBatchStore::put_item( $batch_id, $secret, 'upload_one', 2, $item, $uploads_dir, $options );
    eforms_test_assert( $ordinal_retry['ok'] === false && $ordinal_retry['reason'] === 'upload_id_conflict', 'A same-ID retry with a different ordinal should conflict.' );
    $duplicate_ordinal = UploadBatchStore::put_item( $batch_id, $secret, 'upload_two', 1, $item, $uploads_dir, $options );
    eforms_test_assert( $duplicate_ordinal['ok'] === false && $duplicate_ordinal['reason'] === 'ordinal_conflict', 'A second upload ID must not claim an existing ordinal.' );
    $after_ordinal_conflicts = UploadBatchStore::status( $batch_id, $secret, $uploads_dir, $now );
    eforms_test_assert( $after_ordinal_conflicts['ok'] === true && count( $after_ordinal_conflicts['batch']['items'] ) === 1, 'Rejected ordinal conflicts must leave the batch readable and unchanged.' );
    $capacity_after_ordinal_conflicts = eforms_test_managed_capacity_record( $uploads_dir );
    eforms_test_assert( $capacity_after_ordinal_conflicts['total_bytes'] === $stored['managed_bytes'], 'Rejected ordinal conflicts must not reserve managed capacity.' );

    $different_source = eforms_test_write_file( $uploads_dir, 'different.png', $png_bytes . 'different' );
    $different_item = $item;
    $different_item['tmp_name'] = $different_source;
    $different_item['size'] = filesize( $different_source );
    $put_conflict = UploadBatchStore::put_item( $batch_id, $secret, 'upload_one', 1, $different_item, $uploads_dir, $options );
    eforms_test_assert( $put_conflict['ok'] === false && $put_conflict['code'] === 'EFORMS_ERR_TOKEN', 'The same upload ID with different content should conflict.' );

    $preview_read = UploadBatchStore::preview_bytes( $batch_id, $secret, 'upload_one', $uploads_dir, $now );
    eforms_test_assert( $preview_read['ok'] === true && $preview_read['body'] === file_get_contents( $preview ), 'The authenticated store read should materialize only the manifest preview member.' );
    if ( function_exists( 'symlink' ) ) {
        $outside_preview = eforms_test_write_file( $uploads_dir, 'outside-preview.jpg', 'outside-preview' );
        $preview_body = $preview_read['body'];
        eforms_test_assert( unlink( $preview ) && symlink( $outside_preview, $preview ), 'The staged preview symlink fixture should replace only the manifest member.' );
        $linked_preview = UploadBatchStore::preview_bytes( $batch_id, $secret, 'upload_one', $uploads_dir, $now );
        eforms_test_assert( empty( $linked_preview['ok'] ) && $linked_preview['reason'] === 'preview_missing', 'Staged preview reads should reject symlinked manifest members.' );
        @unlink( $preview );
        file_put_contents( $preview, $preview_body );
    }

    $deleted = UploadBatchStore::delete_item( $batch_id, $secret, 'upload_one', $uploads_dir, $now );
    eforms_test_assert( $deleted['ok'] === true && ! is_dir( dirname( $original ) ), 'Open deletion should remove the aggregate item directory.' );
    $deleted_retry = UploadBatchStore::delete_item( $batch_id, $secret, 'upload_one', $uploads_dir, $now );
    eforms_test_assert( $deleted_retry['ok'] === true, 'Open deletion should be idempotent.' );
    $resurrection = UploadBatchStore::put_item( $batch_id, $secret, 'upload_one', 1, $item, $uploads_dir, $options );
    eforms_test_assert( $resurrection['ok'] === false, 'A deletion tombstone should prevent late item resurrection.' );
    $capacity_after_delete = eforms_test_managed_capacity_record( $uploads_dir );
    eforms_test_assert( $capacity_after_delete['total_bytes'] === 0, 'Capacity should release only after confirmed item deletion.' );

    $lifetime_field = $field;
    $lifetime_field['max_files'] = 1;
    $lifetime_field['max_total_bytes'] = 1048576;
    $lifetime_binding = eforms_test_batch_binding( 'token-lifetime-limit', 'replacement_photos', $now + 3600 );
    $lifetime_secret = eforms_test_batch_secret( "\x35" );
    $lifetime_created = UploadBatchStore::create_batch( $lifetime_binding, $lifetime_secret, $lifetime_field, $uploads_dir, $now );
    eforms_test_assert( ! empty( $lifetime_created['ok'] ), 'The replacement-lifetime fixture should create one-item batch.' );
    $lifetime_batch_id = $lifetime_created['batch']['batch_id'];
    foreach ( array( 'replacement_one', 'replacement_two' ) as $ordinal => $replacement_id ) {
        $replacement_put = UploadBatchStore::put_item( $lifetime_batch_id, $lifetime_secret, $replacement_id, $ordinal, $item, $uploads_dir, $options );
        eforms_test_assert( ! empty( $replacement_put['ok'] ), 'Each upload inside the lifetime bound should be accepted.' );
        $replacement_delete = UploadBatchStore::delete_item( $lifetime_batch_id, $lifetime_secret, $replacement_id, $uploads_dir, $now );
        eforms_test_assert( ! empty( $replacement_delete['ok'] ), 'Each accepted replacement must retain a tombstone slot and remain deletable.' );
    }
    $lifetime_rejected = UploadBatchStore::put_item( $lifetime_batch_id, $lifetime_secret, 'replacement_three', 2, $item, $uploads_dir, $options );
    eforms_test_assert(
        empty( $lifetime_rejected['ok'] ) && $lifetime_rejected['reason'] === 'upload_lifetime_exceeded',
        'The store should enforce its deletion-history lifetime before accepting an item that could not be tombstoned.'
    );
    $rejected_remove = UploadBatchStore::delete_item( $lifetime_batch_id, $lifetime_secret, 'replacement_three', $uploads_dir, $now );
    eforms_test_assert( ! empty( $rejected_remove['ok'] ), 'Deleting a definitely absent item at the lifetime cap should remain idempotent.' );
    $lifetime_status = UploadBatchStore::status( $lifetime_batch_id, $lifetime_secret, $uploads_dir, $now );
    eforms_test_assert( ! empty( $lifetime_status['ok'] ) && $lifetime_status['batch']['items'] === array(), 'Lifetime rejection and absent deletion must leave no active item behind.' );

    $after_delete_manifest = json_decode( file_get_contents( $manifest_path ), true );
    $overlapping_manifest = $after_delete_manifest;
    $overlapping_manifest['items']['upload_one'] = $stored;
    $overlapping_manifest['original_bytes'] += $stored['bytes'];
    $overlapping_manifest['managed_bytes'] += $stored['managed_bytes'];
    file_put_contents( $manifest_path, json_encode( $overlapping_manifest ) );
    $overlap_status = UploadBatchStore::status( $batch_id, $secret, $uploads_dir, $now );
    eforms_test_assert( $overlap_status['ok'] === false && $overlap_status['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'An upload ID cannot be both active and tombstoned.' );
    $overlap_upload = UploadBatchStore::put_item( $batch_id, $secret, 'overlap_probe', 2, $item, $uploads_dir, $options );
    eforms_test_assert( $overlap_upload['ok'] === false && $overlap_upload['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'Upload must reject an active/tombstone overlap before mutation.' );
    $overlap_claim = UploadBatchStore::claim_finalization( $batch_id, $secret, $binding, $field, array(), 'overlap-submission', $uploads_dir, $now + 10 );
    eforms_test_assert( $overlap_claim['ok'] === false && $overlap_claim['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'Finalization must reject an active/tombstone overlap.' );
    file_put_contents( $manifest_path, json_encode( $after_delete_manifest ) );

    $capacity_path = $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME;
    $reserved_bytes = filesize( $source ) + Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' );
    $near_ceiling = array(
        'version' => UploadBatchStore::CAPACITY_VERSION,
        'total_bytes' => Anchors::get( 'MANAGED_UPLOAD_MAX_BYTES' ) - $reserved_bytes + 1,
        'reservations' => array(),
        'updated_at' => $now,
    );
    file_put_contents( $capacity_path, json_encode( $near_ceiling ) );
    chmod( $capacity_path, 0600 );
    $capacity_failure = UploadBatchStore::put_item( $batch_id, $secret, 'over_capacity', 2, $item, $uploads_dir, $options );
    eforms_test_assert( $capacity_failure['ok'] === false && $capacity_failure['reason'] === 'managed_capacity_exceeded', 'A cross-batch reservation above the managed ceiling should fail closed.' );

    $empty_capacity = $near_ceiling;
    $empty_capacity['total_bytes'] = 0;
    file_put_contents( $capacity_path, json_encode( $empty_capacity ) );
    $unknown_free = $options;
    $unknown_free['free_bytes'] = false;
    $free_failure = UploadBatchStore::put_item( $batch_id, $secret, 'unknown_free', 2, $item, $uploads_dir, $unknown_free );
    eforms_test_assert( $free_failure['ok'] === false && $free_failure['reason'] === 'free_space_unavailable', 'Unavailable free-space observation should fail closed.' );

    $low_free = $options;
    $low_free['free_bytes'] = Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + $reserved_bytes - 1;
    $reserve_failure = UploadBatchStore::put_item( $batch_id, $secret, 'low_free', 2, $item, $uploads_dir, $low_free );
    eforms_test_assert( $reserve_failure['ok'] === false && $reserve_failure['reason'] === 'free_space_reserve', 'A reservation that consumes the fixed disk reserve should fail closed.' );

    $outstanding_capacity = $empty_capacity;
    $outstanding_capacity['total_bytes'] = $reserved_bytes;
    $outstanding_capacity['reservations']['other_batch'] = array(
        'batch_id' => str_repeat( 'Q', 43 ),
        'upload_id' => 'other_upload',
        'bytes' => $reserved_bytes,
        'created_at' => $now,
    );
    file_put_contents( $capacity_path, json_encode( $outstanding_capacity ) );
    $reserved_free = $options;
    $reserved_free['free_bytes'] = Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + ( 2 * $reserved_bytes ) - 1;
    $outstanding_failure = UploadBatchStore::put_item( $batch_id, $secret, 'reserved_free', 2, $item, $uploads_dir, $reserved_free );
    eforms_test_assert(
        $outstanding_failure['ok'] === false && $outstanding_failure['reason'] === 'free_space_reserve',
        'Free-space projection should include reservations from other in-flight batches.'
    );
    file_put_contents( $capacity_path, json_encode( $empty_capacity ) );

    $final_binding = eforms_test_batch_binding( 'token-finalize-01', 'final_photos', $now + 3600 );
    $final_secret = eforms_test_batch_secret( "\x33" );
    $final_created = UploadBatchStore::create_batch( $final_binding, $final_secret, $field, $uploads_dir, $now );
    eforms_test_assert( $final_created['ok'] === true, 'A second exact binding should create an independent batch.' );
    $final_batch_id = $final_created['batch']['batch_id'];
    $final_put = UploadBatchStore::put_item( $final_batch_id, $final_secret, 'final_photo', 0, $item, $uploads_dir, $options );
    eforms_test_assert( $final_put['ok'] === true, 'The finalization fixture should commit one item.' );
    $final_resolved = UploadBatchStore::resolve_open( $final_batch_id, $final_secret, $final_binding, $field, $uploads_dir, $now + 10 );
    eforms_test_assert( UploadBatchStore::delete_item( $final_batch_id, $final_secret, 'final_photo', $uploads_dir, $now + 10 )['ok'] === true, 'The finalization race fixture should delete the resolved item.' );
    $stale_claim = UploadBatchStore::claim_finalization( $final_batch_id, $final_secret, $final_binding, $field, $final_resolved['items'], 'submission-01', $uploads_dir, $now + 10 );
    eforms_test_assert( $stale_claim['ok'] === false && $stale_claim['reason'] === 'batch_items_changed', 'Finalization must reject an item set that changed after resolution.' );
    $open_after_stale_claim = UploadBatchStore::status( $final_batch_id, $final_secret, $uploads_dir, $now + 10 );
    eforms_test_assert( $open_after_stale_claim['ok'] === true && $open_after_stale_claim['batch']['state'] === 'open', 'A stale finalization snapshot must leave the batch editable for retry.' );
    $replacement_put = UploadBatchStore::put_item( $final_batch_id, $final_secret, 'final_photo_replacement', 1, $item, $uploads_dir, $options );
    eforms_test_assert( $replacement_put['ok'] === true, 'The open batch should accept a replacement after rejecting the stale snapshot.' );
    $before_finalize = eforms_test_managed_capacity_record( $uploads_dir );
    $final_resolved = UploadBatchStore::resolve_open( $final_batch_id, $final_secret, $final_binding, $field, $uploads_dir, $now + 10 );

    $claimed = UploadBatchStore::claim_finalization( $final_batch_id, $final_secret, $final_binding, $field, $final_resolved['items'], 'submission-01', $uploads_dir, $now + 10 );
    eforms_test_assert( $claimed['ok'] === true && $claimed['batch']['state'] === 'finalizing', 'Finalization should durably freeze the batch under one submission claim.' );
    $final_staged_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $final_batch_id ) . '/' . $final_batch_id;
    eforms_test_assert( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $final_staged_path ) === $final_staged_path . UploadBatchStore::LOCK_FILENAME, 'The store should own the adjacent staged lock layout.' );
    eforms_test_assert( is_file( $final_staged_path . '.lock' ), 'Staged aggregate locks should live outside the directory that finalization renames.' );
    eforms_test_assert( ! is_file( $final_staged_path . '/' . UploadBatchStore::LOCK_FILENAME ), 'Staged finalization should not hold an in-aggregate lock file across rename.' );
    $foreign_claim = UploadBatchStore::claim_finalization( $final_batch_id, $final_secret, $final_binding, $field, $final_resolved['items'], 'submission-02', $uploads_dir, $now + 10 );
    eforms_test_assert( $foreign_claim['ok'] === false, 'A foreign finalization claim should fail closed.' );
    eforms_test_assert( UploadBatchStore::delete_item( $final_batch_id, $final_secret, 'final_photo_replacement', $uploads_dir, $now + 10 )['ok'] === false, 'Finalizing aggregates should reject deletion.' );
    eforms_test_assert( UploadBatchStore::preview_bytes( $final_batch_id, $final_secret, 'final_photo_replacement', $uploads_dir, $now + 10 )['ok'] === false, 'Finalizing aggregates should reject staged preview reads.' );

    $cleanup_deadline = $final_created['batch']['delete_after'];
    $expired_recovery = UploadBatchStore::resolve_recovery( $final_batch_id, $final_secret, $final_binding, $field, 'submission-01', $uploads_dir, $cleanup_deadline );
    eforms_test_assert( empty( $expired_recovery['ok'] ) && $expired_recovery['reason'] === 'recovery_denied', 'Recovery should become terminal exactly at the staged cleanup deadline.' );
    $expired_reopen = UploadBatchStore::reopen_claim( $final_batch_id, 'submission-01', $uploads_dir, $cleanup_deadline );
    eforms_test_assert( empty( $expired_reopen['ok'] ) && $expired_reopen['reason'] === 'claim_reopen_denied', 'A staged claim should not reopen at its cleanup deadline.' );
    $expired_finalize = UploadBatchStore::finalize( $final_batch_id, 'submission-01', $uploads_dir, $cleanup_deadline );
    eforms_test_assert( empty( $expired_finalize['ok'] ) && $expired_finalize['reason'] === 'finalize_claim_mismatch', 'Finalization should not refresh retention at the staged cleanup deadline.' );

    $finalized = UploadBatchStore::finalize( $final_batch_id, 'submission-01', $uploads_dir, $now + 20 );
    eforms_test_assert( $finalized['ok'] === true, 'A matching claim should atomically finalize the aggregate.' );
    eforms_upload_batch_store_assert_protected_dir( $uploads_dir . '/eforms-private/' . UploadBatchStore::SUBMISSIONS_DIR, 'submission root' );
    eforms_test_assert( ! file_exists( $final_staged_path . '.lock' ), 'Finalization should remove the external staged lock after the aggregate is renamed.' );
    eforms_test_assert(
        $finalized['submission']['gallery_expires_at'] === $now + 20 + Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' )
            && $finalized['submission']['delete_after'] === $finalized['submission']['gallery_expires_at'],
        'Finalization should replace staged cleanup with the fixed gallery retention deadline.'
    );
    $former_status = UploadBatchStore::status( $final_batch_id, $final_secret, $uploads_dir, $now + 20 );
    eforms_test_assert( $former_status['ok'] === false && ! empty( $former_status['gone'] ), 'The former batch path should return the generic terminal result after rename.' );
    $after_finalize = eforms_test_managed_capacity_record( $uploads_dir );
    eforms_test_assert( $after_finalize['total_bytes'] === $before_finalize['total_bytes'], 'Aggregate rename should have zero capacity delta.' );

    $submission = UploadBatchStore::submission( 'submission-01', $uploads_dir, $now + 20 );
    eforms_test_assert( $submission['ok'] === true && count( $submission['submission']['items'] ) === 1, 'Finalized reads should return bounded manifest summaries.' );
    $original_read = UploadBatchStore::submission_file( 'submission-01', 'final_photo_replacement', 'original', $uploads_dir, $now + 20 );
    eforms_test_assert( $original_read['ok'] === true && is_resource( $original_read['stream'] ), 'Finalized reads should open an exact manifest-owned variant inside the store boundary.' );
    if ( isset( $original_read['stream'] ) && is_resource( $original_read['stream'] ) ) {
        fclose( $original_read['stream'] );
    }
    $submission_path = $private_dir . '/submissions/' . Helpers::h2( 'submission-01' ) . '/submission-01';
    eforms_test_assert( UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $submission_path ) === $submission_path . '/' . UploadBatchStore::LOCK_FILENAME, 'The store should own the internal finalized lock layout.' );
    $submission_root = $private_dir . '/' . UploadBatchStore::SUBMISSIONS_DIR;
    $submission_index_path = $submission_root . '/' . PrivateDir::INDEX_FILENAME;
    $submission_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $submission_path );
    unlink( $submission_index_path );
    $unprotected_submission = UploadBatchStore::submission( 'submission-01', $uploads_dir, $now + 20 );
    eforms_test_assert( empty( $unprotected_submission['ok'] ) && ! file_exists( $submission_index_path ), 'Gallery reads should fail closed without recreating missing submission protection files.' );
    file_put_contents( $submission_index_path, PrivateDir::INDEX_CONTENT );
    chmod( $submission_index_path, 0600 );
    unlink( $submission_lock_path );
    $unlocked_submission = UploadBatchStore::submission( 'submission-01', $uploads_dir, $now + 20 );
    eforms_test_assert( empty( $unlocked_submission['ok'] ) && $unlocked_submission['reason'] === 'submission_lock_failed' && ! file_exists( $submission_lock_path ), 'Gallery reads should fail closed without recreating a missing aggregate lock.' );
    file_put_contents( $submission_lock_path, '' );
    chmod( $submission_lock_path, 0600 );
    $submission_manifest_path = $submission_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $submission_manifest = json_decode( file_get_contents( $submission_manifest_path ), true );
    $submission_manifest['claim']['submission_id'] = 'submission-02';
    file_put_contents( $submission_manifest_path, json_encode( $submission_manifest ) );
    $mismatched_submission = UploadBatchStore::submission( 'submission-01', $uploads_dir, $now + 20 );
    eforms_test_assert( $mismatched_submission['ok'] === false, 'A finalized manifest must match the submission directory through which it was opened.' );
    $submission_manifest['claim']['submission_id'] = 'submission-01';
    file_put_contents( $submission_manifest_path, json_encode( $submission_manifest ) );
    eforms_test_assert( UploadBatchStore::mark_email_attempted( 'submission-01', $uploads_dir, $now + 30 )['ok'] === true, 'The first email-attempt marker should persist.' );
    eforms_test_assert( UploadBatchStore::mark_email_attempted( 'submission-01', $uploads_dir, $now + 31 )['ok'] === false, 'The email-attempt marker should be at most once.' );

    $record = json_decode( file_get_contents( $capacity_path ), true );
    $record['total_bytes'] += 999999;
    $record['reservations']['stale'] = array(
        'batch_id' => $batch_id,
        'upload_id' => 'crashed',
        'bytes' => 999999,
        'created_at' => $now - 100,
    );
    file_put_contents( $capacity_path, json_encode( $record ) );
    chmod( $capacity_path, 0600 );
    $reconciled = UploadBatchStore::reconcile_capacity( $uploads_dir, $now - 1 );
    eforms_test_assert( $reconciled['ok'] === true && ! isset( $reconciled['capacity']['reservations']['stale'] ), 'Reconciliation should remove a caller-declared stale reservation.' );
    eforms_test_assert( $reconciled['capacity']['total_bytes'] === $before_finalize['total_bytes'], 'Reconciliation should settle to the exact managed original and preview bytes.' );
}

$missing = UploadBatchStore::status( str_repeat( 'A', 43 ), $secret, $uploads_dir, $now );
eforms_test_assert( $missing['ok'] === false && ! empty( $missing['gone'] ), 'A nonexistent batch should share the generic terminal result.' );
$expired = UploadBatchStore::status( $batch_id, $secret, $uploads_dir, $created['batch']['delete_after'] );
eforms_test_assert( $expired['ok'] === false && ! empty( $expired['gone'] ), 'Cleanup expiry should share the generic terminal result before credential disclosure.' );

$corrupt_binding = eforms_test_batch_binding( 'token-corrupt-01', 'corrupt_photos', $now + 3600 );
$corrupt_created = UploadBatchStore::create_batch( $corrupt_binding, eforms_test_batch_secret( "\x44" ), $field, $uploads_dir, $now );
eforms_test_assert( $corrupt_created['ok'] === true, 'The corrupt-manifest fixture should create normally.' );
$corrupt_batch_path = $private_dir . '/staged/' . Helpers::h2( $corrupt_created['batch']['batch_id'] ) . '/' . $corrupt_created['batch']['batch_id'];
$corrupt_manifest_path = $corrupt_batch_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
$corrupt_manifest = json_decode( file_get_contents( $corrupt_manifest_path ), true );
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
