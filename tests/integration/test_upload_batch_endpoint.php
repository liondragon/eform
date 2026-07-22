<?php
/**
 * Integration tests for the managed upload HTTP adapter.
 *
 * Contract: Managed Upload API
 * Contract: Throttling
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchEndpoint.php';

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return 'https://example.com';
    }
}

function eforms_test_upload_endpoint_config( $uploads_dir, $throttle = true, $max = 120, $uploads_enabled = true ) {
    eforms_test_set_filter(
        'eforms_config',
        function ( $config ) use ( $uploads_dir, $throttle, $max, $uploads_enabled ) {
            $config['uploads']['dir'] = $uploads_dir;
            $config['uploads']['enable'] = $uploads_enabled;
            $config['throttle']['enable'] = $throttle;
            $config['throttle']['per_ip']['max_per_minute'] = $max;
            $config['throttle']['per_ip']['cooldown_seconds'] = 0;
            return $config;
        }
    );
    Config::reset_for_tests();
}

class EformsTestUploadEndpointRequest {
    private $method;
    private $headers;
    private $url_params;
    private $body_params;
    private $query_params;

    public function __construct( $method, $headers, $url_params, $body_params = array(), $query_params = array() ) {
        $this->method = $method;
        $this->headers = $headers;
        $this->url_params = $url_params;
        $this->body_params = $body_params;
        $this->query_params = $query_params;
    }

    public function get_method() {
        return $this->method;
    }

    public function get_header( $name ) {
        foreach ( $this->headers as $key => $value ) {
            if ( strcasecmp( $key, $name ) === 0 ) {
                return $value;
            }
        }
        return '';
    }

    public function get_url_params() {
        return $this->url_params;
    }

    public function get_body_params() {
        return $this->body_params;
    }

    public function get_query_params() {
        return $this->query_params;
    }
}

function eforms_test_upload_endpoint_secret( $byte ) {
    return rtrim( strtr( base64_encode( str_repeat( $byte, Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
}

function eforms_test_upload_endpoint_request( $method, $content_type, $params, $secret, $files = array(), $ip = '203.0.113.40' ) {
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
        'client_ip' => $ip,
    );
}

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

$disabled_master_dir = eforms_test_setup_uploads( 'eforms-upload-endpoint-disabled-master' );
eforms_test_upload_endpoint_config( $disabled_master_dir, true, 120, false );
$disabled_master_mint = Security::mint_js_record( 'upload-test', $disabled_master_dir );
$disabled_master_params = array(
    FormProtocol::FIELD_FORM_ID => 'upload-test',
    FormProtocol::FIELD_INSTANCE_ID => $disabled_master_mint['instance_id'],
    FormProtocol::FIELD_TOKEN => $disabled_master_mint['token'],
    FormProtocol::UPLOAD_FIELD_PARAM => 'photos',
);
$disabled_master_create = UploadBatchEndpoint::create(
    eforms_test_upload_endpoint_request( 'POST', 'application/x-www-form-urlencoded', $disabled_master_params, eforms_test_upload_endpoint_secret( "\x4f" ) )
);
eforms_test_assert( $disabled_master_create['status'] === 503, 'Staged creation should fail closed when the master upload switch is disabled.' );
eforms_test_assert( ! is_dir( $disabled_master_dir . '/eforms-private/staged' ), 'Disabled-master rejection should precede managed batch mutation.' );
eforms_test_remove_tree( $disabled_master_dir );

$disabled_uploads_dir = eforms_test_setup_uploads( 'eforms-upload-endpoint-disabled-throttle' );
eforms_test_upload_endpoint_config( $disabled_uploads_dir, false );
$disabled_mint = Security::mint_js_record( 'upload-test', $disabled_uploads_dir );
$disabled_params = array(
    FormProtocol::FIELD_FORM_ID => 'upload-test',
    FormProtocol::FIELD_INSTANCE_ID => $disabled_mint['instance_id'],
    FormProtocol::FIELD_TOKEN => $disabled_mint['token'],
    FormProtocol::UPLOAD_FIELD_PARAM => 'photos',
);
$disabled_create = UploadBatchEndpoint::create(
    eforms_test_upload_endpoint_request( 'POST', 'application/x-www-form-urlencoded', $disabled_params, eforms_test_upload_endpoint_secret( "\x50" ) )
);
eforms_test_assert( $disabled_create['status'] === 503, 'Staged creation should fail closed when the mandatory throttle capability is disabled.' );
eforms_test_assert( ! is_dir( $disabled_uploads_dir . '/eforms-private/staged' ), 'Disabled-throttle rejection should precede managed batch mutation.' );
eforms_test_remove_tree( $disabled_uploads_dir );

$uploads_dir = eforms_test_setup_uploads( 'eforms-upload-endpoint' );
eforms_test_upload_endpoint_config( $uploads_dir );
$mint = Security::mint_js_record( 'upload-test', $uploads_dir );
eforms_test_assert( $mint['ok'] === true, 'The endpoint fixture should mint one persisted form token.' );
$secret = eforms_test_upload_endpoint_secret( "\x51" );
$create_params = array(
    FormProtocol::FIELD_FORM_ID => 'upload-test',
    FormProtocol::FIELD_INSTANCE_ID => $mint['instance_id'],
    FormProtocol::FIELD_TOKEN => $mint['token'],
    FormProtocol::UPLOAD_FIELD_PARAM => 'photos',
);
$create_request = eforms_test_upload_endpoint_request( 'POST', 'application/x-www-form-urlencoded; charset=UTF-8', $create_params, $secret );
$cross_origin_bad_create = $create_request;
$cross_origin_bad_create['headers']['Origin'] = 'https://evil.example';
$cross_origin_bad_create['headers']['Content-Type'] = 'text/plain';
eforms_test_assert( UploadBatchEndpoint::create( $cross_origin_bad_create )['status'] === 403, 'Create must reject cross-origin requests before exposing content-type validation.' );
$created = UploadBatchEndpoint::create( $create_request );
eforms_test_assert( $created['status'] === 200 && isset( $created['body']['batch_id'] ), 'Create should return the deterministic batch contract.' );
eforms_test_assert( $created['headers']['Cache-Control'] === 'no-store, max-age=0', 'Every JSON batch response should be no-store.' );
eforms_test_assert( strpos( json_encode( $created['body'] ), $secret ) === false, 'Create must not echo the batch secret.' );
$batch_id = $created['body']['batch_id'];

$retry = UploadBatchEndpoint::create( $create_request );
eforms_test_assert( $retry['status'] === 200 && $retry['body']['batch_id'] === $batch_id, 'A lost create response should converge through the same binding and secret.' );
$different_secret_request = $create_request;
$different_secret_request['headers'][ FormProtocol::HEADER_BATCH_SECRET ] = eforms_test_upload_endpoint_secret( "\x52" );
$create_conflict = UploadBatchEndpoint::create( $different_secret_request );
eforms_test_assert( $create_conflict['status'] === 409 && $create_conflict['body'] === array( 'error' => 'EFORMS_ERR_TOKEN' ), 'A different create secret should return a generic conflict.' );

$expired_mint = Security::mint_js_record( 'upload-test', $uploads_dir );
$expired_record_path = $uploads_dir . '/eforms-private/tokens/' . Helpers::h2( $expired_mint['token'] ) . '/' . hash( 'sha256', $expired_mint['token'] ) . '.json';
$expired_record = json_decode( file_get_contents( $expired_record_path ), true );
$expired_record['issued_at'] = time() - 100;
$expired_record['expires'] = time() - 1;
file_put_contents( $expired_record_path, json_encode( $expired_record ) );
$expired_params = $create_params;
$expired_params[ FormProtocol::FIELD_INSTANCE_ID ] = $expired_mint['instance_id'];
$expired_params[ FormProtocol::FIELD_TOKEN ] = $expired_mint['token'];
$expired_create = UploadBatchEndpoint::create(
    eforms_test_upload_endpoint_request( 'POST', 'application/x-www-form-urlencoded', $expired_params, eforms_test_upload_endpoint_secret( "\x53" ) )
);
eforms_test_assert( $expired_create['status'] === 410 && $expired_create['body'] === array( 'error' => 'EFORMS_ERR_TOKEN' ), 'Expired initial batch credentials should return an unambiguous terminal response.' );

$status_request = eforms_test_upload_endpoint_request( 'GET', '', array( 'batch_id' => $batch_id ), $secret );
$status = UploadBatchEndpoint::status( $status_request );
eforms_test_assert( $status['status'] === 200 && $status['body']['state'] === 'open', 'An authenticated status request should expose only the bounded open state.' );
$shadowed_status = UploadBatchEndpoint::status(
    new EformsTestUploadEndpointRequest(
        'GET',
        array( FormProtocol::HEADER_BATCH_SECRET => $secret ),
        array( FormProtocol::UPLOAD_BATCH_PARAM => $batch_id ),
        array( FormProtocol::UPLOAD_BATCH_PARAM => str_repeat( 'B', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ) ),
        array( FormProtocol::UPLOAD_BATCH_PARAM => str_repeat( 'C', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ) )
    )
);
eforms_test_assert( $shadowed_status['status'] === 200, 'Body and query values must not override the route-owned batch identity.' );
$headerless_status_request = $status_request;
unset( $headerless_status_request['headers']['Origin'] );
$headerless_status = UploadBatchEndpoint::status( $headerless_status_request );
eforms_test_assert( $headerless_status['status'] === 200, 'A credentialed same-origin GET should not require the Origin header browsers commonly omit.' );
$headerless_create_request = $create_request;
unset( $headerless_create_request['headers']['Origin'] );
eforms_test_assert( UploadBatchEndpoint::create( $headerless_create_request )['status'] === 403, 'A mutating endpoint should continue to require an explicit same-origin header.' );
$body_secret_only = $status_request;
unset( $body_secret_only['headers'][ FormProtocol::HEADER_BATCH_SECRET ] );
$body_secret_only['params'][ FormProtocol::UPLOAD_BATCH_SECRET ] = $secret;
$body_denied = UploadBatchEndpoint::status( $body_secret_only );
eforms_test_assert( $body_denied['status'] === 409, 'Endpoint credentials in body/query parameters should be ignored.' );

$removed_mint = Security::mint_js_record( 'upload-test', $uploads_dir );
$removed_secret = eforms_test_upload_endpoint_secret( "\x54" );
$removed_params = $create_params;
$removed_params[ FormProtocol::FIELD_INSTANCE_ID ] = $removed_mint['instance_id'];
$removed_params[ FormProtocol::FIELD_TOKEN ] = $removed_mint['token'];
$removed_create = UploadBatchEndpoint::create(
    eforms_test_upload_endpoint_request( 'POST', 'application/x-www-form-urlencoded', $removed_params, $removed_secret )
);
eforms_test_assert( $removed_create['status'] === 200, 'The aggregate-removal race fixture should create a batch.' );
$removed_batch_id = $removed_create['body']['batch_id'];
$removed_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $removed_batch_id ) . '/' . $removed_batch_id;
unlink( $removed_path . '/' . UploadBatchStore::MANIFEST_FILENAME );
$removed_status = UploadBatchEndpoint::status(
    eforms_test_upload_endpoint_request( 'GET', '', array( 'batch_id' => $removed_batch_id ), $removed_secret )
);
eforms_test_assert( $removed_status['status'] === 410 && $removed_status['body'] === array( 'error' => 'EFORMS_ERR_TOKEN' ), 'A manifest-removal race should collapse to the same generic terminal response as an absent aggregate.' );

$dropped_oversize_request = eforms_test_upload_endpoint_request(
    'POST',
    'multipart/form-data; boundary=eforms',
    array(),
    $secret
);
$dropped_oversize_request['headers']['Content-Length'] = (string) PHP_INT_MAX;
$dropped_oversize = UploadBatchEndpoint::upload( $dropped_oversize_request );
eforms_test_assert( $dropped_oversize['status'] === 413 && $dropped_oversize['body'] === array( 'error' => 'EFORMS_ERR_UPLOAD_TYPE' ), 'A multipart body dropped after exceeding the effective request cap should return 413.' );

$missing_upload_request = $dropped_oversize_request;
$missing_upload_request['headers']['Content-Length'] = '1';
$missing_upload = UploadBatchEndpoint::upload( $missing_upload_request );
eforms_test_assert( $missing_upload['status'] === 400 && $missing_upload['body'] === array( 'error' => 'EFORMS_ERR_UPLOAD_TYPE' ), 'A small malformed multipart request should remain a 400.' );

$png = eforms_test_fixture_bytes( 'staged-landscape.png' );
$png_path = eforms_test_write_file( $uploads_dir, 'endpoint.png', $png );
$upload_request = eforms_test_upload_endpoint_request(
    'POST',
    'multipart/form-data; boundary=eforms',
    array( 'batch_id' => $batch_id, 'upload_id' => 'client_upload_1', FormProtocol::UPLOAD_ORDINAL_PARAM => 0 ),
    $secret,
    array(
        FormProtocol::UPLOAD_FILE_PARAM => array(
            'name' => 'Customer Photo.png',
            'tmp_name' => $png_path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize( $png_path ),
        ),
    )
);
$cross_origin_bad_upload = $upload_request;
$cross_origin_bad_upload['headers']['Origin'] = 'https://evil.example';
$cross_origin_bad_upload['headers']['Content-Type'] = 'application/octet-stream';
eforms_test_assert( UploadBatchEndpoint::upload( $cross_origin_bad_upload )['status'] === 403, 'Upload must reject cross-origin requests before exposing content-type validation.' );
$uploaded = UploadBatchEndpoint::upload( $upload_request );
$backend_ready = UploadPolicy::staged_host_readiness();
if ( $backend_ready['ok'] ) {
    eforms_test_assert( $uploaded['status'] === 200 && $uploaded['body']['upload_id'] === 'client_upload_1', 'Multipart upload should return one safe committed item summary.' );
    eforms_test_assert( strpos( json_encode( $uploaded['body'] ), $uploads_dir ) === false, 'Upload responses should not expose private paths.' );

$retry_path = eforms_test_write_file( $uploads_dir, 'endpoint-retry.png', $png );
$upload_retry_request = $upload_request;
$upload_retry_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ]['tmp_name'] = $retry_path;
$upload_retry_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ]['size'] = filesize( $retry_path );
$upload_retry = UploadBatchEndpoint::upload( $upload_retry_request );
eforms_test_assert( $upload_retry['status'] === 200 && $upload_retry['body'] === $uploaded['body'], 'A same-ID same-content upload retry should return the existing item.' );

$ordinal_retry_request = $upload_request;
$ordinal_retry_request['params'][ FormProtocol::UPLOAD_ORDINAL_PARAM ] = 1;
$identity_bad_path = eforms_test_write_file( $uploads_dir, 'identity-bad.txt', 'not an image' );
$ordinal_retry_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ] = array(
    'name' => 'identity-bad.txt',
    'tmp_name' => $identity_bad_path,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize( $identity_bad_path ),
);
$ordinal_retry = UploadBatchEndpoint::upload( $ordinal_retry_request );
eforms_test_assert( $ordinal_retry['status'] === 409 && $ordinal_retry['body'] === array( 'error' => 'EFORMS_ERR_TOKEN' ), 'A same-ID retry with a different ordinal should return a generic conflict.' );
$content_retry_request = $ordinal_retry_request;
$content_retry_request['params'][ FormProtocol::UPLOAD_ORDINAL_PARAM ] = 0;
$content_bad_path = eforms_test_write_file( $uploads_dir, 'content-bad.txt', 'not an image' );
$content_retry_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ]['tmp_name'] = $content_bad_path;
$content_retry_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ]['size'] = filesize( $content_bad_path );
$content_retry = UploadBatchEndpoint::upload( $content_retry_request );
eforms_test_assert( $content_retry['status'] === 409 && $content_retry['body'] === array( 'error' => 'EFORMS_ERR_TOKEN' ), 'A same-ID retry with unsupported different bytes should remain a generic conflict.' );
$duplicate_ordinal_request = $upload_request;
$duplicate_ordinal_request['params']['upload_id'] = 'client_upload_2';
$duplicate_path = eforms_test_write_file( $uploads_dir, 'endpoint-duplicate.png', $png );
$duplicate_ordinal_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ]['tmp_name'] = $duplicate_path;
$duplicate_ordinal_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ]['size'] = filesize( $duplicate_path );
$duplicate_ordinal = UploadBatchEndpoint::upload( $duplicate_ordinal_request );
eforms_test_assert( $duplicate_ordinal['status'] === 400 && $duplicate_ordinal['body'] === array( 'error' => 'EFORMS_ERR_UPLOAD_TYPE' ), 'A second upload ID must not claim an existing ordinal.' );
$after_ordinal_conflicts = UploadBatchEndpoint::status( $status_request );
eforms_test_assert( $after_ordinal_conflicts['status'] === 200 && count( $after_ordinal_conflicts['body']['items'] ) === 1, 'Rejected ordinal conflicts must leave the endpoint batch readable and unchanged.' );

$oversize_path = eforms_test_write_file( $uploads_dir, 'oversize.jpg', str_repeat( 'x', 20971521 ) );
$oversize_request = $upload_request;
$oversize_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ]['name'] = 'Oversize.jpg';
$oversize_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ]['tmp_name'] = $oversize_path;
$oversize_request['files'][ FormProtocol::UPLOAD_FILE_PARAM ]['size'] = filesize( $oversize_path );
$oversize = UploadBatchEndpoint::upload( $oversize_request );
eforms_test_assert( $oversize['status'] === 413 && $oversize['body'] === array( 'error' => 'EFORMS_ERR_UPLOAD_TYPE' ), 'An oversized committed-ID retry should return 413 before raw-content comparison.' );

    $preview_request = eforms_test_upload_endpoint_request(
        'GET',
        '',
        array( 'batch_id' => $batch_id, 'upload_id' => 'client_upload_1' ),
        $secret
    );
    $preview = UploadBatchEndpoint::preview( $preview_request );
    eforms_test_assert( $preview['status'] === 200 && $preview['headers']['Content-Type'] === 'image/jpeg', 'Authenticated preview should return only JPEG bytes.' );
    $shadowed_preview = UploadBatchEndpoint::preview(
        new EformsTestUploadEndpointRequest(
            'GET',
            array( FormProtocol::HEADER_BATCH_SECRET => $secret ),
            array( FormProtocol::UPLOAD_BATCH_PARAM => $batch_id, FormProtocol::UPLOAD_ITEM_PARAM => 'client_upload_1' ),
            array( FormProtocol::UPLOAD_BATCH_PARAM => str_repeat( 'B', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ), FormProtocol::UPLOAD_ITEM_PARAM => 'wrong-body-item' ),
            array( FormProtocol::UPLOAD_BATCH_PARAM => str_repeat( 'C', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ), FormProtocol::UPLOAD_ITEM_PARAM => 'wrong-query-item' )
        )
    );
    eforms_test_assert( $shadowed_preview['status'] === 200, 'Body and query values must not override route-owned preview identities.' );
    $headerless_preview_request = $preview_request;
    unset( $headerless_preview_request['headers']['Origin'] );
    $headerless_preview = UploadBatchEndpoint::preview( $headerless_preview_request );
    eforms_test_assert( $headerless_preview['status'] === 200, 'A credentialed preview GET should accept a missing Origin header.' );
    $headerless_delete_request = array_merge( $preview_request, array( 'method' => 'DELETE' ) );
    unset( $headerless_delete_request['headers']['Origin'] );
    eforms_test_assert( UploadBatchEndpoint::delete( $headerless_delete_request )['status'] === 403, 'A mutating delete must reject a missing Origin header.' );
    $preview_tmp = eforms_test_write_file( $uploads_dir, 'served-preview.jpg', $preview['body'] );
    eforms_test_assert( UploadPolicy::detect_mime( $preview_tmp ) === 'image/jpeg', 'Served preview bytes should agree with the response MIME.' );

    $template = TemplateLoader::load( 'upload-test' );
    $field = null;
    foreach ( $template['template']['fields'] as $candidate ) {
        if ( isset( $candidate['key'] ) && $candidate['key'] === 'photos' ) {
            $field = $candidate;
        }
    }
    $binding = array(
        'raw_token' => $mint['token'],
        'form_id' => 'upload-test',
        'instance_id' => $mint['instance_id'],
        'field_key' => 'photos',
    );
    $resolved = UploadBatchStore::resolve_open( $batch_id, $secret, $binding, $field, $uploads_dir );
    $claim = UploadBatchStore::claim_finalization( $batch_id, $secret, $binding, $field, $resolved['items'], $mint['token'], $uploads_dir );
    eforms_test_assert( $claim['ok'] === true, 'The endpoint fixture should enter finalizing through the store owner.' );
    $finalizing_status = UploadBatchEndpoint::status( $status_request );
    eforms_test_assert( $finalizing_status['status'] === 200 && $finalizing_status['body']['state'] === 'finalizing', 'Status should expose finalizing only while the staged path exists.' );
    eforms_test_assert( UploadBatchEndpoint::upload( $upload_request )['status'] === 409, 'Finalizing should reject upload.' );
    eforms_test_assert( UploadBatchEndpoint::delete( array_merge( $preview_request, array( 'method' => 'DELETE' ) ) )['status'] === 409, 'Finalizing should reject delete.' );
    eforms_test_assert( UploadBatchEndpoint::preview( $preview_request )['status'] === 409, 'Finalizing should reject staged preview.' );

    $ledger = Ledger::reserve( 'upload-test', $mint['token'], $uploads_dir );
    eforms_test_assert( $ledger['ok'] === true, 'The finalized endpoint fixture should durably consume its token.' );
    $finalized = UploadBatchStore::finalize( $batch_id, $mint['token'], $uploads_dir );
    eforms_test_assert( $finalized['ok'] === true, 'The endpoint fixture should finalize through the aggregate owner.' );
    $recreate = UploadBatchEndpoint::create( $create_request );
    eforms_test_assert( $recreate['status'] === 410 && $recreate['body'] === array( 'error' => 'EFORMS_ERR_TOKEN' ), 'A consumed token must not recreate its renamed batch.' );
    $recreated_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $batch_id ) . '/' . $batch_id;
    eforms_test_assert( ! is_dir( $recreated_path ), 'Consumed-token rejection must happen before managed batch creation.' );
    $post_rename = UploadBatchEndpoint::status( $body_secret_only );
    eforms_test_assert( $post_rename['status'] === 410 && $post_rename['body'] === array( 'error' => 'EFORMS_ERR_TOKEN' ), 'Post-rename status should return generic 410 before credential validation.' );
}

$missing_request = eforms_test_upload_endpoint_request( 'GET', '', array( 'batch_id' => str_repeat( 'A', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ) ), null );
$missing = UploadBatchEndpoint::status( $missing_request );
eforms_test_assert( $missing['status'] === 410 && $missing['body'] === array( 'error' => 'EFORMS_ERR_TOKEN' ), 'Never-existing batches should share the post-rename 410 response.' );
$cross_origin = $missing_request;
$cross_origin['headers']['Origin'] = 'https://evil.example';
eforms_test_assert( UploadBatchEndpoint::status( $cross_origin )['status'] === 403, 'Every batch endpoint should require same-origin requests.' );

eforms_test_remove_tree( $uploads_dir );

// A failed create content type consumes the one allowed attempt; the next
// valid request is throttled before credential or managed-state work.
$throttle_create_dir = eforms_test_setup_uploads( 'eforms-upload-create-throttle' );
eforms_test_upload_endpoint_config( $throttle_create_dir );
$create_mint = Security::mint_js_record( 'upload-test', $throttle_create_dir );
eforms_test_upload_endpoint_config( $throttle_create_dir, true, 1 );
$throttle_secret = eforms_test_upload_endpoint_secret( "\x61" );
$throttle_params = $create_params;
$throttle_params[ FormProtocol::FIELD_INSTANCE_ID ] = $create_mint['instance_id'];
$throttle_params[ FormProtocol::FIELD_TOKEN ] = $create_mint['token'];
$failed_type = UploadBatchEndpoint::create(
    eforms_test_upload_endpoint_request( 'POST', 'text/plain', $throttle_params, $throttle_secret, array(), '203.0.113.61' )
);
eforms_test_assert( $failed_type['status'] === 400, 'A failed create content type should consume an allowed throttle attempt.' );
$throttled_create = UploadBatchEndpoint::create(
    eforms_test_upload_endpoint_request( 'POST', 'application/x-www-form-urlencoded', $throttle_params, $throttle_secret, array(), '203.0.113.61' )
);
eforms_test_assert( $throttled_create['status'] === 429 && isset( $throttled_create['headers']['Retry-After'] ), 'The next create should return 429 with Retry-After before creating a batch.' );
eforms_test_assert( ! is_dir( $throttle_create_dir . '/eforms-private/staged' ), 'A throttled create should not mutate managed batch state.' );
eforms_test_remove_tree( $throttle_create_dir );

// A failed image attempt consumes the one allowed upload attempt; the next
// request is throttled before editor readiness or capacity mutation.
$throttle_upload_dir = eforms_test_setup_uploads( 'eforms-upload-item-throttle' );
eforms_test_upload_endpoint_config( $throttle_upload_dir );
$upload_mint = Security::mint_js_record( 'upload-test', $throttle_upload_dir );
$upload_secret = eforms_test_upload_endpoint_secret( "\x62" );
$upload_create_params = $create_params;
$upload_create_params[ FormProtocol::FIELD_INSTANCE_ID ] = $upload_mint['instance_id'];
$upload_create_params[ FormProtocol::FIELD_TOKEN ] = $upload_mint['token'];
$upload_created = UploadBatchEndpoint::create(
    eforms_test_upload_endpoint_request( 'POST', 'application/x-www-form-urlencoded', $upload_create_params, $upload_secret, array(), '203.0.113.64' )
);
eforms_test_assert( $upload_created['status'] === 200, 'The upload-throttle fixture should create before enabling throttle.' );
eforms_test_upload_endpoint_config( $throttle_upload_dir, true, 1 );
$bad_path = eforms_test_write_file( $throttle_upload_dir, 'bad.txt', 'not an image' );
$bad_upload = eforms_test_upload_endpoint_request(
    'POST',
    'multipart/form-data; boundary=eforms',
    array( 'batch_id' => $upload_created['body']['batch_id'], 'upload_id' => 'bad_image', FormProtocol::UPLOAD_ORDINAL_PARAM => 0 ),
    $upload_secret,
    array( FormProtocol::UPLOAD_FILE_PARAM => array( 'name' => 'bad.txt', 'tmp_name' => $bad_path, 'error' => 0, 'size' => filesize( $bad_path ) ) ),
    '203.0.113.62'
);
$bad_type_upload = $bad_upload;
$bad_type_upload['headers']['Content-Type'] = 'application/octet-stream';
$bad_type_upload['client_ip'] = '203.0.113.63';
eforms_test_assert( UploadBatchEndpoint::upload( $bad_type_upload )['status'] === 400, 'A failed upload content type should consume an allowed throttle attempt.' );
$throttled_after_bad_type = $bad_upload;
$throttled_after_bad_type['client_ip'] = '203.0.113.63';
eforms_test_assert( UploadBatchEndpoint::upload( $throttled_after_bad_type )['status'] === 429, 'The next upload from that client should be throttled before body validation.' );
$failed_image = UploadBatchEndpoint::upload( $bad_upload );
eforms_test_assert( $failed_image['status'] === 400, 'A malformed image should fail after consuming the allowed throttle attempt.' );
$valid_path = eforms_test_write_file( $throttle_upload_dir, 'valid.png', $png );
$bad_upload['params']['upload_id'] = 'valid_image';
$bad_upload['files'][ FormProtocol::UPLOAD_FILE_PARAM ] = array( 'name' => 'valid.png', 'tmp_name' => $valid_path, 'error' => 0, 'size' => filesize( $valid_path ) );
$throttled_upload = UploadBatchEndpoint::upload( $bad_upload );
eforms_test_assert( $throttled_upload['status'] === 429 && isset( $throttled_upload['headers']['Retry-After'] ), 'The next upload should return 429 with Retry-After.' );
$throttle_capacity = eforms_test_managed_capacity_record( $throttle_upload_dir );
eforms_test_assert( is_array( $throttle_capacity ) && $throttle_capacity['total_bytes'] === 0, 'Failed and throttled uploads should not mutate managed capacity.' );

eforms_test_remove_tree( $throttle_upload_dir );
echo "All upload batch endpoint tests passed.\n";
