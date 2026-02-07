<?php
/**
 * Client-IP resolution and privacy-safe presentation helpers.
 *
 * Spec: Privacy and IP handling (docs/Canonical_Spec.md#sec-privacy)
 * Spec: Throttling (docs/Canonical_Spec.md#sec-throttling)
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

require_once __DIR__ . '/../Config.php';

class ClientIp {
    const IPV6_BITS = 128;
    const IPV4_BITS = 32;
    const IPV6_MASK_KEEP_BYTES = 6; // Keep first 48 bits, zero last 80 bits.

    /**
     * Resolve the client IP using trusted-proxy rules.
     *
     * @param mixed $request Optional request object/array.
     * @param array|null $config Optional config snapshot.
     * @return string Resolved IP literal, or empty string when unavailable/invalid.
     */
    public static function resolve( $request = null, $config = null ) {
        $config = self::config_snapshot( $config );
        $remote = self::remote_addr( $request );
        if ( $remote === '' ) {
            return '';
        }

        $header_name = self::config_value( $config, array( 'privacy', 'client_ip_header' ), '' );
        if ( ! is_string( $header_name ) ) {
            $header_name = '';
        }
        $header_name = trim( $header_name );
        if ( $header_name === '' ) {
            return $remote;
        }

        $trusted = self::config_value( $config, array( 'privacy', 'trusted_proxies' ), array() );
        if ( ! is_array( $trusted ) || ! self::ip_in_cidrs( $remote, $trusted ) ) {
            return $remote;
        }

        $header_value = self::header_value( $request, $header_name );
        if ( $header_value === '' ) {
            return $remote;
        }

        $candidates = self::parse_header_ips( $header_value );
        foreach ( $candidates as $candidate ) {
            if ( self::is_public_ip( $candidate ) ) {
                return $candidate;
            }
        }

        return $remote;
    }

    /**
     * Present a resolved IP per privacy.ip_mode.
     *
     * @param string $ip Resolved client IP literal.
     * @param array|null $config Optional config snapshot.
     * @return string Privacy-processed value suitable for logs/emails.
     */
    public static function present( $ip, $config = null ) {
        return self::present_with_mode( $ip, self::ip_mode( $config ), $config );
    }

    /**
     * Present an IP for logging sinks. Minimal mode never emits full IPs.
     *
     * @param string $ip Resolved client IP literal.
     * @param array|null $config Optional config snapshot.
     * @param string $logging_mode Logging sink mode.
     * @return string Privacy-processed value suitable for logs.
     */
    public static function present_for_logging( $ip, $config = null, $logging_mode = 'minimal' ) {
        $mode = self::ip_mode( $config );
        if ( is_string( $logging_mode ) && strtolower( $logging_mode ) === 'minimal' && $mode === 'full' ) {
            $mode = 'masked';
        }

        return self::present_with_mode( $ip, $mode, $config );
    }

    /**
     * Whether email templates should include the ip meta token.
     *
     * @param array|null $config Optional config snapshot.
     * @return bool
     */
    public static function should_include_email_ip( $config = null ) {
        return self::ip_mode( $config ) !== 'none';
    }

    /**
     * Resolve the effective privacy.ip_mode value.
     *
     * @param array|null $config Optional config snapshot.
     * @return string one of none|masked|hash|full
     */
    public static function ip_mode( $config = null ) {
        $config = self::config_snapshot( $config );
        $mode = self::config_value( $config, array( 'privacy', 'ip_mode' ), 'none' );
        if ( ! is_string( $mode ) ) {
            return 'none';
        }

        $mode = strtolower( trim( $mode ) );
        if ( in_array( $mode, array( 'none', 'masked', 'hash', 'full' ), true ) ) {
            return $mode;
        }

        return 'none';
    }

    private static function present_with_mode( $ip, $mode, $config ) {
        $ip = self::normalize_ip_literal( $ip, true );
        if ( $ip === '' ) {
            return '';
        }

        if ( $mode === 'none' ) {
            return '';
        }

        if ( $mode === 'full' ) {
            return $ip;
        }

        if ( $mode === 'hash' ) {
            $salt = self::config_value( self::config_snapshot( $config ), array( 'privacy', 'ip_hash_salt' ), '' );
            if ( ! is_string( $salt ) ) {
                $salt = '';
            }
            return hash( 'sha256', $ip . $salt );
        }

        if ( strpos( $ip, ':' ) !== false ) {
            return self::mask_ipv6( $ip );
        }

        return self::mask_ipv4( $ip );
    }

    private static function remote_addr( $request ) {
        $candidates = array();

        if ( is_array( $request ) ) {
            if ( isset( $request['remote_addr'] ) && is_string( $request['remote_addr'] ) ) {
                $candidates[] = $request['remote_addr'];
            }
            if ( isset( $request['client_ip'] ) && is_string( $request['client_ip'] ) ) {
                $candidates[] = $request['client_ip'];
            }
            if ( isset( $request['server'] ) && is_array( $request['server'] ) ) {
                if ( isset( $request['server']['REMOTE_ADDR'] ) && is_string( $request['server']['REMOTE_ADDR'] ) ) {
                    $candidates[] = $request['server']['REMOTE_ADDR'];
                }
            }
        }

        if ( is_object( $request ) ) {
            if ( isset( $request->remote_addr ) && is_string( $request->remote_addr ) ) {
                $candidates[] = $request->remote_addr;
            }
            if ( isset( $request->client_ip ) && is_string( $request->client_ip ) ) {
                $candidates[] = $request->client_ip;
            }
            if ( method_exists( $request, 'get_client_ip' ) ) {
                $value = $request->get_client_ip();
                if ( is_string( $value ) ) {
                    $candidates[] = $value;
                }
            }
        }

        if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
            $candidates[] = $_SERVER['REMOTE_ADDR'];
        }

        foreach ( $candidates as $candidate ) {
            $parsed = self::normalize_ip_literal( $candidate, true );
            if ( $parsed !== '' ) {
                return $parsed;
            }
        }

        return '';
    }

    private static function header_value( $request, $name ) {
        if ( ! is_string( $name ) || trim( $name ) === '' ) {
            return '';
        }

        $name = trim( $name );

        if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
            $value = $request->get_header( $name );
            if ( is_string( $value ) ) {
                return trim( $value );
            }
        }

        if ( is_array( $request ) && isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
            foreach ( $request['headers'] as $key => $value ) {
                if ( is_string( $key ) && strcasecmp( $key, $name ) === 0 && is_string( $value ) ) {
                    return trim( $value );
                }
            }
        }

        if ( is_array( $request ) && isset( $request['server'] ) && is_array( $request['server'] ) ) {
            $server_value = self::server_header_value( $request['server'], $name );
            if ( $server_value !== '' ) {
                return $server_value;
            }
        }

        return self::server_header_value( $_SERVER, $name );
    }

    private static function server_header_value( $server, $name ) {
        if ( ! is_array( $server ) ) {
            return '';
        }

        $keys = array(
            $name,
            'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ),
        );

        foreach ( $keys as $key ) {
            if ( isset( $server[ $key ] ) && is_string( $server[ $key ] ) ) {
                return trim( $server[ $key ] );
            }
        }

        return '';
    }

    private static function parse_header_ips( $value ) {
        if ( ! is_string( $value ) || $value === '' ) {
            return array();
        }

        $parts = explode( ',', $value );
        $ips = array();
        foreach ( $parts as $part ) {
            $ip = self::normalize_ip_literal( $part, true );
            if ( $ip !== '' ) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    private static function normalize_ip_literal( $value, $allow_port ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = trim( $value );
        $value = trim( $value, "\"'" );
        if ( $value === '' ) {
            return '';
        }

        if ( stripos( $value, 'for=' ) === 0 ) {
            $value = trim( substr( $value, 4 ), "\"'" );
        }

        if ( substr( $value, 0, 1 ) === '[' ) {
            $close = strpos( $value, ']' );
            if ( $close === false ) {
                return '';
            }
            $value = substr( $value, 1, $close - 1 );
        } elseif ( $allow_port && strpos( $value, '.' ) !== false ) {
            if ( preg_match( '/^(.+):([0-9]{1,5})$/', $value, $matches ) === 1 ) {
                $value = $matches[1];
            }
        }

        $zone_pos = strpos( $value, '%' );
        if ( $zone_pos !== false ) {
            $value = substr( $value, 0, $zone_pos );
        }

        $validated = filter_var( $value, FILTER_VALIDATE_IP );
        if ( $validated === false ) {
            return '';
        }

        $packed = @inet_pton( $value );
        if ( ! is_string( $packed ) ) {
            return $value;
        }

        $normalized = @inet_ntop( $packed );
        return is_string( $normalized ) ? $normalized : $value;
    }

    private static function is_public_ip( $ip ) {
        if ( ! is_string( $ip ) || $ip === '' ) {
            return false;
        }

        return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false;
    }

    private static function ip_in_cidrs( $ip, $cidrs ) {
        if ( ! is_string( $ip ) || $ip === '' || ! is_array( $cidrs ) ) {
            return false;
        }

        foreach ( $cidrs as $cidr ) {
            if ( ! is_string( $cidr ) || trim( $cidr ) === '' ) {
                continue;
            }
            if ( self::ip_in_cidr( $ip, trim( $cidr ) ) ) {
                return true;
            }
        }

        return false;
    }

    private static function ip_in_cidr( $ip, $cidr ) {
        $slash = strpos( $cidr, '/' );
        if ( $slash === false ) {
            $literal = self::normalize_ip_literal( $cidr, false );
            return $literal !== '' && $literal === $ip;
        }

        $network = self::normalize_ip_literal( substr( $cidr, 0, $slash ), false );
        $prefix_raw = trim( substr( $cidr, $slash + 1 ) );
        if ( $network === '' || $prefix_raw === '' || preg_match( '/^[0-9]+$/', $prefix_raw ) !== 1 ) {
            return false;
        }

        $prefix = (int) $prefix_raw;
        $ip_bin = @inet_pton( $ip );
        $network_bin = @inet_pton( $network );
        if ( ! is_string( $ip_bin ) || ! is_string( $network_bin ) || strlen( $ip_bin ) !== strlen( $network_bin ) ) {
            return false;
        }

        $max_bits = strlen( $ip_bin ) === 16 ? self::IPV6_BITS : self::IPV4_BITS;
        if ( $prefix < 0 || $prefix > $max_bits ) {
            return false;
        }

        $full_bytes = intdiv( $prefix, 8 );
        $remainder_bits = $prefix % 8;

        if ( $full_bytes > 0 ) {
            if ( substr( $ip_bin, 0, $full_bytes ) !== substr( $network_bin, 0, $full_bytes ) ) {
                return false;
            }
        }

        if ( $remainder_bits === 0 ) {
            return true;
        }

        $mask = ( 0xFF << ( 8 - $remainder_bits ) ) & 0xFF;
        return ( ord( $ip_bin[ $full_bytes ] ) & $mask ) === ( ord( $network_bin[ $full_bytes ] ) & $mask );
    }

    private static function mask_ipv4( $ip ) {
        $ip = self::normalize_ip_literal( $ip, false );
        if ( $ip === '' || strpos( $ip, ':' ) !== false ) {
            return '';
        }

        $parts = explode( '.', $ip );
        if ( count( $parts ) !== 4 ) {
            return '';
        }

        $parts[3] = '0';
        return implode( '.', $parts );
    }

    private static function mask_ipv6( $ip ) {
        $ip = self::normalize_ip_literal( $ip, false );
        if ( $ip === '' || strpos( $ip, ':' ) === false ) {
            return '';
        }

        $packed = @inet_pton( $ip );
        if ( ! is_string( $packed ) || strlen( $packed ) !== 16 ) {
            return '';
        }

        $masked = substr( $packed, 0, self::IPV6_MASK_KEEP_BYTES ) . str_repeat( "\x00", 16 - self::IPV6_MASK_KEEP_BYTES );
        $display = @inet_ntop( $masked );

        return is_string( $display ) ? $display : '';
    }

    private static function config_snapshot( $config ) {
        if ( is_array( $config ) ) {
            return $config;
        }

        if ( class_exists( 'Config' ) && method_exists( 'Config', 'get' ) ) {
            $snapshot = Config::get();
            if ( is_array( $snapshot ) ) {
                return $snapshot;
            }
        }

        if ( class_exists( 'Config' ) && method_exists( 'Config', 'defaults' ) ) {
            $defaults = Config::defaults();
            if ( is_array( $defaults ) ) {
                return $defaults;
            }
        }

        return array();
    }

    private static function config_value( $config, $path, $fallback ) {
        if ( ! is_array( $config ) || ! is_array( $path ) ) {
            return $fallback;
        }

        $cursor = $config;
        foreach ( $path as $segment ) {
            if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
                return $fallback;
            }
            $cursor = $cursor[ $segment ];
        }

        return $cursor;
    }
}
