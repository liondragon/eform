<?php
/*
  Plugin Name: Enhanced iContact Form
  Plugin URI: https://inspiredexperts.com
  Description: Advanced Internal Contact Form with improved security
  Version: 2.2
  Author: James Alexander
*/

if (!defined('ABSPATH')) {
    exit;
}

// Load Composer autoloader if available for external dependencies.
$autoload_path = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
if ( file_exists( $autoload_path ) ) {
    require_once $autoload_path;
}

// Load assets only when shortcode is present
function enhanced_icf_enqueue_scripts() {
    global $post;

    if ( isset( $post->post_content ) && has_shortcode( $post->post_content, 'enhanced_icf_shortcode' ) ) {
        $js_url = plugins_url( 'assets/enhanced-form.js', __FILE__ );
        wp_enqueue_script( 'enhanced-icf-js', $js_url, array(), '1.0', true );
    }
}
add_action( 'wp_enqueue_scripts', 'enhanced_icf_enqueue_scripts' );

// Include supporting files
require_once plugin_dir_path( __FILE__ ) . 'includes/logger.php';
$logger = new Logger();
require_once plugin_dir_path( __FILE__ ) . 'includes/mail-error-logger.php';
/**
 * Register mail error logging callbacks.
 *
 * The Mail_Error_Logger class hooks into WordPress mail error and
 * PHPMailer debugging events using the provided logger instance.
 */
new Mail_Error_Logger( $logger );
require_once plugin_dir_path( __FILE__ ) . 'includes/field-registry.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/template-tags.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/render.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/template-config.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-enhanced-icf-processor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-enhanced-icf.php';

// Initialize plugin with global defaults for backward compatibility.
$registry  = new FieldRegistry();
$GLOBALS['eform_registry'] = $registry;
$processor = new Enhanced_ICF_Form_Processor( $logger, $registry );
$form      = new Enhanced_Internal_Contact_Form( $processor, $logger );

// Each shortcode instance gets its own registry and processor.
add_shortcode( 'enhanced_icf_shortcode', function( $atts = [] ) use ( $logger, $form ) {
    $registry_instance  = new FieldRegistry();
    $processor_instance = new Enhanced_ICF_Form_Processor( $logger, $registry_instance );
    return $form->handle_shortcode( $atts, $registry_instance, $processor_instance );
} );

