<?php
/**
 * Control-plane backup/restore drill for one authoritative R2 artifact.
 *
 * Contract: Managed Aggregate Contract
 * Contract: Runtime Storage backup and restore
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/managed_upload_fixtures.php';
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
$private_dir = $uploads_dir . '/eforms-private';
$worker_restore = eforms_test_restore_worker_item_fixture( $field, $uploads_dir, $created_at + 50 );
$worker_backup = $root . '/candidate-backup/eforms-private';
eforms_test_restore_copy_tree( $private_dir, $worker_backup );
eforms_test_remove_tree( $private_dir );
eforms_test_restore_copy_tree( $worker_backup, $private_dir );
$worker_manifest = json_decode( file_get_contents( $worker_restore['manifest_path'] ), true );
$worker_item = $worker_manifest['items'][ $worker_restore['upload_id'] ];
$worker_authority = array(
    'upload_id' => $worker_restore['upload_id'],
    'storage_identity' => $worker_item['storage_identity'],
    'validation_contract_version' => $worker_item['validation_contract_version'],
    'object_key' => $worker_item['object_key'],
    'object_version' => $worker_item['object_version'],
    'etag' => $worker_item['etag'],
    'bytes' => $worker_item['bytes'],
    'policy_fingerprint' => $worker_item['policy_fingerprint'],
    'expected_composition_fingerprint' => $worker_item['storage_identity'],
);
$worker_inspect_now = $created_at + 60;
$worker_requester = function ( $url, $arguments ) use ( $worker_authority, $worker_inspect_now, $key_bytes ) {
    $claims = eforms_test_restore_worker_object_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], $worker_inspect_now, $key_bytes );
    eforms_test_assert( $claims['action'] === 'inspect', 'Worker restore sign-off must be read-only.' );
    $exact = $claims['object_key'] === $worker_authority['object_key']
        && $claims['object_version'] === $worker_authority['object_version']
        && $claims['etag'] === $worker_authority['etag'];
    return array(
        'status' => 200,
        'body' => json_encode(
            array(
                'result' => eforms_test_restore_sign_worker_result(
                    array(
                        'request_id' => $claims['request_id'],
                        'object_key' => $claims['object_key'],
                        'object_version' => $claims['object_version'],
                        'status' => $exact ? 'present' : 'version_mismatch',
                        'expires_at' => $claims['expires_at'],
                    ),
                    $key_bytes
                ),
            )
        ),
    );
};
$worker_known = WorkerClient::worker_inspect_object(
    $worker_authority['upload_id'],
    $worker_authority['storage_identity'],
    $worker_authority['validation_contract_version'],
    $worker_authority['object_key'],
    $worker_authority['object_version'],
    $worker_authority['etag'],
    $worker_authority['bytes'],
    $worker_authority['policy_fingerprint'],
    $worker_authority['expected_composition_fingerprint'],
    $worker_inspect_now,
    $worker_requester,
    'restore_worker_signoff'
);
$worker_wrong = WorkerClient::worker_inspect_object(
    $worker_authority['upload_id'],
    $worker_authority['storage_identity'],
    $worker_authority['validation_contract_version'],
    $worker_authority['object_key'],
    $worker_authority['object_version'] . '-wrong',
    $worker_authority['etag'],
    $worker_authority['bytes'],
    $worker_authority['policy_fingerprint'],
    $worker_authority['expected_composition_fingerprint'],
    $worker_inspect_now,
    $worker_requester,
    'restore_worker_signoff'
);
$worker_mixed = WorkerClient::worker_inspect_object(
    $worker_authority['upload_id'],
    $worker_authority['storage_identity'],
    $worker_authority['validation_contract_version'],
    $worker_authority['object_key'],
    $worker_authority['object_version'],
    $worker_authority['etag'],
    $worker_authority['bytes'],
    $worker_authority['policy_fingerprint'],
    $worker_authority['expected_composition_fingerprint'],
    $worker_inspect_now,
    function ( $url, $arguments ) use ( $worker_inspect_now, $key_bytes ) {
        $claims = eforms_test_restore_worker_object_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], $worker_inspect_now, $key_bytes );
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
                        $key_bytes,
                        '2'
                    ),
                )
            ),
        );
    },
    'restore_worker_signoff'
);
$worker_unknown = WorkerClient::worker_inspect_object(
    $worker_authority['upload_id'],
    $worker_authority['storage_identity'],
    $worker_authority['validation_contract_version'],
    $worker_authority['object_key'],
    '-',
    '-',
    $worker_authority['bytes'],
    $worker_authority['policy_fingerprint'],
    $worker_authority['expected_composition_fingerprint'],
    $worker_inspect_now,
    $worker_requester,
    'restore_worker_signoff'
);
eforms_test_assert(
    ! empty( $worker_known['ok'] )
        && ! empty( $worker_known['present'] )
        && empty( $worker_wrong['ok'] )
        && $worker_wrong['reason'] === 'version_mismatch'
        && empty( $worker_mixed['ok'] )
        && $worker_mixed['reason'] === 'result_invalid'
        && empty( $worker_unknown['ok'] )
        && $worker_unknown['reason'] === 'object_version_required',
    'Restored candidate sign-off should accept exact registered-item authority and fail closed for wrong, mixed, and unknown authority.'
);
$worker_deleted = UploadBatchStore::worker_delete_item(
    $worker_restore['batch_id'],
    $worker_restore['secret'],
    $worker_restore['upload_id'],
    $uploads_dir,
    $created_at + 70
);
eforms_test_assert( ! empty( $worker_deleted['ok'] ), 'Worker restore fixture should become cleanup-eligible after sign-off.' );
$worker_drain = Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' )
    + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' )
    + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
$worker_cleanup_after = max( $worker_restore['delete_after'], $worker_restore['validation_until'], ( $created_at + 70 ) + $worker_drain );
UploadBatchStore::gc_aggregates(
    'staged',
    $uploads_dir,
    $worker_cleanup_after + 1,
    10,
    false,
    array(),
    function () {
        return array( 'ok' => true, 'absent' => true );
    }
);
UploadBatchStore::gc_aggregates(
    'staged',
    $uploads_dir,
    $worker_cleanup_after + 2,
    10,
    false,
    array(),
    function () {
        return array( 'ok' => true, 'absent' => true );
    }
);


eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $root );
echo $provider_integration ? "Genuine remote restore drill passed.\n" : "Remote restore drill passed.\n";


function eforms_test_restore_worker_item_fixture( $field, $uploads_dir, $now ) {
    $binding = array(
        'raw_token' => 'restore-candidate-token',
        'form_id' => 'virtual-estimate',
        'instance_id' => 'restore-candidate-instance',
        'field_key' => 'project_photos',
        'accept_until' => $now + 3600,
    );
    $secret = eforms_test_managed_batch_secret( 'C' );
    $created = UploadBatchStore::create_batch(
        $binding,
        $secret,
        $field,
        $uploads_dir,
        $now,
        FormProtocol::UPLOAD_TRANSPORT_WORKER,
        WorkerClient::composition_fingerprint()
    );
    eforms_test_assert( ! empty( $created['ok'] ), 'Worker restore fixture should create one Worker-owned batch.' );
    $batch_id = $created['batch']['batch_id'];
    $upload_id = 'restore_worker_photo';
    $validation_until = $binding['accept_until'] + 100;
    $authorized = UploadBatchStore::worker_authorize_intent(
        $batch_id,
        $secret,
        $upload_id,
        0,
        'restore-candidate.png',
        3072,
        'image/png',
        $uploads_dir,
        array(
            'now' => $now + 1,
            'storage_identity' => WorkerClient::composition_fingerprint(),
            'validation_contract_version' => 'validation-v1',
            'upload_until' => $now + 120,
            'accept_until' => $binding['accept_until'],
            'validation_until' => $validation_until,
            'staged_delete_after' => $created['batch']['delete_after'],
        )
    );
    eforms_test_assert( ! empty( $authorized['ok'] ), 'Worker restore fixture should authorize one exact candidate intent: ' . json_encode( $authorized ) );
    $path = $uploads_dir . '/eforms-private/' . UploadBatchStore::STAGED_DIR . '/' . Helpers::h2( $batch_id ) . '/' . $batch_id;
    $manifest_path = $path . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $manifest = json_decode( file_get_contents( $manifest_path ), true );
    $receipt = eforms_test_worker_stored_receipt( $manifest, $upload_id, 'restore-candidate-version-1', 'restore-candidate-etag' );
    $completed = UploadBatchStore::worker_complete_stored_receipt(
        $batch_id,
        $secret,
        $upload_id,
        $receipt,
        $uploads_dir,
        $now + 2
    );
    eforms_test_assert( ! empty( $completed['ok'] ), 'Worker restore fixture should register one exact candidate item: ' . json_encode( $completed ) );
    return array(
        'batch_id' => $batch_id,
        'secret' => $secret,
        'upload_id' => $upload_id,
        'manifest_path' => $manifest_path,
        'validation_until' => $validation_until,
        'delete_after' => $created['batch']['delete_after'],
    );
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

function eforms_test_restore_worker_object_claims( $token, $now, $key_bytes ) {
    $verified = WorkerProtocol::verify_worker_object_request(
        $token,
        array( EFORMS_WORKER_ACTIVE_KEY_ID => $key_bytes ),
        EFORMS_WORKER_ENVIRONMENT_ID,
        $now
    );
    eforms_test_assert( ! empty( $verified['ok'] ) && isset( $verified['claims'] ), 'Restore candidate object request should verify under the local fake key.' );
    return $verified['claims'];
}

function eforms_test_restore_sign_result( $claims, $key_bytes, $version = WorkerProtocol::VERSION ) {
    $parts = array_merge(
        array( WorkerProtocol::OBJECT_RESULT_DOMAIN, $version, EFORMS_WORKER_ACTIVE_KEY_ID, EFORMS_WORKER_ENVIRONMENT_ID ),
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

function eforms_test_restore_sign_worker_result( $claims, $key_bytes ) {
    return WorkerProtocol::sign_worker_object_result(
        $claims,
        EFORMS_WORKER_ACTIVE_KEY_ID,
        $key_bytes,
        EFORMS_WORKER_ENVIRONMENT_ID
    );
}
