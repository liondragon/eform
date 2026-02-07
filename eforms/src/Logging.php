<?php
/**
 * Logging front-controller with minimal/jsonl/fail2ban sinks.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Privacy/ClientIp.php';
require_once __DIR__ . '/Logging/JsonlLogger.php';
require_once __DIR__ . '/Logging/Fail2banLogger.php';

class Logging {
    const REQUEST_ID_MAX_BYTES = 128;

    private static $request_id = null;
    private static $desc_sha1 = '';

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
        $mode = self::config_value( $config, array( 'logging', 'mode' ), 'off' );

        if ( ! is_array( $meta ) ) {
            $meta = array();
        }

        $meta['request_id'] = self::resolve_request_id_from_meta( $meta, $request );
        $meta = self::inject_runtime_meta( $meta, $request, $config );

        $raw_ip = self::resolve_raw_ip( $meta, $request, $config );
        Fail2banLogger::emit( is_string( $code ) ? $code : '', $meta, $raw_ip, $config );

        if ( ! self::should_log( $config, $severity ) ) {
            return;
        }

        $ip = self::resolve_logging_ip( $raw_ip, $config, $mode );
        if ( $mode === 'minimal' ) {
            $line = self::format_minimal_line( $severity, $code, $meta, $ip );
            error_log( $line );
            return;
        }

        if ( $mode === 'jsonl' ) {
            $payload = self::jsonl_payload( $severity, $code, $meta, $request, $ip, $config );
            JsonlLogger::write_event( $payload, $config );
        }
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
        self::$desc_sha1 = '';
        if ( class_exists( 'JsonlLogger' ) && method_exists( 'JsonlLogger', 'reset_for_tests' ) ) {
            JsonlLogger::reset_for_tests();
        }
        if ( class_exists( 'Fail2banLogger' ) && method_exists( 'Fail2banLogger', 'reset_for_tests' ) ) {
            Fail2banLogger::reset_for_tests();
        }
    }

    /**
     * Register resolved descriptors for level-2 desc_sha1 emission.
     *
     * @param mixed $descriptors
     * @return void
     */
    public static function remember_descriptors( $descriptors ) {
        $hash = self::fingerprint_descriptors( $descriptors );
        if ( $hash !== '' ) {
            self::$desc_sha1 = $hash;
        }
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

    private static function format_minimal_line( $severity, $code, $meta, $ip ) {
        $severity = self::normalize_severity( $severity );
        $code     = is_string( $code ) ? $code : '';

        $form_id       = self::line_field( $meta, 'form_id' );
        $submission_id = self::line_field( $meta, 'submission_id' );
        $uri           = self::line_field( $meta, 'uri' );
        $message       = self::line_field( $meta, 'message' );

        $ip = is_string( $ip ) && $ip !== '' ? $ip : 'none';

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

    private static function jsonl_payload( $severity, $code, $meta, $request, $ip, $config ) {
        $payload = array(
            'ts' => gmdate( 'c' ),
            'severity' => self::normalize_severity( $severity ),
            'code' => is_string( $code ) ? $code : '',
            'request_id' => isset( $meta['request_id'] ) && is_string( $meta['request_id'] ) ? $meta['request_id'] : self::request_id( $request ),
            'form_id' => self::line_field( $meta, 'form_id' ),
            'submission_id' => self::line_field( $meta, 'submission_id' ),
            'uri' => self::line_field( $meta, 'uri' ),
            'ip' => is_string( $ip ) ? $ip : '',
            'msg' => self::line_field( $meta, 'message' ),
            'meta' => self::sanitize_meta_for_jsonl( $meta, $config ),
        );

        if ( isset( $meta['desc_sha1'] ) && is_string( $meta['desc_sha1'] ) && $meta['desc_sha1'] !== '' ) {
            $payload['desc_sha1'] = $meta['desc_sha1'];
        }

        if ( isset( $meta['origin_state'] ) && is_scalar( $meta['origin_state'] ) ) {
            $payload['origin_state'] = (string) $meta['origin_state'];
        }
        if ( isset( $meta['throttle_state'] ) && is_scalar( $meta['throttle_state'] ) ) {
            $payload['throttle_state'] = (string) $meta['throttle_state'];
        }
        if ( isset( $meta['soft_reasons'] ) && is_array( $meta['soft_reasons'] ) ) {
            $payload['soft_reasons'] = array_values( array_filter( $meta['soft_reasons'], 'is_string' ) );
        }

        return $payload;
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

    private static function inject_runtime_meta( $meta, $request, $config ) {
        if ( ! isset( $meta['uri'] ) || ! is_string( $meta['uri'] ) || trim( $meta['uri'] ) === '' ) {
            $meta['uri'] = self::resolve_filtered_uri( $request );
        } else {
            $meta['uri'] = self::filter_uri( $meta['uri'] );
        }

        $desc_sha1 = self::resolve_desc_sha1( $meta, $config );
        if ( $desc_sha1 !== '' ) {
            $meta['desc_sha1'] = $desc_sha1;
        }

        if ( self::config_bool( $config, array( 'logging', 'headers' ), false ) ) {
            $ua = self::header_value( $request, 'User-Agent' );
            if ( is_string( $ua ) && trim( $ua ) !== '' ) {
                $meta['ua'] = self::normalize_header_text( $ua );
            }

            $origin = self::normalize_origin( self::header_value( $request, 'Origin' ) );
            if ( $origin !== '' ) {
                $meta['origin'] = $origin;
            }
        }

        return $meta;
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

    private static function resolve_raw_ip( $meta, $request, $config ) {
        $raw_ip = self::meta_raw_ip( $meta );
        if ( $raw_ip !== '' ) {
            return $raw_ip;
        }

        return ClientIp::resolve( $request, $config );
    }

    private static function resolve_logging_ip( $raw_ip, $config, $mode ) {
        if ( ! is_string( $raw_ip ) || $raw_ip === '' ) {
            return '';
        }

        $mode = is_string( $mode ) ? $mode : '';
        if ( $mode === 'minimal' ) {
            return ClientIp::present_for_logging( $raw_ip, $config, 'minimal' );
        }

        $allow_pii = self::config_bool( $config, array( 'logging', 'pii' ), false );
        if ( ! $allow_pii ) {
            return ClientIp::present_for_logging( $raw_ip, $config, 'minimal' );
        }

        return ClientIp::present( $raw_ip, $config );
    }

    private static function meta_raw_ip( $meta ) {
        if ( ! is_array( $meta ) ) {
            return '';
        }

        $candidates = array( 'client_ip_resolved', 'client_ip', 'ip_raw' );
        foreach ( $candidates as $key ) {
            if ( isset( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
                $value = trim( $meta[ $key ] );
                if ( $value !== '' ) {
                    return $value;
                }
            }
        }

        return '';
    }

    private static function resolve_filtered_uri( $request ) {
        $raw = '';
        if ( is_array( $request ) && isset( $request['uri'] ) && is_string( $request['uri'] ) ) {
            $raw = $request['uri'];
        } elseif ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
            $raw = $_SERVER['REQUEST_URI'];
        }

        return self::filter_uri( $raw );
    }

    private static function filter_uri( $raw_uri ) {
        if ( ! is_string( $raw_uri ) || trim( $raw_uri ) === '' ) {
            return '';
        }

        $raw_uri = trim( $raw_uri );
        $path = parse_url( $raw_uri, PHP_URL_PATH );
        $query = parse_url( $raw_uri, PHP_URL_QUERY );
        if ( ! is_string( $path ) ) {
            $path = '';
        }

        $filtered_query = array();
        if ( is_string( $query ) && $query !== '' ) {
            parse_str( $query, $params );
            if ( is_array( $params ) ) {
                ksort( $params );
                foreach ( $params as $key => $value ) {
                    if ( ! is_string( $key ) || strncmp( $key, 'eforms_', 7 ) !== 0 ) {
                        continue;
                    }
                    if ( is_array( $value ) ) {
                        continue;
                    }
                    $filtered_query[ $key ] = is_scalar( $value ) ? (string) $value : '';
                }
            }
        }

        if ( empty( $filtered_query ) ) {
            return $path;
        }

        return $path . '?' . http_build_query( $filtered_query, '', '&', PHP_QUERY_RFC3986 );
    }

    private static function normalize_origin( $origin ) {
        if ( ! is_string( $origin ) ) {
            return '';
        }

        $origin = trim( $origin );
        if ( $origin === '' ) {
            return '';
        }

        $scheme = parse_url( $origin, PHP_URL_SCHEME );
        $host = parse_url( $origin, PHP_URL_HOST );
        if ( ! is_string( $scheme ) || ! is_string( $host ) ) {
            return '';
        }

        return strtolower( $scheme ) . '://' . strtolower( $host );
    }

    private static function normalize_header_text( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = trim( $value );
        $value = preg_replace( '/[\\x00-\\x1F\\x7F]/', ' ', $value );
        $value = preg_replace( '/\\s+/', ' ', $value );
        return is_string( $value ) ? trim( $value ) : '';
    }

    private static function sanitize_meta_for_jsonl( $meta, $config ) {
        if ( ! is_array( $meta ) ) {
            return array();
        }

        $allow_pii = self::config_bool( $config, array( 'logging', 'pii' ), false );
        $sanitized = $meta;

        unset( $sanitized['descriptors'], $sanitized['context_descriptors'], $sanitized['resolved_descriptors'], $sanitized['client_ip'], $sanitized['client_ip_resolved'], $sanitized['ip_raw'] );

        if ( ! $allow_pii ) {
            $pii_keys = array( 'to', 'email', 'reply_to', 'from', 'from_address', 'reply_to_address' );
            foreach ( $pii_keys as $key ) {
                if ( array_key_exists( $key, $sanitized ) ) {
                    $sanitized[ $key ] = '[redacted]';
                }
            }
        }

        return $sanitized;
    }

    private static function resolve_desc_sha1( $meta, $config ) {
        $level = self::config_value( $config, array( 'logging', 'level' ), 0 );
        $level = is_numeric( $level ) ? (int) $level : 0;
        if ( $level < 2 ) {
            return '';
        }

        if ( is_array( $meta ) && isset( $meta['desc_sha1'] ) && is_string( $meta['desc_sha1'] ) ) {
            $provided = self::sanitize_desc_sha1( $meta['desc_sha1'] );
            if ( $provided !== '' ) {
                self::$desc_sha1 = $provided;
                return $provided;
            }
        }

        $from_meta = self::descriptors_from_meta( $meta );
        if ( $from_meta !== null ) {
            $hash = self::fingerprint_descriptors( $from_meta );
            if ( $hash !== '' ) {
                self::$desc_sha1 = $hash;
                return $hash;
            }
        }

        return self::$desc_sha1;
    }

    private static function descriptors_from_meta( $meta ) {
        if ( ! is_array( $meta ) ) {
            return null;
        }

        $keys = array( 'descriptors', 'context_descriptors', 'resolved_descriptors' );
        foreach ( $keys as $key ) {
            if ( isset( $meta[ $key ] ) && is_array( $meta[ $key ] ) ) {
                return $meta[ $key ];
            }
        }

        return null;
    }

    private static function fingerprint_descriptors( $descriptors ) {
        if ( ! is_array( $descriptors ) ) {
            return '';
        }

        $canonical = self::canonicalize_for_hash( $descriptors );
        $json = self::encode_json( $canonical );
        if ( ! is_string( $json ) || $json === '' || $json === '{}' ) {
            return '';
        }

        return sha1( $json );
    }

    private static function canonicalize_for_hash( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        $is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
        if ( $is_list ) {
            $out = array();
            foreach ( $value as $item ) {
                $out[] = self::canonicalize_for_hash( $item );
            }

            return $out;
        }

        ksort( $value );
        $out = array();
        foreach ( $value as $key => $item ) {
            $out[ $key ] = self::canonicalize_for_hash( $item );
        }

        return $out;
    }

    private static function sanitize_desc_sha1( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = strtolower( trim( $value ) );
        if ( preg_match( '/^[0-9a-f]{40}$/', $value ) !== 1 ) {
            return '';
        }

        return $value;
    }

    private static function config_bool( $config, $path, $fallback ) {
        $value = self::config_value( $config, $path, $fallback );
        if ( is_bool( $value ) ) {
            return $value;
        }

        return (bool) $fallback;
    }
}
