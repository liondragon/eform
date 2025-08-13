<?php
// includes/FieldRegistry.php

/**
 * Internal registry for field behavior callbacks.
 *
 * Stores mappings of field types to normalizers, validators, and renderers.
 * Provides helpers to register new behaviors internally.
 */
class FieldRegistry {
    /** @var array<string,bool> */
    private static array $field_types = [];
    /** @var array<string,mixed> */
    private static array $normalizers = [];
    /** @var array<string,mixed> */
    private static array $validators = [];
    /** @var array<string,mixed> */
    private static array $renderers = [];

    /**
     * Register callbacks for a field type.
     *
     * @internal
     */
    public static function register( string $type, $normalizer, $validator, $renderer ): void {
        self::$field_types[ $type ] = true;
        if ( $normalizer ) {
            self::$normalizers[ $type ] = $normalizer;
        }
        if ( $validator ) {
            self::$validators[ $type ] = $validator;
        }
        if ( $renderer ) {
            self::$renderers[ $type ] = $renderer;
        }
    }

    /** @internal */
    public static function register_normalizer( string $type, $callback ): void {
        self::$normalizers[ $type ] = $callback;
    }

    /** @internal */
    public static function register_validator( string $type, $callback ): void {
        self::$validators[ $type ] = $callback;
    }

    /** @internal */
    public static function register_renderer( string $type, $callback ): void {
        self::$renderers[ $type ] = $callback;
    }

    public static function get_normalizer( string $type ) {
        return self::$normalizers[ $type ] ?? self::$normalizers['text'];
    }

    public static function get_validator( string $type ) {
        return self::$validators[ $type ] ?? self::$validators['text'];
    }

    public static function get_renderer( string $type ) {
        return self::$renderers[ $type ] ?? self::$renderers['text'];
    }
}

// Register default field behaviors.
FieldRegistry::register( 'text', 'sanitize_text_field', ['Validator', 'validate_pattern'], 'input' );
FieldRegistry::register( 'email', 'sanitize_email', ['Validator', 'validate_email'], 'input' );
FieldRegistry::register( 'tel', ['Validator', 'sanitize_digits'], ['Validator', 'validate_phone'], 'input' );
FieldRegistry::register( 'number', ['Validator', 'sanitize_number'], ['Validator', 'validate_range'], 'input' );
FieldRegistry::register( 'radio', 'sanitize_text_field', ['Validator', 'validate_choice'], 'input' );
FieldRegistry::register( 'textarea', 'sanitize_textarea_field', ['Validator', 'validate_message'], 'textarea' );
FieldRegistry::register( 'checkbox', 'sanitize_text_field', ['Validator', 'validate_choices'], 'input' );
FieldRegistry::register( 'url', 'esc_url_raw', ['Validator', 'validate_url'], 'input' );
FieldRegistry::register( 'textarea_html', 'wp_kses_post', ['Validator', 'validate_message'], 'textarea' );
FieldRegistry::register( 'select', 'sanitize_text_field', ['Validator', 'validate_choice'], 'select' );
FieldRegistry::register( 'range', ['Validator', 'sanitize_number'], ['Validator', 'validate_range'], 'input' );
FieldRegistry::register( 'zip', ['Validator', 'sanitize_digits'], ['Validator', 'validate_zip'], 'input' );
