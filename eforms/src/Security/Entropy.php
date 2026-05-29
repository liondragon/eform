<?php
/**
 * Shared entropy helpers for security-sensitive identifiers.
 */

class Entropy {
    public static function bytes( $length ) {
        $length = is_numeric( $length ) ? (int) $length : 0;
        if ( $length <= 0 ) {
            return '';
        }

        $bytes = '';
        if ( function_exists( 'random_bytes' ) ) {
            try {
                $bytes = random_bytes( $length );
            } catch ( Exception $e ) {
                $bytes = '';
            }
        }

        if ( $bytes === '' && function_exists( 'openssl_random_pseudo_bytes' ) ) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes( $length, $strong );
            if ( ! $strong ) {
                $bytes = '';
            }
        }

        if ( ! is_string( $bytes ) || strlen( $bytes ) !== $length ) {
            return '';
        }

        return $bytes;
    }

    public static function uuid_v4() {
        $bytes = self::bytes( 16 );
        if ( $bytes === '' ) {
            return '';
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

    public static function base64url_id( $length ) {
        $bytes = self::bytes( $length );
        if ( $bytes === '' ) {
            return '';
        }

        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    public static function hex( $length ) {
        $bytes = self::bytes( $length );
        return $bytes !== '' ? bin2hex( $bytes ) : '';
    }
}
