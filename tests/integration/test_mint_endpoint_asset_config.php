<?php
/**
 * Integration test for WordPress-provided mint endpoint script data.
 *
 * Contract: Assets
 * Contract: JS-minted mode contract
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
        $origin = isset( $GLOBALS['eforms_test_asset_origin'] ) ? $GLOBALS['eforms_test_asset_origin'] : '';
        return $origin . '/wp-content/plugins/eforms/' . ltrim( $path, '/' );
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
eforms_test_assert(
    strpos( $inline['data'], 'window.eformsSettings.clientPreparation = null;' ) !== false,
    'Client preparation should be default-off without emitting an optional Worker bundle.'
);

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['media']['client_preparation'] = Config::CLIENT_PREPARATION_OPPORTUNISTIC_JPEG;
        return $config;
    }
);
Config::reset_for_tests();
FormRenderer::reset_for_tests();
$GLOBALS['eforms_test_inline_scripts'] = array();
$GLOBALS['eforms_test_asset_origin'] = 'https://cdn.example.net';
FormRenderer::render(
    'quote-request',
    array(
        'cacheable' => true,
        'security' => array( 'mode' => 'js', 'token' => '', 'instance_id' => '', 'timestamp' => '' ),
    )
);
$enabled_inline = $GLOBALS['eforms_test_inline_scripts'][0]['data'];
eforms_test_assert(
    strpos( $enabled_inline, 'window.eformsSettings.clientPreparation = {"workerUrl":"\/wp-content\/plugins\/eforms\/assets\/client-image-preparer.js?ver=' ) !== false
        && strpos( $enabled_inline, '","recipe":' . json_encode( FormProtocol::client_preparation_recipe() ) . '};' ) !== false
        && strpos( $enabled_inline, 'cdn.example.net' ) === false,
    'Enabled client preparation should emit one document-origin cache-versioned Worker and fixed-recipe bundle.'
);
$GLOBALS['eforms_test_asset_origin'] = '';
eforms_test_set_filter( 'eforms_config', null );
