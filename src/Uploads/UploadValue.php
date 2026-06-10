<?php
/**
 * Pure helpers for normalized upload value shape.
 *
 * Spec: Uploads (docs/Canonical_Spec.md#sec-uploads)
 */

class UploadValue {
    public static function is_item( $value ) {
        if ( ! is_array( $value ) ) {
            return false;
        }

        return array_key_exists( 'tmp_name', $value )
            && array_key_exists( 'original_name', $value )
            && array_key_exists( 'size', $value )
            && array_key_exists( 'error', $value );
    }

    public static function is_normalized_item( $value ) {
        return self::is_item( $value ) && array_key_exists( 'original_name_safe', $value );
    }

    public static function items( $value, $require_safe_name = false ) {
        $is_item = $require_safe_name ? self::is_normalized_item( $value ) : self::is_item( $value );
        if ( $is_item ) {
            return array( $value );
        }

        if ( ! is_array( $value ) ) {
            return array();
        }

        $items = array();
        foreach ( $value as $entry ) {
            $is_entry = $require_safe_name ? self::is_normalized_item( $entry ) : self::is_item( $entry );
            if ( $is_entry ) {
                $items[] = $entry;
            }
        }

        return $items;
    }

    public static function items_with_single( $value ) {
        if ( $value === null ) {
            return array( 'items' => array(), 'single' => false );
        }

        if ( self::is_item( $value ) ) {
            return array( 'items' => array( $value ), 'single' => true );
        }

        if ( is_array( $value ) ) {
            return array( 'items' => $value, 'single' => false );
        }

        return array( 'items' => array(), 'single' => false );
    }

    public static function file_map_from_payload( $files, $maxlen = 128, $include_type = false ) {
        if ( ! is_array( $files ) ) {
            return array();
        }

        $files = self::extract_files_payload( $files );
        if ( ! self::is_files_payload( $files ) || ! is_array( $files['name'] ) ) {
            return array();
        }

        $out = array();
        foreach ( $files['name'] as $field_key => $name ) {
            $tmp = self::array_value( $files['tmp_name'], $field_key );
            $err = self::array_value( $files['error'], $field_key );
            $size = self::array_value( $files['size'], $field_key );
            $type = isset( $files['type'] ) ? self::array_value( $files['type'], $field_key ) : null;

            if ( is_array( $name ) ) {
                $out[ $field_key ] = self::file_list_from_parts( $name, $tmp, $err, $size, $type, $maxlen, $include_type );
                continue;
            }

            $out[ $field_key ] = self::file_item_from_parts( $name, $tmp, $err, $size, $type, $maxlen, $include_type );
        }

        return $out;
    }

    public static function is_no_file( $item ) {
        if ( ! self::is_item( $item ) ) {
            return false;
        }

        $error = isset( $item['error'] ) ? (int) $item['error'] : 0;
        $name = isset( $item['original_name'] ) && is_string( $item['original_name'] ) ? $item['original_name'] : '';

        if ( $error === UPLOAD_ERR_NO_FILE ) {
            return true;
        }

        return $name === '';
    }

    public static function name_for_validation( $item ) {
        if ( is_array( $item ) && isset( $item['original_name_safe'] ) && is_string( $item['original_name_safe'] ) && $item['original_name_safe'] !== '' ) {
            return $item['original_name_safe'];
        }

        return self::original_name( $item );
    }

    public static function name_for_storage( $item ) {
        if ( is_array( $item ) && isset( $item['original_name_safe'] ) && is_string( $item['original_name_safe'] ) ) {
            return $item['original_name_safe'];
        }

        return self::original_name( $item );
    }

    public static function display_name( $item, $fallback_path = '' ) {
        if ( is_array( $item ) && isset( $item['original_name_safe'] ) && is_string( $item['original_name_safe'] ) ) {
            $name = trim( $item['original_name_safe'] );
            if ( $name !== '' ) {
                return $name;
            }
        }

        $name = self::original_name( $item );
        $name = is_string( $name ) ? trim( $name ) : '';
        if ( $name !== '' ) {
            return $name;
        }

        $base = basename( (string) $fallback_path );
        return is_string( $base ) ? $base : '';
    }

    public static function stored_path( $item ) {
        if ( ! is_array( $item ) || ! isset( $item['stored'] ) || ! is_array( $item['stored'] ) ) {
            return '';
        }

        $path = isset( $item['stored']['path'] ) && is_string( $item['stored']['path'] ) ? $item['stored']['path'] : '';
        return trim( $path );
    }

    public static function stored_bytes( $item ) {
        if ( is_array( $item ) && isset( $item['stored'] ) && is_array( $item['stored'] ) && isset( $item['stored']['bytes'] ) && is_numeric( $item['stored']['bytes'] ) ) {
            $bytes = (int) $item['stored']['bytes'];
            return $bytes > 0 ? $bytes : 0;
        }

        return null;
    }

    public static function original_name( $item ) {
        if ( is_array( $item ) && isset( $item['original_name'] ) && is_string( $item['original_name'] ) ) {
            return $item['original_name'];
        }

        return '';
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

    private static function file_list_from_parts( $names, $tmp_names, $errors, $sizes, $types, $maxlen, $include_type ) {
        $items = array();
        foreach ( $names as $index => $name ) {
            $tmp = self::array_value( $tmp_names, $index );
            $err = self::array_value( $errors, $index );
            $size = self::array_value( $sizes, $index );
            $type = self::array_value( $types, $index );

            if ( is_array( $name ) ) {
                $items = array_merge( $items, self::file_list_from_parts( $name, $tmp, $err, $size, $type, $maxlen, $include_type ) );
                continue;
            }

            $items[] = self::file_item_from_parts( $name, $tmp, $err, $size, $type, $maxlen, $include_type );
        }

        return $items;
    }

    private static function file_item_from_parts( $name, $tmp_name, $error, $size, $type, $maxlen, $include_type ) {
        $original = is_string( $name ) ? $name : '';
        $item = array(
            'tmp_name' => is_string( $tmp_name ) ? $tmp_name : '',
            'original_name' => $original,
            'size' => is_numeric( $size ) ? (int) $size : 0,
            'error' => is_numeric( $error ) ? (int) $error : 0,
            'original_name_safe' => self::sanitize_original_name( $original, $maxlen ),
        );

        if ( $include_type ) {
            $item['type'] = is_string( $type ) ? $type : '';
        }

        return $item;
    }

    private static function array_value( $value, $key ) {
        return is_array( $value ) && array_key_exists( $key, $value ) ? $value[ $key ] : null;
    }

    private static function sanitize_original_name( $name, $maxlen ) {
        $value = str_replace( '\\', '/', is_string( $name ) ? $name : '' );
        $value = basename( $value );
        if ( class_exists( 'Helpers' ) && method_exists( 'Helpers', 'nfc' ) ) {
            $value = Helpers::nfc( $value );
        }
        $value = preg_replace( '/[\\x00-\\x1F\\x7F]/u', '', $value );
        $value = preg_replace( '/\\s+/u', ' ', $value );
        $value = preg_replace( '/\\.+/u', '.', $value );
        $value = trim( $value, " \t\n\r\0\x0B." );
        $value = self::truncate_unicode( $value, $maxlen );

        if ( $value === '' ) {
            $ext = pathinfo( is_string( $name ) ? $name : '', PATHINFO_EXTENSION );
            $value = $ext !== '' ? 'file.' . $ext : 'file';
            $value = self::truncate_unicode( $value, $maxlen );
        }

        return $value;
    }

    private static function truncate_unicode( $value, $maxlen ) {
        if ( ! is_string( $value ) || ! is_numeric( $maxlen ) || (int) $maxlen <= 0 ) {
            return '';
        }

        $maxlen = (int) $maxlen;
        if ( function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $value, 'UTF-8' ) <= $maxlen ) {
                return $value;
            }
            return mb_substr( $value, 0, $maxlen, 'UTF-8' );
        }

        return strlen( $value ) <= $maxlen ? $value : substr( $value, 0, $maxlen );
    }
}
