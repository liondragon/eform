<?php
/**
 * Plugin Name: eForms
 * Description: Secure, cache-aware electronic forms for WordPress.
 * Version: 1.0.0
 * Author: eForms Team
 */

require_once __DIR__ . '/src/Compat.php';

if ( ! defined( 'EFORMS_PLUGIN_FILE' ) ) {
    define( 'EFORMS_PLUGIN_FILE', __FILE__ );
}

// Educational note: if the host platform is incompatible, fail closed by not
// registering public surfaces, and (in wp-admin) show a notice + deactivate.
if ( ! Compat::guard( __FILE__ ) ) {
    return;
}

require_once __DIR__ . '/src/bootstrap.php';

if ( function_exists( 'register_activation_hook' ) ) {
    register_activation_hook( __FILE__, 'eforms_activate' );
}
if ( function_exists( 'register_deactivation_hook' ) ) {
    register_deactivation_hook( __FILE__, 'eforms_deactivate' );
}

eforms_bootstrap();
