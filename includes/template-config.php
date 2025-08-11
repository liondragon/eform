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
    static $cache = [];

    $theme_dir = rtrim( get_stylesheet_directory(), '/\\' );
    $cache_key = $template . '|' . $theme_dir;

    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    $cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( $cache_key, 'eform_template_config' ) : false;
    if ( false !== $cached ) {
        $cache[ $cache_key ] = $cached;
        return $cached;
    }

    $plugin_dir = rtrim( plugin_dir_path( __DIR__ ), '/\\' ) . '/templates';
    $plugin_paths = [
        $plugin_dir . '/' . $template . '.php',
        $plugin_dir . '/' . $template . '.json',
    ];
    $base = eform_load_config_from_paths( $plugin_paths );

    $theme_paths = [
        $theme_dir . '/eform/' . $template . '.php',
        $theme_dir . '/eform/' . $template . '.json',
    ];
    $data = eform_load_config_from_paths( $theme_paths );

    $config = array_replace_recursive( $base, $data );

    $cache[ $cache_key ] = $config;
    if ( function_exists( 'wp_cache_set' ) ) {
        wp_cache_set( $cache_key, $config, 'eform_template_config' );
    }

    return $config;
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
