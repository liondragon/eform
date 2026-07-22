<?php
/**
 * External review redirects fail closed when WordPress redirect ownership is unavailable.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/WordPressRuntime.php';

eforms_test_assert( ! function_exists( 'wp_redirect' ), 'This test must run without a wp_redirect stub.' );
eforms_test_assert(
    WordPressRuntime::external_redirect( 'https://media.example.test/v1/review?grant=test', 302, 'https://media.example.test' ) === false,
    'Review delivery must not fall back to a raw Location header when wp_redirect is unavailable.'
);

echo "Review redirect runtime requirement tests passed.\n";
