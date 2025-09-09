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
    const TEMPLATES_DIR = __DIR__ . '/templates';
    const ASSETS_DIR = __DIR__ . '/assets';
    \define(__NAMESPACE__ . '\\PLUGIN_URL', \plugins_url('', __FILE__));
    \define(__NAMESPACE__ . '\\ASSETS_URL', PLUGIN_URL . '/assets');
}

namespace {
    use EForms\Config;
    use EForms\FormManager;

    if (version_compare(PHP_VERSION, '8.0', '<')) {
        add_action('admin_init', function () {
            deactivate_plugins(plugin_basename(__FILE__));
        });
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' . esc_html__('Electronic Forms requires PHP 8.0 or higher.', 'eforms') . '</p></div>';
        });
        return;
    }

    global $wp_version;
    if (version_compare($wp_version, '5.8', '<')) {
        add_action('admin_init', function () {
            deactivate_plugins(plugin_basename(__FILE__));
        });
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' . esc_html__('Electronic Forms requires WordPress 5.8 or higher.', 'eforms') . '</p></div>';
        });
        return;
    }

    spl_autoload_register(function (string $class): void {
        if (strpos($class, 'EForms\\') !== 0) {
            return;
        }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });

    Config::bootstrap();

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
            $cookie = 'eforms_t_' . $formId;
            $value = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
            $params = [
                'expires'  => time() + $ttl,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ];
            setcookie($cookie, $value, $params);
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
            if ($ct && !preg_match('~^(application/x-www-form-urlencoded|multipart/form-data)(;|$)~i', $ct)) {
                status_header(415);
                echo 'Unsupported Media Type';
                exit;
            }
            // Delegate to FormManager for full submit pipeline.
            $fm = new FormManager();
            $fm->handleSubmit();
            exit;
        }
    });

    function eform_render(string $formId, array $opts = []): string
    {
        $fm = new FormManager();
        return $fm->render($formId, $opts);
    }

    add_shortcode('eform', function ($atts) {
        $atts = shortcode_atts([
            'id' => '',
            'cacheable' => 'true',
        ], $atts, 'eform');
        $formId = sanitize_key($atts['id']);
        $cacheable = filter_var($atts['cacheable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $fm = new FormManager();
        return $fm->render($formId, ['cacheable' => $cacheable !== false]);
    });
}
