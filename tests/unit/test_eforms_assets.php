<?php
/**
 * Unit tests for canonical browser asset routing.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['eforms_asset_styles'] = array();
$GLOBALS['eforms_asset_scripts'] = array();

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src, $dependencies = array(), $version = false ) {
        $GLOBALS['eforms_asset_styles'][] = array( 'handle' => $handle, 'src' => $src, 'dependencies' => $dependencies );
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src, $dependencies = array(), $version = false, $in_footer = false ) {
        $GLOBALS['eforms_asset_scripts'][] = array( 'handle' => $handle, 'src' => $src, 'in_footer' => $in_footer );
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        $origin = isset( $GLOBALS['eforms_test_asset_origin'] ) ? $GLOBALS['eforms_test_asset_origin'] : '';
        return $origin . '/wp-content/plugins/eforms/' . ltrim( $path, '/' );
    }
}

require_once __DIR__ . '/../../src/EformsAssets.php';

EformsAssets::enqueue_form( array(), true );
eforms_test_assert( array_column( $GLOBALS['eforms_asset_styles'], 'handle' ) === array( 'eforms', 'eforms-upload' ), 'Staged forms should route through core and upload style handles.' );
eforms_test_assert( $GLOBALS['eforms_asset_styles'][1]['dependencies'] === array( 'eforms' ), 'Upload styles should depend on the core form stylesheet.' );
eforms_test_assert( array_column( $GLOBALS['eforms_asset_scripts'], 'handle' ) === array( 'eforms' ), 'Forms should route through the canonical browser runtime handle.' );

$GLOBALS['eforms_asset_styles'] = array();
$GLOBALS['eforms_asset_scripts'] = array();
EformsAssets::enqueue_review( array() );
eforms_test_assert( array_column( $GLOBALS['eforms_asset_styles'], 'handle' ) === array( 'eforms', 'eforms-review-gallery' ), 'Review pages should route through core and review style handles.' );
eforms_test_assert( $GLOBALS['eforms_asset_styles'][1]['dependencies'] === array( 'eforms' ), 'Review styles should depend on the core stylesheet.' );
eforms_test_assert( array_column( $GLOBALS['eforms_asset_scripts'], 'handle' ) === array( 'eforms-review-gallery' ), 'Review pages should route through the review runtime only.' );

$GLOBALS['eforms_asset_styles'] = array();
$GLOBALS['eforms_asset_scripts'] = array();
EformsAssets::enqueue_admin_settings();
eforms_test_assert( array_column( $GLOBALS['eforms_asset_styles'], 'handle' ) === array( 'eforms-admin-settings' ), 'Settings should route through its scoped admin stylesheet.' );
eforms_test_assert( array_column( $GLOBALS['eforms_asset_scripts'], 'handle' ) === array( 'eforms-admin-settings' ), 'Settings should route through its scoped admin runtime.' );

$GLOBALS['eforms_test_asset_origin'] = 'https://cdn.example.net';
$worker_url = EformsAssets::same_origin_versioned_url( 'assets/client-image-preparer.js' );
eforms_test_assert(
    strpos( $worker_url, '/wp-content/plugins/eforms/assets/client-image-preparer.js?ver=' ) === 0
        && strpos( $worker_url, 'cdn.example.net' ) === false,
    'Document-origin Worker asset URLs should strip plugin URL origins while retaining versioning.'
);
$GLOBALS['eforms_test_asset_origin'] = '';

echo "Eforms asset tests passed.\n";
