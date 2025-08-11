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

    $plugin_dir  = rtrim( plugin_dir_path( __DIR__ ), '/\\' ) . '/templates';
    $plugin_paths = [
        $plugin_dir . '/' . $template . '.php',
        $plugin_dir . '/' . $template . '.json',
    ];

    $theme_paths = [
        $theme_dir . '/eform/' . $template . '.php',
        $theme_dir . '/eform/' . $template . '.json',
    ];

    $version = eform_config_version_token( array_merge( $plugin_paths, $theme_paths ) );
    $cache_key = $template . '|' . $theme_dir;

    if ( isset( $cache[ $cache_key ] ) && $cache[ $cache_key ]['v'] === $version ) {
        return $cache[ $cache_key ]['c'];
    }

    $cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( $cache_key, 'eform_template_config' ) : false;
    if ( is_array( $cached ) && isset( $cached['v'], $cached['c'] ) && $cached['v'] === $version ) {
        $cache[ $cache_key ] = $cached;
        return $cached['c'];
    }

    $base = eform_load_config_from_paths( $plugin_paths );
    $data = eform_load_config_from_paths( $theme_paths );

    $config = array_replace_recursive( $base, $data );

    $entry = [ 'v' => $version, 'c' => $config ];
    $cache[ $cache_key ] = $entry;
    if ( function_exists( 'wp_cache_set' ) ) {
        wp_cache_set( $cache_key, $entry, 'eform_template_config' );

        $keys = wp_cache_get( 'keys', 'eform_template_config' );
        if ( ! is_array( $keys ) ) {
            $keys = [];
        }
        if ( ! in_array( $cache_key, $keys, true ) ) {
            $keys[] = $cache_key;
            wp_cache_set( 'keys', $keys, 'eform_template_config' );
        }
    }

    return $config;
}

/**
 * Calculate a version token for a set of configuration file paths.
 *
 * Uses the most recent modification time among the provided files as a simple
 * numeric token. Whenever any configuration file changes, its `filemtime`
 * updates and the derived token differs, forcing the cache to refresh.
 *
 * @param array $paths File paths to inspect.
 * @return string Version token.
 */
function eform_config_version_token( array $paths ): string {
    $latest = 0;
    foreach ( $paths as $path ) {
        $mtime  = file_exists( $path ) ? filemtime( $path ) : 0;
        $latest = max( $latest, $mtime );
    }

    return (string) $latest;
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

/**
 * Remove all cached template configuration entries.
 *
 * Intended for use on theme or plugin activation to clear potentially stale
 * data stored in the object cache.
 */
function eform_purge_template_config_cache(): void {
    if ( ! function_exists( 'wp_cache_delete' ) ) {
        return;
    }

    $keys = wp_cache_get( 'keys', 'eform_template_config' );
    if ( is_array( $keys ) ) {
        foreach ( $keys as $key ) {
            wp_cache_delete( $key, 'eform_template_config' );
        }
        wp_cache_delete( 'keys', 'eform_template_config' );
    }
}
