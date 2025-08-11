<?php
require_once __DIR__ . '/../vendor/autoload.php';
// Minimal WordPress stubs for testing
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct( $code = '', $message = '', $data = [] ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
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

function register_template_fields_from_config( FieldRegistry $registry, string $template ): void {
    $config = eform_get_template_config( $template );
    foreach ( $config['fields'] ?? [] as $post_key => $field ) {
        $key   = FieldRegistry::field_key_from_post( $post_key );
        $field = array_merge( $field, [ 'post_key' => $post_key ] );
        $registry->register_field_from_config( $template, $key, $field );
    }
}

function get_default_field_values( FieldRegistry $registry, string $template = 'default' ): array {
    $defaults = [
        'name'    => 'John Doe',
        'email'   => 'john@example.com',
        'phone'   => '1234567890',
        'zip'     => '12345',
        'message' => str_repeat('a', 25),
    ];

    $fields = $registry->get_fields( $template );
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
function plugin_dir_path($file){
    return dirname($file) . '/';
}
function get_stylesheet_directory(){
    return $GLOBALS['_eform_theme_dir'] ?? sys_get_temp_dir() . '/theme';
}
function did_action($hook){return false;}
function has_action($hook,$callback){return false;}
function wp_register_style(){ }
function wp_enqueue_style(){ }
function wp_print_styles(){ }
function wp_style_is(){ return false; }
function wp_add_inline_style(){ }
function wp_mkdir_p($dir){
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return true;
}
function wp_nonce_field(){ }
class WP_Post {
    public $post_content;
}
if ( ! defined('WP_CONTENT_DIR') ) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content');
}
require_once __DIR__.'/../includes/logger.php';
require_once __DIR__.'/../includes/field-registry.php';
require_once __DIR__.'/../includes/template-tags.php';
require_once __DIR__.'/../includes/render.php';
require_once __DIR__.'/../includes/template-config.php';
require_once __DIR__.'/../includes/class-validation-exception.php';
require_once __DIR__.'/../includes/class-enhanced-icf-processor.php';
require_once __DIR__.'/../includes/class-enhanced-icf.php';
