<?php
/**
 * Plugin Name: Electronic Forms
 * Description: Lightweight plugin for forms.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Requires at least: 5.8
 */
declare(strict_types=1);

namespace {
    defined('ABSPATH') || exit;
}

namespace EForms {
    const VERSION = '0.1.0';
    // Paths/URLs for assets & templates
    const PLUGIN_DIR = __DIR__;
    const TEMPLATES_DIR = __DIR__ . '/templates/forms';
    const ASSETS_DIR = __DIR__ . '/assets';
    \define(__NAMESPACE__ . '\\PLUGIN_URL', \plugins_url('', __FILE__));
    \define(__NAMESPACE__ . '\\ASSETS_URL', PLUGIN_URL . '/assets');
}

namespace {
    use EForms\Config;
    use EForms\Rendering\FormRenderer;
    use EForms\Submission\SubmitHandler;

    if (version_compare(PHP_VERSION, '8.0', '<')) {
        add_action('admin_init', function () {
            deactivate_plugins(plugin_basename(__FILE__));
        });
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' . esc_html('Electronic Forms requires PHP 8.0 or higher.') . '</p></div>';
        });
        return;
    }

    global $wp_version;
    if (version_compare($wp_version, '5.8', '<')) {
        add_action('admin_init', function () {
            deactivate_plugins(plugin_basename(__FILE__));
        });
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' . esc_html('Electronic Forms requires WordPress 5.8 or higher.') . '</p></div>';
        });
        return;
    }

    require_once __DIR__ . '/src/Config.php';
    require_once __DIR__ . '/src/Helpers.php';
    require_once __DIR__ . '/src/Logging.php';
    require_once __DIR__ . '/src/TemplateSpec.php';
    require_once __DIR__ . '/src/Spec.php';
    require_once __DIR__ . '/src/Rendering/Renderer.php';
    require_once __DIR__ . '/src/Validation/Normalizer.php';
    require_once __DIR__ . '/src/Validation/Validator.php';
    require_once __DIR__ . '/src/Validation/TemplateValidator.php';
    require_once __DIR__ . '/src/Security/Security.php';
    require_once __DIR__ . '/src/Uploads/Uploads.php';
    require_once __DIR__ . '/src/Email/Emailer.php';
    require_once __DIR__ . '/src/Rendering/FormRenderer.php';
    require_once __DIR__ . '/src/Submission/SubmitHandler.php';

    Config::bootstrap();

    if (!extension_loaded('fileinfo')) {
        define('EFORMS_FINFO_UNAVAILABLE', true);
    }

    // If the request includes any eforms_* query args, ensure responses are not cached.
    foreach (array_keys($_GET) as $key) {
        if (str_starts_with($key, 'eforms_')) {
            \nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
            if (function_exists('eforms_header')) {
                eforms_header('Cache-Control: private, no-store, max-age=0');
            }
            break;
        }
    }

    /**
     * Rewrite rules + query vars for /eforms/prime and /eforms/submit.
     */
    \register_activation_hook(__FILE__, function () {
        add_rewrite_rule('^eforms/prime$', 'index.php?eforms_prime=1', 'top');
        add_rewrite_rule('^eforms/submit$', 'index.php?eforms_submit=1', 'top');
        \flush_rewrite_rules();
    });
    \register_deactivation_hook(__FILE__, function () {
        \flush_rewrite_rules();
    });
    \add_action('init', function () {
        add_rewrite_rule('^eforms/prime$', 'index.php?eforms_prime=1', 'top');
        add_rewrite_rule('^eforms/submit$', 'index.php?eforms_submit=1', 'top');
    });
    \add_filter('query_vars', function ($vars) {
        $vars[] = 'eforms_prime';
        $vars[] = 'eforms_submit';
        return $vars;
    });

    /**
     * Router for prime/submit.
     */
    \add_action('template_redirect', function () {
        $isPrime = get_query_var('eforms_prime');
        $isSubmit = get_query_var('eforms_submit');
        if ($isPrime) {
            // /eforms/prime?f={form_id}
            $formId = isset($_GET['f']) ? sanitize_key((string)$_GET['f']) : '';
            if ($formId === '') {
                status_header(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Missing form id.';
                exit;
            }
            $ttl = (int) Config::get('security.token_ttl_seconds', 600);
            $cookie = 'eforms_eid_' . $formId;
            $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
            $issuedAt = time();
            $value = 'i-' . $uuid;
            $expire = $issuedAt + $ttl;
            $maxAge = max(0, $expire - $issuedAt);
            $cookieStr = $cookie . '=' . rawurlencode($value)
                . '; Path=/'
                . '; HttpOnly'
                . '; SameSite=Lax'
                . '; Max-Age=' . $maxAge
                . '; Expires=' . gmdate('D, d M Y H:i:s', $expire) . ' GMT';
            if (is_ssl()) {
                $cookieStr .= '; Secure';
            }
            header('Set-Cookie: ' . $cookieStr, false);
            if (function_exists('eforms_header')) {
                eforms_header('Set-Cookie: ' . $cookieStr);
            }
            $_COOKIE[$cookie] = $value;
            $uploadsDir = rtrim((string) Config::get('uploads.dir', ''), '/');
            if ($uploadsDir !== '') {
                $hash = hash('sha256', $value);
                $shard = substr($hash, 0, 2);
                $dir = $uploadsDir . '/eid_minted/' . $formId . '/' . $shard;
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                    @chmod($dir, 0700);
                }
                if (is_dir($dir)) {
                    $slotsAllowed = [];
                    if ((bool) Config::get('security.cookie_mode_slots_enabled', false)) {
                        $slotsAllowed = Config::get('security.cookie_mode_slots_allowed', []);
                        if (!is_array($slotsAllowed)) {
                            $slotsAllowed = [];
                        }
                    }
                    $payload = json_encode([
                        'mode' => 'cookie',
                        'form_id' => $formId,
                        'eid' => $value,
                        'issued_at' => $issuedAt,
                        'expires' => $expire,
                        'slots_allowed' => array_values($slotsAllowed),
                    ], JSON_UNESCAPED_SLASHES);
                    if ($payload !== false) {
                        $file = $dir . '/' . $value . '.json';
                        @file_put_contents($file, $payload);
                        @chmod($file, 0600);
                    }
                }
            }
            // No-store 204
            \nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
            if (function_exists('eforms_header')) {
                eforms_header('Cache-Control: private, no-store, max-age=0');
            }
            status_header(204);
            exit;
        }
        if ($isSubmit) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Allow: POST');
                status_header(405);
                exit;
            }
            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if ($ct) {
                if (stripos($ct, 'multipart/form-data') === 0) {
                    if (!preg_match('~boundary=(?:"([^"]+)"|([^;]+))~i', $ct, $m)) {
                        status_header(415);
                        echo 'Unsupported Media Type';
                        exit;
                    }
                    $boundary = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
                    if ($boundary === '') {
                        status_header(415);
                        echo 'Unsupported Media Type';
                        exit;
                    }
                } elseif (!preg_match('~^application/x-www-form-urlencoded($|;)~i', $ct)) {
                    status_header(415);
                    echo 'Unsupported Media Type';
                    exit;
                }
            }
            // Delegate to SubmitHandler for full submit pipeline.
            $sh = new SubmitHandler();
            $sh->handleSubmit();
            exit;
        }
    });

    function eform_render(string $formId, array $opts = []): string
    {
        $fr = new FormRenderer();
        return $fr->render($formId, $opts);
    }

    add_shortcode('eform', function ($atts) {
        $atts = shortcode_atts([
            'id' => '',
            'cacheable' => 'false',
        ], $atts, 'eform');
        $formId = sanitize_key($atts['id']);
        $cacheable = filter_var($atts['cacheable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $fr = new FormRenderer();
        return $fr->render($formId, ['cacheable' => $cacheable === true]);
    });
}
