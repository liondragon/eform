<?php
/**
 * Exact-origin validation for intentional external review redirects.
 */

require_once __DIR__ . '/../bootstrap.php';

if ( ! function_exists( 'wp_redirect' ) ) {
    function wp_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
        $GLOBALS['eforms_test_external_redirect'] = array( $location, $status, $x_redirect_by );
        return true;
    }
}

require_once __DIR__ . '/../../src/WordPressRuntime.php';

$target = 'https://media.example.test/v1/review?grant=test';
eforms_test_assert(
    WordPressRuntime::external_redirect( $target, 302, 'https://MEDIA.example.test:443' ) === true,
    'The validated Worker HTTPS origin should be allowed through wp_redirect.'
);
eforms_test_assert(
    $GLOBALS['eforms_test_external_redirect'] === array( $target, 302, 'eForms' ),
    'The runtime owner should pass the exact target and status to WordPress.'
);
$before = $GLOBALS['eforms_test_external_redirect'];
eforms_test_assert(
    WordPressRuntime::external_redirect( 'https://other.example.test/v1/review', 302, 'https://media.example.test' ) === false
        && $GLOBALS['eforms_test_external_redirect'] === $before,
    'A different origin must fail before WordPress receives a redirect.'
);
eforms_test_assert(
    WordPressRuntime::external_redirect( 'http://media.example.test/v1/review', 302, 'https://media.example.test' ) === false,
    'Review redirects must remain HTTPS.'
);

echo "Review external redirect tests passed.\n";
