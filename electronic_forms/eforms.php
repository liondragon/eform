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
