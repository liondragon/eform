<?php
/**
 * Integration test for WordPress-provided mint endpoint script data.
 *
 * Spec: Assets (docs/Canonical_Spec.md#sec-assets)
 * Spec: JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';

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

if ( ! function_exists( 'wp_add_inline_script' ) ) {
    function wp_add_inline_script( $handle, $data, $position = 'after' ) {
        if ( ! isset( $GLOBALS['eforms_test_inline_scripts'] ) ) {
            $GLOBALS['eforms_test_inline_scripts'] = array();
        }
        $GLOBALS['eforms_test_inline_scripts'][] = array(
            'handle' => $handle,
            'data' => $data,
            'position' => $position,
        );
        return true;
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return '/wp-content/plugins/eform/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $path = '' ) {
        return 'https://example.com/blog/wp-json/' . ltrim( $path, '/' );
    }
}

Config::reset_for_tests();
FormRenderer::reset_for_tests();

$GLOBALS['eforms_test_scripts'] = array();
$GLOBALS['eforms_test_inline_scripts'] = array();

$html = FormRenderer::render(
    'quote-request',
    array(
        'cacheable' => true,
        'security' => array(
            'mode' => 'js',
            'token' => '',
            'instance_id' => '',
            'timestamp' => '',
        ),
    )
);

eforms_test_assert( is_string( $html ) && strpos( $html, 'data-eforms-mode="js"' ) !== false, 'Renderer should render JS-minted mode.' );
eforms_test_assert( count( $GLOBALS['eforms_test_scripts'] ) === 1, 'Renderer should enqueue forms.js.' );
eforms_test_assert( count( $GLOBALS['eforms_test_inline_scripts'] ) === 1, 'Renderer should add one inline settings block.' );

$inline = $GLOBALS['eforms_test_inline_scripts'][0];
eforms_test_assert( $inline['handle'] === 'eforms', 'Mint endpoint settings should attach to the eforms script.' );
eforms_test_assert( $inline['position'] === 'before', 'Mint endpoint settings should run before forms.js.' );
eforms_test_assert(
    strpos( $inline['data'], 'window.eformsSettings.mintEndpoint = "https:\/\/example.com\/blog\/wp-json\/eforms\/mint";' ) !== false,
    'Mint endpoint settings should use rest_url().'
);
