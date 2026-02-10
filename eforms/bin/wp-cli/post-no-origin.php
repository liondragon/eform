<?php
/**
 * Smoke script: POST /eforms/mint without Origin must hard-fail.
 *
 * Usage:
 *   wp eval-file wp-content/plugins/eform/bin/wp-cli/post-no-origin.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "This script must run inside WordPress (wp eval-file).\n" );
    exit( 1 );
}

if ( ! function_exists( 'rest_do_request' ) || ! class_exists( 'WP_REST_Request' ) ) {
    fwrite( STDERR, "REST runtime unavailable.\n" );
    exit( 1 );
}

// Ensure routes are registered for this runtime.
rest_get_server();

$request = new WP_REST_Request( 'POST', '/eforms/mint' );
$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
$request->set_param( 'f', 'contact' );

// Intentionally omit Origin.
$response = rest_do_request( $request );
$status = (int) $response->get_status();
$data = $response->get_data();
$error = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : '';

if ( $status !== 403 || $error !== 'EFORMS_ERR_ORIGIN_FORBIDDEN' ) {
    fwrite(
        STDERR,
        sprintf(
            "Unexpected /eforms/mint no-origin result: status=%d error=%s\n",
            $status,
            $error
        )
    );
    exit( 1 );
}
