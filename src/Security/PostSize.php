<?php
/**
 * POST size cap calculation helper.
 *
 * Contract: POST size cap
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

    /**
     * Resolve request Content-Length for POST-size gates.
     *
     * Explicit request metadata wins over ambient PHP globals. When an object or
     * array request exposes headers but no Content-Length, the request owns that
     * absence and globals are not consulted.
     *
     * @param mixed $request Request object, request array, or null for globals.
     * @return int|null Non-negative length, or null when unavailable.
     */
    public static function content_length( $request = null ) {
        if ( is_array( $request ) && array_key_exists( 'content_length', $request ) && is_numeric( $request['content_length'] ) ) {
            return max( 0, (int) $request['content_length'] );
        }

        $header = self::request_header( $request, 'Content-Length' );
        if ( $header['owned'] ) {
            return $header['value'] !== '' && is_numeric( $header['value'] )
                ? max( 0, (int) $header['value'] )
                : null;
        }

        return self::server_content_length();
    }

    public static function request_exceeds_cap( $request, $content_type, $config = null ) {
        $length = self::content_length( $request );
        if ( $length === null ) {
            return false;
        }

        return $length > self::effective_cap( $content_type, $config );
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

    private static function request_header( $request, $name ) {
        if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
            $value = $request->get_header( $name );
            return array(
                'owned' => true,
                'value' => is_string( $value ) || is_numeric( $value ) ? trim( (string) $value ) : '',
            );
        }

        if ( is_array( $request ) && isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
            foreach ( $request['headers'] as $key => $value ) {
                if ( is_string( $key ) && strcasecmp( $key, $name ) === 0 && ( is_string( $value ) || is_numeric( $value ) ) ) {
                    return array( 'owned' => true, 'value' => trim( (string) $value ) );
                }
            }
            return array( 'owned' => true, 'value' => '' );
        }

        return array( 'owned' => false, 'value' => '' );
    }

    private static function server_content_length() {
        if ( isset( $_SERVER['CONTENT_LENGTH'] ) && is_numeric( $_SERVER['CONTENT_LENGTH'] ) ) {
            return max( 0, (int) $_SERVER['CONTENT_LENGTH'] );
        }

        if ( isset( $_SERVER['HTTP_CONTENT_LENGTH'] ) && is_numeric( $_SERVER['HTTP_CONTENT_LENGTH'] ) ) {
            return max( 0, (int) $_SERVER['HTTP_CONTENT_LENGTH'] );
        }

        return null;
    }

    private static function config_int( $config, $path, $default ) {
        $value = Config::value( $config, $path );
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }

        return $default;
    }

    private static function config_bool( $config, $path, $default ) {
        return Config::bool( $config, $path, $default );
    }
}
