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

// Honor uninstall flags
$purgeUploads = (bool) EForms\Config::get('install.uninstall.purge_uploads', false);
$purgeLogs    = (bool) EForms\Config::get('install.uninstall.purge_logs', false);
$baseDir      = (string) EForms\Config::get('uploads.dir', '');
if ($baseDir && ($purgeUploads || $purgeLogs)) {
    // Both uploads and logs live under /eforms-private in this build.
    $target = rtrim($baseDir, '/\\');
    // Safety: ensure it’s inside wp_upload_dir()
    $uploads = wp_upload_dir();
    $root = rtrim($uploads['basedir'], '/\\');
    if (str_starts_with($target, $root)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            /** @var SplFileInfo $path */
            if ($path->isDir()) {
                @rmdir($path->getRealPath());
            } else {
                @unlink($path->getRealPath());
            }
        }
        @rmdir($target);
    }
}
