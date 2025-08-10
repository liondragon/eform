<?php
// includes/field-registry.php

class FieldRegistry {
    /**
     * Retrieve the base configuration for all available fields.
     *
     * @return array[]
     */
    public function get_field_map(): array {
        $fields = [
            'name'    => [
                'post_key'     => 'name_input',
                'required'     => true,
                'sanitize_cb'  => 'sanitize_text_field',
                'validate_cb'  => [self::class, 'validate_name'],
            ],
            'email'   => [
                'post_key'     => 'email_input',
                'required'     => true,
                'sanitize_cb'  => 'sanitize_email',
                'validate_cb'  => [self::class, 'validate_email'],
            ],
            'phone'   => [
                'post_key'     => 'tel_input',
                'required'     => true,
                'sanitize_cb'  => [self::class, 'sanitize_digits'],
                'validate_cb'  => [self::class, 'validate_phone'],
            ],
            'zip'     => [
                'post_key'     => 'zip_input',
                'required'     => true,
                'sanitize_cb'  => 'sanitize_text_field',
                'validate_cb'  => [self::class, 'validate_zip'],
            ],
            'message' => [
                'post_key'     => 'message_input',
                'required'     => true,
                'sanitize_cb'  => 'sanitize_textarea_field',
                'validate_cb'  => [self::class, 'validate_message'],
            ],
            // Generic field definitions allow templates to introduce custom
            // inputs without writing new PHP validation logic. These require
            // a "post_key" override when registered so the registry knows
            // which submitted value to map.
            'text_generic' => [
                'post_key'        => '',
                'required'        => false,
                'sanitize_cb'     => 'sanitize_text_field',
                'validate_cb'     => [self::class, 'validate_pattern'],
                'required_params' => ['post_key'],
            ],
            'number_generic' => [
                'post_key'        => '',
                'required'        => false,
                'sanitize_cb'     => [self::class, 'sanitize_number'],
                'validate_cb'     => [self::class, 'validate_range'],
                'required_params' => ['post_key'],
            ],
            'radio_generic' => [
                'post_key'        => '',
                'required'        => false,
                'sanitize_cb'     => 'sanitize_text_field',
                'validate_cb'     => [self::class, 'validate_choice'],
                // "choices" must be provided when registering this field so
                // validation can ensure the submitted value is allowed.
                'required_params' => ['post_key', 'choices'],
            ],
        ];

        if ( function_exists( 'apply_filters' ) ) {
            $fields = apply_filters( 'eform_field_map', $fields );
        }

        return $fields;
    }

    /**
     * Registered fields per template.
     *
     * @var array<string,array>
     */
    private $registered = [];

    /**
     * Register a field for a template.
     *
     * @param string $template Template slug.
     * @param string $field    Field key.
     * @param array  $args     Field overrides (e.g. ['required' => true]).
     */
    public function register_field( string $template, string $field, array $args = [] ): void {
        $field_map = $this->get_field_map();
        if ( ! isset( $field_map[ $field ] ) ) {
            return;
        }

        $base   = $field_map[ $field ];
        $params = $base['required_params'] ?? [];
        foreach ( $params as $param ) {
            $provided = array_key_exists( $param, $args ) || ! empty( $base[ $param ] );
            if ( ! $provided ) {
                $message = sprintf(
                    'Missing required parameter "%s" for field "%s" in template "%s"',
                    $param,
                    $field,
                    $template
                );
                trigger_error( $message, E_USER_WARNING );
                return;
            }
        }

        // Merge base configuration with any overrides.
        $config = array_merge( $base, $args );

        if ( isset( $config['required'] ) ) {
            $config['required'] = (bool) $config['required'];
        }

        unset( $config['required_params'] );

        // Ensure callbacks are valid before registering.
        foreach ( [ 'sanitize_cb', 'validate_cb' ] as $cb_key ) {
            if ( isset( $config[ $cb_key ] ) && ! is_callable( $config[ $cb_key ] ) ) {
                $message = sprintf(
                    'Invalid %s for field "%s" in template "%s"',
                    $cb_key,
                    $field,
                    $template
                );
                // Trigger a warning for invalid callbacks.
                trigger_error( $message, E_USER_WARNING );
                return;
            }
        }

        $this->registered[ $template ][ $field ] = $config;
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
        $fields = $this->registered[ $template ] ?? $this->get_field_map();

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
        if (strlen($value) < 3) {
            return 'Name too short.';
        }
        if (!preg_match("/^[\\p{L}\\s.'-]+$/u", $value)) {
            return 'Invalid characters in name.';
        }
        return '';
    }

    public static function validate_email(string $value, array $field): string {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'Invalid email.';
        }
        return '';
    }

    public static function validate_phone(string $value, array $field): string {
        if ($field['required'] && empty($value)) {
            return 'Phone is required.';
        }
        if (!preg_match('/^\d{10}$/', $value)) {
            return 'Invalid phone number.';
        }
        return '';
    }

    public static function validate_zip(string $value, array $field): string {
        if (!preg_match('/^\d{5}$/', $value)) {
            return 'Zip must be 5 digits.';
        }
        return '';
    }

    public static function validate_message(string $value, array $field): string {
        $plain = wp_strip_all_tags($value);
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
