<?php
/**
 * Real WordPress REST-server regression for binary staged previews.
 *
 * Run with: wp eval-file tests/wp-runtime/rest-preview.php
 */

if ( ! class_exists( 'UploadBatchEndpoint' ) ) {
    require_once dirname( __DIR__, 2 ) . '/src/Uploads/UploadBatchEndpoint.php';
}

$tmp_root = rtrim( sys_get_temp_dir(), '/\\' ) . '/eforms-rest-preview-' . getmypid() . '-' . str_replace( '.', '', uniqid( '', true ) );
$uploads_dir = $tmp_root . '/uploads';

function eforms_rest_preview_remove_tree( $path ) {
    if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
        return;
    }
    if ( is_file( $path ) || is_link( $path ) ) {
        @unlink( $path );
        return;
    }
    $items = scandir( $path );
    if ( is_array( $items ) ) {
        foreach ( $items as $item ) {
            if ( $item !== '.' && $item !== '..' ) {
                eforms_rest_preview_remove_tree( $path . '/' . $item );
            }
        }
    }
    @rmdir( $path );
}

register_shutdown_function(
    function () use ( $tmp_root ) {
        eforms_rest_preview_remove_tree( $tmp_root );
    }
);

if ( ! mkdir( $uploads_dir, 0700, true ) && ! is_dir( $uploads_dir ) ) {
    throw new RuntimeException( 'Unable to create the temporary uploads directory.' );
}

add_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        return $config;
    }
);
Config::reset_for_tests();

if ( has_filter( 'rest_pre_serve_request', 'eforms_rest_strip_cors_headers' ) !== 15
    || has_filter( 'rest_pre_serve_request', 'eforms_rest_serve_raw_body' ) !== 20
) {
    throw new RuntimeException( 'eForms CORS stripping must run after WordPress core CORS and before raw-body emission.' );
}

$cors_response = new WP_REST_Response( array( 'ok' => true ) );
foreach ( array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Allow-Credentials', 'Access-Control-Expose-Headers', 'Access-Control-Max-Age' ) as $header ) {
    $cors_response->header( $header, 'test-value' );
}
$cors_request = new WP_REST_Request( 'GET', '/eforms/upload-batches' );
eforms_rest_strip_cors_headers( false, $cors_response, $cors_request );
$remaining_cors_headers = array_change_key_case( $cors_response->get_headers(), CASE_LOWER );
foreach ( array( 'access-control-allow-origin', 'access-control-allow-methods', 'access-control-allow-headers', 'access-control-allow-credentials', 'access-control-expose-headers', 'access-control-max-age' ) as $header ) {
    if ( array_key_exists( $header, $remaining_cors_headers ) ) {
        throw new RuntimeException( 'eForms retained a CORS response header: ' . $header );
    }
}

$now = time();
$secret = rtrim( strtr( base64_encode( str_repeat( "\x51", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
$field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 1048576,
    'max_files' => 1,
    'max_total_bytes' => 1048576,
);
$binding = array(
    'raw_token' => 'rest-preview-runtime-token',
    'form_id' => 'upload-test',
    'instance_id' => 'rest-preview-runtime-instance',
    'field_key' => 'photos',
    'accept_until' => $now + 3600,
);
$created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
if ( empty( $created['ok'] ) ) {
    throw new RuntimeException( 'Unable to create the staged preview fixture.' );
}

$source = $tmp_root . '/source.png';
$png = base64_decode( trim( file_get_contents( dirname( __DIR__ ) . '/fixtures/staged-landscape.png.b64' ) ), true );
if ( file_put_contents( $source, $png ) === false ) {
    throw new RuntimeException( 'Unable to write the staged preview source fixture.' );
}

$put = UploadBatchStore::put_item(
    $created['batch']['batch_id'],
    $secret,
    'runtime_photo',
    0,
    array(
        'tmp_name' => $source,
        'original_name' => 'Runtime Photo.png',
        'size' => filesize( $source ),
        'error' => UPLOAD_ERR_OK,
    ),
    $uploads_dir,
    array(
        'now' => $now,
        'memory_limit' => -1,
        'execution_limit' => 0,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
    )
);
if ( empty( $put['ok'] ) ) {
    throw new RuntimeException( 'Unable to process the staged preview fixture.' );
}

$shadow_request = new WP_REST_Request( 'GET' );
$shadow_request->set_url_params(
    array(
        FormProtocol::UPLOAD_BATCH_PARAM => $created['batch']['batch_id'],
        FormProtocol::UPLOAD_ITEM_PARAM => 'runtime_photo',
    )
);
$shadow_request->set_body_params(
    array(
        FormProtocol::UPLOAD_BATCH_PARAM => str_repeat( 'B', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ),
        FormProtocol::UPLOAD_ITEM_PARAM => 'wrong-body-item',
    )
);
$shadow_request->set_query_params(
    array(
        FormProtocol::UPLOAD_BATCH_PARAM => str_repeat( 'C', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ),
        FormProtocol::UPLOAD_ITEM_PARAM => 'wrong-query-item',
    )
);
$shadow_request->set_header( FormProtocol::HEADER_BATCH_SECRET, $secret );
$shadow_result = UploadBatchEndpoint::preview( $shadow_request );
if ( ! is_array( $shadow_result ) || $shadow_result['status'] !== 200 ) {
    throw new RuntimeException( 'WordPress REST body/query parameters overrode route-owned preview identities.' );
}

$cross_origin_options = new WP_REST_Request( 'OPTIONS', '/eforms/upload-batches' );
$cross_origin_options->set_header( 'Origin', 'https://attacker.example' );
$cross_origin_response = rest_do_request( $cross_origin_options );
$cross_origin_body = $cross_origin_response->get_data();
if ( $cross_origin_response->get_status() !== 403 || ! is_array( $cross_origin_body ) || $cross_origin_body['code'] !== 'EFORMS_ERR_ORIGIN_FORBIDDEN' ) {
    throw new RuntimeException( 'WordPress core OPTIONS handling bypassed the eForms cross-origin preflight guard.' );
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_X_EFORMS_BATCH_SECRET'] = $secret;
$route = '/eforms/upload-batches/' . $created['batch']['batch_id'] . '/items/runtime_photo/preview';
ob_start();
rest_get_server()->serve_request( $route );
$body = ob_get_clean();

if ( ! is_string( $body ) || substr( $body, 0, 3 ) !== "\xFF\xD8\xFF" || substr( $body, -2 ) !== "\xFF\xD9" ) {
    throw new RuntimeException( 'WordPress REST did not serve the exact raw JPEG body.' );
}

echo "WordPress REST raw staged preview checks passed.\n";
