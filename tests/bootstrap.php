<?php
// Minimal WordPress stubs for testing
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
function esc_html($text){
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
function add_action($hook,$callback,$priority=10){
}
function add_shortcode($tag,$callback){
}
function plugin_dir_path($file){
    return dirname($file) . '/';
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
if ( ! defined('WP_CONTENT_DIR') ) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content');
}
require_once __DIR__.'/../includes/logger.php';
require_once __DIR__.'/../includes/class-enhanced-icf-processor.php';
require_once __DIR__.'/../includes/class-enhanced-icf.php';
