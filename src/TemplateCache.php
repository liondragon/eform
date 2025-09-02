<?php
// includes/template-config.php

/**
 * Load a template configuration from the plugin's templates directory.
 *
 * Only JSON configuration files bundled with the plugin are supported.
 *
 * @param string $template Template slug.
 * @return array Parsed configuration.
 */
function eform_get_template_config( string $template ): array {
    static $cache = [];

    $file = $template . '.json';
    if ( ! preg_match( '/^[a-z0-9-]+\.json$/', $file ) ) {
        return [];
    }

    $plugin_dir = rtrim( plugin_dir_path( __DIR__ ), '/\\' ) . '/templates';
    $path       = $plugin_dir . '/' . $file;

    $version  = eform_config_version_token( [ $path ] );
    $cache_key = $template;

    if ( isset( $cache[ $cache_key ] ) && $cache[ $cache_key ]['v'] === $version ) {
        return $cache[ $cache_key ]['c'];
    }

    $cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( $cache_key, 'eform_template_config' ) : false;
    if ( is_array( $cached ) && isset( $cached['v'], $cached['c'] ) && $cached['v'] === $version ) {
        $cache[ $cache_key ] = $cached;
        return $cached['c'];
    }

    $config = eform_load_config_from_paths( [ $path ] );

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
        $file = basename( $path );
        if ( ! preg_match( '/^[a-z0-9-]+\.json$/', $file ) ) {
            continue;
        }
        if ( file_exists( $path ) && is_readable( $path ) ) {
            if ( 'json' === pathinfo( $path, PATHINFO_EXTENSION ) ) {
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
 * Retrieve field metadata for a template.
 *
 * @param string $template Template slug.
 * @return array<string,array> Field rules keyed by logical field key.
 */
function eform_get_template_fields( string $template ): array {
    $config = eform_get_template_config( $template );
    $fields = [];
    $reserved_keys    = [
        'form_id',
        'instance_id',
        '_wpnonce',
        'eforms_hp',
        'timestamp',
        'js_ok',
        'ip',
        'submitted_at',
    ];
    $multi_value_types = [ 'checkbox' ];

    foreach ( $config['fields'] ?? [] as $post_key => $field ) {
        if ( ! isset( $field['key'] ) ) {
            // Skip pseudo-fields like row_group that do not carry data.
            continue;
        }

        $key      = sanitize_key( $field['key'] );
        $post_key = sanitize_key( $field['post_key'] ?? $field['key'] );
        if ( in_array( $key, $reserved_keys, true ) ) {
            continue;
        }
        if ( in_array( $field['type'] ?? '', $multi_value_types, true ) ) {
            $post_key .= '[]';
        }

        $field['post_key'] = $post_key;
        unset( $field['key'] );
        $fields[ $key ] = $field;
    }

    return $fields;
}

/**
 * Remove all cached template configuration entries.
 *
 * Intended for use on plugin activation or after modifying template files to
 * clear potentially stale data stored in the object cache.
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
