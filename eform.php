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

// Load global assets
add_action('wp_enqueue_scripts', function () {
    $js_url = plugins_url('assets/enhanced-form.js', __FILE__);
    wp_enqueue_script('enhanced-icf-js', $js_url, array(), '1.0', true);
});

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
require_once plugin_dir_path( __FILE__ ) . 'includes/class-enhanced-icf-processor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-enhanced-icf.php';

// Initialize plugin
$registry  = new FieldRegistry();
$processor = new Enhanced_ICF_Form_Processor( $logger, $registry );
new Enhanced_Internal_Contact_Form( $processor, $logger );

