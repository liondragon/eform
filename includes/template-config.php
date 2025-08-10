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
function eform_get_template_config(string $template): array {
    $defaults = [
        'default' => [
            'fields' => [
                'name_input' => [
                    'type' => 'text',
                    'placeholder' => 'Your Name',
                    'required' => '',
                    'autocomplete' => 'name',
                    'aria-label' => 'Your Name',
                    'aria-required' => 'true',
                    'style' => 'grid-area: name'
                ],
                'email_input' => [
                    'type' => 'email',
                    'placeholder' => 'Your Email',
                    'required' => '',
                    'autocomplete' => 'email',
                    'aria-label' => 'email',
                    'aria-required' => 'true',
                    'style' => 'grid-area: email'
                ],
                'tel_input' => [
                    'type' => 'tel',
                    'placeholder' => 'Phone',
                    'required' => '',
                    'autocomplete' => 'tel',
                    'aria-label' => 'Phone',
                    'aria-required' => 'true',
                    'style' => 'grid-area: phone'
                ],
                'zip_input' => [
                    'type' => 'text',
                    'placeholder' => 'Project Zip Code',
                    'required' => '',
                    'autocomplete' => 'postal-code',
                    'aria-label' => 'Project Zip Code',
                    'aria-required' => 'true',
                    'style' => 'grid-area: zip'
                ],
                'message_input' => [
                    'type' => 'textarea',
                    'cols' => '21',
                    'rows' => '5',
                    'placeholder' => 'Please describe your project and let us know if there is any urgency',
                    'required' => '',
                    'aria-label' => 'Message',
                    'aria-required' => 'true',
                    'style' => 'grid-area: message'
                ],
            ],
        ],
    ];

    $base = $defaults[$template] ?? [];

    $theme_dir   = rtrim( get_stylesheet_directory(), '/\\' ) . '/eform';
    $paths       = [
        $theme_dir . '/' . $template . '.json',
        $theme_dir . '/' . $template . '.yaml',
        $theme_dir . '/' . $template . '.yml',
    ];

    // Fallback to bundled templates when theme files are absent.
    $plugin_dir = rtrim( plugin_dir_path( __DIR__ ), '/\\' ) . '/templates';
    $paths      = array_merge(
        $paths,
        [
            $plugin_dir . '/' . $template . '.json',
            $plugin_dir . '/' . $template . '.yaml',
            $plugin_dir . '/' . $template . '.yml',
        ]
    );

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
        return $base;
    }

    $schema_file = __DIR__ . '/form-template-schema.json';
    if ( file_exists( $schema_file ) ) {
        $schema    = json_decode( file_get_contents( $schema_file ) );
        $validator = new Validator();
        $validator->validate( json_decode( json_encode( $data ) ), $schema );

        if ( ! $validator->isValid() ) {
            return $base;
        }
    }

    return array_replace_recursive( $base, $data );
}

