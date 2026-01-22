<?php
/**
 * Validate stage (authoritative; deterministic ordering).
 *
 * Educational note: this stage may reject. It collects all errors and does not
 * mutate values; Coerce is responsible for canonicalization after validation.
 *
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 * Spec: Cross-field rules (docs/Canonical_Spec.md#sec-cross-field-rules)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Errors.php';

class Validator {
    /**
     * Validate normalized values against the template context.
     *
     * @param array $context TemplateContext array.
     * @param array $normalized Result from NormalizerStage::normalize() (or the values map).
     * @return array{ok: bool, values: array, errors: Errors}
     */
    public static function validate( $context, $normalized ) {
        $errors = new Errors();

        $values = self::extract_values( $normalized );
        $context = is_array( $context ) ? $context : array();

        $fields = isset( $context['fields'] ) && is_array( $context['fields'] ) ? $context['fields'] : array();
        $descriptors = isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ? $context['descriptors'] : array();
        $rules = isset( $context['rules'] ) && is_array( $context['rules'] ) ? $context['rules'] : array();

        $field_defs = self::index_fields( $fields );
        $field_order = self::descriptor_order( $descriptors );

        $buckets = array();
        foreach ( $field_order as $field_key ) {
            $buckets[ $field_key ] = array(
                'struct' => array(),
                'required' => array(),
                'intrinsic' => array(),
                'cross' => array(),
            );
        }

        $presence = array();
        foreach ( $field_order as $field_key ) {
            $presence[ $field_key ] = self::is_present( array_key_exists( $field_key, $values ) ? $values[ $field_key ] : null );
        }

        $config = Config::get();
        $max_items = self::max_items_per_multivalue( $config );

        foreach ( $descriptors as $descriptor ) {
            if ( ! is_array( $descriptor ) ) {
                continue;
            }

            $key = isset( $descriptor['key'] ) && is_string( $descriptor['key'] ) ? $descriptor['key'] : '';
            if ( $key === '' || ! isset( $buckets[ $key ] ) ) {
                continue;
            }

            $field = isset( $field_defs[ $key ] ) ? $field_defs[ $key ] : array();
            $type = isset( $descriptor['type'] ) ? $descriptor['type'] : '';
            $is_multivalue = ! empty( $descriptor['is_multivalue'] );
            $value = array_key_exists( $key, $values ) ? $values[ $key ] : null;

            self::validate_struct( $buckets[ $key ]['struct'], $type, $is_multivalue, $value );
            if ( ! empty( $buckets[ $key ]['struct'] ) ) {
                continue;
            }

            if ( is_array( $field ) && ! empty( $field['required'] ) && ! $presence[ $key ] ) {
                $buckets[ $key ]['required'][] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_REQUIRED',
                    'message' => '',
                );
            }

            self::validate_intrinsic( $buckets[ $key ]['intrinsic'], $type, $is_multivalue, $value, $field, $max_items );
        }

        $global_entries = array();
        self::validate_rules( $rules, $values, $presence, $buckets, $global_entries );

        foreach ( $global_entries as $entry ) {
            $errors->add_global( $entry['code'], $entry['message'] );
        }

        foreach ( $field_order as $field_key ) {
            $field_bucket = $buckets[ $field_key ];
            foreach ( array( 'struct', 'required', 'intrinsic', 'cross' ) as $group ) {
                foreach ( $field_bucket[ $group ] as $entry ) {
                    $errors->add_field( $field_key, $entry['code'], $entry['message'] );
                }
            }
        }

        return array(
            'ok' => ! $errors->any(),
            'values' => $values,
            'errors' => $errors,
        );
    }

    private static function extract_values( $normalized ) {
        if ( is_array( $normalized ) && isset( $normalized['values'] ) && is_array( $normalized['values'] ) ) {
            return $normalized['values'];
        }

        return is_array( $normalized ) ? $normalized : array();
    }

    private static function index_fields( $fields ) {
        $out = array();

        if ( ! is_array( $fields ) ) {
            return $out;
        }

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            if ( isset( $field['type'] ) && $field['type'] === 'row_group' ) {
                continue;
            }

            $key = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
            if ( $key === '' ) {
                continue;
            }

            $out[ $key ] = $field;
        }

        return $out;
    }

    private static function descriptor_order( $descriptors ) {
        $order = array();

        if ( ! is_array( $descriptors ) ) {
            return $order;
        }

        foreach ( $descriptors as $descriptor ) {
            if ( ! is_array( $descriptor ) ) {
                continue;
            }

            $key = isset( $descriptor['key'] ) && is_string( $descriptor['key'] ) ? $descriptor['key'] : '';
            if ( $key === '' ) {
                continue;
            }

            $order[] = $key;
        }

        return $order;
    }

    private static function is_present( $value ) {
        if ( $value === null ) {
            return false;
        }

        if ( is_string( $value ) ) {
            return $value !== '';
        }

        if ( is_array( $value ) ) {
            return ! empty( $value );
        }

        return true;
    }

    private static function validate_struct( &$entries, $type, $is_multivalue, $value ) {
        if ( $value === null ) {
            return;
        }

        if ( $type === 'file' || $type === 'files' ) {
            // Deeper upload enforcement lands with the uploads subsystem tasks.
            return;
        }

        if ( $is_multivalue ) {
            if ( ! is_array( $value ) ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
                return;
            }

            foreach ( $value as $entry ) {
                if ( is_array( $entry ) ) {
                    $entries[] = array(
                        'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                        'message' => '',
                    );
                    return;
                }
            }

            return;
        }

        if ( is_array( $value ) ) {
            $entries[] = array(
                'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                'message' => '',
            );
        }
    }

    private static function validate_intrinsic( &$entries, $type, $is_multivalue, $value, $field, $max_items ) {
        if ( $value === null ) {
            return;
        }

        if ( $type === 'file' || $type === 'files' ) {
            // Deeper upload enforcement lands with the uploads subsystem tasks.
            return;
        }

        if ( $is_multivalue ) {
            if ( is_array( $value ) && $max_items > 0 && count( $value ) > $max_items ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }

        if ( $type === 'select' || $type === 'radio' || $type === 'checkbox' ) {
            self::validate_choice( $entries, $is_multivalue, $value, $field );
            return;
        }

        if ( $is_multivalue ) {
            if ( ! is_array( $value ) ) {
                return;
            }

            foreach ( $value as $entry ) {
                self::validate_text_like( $entries, $type, $entry, $field );
            }

            return;
        }

        self::validate_text_like( $entries, $type, $value, $field );
    }

    private static function validate_choice( &$entries, $is_multivalue, $value, $field ) {
        $options = array();
        if ( is_array( $field ) && isset( $field['options'] ) && is_array( $field['options'] ) ) {
            $options = $field['options'];
        }

        $allowed = array();
        $disabled = array();
        foreach ( $options as $opt ) {
            if ( ! is_array( $opt ) || ! isset( $opt['key'] ) || ! is_string( $opt['key'] ) ) {
                continue;
            }

            $key = $opt['key'];
            $allowed[ $key ] = true;

            if ( isset( $opt['disabled'] ) && $opt['disabled'] === true ) {
                $disabled[ $key ] = true;
            }
        }

        if ( $value === null ) {
            return;
        }

        if ( $is_multivalue ) {
            if ( ! is_array( $value ) ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
                return;
            }

            foreach ( $value as $entry ) {
                if ( ! is_string( $entry ) ) {
                    $entries[] = array(
                        'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                        'message' => '',
                    );
                    continue;
                }

                if ( $entry === '' ) {
                    continue;
                }

                if ( ! isset( $allowed[ $entry ] ) ) {
                    $entries[] = array(
                        'code' => 'EFORMS_ERR_SCHEMA_ENUM',
                        'message' => '',
                    );
                    continue;
                }

                if ( isset( $disabled[ $entry ] ) ) {
                    $entries[] = array(
                        'code' => 'EFORMS_ERR_SCHEMA_ENUM',
                        'message' => '',
                    );
                }
            }

            return;
        }

        if ( is_array( $value ) ) {
            $entries[] = array(
                'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                'message' => '',
            );
            return;
        }

        if ( ! is_string( $value ) ) {
            $entries[] = array(
                'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                'message' => '',
            );
            return;
        }

        if ( $value === '' ) {
            return;
        }

        if ( ! isset( $allowed[ $value ] ) ) {
            $entries[] = array(
                'code' => 'EFORMS_ERR_SCHEMA_ENUM',
                'message' => '',
            );
            return;
        }

        if ( isset( $disabled[ $value ] ) ) {
            $entries[] = array(
                'code' => 'EFORMS_ERR_SCHEMA_ENUM',
                'message' => '',
            );
        }
    }

    private static function validate_text_like( &$entries, $type, $value, $field ) {
        if ( $value === null ) {
            return;
        }

        if ( is_array( $value ) ) {
            $entries[] = array(
                'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                'message' => '',
            );
            return;
        }

        if ( ! is_string( $value ) ) {
            $entries[] = array(
                'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                'message' => '',
            );
            return;
        }

        if ( $value === '' ) {
            return;
        }

        if ( is_array( $field ) && isset( $field['max_length'] ) && is_int( $field['max_length'] ) ) {
            if ( self::string_length( $value ) > $field['max_length'] ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }

        if ( is_array( $field ) && isset( $field['pattern'] ) && is_string( $field['pattern'] ) && $field['pattern'] !== '' ) {
            $ok = self::pattern_match( $field['pattern'], $value );
            if ( ! $ok ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }

        if ( $type === 'email' ) {
            if ( function_exists( 'is_email' ) ) {
                if ( ! is_email( $value ) ) {
                    $entries[] = array(
                        'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                        'message' => '',
                    );
                }
            } elseif ( filter_var( $value, FILTER_VALIDATE_EMAIL ) === false ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }

        if ( $type === 'url' ) {
            if ( ! self::is_valid_url( $value ) ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }

        if ( $type === 'zip_us' ) {
            if ( ! preg_match( '/^\\d{5}$/', $value ) ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }

        if ( $type === 'tel_us' ) {
            if ( ! self::is_valid_tel_us( $value ) ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }

        if ( $type === 'number' || $type === 'range' ) {
            if ( ! self::is_numeric_string( $value ) ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            } else {
                self::validate_numeric_range( $entries, $value, $field );
            }
        }

        if ( $type === 'date' ) {
            if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }
    }

    private static function validate_numeric_range( &$entries, $value, $field ) {
        $number = (float) $value;

        if ( is_array( $field ) && isset( $field['min'] ) && is_int( $field['min'] ) ) {
            if ( $number < (float) $field['min'] ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }

        if ( is_array( $field ) && isset( $field['max'] ) && is_int( $field['max'] ) ) {
            if ( $number > (float) $field['max'] ) {
                $entries[] = array(
                    'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                    'message' => '',
                );
            }
        }
    }

    private static function validate_rules( $rules, $values, $presence, &$buckets, &$global_entries ) {
        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) || ! isset( $rule['rule'] ) || ! is_string( $rule['rule'] ) ) {
                continue;
            }

            $type = $rule['rule'];

            if ( $type === 'required_if' ) {
                $target = isset( $rule['target'] ) ? $rule['target'] : '';
                $field = isset( $rule['field'] ) ? $rule['field'] : '';
                $equals = isset( $rule['equals'] ) ? $rule['equals'] : '';

                if ( ! is_string( $target ) || ! is_string( $field ) || ! is_string( $equals ) ) {
                    continue;
                }

                if ( self::value_equals( $values, $field, $equals ) && self::target_missing( $presence, $target ) ) {
                    self::add_cross_required( $buckets, $target );
                }

                continue;
            }

            if ( $type === 'required_unless' ) {
                $target = isset( $rule['target'] ) ? $rule['target'] : '';
                $field = isset( $rule['field'] ) ? $rule['field'] : '';
                $equals = isset( $rule['equals'] ) ? $rule['equals'] : '';

                if ( ! is_string( $target ) || ! is_string( $field ) || ! is_string( $equals ) ) {
                    continue;
                }

                if ( ! self::value_equals( $values, $field, $equals ) && self::target_missing( $presence, $target ) ) {
                    self::add_cross_required( $buckets, $target );
                }

                continue;
            }

            if ( $type === 'required_if_any' ) {
                $target = isset( $rule['target'] ) ? $rule['target'] : '';
                $fields = isset( $rule['fields'] ) ? $rule['fields'] : array();
                $equals_any = isset( $rule['equals_any'] ) ? $rule['equals_any'] : array();

                if ( ! is_string( $target ) || ! is_array( $fields ) || ! is_array( $equals_any ) ) {
                    continue;
                }

                $trigger = false;
                foreach ( $fields as $field_key ) {
                    if ( ! is_string( $field_key ) ) {
                        continue;
                    }

                    foreach ( $equals_any as $candidate ) {
                        if ( ! is_string( $candidate ) ) {
                            continue;
                        }

                        if ( self::value_equals( $values, $field_key, $candidate ) ) {
                            $trigger = true;
                            break 2;
                        }
                    }
                }

                if ( $trigger && self::target_missing( $presence, $target ) ) {
                    self::add_cross_required( $buckets, $target );
                }

                continue;
            }

            if ( $type === 'matches' ) {
                $target = isset( $rule['target'] ) ? $rule['target'] : '';
                $field = isset( $rule['field'] ) ? $rule['field'] : '';

                if ( ! is_string( $target ) || ! is_string( $field ) ) {
                    continue;
                }

                if ( ! isset( $buckets[ $target ] ) ) {
                    continue;
                }

                $lhs = array_key_exists( $target, $values ) ? $values[ $target ] : null;
                $rhs = array_key_exists( $field, $values ) ? $values[ $field ] : null;

                if ( $lhs === null || $rhs === null ) {
                    continue;
                }

                if ( is_array( $lhs ) || is_array( $rhs ) ) {
                    $buckets[ $target ]['cross'][] = array(
                        'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                        'message' => '',
                    );
                    continue;
                }

                if ( (string) $lhs !== (string) $rhs ) {
                    $buckets[ $target ]['cross'][] = array(
                        'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                        'message' => '',
                    );
                }

                continue;
            }

            if ( $type === 'one_of' ) {
                $fields = isset( $rule['fields'] ) ? $rule['fields'] : array();
                if ( ! is_array( $fields ) ) {
                    continue;
                }

                $any = false;
                foreach ( $fields as $field_key ) {
                    if ( is_string( $field_key ) && isset( $presence[ $field_key ] ) && $presence[ $field_key ] ) {
                        $any = true;
                        break;
                    }
                }

                if ( ! $any ) {
                    $global_entries[] = array(
                        'code' => 'EFORMS_ERR_SCHEMA_REQUIRED',
                        'message' => '',
                    );
                }

                continue;
            }

            if ( $type === 'mutually_exclusive' ) {
                $fields = isset( $rule['fields'] ) ? $rule['fields'] : array();
                if ( ! is_array( $fields ) ) {
                    continue;
                }

                $count = 0;
                foreach ( $fields as $field_key ) {
                    if ( is_string( $field_key ) && isset( $presence[ $field_key ] ) && $presence[ $field_key ] ) {
                        $count += 1;
                    }
                }

                if ( $count > 1 ) {
                    $global_entries[] = array(
                        'code' => 'EFORMS_ERR_SCHEMA_TYPE',
                        'message' => '',
                    );
                }
            }
        }
    }

    private static function target_missing( $presence, $target ) {
        return is_string( $target ) && isset( $presence[ $target ] ) && ! $presence[ $target ];
    }

    private static function add_cross_required( &$buckets, $target ) {
        if ( ! is_string( $target ) || ! isset( $buckets[ $target ] ) ) {
            return;
        }

        $buckets[ $target ]['cross'][] = array(
            'code' => 'EFORMS_ERR_SCHEMA_REQUIRED',
            'message' => '',
        );
    }

    private static function value_equals( $values, $field, $equals ) {
        if ( ! is_string( $field ) || $field === '' ) {
            return false;
        }

        if ( ! array_key_exists( $field, $values ) ) {
            return false;
        }

        $value = $values[ $field ];
        if ( $value === null || is_array( $value ) ) {
            return false;
        }

        return (string) $value === (string) $equals;
    }

    private static function string_length( $value ) {
        if ( function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $value, 'UTF-8' );
        }

        return strlen( $value );
    }

    private static function pattern_match( $pattern, $value ) {
        $delim = '~';
        $escaped = str_replace( $delim, '\\' . $delim, $pattern );

        $result = @preg_match( $delim . $escaped . $delim . 'u', $value );
        return $result === 1;
    }

    private static function is_valid_url( $value ) {
        $url = filter_var( $value, FILTER_VALIDATE_URL );
        if ( $url === false ) {
            return false;
        }

        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'] ) ) {
            return false;
        }

        $scheme = strtolower( $parts['scheme'] );
        return $scheme === 'http' || $scheme === 'https';
    }

    private static function is_numeric_string( $value ) {
        if ( ! is_string( $value ) ) {
            return false;
        }

        $value = trim( $value );
        if ( $value === '' ) {
            return false;
        }

        return is_numeric( $value );
    }

    private static function is_valid_tel_us( $value ) {
        if ( ! is_string( $value ) ) {
            return false;
        }

        $digits = preg_replace( '/\\D+/', '', $value );
        if ( $digits === '' ) {
            return false;
        }

        if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
            $digits = substr( $digits, 1 );
        }

        return strlen( $digits ) === 10;
    }

    private static function max_items_per_multivalue( $config ) {
        if ( is_array( $config )
            && isset( $config['validation']['max_items_per_multivalue'] )
            && is_numeric( $config['validation']['max_items_per_multivalue'] )
        ) {
            return (int) $config['validation']['max_items_per_multivalue'];
        }

        return 0;
    }
}
