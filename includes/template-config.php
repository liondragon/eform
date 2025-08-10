<?php
// includes/template-config.php

use JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Load a template configuration from the active theme and merge with defaults.
 *
 * Looks for `{theme}/eform/{template}.json` or `.yaml` files. When found the
 * configuration is validated against the `form-template-schema.json` schema
 * before being merged with any built-in defaults.
 *
 * @param string $template Template slug.
 * @return array Merged configuration.
 */
function eform_get_template_config( string $template ): array {
    $schema_file = __DIR__ . '/form-template-schema.json';

    $plugin_dir = rtrim( plugin_dir_path( __DIR__ ), '/\\' ) . '/templates';
    $plugin_paths = [
        $plugin_dir . '/' . $template . '.json',
        $plugin_dir . '/' . $template . '.yaml',
        $plugin_dir . '/' . $template . '.yml',
    ];
    $base = eform_load_config_from_paths( $plugin_paths, $schema_file );

    $theme_dir = rtrim( get_stylesheet_directory(), '/\\' ) . '/eform';
    $theme_paths = [
        $theme_dir . '/' . $template . '.json',
        $theme_dir . '/' . $template . '.yaml',
        $theme_dir . '/' . $template . '.yml',
    ];
    $data = eform_load_config_from_paths( $theme_paths, $schema_file );

    return array_replace_recursive( $base, $data );
}

/**
 * Load the first valid configuration file from a set of paths.
 *
 * @param array  $paths       Potential file locations.
 * @param string $schema_file JSON schema used for validation.
 * @return array Parsed configuration or empty array on failure.
 */
function eform_load_config_from_paths( array $paths, string $schema_file ): array {
    $data = [];
    foreach ( $paths as $path ) {
        if ( file_exists( $path ) && is_readable( $path ) ) {
            $ext = pathinfo( $path, PATHINFO_EXTENSION );
            try {
                if ( 'json' === $ext ) {
                    $data = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
                } else {
                    $data = Yaml::parseFile( $path );
                }
            } catch ( \Throwable $e ) {
                $data = [];
            }
            break;
        }
    }

    if ( empty( $data ) || ! is_array( $data ) ) {
        return [];
    }

    if ( file_exists( $schema_file ) ) {
        $schema    = json_decode( file_get_contents( $schema_file ) );
        $validator = new Validator();
        $validator->validate( json_decode( json_encode( $data ) ), $schema );

        if ( ! $validator->isValid() ) {
            return [];
        }
    }

    return $data;
}
