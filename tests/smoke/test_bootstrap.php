<?php
/**
 * Smoke test for the plugin bootstrap wiring.
 *
 * Contract: Architecture and file layout; Public surfaces index; Lazy-load lifecycle.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['eforms_test_uploads_dir'] = eforms_test_tmp_root( 'eforms-runtime-bootstrap-must-not-probe-storage' );
require_once __DIR__ . '/../../eforms.php';
unset( $GLOBALS['eforms_test_uploads_dir'] );

// Given a loaded plugin...
// When the public entry points are registered...
// Then the template tag and shortcode exist and fail closed.
eforms_test_assert( function_exists( 'eform_render' ), 'eform_render should be defined.' );
$output = eform_render( 'demo', array() );
eforms_test_assert( is_string( $output ), 'eform_render should return a string.' );
eforms_test_assert(
    strpos( $output, 'EFORMS_ERR_SCHEMA_REQUIRED' ) !== false,
    'eform_render should surface the deterministic error code.'
);

eforms_test_assert(
    isset( $GLOBALS['eforms_test_hooks']['shortcode']['eform'] ),
    'Shortcode [eform] should be registered.'
);

eforms_test_assert(
    isset( $GLOBALS['eforms_test_hooks']['action']['rest_api_init'] ),
    'REST init hook should be registered.'
);

eforms_test_assert(
    isset( $GLOBALS['eforms_test_hooks']['action']['init'] ),
    'Init hook should be registered.'
);
eforms_test_assert(
    isset( $GLOBALS['eforms_test_hooks']['activation'][ realpath( __DIR__ . '/../../eforms.php' ) ] ),
    'Plugin activation should register the upload-lifecycle resume owner.'
);

eforms_test_assert(
    isset( $GLOBALS['eforms_test_hooks']['filter']['rest_pre_dispatch'] ),
    'The route-scoped REST preflight guard should be registered.'
);

foreach ( $GLOBALS['eforms_test_hooks']['action']['rest_api_init'] as $callback ) {
    call_user_func( $callback );
}

eforms_test_assert(
    ! empty( $GLOBALS['eforms_test_hooks']['rest'] ),
    'REST routes should be registered during rest_api_init.'
);

foreach ( $GLOBALS['eforms_test_hooks']['action']['init'] as $callback ) {
    call_user_func( $callback );
}

eforms_test_assert(
    ! empty( $GLOBALS['eforms_test_hooks']['rewrite'] ),
    'Rewrite rules should include /eforms/mint.'
);
$activation_uploads = eforms_test_setup_uploads( 'eforms-activation-purge-barrier' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $activation_uploads ) {
        $config['uploads']['dir'] = $activation_uploads;
        return $config;
    }
);
Config::reset_for_tests();
$activation_private = PrivateDir::ensure( $activation_uploads );
$activation_marker = $activation_private['path'] . '/' . PrivateDir::PURGE_MARKER_FILENAME;
file_put_contents( $activation_marker, "purged\n" );
$activation = $GLOBALS['eforms_test_hooks']['activation'][ realpath( __DIR__ . '/../../eforms.php' ) ];
call_user_func( $activation );
eforms_test_assert( ! file_exists( $activation_marker ), 'Activation should clear the uninstall purge barrier before managed requests resume.' );
eforms_test_assert( is_file( $activation_private['path'] . '/' . PrivateDir::LIFECYCLE_LOCK_FILENAME ), 'Activation should preserve the purge synchronization inode.' );
eforms_test_remove_tree( $activation_uploads );

$blocked_uploads = eforms_test_setup_uploads( 'eforms-activation-blocked-purge-barrier' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $blocked_uploads ) {
        $config['uploads']['dir'] = $blocked_uploads;
        return $config;
    }
);
Config::reset_for_tests();
$blocked_private = PrivateDir::ensure( $blocked_uploads );
$blocked_marker = $blocked_private['path'] . '/' . PrivateDir::PURGE_MARKER_FILENAME;
mkdir( $blocked_marker, 0700 );
$activation_failed = false;
try {
    call_user_func( $activation );
} catch ( RuntimeException $error ) {
    $activation_failed = strpos( $error->getMessage(), 'could not reopen' ) !== false;
}
eforms_test_assert( $activation_failed === true, 'Activation should fail visibly when the durable purge barrier cannot be removed.' );
eforms_test_assert( is_dir( $blocked_marker ), 'Failed activation should preserve the purge barrier.' );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $blocked_uploads );

$incompatible_uploads_root = eforms_test_tmp_root( 'eforms-activation-incompatible-storage' );
mkdir( $incompatible_uploads_root, 0700, true );
$incompatible_uploads = eforms_test_write_file( $incompatible_uploads_root, 'not-a-directory', 'x' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $incompatible_uploads ) {
        $config['uploads']['dir'] = $incompatible_uploads;
        return $config;
    }
);
Config::reset_for_tests();
$incompatible_activation_failed = false;
try {
    call_user_func( $activation );
} catch ( RuntimeException $error ) {
    $incompatible_activation_failed = strpos( $error->getMessage(), 'managed storage is incompatible' ) !== false;
}
eforms_test_assert( $incompatible_activation_failed === true, 'Activation should reject incompatible configured storage.' );
eforms_test_assert( is_file( $incompatible_uploads ), 'Failed compatibility probing should preserve the configured path.' );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $incompatible_uploads_root );
