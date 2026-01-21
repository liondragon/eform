<?php
/**
 * Integration tests for /eforms/mint contract.
 *
 * Spec: JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode)
 * Spec: Throttling (docs/Canonical_Spec.md#sec-throttling)
 * Spec: Security (docs/Canonical_Spec.md#sec-security)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Helpers.php';
require_once __DIR__ . '/../../src/Security/MintEndpoint.php';
require_once __DIR__ . '/../../src/Security/Security.php';

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => isset( $GLOBALS['eforms_test_uploads_dir'] ) ? $GLOBALS['eforms_test_uploads_dir'] : '',
        );
    }
}

if ( ! function_exists( 'eforms_test_remove_tree' ) ) {
    function eforms_test_remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
            return;
        }

        $items = array_diff( scandir( $path ), array( '.', '..' ) );
        foreach ( $items as $item ) {
            eforms_test_remove_tree( $path . '/' . $item );
        }
        @rmdir( $path );
    }
}

if ( ! function_exists( 'eforms_test_setup_uploads' ) ) {
    function eforms_test_setup_uploads( $prefix ) {
        $uploads_dir = eforms_test_tmp_root( $prefix );
        mkdir( $uploads_dir, 0700, true );
        $GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
        return $uploads_dir;
    }
}

if ( ! function_exists( 'eforms_test_configure_uploads' ) ) {
    function eforms_test_configure_uploads( $uploads_dir, $extra = array() ) {
        eforms_test_set_filter(
            'eforms_config',
            function ( $config ) use ( $uploads_dir, $extra ) {
                $config['uploads']['dir'] = $uploads_dir;
                foreach ( $extra as $key => $value ) {
                    if ( $key === 'security' || $key === 'throttle' || $key === 'uploads' ) {
                        $config[ $key ] = array_merge( $config[ $key ], $value );
                    }
                }
                return $config;
            }
        );
        Config::reset_for_tests();
    }
}

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

// Given a valid POST /eforms/mint request...
// When the endpoint runs...
// Then it returns a JSON payload and persists a JS token record.
$uploads_dir = eforms_test_setup_uploads( 'eforms-mint' );
eforms_test_configure_uploads( $uploads_dir, array( 'throttle' => array( 'enable' => false ) ) );

$request = array(
    'method' => 'POST',
    'headers' => array(
        'Origin' => 'https://example.com',
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
    'params' => array( 'f' => 'contact' ),
    'client_ip' => '203.0.113.10',
);

$response = MintEndpoint::handle( $request );
eforms_test_assert( $response['status'] === 200, 'Mint should return 200 for valid requests.' );
eforms_test_assert( $response['headers']['Cache-Control'] === 'no-store, max-age=0', 'Mint should set cache-control.' );
eforms_test_assert( strpos( $response['headers']['Content-Type'], 'application/json' ) === 0, 'Mint should return JSON content type.' );
eforms_test_assert( isset( $response['body']['token'] ), 'Mint response should include token.' );
eforms_test_assert( isset( $response['body']['instance_id'] ), 'Mint response should include instance_id.' );
eforms_test_assert( isset( $response['body']['timestamp'] ), 'Mint response should include timestamp.' );
eforms_test_assert( isset( $response['body']['expires'] ), 'Mint response should include expires.' );

eforms_test_assert(
    preg_match( Security::TOKEN_REGEX, $response['body']['token'] ) === 1,
    'Mint token should be a UUID.'
);

eforms_test_assert(
    preg_match( Security::INSTANCE_ID_REGEX, $response['body']['instance_id'] ) === 1,
    'Mint instance_id should be base64url.'
);

$record_path = $uploads_dir . '/eforms-private/tokens/' . Helpers::h2( $response['body']['token'] ) . '/' . hash( 'sha256', $response['body']['token'] ) . '.json';
eforms_test_assert( is_file( $record_path ), 'Mint should persist a JS token record.' );
$record = json_decode( file_get_contents( $record_path ), true );
eforms_test_assert( $record['mode'] === 'js', 'Persisted token record should be JS mode.' );
eforms_test_assert( $record['form_id'] === 'contact', 'Persisted token record should store form_id.' );

eforms_test_remove_tree( $uploads_dir );

// Given a GET request...
// When the endpoint runs...
// Then it returns 405 with Allow: POST.
$request['method'] = 'GET';
$response = MintEndpoint::handle( $request );
eforms_test_assert( $response['status'] === 405, 'Mint should reject non-POST methods.' );
eforms_test_assert( $response['headers']['Allow'] === 'POST', 'Mint should include Allow header.' );
eforms_test_assert( $response['body']['error'] === 'EFORMS_ERR_METHOD_NOT_ALLOWED', 'Mint should return method error code.' );

// Given a JSON content type...
// When the endpoint runs...
// Then it rejects the request with a type error.
$request['method'] = 'POST';
$request['headers']['Content-Type'] = 'application/json';
$response = MintEndpoint::handle( $request );
eforms_test_assert( $response['status'] === 400, 'Mint should reject JSON content type.' );
eforms_test_assert( $response['body']['error'] === 'EFORMS_ERR_TYPE', 'Mint should return type error.' );

// Given a missing form id...
// When the endpoint runs...
// Then it returns invalid form id.
$request['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
$request['params'] = array();
$response = MintEndpoint::handle( $request );
eforms_test_assert( $response['status'] === 400, 'Mint should reject missing form id.' );
eforms_test_assert( $response['body']['error'] === 'EFORMS_ERR_INVALID_FORM_ID', 'Mint should return invalid form id.' );

// Given a cross-origin request...
// When the endpoint runs...
// Then it hard-fails with origin forbidden.
$request['params'] = array( 'f' => 'contact' );
$request['headers']['Origin'] = 'https://evil.example';
$response = MintEndpoint::handle( $request );
eforms_test_assert( $response['status'] === 403, 'Mint should reject cross-origin requests.' );
eforms_test_assert( $response['body']['error'] === 'EFORMS_ERR_ORIGIN_FORBIDDEN', 'Mint should return origin forbidden.' );

// Given an oversized POST body...
// When the endpoint runs...
// Then it rejects with a mint failure error.
$uploads_dir = eforms_test_setup_uploads( 'eforms-mint-size' );
eforms_test_configure_uploads( $uploads_dir, array( 'security' => array( 'max_post_bytes' => 1 ) ) );
$_SERVER['CONTENT_LENGTH'] = '2';
$request['headers']['Origin'] = 'https://example.com';
$response = MintEndpoint::handle( $request );
eforms_test_assert( $response['status'] === 400, 'Mint should reject oversized POST bodies.' );
eforms_test_assert( $response['body']['error'] === 'EFORMS_ERR_MINT_FAILED', 'Mint should use mint failed for oversize.' );

unset( $_SERVER['CONTENT_LENGTH'] );
eforms_test_remove_tree( $uploads_dir );

// Given an unwritable uploads dir...
// When the endpoint runs...
// Then it surfaces a mint failure.
$uploads_dir = eforms_test_setup_uploads( 'eforms-mint-unwritable' );
chmod( $uploads_dir, 0500 );
eforms_test_configure_uploads( $uploads_dir );

$request['headers']['Origin'] = 'https://example.com';
$response = MintEndpoint::handle( $request );
eforms_test_assert( $response['status'] === 500, 'Mint should fail when storage is unavailable.' );
eforms_test_assert( $response['body']['error'] === 'EFORMS_ERR_MINT_FAILED', 'Mint should return mint failed on storage errors.' );

chmod( $uploads_dir, 0700 );
eforms_test_remove_tree( $uploads_dir );
