<?php
/**
 * Send an oversized payload to trigger a 413 response.
 *
 * Usage:
 *   wp eval-file wp-content/plugins/eform/bin/wp-cli/post-oversized.php
 */

$payload = str_repeat( 'A', 26 * 1024 * 1024 ); // ~26MB

$response = wp_remote_post( home_url( '/eforms/submit' ), [
    'sslverify' => false,
    'timeout'   => 60,
    'headers'   => [
        'Content-Type' => 'application/x-www-form-urlencoded',
    ],
    'body'      => [
        'payload' => $payload,
    ],
] );

if ( is_wp_error( $response ) ) {
    WP_CLI::error( 'Request failed: ' . $response->get_error_message() );
}

$code = wp_remote_retrieve_response_code( $response );
if ( $code === 413 ) {
    WP_CLI::success( 'Received expected 413 response.' );
    return;
}

WP_CLI::error( 'Expected 413 response, got ' . $code );
