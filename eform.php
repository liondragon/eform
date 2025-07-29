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
require_once plugin_dir_path(__FILE__) . 'includes/logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-enhanced-icf.php';

// Initialize plugin
new Enhanced_Internal_Contact_Form();
