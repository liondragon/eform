<?php
/**
 * Integration test for redirect safety fallback removal.
 *
 * Contract: Redirect Safety
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Submission/Success.php';

eforms_test_assert( ! function_exists( 'wp_safe_redirect' ), 'This test must run without a wp_safe_redirect stub.' );

$context = array(
    'id' => 'contact',
    'result_pages' => array(
        'success' => array(
            'message' => 'Thanks.',
        ),
    ),
);

$result = Success::redirect(
    $context,
    array(
        'current_url' => 'https://example.com/contact/',
    )
);

eforms_test_assert( $result['ok'] === false, 'Success redirect should fail closed without wp_safe_redirect.' );
eforms_test_assert( $result['reason'] === 'redirect_rejected', 'Missing wp_safe_redirect should use redirect_rejected.' );
