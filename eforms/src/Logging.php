<?php
/**
 * Minimal logging with per-request correlation id.
 *
 * Educational note: this file only implements the minimal sink; JSONL and
 * fail2ban outputs are added in later phases.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

require_once __DIR__ . '/Config.php';

class Logging {
    const REQUEST_ID_MAX_BYTES = 128;

    private static $request_id = null;

    /**
     * Emit a logging event when minimal logging is enabled.
     *
     * @param string $severity error|warning|info
     * @param string $code Stable error/event code.
     * @param array $meta Structured metadata for the event.
     * @param mixed $request Optional request object/array for header resolution.
     */
    public static function event( $severity, $code, $meta = array(), $request = null ) {
        $config = self::config_snapshot();
        if ( ! self::should_log( $config, $severity ) ) {
            return;
        }

        $mode = self::config_value( $config, array( 'logging', 'mode' ), 'off' );
        if ( $mode !== 'minimal' ) {
            return;
        }

        if ( ! is_array( $meta ) ) {
            $meta = array();
        }

        $meta['request_id'] = self::resolve_request_id_from_meta( $meta, $request );

        $line = self::format_minimal_line( $severity, $code, $meta );
        error_log( $line );
    }

    /**
     * Resolve or generate the request correlation id.
     *
     * @param mixed $request Optional request object/array.
     * @return string
     */
    public static function request_id( $request = null ) {
        if ( is_string( self::$request_id ) && self::$request_id !== '' ) {
            return self::$request_id;
        }

        $filtered = self::apply_request_id_filter( '', $request );
        $filtered = self::sanitize_request_id( $filtered );
        if ( $filtered !== '' ) {
            self::$request_id = $filtered;
            return $filtered;
        }

        $header_id = self::resolve_header_request_id( $request );
        if ( $header_id !== '' ) {
            self::$request_id = $header_id;
            return $header_id;
        }

        $generated = self::sanitize_request_id( self::generate_uuid_v4() );
        if ( $generated === '' ) {
            $generated = self::fallback_request_id();
        }

        self::$request_id = $generated;
        return $generated;
    }

    /**
     * Test helper to reset request-id cache.
     */
    public static function reset_for_tests() {
        self::$request_id = null;
    }

    private static function config_snapshot() {
        $config = Config::get();
        if ( is_array( $config ) ) {
            return $config;
        }

        $defaults = Config::defaults();
        return is_array( $defaults ) ? $defaults : array();
    }

    private static function should_log( $config, $severity ) {
        $mode = self::config_value( $config, array( 'logging', 'mode' ), 'off' );
        if ( $mode === 'off' ) {
            return false;
        }

        $level = self::config_value( $config, array( 'logging', 'level' ), 0 );
        $level = is_numeric( $level ) ? (int) $level : 0;

        $severity = self::normalize_severity( $severity );
        $severity_level = self::severity_level( $severity );

        return $severity_level <= $level;
    }

    private static function normalize_severity( $severity ) {
        if ( ! is_string( $severity ) || $severity === '' ) {
            return 'error';
        }

        $severity = strtolower( $severity );
        if ( in_array( $severity, array( 'error', 'warning', 'info' ), true ) ) {
            return $severity;
        }

        return 'error';
    }

    private static function severity_level( $severity ) {
        if ( $severity === 'warning' ) {
            return 1;
        }
        if ( $severity === 'info' ) {
            return 2;
        }

        return 0;
    }

    private static function resolve_request_id_from_meta( $meta, $request ) {
        if ( is_array( $meta ) && isset( $meta['request_id'] ) && is_string( $meta['request_id'] ) ) {
            $sanitized = self::sanitize_request_id( $meta['request_id'] );
            if ( $sanitized !== '' ) {
                return $sanitized;
            }
        }

        return self::request_id( $request );
    }

    private static function apply_request_id_filter( $candidate, $request ) {
        if ( ! function_exists( 'apply_filters' ) ) {
            return '';
        }

        $filtered = apply_filters( 'eforms_request_id', $candidate, $request );
        if ( is_string( $filtered ) ) {
            return $filtered;
        }

        return '';
    }

    private static function resolve_header_request_id( $request ) {
        $headers = array(
            'X-Eforms-Request-Id',
            'X-Request-Id',
            'X-Correlation-Id',
        );

        foreach ( $headers as $name ) {
            $value = self::header_value( $request, $name );
            $value = self::sanitize_request_id( $value );
            if ( $value !== '' ) {
                return $value;
            }
        }

        return '';
    }

    private static function header_value( $request, $name ) {
        if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
            $value = $request->get_header( $name );
            if ( is_string( $value ) ) {
                return $value;
            }
        }

        if ( is_array( $request ) && isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
            foreach ( $request['headers'] as $key => $value ) {
                if ( is_string( $key ) && strcasecmp( $key, $name ) === 0 && is_string( $value ) ) {
                    return $value;
                }
            }
        }

        $server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        if ( isset( $_SERVER[ $server_key ] ) && is_string( $_SERVER[ $server_key ] ) ) {
            return $_SERVER[ $server_key ];
        }

        return '';
    }

    private static function sanitize_request_id( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = trim( $value );
        $value = preg_replace( '/[\\x00-\\x1F\\x7F]/', ' ', $value );
        $value = preg_replace( '/\\s+/', ' ', $value );
        $value = trim( $value );

        if ( $value === '' ) {
            return '';
        }

        if ( ! self::is_ascii_printable( $value ) ) {
            return '';
        }

        if ( strlen( $value ) > self::REQUEST_ID_MAX_BYTES ) {
            $value = substr( $value, 0, self::REQUEST_ID_MAX_BYTES );
            $value = rtrim( $value );
        }

        return $value;
    }

    private static function is_ascii_printable( $value ) {
        return is_string( $value ) && preg_match( '/^[\\x20-\\x7E]+$/', $value ) === 1;
    }

    private static function generate_uuid_v4() {
        $bytes = '';
        if ( function_exists( 'random_bytes' ) ) {
            $bytes = random_bytes( 16 );
        } elseif ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
            $bytes = openssl_random_pseudo_bytes( 16 );
        }

        if ( ! is_string( $bytes ) || strlen( $bytes ) !== 16 ) {
            $bytes = '';
            for ( $i = 0; $i < 16; $i++ ) {
                $bytes .= chr( mt_rand( 0, 255 ) );
            }
        }

        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

        $hex = bin2hex( $bytes );
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr( $hex, 0, 8 ),
            substr( $hex, 8, 4 ),
            substr( $hex, 12, 4 ),
            substr( $hex, 16, 4 ),
            substr( $hex, 20, 12 )
        );
    }

    private static function fallback_request_id() {
        return 'eforms-request';
    }

    private static function format_minimal_line( $severity, $code, $meta ) {
        $severity = self::normalize_severity( $severity );
        $code     = is_string( $code ) ? $code : '';

        $form_id       = self::line_field( $meta, 'form_id' );
        $submission_id = self::line_field( $meta, 'submission_id' );
        $uri           = self::line_field( $meta, 'uri' );
        $message       = self::line_field( $meta, 'message' );

        // Until privacy/IP handling is implemented, minimal logs omit IP details.
        $ip = 'none';

        $encoded_meta = self::encode_json( $meta );

        return sprintf(
            'eforms severity=%s code=%s form=%s subm=%s ip=%s uri="%s" msg="%s" meta=%s',
            $severity,
            $code,
            $form_id,
            $submission_id,
            $ip,
            $uri,
            $message,
            $encoded_meta
        );
    }

    private static function line_field( $meta, $key ) {
        if ( ! is_array( $meta ) || ! isset( $meta[ $key ] ) ) {
            return '';
        }

        $value = $meta[ $key ];
        if ( is_scalar( $value ) ) {
            return self::escape_line_value( (string) $value );
        }

        return '';
    }

    private static function escape_line_value( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = preg_replace( '/[\\x00-\\x1F\\x7F]/', '', $value );
        $value = str_replace( '"', '\\"', $value );
        return $value;
    }

    private static function encode_json( $value ) {
        if ( function_exists( 'wp_json_encode' ) ) {
            $encoded = wp_json_encode( $value );
        } else {
            $encoded = json_encode( $value );
        }

        return is_string( $encoded ) ? $encoded : '{}';
    }

    private static function config_value( $config, $path, $fallback ) {
        $current = $config;

        foreach ( $path as $key ) {
            if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
                return $fallback;
            }
            $current = $current[ $key ];
        }

        return $current;
    }
}
