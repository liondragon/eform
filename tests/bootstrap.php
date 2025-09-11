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
    'headers_file' => __DIR__ . '/tmp/headers.txt',
];
@mkdir($TEST_ARTIFACTS['dir'], 0777, true);
@file_put_contents($TEST_ARTIFACTS['status_file'], '');
@file_put_contents($TEST_ARTIFACTS['redirect_file'], '');
@file_put_contents($TEST_ARTIFACTS['mail_file'], '[]');
@file_put_contents($TEST_ARTIFACTS['log_file'], '');
@file_put_contents($TEST_ARTIFACTS['headers_file'], '');
ini_set('error_log', $TEST_ARTIFACTS['log_file']);


// Simulate WP env
$GLOBALS['wp_version'] = '6.5.0';

// Default user agent for soft-fail calculations
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'phpunit';

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
function remove_action($hook, $callable, $priority = 10) {
    global $TEST_HOOKS;
    if (!isset($TEST_HOOKS[$hook][$priority])) return;
    foreach ($TEST_HOOKS[$hook][$priority] as $i => $cb) {
        if ($cb === $callable) {
            unset($TEST_HOOKS[$hook][$priority][$i]);
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
function wp_kses($html, $allowed, $allowed_protocols = []) {
    $tags = '<' . implode('><', array_keys($allowed)) . '>';
    return strip_tags((string)$html, $tags);
}
function wp_kses_post($html) {
    $allowed = '<a><strong><em><span><p><br><div><h1><h2><h3><h4><h5><h6><ul><ol><li>';
    return strip_tags((string)$html, $allowed);
}
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
function eforms_header(string $h): void {
    global $TEST_ARTIFACTS;
    file_put_contents($TEST_ARTIFACTS['headers_file'], $h . "\n", FILE_APPEND);
}
function wp_get_referer() { return $_SERVER['HTTP_REFERER'] ?? null; }
function plugins_url($path = '', $plugin_file = '') {
    return 'http://hub.local/wp-content/plugins/eform/' . ltrim((string)$path, '/');
}
function wp_upload_dir() {
    return ['basedir' => __DIR__ . '/tmp/uploads', 'baseurl' => 'http://hub.local/wp-content/uploads'];
}
function wp_http_validate_url($url) { return filter_var((string)$url, FILTER_VALIDATE_URL) ? $url : false; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function wp_mail($to, $subject, $message, $headers = [], $attachments = []) {
    global $TEST_ARTIFACTS;
    $mailer = new \stdClass();
    $mailer->From = '';
    do_action('phpmailer_init', $mailer);
    $list = json_decode((string)file_get_contents($TEST_ARTIFACTS['mail_file']), true) ?: [];
    $list[] = [
        'to' => $to,
        'subject' => $subject,
        'message' => $message,
        'headers' => $headers,
        'attachments' => $attachments,
    ];
    file_put_contents($TEST_ARTIFACTS['mail_file'], json_encode($list));
    // Allow forcing failure via env variable
    return getenv('EFORMS_FORCE_MAIL_FAIL') ? false : true;
}
function is_email($email) {
    return (bool) filter_var((string)$email, FILTER_VALIDATE_EMAIL);
}

function wp_json_encode($value, $flags = 0, $depth = 512) {
    $json = json_encode($value, $flags, $depth);
    if ($json !== false) {
        return $json;
    }
    if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
        return json_encode($value, $flags | JSON_PARTIAL_OUTPUT_ON_ERROR, $depth);
    }
    return false;
}

function wp_remote_post($url, $args = []) {
    return ['body' => json_encode(['success' => false])];
}
function wp_remote_retrieve_body($res) {
    return is_array($res) ? ($res['body'] ?? '') : '';
}
function is_wp_error($v) { return false; }

function wp_register_style($handle, $src, $deps = [], $ver = false, $args = []) {}
function wp_register_script($handle, $src, $deps = [], $ver = false, $in_footer = []) {}
$GLOBALS['wp_enqueued_scripts'] = [];
function wp_enqueue_style($handle) {}
function wp_enqueue_script($handle) { $GLOBALS['wp_enqueued_scripts'][] = $handle; }

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
    if (getenv('EFORMS_JS_HARD_MODE')) {
        $defaults['security']['js_hard_mode'] = (getenv('EFORMS_JS_HARD_MODE') === '1');
    }
    $tlenv = getenv('EFORMS_TOKEN_LEDGER_ENABLE');
    if ($tlenv !== false) {
        $defaults['security']['token_ledger']['enable'] = ($tlenv === '1');
    }
    if (getenv('EFORMS_LOG_LEVEL')) {
        $defaults['logging']['level'] = (int) getenv('EFORMS_LOG_LEVEL');
    }
    if (getenv('EFORMS_LOG_HEADERS')) {
        $defaults['logging']['headers'] = (getenv('EFORMS_LOG_HEADERS') === '1');
    }
    if (getenv('EFORMS_LOG_MODE')) {
        $defaults['logging']['mode'] = getenv('EFORMS_LOG_MODE');
    }
    if (getenv('EFORMS_UPLOAD_MAX_FILE_BYTES')) {
        $defaults['uploads']['max_file_bytes'] = (int) getenv('EFORMS_UPLOAD_MAX_FILE_BYTES');
    }
    if (getenv('EFORMS_UPLOAD_RETENTION_SECONDS')) {
        $defaults['uploads']['retention_seconds'] = (int) getenv('EFORMS_UPLOAD_RETENTION_SECONDS');
    }
    if (getenv('EFORMS_CHALLENGE_MODE')) {
        $defaults['challenge']['mode'] = getenv('EFORMS_CHALLENGE_MODE');
    }
    if (getenv('EFORMS_CHALLENGE_PROVIDER')) {
        $defaults['challenge']['provider'] = getenv('EFORMS_CHALLENGE_PROVIDER');
    }
    if (getenv('EFORMS_TURNSTILE_SITE_KEY')) {
        $defaults['challenge']['turnstile']['site_key'] = getenv('EFORMS_TURNSTILE_SITE_KEY');
    }
    if (getenv('EFORMS_TURNSTILE_SECRET_KEY')) {
        $defaults['challenge']['turnstile']['secret_key'] = getenv('EFORMS_TURNSTILE_SECRET_KEY');
    }
    if (getenv('EFORMS_HCAPTCHA_SITE_KEY')) {
        $defaults['challenge']['hcaptcha']['site_key'] = getenv('EFORMS_HCAPTCHA_SITE_KEY');
    }
    if (getenv('EFORMS_HCAPTCHA_SECRET_KEY')) {
        $defaults['challenge']['hcaptcha']['secret_key'] = getenv('EFORMS_HCAPTCHA_SECRET_KEY');
    }
    if (getenv('EFORMS_RECAPTCHA_SITE_KEY')) {
        $defaults['challenge']['recaptcha']['site_key'] = getenv('EFORMS_RECAPTCHA_SITE_KEY');
    }
    if (getenv('EFORMS_RECAPTCHA_SECRET_KEY')) {
        $defaults['challenge']['recaptcha']['secret_key'] = getenv('EFORMS_RECAPTCHA_SECRET_KEY');
    }
    if (getenv('EFORMS_THROTTLE_ENABLE')) {
        $defaults['throttle']['enable'] = (getenv('EFORMS_THROTTLE_ENABLE') === '1');
    }
    if (getenv('EFORMS_THROTTLE_MAX_PER_MINUTE')) {
        $defaults['throttle']['per_ip']['max_per_minute'] = (int) getenv('EFORMS_THROTTLE_MAX_PER_MINUTE');
    }
    if (getenv('EFORMS_THROTTLE_COOLDOWN_SECONDS')) {
        $defaults['throttle']['per_ip']['cooldown_seconds'] = (int) getenv('EFORMS_THROTTLE_COOLDOWN_SECONDS');
    }
    if (getenv('EFORMS_THROTTLE_HARD_MULTIPLIER')) {
        $defaults['throttle']['per_ip']['hard_multiplier'] = (float) getenv('EFORMS_THROTTLE_HARD_MULTIPLIER');
    }
    if (getenv('EFORMS_EMAIL_POLICY')) {
        $defaults['email']['policy'] = getenv('EFORMS_EMAIL_POLICY');
    }
    if (getenv('EFORMS_EMAIL_DISABLE_SEND')) {
        $defaults['email']['disable_send'] = (getenv('EFORMS_EMAIL_DISABLE_SEND') === '1');
    }
    if (getenv('EFORMS_EMAIL_STAGING_REDIRECT_TO')) {
        $val = getenv('EFORMS_EMAIL_STAGING_REDIRECT_TO');
        $defaults['email']['staging_redirect_to'] = str_contains($val, ',') ? array_map('trim', explode(',', $val)) : $val;
    }
    if (getenv('EFORMS_EMAIL_SMTP_TIMEOUT_SECONDS')) {
        $defaults['email']['smtp']['timeout_seconds'] = (int) getenv('EFORMS_EMAIL_SMTP_TIMEOUT_SECONDS');
    }
    if (getenv('EFORMS_EMAIL_SMTP_MAX_RETRIES')) {
        $defaults['email']['smtp']['max_retries'] = (int) getenv('EFORMS_EMAIL_SMTP_MAX_RETRIES');
    }
    if (getenv('EFORMS_EMAIL_SMTP_RETRY_BACKOFF_SECONDS')) {
        $defaults['email']['smtp']['retry_backoff_seconds'] = (int) getenv('EFORMS_EMAIL_SMTP_RETRY_BACKOFF_SECONDS');
    }
    if (getenv('EFORMS_EMAIL_DKIM_DOMAIN')) {
        $defaults['email']['dkim']['domain'] = getenv('EFORMS_EMAIL_DKIM_DOMAIN');
    }
    if (getenv('EFORMS_EMAIL_DKIM_SELECTOR')) {
        $defaults['email']['dkim']['selector'] = getenv('EFORMS_EMAIL_DKIM_SELECTOR');
    }
    if (getenv('EFORMS_EMAIL_DKIM_PRIVATE_KEY_PATH')) {
        $defaults['email']['dkim']['private_key_path'] = getenv('EFORMS_EMAIL_DKIM_PRIVATE_KEY_PATH');
    }
    if (getenv('EFORMS_EMAIL_DKIM_PASS_PHRASE')) {
        $defaults['email']['dkim']['pass_phrase'] = getenv('EFORMS_EMAIL_DKIM_PASS_PHRASE');
    }
    return $defaults;
});

// Define ABSPATH to satisfy plugin guard
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Include the plugin under test
require dirname(__DIR__) . '/eforms.php';
