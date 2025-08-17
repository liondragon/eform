<?php
if ( file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
// Minimal WordPress stubs for testing
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}
function sanitize_text_field($str){
    $str = strip_tags($str);
    $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
    return trim($str);
}
function sanitize_email($email){
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}
function sanitize_textarea_field($str){
    return strip_tags($str);
}
function wp_verify_nonce($nonce,$action){
    return $nonce === 'valid';
}
function wp_strip_all_tags($str){
    return strip_tags($str);
}
function wp_kses( $html, $allowed_html = [] ) {
    if ( $html === '' ) {
        return '';
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $wrapper = '<div>' . $html . '</div>';
    if ( ! @$doc->loadHTML( $wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
        return '';
    }
    $body = $doc->getElementsByTagName('div')->item(0);
    $sanitize = function( DOMNode $node ) use ( &$sanitize, $allowed_html ) {
        if ( $node->nodeType === XML_ELEMENT_NODE ) {
            $tag = strtolower( $node->nodeName );
            if ( ! isset( $allowed_html[ $tag ] ) ) {
                while ( $node->firstChild ) {
                    $node->parentNode->insertBefore( $node->firstChild, $node );
                }
                $node->parentNode->removeChild( $node );
                return;
            }
            if ( $node->hasAttributes() ) {
                foreach ( iterator_to_array( $node->attributes ) as $attr ) {
                    if ( ! isset( $allowed_html[ $tag ][ $attr->nodeName ] ) ) {
                        $node->removeAttributeNode( $attr );
                    }
                }
            }
        }
        foreach ( iterator_to_array( $node->childNodes ) as $child ) {
            $sanitize( $child );
        }
    };
    $sanitize( $body );
    $out = '';
    foreach ( iterator_to_array( $body->childNodes ) as $child ) {
        $out .= $doc->saveHTML( $child );
    }
    return $out;
}
function wp_kses_post( $content ){
    return strip_tags( $content );
}
function esc_html($text){
    return htmlspecialchars($text, ENT_QUOTES);
}
function esc_attr($text){
    return htmlspecialchars($text, ENT_QUOTES);
}
function esc_textarea($text){
    return htmlspecialchars($text, ENT_QUOTES);
}
function get_option($name,$default=''){
    if($name==='admin_email'){return 'admin@example.com';}
    return $default;
}
function apply_filters($tag,$value){
    return $value;
}
function wp_mail($to,$subject,$message,$headers){
    $GLOBALS['_last_mail'] = compact('to','subject','message','headers');
    return true;
}
function eform_get_safe_fields($data){
    return array_keys($data);
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
    return json_encode( $data, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, $depth );
}

// Simple in-memory cache for testing wp_cache_* functions.
$GLOBALS['wp_cache'] = [];
function wp_cache_set( $key, $data, $group = '' ) {
    $GLOBALS['wp_cache'][ $group ][ $key ] = $data;
    return true;
}
function wp_cache_get( $key, $group = '' ) {
    return $GLOBALS['wp_cache'][ $group ][ $key ] ?? false;
}
function wp_cache_delete( $key, $group = '' ) {
    if ( isset( $GLOBALS['wp_cache'][ $group ][ $key ] ) ) {
        unset( $GLOBALS['wp_cache'][ $group ][ $key ] );
        return true;
    }
    return false;
}

function get_default_field_values( string $template = 'default' ): array {
    $defaults = [
        'name'    => 'John Doe',
        'email'   => 'john@example.com',
        'tel'   => '1234567890',
        'zip'     => '12345',
        'message' => str_repeat('a', 25),
    ];

    $fields = eform_get_template_fields( $template );
    $values = [];
    foreach ( $fields as $field => $_ ) {
        $values[ $field ] = $defaults[ $field ] ?? '';
    }

    return $values;
}
function sanitize_key($key){
    return preg_replace('/[^a-z0-9_]/','', strtolower($key));
}
function wp_unslash($value){
    return $value;
}
function wp_safe_redirect($url){
    $GLOBALS['redirected_to']=$url;
}
function esc_url_raw($url){
    return $url;
}
function plugins_url( $path = '', $plugin = '' ) {
    return $path;
}
function has_shortcode( $content, $shortcode ) {
    return strpos( $content, '[' . $shortcode ) !== false;
}
function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = false ) {
    $GLOBALS['enqueued_scripts'][] = $handle;
}
function add_action($hook,$callback,$priority=10){
}
function add_shortcode($tag,$callback){
}
function shortcode_atts( $pairs, $atts ) {
    return array_merge( $pairs, $atts );
}
function plugin_dir_path($file){
    return dirname($file) . '/';
}
function get_stylesheet_directory(){
    return $GLOBALS['_eform_theme_dir'] ?? sys_get_temp_dir() . '/theme';
}
function did_action($hook){return false;}
function has_action($hook,$callback){return false;}
function wp_register_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
    $GLOBALS['registered_styles'][] = $handle;
}
function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
    $GLOBALS['enqueued_styles'][] = $handle;
}
function wp_print_styles( $handle = '' ) {
    $GLOBALS['printed_styles'][] = $handle;
}
function wp_style_is( $handle, $list = 'enqueued' ) {
    if ( 'registered' === $list ) {
        return in_array( $handle, $GLOBALS['registered_styles'] ?? [], true );
    }
    return in_array( $handle, $GLOBALS['enqueued_styles'] ?? [], true );
}
function wp_add_inline_style( $handle, $data ) {
    $GLOBALS['inline_styles'][ $handle ] = ( $GLOBALS['inline_styles'][ $handle ] ?? '' ) . $data;
    return true;
}
function wp_mkdir_p($dir){
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return true;
}
function wp_nonce_field($action = -1){
    echo '<input type="hidden" name="_wpnonce" value="valid">';
}
class WP_Post {
    public $post_content;
}
if ( ! defined('WP_CONTENT_DIR') ) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content');
}
require_once __DIR__.'/../src/Helpers.php';
require_once __DIR__.'/../src/Logging.php';
require_once __DIR__.'/../src/FormData.php';
require_once __DIR__.'/../src/FieldRegistry.php';
require_once __DIR__.'/../src/Renderer.php';
require_once __DIR__.'/../src/FormManager.php';
require_once __DIR__.'/../src/Normalizer.php';
require_once __DIR__.'/../src/Validator.php';
require_once __DIR__.'/../src/Emailer.php';
require_once __DIR__.'/../src/Security.php';
require_once __DIR__.'/../src/class-enhanced-icf-processor.php';
require_once __DIR__.'/../src/class-enhanced-icf.php';
require_once __DIR__.'/../src/TemplateCache.php';
