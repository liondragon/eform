<?php
// includes/field-registry.php

class FieldRegistry {
    /**
     * Field configuration per template.
     *
     * @var array[]
     */
    private const FIELDS = [
        'default' => [
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
        ],
    ];

    /**
     * Retrieve field configuration for a template.
     */
    public function get_fields(string $template): array {
        return self::FIELDS[$template] ?? self::FIELDS['default'];
    }

    /**
     * Sanitize to keep digits only.
     */
    public static function sanitize_digits(string $value): string {
        return preg_replace('/\D/', '', $value);
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
}
