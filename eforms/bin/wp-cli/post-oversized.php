<?php
/**
 * Smoke script: POST /eforms/mint oversized body must fail deterministically.
 *
 * Usage:
 *   wp eval-file wp-content/plugins/eform/bin/wp-cli/post-oversized.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "This script must run inside WordPress (wp eval-file).\n" );
    exit( 1 );
}

if ( ! function_exists( 'rest_do_request' ) || ! class_exists( 'WP_REST_Request' ) ) {
    fwrite( STDERR, "REST runtime unavailable.\n" );
    exit( 1 );
}

add_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['max_post_bytes'] = 1;
        return $config;
    }
);

if ( class_exists( 'Config' ) && method_exists( 'Config', 'reset_for_tests' ) ) {
    Config::reset_for_tests();
}

$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['SERVER_PORT'] = 80;
unset( $_SERVER['HTTPS'] );
$_SERVER['CONTENT_LENGTH'] = 2;

// Ensure routes are registered for this runtime.
rest_get_server();

$request = new WP_REST_Request( 'POST', '/eforms/mint' );
$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
$request->set_header( 'Origin', 'http://example.test' );
$request->set_param( 'f', 'contact' );

$response = rest_do_request( $request );
$status = (int) $response->get_status();
$data = $response->get_data();
$error = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : '';

unset( $_SERVER['CONTENT_LENGTH'] );

if ( $status !== 400 || $error !== 'EFORMS_ERR_MINT_FAILED' ) {
    fwrite(
        STDERR,
        sprintf(
            "Unexpected /eforms/mint oversized result: status=%d error=%s\n",
            $status,
            $error
        )
    );
    exit( 1 );
}
