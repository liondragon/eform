<?php
/**
 * Fail-closed wrappers for required WordPress runtime APIs.
 */

class WordPressRuntime {
    public static function safe_redirect( $url, $status ) {
        if ( ! function_exists( 'wp_safe_redirect' ) ) {
            return false;
        }

        return wp_safe_redirect( $url, $status ) === true;
    }

    /**
     * Emit an intentional cross-origin redirect only through WordPress after
     * exact HTTPS-origin validation. The allowed origin comes from the
     * already-validated Worker composition, never from request data.
     */
    public static function external_redirect( $url, $status, $allowed_origin ) {
        if ( ! function_exists( 'wp_redirect' )
            || ! is_int( $status )
            || $status < 300
            || $status > 399
            || self::origin( $url ) === ''
            || ! hash_equals( self::origin( $allowed_origin ), self::origin( $url ) )
        ) {
            return false;
        }
        return wp_redirect( $url, $status, 'eForms' ) === true;
    }

    private static function origin( $url ) {
        if ( ! is_string( $url ) || $url === '' ) {
            return '';
        }
        $parts = parse_url( $url );
        if ( ! is_array( $parts )
            || ! isset( $parts['scheme'], $parts['host'] )
            || strtolower( $parts['scheme'] ) !== 'https'
            || isset( $parts['user'] )
            || isset( $parts['pass'] )
        ) {
            return '';
        }
        $host = strtolower( rtrim( $parts['host'], '.' ) );
        if ( $host === '' ) {
            return '';
        }
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
        return 'https://' . $host . ( $port === 443 ? '' : ':' . $port );
    }
}
