<?php
/**
 * Focused uninstall test for admin option cleanup before uploads API guards.
 *
 * Contract: Configuration
 */

if ( ! function_exists( 'eforms_test_assert' ) ) {
    function eforms_test_assert( $condition, $message ) {
        if ( ! $condition ) {
            throw new RuntimeException( $message );
        }
    }
}

$GLOBALS['eforms_test_deleted_options'] = array();

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $name ) {
        $GLOBALS['eforms_test_deleted_options'][] = $name;
        return true;
    }
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    define( 'WP_UNINSTALL_PLUGIN', true );
}

require __DIR__ . '/../../uninstall.php';

eforms_test_assert(
    in_array( 'eforms_admin_config', $GLOBALS['eforms_test_deleted_options'], true ),
    'Uninstall should delete eforms_admin_config before returning when wp_upload_dir() is unavailable.'
);
