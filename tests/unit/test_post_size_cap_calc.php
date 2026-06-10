<?php
/**
 * Unit tests for POST size cap calculation.
 *
 * Spec: POST size cap (docs/Canonical_Spec.md#sec-post-size-cap);
 * Configuration (docs/Canonical_Spec.md#sec-configuration).
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

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
