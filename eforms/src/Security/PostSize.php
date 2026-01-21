<?php
/**
 * POST size cap calculation helper.
 *
 * Spec: POST size cap (docs/Canonical_Spec.md#sec-post-size-cap)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Helpers.php';

class PostSize {
    const CT_MULTIPART = 'multipart/form-data';

    /**
     * Compute the effective POST size cap from config + PHP INI limits.
     *
     * @param string|null $content_type Request Content-Type header value.
     * @param array|null $config Optional frozen config snapshot.
     * @param mixed $ini_post Optional override for post_max_size (numeric or ini string).
     * @param mixed $ini_upload Optional override for upload_max_filesize (numeric or ini string).
     * @return int Byte cap for the request body.
     */
    public static function effective_cap( $content_type, $config = null, $ini_post = null, $ini_upload = null ) {
        $config = is_array( $config ) ? $config : Config::get();

        $app_cap = self::config_int( $config, array( 'security', 'max_post_bytes' ), PHP_INT_MAX );
        if ( $app_cap < 0 ) {
            $app_cap = 0;
        }

        $ini_post_cap = self::ini_cap( $ini_post, 'post_max_size' );
        $caps = array( $app_cap, $ini_post_cap );

        $uploads_enabled = self::config_bool( $config, array( 'uploads', 'enable' ), false );
        $is_multipart = self::is_multipart( $content_type );

        // Educational note: upload INI caps apply only to multipart requests when uploads are enabled.
        if ( $uploads_enabled && $is_multipart ) {
            $ini_upload_cap = self::ini_cap( $ini_upload, 'upload_max_filesize' );
            $caps[] = $ini_upload_cap;
        }

        return self::min_cap( $caps );
    }

    private static function is_multipart( $content_type ) {
        if ( ! is_string( $content_type ) ) {
            return false;
        }

        $content_type = trim( $content_type );
        if ( $content_type === '' ) {
            return false;
        }

        $content_type = strtolower( $content_type );
        $semi = strpos( $content_type, ';' );
        if ( $semi !== false ) {
            $content_type = trim( substr( $content_type, 0, $semi ) );
        }

        return $content_type === self::CT_MULTIPART;
    }

    private static function ini_cap( $override, $ini_key ) {
        if ( $override !== null ) {
            if ( is_numeric( $override ) ) {
                return (int) $override;
            }
            return Helpers::bytes_from_ini( $override );
        }

        return Helpers::bytes_from_ini( ini_get( $ini_key ) );
    }

    private static function min_cap( $caps ) {
        $min = null;

        foreach ( $caps as $cap ) {
            $value = is_numeric( $cap ) ? (int) $cap : PHP_INT_MAX;
            if ( $min === null || $value < $min ) {
                $min = $value;
            }
        }

        return $min === null ? 0 : $min;
    }

    private static function config_int( $config, $path, $default ) {
        $value = self::config_value( $config, $path );
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }

        return $default;
    }

    private static function config_bool( $config, $path, $default ) {
        $value = self::config_value( $config, $path );
        if ( is_bool( $value ) ) {
            return $value;
        }

        return $default;
    }

    private static function config_value( $config, $path ) {
        if ( ! is_array( $path ) ) {
            return null;
        }

        $cursor = $config;
        foreach ( $path as $segment ) {
            if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
                return null;
            }
            $cursor = $cursor[ $segment ];
        }

        return $cursor;
    }
}
