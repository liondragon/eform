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
    /** @var array<string,array<string,mixed>> */
    private static array $traits = [];

    /**
     * Register callbacks for a field type.
     *
     * @internal
     */
    public static function register( string $type, $normalizer, $validator, $renderer, array $traits = [] ): void {
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
        self::$traits[ $type ] = $traits;
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

    public static function get_traits( string $type ): array {
        return self::$traits[ $type ] ?? [];
    }

    public static function is_multivalue( string $type ): bool {
        return ! empty( self::$traits[ $type ]['is_multivalue'] );
    }

    public static function get_max_length( string $type ): ?int {
        return self::$traits[ $type ]['max_length'] ?? null;
    }

    public static function get_option_mode( string $type ): string {
        return self::$traits[ $type ]['options'] ?? 'none';
    }

    /**
     * Return all field types that accept multiple values.
     *
     * @return array<int,string>
     */
    public static function get_multivalue_types(): array {
        return array_keys( array_filter( self::$traits, static function( $t ) {
            return ! empty( $t['is_multivalue'] );
        } ) );
    }
}

// Register default field behaviors and traits.
FieldRegistry::register( 'text', 'sanitize_text_field', ['Validator', 'validate_pattern'], 'input', [ 'max_length' => 200 ] );
FieldRegistry::register( 'email', 'sanitize_email', ['Validator', 'validate_email'], 'input', [ 'max_length' => 254 ] );
FieldRegistry::register( 'tel', ['Validator', 'sanitize_digits'], ['Validator', 'validate_phone'], 'input', [ 'max_length' => 10 ] );
FieldRegistry::register( 'name', 'sanitize_text_field', ['Validator', 'validate_pattern'], 'input', [ 'max_length' => 100 ] );
FieldRegistry::register( 'number', ['Validator', 'sanitize_number'], ['Validator', 'validate_range'], 'input' );
FieldRegistry::register( 'radio', 'sanitize_text_field', ['Validator', 'validate_choice'], 'input', [ 'options' => 'choices' ] );
FieldRegistry::register( 'textarea', 'sanitize_textarea_field', ['Validator', 'validate_message'], 'textarea', [ 'max_length' => 10000 ] );
FieldRegistry::register( 'checkbox', 'sanitize_text_field', ['Validator', 'validate_choices'], 'input', [ 'is_multivalue' => true, 'options' => 'choices' ] );
FieldRegistry::register( 'url', 'esc_url_raw', ['Validator', 'validate_url'], 'input', [ 'max_length' => 2000 ] );
FieldRegistry::register( 'textarea_html', 'wp_kses_post', ['Validator', 'validate_message'], 'textarea', [ 'max_length' => 10000 ] );
FieldRegistry::register( 'select', 'sanitize_text_field', ['Validator', 'validate_choice'], 'select', [ 'options' => 'select' ] );
FieldRegistry::register( 'range', ['Validator', 'sanitize_number'], ['Validator', 'validate_range'], 'input' );
FieldRegistry::register( 'zip', ['Validator', 'sanitize_digits'], ['Validator', 'validate_zip'], 'input', [ 'max_length' => 5 ] );
FieldRegistry::register( 'message', 'sanitize_textarea_field', ['Validator', 'validate_message'], 'textarea', [ 'max_length' => 10000 ] );
FieldRegistry::register( 'file', null, ['Validator', 'validate_file'], 'input', [ 'is_multivalue' => true ] );
