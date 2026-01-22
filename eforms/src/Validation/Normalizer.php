<?php
/**
 * Normalize stage (pure + deterministic).
 *
 * Educational note: normalization is lossless; it shapes inputs for Validate
 * without rejecting or reordering values.
 *
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Helpers.php';

class NormalizerStage {
    /**
     * Normalize POST + FILES data for a TemplateContext.
     *
     * @param array $context TemplateContext array with descriptors.
     * @param array $post POST payload (form-scoped).
     * @param array $files FILES payload (form-scoped).
     * @return array{values: array}
     */
    public static function normalize( $context, $post, $files ) {
        $post = is_array( $post ) ? $post : array();
        $files = is_array( $files ) ? $files : array();

        $descriptors = array();
        if ( is_array( $context ) && isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ) {
            $descriptors = $context['descriptors'];
        }

        $file_map = self::flatten_files( $files );
        $values = array();

        foreach ( $descriptors as $descriptor ) {
            if ( ! is_array( $descriptor ) ) {
                continue;
            }

            $key = isset( $descriptor['key'] ) && is_string( $descriptor['key'] ) ? $descriptor['key'] : '';
            if ( $key === '' ) {
                continue;
            }

            $type = isset( $descriptor['type'] ) ? $descriptor['type'] : '';
            $is_multivalue = ! empty( $descriptor['is_multivalue'] );

            if ( $type === 'file' || $type === 'files' ) {
                $raw = array_key_exists( $key, $file_map ) ? $file_map[ $key ] : null;
                $values[ $key ] = self::normalize_upload_value( $raw, $is_multivalue );
                continue;
            }

            $raw = array_key_exists( $key, $post ) ? $post[ $key ] : null;
            $values[ $key ] = self::normalize_value( $raw, $descriptor, $is_multivalue );
        }

        return array(
            'values' => $values,
        );
    }

    private static function normalize_value( $value, $descriptor, $is_multivalue ) {
        $value = self::unslash_deep( $value );

        $handler = null;
        if ( is_array( $descriptor )
            && isset( $descriptor['handlers']['n'] )
            && is_callable( $descriptor['handlers']['n'] )
        ) {
            $handler = $descriptor['handlers']['n'];
        }

        if ( is_array( $value ) ) {
            $normalized = array();
            foreach ( $value as $entry ) {
                $normalized[] = self::normalize_scalar( $entry );
            }

            if ( $is_multivalue ) {
                $filtered = array();
                foreach ( $normalized as $entry ) {
                    if ( $entry === '' || $entry === null ) {
                        continue;
                    }
                    $filtered[] = self::apply_handler( $handler, $entry, $descriptor );
                }
                return empty( $filtered ) ? null : $filtered;
            }

            $out = array();
            foreach ( $normalized as $entry ) {
                $out[] = self::apply_handler( $handler, $entry, $descriptor );
            }
            return $out;
        }

        if ( $value === null ) {
            return null;
        }

        $normalized = self::normalize_scalar( $value );

        if ( $is_multivalue ) {
            if ( $normalized === '' || $normalized === null ) {
                return null;
            }

            return array( self::apply_handler( $handler, $normalized, $descriptor ) );
        }

        return self::apply_handler( $handler, $normalized, $descriptor );
    }

    private static function normalize_scalar( $value ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        $value = self::normalize_line_endings( $value );
        $value = trim( $value );
        $value = Helpers::nfc( $value );

        return $value;
    }

    private static function apply_handler( $handler, $value, $descriptor ) {
        if ( $handler === null ) {
            return $value;
        }

        return call_user_func( $handler, $value, $descriptor );
    }

    private static function normalize_line_endings( $value ) {
        return str_replace( array( "\r\n", "\r" ), "\n", $value );
    }

    private static function unslash_deep( $value ) {
        if ( function_exists( 'wp_unslash' ) ) {
            return wp_unslash( $value );
        }

        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $entry ) {
                $out[ $key ] = self::unslash_deep( $entry );
            }
            return $out;
        }

        if ( is_string( $value ) ) {
            return stripslashes( $value );
        }

        return $value;
    }

    private static function flatten_files( $files ) {
        if ( ! is_array( $files ) ) {
            return array();
        }

        $files = self::extract_files_payload( $files );

        if ( ! self::is_files_payload( $files ) ) {
            return array();
        }

        $names = isset( $files['name'] ) ? $files['name'] : array();
        $tmp_names = isset( $files['tmp_name'] ) ? $files['tmp_name'] : array();
        $errors = isset( $files['error'] ) ? $files['error'] : array();
        $sizes = isset( $files['size'] ) ? $files['size'] : array();

        if ( ! is_array( $names ) ) {
            return array();
        }

        $maxlen = self::uploads_original_maxlen();
        $out = array();

        foreach ( $names as $field_key => $name ) {
            $tmp = is_array( $tmp_names ) && array_key_exists( $field_key, $tmp_names ) ? $tmp_names[ $field_key ] : null;
            $err = is_array( $errors ) && array_key_exists( $field_key, $errors ) ? $errors[ $field_key ] : null;
            $size = is_array( $sizes ) && array_key_exists( $field_key, $sizes ) ? $sizes[ $field_key ] : null;

            if ( is_array( $name ) ) {
                $out[ $field_key ] = self::flatten_file_list( $name, $tmp, $err, $size, $maxlen );
                continue;
            }

            $out[ $field_key ] = self::build_file_item( $name, $tmp, $err, $size, $maxlen );
        }

        return $out;
    }

    private static function normalize_upload_value( $value, $is_multivalue ) {
        if ( $value === null ) {
            return null;
        }

        $was_array = is_array( $value ) && ! self::is_file_item( $value );
        $items = array();

        if ( self::is_file_item( $value ) ) {
            $items = array( $value );
        } elseif ( is_array( $value ) ) {
            $items = $value;
        } else {
            return $value;
        }

        $filtered = array();
        foreach ( $items as $entry ) {
            if ( ! self::is_file_item( $entry ) ) {
                $filtered[] = $entry;
                continue;
            }

            if ( self::is_no_file( $entry ) ) {
                continue;
            }

            $filtered[] = $entry;
        }

        if ( $is_multivalue ) {
            return empty( $filtered ) ? null : $filtered;
        }

        if ( $was_array ) {
            return $filtered;
        }

        return empty( $filtered ) ? null : $filtered[0];
    }

    private static function flatten_file_list( $names, $tmp_names, $errors, $sizes, $maxlen ) {
        $items = array();

        if ( ! is_array( $names ) ) {
            return $items;
        }

        foreach ( $names as $index => $name ) {
            $tmp = is_array( $tmp_names ) && array_key_exists( $index, $tmp_names ) ? $tmp_names[ $index ] : null;
            $err = is_array( $errors ) && array_key_exists( $index, $errors ) ? $errors[ $index ] : null;
            $size = is_array( $sizes ) && array_key_exists( $index, $sizes ) ? $sizes[ $index ] : null;

            if ( is_array( $name ) ) {
                $items = array_merge( $items, self::flatten_file_list( $name, $tmp, $err, $size, $maxlen ) );
                continue;
            }

            $items[] = self::build_file_item( $name, $tmp, $err, $size, $maxlen );
        }

        return $items;
    }

    private static function build_file_item( $name, $tmp_name, $error, $size, $maxlen ) {
        $original = is_string( $name ) ? $name : '';

        return array(
            'tmp_name' => is_string( $tmp_name ) ? $tmp_name : '',
            'original_name' => $original,
            'size' => is_numeric( $size ) ? (int) $size : 0,
            'error' => is_numeric( $error ) ? (int) $error : 0,
            'original_name_safe' => self::sanitize_original_name( $original, $maxlen ),
        );
    }

    private static function sanitize_original_name( $name, $maxlen ) {
        if ( ! is_string( $name ) ) {
            $name = '';
        }

        $value = str_replace( '\\', '/', $name );
        $value = basename( $value );
        $value = Helpers::nfc( $value );
        $value = preg_replace( '/[\\x00-\\x1F\\x7F]/u', '', $value );
        $value = preg_replace( '/\\s+/u', ' ', $value );
        $value = preg_replace( '/\\.+/u', '.', $value );
        $value = trim( $value, " \t\n\r\0\x0B." );
        $value = self::truncate_unicode( $value, $maxlen );

        if ( $value === '' ) {
            $ext = pathinfo( $name, PATHINFO_EXTENSION );
            $value = $ext !== '' ? 'file.' . $ext : 'file';
            $value = self::truncate_unicode( $value, $maxlen );
        }

        return $value;
    }

    private static function truncate_unicode( $value, $maxlen ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        if ( ! is_numeric( $maxlen ) || (int) $maxlen <= 0 ) {
            return '';
        }

        $maxlen = (int) $maxlen;

        if ( function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $value, 'UTF-8' ) <= $maxlen ) {
                return $value;
            }
            return mb_substr( $value, 0, $maxlen, 'UTF-8' );
        }

        if ( strlen( $value ) <= $maxlen ) {
            return $value;
        }

        return substr( $value, 0, $maxlen );
    }

    private static function uploads_original_maxlen() {
        $config = Config::get();

        if ( isset( $config['uploads']['original_maxlen'] ) && is_numeric( $config['uploads']['original_maxlen'] ) ) {
            return (int) $config['uploads']['original_maxlen'];
        }

        return 128;
    }

    private static function is_file_item( $value ) {
        if ( ! is_array( $value ) ) {
            return false;
        }

        return array_key_exists( 'tmp_name', $value )
            && array_key_exists( 'original_name', $value )
            && array_key_exists( 'size', $value )
            && array_key_exists( 'error', $value );
    }

    private static function is_no_file( $item ) {
        if ( ! self::is_file_item( $item ) ) {
            return false;
        }

        $error = isset( $item['error'] ) ? (int) $item['error'] : 0;
        $name = isset( $item['original_name'] ) && is_string( $item['original_name'] ) ? $item['original_name'] : '';

        if ( $error === UPLOAD_ERR_NO_FILE ) {
            return true;
        }

        return $name === '';
    }

    private static function extract_files_payload( $files ) {
        if ( self::is_files_payload( $files ) ) {
            return $files;
        }

        if ( count( $files ) === 1 ) {
            $candidate = reset( $files );
            if ( is_array( $candidate ) && self::is_files_payload( $candidate ) ) {
                return $candidate;
            }
        }

        return $files;
    }

    private static function is_files_payload( $files ) {
        return is_array( $files )
            && array_key_exists( 'name', $files )
            && array_key_exists( 'tmp_name', $files )
            && array_key_exists( 'error', $files )
            && array_key_exists( 'size', $files );
    }
}
