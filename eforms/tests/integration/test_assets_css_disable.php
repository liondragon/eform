<?php
/**
 * Integration test for assets.css_disable.
 *
 * Spec: Assets (docs/Canonical_Spec.md#sec-assets)
 * Spec: Configuration (docs/Canonical_Spec.md#sec-configuration)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src, $deps = array(), $ver = false ) {
        if ( ! isset( $GLOBALS['eforms_test_styles'] ) ) {
            $GLOBALS['eforms_test_styles'] = array();
        }
        $GLOBALS['eforms_test_styles'][] = array(
            'handle' => $handle,
            'src' => $src,
        );
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
        if ( ! isset( $GLOBALS['eforms_test_scripts'] ) ) {
            $GLOBALS['eforms_test_scripts'] = array();
        }
        $GLOBALS['eforms_test_scripts'][] = array(
            'handle' => $handle,
            'src' => $src,
        );
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return $path;
    }
}

// Given assets.css_disable is true...
// When FormRenderer renders a cacheable form...
// Then CSS is not enqueued but JS remains enqueued.
Config::reset_for_tests();
FormRenderer::reset_for_tests();

$GLOBALS['eforms_test_styles'] = array();
$GLOBALS['eforms_test_scripts'] = array();

eforms_test_set_filter( 'eforms_config', function ( $config ) {
    if ( ! is_array( $config ) ) {
        return $config;
    }
    if ( ! isset( $config['assets'] ) || ! is_array( $config['assets'] ) ) {
        $config['assets'] = array();
    }
    $config['assets']['css_disable'] = true;
    return $config;
} );

$output = FormRenderer::render( 'quote-request', array( 'cacheable' => true ) );

eforms_test_assert( is_string( $output ), 'Renderer should return HTML.' );
eforms_test_assert(
    strpos( $output, 'name="eforms_mode" value="js"' ) !== false,
    'Renderer should render JS-minted mode when cacheable=true.'
);
eforms_test_assert(
    empty( $GLOBALS['eforms_test_styles'] ),
    'Renderer should not enqueue CSS when assets.css_disable=true.'
);
$js_path = dirname( __DIR__, 2 ) . '/assets/forms.js';
if ( is_file( $js_path ) ) {
    eforms_test_assert(
        ! empty( $GLOBALS['eforms_test_scripts'] ),
        'Renderer should still enqueue JS when assets.css_disable=true.'
    );
} else {
    eforms_test_assert(
        empty( $GLOBALS['eforms_test_scripts'] ),
        'Renderer should skip JS enqueue when forms.js is missing.'
    );
}

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
FormRenderer::reset_for_tests();
