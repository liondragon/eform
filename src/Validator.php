<?php
// includes/Validator.php
require_once __DIR__ . '/Normalizer.php';
require_once __DIR__ . '/FieldRegistry.php';

class Validator {

    /**
     * Normalize raw submission data.
     *
     * @param array $field_map        Field rules keyed by logical field key.
     * @param array $submitted_data   Raw submitted values keyed by logical field key.
     * @param array $array_field_types Field types that accept array values.
     * @return array{data:array,invalid_fields:array}
     */
    public function normalize_submission( array $field_map, array $submitted_data, array $array_field_types = [] ): array {
        if ( empty( $array_field_types ) ) {
            $array_field_types = FieldRegistry::get_multivalue_types();
        }
        $data           = [];
        $invalid_fields = [];
        foreach ( $field_map as $field => $details ) {
            $value    = $submitted_data[ $field ] ?? '';
            $type     = $details['type'] ?? 'text';
            $is_array = is_array( $value );
            if ( $is_array && ! in_array( $type, $array_field_types, true ) ) {
                $invalid_fields[] = $field;
                continue;
            }
            if ( $is_array ) {
                $data[ $field ] = array_map( [ ValueNormalizer::class, 'normalize' ], $value );
            } else {
                $data[ $field ] = ValueNormalizer::normalize( (string) $value );
            }
        }
        return [ 'data' => $data, 'invalid_fields' => $invalid_fields ];
    }

    /**
     * Validate normalized data using callbacks registered for the field type.
     *
     * @param array $field_map        Field rules keyed by logical field key.
     * @param array $normalized_data  Normalized values keyed by logical field key.
     * @param array $array_field_types Field types that accept array values.
     * @return array{data:array,errors:array}
     */
    public function validate_submission( array $field_map, array $normalized_data, array $array_field_types = [] ): array {
        if ( empty( $array_field_types ) ) {
            $array_field_types = FieldRegistry::get_multivalue_types();
        }
        $sanitized = [];
        foreach ( $field_map as $field => $details ) {
            $value       = $normalized_data[ $field ] ?? '';
            $type        = $details['type'] ?? 'text';
            $sanitize_cb = FieldRegistry::get_normalizer( $type );
            if ( is_array( $value ) ) {
                if ( is_array( $sanitize_cb ) || is_string( $sanitize_cb ) ) {
                    $sanitized[ $field ] = array_map( $sanitize_cb, $value );
                } else {
                    $sanitized[ $field ] = $value;
                }
            } else {
                $sanitized[ $field ] = $sanitize_cb( $value );
            }
        }

        $errors = [];
        foreach ( $field_map as $field => $details ) {
            $type        = $details['type'] ?? 'text';
            $validate_cb = FieldRegistry::get_validator( $type );
            $value       = $sanitized[ $field ] ?? '';

            $required = ! empty( $details['required'] );
            if ( ! empty( $details['required_if'] ) && is_array( $details['required_if'] ) ) {
                $other    = $details['required_if'][0] ?? '';
                $expected = $details['required_if'][1] ?? '';
                $other_val = $sanitized[ $other ] ?? '';
                if ( is_array( $expected ) ) {
                    if ( is_array( $other_val ) ) {
                        $required = $required || (bool) array_intersect( $expected, $other_val );
                    } else {
                        $required = $required || in_array( $other_val, $expected, true );
                    }
                } else {
                    if ( is_array( $other_val ) ) {
                        $required = $required || in_array( $expected, $other_val, true );
                    } else {
                        $required = $required || ( $other_val === $expected );
                    }
                }
            }
            if ( ! empty( $details['required_with'] ) ) {
                foreach ( (array) $details['required_with'] as $other ) {
                    $other_val = $sanitized[ $other ] ?? '';
                    if ( $other_val !== '' && ( ! is_array( $other_val ) || ! empty( $other_val ) ) ) {
                        $required = true;
                        break;
                    }
                }
            }
            if ( ! empty( $details['required_without'] ) ) {
                $all_empty = true;
                foreach ( (array) $details['required_without'] as $other ) {
                    $other_val = $sanitized[ $other ] ?? '';
                    if ( $other_val !== '' && ( ! is_array( $other_val ) || ! empty( $other_val ) ) ) {
                        $all_empty = false;
                        break;
                    }
                }
                if ( $all_empty ) {
                    $required = true;
                }
            }

            if ( $required ) {
                $details['required'] = true;
            } else {
                unset( $details['required'] );
            }

            $max_length = $details['max_length'] ?? FieldRegistry::get_max_length( $type );
            if ( $max_length && ! is_array( $value ) && mb_strlen( (string) $value ) > $max_length ) {
                $errors[ $field ] = 'Value too long.';
                continue;
            }
            if ( $max_length && is_array( $value ) ) {
                $too_long = false;
                foreach ( $value as $v ) {
                    if ( mb_strlen( (string) $v ) > $max_length ) {
                        $too_long = true;
                        break;
                    }
                }
                if ( $too_long ) {
                    $errors[ $field ] = 'Value too long.';
                    continue;
                }
            }

            $error = $validate_cb( $value, $details );
            if ( ! $error && ! empty( $details['matches'] ) ) {
                $other_val = $sanitized[ $details['matches'] ] ?? '';
                if ( $value !== $other_val ) {
                    $error = 'Values do not match.';
                }
            }
            if ( $error ) {
                $errors[ $field ] = $error;
            }
        }
        return [ 'data' => $sanitized, 'errors' => $errors ];
    }

    /**
     * Coerce sanitized values to canonical representations.
     *
     * @param array $field_map      Field rules keyed by logical field key.
     * @param array $sanitized_data Sanitized values keyed by logical field key.
     * @return array Canonical values keyed by logical field key.
     */
    public function coerce_submission( array $field_map, array $sanitized_data ): array {
        $data = [];
        foreach ( $field_map as $field => $details ) {
            $type  = $details['type'] ?? 'text';
            $value = $sanitized_data[ $field ] ?? ( $type === 'checkbox' ? [] : '' );
            if ( $type === 'number' ) {
                if ( $value === '' || ! is_numeric( $value ) ) {
                    $data[ $field ] = $value;
                } elseif ( strpos( $value, '.' ) !== false ) {
                    $data[ $field ] = (float) $value;
                } else {
                    $data[ $field ] = (int) $value;
                }
            } else {
                $data[ $field ] = $value;
            }
        }
        return $data;
    }

    /**
     * Run normalization, validation, and coercion in sequence.
     *
     * @param array $field_map        Field rules keyed by logical field key.
     * @param array $submitted_data   Raw submitted values keyed by logical field key.
     * @param array $array_field_types Field types that accept array values.
     * @return array{data:array,errors:array,invalid_fields:array}
     */
    public function process_submission( array $field_map, array $submitted_data, array $array_field_types = [] ): array {
        $normalized = $this->normalize_submission( $field_map, $submitted_data, $array_field_types );
        $validated  = $this->validate_submission( $field_map, $normalized['data'], $array_field_types );
        $coerced    = $this->coerce_submission( $field_map, $validated['data'] );
        return [
            'data'           => $coerced,
            'errors'         => $validated['errors'],
            'invalid_fields' => $normalized['invalid_fields'],
        ];
    }

    /** Sanitize to keep digits only. */
    public static function sanitize_digits( string $value ): string {
        $digits = preg_replace( '/\D+/', '', $value );
        if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
            $digits = substr( $digits, 1 );
        }
        return $digits;
    }

    /** Sanitize a number allowing fractions. */
    public static function sanitize_number( string $value ): string {
        $number = filter_var( $value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );
        return $number === null ? '' : $number;
    }

    public static function validate_name( string $value, array $field ): string {
        if ( $value === '' ) {
            return ! empty( $field['required'] ) ? 'Name is required.' : '';
        }
        if ( strlen( $value ) < 3 ) {
            return 'Name too short.';
        }
        if ( ! preg_match( "/^[\\p{L}\\s.'-]+$/u", $value ) ) {
            return 'Invalid characters in name.';
        }
        return '';
    }

    public static function validate_email( string $value, array $field ): string {
        if ( $value === '' ) {
            return ! empty( $field['required'] ) ? 'Email is required.' : '';
        }
        if ( ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
            return 'Invalid email.';
        }
        return '';
    }

    public static function validate_phone( string $value, array $field ): string {
        if ( $value === '' ) {
            return ! empty( $field['required'] ) ? 'Phone is required.' : '';
        }
        if ( ! preg_match( '/^\d{10}$/', $value ) ) {
            return 'Invalid phone number.';
        }
        return '';
    }

    public static function validate_url( string $value, array $field ): string {
        if ( $value === '' ) {
            return ! empty( $field['required'] ) ? 'URL is required.' : '';
        }
        if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
            return 'Invalid URL.';
        }
        return '';
    }

    public static function validate_zip( string $value, array $field ): string {
        if ( $value === '' ) {
            return ! empty( $field['required'] ) ? 'Zip is required.' : '';
        }
        if ( ! preg_match( '/^\d{5}$/', $value ) ) {
            return 'Zip must be 5 digits.';
        }
        return '';
    }

    public static function validate_message( string $value, array $field ): string {
        $plain = wp_strip_all_tags( $value );
        if ( $plain === '' ) {
            return ! empty( $field['required'] ) ? 'Message is required.' : '';
        }
        if ( strlen( $plain ) < 20 ) {
            return 'Message too short.';
        }
        return '';
    }

    /** Validate a field against an optional regex pattern. */
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

    /** Validate a numeric field against optional min/max bounds. */
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

    /** Validate a selection against a list of allowed choices. */
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

    /** Validate multiple selections against a list of allowed choices. */
    public static function validate_choices( array $values, array $field ): string {
        if ( ! empty( $field['required'] ) && empty( $values ) ) {
            return 'At least one selection is required.';
        }
        if ( empty( $values ) ) {
            return '';
        }
        $choices = $field['choices'] ?? [];
        foreach ( $values as $value ) {
            if ( ! in_array( $value, $choices, true ) ) {
                return 'Invalid selection.';
            }
        }
        return '';
    }

    /** Validate uploaded files. */
    public static function validate_file( array $files, array $field ): string {
        if ( ! empty( $field['required'] ) && empty( $files ) ) {
            return 'File is required.';
        }
        return '';
    }
}
