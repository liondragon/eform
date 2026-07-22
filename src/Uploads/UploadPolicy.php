<?php
/**
 * Upload accept-token policy and MIME/extension validation helpers.
 *
 * Contract: Uploads
 * Contract: Validation pipeline
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/HeifInspector.php';
require_once __DIR__ . '/UploadValue.php';

class UploadPolicy {
    const DEFAULT_TOKENS = array( 'image', 'pdf' );

    const TOKEN_MAP = array(
        'image' => array(
            'extension_mimes' => array(
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
            ),
        ),
        'pdf' => array(
            'extension_mimes' => array(
                'pdf' => 'application/pdf',
            ),
        ),
    );

    const STAGED_TOKEN_MAP = array(
        'image' => array(
            'extension_mimes' => array(
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'heic' => array( 'image/heic', 'image/heif' ),
                'heif' => array( 'image/heic', 'image/heif' ),
            ),
        ),
    );

    const STAGED_CANONICAL_EXTENSION_BY_MIME = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
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

    public static function resolve_tokens( $accept, $use_defaults, $upload_mode = 'synchronous' ) {
        $tokens = self::normalize_accept_tokens( $accept );
        if ( empty( $tokens ) && $use_defaults ) {
            $tokens = self::DEFAULT_TOKENS;
        }

        $map_owner = $upload_mode === 'staged' ? self::STAGED_TOKEN_MAP : self::TOKEN_MAP;
        $allowed = array();
        foreach ( $tokens as $token ) {
            if ( isset( $map_owner[ $token ] ) ) {
                $allowed[] = $token;
            }
        }

        return $allowed;
    }

    public static function canonical_tokens( $accept ) {
        $tokens = self::normalize_accept_tokens( $accept );
        sort( $tokens, SORT_STRING );
        return $tokens;
    }

    public static function staged_tokens_allowed( $tokens ) {
        $tokens = self::canonical_tokens( $tokens );
        return $tokens === array( 'image' );
    }

    public static function policy_for_tokens( $tokens, $upload_mode = 'synchronous' ) {
        $mimes = array();
        $exts = array();
        $ext_to_mime = array();
        $map_owner = $upload_mode === 'staged' ? self::STAGED_TOKEN_MAP : self::TOKEN_MAP;

        foreach ( $tokens as $token ) {
            if ( ! isset( $map_owner[ $token ] ) ) {
                continue;
            }

            $map = $map_owner[ $token ];
            foreach ( $map['extension_mimes'] as $ext => $expected_mime ) {
                $exts[ $ext ] = true;
                $ext_to_mime[ $ext ] = $expected_mime;
                foreach ( is_array( $expected_mime ) ? $expected_mime : array( $expected_mime ) as $mime ) {
                    $mimes[ $mime ] = true;
                }
            }
        }

        return array(
            'mimes' => array_keys( $mimes ),
            'extensions' => array_keys( $exts ),
            'ext_to_mime' => $ext_to_mime,
        );
    }

    public static function staged_mimes() {
        $policy = self::policy_for_tokens( array( 'image' ), 'staged' );
        return $policy['mimes'];
    }

    /**
     * Project the authoritative staged extension policy to the browser. Where
     * a container has MIME aliases, prefer its same-named image MIME.
     */
    public static function staged_browser_mime_by_extension() {
        $policy = self::policy_for_tokens( array( 'image' ), 'staged' );
        $out = array();
        foreach ( $policy['ext_to_mime'] as $extension => $expected ) {
            $mimes = is_array( $expected ) ? $expected : array( $expected );
            $preferred = 'image/' . $extension;
            $out[ $extension ] = in_array( $preferred, $mimes, true ) ? $preferred : reset( $mimes );
        }
        ksort( $out, SORT_STRING );
        return $out;
    }

    public static function staged_extension_for_mime( $mime ) {
        return is_string( $mime ) && isset( self::STAGED_CANONICAL_EXTENSION_BY_MIME[ $mime ] )
            ? self::STAGED_CANONICAL_EXTENSION_BY_MIME[ $mime ]
            : '';
    }

    public static function effective_staged_limits( $field ) {
        $field = is_array( $field ) ? $field : array();
        $max_file_bytes = isset( $field['max_file_bytes'] ) && is_int( $field['max_file_bytes'] ) && $field['max_file_bytes'] > 0
            ? min( $field['max_file_bytes'], Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' ) )
            : 0;
        $max_files = isset( $field['max_files'] ) && is_int( $field['max_files'] ) && $field['max_files'] > 0
            ? $field['max_files']
            : 0;
        $maximum_total = $max_file_bytes > 0 && $max_files > 0 && $max_files <= intdiv( PHP_INT_MAX, $max_file_bytes )
            ? $max_file_bytes * $max_files
            : 0;
        $max_total_bytes = isset( $field['max_total_bytes'] ) && is_int( $field['max_total_bytes'] ) && $field['max_total_bytes'] > 0
            ? min( $field['max_total_bytes'], $maximum_total )
            : 0;

        return array(
            'max_file_bytes' => $max_file_bytes,
            'max_files' => $max_files,
            'max_total_bytes' => $max_total_bytes,
        );
    }

    public static function validate_item( $item, $field ) {
        $envelope = self::validate_item_envelope( $item, $field );
        if ( empty( $envelope['ok'] ) ) {
            return $envelope;
        }

        $path = $envelope['tmp_name'];
        $size = $envelope['bytes'];

        $mode = isset( $field['upload_mode'] ) && $field['upload_mode'] === 'staged' ? 'staged' : 'synchronous';
        $display_name = UploadValue::sanitize_display_name( UploadValue::original_name( $item ) );
        if ( $mode === 'staged' ) {
            $inspected = self::inspect_staged_artifact( $path, $display_name, $field );
            return empty( $inspected['ok'] )
                ? $inspected
                : array_merge( $inspected, array( 'tmp_name' => $path ) );
        }

        $extension = self::extension_from_name( $display_name );
        if ( $extension === '' ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This file type isn\'t allowed.' );
        }
        if ( ! self::finfo_available() ) {
            return self::failure( 'EFORMS_FINFO_UNAVAILABLE', 'File uploads are unsupported on this server.' );
        }

        $mime = self::detect_mime( $path );
        $accept_defined = array_key_exists( 'accept', $field );
        $tokens = self::resolve_tokens( $accept_defined ? $field['accept'] : null, ! $accept_defined, $mode );
        if ( empty( $tokens ) ) {
            return self::failure( 'EFORMS_ERR_ACCEPT_EMPTY', 'No allowed file types for this upload.' );
        }
        $policy = self::policy_for_tokens( $tokens, $mode );
        if ( $mime === false || ! self::mime_allowed( $mime, $extension, $policy ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This file type isn\'t allowed.' );
        }

        $result = array(
            'ok' => true,
            'code' => '',
            'message' => '',
            'tmp_name' => $path,
            'display_name' => $display_name,
            'extension' => $extension,
            'mime' => $mime,
            'bytes' => $size,
            'width' => 0,
            'height' => 0,
        );

        return $result;
    }

    public static function inspect_staged_artifact( $path, $display_name, $field ) {
        $display_name = UploadValue::sanitize_display_name( $display_name );
        $extension = self::extension_from_name( $display_name );
        $bytes = is_string( $path ) && ! is_link( $path ) && is_file( $path ) ? @filesize( $path ) : false;
        $max_file_bytes = isset( $field['max_file_bytes'] ) && is_int( $field['max_file_bytes'] )
            ? min( $field['max_file_bytes'], Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' ) )
            : Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' );
        if ( $display_name === '' || $extension === '' || ! is_int( $bytes ) || $bytes < 1 || $bytes > $max_file_bytes ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This file exceeds the size limit.' );
        }
        if ( ! self::finfo_available() ) {
            return self::failure( 'EFORMS_FINFO_UNAVAILABLE', 'File uploads are unsupported on this server.' );
        }

        $mime = self::detect_mime( $path );
        $tokens = isset( $field['accept'] ) && is_array( $field['accept'] )
            ? $field['accept']
            : self::default_tokens();
        $policy = self::policy_for_tokens( $tokens, 'staged' );
        if ( $mime === false || ! self::mime_allowed( $mime, $extension, $policy ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This file type isn\'t allowed.' );
        }
        if ( $mime === 'image/png' && self::png_animation_state( $path ) !== false ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be inspected.' );
        }
        if ( $mime === 'image/webp' && self::webp_animation_state( $path ) !== false ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be inspected.' );
        }

        $dimensions = self::image_dimensions( $path, $mime );
        if ( $dimensions === null || ! self::staged_dimensions_allowed( $dimensions[0], $dimensions[1] ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image exceeds the inspection limit.' );
        }
        return array(
            'ok' => true,
            'code' => '',
            'message' => '',
            'display_name' => $display_name,
            'extension' => $extension,
            'mime' => $mime,
            'bytes' => $bytes,
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
        );
    }

    /**
     * Validate the cheap upload envelope before MIME, image, or hash work.
     */
    public static function validate_item_envelope( $item, $field ) {
        if ( ! UploadValue::is_item( $item ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'File upload failed. Please try again.' );
        }

        $error = isset( $item['error'] ) ? (int) $item['error'] : UPLOAD_ERR_OK;
        if ( $error !== UPLOAD_ERR_OK ) {
            $size_error = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE;
            $message = $size_error
                ? 'This file exceeds the size limit.'
                : 'File upload failed. Please try again.';
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', $message, $size_error ? 'request_size_exceeded' : '' );
        }

        $path = isset( $item['tmp_name'] ) && is_string( $item['tmp_name'] ) ? $item['tmp_name'] : '';
        if ( $path === '' || ! is_file( $path ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'File upload failed. Please try again.' );
        }

        $size = filesize( $path );
        $max_file_bytes = isset( $field['max_file_bytes'] ) && is_numeric( $field['max_file_bytes'] )
            ? max( 0, (int) $field['max_file_bytes'] )
            : 0;
        if ( ! is_int( $size ) || $size < 0 ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This file exceeds the size limit.' );
        }
        if ( $max_file_bytes > 0 && $size > $max_file_bytes ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This file exceeds the size limit.', 'max_file_bytes_exceeded' );
        }
        return array( 'ok' => true, 'tmp_name' => $path, 'bytes' => $size );
    }

    public static function staged_dimensions_allowed( $width, $height ) {
        if ( ! is_numeric( $width ) || ! is_numeric( $height ) ) {
            return false;
        }

        $width = (int) $width;
        $height = (int) $height;
        if ( $width <= 0 || $height <= 0 || max( $width, $height ) > Anchors::get( 'MANAGED_ARTIFACT_MAX_EDGE' ) ) {
            return false;
        }

        return $width <= intdiv( Anchors::get( 'MANAGED_ARTIFACT_MAX_PIXELS' ), $height );
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
        if ( ( is_string( $expected_mime ) && $expected_mime === '' )
            || ( ! is_string( $expected_mime ) && ! is_array( $expected_mime ) )
            || ( is_array( $expected_mime ) && empty( $expected_mime ) )
        ) {
            return false;
        }

        $extension_matches = is_array( $expected_mime )
            ? in_array( $mime, $expected_mime, true )
            : $mime === $expected_mime;
        if ( ! $extension_matches ) {
            return false;
        }

        return in_array( $mime, $allowed_mimes, true );
    }

    private static function failure( $code, $message, $reason = '' ) {
        $result = array(
            'ok' => false,
            'code' => $code,
            'message' => $message,
        );
        if ( $reason !== '' ) {
            $result['reason'] = $reason;
        }
        return $result;
    }

    private static function image_dimensions( $path, $mime ) {
        if ( ! self::is_heic_mime( $mime ) ) {
            $metadata = array();
            $dimensions = @getimagesize( $path, $metadata );
            if ( ! is_array( $dimensions ) || ! isset( $dimensions[0], $dimensions[1] ) ) {
                return null;
            }
            $width = (int) $dimensions[0];
            $height = (int) $dimensions[1];
            if ( $mime === 'image/jpeg' ) {
                $orientation = self::jpeg_orientation( isset( $metadata['APP1'] ) ? $metadata['APP1'] : '' );
                if ( $orientation >= 5 && $orientation <= 8 ) {
                    $swap = $width;
                    $width = $height;
                    $height = $swap;
                }
            }
            return array( $width, $height );
        }
        $inspected = HeifInspector::inspect( $path );
        if ( ! is_array( $inspected )
            || ! self::staged_dimensions_allowed( $inspected['coded_width'], $inspected['coded_height'] )
        ) {
            return null;
        }
        return array( $inspected['width'], $inspected['height'] );
    }

    private static function jpeg_orientation( $app1 ) {
        if ( ! is_string( $app1 ) || strlen( $app1 ) < 14 || substr( $app1, 0, 6 ) !== "Exif\0\0" ) {
            return 1;
        }
        $tiff = substr( $app1, 6 );
        $little_endian = substr( $tiff, 0, 2 ) === 'II';
        if ( ! $little_endian && substr( $tiff, 0, 2 ) !== 'MM' ) {
            return 1;
        }
        $read_u16 = function ( $offset ) use ( $tiff, $little_endian ) {
            if ( $offset < 0 || $offset > strlen( $tiff ) - 2 ) {
                return null;
            }
            $value = unpack( $little_endian ? 'vvalue' : 'nvalue', substr( $tiff, $offset, 2 ) );
            return isset( $value['value'] ) ? (int) $value['value'] : null;
        };
        $read_u32 = function ( $offset ) use ( $tiff, $little_endian ) {
            if ( $offset < 0 || $offset > strlen( $tiff ) - 4 ) {
                return null;
            }
            $value = unpack( $little_endian ? 'Vvalue' : 'Nvalue', substr( $tiff, $offset, 4 ) );
            return isset( $value['value'] ) ? (int) $value['value'] : null;
        };
        if ( $read_u16( 2 ) !== 42 ) {
            return 1;
        }
        $ifd_offset = $read_u32( 4 );
        $entry_count = $ifd_offset === null ? null : $read_u16( $ifd_offset );
        if ( $entry_count === null || $ifd_offset > strlen( $tiff ) - 2 - ( $entry_count * 12 ) ) {
            return 1;
        }
        for ( $index = 0; $index < $entry_count; $index++ ) {
            $entry = $ifd_offset + 2 + ( $index * 12 );
            if ( $read_u16( $entry ) !== 0x0112 || $read_u16( $entry + 2 ) !== 3 || $read_u32( $entry + 4 ) !== 1 ) {
                continue;
            }
            $orientation = $read_u16( $entry + 8 );
            return $orientation !== null && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
        }
        return 1;
    }

    /**
     * Return true for APNG, false for a structurally bounded static PNG, or null on parse failure.
     */
    private static function png_animation_state( $path ) {
        $size = @filesize( $path );
        $handle = @fopen( $path, 'rb' );
        if ( ! is_int( $size ) || $size < 33 || $handle === false ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
            return null;
        }

        try {
            if ( fread( $handle, 8 ) !== "\x89PNG\r\n\x1a\n" ) {
                return null;
            }
            $first = true;
            $remaining_chunks = (int) Anchors::get( 'MANAGED_IMAGE_CONTAINER_MAX_CHUNKS' );
            while ( $remaining_chunks > 0 ) {
                $remaining_chunks--;
                $header = fread( $handle, 8 );
                if ( ! is_string( $header ) || strlen( $header ) !== 8 ) {
                    return null;
                }
                $chunk = unpack( 'Nlength/a4type', $header );
                $length = isset( $chunk['length'] ) ? (int) $chunk['length'] : -1;
                $type = isset( $chunk['type'] ) ? $chunk['type'] : '';
                $position = ftell( $handle );
                if ( $length < 0 || ! is_int( $position ) || $length > $size - $position - 4 ) {
                    return null;
                }
                if ( $first && ( $type !== 'IHDR' || $length !== 13 ) ) {
                    return null;
                }
                $first = false;
                if ( $type === 'acTL' ) {
                    return $length === 8 ? true : null;
                }
                if ( $type === 'IDAT' || $type === 'IEND' ) {
                    return false;
                }
                if ( fseek( $handle, $length + 4, SEEK_CUR ) !== 0 ) {
                    return null;
                }
            }
            return null;
        } finally {
            fclose( $handle );
        }
    }

    /**
     * Return true for animated WebP, false for a bounded static WebP, or null on parse failure.
     */
    private static function webp_animation_state( $path ) {
        $length = @filesize( $path );
        $handle = @fopen( $path, 'rb' );
        if ( ! is_int( $length ) || $length < 20 || $handle === false ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
            return null;
        }
        try {
            $riff = fread( $handle, 12 );
            if ( ! is_string( $riff ) || strlen( $riff ) !== 12 || substr( $riff, 0, 4 ) !== 'RIFF' || substr( $riff, 8, 4 ) !== 'WEBP' ) {
                return null;
            }
            $offset = 12;
            $remaining_chunks = (int) Anchors::get( 'MANAGED_IMAGE_CONTAINER_MAX_CHUNKS' );
            while ( $remaining_chunks > 0 && $offset + 8 <= $length ) {
                $remaining_chunks--;
                $header = fread( $handle, 8 );
                if ( ! is_string( $header ) || strlen( $header ) !== 8 ) {
                    return null;
                }
                $type = substr( $header, 0, 4 );
                $size = unpack( 'Vsize', substr( $header, 4, 4 ) );
                $size = isset( $size['size'] ) ? (int) $size['size'] : -1;
                $chunk_size = $size;
                $data_offset = $offset + 8;
                if ( $size < 0 || $data_offset > $length - $size ) {
                    return null;
                }
                if ( $type === 'ANIM' || $type === 'ANMF' ) {
                    return true;
                }
                if ( $type === 'VP8X' ) {
                    $flags = $size > 0 ? fread( $handle, 1 ) : false;
                    if ( ! is_string( $flags ) || strlen( $flags ) !== 1 ) {
                        return null;
                    }
                    if ( ( ord( $flags ) & 0x02 ) !== 0 ) {
                        return true;
                    }
                    $size--;
                } elseif ( $type === 'VP8 ' || $type === 'VP8L' ) {
                    return false;
                }
                $skip = $size + ( $chunk_size % 2 );
                if ( $skip > 0 && fseek( $handle, $skip, SEEK_CUR ) !== 0 ) {
                    return null;
                }
                $offset = ftell( $handle );
                if ( ! is_int( $offset ) ) {
                    return null;
                }
            }
            return $remaining_chunks > 0 ? false : null;
        } finally {
            fclose( $handle );
        }
    }

    private static function is_heic_mime( $mime ) {
        return $mime === 'image/heic' || $mime === 'image/heif';
    }

}
