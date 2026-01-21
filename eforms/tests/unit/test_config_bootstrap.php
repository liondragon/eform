<?php
/**
 * Unit tests for Config bootstrap and override precedence.
 *
 * Spec: Configuration (docs/Canonical_Spec.md#sec-configuration).
 */

require_once __DIR__ . '/../bootstrap.php';

eforms_test_define_wp_content( 'eforms-config' );

require_once __DIR__ . '/../../src/Config.php';

$dropin_path = WP_CONTENT_DIR . '/' . Config::DROPIN_FILENAME;

$write_dropin = function ( $content ) use ( $dropin_path ) {
    file_put_contents( $dropin_path, $content );
};

$remove_dropin = function () use ( $dropin_path ) {
    if ( file_exists( $dropin_path ) ) {
        unlink( $dropin_path );
    }
};

// Given no drop-in...
// When Config::get is called...
// Then defaults are used.
$remove_dropin();
Config::reset_for_tests();
$defaults = Config::defaults();
$config   = Config::get();
eforms_test_assert( $config['security']['origin_mode'] === $defaults['security']['origin_mode'], 'Defaults should load when no drop-in exists.' );

// Given a valid drop-in override...
// When Config::get is called...
// Then the override is applied.
$write_dropin( "<?php\nreturn ['security' => ['origin_mode' => 'hard']];\n" );
Config::reset_for_tests();
$config = Config::get();
eforms_test_assert( $config['security']['origin_mode'] === 'hard', 'Drop-in overrides should apply.' );

// Given drop-in output...
// When Config::get is called...
// Then the drop-in is rejected.
$write_dropin( "<?php echo 'oops'; return ['security' => ['origin_mode' => 'hard']];\n" );
Config::reset_for_tests();
$config = Config::get();
eforms_test_assert( $config['security']['origin_mode'] === $defaults['security']['origin_mode'], 'Drop-in output should be rejected.' );

// Given a drop-in with unknown keys...
// When Config::get is called...
// Then overrides are rejected.
$write_dropin( "<?php return ['unknown' => ['x' => 1], 'security' => ['origin_mode' => 'hard']];\n" );
Config::reset_for_tests();
$config = Config::get();
eforms_test_assert( $config['security']['origin_mode'] === $defaults['security']['origin_mode'], 'Unknown keys should reject drop-in overrides.' );

// Given a filter override...
// When Config::get is called...
// Then the filter takes precedence.
$write_dropin( "<?php return ['security' => ['origin_mode' => 'hard']];\n" );
eforms_test_set_filter(
    'eforms_config',
    function ( $current ) {
    return array( 'security' => array( 'origin_mode' => 'off' ) );
    }
);
Config::reset_for_tests();
$config = Config::get();
eforms_test_assert( $config['security']['origin_mode'] === 'off', 'Filter overrides should take precedence.' );
eforms_test_set_filter( 'eforms_config', null );

// Given a returned config snapshot...
// When the caller mutates it...
// Then the stored snapshot remains unchanged.
$remove_dropin();
Config::reset_for_tests();
$config = Config::get();
$config['security']['origin_mode'] = 'hard';
$again = Config::get();
eforms_test_assert( $again['security']['origin_mode'] === $defaults['security']['origin_mode'], 'Config snapshot should remain frozen.' );

$remove_dropin();
