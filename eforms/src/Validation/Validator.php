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
require_once __DIR__ . '/../Uploads/UploadPolicy.php';

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
        $upload_state = self::uploads_state( $config );
        $global_entries = array();

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

            self::validate_intrinsic( $buckets[ $key ]['intrinsic'], $type, $is_multivalue, $value, $field, $max_items, $upload_state, $global_entries );
        }

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
            self::validate_upload_struct( $entries, $type, $value );
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

    private static function validate_intrinsic( &$entries, $type, $is_multivalue, $value, $field, $max_items, &$upload_state, &$global_entries ) {
        if ( $value === null ) {
            return;
        }

        if ( $type === 'file' || $type === 'files' ) {
            self::validate_uploads( $entries, $type, $value, $field, $upload_state, $global_entries );
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

    private static function uploads_state( $config ) {
        $limit = PHP_INT_MAX;
        if ( is_array( $config )
            && isset( $config['uploads'] )
            && is_array( $config['uploads'] )
            && isset( $config['uploads']['total_request_bytes'] )
            && is_numeric( $config['uploads']['total_request_bytes'] )
        ) {
            $limit = (int) $config['uploads']['total_request_bytes'];
        }

        if ( $limit < 0 ) {
            $limit = 0;
        }

        return array(
            'total_limit' => $limit,
            'total_bytes' => 0,
            'total_exceeded' => false,
        );
    }

    private static function validate_upload_struct( &$entries, $type, $value ) {
        if ( $value === null ) {
            return;
        }

        if ( $type === 'file' ) {
            if ( ! self::is_upload_item( $value ) ) {
                $entries[] = self::upload_error_entry( 'File upload failed. Please try again.' );
            }
            return;
        }

        if ( ! is_array( $value ) ) {
            $entries[] = self::upload_error_entry( 'File upload failed. Please try again.' );
            return;
        }

        foreach ( $value as $entry ) {
            if ( ! self::is_upload_item( $entry ) ) {
                $entries[] = self::upload_error_entry( 'File upload failed. Please try again.' );
                return;
            }
        }
    }

    private static function validate_uploads( &$entries, $type, $value, $field, &$upload_state, &$global_entries ) {
        $items = array();
        if ( $type === 'file' ) {
            $items = self::is_upload_item( $value ) ? array( $value ) : array();
        } elseif ( is_array( $value ) ) {
            $items = $value;
        }

        if ( empty( $items ) ) {
            return;
        }

        $accept_defined = is_array( $field ) && array_key_exists( 'accept', $field );
        $accept_value = $accept_defined ? $field['accept'] : null;
        $tokens = UploadPolicy::resolve_tokens( $accept_value, ! $accept_defined );

        if ( empty( $tokens ) ) {
            $entries[] = array(
                'code' => 'EFORMS_ERR_ACCEPT_EMPTY',
                'message' => 'No allowed file types for this upload.',
            );
            return;
        }

        if ( ! UploadPolicy::finfo_available() ) {
            $global_entries[] = array(
                'code' => 'EFORMS_FINFO_UNAVAILABLE',
                'message' => 'File uploads are unsupported on this server.',
            );
            return;
        }

        $policy = UploadPolicy::policy_for_tokens( $tokens );
        $max_file_bytes = self::field_max_file_bytes( $field );

        if ( $type === 'files' ) {
            $max_files = self::field_max_files( $field );
            if ( $max_files > 0 && count( $items ) > $max_files ) {
                $entries[] = self::upload_error_entry( 'Too many files.' );
                return;
            }
        }

        foreach ( $items as $item ) {
            if ( ! self::is_upload_item( $item ) ) {
                $entries[] = self::upload_error_entry( 'File upload failed. Please try again.' );
                continue;
            }

            $error = isset( $item['error'] ) ? (int) $item['error'] : 0;
            if ( $error !== UPLOAD_ERR_OK ) {
                $entries[] = self::upload_error_entry( self::upload_error_message( $error ) );
                continue;
            }

            $size = isset( $item['size'] ) && is_numeric( $item['size'] ) ? (int) $item['size'] : 0;
            if ( $max_file_bytes > 0 && $size > $max_file_bytes ) {
                $entries[] = self::upload_error_entry( 'This file exceeds the size limit.' );
                continue;
            }

            if ( ! empty( $upload_state['total_exceeded'] ) ) {
                $entries[] = self::upload_error_entry( 'This file exceeds the size limit.' );
                continue;
            }

            $limit = isset( $upload_state['total_limit'] ) ? (int) $upload_state['total_limit'] : PHP_INT_MAX;
            $total = isset( $upload_state['total_bytes'] ) ? (int) $upload_state['total_bytes'] : 0;
            $total += $size;
            $upload_state['total_bytes'] = $total;
            if ( $limit > 0 && $total > $limit ) {
                $upload_state['total_exceeded'] = true;
                $entries[] = self::upload_error_entry( 'This file exceeds the size limit.' );
                continue;
            }

            $tmp_name = isset( $item['tmp_name'] ) && is_string( $item['tmp_name'] ) ? $item['tmp_name'] : '';
            if ( $tmp_name === '' || ! is_file( $tmp_name ) ) {
                $entries[] = self::upload_error_entry( 'File upload failed. Please try again.' );
                continue;
            }

            $name = '';
            if ( isset( $item['original_name_safe'] ) && is_string( $item['original_name_safe'] ) && $item['original_name_safe'] !== '' ) {
                $name = $item['original_name_safe'];
            } elseif ( isset( $item['original_name'] ) && is_string( $item['original_name'] ) ) {
                $name = $item['original_name'];
            }

            $extension = UploadPolicy::extension_from_name( $name );
            if ( $extension === '' ) {
                $entries[] = self::upload_error_entry( 'This file type isn\'t allowed.' );
                continue;
            }

            $mime = UploadPolicy::detect_mime( $tmp_name );
            if ( $mime === false ) {
                $entries[] = self::upload_error_entry( 'This file type isn\'t allowed.' );
                continue;
            }

            if ( ! UploadPolicy::mime_allowed( $mime, $extension, $policy ) ) {
                $entries[] = self::upload_error_entry( 'This file type isn\'t allowed.' );
                continue;
            }
        }
    }

    private static function is_upload_item( $value ) {
        if ( ! is_array( $value ) ) {
            return false;
        }

        return array_key_exists( 'tmp_name', $value )
            && array_key_exists( 'original_name', $value )
            && array_key_exists( 'size', $value )
            && array_key_exists( 'error', $value );
    }

    private static function field_max_file_bytes( $field ) {
        if ( is_array( $field ) && isset( $field['max_file_bytes'] ) && is_numeric( $field['max_file_bytes'] ) ) {
            $value = (int) $field['max_file_bytes'];
            return $value > 0 ? $value : 0;
        }

        return 0;
    }

    private static function field_max_files( $field ) {
        if ( is_array( $field ) && isset( $field['max_files'] ) && is_numeric( $field['max_files'] ) ) {
            $value = (int) $field['max_files'];
            return $value > 0 ? $value : 0;
        }

        return 0;
    }

    private static function upload_error_entry( $message ) {
        return array(
            'code' => 'EFORMS_ERR_UPLOAD_TYPE',
            'message' => $message,
        );
    }

    private static function upload_error_message( $error ) {
        if ( $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE ) {
            return 'This file exceeds the size limit.';
        }

        return 'File upload failed. Please try again.';
    }
}
