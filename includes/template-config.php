<?php
// includes/template-config.php

/**
 * Load a template configuration from the active theme and merge with defaults.
 *
 * Looks for `{theme}/eform/{template}.php` or `.json` files. When found the
 * configuration is merged with any built-in defaults.
 *
 * @param string $template Template slug.
 * @return array Merged configuration.
 */
function eform_get_template_config( string $template ): array {
    $plugin_dir = rtrim( plugin_dir_path( __DIR__ ), '/\\' ) . '/templates';
    $plugin_paths = [
        $plugin_dir . '/' . $template . '.php',
        $plugin_dir . '/' . $template . '.json',
    ];
    $base = eform_load_config_from_paths( $plugin_paths );

    $theme_dir = rtrim( get_stylesheet_directory(), '/\\' ) . '/eform';
    $theme_paths = [
        $theme_dir . '/' . $template . '.php',
        $theme_dir . '/' . $template . '.json',
    ];
    $data = eform_load_config_from_paths( $theme_paths );

    return array_replace_recursive( $base, $data );
}

/**
 * Load the first valid configuration file from a set of paths.
 *
 * @param array $paths Potential file locations.
 * @return array Parsed configuration or empty array on failure.
 */
function eform_load_config_from_paths( array $paths ): array {
    foreach ( $paths as $path ) {
        if ( file_exists( $path ) && is_readable( $path ) ) {
            $ext = pathinfo( $path, PATHINFO_EXTENSION );

            if ( 'php' === $ext ) {
                $data = include $path;
                if ( is_array( $data ) ) {
                    return $data;
                }
            }

            if ( 'json' === $ext ) {
                $content = file_get_contents( $path );
                $data    = json_decode( $content, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
                    return $data;
                }
            }
        }
    }

    return [];
}
