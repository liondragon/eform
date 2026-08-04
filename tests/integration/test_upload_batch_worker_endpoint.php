<?php
/**
 * Integration coverage for the deployment-bound Worker upload composition.
 *
 * Contract: Managed Upload API
 * Contract: Worker trust and ingress protocol
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/managed_upload_fixtures.php';
require_once __DIR__ . '/../../src/Anchors.php';

$worker_key = str_repeat( "\x71", Anchors::get( 'WORKER_INTEGRATION_KEY_BYTES' ) );
$GLOBALS['eforms_test_worker_endpoint_key'] = $worker_key;
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

function eforms_test_worker_endpoint_request( $method, $content_type, $params, $secret, $files = array(), $worker_requester = null ) {
    $headers = array( 'Origin' => 'https://example.com' );
    if ( $content_type !== '' ) {
        $headers['Content-Type'] = $content_type;
    }
    if ( $secret !== null ) {
        $headers[ FormProtocol::HEADER_BATCH_SECRET ] = $secret;
    }
    $request = array(
        'method' => $method,
        'headers' => $headers,
        'params' => $params,
        'files' => $files,
        'client_ip' => '203.0.113.73',
    );
    if ( is_callable( $worker_requester ) ) {
        $request['worker_requester'] = $worker_requester;
    }
    return $request;
}

function eforms_test_worker_endpoint_health_requester( $url, $arguments ) {
    $token = isset( $arguments['headers'][ WorkerClient::HEALTH_HEADER ] ) ? $arguments['headers'][ WorkerClient::HEALTH_HEADER ] : '';
    $claims = eforms_test_worker_endpoint_health_claims( $token );
    if ( $claims === null ) {
        return array( 'status' => 403, 'body' => '{}' );
    }
    $result = eforms_test_worker_endpoint_sign_envelope(
        'health_result',
        array(
            'request_id' => $claims['request_id'],
            'storage_ready' => true,
            'inspection_ready' => true,
            'queue_producer_ready' => true,
            'limiter_ready' => true,
            'keys_ready' => true,
            'storage_identity_ready' => true,
            'validation_contract_ready' => true,
            'storage_identity' => $claims['storage_identity'],
            'validation_contract_version' => $claims['validation_contract_version'],
            'checked_at' => time(),
            'expires_at' => $claims['expires_at'],
        )
    );
    return array( 'status' => 200, 'body' => json_encode( array( 'result' => $result ) ) );
}

function eforms_test_worker_endpoint_sign_envelope( $schema_name, $claims ) {
    $schema = WorkerProtocol::SCHEMAS[ $schema_name ];
    $parts = array( $schema['domain'], WorkerProtocol::VERSION, EFORMS_WORKER_ACTIVE_KEY_ID, EFORMS_WORKER_ENVIRONMENT_ID );
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
    return $encode( $payload ) . '.' . $encode( hash_hmac( 'sha256', $payload, $GLOBALS['eforms_test_worker_endpoint_key'], true ) );
}

function eforms_test_worker_endpoint_health_claims( $token ) {
    if ( ! is_string( $token ) || substr_count( $token, '.' ) !== 1 ) {
        return null;
    }
    list( $payload_b64, $signature_b64 ) = explode( '.', $token, 2 );
    $decode = function ( $encoded ) {
        $remainder = strlen( $encoded ) % 4;
        if ( $remainder > 0 ) {
            $encoded .= str_repeat( '=', 4 - $remainder );
        }
        return base64_decode( strtr( $encoded, '-_', '+/' ), true );
    };
    $payload = $decode( $payload_b64 );
    $signature = $decode( $signature_b64 );
    if ( ! is_string( $payload ) || ! is_string( $signature ) ) {
        return null;
    }
    $expected = hash_hmac( 'sha256', $payload, $GLOBALS['eforms_test_worker_endpoint_key'], true );
    if ( ! hash_equals( $expected, $signature ) ) {
        return null;
    }
    $parts = array();
    $offset = 0;
    while ( $offset < strlen( $payload ) ) {
        if ( $offset + 4 > strlen( $payload ) ) {
            return null;
        }
        $length = unpack( 'N', substr( $payload, $offset, 4 ) )[1];
        $offset += 4;
        if ( $length < 0 || $offset + $length > strlen( $payload ) ) {
            return null;
        }
        $parts[] = substr( $payload, $offset, $length );
        $offset += $length;
    }
    $schema = WorkerProtocol::SCHEMAS['health_request'];
    $fields = array_keys( $schema['fields'] );
    if ( count( $parts ) !== 4 + count( $fields )
        || $parts[0] !== WorkerProtocol::HEALTH_REQUEST_DOMAIN
        || $parts[1] !== WorkerProtocol::VERSION
        || $parts[2] !== EFORMS_WORKER_ACTIVE_KEY_ID
        || $parts[3] !== EFORMS_WORKER_ENVIRONMENT_ID
    ) {
        return null;
    }
    $claims = array();
    foreach ( $fields as $index => $field ) {
        $value = $parts[4 + $index];
        $claims[ $field ] = $field === 'expires_at' ? (int) $value : $value;
    }
    return $claims;
}

function eforms_test_worker_endpoint_receipt( $manifest, $upload_id, $object_version, $etag, $bytes ) {
    return WorkerProtocol::sign_worker_stored_receipt(
        eforms_test_worker_stored_receipt( $manifest, $upload_id, $object_version, $etag, array( 'bytes' => $bytes ) ),
        EFORMS_WORKER_ACTIVE_KEY_ID,
        $GLOBALS['eforms_test_worker_endpoint_key'],
        EFORMS_WORKER_ENVIRONMENT_ID
    );
}

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

$uploads_dir = eforms_test_setup_uploads( 'eforms-upload-worker-endpoint' );
eforms_test_worker_endpoint_config( $uploads_dir );
$mint = Security::mint_js_record( 'upload-test', $uploads_dir );
$secret = eforms_test_managed_batch_secret( "\x73" );
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
    $secret,
    array(),
    'eforms_test_worker_endpoint_health_requester'
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
$verified_grant = WorkerProtocol::verify_worker_upload_grant(
    $transport['grant'],
    WorkerClient::configuration()['keys'],
    EFORMS_WORKER_ENVIRONMENT_ID,
    time()
);
eforms_test_assert( ! empty( $verified_grant['ok'] ) && isset( $verified_grant['claims']['validation_until'] ), 'Worker authorization should sign the live v3 Queue-backed upload grant.' );
$capacity = eforms_test_managed_capacity_record( $uploads_dir );
$reservations = array_values( $capacity['reservations'] );
eforms_test_assert( count( $reservations ) === 1 && $reservations[0]['transient_bytes'] === 0, 'Direct Worker authorization should not reserve a PHP multipart copy.' );

$manifest_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $batch_id ) . '/' . $batch_id . '/' . UploadBatchStore::MANIFEST_FILENAME;
$manifest = json_decode( file_get_contents( $manifest_path ), true );
eforms_test_assert( $manifest['version'] === UploadBatchStore::WORKER_MANIFEST_VERSION, 'Worker batches should use the v3 candidate manifest schema at creation.' );
$receipt = eforms_test_worker_endpoint_receipt( $manifest, 'remote_photo', 'remote-v1', 'remote-etag-v1', strlen( $png ) );
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
eforms_test_assert( $completed['body']['mime'] === '' && $completed['body']['width'] === 0 && $completed['body']['height'] === 0, 'Stored receipt registration must not expose accepted media facts before Queue validation.' );
eforms_test_assert( UploadBatchEndpoint::upload( $completion_request )['body'] === $completed['body'], 'A lost completion response should converge to the same item.' );
$committed_authorization_retry = $authorize;
$committed_authorization_retry['worker_requester'] = function () {
    return array( 'status' => 503, 'body' => '{}' );
};
$committed_retry_response = UploadBatchEndpoint::upload( $committed_authorization_retry );
eforms_test_assert(
    $committed_retry_response['status'] === 200
        && ! empty( $committed_retry_response['body'][ FormProtocol::UPLOAD_RESPONSE_COMMITTED ] )
        && $committed_retry_response['body']['upload_id'] === 'remote_photo'
        && ! isset( $committed_retry_response['body']['transport'] )
        && $committed_retry_response['body']['bytes'] === $completed['body']['bytes']
        && $committed_retry_response['body']['display_name'] === $completed['body']['display_name'],
    'Worker endpoint authorization retries should converge to the committed item when fresh grants are unavailable.'
);
$changed_request = $completion_request;
$changed_request['params'][ FormProtocol::UPLOAD_RECEIPT_PARAM ] = eforms_test_worker_endpoint_receipt( $manifest, 'remote_photo', 'remote-v2', 'remote-etag-v1', strlen( $png ) );
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
    isset( $deleted_manifest['tombstones']['remote_cancel']['storage_identity'] )
        && empty( $deleted_manifest['tombstones']['remote_cancel']['capacity_release_started'] )
        && empty( $deleted_manifest['tombstones']['remote_cancel']['capacity_released'] )
        && isset( $deleted_capacity['reservations'][ $deleted_reservation_id ] ),
    'Remote removal must retain exact cleanup authority and physical accounting until the remote lifecycle owner confirms exact-object absence.'
);
$late_manifest = $manifest;
$late_manifest['intents']['remote_cancel'] = $cancel_intent;
$late_request = $completion_request;
$late_request['params']['upload_id'] = 'remote_cancel';
$late_request['params'][ FormProtocol::UPLOAD_RECEIPT_PARAM ] = eforms_test_worker_endpoint_receipt( $late_manifest, 'remote_cancel', 'remote-cancel-v1', 'remote-cancel-etag-v1', strlen( $png ) );
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
