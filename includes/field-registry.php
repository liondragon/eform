<?php
// includes/field-registry.php

class FieldRegistry {

    /**
     * Map field types from configuration to sanitize/validate callbacks.
     *
     * @var array<string,array{sanitize_cb:callable,validate_cb:callable}>
     */
    private $type_map = [
        'text'     => [
            'sanitize_cb' => 'sanitize_text_field',
            'validate_cb' => [self::class, 'validate_pattern'],
        ],
        'email'    => [
            'sanitize_cb' => 'sanitize_email',
            'validate_cb' => [self::class, 'validate_email'],
        ],
        'tel'      => [
            'sanitize_cb' => [self::class, 'sanitize_digits'],
            'validate_cb' => [self::class, 'validate_phone'],
        ],
        'number'   => [
            'sanitize_cb' => [self::class, 'sanitize_number'],
            'validate_cb' => [self::class, 'validate_range'],
        ],
        'radio'    => [
            'sanitize_cb' => 'sanitize_text_field',
            'validate_cb' => [self::class, 'validate_choice'],
        ],
        'textarea' => [
            'sanitize_cb' => 'sanitize_textarea_field',
            'validate_cb' => [self::class, 'validate_message'],
        ],
    ];

    /**
     * Registered fields per template.
     *
     * @var array<string,array>
     */
    private $registered = [];
    /**
     * Register a field using configuration data.
     *
     * The configuration array should include the original `post_key`, `type`,
     * and optionally `required`, `pattern`, `min`, `max`, or `choices`.
     */
    public function register_field_from_config( string $template, string $field, array $config ): void {
        $type      = $config['type'] ?? 'text';
        $callbacks = $this->type_map[ $type ] ?? $this->type_map['text'];

        $field_config = [
            'post_key'    => $config['post_key'] ?? $field,
            'required'    => (bool) ( $config['required'] ?? false ),
            'sanitize_cb' => $callbacks['sanitize_cb'],
            'validate_cb' => $callbacks['validate_cb'],
        ];

        foreach ( [ 'pattern', 'min', 'max', 'choices' ] as $param ) {
            if ( isset( $config[ $param ] ) ) {
                $field_config[ $param ] = $config[ $param ];
            }
        }

        $this->registered[ $template ][ $field ] = $field_config;
    }

    /**
     * Derive the logical field key from a posted field name.
     */
    public static function field_key_from_post( string $post_key ): string {
        $key = sanitize_key( preg_replace( '/_input$/', '', $post_key ) );
        if ( 'tel' === $key ) {
            return 'phone';
        }
        return $key;
    }

    /**
     * Retrieve field configuration for a template.
     */
    public function get_fields( string $template ): array {
        return $this->get_template_map( $template );
    }

    /**
     * Retrieve the field map for a given template.
     */
    public function get_template_map( string $template ): array {
        $fields = $this->registered[ $template ] ?? [];

        if ( function_exists( 'apply_filters' ) ) {
            $fields = apply_filters( 'eform_template_map', $fields, $template );
        }

        return $fields;
    }

    /**
     * Sanitize to keep digits only.
     */
    public static function sanitize_digits(string $value): string {
        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    public static function validate_name(string $value, array $field): string {
        if ($value === '') {
            return ! empty($field['required']) ? 'Name is required.' : '';
        }
        if (strlen($value) < 3) {
            return 'Name too short.';
        }
        if (!preg_match("/^[\\p{L}\\s.'-]+$/u", $value)) {
            return 'Invalid characters in name.';
        }
        return '';
    }

    public static function validate_email(string $value, array $field): string {
        if ($value === '') {
            return ! empty($field['required']) ? 'Email is required.' : '';
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'Invalid email.';
        }
        return '';
    }

    public static function validate_phone(string $value, array $field): string {
        if ($value === '') {
            return ! empty($field['required']) ? 'Phone is required.' : '';
        }
        if (!preg_match('/^\d{10}$/', $value)) {
            return 'Invalid phone number.';
        }
        return '';
    }

    public static function validate_zip(string $value, array $field): string {
        if ($value === '') {
            return ! empty($field['required']) ? 'Zip is required.' : '';
        }
        if (!preg_match('/^\d{5}$/', $value)) {
            return 'Zip must be 5 digits.';
        }
        return '';
    }

    public static function validate_message(string $value, array $field): string {
        $plain = wp_strip_all_tags($value);
        if ($plain === '') {
            return ! empty($field['required']) ? 'Message is required.' : '';
        }
        if (strlen($plain) < 20) {
            return 'Message too short.';
        }
        return '';
    }

    /**
     * Sanitize a number allowing fractions.
     */
    public static function sanitize_number( string $value ): string {
        $number = filter_var( $value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );
        return $number === null ? '' : $number;
    }

    /**
     * Validate a field against an optional regex pattern.
     */
    public static function validate_pattern( string $value, array $field ): string {
        if ( ! empty( $field['required'] ) && $value === '' ) {
            return 'This field is required.';
        }
        if ( $value === '' ) {
            return '';
        }
        if ( ! empty( $field['pattern'] ) && ! preg_match( '/' . $field['pattern'] . '/u', $value ) ) {
            return 'Invalid format.';
        }
        return '';
    }

    /**
     * Validate a numeric field against optional min/max bounds.
     */
    public static function validate_range( string $value, array $field ): string {
        if ( ! empty( $field['required'] ) && $value === '' ) {
            return 'This field is required.';
        }
        if ( $value === '' ) {
            return '';
        }
        if ( ! is_numeric( $value ) ) {
            return 'Invalid number.';
        }
        $number = $value + 0;
        if ( isset( $field['min'] ) && $number < $field['min'] ) {
            return 'Value must be at least ' . $field['min'] . '.';
        }
        if ( isset( $field['max'] ) && $number > $field['max'] ) {
            return 'Value must be at most ' . $field['max'] . '.';
        }
        return '';
    }

    /**
     * Validate a selection against a list of allowed choices.
     */
    public static function validate_choice( string $value, array $field ): string {
        if ( ! empty( $field['required'] ) && $value === '' ) {
            return 'This field is required.';
        }
        if ( $value === '' ) {
            return '';
        }
        $choices = $field['choices'] ?? [];
        if ( ! in_array( $value, $choices, true ) ) {
            return 'Invalid selection.';
        }
        return '';
    }
}
