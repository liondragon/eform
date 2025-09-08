<?php
declare(strict_types=1);

// Minimal WP stubs and test harness utilities for running plugin in CLI.

// Globals used by harness
global $TEST_HOOKS, $TEST_FILTERS, $TEST_QUERY_VARS, $TEST_ARTIFACTS;
$TEST_HOOKS = [];
$TEST_FILTERS = [];
$TEST_QUERY_VARS = [];
$TEST_ARTIFACTS = [
    'dir' => __DIR__ . '/tmp',
    'status_file' => __DIR__ . '/tmp/status_code.txt',
    'redirect_file' => __DIR__ . '/tmp/redirect.txt',
    'mail_file' => __DIR__ . '/tmp/mail.json',
    'log_file' => __DIR__ . '/tmp/php_error.log',
];
@mkdir($TEST_ARTIFACTS['dir'], 0777, true);
@file_put_contents($TEST_ARTIFACTS['status_file'], '');
@file_put_contents($TEST_ARTIFACTS['redirect_file'], '');
@file_put_contents($TEST_ARTIFACTS['mail_file'], '[]');
@file_put_contents($TEST_ARTIFACTS['log_file'], '');
ini_set('error_log', $TEST_ARTIFACTS['log_file']);

// Simulate WP env
$GLOBALS['wp_version'] = '6.5.0';

function add_action($hook, $callable, $priority = 10, $args = 1) {
    global $TEST_HOOKS;
    $TEST_HOOKS[$hook][$priority][] = $callable;
}
function do_action($hook, ...$args) {
    global $TEST_HOOKS;
    if (!isset($TEST_HOOKS[$hook])) return;
    ksort($TEST_HOOKS[$hook]);
    foreach ($TEST_HOOKS[$hook] as $list) {
        foreach ($list as $cb) {
            $cb(...$args);
        }
    }
}
function add_filter($hook, $callable, $priority = 10, $args = 1) {
    global $TEST_FILTERS;
    $TEST_FILTERS[$hook][$priority][] = $callable;
}
function apply_filters($hook, $value) {
    global $TEST_FILTERS;
    if (!isset($TEST_FILTERS[$hook])) return $value;
    ksort($TEST_FILTERS[$hook]);
    foreach ($TEST_FILTERS[$hook] as $list) {
        foreach ($list as $cb) {
            $value = $cb($value);
        }
    }
    return $value;
}
function get_query_var($key, $default = null) {
    global $TEST_QUERY_VARS;
    return $TEST_QUERY_VARS[$key] ?? $default;
}

function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}
function flush_rewrite_rules() {}
function add_rewrite_rule($a, $b, $c) {}

function plugin_basename($f) { return basename((string)$f); }
function deactivate_plugins($slug) {}
function admin_url($p = '') { return 'http://hub.local/wp-admin/' . ltrim($p, '/'); }

function esc_html__($s, $d = '') { return $s; }
function esc_html($s) { return (string)$s; }
function esc_attr($s) { return (string)$s; }
function esc_url($s) { return (string)$s; }
function esc_textarea($s) { return (string)$s; }
function wp_kses($html, $allowed) { return $html; }
function wp_kses_post($html) { return $html; }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key)); }
function shortcode_atts($pairs, $atts, $shortcode = '') {
    $atts = (array)$atts;
    $out = [];
    foreach ($pairs as $name => $default) {
        $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
    }
    return $out;
}
function add_shortcode($tag, $cb) {}

function home_url($path = '') { return 'http://hub.local' . $path; }
function is_ssl() { return false; }
function wp_safe_redirect($location, $status = 302) {
    global $TEST_ARTIFACTS;
    file_put_contents($TEST_ARTIFACTS['redirect_file'], json_encode(['location'=>$location,'status'=>$status]));
}
function add_query_arg($key, $value, $url) {
    $sep = (str_contains((string)$url, '?')) ? '&' : '?';
    return $url . $sep . rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
}
function status_header($code) {
    global $TEST_ARTIFACTS;
    file_put_contents($TEST_ARTIFACTS['status_file'], (string)$code);
}
function nocache_headers() {}
function wp_get_referer() { return $_SERVER['HTTP_REFERER'] ?? null; }
function plugins_url($path = '', $plugin_file = '') {
    return 'http://hub.local/wp-content/plugins/eform/' . ltrim((string)$path, '/');
}
function wp_upload_dir() {
    return ['basedir' => __DIR__ . '/tmp/uploads', 'baseurl' => 'http://hub.local/wp-content/uploads'];
}
function wp_http_validate_url($url) { return filter_var((string)$url, FILTER_VALIDATE_URL) ? $url : false; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function wp_mail($to, $subject, $message, $headers = []) {
    global $TEST_ARTIFACTS;
    $list = json_decode((string)file_get_contents($TEST_ARTIFACTS['mail_file']), true) ?: [];
    $list[] = [
        'to' => $to,
        'subject' => $subject,
        'message' => $message,
        'headers' => $headers,
    ];
    file_put_contents($TEST_ARTIFACTS['mail_file'], json_encode($list));
    // Allow forcing failure via env variable
    return getenv('EFORMS_FORCE_MAIL_FAIL') ? false : true;
}
function is_email($email) {
    return (bool) filter_var((string)$email, FILTER_VALIDATE_EMAIL);
}

function wp_register_style($handle, $src, $deps = [], $ver = false, $args = []) {}
function wp_register_script($handle, $src, $deps = [], $ver = false, $in_footer = []) {}
function wp_enqueue_style($handle) {}
function wp_enqueue_script($handle) {}

// Ensure uploads subdirs exist
@mkdir(__DIR__ . '/tmp/uploads/eforms-private', 0777, true);

// Allow tests to override plugin config using a filter shim
add_filter('eforms_config', function (array $defaults) {
    // Override uploads dir to our tmp path
    $defaults['uploads']['dir'] = __DIR__ . '/tmp/uploads/eforms-private';
    // Allow per-test env toggles
    if (getenv('EFORMS_ORIGIN_MODE')) {
        $defaults['security']['origin_mode'] = getenv('EFORMS_ORIGIN_MODE');
    }
    if (getenv('EFORMS_ORIGIN_MISSING_HARD')) {
        $defaults['security']['origin_missing_hard'] = (getenv('EFORMS_ORIGIN_MISSING_HARD') === '1');
    }
    if (getenv('EFORMS_SUBMISSION_TOKEN_REQUIRED')) {
        $defaults['security']['submission_token']['required'] = (getenv('EFORMS_SUBMISSION_TOKEN_REQUIRED') === '1');
    }
    if (getenv('EFORMS_COOKIE_MISSING_POLICY')) {
        $defaults['security']['cookie_missing_policy'] = getenv('EFORMS_COOKIE_MISSING_POLICY');
    }
    if (getenv('EFORMS_HONEYPOT_RESPONSE')) {
        $defaults['security']['honeypot_response'] = getenv('EFORMS_HONEYPOT_RESPONSE');
    }
    if (getenv('EFORMS_LOG_LEVEL')) {
        $defaults['logging']['level'] = (int) getenv('EFORMS_LOG_LEVEL');
    }
    if (getenv('EFORMS_UPLOAD_MAX_FILE_BYTES')) {
        $defaults['uploads']['max_file_bytes'] = (int) getenv('EFORMS_UPLOAD_MAX_FILE_BYTES');
    }
    if (getenv('EFORMS_UPLOAD_RETENTION_SECONDS')) {
        $defaults['uploads']['retention_seconds'] = (int) getenv('EFORMS_UPLOAD_RETENTION_SECONDS');
    }
    return $defaults;
});

// Define ABSPATH to satisfy plugin guard
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Include the plugin under test
require dirname(__DIR__) . '/eforms.php';
