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
}
