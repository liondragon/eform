<?php
/**
 * Integration coverage for the deployment-bound Worker upload composition.
 *
 * Contract: Managed Upload API
 * Contract: Worker trust and ingress protocol
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Anchors.php';

$worker_key = str_repeat( "\x71", Anchors::get( 'WORKER_INTEGRATION_KEY_BYTES' ) );
$worker_key_b64 = rtrim( strtr( base64_encode( $worker_key ), '+/', '-_' ), '=' );
define( 'EFORMS_UPLOAD_COMPOSITION', 'worker_r2_cloudflare' );
define( 'EFORMS_WORKER_URL', 'https://media.example.test' );
define( 'EFORMS_WORKER_ENVIRONMENT_ID', 'integration' );
define( 'EFORMS_WORKER_ACTIVE_KEY_ID', 'key-integration' );
define( 'EFORMS_WORKER_ACTIVE_KEY_B64', $worker_key_b64 );

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchEndpoint.php';

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return 'https://example.com';
    }
}

function eforms_test_worker_endpoint_config( $uploads_dir ) {
    eforms_test_set_filter(
        'eforms_config',
        function ( $config ) use ( $uploads_dir ) {
            $config['uploads']['dir'] = $uploads_dir;
            $config['uploads']['enable'] = true;
            $config['throttle']['enable'] = true;
            $config['throttle']['per_ip']['max_per_minute'] = 120;
            $config['throttle']['per_ip']['cooldown_seconds'] = 0;
            return $config;
        }
    );
    Config::reset_for_tests();
}

function eforms_test_worker_endpoint_secret( $byte ) {
    return rtrim( strtr( base64_encode( str_repeat( $byte, Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
}

function eforms_test_worker_endpoint_request( $method, $content_type, $params, $secret, $files = array() ) {
    $headers = array( 'Origin' => 'https://example.com' );
    if ( $content_type !== '' ) {
        $headers['Content-Type'] = $content_type;
    }
    if ( $secret !== null ) {
        $headers[ FormProtocol::HEADER_BATCH_SECRET ] = $secret;
    }
    return array(
        'method' => $method,
        'headers' => $headers,
        'params' => $params,
        'files' => $files,
        'client_ip' => '203.0.113.73',
    );
}

function eforms_test_worker_endpoint_receipt( $claims, $secret ) {
    $schema = WorkerProtocol::SCHEMAS['upload_receipt'];
    $parts = array( WorkerProtocol::UPLOAD_RECEIPT_DOMAIN, WorkerProtocol::VERSION, EFORMS_WORKER_ACTIVE_KEY_ID, EFORMS_WORKER_ENVIRONMENT_ID );
    foreach ( $schema['fields'] as $field => $type ) {
        $parts[] = (string) $claims[ $field ];
    }
    $payload = '';
    foreach ( $parts as $part ) {
        $payload .= pack( 'N', strlen( $part ) ) . $part;
    }
    $encode = function ( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    };
    return $encode( $payload ) . '.' . $encode( hash_hmac( 'sha256', $payload, $secret, true ) );
}

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

$uploads_dir = eforms_test_setup_uploads( 'eforms-upload-worker-endpoint' );
eforms_test_worker_endpoint_config( $uploads_dir );
$mint = Security::mint_js_record( 'upload-test', $uploads_dir );
$secret = eforms_test_worker_endpoint_secret( "\x73" );
$create = UploadBatchEndpoint::create(
    eforms_test_worker_endpoint_request(
        'POST',
        'application/x-www-form-urlencoded',
        array(
            FormProtocol::FIELD_FORM_ID => 'upload-test',
            FormProtocol::FIELD_INSTANCE_ID => $mint['instance_id'],
            FormProtocol::FIELD_TOKEN => $mint['token'],
            FormProtocol::UPLOAD_FIELD_PARAM => 'photos',
        ),
        $secret
    )
);
eforms_test_assert( $create['status'] === 200, 'A complete Worker composition should create the normal bound aggregate.' );
$batch_id = $create['body']['batch_id'];
$png = eforms_test_fixture_bytes( 'staged-landscape.png' );
$authorize = eforms_test_worker_endpoint_request(
    'POST',
    'application/x-www-form-urlencoded',
    array(
        'batch_id' => $batch_id,
        'upload_id' => 'remote_photo',
        FormProtocol::UPLOAD_ORDINAL_PARAM => 0,
        FormProtocol::UPLOAD_DISPLAY_NAME_PARAM => 'Phone Photo.png',
        FormProtocol::UPLOAD_BYTES_PARAM => strlen( $png ),
        FormProtocol::UPLOAD_MIME_PARAM => 'image/png',
    ),
    $secret
);
$authorized = UploadBatchEndpoint::upload( $authorize );
$transport = isset( $authorized['body']['transport'] ) ? $authorized['body']['transport'] : array();
eforms_test_assert(
    $authorized['status'] === 200
        && $transport === array(
            'kind' => 'worker',
            'url' => 'https://media.example.test/v1/upload',
            'grant' => $transport['grant'],
            'mime' => 'image/png',
        )
        && is_string( $transport['grant'] )
        && $transport['grant'] !== '',
    'Worker authorization should return one scoped target and signed grant after durable intent creation.'
);
eforms_test_assert( strpos( json_encode( $authorized['body'] ), $worker_key_b64 ) === false, 'Worker authorization must not expose integration key material.' );
$capacity = eforms_test_managed_capacity_record( $uploads_dir );
$reservations = array_values( $capacity['reservations'] );
eforms_test_assert( count( $reservations ) === 1 && $reservations[0]['transient_bytes'] === 0, 'Direct Worker authorization should not reserve a PHP multipart copy.' );

$manifest_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $batch_id ) . '/' . $batch_id . '/' . UploadBatchStore::MANIFEST_FILENAME;
$manifest = json_decode( file_get_contents( $manifest_path ), true );
$intent = $manifest['intents']['remote_photo'];
$claims = array(
    'intent_id' => $intent['intent_id'],
    'batch_id' => $batch_id,
    'upload_id' => 'remote_photo',
    'ordinal' => 0,
    'object_key' => $intent['object_key'],
    'object_version' => 'remote-v1',
    'etag' => 'remote-etag-v1',
    'bytes' => strlen( $png ),
    'mime' => 'image/png',
    'width' => 3,
    'height' => 2,
    'policy_fingerprint' => $intent['policy_fingerprint'],
    'expires_at' => time() + Anchors::get( 'WORKER_RECEIPT_TTL_SECONDS' ),
);
$receipt = eforms_test_worker_endpoint_receipt( $claims, $worker_key );
$completion_request = eforms_test_worker_endpoint_request(
    'POST',
    'application/x-www-form-urlencoded',
    array( 'batch_id' => $batch_id, 'upload_id' => 'remote_photo', FormProtocol::UPLOAD_RECEIPT_PARAM => $receipt ),
    $secret
);
$oversized_completion = $completion_request;
$oversized_completion['headers']['Content-Length'] = (string) PHP_INT_MAX;
eforms_test_assert( UploadBatchEndpoint::upload( $oversized_completion )['status'] === 413, 'Oversized form-encoded Worker receipt requests should return the upload size error before receipt verification.' );
$manifest_after_oversized_completion = json_decode( file_get_contents( $manifest_path ), true );
eforms_test_assert(
    isset( $manifest_after_oversized_completion['intents']['remote_photo'] )
        && empty( $manifest_after_oversized_completion['items']['remote_photo'] ),
    'Oversized Worker receipt rejection should leave the unresolved intent uncommitted.'
);
$completed = UploadBatchEndpoint::upload( $completion_request );
eforms_test_assert( $completed['status'] === 200 && $completed['body']['upload_id'] === 'remote_photo', 'A valid receipt should commit without PHP receiving artifact bytes.' );
eforms_test_assert( UploadBatchEndpoint::upload( $completion_request )['body'] === $completed['body'], 'A lost completion response should converge to the same item.' );
$changed = $claims;
$changed['object_version'] = 'remote-v2';
$changed_request = $completion_request;
$changed_request['params'][ FormProtocol::UPLOAD_RECEIPT_PARAM ] = eforms_test_worker_endpoint_receipt( $changed, $worker_key );
eforms_test_assert( UploadBatchEndpoint::upload( $changed_request )['status'] === 409, 'Changed immutable facts should fail generically.' );

$cancel_authorize = $authorize;
$cancel_authorize['params']['upload_id'] = 'remote_cancel';
$cancel_authorize['params'][ FormProtocol::UPLOAD_ORDINAL_PARAM ] = 1;
eforms_test_assert( UploadBatchEndpoint::upload( $cancel_authorize )['status'] === 200, 'A cancellable remote intent should authorize.' );
$manifest = json_decode( file_get_contents( $manifest_path ), true );
$cancel_intent = $manifest['intents']['remote_cancel'];
$deleted = UploadBatchEndpoint::delete( eforms_test_worker_endpoint_request( 'DELETE', '', array( 'batch_id' => $batch_id, 'upload_id' => 'remote_cancel' ), $secret ) );
eforms_test_assert( $deleted['status'] === 200, 'Remote removal should tombstone the item while physical deletion remains pending.' );
$deleted_manifest = json_decode( file_get_contents( $manifest_path ), true );
$deleted_capacity = eforms_test_managed_capacity_record( $uploads_dir );
$deleted_reservation_id = hash( 'sha256', $batch_id . "\0remote_cancel" );
eforms_test_assert(
    ! empty( $deleted_manifest['tombstones']['remote_cancel']['capacity_release_started'] )
        && empty( $deleted_manifest['tombstones']['remote_cancel']['capacity_released'] )
        && isset( $deleted_capacity['reservations'][ $deleted_reservation_id ] ),
    'Remote removal must retain physical accounting until the remote lifecycle owner confirms exact-object absence.'
);
$late = $claims;
$late['intent_id'] = $cancel_intent['intent_id'];
$late['upload_id'] = 'remote_cancel';
$late['ordinal'] = 1;
$late['object_key'] = $cancel_intent['object_key'];
$late['policy_fingerprint'] = $cancel_intent['policy_fingerprint'];
$late_request = $completion_request;
$late_request['params']['upload_id'] = 'remote_cancel';
$late_request['params'][ FormProtocol::UPLOAD_RECEIPT_PARAM ] = eforms_test_worker_endpoint_receipt( $late, $worker_key );
eforms_test_assert( UploadBatchEndpoint::upload( $late_request )['status'] === 409, 'A late receipt after removal should fail against the tombstone.' );

$multipart = eforms_test_worker_endpoint_request(
    'POST',
    'multipart/form-data; boundary=eforms',
    array( 'batch_id' => $batch_id, 'upload_id' => 'remote_photo', FormProtocol::UPLOAD_ORDINAL_PARAM => 0 ),
    $secret,
    array(
        FormProtocol::UPLOAD_FILE_PARAM => array(
            'name' => 'Phone Photo.png',
            'tmp_name' => eforms_test_write_file( $uploads_dir, 'worker-fallback.png', $png ),
            'error' => 0,
            'size' => strlen( $png ),
        ),
    )
);
eforms_test_assert( UploadBatchEndpoint::upload( $multipart )['status'] === 503, 'Worker composition must not fall back to same-origin multipart.' );

eforms_test_remove_tree( $uploads_dir );
echo "Worker upload endpoint tests passed.\n";
