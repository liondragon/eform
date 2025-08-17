<?php
/*
  Plugin Name: Enhanced iContact Form
  Plugin URI: https://inspiredexperts.com
  Description: Advanced Internal Contact Form with improved security
  Version: 2.2
  Author: James Alexander
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$autoload_path = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
if ( file_exists( $autoload_path ) ) {
    require_once $autoload_path;
}

spl_autoload_register( function ( $class ) {
    $map = [
        'Logging'                      => 'src/Logging.php',
        'Mail_Error_Logger'            => 'src/MailErrorLogger.php',
        'FormData'                     => 'src/FormData.php',
        'Renderer'                     => 'src/Renderer.php',
        'FormManager'                  => 'src/FormManager.php',
        'Validator'                    => 'src/Validator.php',
        'Emailer'                      => 'src/Emailer.php',
        'Security'                     => 'src/Security.php',
        'Enhanced_ICF_Form_Processor'  => 'src/class-enhanced-icf-processor.php',
        'Enhanced_Internal_Contact_Form' => 'src/class-enhanced-icf.php',
        'FieldRegistry'                => 'src/FieldRegistry.php',
        'ValueNormalizer'              => 'src/Normalizer.php',
        'Uploads'                      => 'src/Uploads.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require_once plugin_dir_path( __FILE__ ) . $map[ $class ];
    }
} );

require_once plugin_dir_path( __FILE__ ) . 'src/Helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'src/TemplateCache.php';

$logger    = new Logging();
new Mail_Error_Logger( $logger );
$processor = new Enhanced_ICF_Form_Processor( $logger );
$renderer  = new Renderer();
$form      = new Enhanced_Internal_Contact_Form( $processor, $logger, $renderer );
$manager   = new FormManager( $form, $renderer );

add_shortcode( 'eforms', function( $atts = [] ) use ( $logger, $manager ) {
    $processor_instance = new Enhanced_ICF_Form_Processor( $logger );
    return $manager->handle_shortcode( $atts, $processor_instance );
} );
