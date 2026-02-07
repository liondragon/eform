<?php
/**
 * Upload accept-token policy and MIME/extension validation helpers.
 *
 * Spec: Uploads (docs/Canonical_Spec.md#sec-uploads)
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 */

class UploadPolicy {
    const DEFAULT_TOKENS = array( 'image', 'pdf' );

    const TOKEN_MAP = array(
        'image' => array(
            'mimes' => array(
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
            ),
            'extensions' => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
        ),
        'pdf' => array(
            'mimes' => array( 'application/pdf' ),
            'extensions' => array( 'pdf' ),
        ),
    );

    public static function default_tokens() {
        return self::DEFAULT_TOKENS;
    }

    public static function normalize_accept_tokens( $accept ) {
        if ( ! is_array( $accept ) ) {
            return array();
        }

        $tokens = array();
        foreach ( $accept as $entry ) {
            if ( ! is_string( $entry ) ) {
                continue;
            }

            $token = strtolower( trim( $entry ) );
            if ( $token === '' ) {
                continue;
            }

            $tokens[] = $token;
        }

        $seen = array();
        $out = array();
        foreach ( $tokens as $token ) {
            if ( isset( $seen[ $token ] ) ) {
                continue;
            }
            $seen[ $token ] = true;
            $out[] = $token;
        }

        return $out;
    }

    public static function resolve_tokens( $accept, $use_defaults ) {
        $tokens = self::normalize_accept_tokens( $accept );
        if ( empty( $tokens ) && $use_defaults ) {
            $tokens = self::DEFAULT_TOKENS;
        }

        $allowed = array();
        foreach ( $tokens as $token ) {
            if ( isset( self::TOKEN_MAP[ $token ] ) ) {
                $allowed[] = $token;
            }
        }

        return $allowed;
    }

    public static function policy_for_tokens( $tokens ) {
        $mimes = array();
        $exts = array();
        $ext_to_mime = array();

        foreach ( $tokens as $token ) {
            if ( ! isset( self::TOKEN_MAP[ $token ] ) ) {
                continue;
            }

            $map = self::TOKEN_MAP[ $token ];
            foreach ( $map['mimes'] as $mime ) {
                $mimes[ $mime ] = true;
            }
            foreach ( $map['extensions'] as $ext ) {
                $exts[ $ext ] = true;
                if ( ! isset( $ext_to_mime[ $ext ] ) ) {
                    $ext_to_mime[ $ext ] = self::extension_mime( $ext );
                }
            }
        }

        return array(
            'mimes' => array_keys( $mimes ),
            'extensions' => array_keys( $exts ),
            'ext_to_mime' => $ext_to_mime,
        );
    }

    public static function extension_from_name( $name ) {
        if ( ! is_string( $name ) || $name === '' ) {
            return '';
        }

        $ext = pathinfo( $name, PATHINFO_EXTENSION );
        $ext = is_string( $ext ) ? strtolower( $ext ) : '';

        return $ext;
    }

    public static function finfo_available() {
        if ( defined( 'EFORMS_FINFO_UNAVAILABLE' ) ) {
            return false;
        }

        return function_exists( 'finfo_open' );
    }

    public static function detect_mime( $path ) {
        if ( ! self::finfo_available() ) {
            return false;
        }

        if ( ! is_string( $path ) || $path === '' || ! is_file( $path ) ) {
            return false;
        }

        $finfo = new finfo( FILEINFO_MIME_TYPE );
        $mime = $finfo->file( $path );

        if ( ! is_string( $mime ) || $mime === '' ) {
            return false;
        }

        return strtolower( $mime );
    }

    public static function mime_allowed( $mime, $extension, $policy ) {
        if ( ! is_string( $mime ) || $mime === '' || ! is_string( $extension ) || $extension === '' ) {
            return false;
        }

        $allowed_mimes = isset( $policy['mimes'] ) && is_array( $policy['mimes'] ) ? $policy['mimes'] : array();
        $allowed_exts = isset( $policy['extensions'] ) && is_array( $policy['extensions'] ) ? $policy['extensions'] : array();
        $ext_to_mime = isset( $policy['ext_to_mime'] ) && is_array( $policy['ext_to_mime'] ) ? $policy['ext_to_mime'] : array();

        if ( ! in_array( $extension, $allowed_exts, true ) ) {
            return false;
        }

        $expected_mime = isset( $ext_to_mime[ $extension ] ) ? $ext_to_mime[ $extension ] : null;
        if ( ! is_string( $expected_mime ) || $expected_mime === '' ) {
            return false;
        }

        if ( $mime === 'application/octet-stream' ) {
            return in_array( $expected_mime, $allowed_mimes, true );
        }

        if ( $mime !== $expected_mime ) {
            return false;
        }

        return in_array( $mime, $allowed_mimes, true );
    }

    private static function extension_mime( $extension ) {
        switch ( $extension ) {
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            case 'pdf':
                return 'application/pdf';
        }

        return '';
    }
}
