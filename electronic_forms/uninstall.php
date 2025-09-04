<?php
declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

require __DIR__ . '/src/Config.php';
EForms\Config::bootstrap();

if (!function_exists('wp_upload_dir')) {
    if (defined('ABSPATH')) {
        $file = ABSPATH . 'wp-admin/includes/file.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }
}

if (!function_exists('wp_upload_dir')) {
    // WordPress functions unavailable; abort gracefully.
    return;
}

// TODO: honor Config uninstall flags (no deletions yet).
