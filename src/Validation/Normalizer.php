<?php
/**
 * Normalize stage (pure + deterministic).
 *
 * Educational note: normalization is lossless; it shapes inputs for Validate
 * without rejecting or reordering values.
 *
 * Contract: Validation pipeline
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Uploads/UploadValue.php';

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
        $maxlen = Config::value( Config::get(), array( 'uploads', 'original_maxlen' ), 128 );
        return UploadValue::file_map_from_payload( $files, is_numeric( $maxlen ) ? (int) $maxlen : 128 );
    }

    private static function normalize_upload_value( $value, $is_multivalue ) {
        if ( $value === null ) {
            return null;
        }
        if ( ! is_array( $value ) ) {
            return $value;
        }

        $normalized = UploadValue::items_with_single( $value );
        $items = $normalized['items'];
        $single = $normalized['single'];
        $filtered = array();
        foreach ( $items as $entry ) {
            if ( ! UploadValue::is_item( $entry ) ) {
                $filtered[] = $entry;
                continue;
            }

            if ( UploadValue::is_no_file( $entry ) ) {
                continue;
            }

            $filtered[] = $entry;
        }

        if ( $is_multivalue ) {
            return empty( $filtered ) ? null : $filtered;
        }

        if ( ! $single ) {
            return $filtered;
        }

        return empty( $filtered ) ? null : $filtered[0];
    }
}
