<?php
/**
 * Control-plane backup/restore drill for one authoritative R2 artifact.
 *
 * Contract: Managed Aggregate Contract
 * Contract: Runtime Storage backup and restore
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';
require_once __DIR__ . '/../../src/Uploads/ReviewController.php';
require_once __DIR__ . '/../../src/Uploads/WorkerClient.php';

$provider_integration = getenv( 'EFORMS_REMOTE_RESTORE_INTEGRATION' ) === '1';
$key_bytes = $provider_integration
    ? WorkerProtocol::decode_integration_key( getenv( 'EFORMS_WORKER_ACTIVE_KEY_B64' ) )
    : str_repeat( 'R', Anchors::get( 'WORKER_INTEGRATION_KEY_BYTES' ) );
$key_b64 = $provider_integration
    ? getenv( 'EFORMS_WORKER_ACTIVE_KEY_B64' )
    : rtrim( strtr( base64_encode( $key_bytes ), '+/', '-_' ), '=' );
if ( $provider_integration ) {
    foreach ( array( 'EFORMS_WORKER_URL', 'EFORMS_SITE_ORIGIN', 'EFORMS_WORKER_ENVIRONMENT_ID', 'EFORMS_WORKER_ACTIVE_KEY_ID', 'EFORMS_WORKER_ACTIVE_KEY_B64' ) as $required_name ) {
        eforms_test_assert( is_string( getenv( $required_name ) ) && getenv( $required_name ) !== '', 'Missing restore integration setting: ' . $required_name );
    }
    eforms_test_assert( is_string( $key_bytes ) && strlen( $key_bytes ) === Anchors::get( 'WORKER_INTEGRATION_KEY_BYTES' ), 'Restore integration key must satisfy the protocol byte contract.' );
}
define( 'EFORMS_UPLOAD_COMPOSITION', 'worker_r2_cloudflare' );
define( 'EFORMS_WORKER_URL', $provider_integration ? getenv( 'EFORMS_WORKER_URL' ) : 'https://media.example.test' );
define( 'EFORMS_WORKER_ENVIRONMENT_ID', $provider_integration ? getenv( 'EFORMS_WORKER_ENVIRONMENT_ID' ) : 'restore-drill' );
define( 'EFORMS_WORKER_ACTIVE_KEY_ID', $provider_integration ? getenv( 'EFORMS_WORKER_ACTIVE_KEY_ID' ) : 'restore-key' );
define( 'EFORMS_WORKER_ACTIVE_KEY_B64', $key_b64 );

$root = eforms_test_tmp_root( 'eforms-remote-restore' );
$uploads_dir = $root . '/uploads';
$backup_dir = $root . '/backup/eforms-private';
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        return $config;
    }
);
Config::reset_for_tests();

$created_at = $provider_integration ? time() : 1700000000;
$field = array(
    'type' => 'files', 'upload_mode' => 'staged', 'accept' => array( 'image' ),
    'max_file_bytes' => 1048576, 'max_files' => 2, 'max_total_bytes' => 2097152,
);
$binding = array(
    'raw_token' => 'restore-token', 'form_id' => 'virtual-estimate',
    'instance_id' => 'restore-instance', 'field_key' => 'project_photos',
    'accept_until' => $created_at + 3600,
);
$secret = rtrim( strtr( base64_encode( str_repeat( 'S', Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
$created = UploadBatchStore::create_batch(
    $binding,
    $secret,
    $field,
    $uploads_dir,
    $created_at,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    WorkerClient::composition_fingerprint()
);
eforms_test_assert( ! empty( $created['ok'] ), 'Restore drill should create one batch.' );
$batch_id = $created['batch']['batch_id'];
$upload_id = 'restore_photo';
$artifact_bytes = $provider_integration
    ? eforms_test_fixture_bytes( 'staged-landscape.png' )
    : str_repeat( 'x', 4096 );
$artifact_size = strlen( $artifact_bytes );
$authorized = UploadBatchStore::authorize_intent(
    $batch_id, $secret, $upload_id, 0, 'restored-phone.png', $artifact_size, 'image/png', 0,
    $uploads_dir,
    array(
        'now' => $created_at + 1,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
        'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
    )
);
eforms_test_assert( ! empty( $authorized['ok'] ), 'Restore drill should persist its intent and capacity reservation.' );
$intent = $authorized['intent'];
$provider_cleanup = array();
if ( $provider_integration ) {
    register_shutdown_function(
        function () use ( &$provider_cleanup ) {
            $failures = 0;
            foreach ( $provider_cleanup as $cleanup ) {
                $deleted = WorkerClient::delete_object(
                    $cleanup['object_key'],
                    $cleanup['object_version'],
                    WorkerClient::composition_fingerprint(),
                    time(),
                    'eforms_test_restore_worker_request',
                    'direct_cleanup'
                );
                if ( empty( $deleted['ok'] ) || empty( $deleted['absent'] ) ) {
                    $failures++;
                }
            }
            if ( $failures > 0 ) {
                fwrite( STDERR, 'WARNING: genuine restore cleanup left ' . $failures . " disposable provider object(s); manual namespace cleanup is required.\n" );
            }
        }
    );
}
$receipt = $provider_integration
    ? eforms_test_restore_upload_artifact( $intent, $batch_id, $artifact_bytes, $created_at, $key_bytes, $provider_cleanup )
    : array(
        'intent_id' => $intent['intent_id'], 'batch_id' => $batch_id, 'upload_id' => $upload_id,
        'ordinal' => 0, 'object_key' => $intent['object_key'], 'object_version' => 'restore-object-version-1',
        'etag' => 'restore-etag', 'bytes' => $artifact_size, 'mime' => 'image/png',
        'width' => 32, 'height' => 24, 'policy_fingerprint' => $intent['policy_fingerprint'],
        'expires_at' => $created_at + 900,
    );
$version = $receipt['object_version'];
$completed = UploadBatchStore::complete_receipt(
    $batch_id,
    $secret,
    $upload_id,
    $receipt,
    $uploads_dir,
    $created_at + 2
);
eforms_test_assert( ! empty( $completed['ok'] ), 'Restore drill should commit immutable provider facts.' );
$unknown_delete = UploadBatchStore::delete_item( $batch_id, $secret, 'never_authorized', $uploads_dir, $created_at + 2 );
eforms_test_assert( ! empty( $unknown_delete['ok'] ), 'A zero-byte terminal ID should not create phantom remote capacity.' );
$resolved = UploadBatchStore::resolve_open( $batch_id, $secret, $binding, $field, $uploads_dir, $created_at + 3 );
$submission_id = 'restore-submission';
$claimed = UploadBatchStore::claim_finalization( $batch_id, $secret, $binding, $field, $resolved['items'], $submission_id, $uploads_dir, $created_at + 3 );
$finalized = ! empty( $claimed['ok'] )
    ? UploadBatchStore::finalize( $batch_id, $submission_id, $uploads_dir, $created_at + 4 )
    : array( 'ok' => false );
eforms_test_assert( ! empty( $finalized['ok'] ), 'Restore drill should finalize the authoritative remote submission.' );

$open_fixture = eforms_test_restore_authorized_batch( 'open', 'O', $provider_integration ? $artifact_size : 2048, $field, $uploads_dir, $created_at + 10 );
$pending_fixture = eforms_test_restore_authorized_batch( 'pending-delete', 'D', $provider_integration ? $artifact_size : 3072, $field, $uploads_dir, $created_at + 20 );
if ( $provider_integration ) {
    foreach ( array( &$open_fixture, &$pending_fixture ) as &$materialized_fixture ) {
        $materialized_receipt = eforms_test_restore_upload_artifact(
            $materialized_fixture['intent'],
            $materialized_fixture['batch_id'],
            $artifact_bytes,
            $created_at + 21,
            $key_bytes,
            $provider_cleanup
        );
        $materialized_fixture['object_version'] = $materialized_receipt['object_version'];
        $materialized_present = WorkerClient::inspect_object(
            $materialized_fixture['intent']['object_key'],
            $materialized_receipt['object_version'],
            WorkerClient::composition_fingerprint(),
            time(),
            'eforms_test_restore_worker_request',
            'restore_pre_backup'
        );
        eforms_test_assert( ! empty( $materialized_present['ok'] ) && ! empty( $materialized_present['present'] ), 'Provider-backed restore fixtures must be materialized before backup.' );
    }
    unset( $materialized_fixture );
}
$pending_delete = UploadBatchStore::delete_item(
    $pending_fixture['batch_id'],
    $pending_fixture['secret'],
    $pending_fixture['upload_id'],
    $uploads_dir,
    $created_at + 22
);
eforms_test_assert(
    ! empty( $pending_delete['ok'] ) && ! empty( $pending_delete['physical_delete_pending'] ),
    'Restore backup should include one remote tombstone whose exact physical cleanup is pending.'
);

$private_dir = $uploads_dir . '/eforms-private';
eforms_test_restore_copy_tree( $private_dir, $backup_dir );
eforms_test_remove_tree( $private_dir );
eforms_test_assert( ! is_dir( $private_dir ), 'Restore drill should remove only its disposable control-plane copy.' );
eforms_test_restore_copy_tree( $backup_dir, $private_dir );

$lease = PrivateDir::acquire_write_lease( $uploads_dir );
eforms_test_assert( $lease instanceof PrivateDirLease, 'Restored private state should reacquire its lifecycle lease.' );
$capacity = UploadBatchStore::capacity_health( $uploads_dir, $lease );
$lease->release();
eforms_test_assert( ! empty( $capacity['ok'] ) && ! empty( $capacity['capacity']['consistent'] ), 'Restored manifests and capacity record should remain mutually consistent.' );
$restored_total = $artifact_size + $open_fixture['bytes'] + $pending_fixture['bytes'];
eforms_test_assert( $capacity['capacity']['total_bytes'] === $restored_total && $capacity['capacity']['authority_bytes'] === $restored_total, 'Restore should conservatively retain finalized, open, and pending-delete byte charges.' );

$open_status = UploadBatchStore::status( $open_fixture['batch_id'], $open_fixture['secret'], $uploads_dir, $created_at + 30 );
eforms_test_assert(
    ! empty( $open_status['ok'] )
        && count( $open_status['batch']['intents'] ) === 1
        && $open_status['batch']['intents'][0]['upload_id'] === $open_fixture['upload_id'],
    'Restore should preserve one open aggregate and its unresolved intent.'
);
$pending_manifest_path = $private_dir . '/' . UploadBatchStore::STAGED_DIR . '/' . Helpers::h2( $pending_fixture['batch_id'] ) . '/' . $pending_fixture['batch_id'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$pending_manifest = json_decode( file_get_contents( $pending_manifest_path ), true );
$pending_tombstone = is_array( $pending_manifest ) && isset( $pending_manifest['tombstones'][ $pending_fixture['upload_id'] ] )
    ? $pending_manifest['tombstones'][ $pending_fixture['upload_id'] ]
    : null;
eforms_test_assert(
    is_array( $pending_tombstone )
        && ! empty( $pending_tombstone['capacity_release_started'] )
        && empty( $pending_tombstone['capacity_released'] ),
    'Restore should preserve the exact pending-delete checkpoint instead of releasing accounting early.'
);

$review = UploadBatchStore::submission_file( $submission_id, $upload_id, $uploads_dir, $created_at + 5 );
eforms_test_assert( ! empty( $review['ok'] ), 'Restored finalized authority should recover the authorized-review artifact prerequisite.' );
$artifact = $review['artifact'];
eforms_test_assert(
    $artifact['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER
        && $artifact['object_key'] === $intent['object_key']
        && $artifact['object_version'] === $version,
    'Review prerequisites should retain the immutable store and exact provider identity.'
);

$review_salt = 'remote-review-salt';
$gallery_url = ReviewController::gallery_url( $submission_id, 'https://forms.example.test', $review_salt );
$gallery = ReviewController::dispatch_current_request(
    array( 'method' => 'GET', 'uri' => $gallery_url ),
    array( 'uploads_dir' => $uploads_dir, 'salt' => $review_salt, 'now' => $created_at + 5, 'base_url' => 'https://forms.example.test' )
);
$review_item = $gallery['review_page']['items'][0];
eforms_test_assert(
    strpos( $review_item['preview_url'], 'eforms_review_preview=' . $upload_id ) !== false
        && strpos( $review_item['download_url'], 'eforms_review_upload=' . $upload_id ) !== false
        && strpos( json_encode( $review_item ), 'media.example.test' ) === false,
    'The gallery should retain WordPress-owned member URLs and never embed an expiring Worker grant.'
);
foreach ( array( 'preview_url' => 'preview', 'download_url' => 'download' ) as $url_key => $action ) {
    $member_now = $created_at + 5;
    $member = ReviewController::dispatch_current_request(
        array( 'method' => 'GET', 'uri' => $review_item[ $url_key ] ),
        array( 'uploads_dir' => $uploads_dir, 'salt' => $review_salt, 'now' => $member_now, 'base_url' => 'https://forms.example.test' )
    );
    eforms_test_assert(
        $member['status'] === 302
            && $member['location'] !== ''
            && $member['redirect_origin'] === WorkerClient::origin()
            && ! isset( $member['headers']['Location'] ),
        'Each authorized member request should delegate one freshly minted Worker redirect to the WordPress runtime owner.'
    );
    $query = array();
    parse_str( (string) parse_url( $member['location'], PHP_URL_QUERY ), $query );
    $grant_expiry = min( $artifact['delete_after'], $member_now + Anchors::get( 'WORKER_REVIEW_GRANT_TTL_SECONDS' ) );
    $expected = WorkerProtocol::sign_review_grant(
        array(
            'submission_id' => $submission_id, 'upload_id' => $upload_id,
            'object_key' => $artifact['object_key'], 'object_version' => $artifact['object_version'],
            'action' => $action, 'recipe_version' => WorkerProtocol::REVIEW_RECIPE_VERSION,
            'expires_at' => $grant_expiry,
        ),
        EFORMS_WORKER_ACTIVE_KEY_ID,
        $key_bytes,
        EFORMS_WORKER_ENVIRONMENT_ID
    );
    eforms_test_assert( isset( $query[ WorkerClient::REVIEW_QUERY ] ) && hash_equals( $expected, $query[ WorkerClient::REVIEW_QUERY ] ), 'Each Worker review URL should be bound to the exact manifest artifact, action, recipe, and bounded expiry.' );
    if ( $provider_integration ) {
        $delivered = eforms_test_restore_get( $member['location'] );
        eforms_test_assert( $delivered['status'] === 200, 'Restored authority should reach genuine provider review delivery.' );
        if ( $action === 'preview' ) {
            eforms_test_assert( isset( $delivered['headers']['content-type'] ) && strpos( $delivered['headers']['content-type'], 'image/jpeg' ) === 0 && strlen( $delivered['body'] ) > 3, 'Genuine restored preview should return private JPEG bytes.' );
        } else {
            eforms_test_assert( hash_equals( hash( 'sha256', $artifact_bytes ), hash( 'sha256', $delivered['body'] ) ), 'Genuine restored download should preserve the exact authoritative bytes.' );
        }
    }

    $later_now = $member_now + Anchors::get( 'WORKER_REVIEW_GRANT_TTL_SECONDS' ) + 1;
    $later = ReviewController::dispatch_current_request(
        array( 'method' => 'GET', 'uri' => $review_item[ $url_key ] ),
        array( 'uploads_dir' => $uploads_dir, 'salt' => $review_salt, 'now' => $later_now, 'base_url' => 'https://forms.example.test' )
    );
    eforms_test_assert(
        $later['status'] === 302 && $later['location'] !== $member['location'],
        'A still-valid WordPress member bearer should mint a new Worker grant after the prior grant expires.'
    );
}

eforms_test_assert( ! empty( UploadBatchStore::update_finalized_availability( $submission_id, $uploads_dir, null, $created_at + 6 )['ok'] ), 'The restored Worker review fixture should support manual-only availability.' );
$manual_member_now = $created_at + 7;
$manual_member = ReviewController::dispatch_current_request(
    array( 'method' => 'GET', 'uri' => $review_item['download_url'] ),
    array( 'uploads_dir' => $uploads_dir, 'salt' => $review_salt, 'now' => $manual_member_now, 'base_url' => 'https://forms.example.test' )
);
$manual_query = array();
parse_str( (string) parse_url( $manual_member['location'], PHP_URL_QUERY ), $manual_query );
$manual_expected = WorkerProtocol::sign_review_grant(
    array(
        'submission_id' => $submission_id, 'upload_id' => $upload_id,
        'object_key' => $artifact['object_key'], 'object_version' => $artifact['object_version'],
        'action' => 'download', 'recipe_version' => WorkerProtocol::REVIEW_RECIPE_VERSION,
        'expires_at' => $manual_member_now + Anchors::get( 'WORKER_REVIEW_GRANT_TTL_SECONDS' ),
    ),
    EFORMS_WORKER_ACTIVE_KEY_ID,
    $key_bytes,
    EFORMS_WORKER_ENVIRONMENT_ID
);
eforms_test_assert(
    $manual_member['status'] === 302
        && isset( $manual_query[ WorkerClient::REVIEW_QUERY ] )
        && hash_equals( $manual_expected, $manual_query[ WorkerClient::REVIEW_QUERY ] ),
    'Manual-only restored Worker reviews should mint grants bounded only by the Worker grant TTL.'
);
eforms_test_assert( ! empty( UploadBatchStore::update_finalized_availability( $submission_id, $uploads_dir, $finalized['submission']['delete_after'], $created_at + 8 )['ok'] ), 'The restored Worker review fixture should restore its numeric deletion ceiling for the GC drill.' );

$requester = $provider_integration ? 'eforms_test_restore_worker_request' : function ( $url, $arguments ) use ( $key_bytes ) {
    $claims = eforms_test_restore_object_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ] );
    eforms_test_assert( $claims['action'] === 'inspect', 'Restore verification must be read-only.' );
    return array(
        'status' => 200,
        'body' => json_encode(
            array(
                'result' => eforms_test_restore_sign_result(
                    array(
                        'request_id' => $claims['request_id'], 'object_key' => $claims['object_key'],
                        'object_version' => $claims['object_version'], 'status' => 'present',
                        'expires_at' => $claims['expires_at'],
                    ),
                    $key_bytes
                ),
            )
        ),
    );
};
if ( $provider_integration ) {
    $wrong_version = WorkerClient::inspect_object(
        $artifact['object_key'],
        $artifact['object_version'] . '-wrong',
        $artifact['artifact_store_identity'],
        $created_at + 5,
        $requester
    );
    eforms_test_assert( empty( $wrong_version['ok'] ), 'Restore sign-off must reject the wrong provider object version.' );
}
$inspected = WorkerClient::inspect_object(
    $artifact['object_key'],
    $artifact['object_version'],
    $artifact['artifact_store_identity'],
    $created_at + 5,
    $requester
);
eforms_test_assert( ! empty( $inspected['ok'] ) && ! empty( $inspected['present'] ), 'Restore sign-off should confirm the exact R2 version before relying on restored authority.' );

$staged_deleted = array();
$staged_gc = array( 'ok' => true );
for ( $attempt = 0; $attempt < 4; $attempt++ ) {
    $cleanup_now = max( $open_fixture['delete_after'], $pending_fixture['delete_after'] ) + 1
        + $attempt * (
            Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' )
            + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' )
            + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' )
            + 2
        );
    $staged_gc = UploadBatchStore::gc_aggregates(
        'staged',
        $uploads_dir,
        $cleanup_now,
        10,
        false,
        array(),
        function ( $object_key, $object_version ) use ( &$staged_deleted, $provider_integration, $requester ) {
            $staged_deleted[] = $object_key;
            if ( $provider_integration ) {
                return WorkerClient::delete_object(
                    $object_key,
                    $object_version,
                    WorkerClient::composition_fingerprint(),
                    time(),
                    $requester,
                    'restore_staged_cleanup'
                );
            }
            return array( 'ok' => true, 'absent' => true );
        }
    );
    eforms_test_assert(
        ! empty( $staged_gc['ok'] ) && $staged_gc['errors'] === 0,
        'Each restored staged-cleanup pass should complete without aggregate errors: ' . json_encode( $staged_gc, JSON_UNESCAPED_SLASHES )
    );
}
$staged_deleted = array_values( array_unique( $staged_deleted ) );
sort( $staged_deleted, SORT_STRING );
$expected_staged_deleted = array( $open_fixture['intent']['object_key'], $pending_fixture['intent']['object_key'] );
sort( $expected_staged_deleted, SORT_STRING );
eforms_test_assert(
    ! empty( $staged_gc['ok'] ) && $staged_deleted === $expected_staged_deleted,
    'Restored open and pending-delete aggregates should resume exact remote cleanup.'
);
if ( $provider_integration ) {
    unset( $provider_cleanup[ $open_fixture['intent']['object_key'] ], $provider_cleanup[ $pending_fixture['intent']['object_key'] ] );
}
$lease = PrivateDir::acquire_write_lease( $uploads_dir );
$after_staged = UploadBatchStore::capacity_health( $uploads_dir, $lease );
$lease->release();
eforms_test_assert(
    ! empty( $after_staged['ok'] ) && $after_staged['capacity']['total_bytes'] === $artifact_size,
    'Resumed staged cleanup should release only open and pending-delete accounting.'
);

$deleted = array();
$gc = UploadBatchStore::gc_aggregates(
    'finalized',
    $uploads_dir,
    $finalized['submission']['delete_after'] + 1,
    10,
    false,
    array(),
    function ( $object_key, $object_version ) use ( &$deleted, $provider_integration, $artifact, $requester ) {
        $deleted[] = array( $object_key, $object_version );
        if ( $provider_integration ) {
            return WorkerClient::delete_object(
                $object_key,
                $object_version,
                $artifact['artifact_store_identity'],
                time(),
                $requester,
                'gc'
            );
        }
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert( ! empty( $gc['ok'] ) && $gc['deleted'] === 1, 'Restored authority should remain eligible for ordinary manifest-driven deletion.' );
eforms_test_assert( $deleted === array( array( $intent['object_key'], $version ) ), 'Deletion after restore should target the exact inspected version.' );
$lease = PrivateDir::acquire_write_lease( $uploads_dir );
$after = UploadBatchStore::capacity_health( $uploads_dir, $lease );
$lease->release();
eforms_test_assert( ! empty( $after['ok'] ) && $after['capacity']['total_bytes'] === 0, 'Confirmed deletion should release restored accounting exactly once.' );
if ( $provider_integration ) {
    unset( $provider_cleanup[ $intent['object_key'] ] );
}

$purge_fixture = eforms_test_restore_authorized_batch( 'purge-resume', 'P', $provider_integration ? $artifact_size : 1024, $field, $uploads_dir, $created_at + 40 );
if ( $provider_integration ) {
    $purge_receipt = eforms_test_restore_upload_artifact( $purge_fixture['intent'], $purge_fixture['batch_id'], $artifact_bytes, $created_at + 41, $key_bytes, $provider_cleanup );
    $purge_present = WorkerClient::inspect_object(
        $purge_fixture['intent']['object_key'],
        $purge_receipt['object_version'],
        WorkerClient::composition_fingerprint(),
        time(),
        'eforms_test_restore_worker_request',
        'restore_pre_purge_backup'
    );
    eforms_test_assert( ! empty( $purge_present['ok'] ) && ! empty( $purge_present['present'] ), 'Interrupted-purge restore fixture must be materialized before backup.' );
}
$purge_lease = PrivateDir::acquire_purge_lease( $uploads_dir );
eforms_test_assert( $purge_lease instanceof PrivateDirLease, 'Purge restore fixture should acquire the exclusive lifecycle lease.' );
$prepared_purge = UploadBatchStore::prepare_remote_purge( $purge_lease, WorkerClient::composition_fingerprint(), $created_at + 42 );
eforms_test_assert( ! empty( $prepared_purge['ok'] ) && empty( $prepared_purge['ready'] ), 'Purge restore fixture should persist its barrier and resumable drain state.' );
$purge_lease->release();
$purge_backup = $root . '/purge-backup/eforms-private';
eforms_test_restore_copy_tree( $private_dir, $purge_backup );
eforms_test_remove_tree( $private_dir );
eforms_test_restore_copy_tree( $purge_backup, $private_dir );
eforms_test_assert(
    is_file( $private_dir . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME )
        && is_file( $private_dir . '/' . PrivateDir::PURGE_MARKER_FILENAME ),
    'Restore should retain both the remote-purge checkpoint and its admission barrier.'
);
$purge_lease = PrivateDir::acquire_purge_lease( $uploads_dir );
eforms_test_assert( $purge_lease instanceof PrivateDirLease, 'Restored purge state should reacquire its exclusive lifecycle lease.' );
$purge_deleted = array();
$purge_delete = function ( $object_key, $object_version ) use ( &$purge_deleted, $provider_integration, $requester ) {
    $purge_deleted[] = $object_key;
    if ( $provider_integration ) {
        return WorkerClient::delete_object(
            $object_key,
            $object_version,
            WorkerClient::composition_fingerprint(),
            time(),
            $requester,
            'restore_purge_resume'
        );
    }
    return array( 'ok' => true, 'absent' => true );
};
$purge_result = array( 'ok' => true, 'ready' => false );
for ( $attempt = 0; $attempt < 5 && empty( $purge_result['ready'] ); $attempt++ ) {
    $purge_result = UploadBatchStore::resume_remote_purge(
        $purge_lease,
        WorkerClient::composition_fingerprint(),
        $purge_delete,
        $prepared_purge['safe_after'] + $attempt
    );
    eforms_test_assert( ! empty( $purge_result['ok'] ), 'Restored remote purge should resume without losing its cursor or barrier.' );
}
$purge_record = json_decode( file_get_contents( $private_dir . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME ), true );
$purge_capacity = json_decode( file_get_contents( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME ), true );
$purge_lease->release();
eforms_test_assert(
    ! empty( $purge_result['ready'] )
        && is_array( $purge_record )
        && $purge_record['next_family'] === 'done'
        && is_array( $purge_capacity )
        && $purge_capacity['total_bytes'] === 0
        && in_array( $purge_fixture['intent']['object_key'], $purge_deleted, true ),
    'Restored purge state should resume exact reservation cleanup and release its conservative charge.'
);
if ( $provider_integration ) {
    unset( $provider_cleanup[ $purge_fixture['intent']['object_key'] ] );
}

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $root );
echo $provider_integration ? "Genuine remote restore drill passed.\n" : "Remote restore drill passed.\n";

function eforms_test_restore_authorized_batch( $label, $secret_byte, $bytes, $field, $uploads_dir, $now ) {
    $binding = array(
        'raw_token' => 'restore-' . $label . '-token',
        'form_id' => 'virtual-estimate',
        'instance_id' => 'restore-' . $label . '-instance',
        'field_key' => 'project_photos',
        'accept_until' => $now + 3600,
    );
    $secret = rtrim(
        strtr( base64_encode( str_repeat( $secret_byte, Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ),
        '='
    );
    $created = UploadBatchStore::create_batch(
        $binding,
        $secret,
        $field,
        $uploads_dir,
        $now,
        FormProtocol::UPLOAD_TRANSPORT_WORKER,
        WorkerClient::composition_fingerprint()
    );
    eforms_test_assert( ! empty( $created['ok'] ), 'Restore control-plane fixture should create the ' . $label . ' aggregate.' );
    $upload_id = 'restore_' . str_replace( '-', '_', $label );
    $authorized = UploadBatchStore::authorize_intent(
        $created['batch']['batch_id'],
        $secret,
        $upload_id,
        0,
        $label . '.png',
        $bytes,
        'image/png',
        0,
        $uploads_dir,
        array(
            'now' => $now + 1,
            'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
            'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
        )
    );
    eforms_test_assert( ! empty( $authorized['ok'] ), 'Restore control-plane fixture should authorize the ' . $label . ' intent.' );
    return array(
        'batch_id' => $created['batch']['batch_id'],
        'secret' => $secret,
        'upload_id' => $upload_id,
        'intent' => $authorized['intent'],
        'bytes' => $bytes,
        'delete_after' => $created['batch']['delete_after'],
    );
}

function eforms_test_restore_upload_artifact( $intent, $batch_id, $bytes, $now, $key_bytes, &$provider_cleanup ) {
    $configuration = WorkerClient::configuration();
    eforms_test_assert( is_array( $configuration ), 'Genuine restore drill requires valid Worker configuration.' );
    $grant_expires_at = min( $intent['expires_at'], $now + Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' ) );
    $grant = WorkerProtocol::sign_upload_grant(
        array(
            'intent_id' => $intent['intent_id'], 'batch_id' => $batch_id, 'upload_id' => $intent['upload_id'],
            'ordinal' => $intent['ordinal'], 'object_key' => $intent['object_key'],
            'declared_bytes' => $intent['declared_bytes'], 'declared_mime' => $intent['declared_mime'],
            'policy_fingerprint' => $intent['policy_fingerprint'], 'max_bytes' => $intent['declared_bytes'],
            'max_edge' => Anchors::get( 'MANAGED_ARTIFACT_MAX_EDGE' ), 'max_pixels' => Anchors::get( 'MANAGED_ARTIFACT_MAX_PIXELS' ),
            'container_entry_limit' => Anchors::get( 'MANAGED_HEIF_MAX_ASSOCIATION_ENTRIES' ),
            'intent_expires_at' => $intent['expires_at'], 'grant_expires_at' => $grant_expires_at,
            'upload_max_seconds' => Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' ),
            'receipt_ttl_seconds' => Anchors::get( 'WORKER_RECEIPT_TTL_SECONDS' ),
        ),
        $configuration['active_id'],
        $key_bytes,
        $configuration['environment']
    );
    eforms_test_assert( $grant !== '', 'Genuine restore drill should mint its exact upload grant.' );
    $provider_cleanup[ $intent['object_key'] ] = array(
        'object_key' => $intent['object_key'],
        'object_version' => '',
    );
    $response = eforms_test_restore_curl(
        $configuration['origin'] . '/v1/upload',
        'PUT',
        array(
            'Origin: ' . getenv( 'EFORMS_SITE_ORIGIN' ),
            'Content-Type: ' . $intent['declared_mime'],
            'Content-Length: ' . strlen( $bytes ),
            'X-EForms-Worker-Grant: ' . $grant,
        ),
        $bytes
    );
    $body = json_decode( $response['body'], true );
    $receipt = is_array( $body ) && isset( $body['receipt'] ) ? $body['receipt'] : '';
    $verified = $response['status'] === 200
        ? WorkerProtocol::verify_upload_receipt( $receipt, $configuration['keys'], $configuration['environment'], time() )
        : array( 'ok' => false );
    eforms_test_assert( ! empty( $verified['ok'] ) && isset( $verified['claims'] ), 'Genuine restore upload should return a valid immutable receipt.' );
    $provider_cleanup[ $intent['object_key'] ]['object_version'] = $verified['claims']['object_version'];
    return $verified['claims'];
}

function eforms_test_restore_worker_request( $url, $arguments ) {
    $headers = array();
    foreach ( $arguments['headers'] as $name => $value ) {
        $headers[] = $name . ': ' . $value;
    }
    $response = eforms_test_restore_curl( $url, 'POST', $headers, '' );
    return array( 'status' => $response['status'], 'body' => $response['body'] );
}

function eforms_test_restore_get( $url ) {
    return eforms_test_restore_curl( $url, 'GET', array(), '' );
}

function eforms_test_restore_curl( $url, $method, $headers, $body ) {
    eforms_test_assert( function_exists( 'curl_init' ), 'Genuine restore drill requires the PHP cURL extension.' );
    $response_headers = array();
    $response_body = '';
    $handle = curl_init( $url );
    $options = array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => Anchors::get( 'WORKER_SERVER_REQUEST_TIMEOUT_SECONDS' ),
        CURLOPT_HEADERFUNCTION => function ( $curl, $line ) use ( &$response_headers ) {
            $parts = explode( ':', $line, 2 );
            if ( count( $parts ) === 2 ) {
                $response_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
            }
            return strlen( $line );
        },
        CURLOPT_WRITEFUNCTION => function ( $curl, $chunk ) use ( &$response_body ) {
            if ( strlen( $response_body ) + strlen( $chunk ) > Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' ) ) {
                return 0;
            }
            $response_body .= $chunk;
            return strlen( $chunk );
        },
    );
    if ( $method !== 'GET' ) {
        $options[ CURLOPT_POSTFIELDS ] = $body;
    }
    curl_setopt_array( $handle, $options );
    $executed = curl_exec( $handle );
    $status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
    curl_close( $handle );
    eforms_test_assert( $executed === true && $status > 0, 'Genuine restore provider request should complete.' );
    return array( 'status' => $status, 'headers' => $response_headers, 'body' => $response_body );
}

function eforms_test_restore_copy_tree( $source, $destination ) {
    eforms_test_assert( is_dir( $source ) && ! is_link( $source ), 'Restore copy source must be a real directory.' );
    if ( ! is_dir( $destination ) ) {
        mkdir( $destination, 0700, true );
    }
    foreach ( new FilesystemIterator( $source, FilesystemIterator::SKIP_DOTS ) as $entry ) {
        eforms_test_assert( ! $entry->isLink(), 'Restore fixture refuses symlink traversal.' );
        $target = $destination . '/' . $entry->getFilename();
        if ( $entry->isDir() ) {
            eforms_test_restore_copy_tree( $entry->getPathname(), $target );
        } else {
            copy( $entry->getPathname(), $target );
            chmod( $target, 0600 );
        }
    }
}

function eforms_test_restore_object_claims( $token ) {
    $segment = explode( '.', $token )[0];
    $payload = base64_decode( strtr( $segment, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $segment ) % 4 ) % 4 ), true );
    $parts = array();
    for ( $offset = 0; is_string( $payload ) && $offset < strlen( $payload ); ) {
        $length = unpack( 'Nvalue', substr( $payload, $offset, 4 ) )['value'];
        $offset += 4;
        $parts[] = substr( $payload, $offset, $length );
        $offset += $length;
    }
    return array(
        'request_id' => $parts[4], 'object_key' => $parts[5], 'object_version' => $parts[6],
        'action' => $parts[7], 'expires_at' => (int) $parts[8],
    );
}

function eforms_test_restore_sign_result( $claims, $key_bytes ) {
    $parts = array_merge(
        array( WorkerProtocol::OBJECT_RESULT_DOMAIN, WorkerProtocol::VERSION, EFORMS_WORKER_ACTIVE_KEY_ID, EFORMS_WORKER_ENVIRONMENT_ID ),
        array_map( 'strval', array_values( $claims ) )
    );
    $payload = '';
    foreach ( $parts as $part ) {
        $payload .= pack( 'N', strlen( $part ) ) . $part;
    }
    $encode = function ( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    };
    return $encode( $payload ) . '.' . $encode( hash_hmac( 'sha256', $payload, $key_bytes, true ) );
}
