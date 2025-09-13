<?php
/**
 * Submit a form without an Origin header.
 *
 * Usage:
 *   wp eval-file wp-content/plugins/eform/bin/wp-cli/post-no-origin.php
 */

// Prime the form to obtain the submission cookie.
$prime = wp_remote_get( home_url( '/eforms/prime?f=contact_us' ), [
    'sslverify' => false,
] );

if ( is_wp_error( $prime ) ) {
    WP_CLI::error( 'Prime request failed: ' . $prime->get_error_message() );
}

$cookies = wp_remote_retrieve_cookies( $prime );
if ( empty( $cookies ) ) {
    WP_CLI::error( 'Prime request failed to set cookie.' );
}

// Submit without an Origin header.
$submit = wp_remote_post( home_url( '/eforms/submit' ), [
    'sslverify' => false,
    'cookies'  => $cookies,
    'headers'  => [
        // Intentionally no Origin header.
    ],
    'body'     => [
        'form_id'     => 'contact_us',
        'instance_id' => 'cli-test',
        'name'        => 'Alice',
        'email'       => 'alice@example.com',
        'message'     => 'Hello from WP-CLI',
    ],
] );

if ( is_wp_error( $submit ) ) {
    WP_CLI::error( 'Submit request failed: ' . $submit->get_error_message() );
}

$body = wp_remote_retrieve_body( $submit );
if ( strpos( $body, 'Security check failed.' ) !== false ) {
    WP_CLI::success( 'Missing Origin was rejected as expected.' );
    return;
}

WP_CLI::error( 'Missing Origin was not rejected.' );
