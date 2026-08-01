<?php
/**
 * Unit tests for POST size cap calculation.
 *
 * Contract: POST size cap;
 * Configuration.
 */

require_once __DIR__ . '/../bootstrap.php';

eforms_test_define_wp_content( 'eforms-post-size' );

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Helpers.php';
require_once __DIR__ . '/../../src/Security/PostSize.php';

$mb = Helpers::BYTES_IN_MIB;

$set_config = function ( $max_post_bytes, $uploads_enabled ) {
    eforms_test_set_filter(
        'eforms_config',
        function ( $current ) use ( $max_post_bytes, $uploads_enabled ) {
            return array(
                'security' => array(
                    'max_post_bytes' => $max_post_bytes,
                ),
                'uploads'  => array(
                    'enable' => $uploads_enabled,
                ),
            );
        }
    );

    Config::reset_for_tests();

    return Config::get();
};

// Given uploads disabled and multipart content...
// When effective cap is calculated...
// Then upload ini limits are ignored.
$config = $set_config( 20 * $mb, false );
$cap = PostSize::effective_cap( 'multipart/form-data; boundary=abc', $config, 8 * $mb, 2 * $mb );
eforms_test_assert( $cap === 8 * $mb, 'PostSize should ignore upload INI caps when uploads are disabled.' );

// Given uploads enabled and urlencoded content...
// When effective cap is calculated...
// Then upload ini limits are ignored.
$config = $set_config( 20 * $mb, true );
$cap = PostSize::effective_cap( 'application/x-www-form-urlencoded; charset=UTF-8', $config, 8 * $mb, 2 * $mb );
eforms_test_assert( $cap === 8 * $mb, 'PostSize should ignore upload INI caps for urlencoded posts.' );

// Given uploads enabled and multipart content...
// When effective cap is calculated...
// Then upload ini limits are enforced.
$cap = PostSize::effective_cap( 'multipart/form-data; boundary=abc', $config, 12 * $mb, 6 * $mb );
eforms_test_assert( $cap === 6 * $mb, 'PostSize should honor upload INI caps for multipart posts.' );

// Given an app cap smaller than server limits...
// When effective cap is calculated...
// Then the app cap wins.
$config = $set_config( 3 * $mb, true );
$cap = PostSize::effective_cap( 'multipart/form-data; boundary=abc', $config, 12 * $mb, 6 * $mb );
eforms_test_assert( $cap === 3 * $mb, 'PostSize should honor security.max_post_bytes when it is the smallest cap.' );

$_SERVER['CONTENT_LENGTH'] = '99';
eforms_test_assert( PostSize::content_length() === 99, 'PostSize should read ambient PHP Content-Length for live requests.' );
eforms_test_assert(
    PostSize::content_length(
        array(
            'headers' => array(
                'Content-Length' => '42',
            ),
        )
    ) === 42,
    'PostSize should read explicit request Content-Length headers.'
);
eforms_test_assert(
    PostSize::content_length(
        array(
            'headers' => array(),
        )
    ) === null,
    'PostSize should not fall back to ambient Content-Length when explicit request headers omit it.'
);
eforms_test_assert(
    PostSize::content_length(
        array(
            'headers' => array( 'Content-Length' => '42' ),
            'content_length' => 7,
        )
    ) === 7,
    'PostSize should prefer explicit request content_length over headers.'
);
eforms_test_assert(
    PostSize::request_exceeds_cap(
        array(
            'headers' => array( 'Content-Length' => '4' ),
        ),
        'application/x-www-form-urlencoded',
        $set_config( 3, false )
    ) === true,
    'PostSize should own the request-exceeds-cap predicate.'
);
eforms_test_assert(
    PostSize::request_exceeds_cap(
        array(
            'headers' => array( 'Content-Length' => '3' ),
        ),
        'application/x-www-form-urlencoded',
        $set_config( 3, false )
    ) === false,
    'PostSize should not reject requests at the exact cap.'
);
unset( $_SERVER['CONTENT_LENGTH'] );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
