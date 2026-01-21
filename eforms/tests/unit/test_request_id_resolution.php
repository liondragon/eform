<?php
/**
 * Unit tests for request-id resolution.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

require_once __DIR__ . '/../../src/Logging.php';

if ( ! isset( $GLOBALS['eforms_test_filters'] ) || ! is_array( $GLOBALS['eforms_test_filters'] ) ) {
    $GLOBALS['eforms_test_filters'] = array();
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        $args = func_get_args();
        if ( isset( $GLOBALS['eforms_test_filters'][ $tag ] ) && is_callable( $GLOBALS['eforms_test_filters'][ $tag ] ) ) {
            return call_user_func_array( $GLOBALS['eforms_test_filters'][ $tag ], array_slice( $args, 1 ) );
        }
        return $value;
    }
}

require_once __DIR__ . '/../bootstrap.php';

// Given a filter override and headers...
// When request_id is resolved...
// Then the filter wins.
Logging::reset_for_tests();
eforms_test_set_filter(
    'eforms_request_id',
    function ( $candidate, $request ) {
        return 'filter-id';
    }
);

$request = array(
    'headers' => array(
        'X-Request-Id' => 'header-id',
    ),
);

$resolved = Logging::request_id( $request );
eforms_test_assert( $resolved === 'filter-id', 'Filter override should win over headers.' );

// Given no filter override and multiple headers...
// When request_id is resolved...
// Then the first header in the precedence list wins.
Logging::reset_for_tests();
eforms_test_set_filter( 'eforms_request_id', function () {
    return '';
} );

$request = array(
    'headers' => array(
        'X-Request-Id' => 'second',
        'X-Eforms-Request-Id' => 'first',
        'X-Correlation-Id' => 'third',
    ),
);

$resolved = Logging::request_id( $request );
eforms_test_assert( $resolved === 'first', 'Header precedence should select X-Eforms-Request-Id.' );

// Given a header with extra whitespace...
// When request_id is resolved...
// Then whitespace is normalized.
Logging::reset_for_tests();
eforms_test_set_filter( 'eforms_request_id', null );

$request = array(
    'headers' => array(
        'X-Request-Id' => "  alpha\tbeta  ",
    ),
);

$resolved = Logging::request_id( $request );
eforms_test_assert( $resolved === 'alpha beta', 'Whitespace should be collapsed to single spaces.' );

// Given no filter and no headers...
// When request_id is resolved twice...
// Then a non-empty id is generated and cached.
Logging::reset_for_tests();
$resolved_one = Logging::request_id( array( 'headers' => array() ) );
$resolved_two = Logging::request_id( array( 'headers' => array() ) );
eforms_test_assert( $resolved_one !== '', 'Generated request_id should not be empty.' );
eforms_test_assert( $resolved_one === $resolved_two, 'request_id should be stable for the request lifetime.' );
