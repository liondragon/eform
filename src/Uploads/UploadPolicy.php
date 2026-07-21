<?php
/**
 * Upload accept-token policy and MIME/extension validation helpers.
 *
 * Contract: Uploads
 * Contract: Validation pipeline
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Helpers.php';
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
            ),
        ),
        'heic' => array(
            'extension_mimes' => array(
                'heic' => array( 'image/heic', 'image/heif' ),
                'heif' => array( 'image/heic', 'image/heif' ),
            ),
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
        return $tokens === array( 'image' ) || $tokens === array( 'heic', 'image' );
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

    public static function staged_mimes( $tokens = array( 'image' ) ) {
        $policy = self::policy_for_tokens( $tokens, 'staged' );
        return $policy['mimes'];
    }

    public static function validate_item( $item, $field, $options = array() ) {
        $envelope = self::validate_item_envelope( $item, $field );
        if ( empty( $envelope['ok'] ) ) {
            return $envelope;
        }

        $path = $envelope['tmp_name'];
        $size = $envelope['bytes'];

        $display_name = UploadValue::sanitize_display_name( UploadValue::original_name( $item ) );
        $extension = self::extension_from_name( $display_name );
        if ( $extension === '' ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This file type isn\'t allowed.' );
        }

        if ( ! self::finfo_available() ) {
            return self::failure( 'EFORMS_FINFO_UNAVAILABLE', 'File uploads are unsupported on this server.' );
        }

        $mime = self::detect_mime( $path );
        $mode = isset( $field['upload_mode'] ) && $field['upload_mode'] === 'staged' ? 'staged' : 'synchronous';
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

        if ( $mode !== 'staged' ) {
            return $result;
        }
        if ( $mime === 'image/png' && self::png_animation_state( $path ) !== false ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        }

        $dimensions = self::image_dimensions( $path, $mime );
        if ( $dimensions === null ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        }

        $width = $dimensions[0];
        $height = $dimensions[1];
        if ( ! self::staged_dimensions_allowed( $width, $height ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image exceeds the processing limit.' );
        }

        $readiness = self::staged_host_readiness( $mime, $options );
        if ( empty( $readiness['ok'] ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'Photo processing is unavailable on this server.' );
        }

        $result['width'] = $width;
        $result['height'] = $height;
        $result['backend'] = $readiness['backend'];
        return $result;
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
        if ( $width <= 0 || $height <= 0 || max( $width, $height ) > Anchors::get( 'STAGED_IMAGE_MAX_EDGE' ) ) {
            return false;
        }

        return $width <= intdiv( Anchors::get( 'STAGED_IMAGE_MAX_PIXELS' ), $height );
    }

    public static function staged_host_readiness( $source_mime, $options = array() ) {
        $memory = array_key_exists( 'memory_limit', $options ) ? $options['memory_limit'] : ini_get( 'memory_limit' );
        $execution = array_key_exists( 'execution_limit', $options ) ? $options['execution_limit'] : ini_get( 'max_execution_time' );
        $memory_bytes = self::ini_bytes( $memory );
        $execution_seconds = is_numeric( $execution ) ? (int) $execution : -1;

        if ( $memory_bytes !== -1 && $memory_bytes < Anchors::get( 'STAGED_IMAGE_MIN_MEMORY_BYTES' ) ) {
            return array( 'ok' => false, 'reason' => 'memory_limit', 'backend' => '' );
        }
        if ( $execution_seconds > 0 && $execution_seconds < Anchors::get( 'STAGED_IMAGE_MIN_EXECUTION_SECONDS' ) ) {
            return array( 'ok' => false, 'reason' => 'execution_limit', 'backend' => '' );
        }

        if ( ! self::is_heic_mime( $source_mime ) ) {
            $supports = isset( $options['editor_support'] ) && is_callable( $options['editor_support'] )
                ? $options['editor_support']
                : ( function_exists( 'wp_image_editor_supports' ) ? 'wp_image_editor_supports' : null );
            try {
                $editor_ready = is_callable( $supports )
                    && call_user_func( $supports, array( 'mime_type' => $source_mime ) )
                    && call_user_func( $supports, array( 'mime_type' => 'image/jpeg' ) );
            } catch ( Throwable $error ) {
                $editor_ready = false;
            }
            if ( ! $editor_ready ) {
                return array( 'ok' => false, 'reason' => 'editor_support', 'backend' => '' );
            }
        }

        $backend = self::preview_backend( $source_mime, $options );
        return array(
            'ok' => $backend !== '',
            'reason' => $backend === '' ? 'backend' : '',
            'backend' => $backend,
        );
    }

    public static function preview_attempts() {
        $attempts = array();
        for ( $index = 0; $index < Anchors::get( 'STAGED_PREVIEW_MAX_ATTEMPTS' ); $index++ ) {
            $attempts[] = array(
                'edge' => Anchors::get( 'STAGED_PREVIEW_MAX_EDGE' ) - ( $index * Anchors::get( 'STAGED_PREVIEW_EDGE_STEP' ) ),
                'quality' => Anchors::get( 'STAGED_PREVIEW_JPEG_QUALITY_INITIAL' ) - ( $index * Anchors::get( 'STAGED_PREVIEW_JPEG_QUALITY_STEP' ) ),
            );
        }
        return $attempts;
    }

    public static function create_staged_preview( $validated, $destination ) {
        if ( ! is_array( $validated ) || empty( $validated['ok'] ) || empty( $validated['tmp_name'] ) || empty( $validated['backend'] ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        }
        if ( ! is_string( $destination ) || $destination === '' || strtolower( pathinfo( $destination, PATHINFO_EXTENSION ) ) !== 'jpg' || file_exists( $destination ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        }

        if ( $validated['backend'] === 'imagick' ) {
            return self::create_preview_imagick( $validated['tmp_name'], $destination, $validated['mime'] );
        }
        if ( $validated['backend'] === 'gd' ) {
            return self::create_preview_gd( $validated['tmp_name'], $destination, $validated['mime'] );
        }

        return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
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
            $dimensions = @getimagesize( $path );
            if ( ! is_array( $dimensions ) || ! isset( $dimensions[0], $dimensions[1] ) ) {
                return null;
            }
            return array( (int) $dimensions[0], (int) $dimensions[1] );
        }

        if ( ! class_exists( 'Imagick' ) ) {
            return null;
        }

        $image = null;
        try {
            $image = new Imagick();
            $image->pingImage( $path . '[0]' );
            if ( $image->getNumberImages() !== 1 ) {
                return null;
            }
            $image->setIteratorIndex( 0 );
            return array( $image->getImageWidth(), $image->getImageHeight() );
        } catch ( Throwable $error ) {
            return null;
        } finally {
            if ( $image instanceof Imagick ) {
                $image->clear();
                $image->destroy();
            }
        }
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
            while ( true ) {
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
        } finally {
            fclose( $handle );
        }
    }

    private static function preview_backend( $source_mime, $options = array() ) {
        if ( self::imagick_supports( $source_mime, $options ) && self::imagick_supports( 'image/jpeg', $options ) ) {
            return 'imagick';
        }

        $gd_function = self::gd_loader( $source_mime );
        $gd_functions = array( $gd_function, 'imagejpeg', 'imagecreatetruecolor', 'imagecopyresampled', 'imagecopy', 'imagedestroy' );
        $exif_ready = $source_mime !== 'image/jpeg'
            || ( array_key_exists( 'exif_support', $options )
                ? (bool) $options['exif_support']
                : function_exists( 'exif_read_data' ) );
        // GD cannot uphold the JPEG orientation contract without an EXIF reader.
        if ( $gd_function !== '' && $exif_ready && count( array_filter( $gd_functions, 'function_exists' ) ) === count( $gd_functions ) ) {
            return 'gd';
        }
        return '';
    }

    private static function create_preview_imagick( $source, $destination, $source_mime ) {
        $image = null;
        try {
            $image = new Imagick();
            $read_source = self::is_heic_mime( $source_mime ) ? $source . '[0]' : $source;
            $image->readImage( $read_source );
            if ( $image->getNumberImages() !== 1 ) {
                throw new RuntimeException( 'animated_image' );
            }
            $image->setIteratorIndex( 0 );
            self::imagick_orient( $image );
            if ( defined( 'Imagick::ORIENTATION_TOPLEFT' ) ) {
                $image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
            }
            // Keep layer flattening independent of whether the source coder can encode.
            $image->setImageFormat( 'jpeg' );
            $image->setImageBackgroundColor( new ImagickPixel( '#ffffff' ) );
            $flattened = $image->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
            $image->clear();
            $image = $flattened;
            $image->stripImage();

            return self::commit_preview_attempts(
                $destination,
                function ( $attempt ) use ( &$image ) {
                    if ( max( $image->getImageWidth(), $image->getImageHeight() ) > $attempt['edge'] ) {
                        $image->thumbnailImage( $attempt['edge'], $attempt['edge'], true, true );
                    }
                    $image->setImageFormat( 'jpeg' );
                    $image->setImageCompression( Imagick::COMPRESSION_JPEG );
                    $image->setImageCompressionQuality( $attempt['quality'] );
                    $image->stripImage();
                    return array(
                        'blob' => $image->getImageBlob(),
                        'width' => $image->getImageWidth(),
                        'height' => $image->getImageHeight(),
                    );
                }
            );
        } catch ( Throwable $error ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        } finally {
            if ( $image instanceof Imagick ) {
                $image->clear();
                $image->destroy();
            }
        }

    }

    private static function create_preview_gd( $source, $destination, $mime ) {
        $loader = self::gd_loader( $mime );
        $image = null;
        try {
            $image = $loader !== '' && function_exists( $loader ) ? @$loader( $source ) : false;
            if ( $image ) {
                $image = self::gd_orient( $image, $source, $mime );
            }
            if ( $image ) {
                $image = self::gd_flatten_white( $image );
            }
            if ( ! $image ) {
                throw new RuntimeException();
            }

            return self::commit_preview_attempts(
                $destination,
                function ( $attempt ) use ( &$image ) {
                    $image = self::gd_resize_within( $image, $attempt['edge'] );
                    if ( ! $image ) {
                        throw new RuntimeException( 'preview_resize' );
                    }
                    return array(
                        'blob' => self::gd_jpeg_blob( $image, $attempt['quality'] ),
                        'width' => imagesx( $image ),
                        'height' => imagesy( $image ),
                    );
                }
            );
        } catch ( Throwable $error ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        } finally {
            if ( $image ) {
                @imagedestroy( $image );
            }
        }
    }

    private static function commit_preview_attempts( $destination, $encode_attempt ) {
        $candidate = '';
        try {
            foreach ( self::preview_attempts() as $index => $attempt ) {
                $encoded = call_user_func( $encode_attempt, $attempt );
                $blob = is_array( $encoded ) && isset( $encoded['blob'] ) ? $encoded['blob'] : false;
                $bytes = is_string( $blob ) ? strlen( $blob ) : 0;
                if ( $bytes <= 0 || $bytes > Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' ) ) {
                    unset( $blob );
                    continue;
                }

                $candidate = $destination . '.attempt-' . $index;
                if ( file_exists( $candidate ) || @file_put_contents( $candidate, $blob, LOCK_EX ) !== $bytes ) {
                    throw new RuntimeException( 'preview_write' );
                }
                unset( $blob );
                if ( ! @rename( $candidate, $destination ) ) {
                    throw new RuntimeException( 'preview_commit' );
                }
                @chmod( $destination, 0600 );
                return self::preview_success( $destination, $bytes, $encoded['width'], $encoded['height'], $index );
            }
        } catch ( Throwable $error ) {
            // The failure result lets the store release its reservation.
        } finally {
            if ( $candidate !== '' && is_file( $candidate ) ) {
                @unlink( $candidate );
            }
            foreach ( glob( $destination . '.attempt-*' ) ?: array() as $partial ) {
                @unlink( $partial );
            }
        }

        return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
    }

    private static function gd_jpeg_blob( $image, $quality ) {
        $buffer_level = ob_get_level();
        if ( ! ob_start() ) {
            return false;
        }
        try {
            if ( ! @imagejpeg( $image, null, $quality ) ) {
                return false;
            }
            $blob = ob_get_contents();
            return is_string( $blob ) ? $blob : false;
        } catch ( Throwable $error ) {
            return false;
        } finally {
            while ( ob_get_level() > $buffer_level ) {
                @ob_end_clean();
            }
        }
    }

    private static function preview_success( $path, $bytes, $width, $height, $attempt ) {
        return array(
            'ok' => true,
            'code' => '',
            'message' => '',
            'path' => $path,
            'bytes' => (int) $bytes,
            'width' => (int) $width,
            'height' => (int) $height,
            'mime' => 'image/jpeg',
            'extension' => 'jpg',
            'attempt' => (int) $attempt,
        );
    }

    private static function imagick_format( $mime ) {
        if ( self::is_heic_mime( $mime ) ) {
            return 'HEIC';
        }
        return $mime === 'image/jpeg' ? 'JPEG' : ( $mime === 'image/png' ? 'PNG' : ( $mime === 'image/webp' ? 'WEBP' : '' ) );
    }

    private static function imagick_supports( $mime, $options = array() ) {
        if ( isset( $options['imagick_support'] ) && is_callable( $options['imagick_support'] ) ) {
            try {
                return (bool) call_user_func( $options['imagick_support'], $mime );
            } catch ( Throwable $error ) {
                return false;
            }
        }
        if ( ! class_exists( 'Imagick' ) ) {
            return false;
        }

        $format = self::imagick_format( $mime );
        if ( $format === '' ) {
            return false;
        }
        try {
            return ! empty( Imagick::queryFormats( $format ) );
        } catch ( Throwable $error ) {
            return false;
        }
    }

    private static function is_heic_mime( $mime ) {
        return $mime === 'image/heic' || $mime === 'image/heif';
    }

    private static function imagick_orient( $image ) {
        if ( method_exists( $image, 'autoOrientImage' ) ) {
            $image->autoOrientImage();
            return;
        }
        if ( method_exists( $image, 'autoOrient' ) ) {
            $image->autoOrient();
            return;
        }
        if ( method_exists( $image, 'autoOrientate' ) ) {
            $image->autoOrientate();
        }
    }

    private static function gd_loader( $mime ) {
        if ( $mime === 'image/jpeg' ) {
            return 'imagecreatefromjpeg';
        }
        if ( $mime === 'image/png' ) {
            return 'imagecreatefrompng';
        }
        if ( $mime === 'image/webp' ) {
            return 'imagecreatefromwebp';
        }
        return '';
    }

    private static function gd_orient( $image, $source, $mime ) {
        if ( $mime !== 'image/jpeg' ) {
            return $image;
        }
        if ( ! function_exists( 'exif_read_data' ) ) {
            @imagedestroy( $image );
            return false;
        }
        $exif = @exif_read_data( $source );
        $orientation = is_array( $exif ) && isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
        if ( $orientation === 2 || $orientation === 4 || $orientation === 5 || $orientation === 7 ) {
            // Orientation 5 is horizontal-flip + 90 CCW; 7 is horizontal-flip + 90 CW.
            if ( ! @imageflip( $image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL ) ) {
                @imagedestroy( $image );
                return false;
            }
        }
        $angle = 0;
        if ( $orientation === 3 ) {
            $angle = 180;
        } elseif ( $orientation === 6 || $orientation === 7 ) {
            $angle = -90;
        } elseif ( $orientation === 5 || $orientation === 8 ) {
            $angle = 90;
        }
        if ( $angle !== 0 ) {
            $rotated = @imagerotate( $image, $angle, 0 );
            if ( ! $rotated ) {
                @imagedestroy( $image );
                return false;
            }
            @imagedestroy( $image );
            $image = $rotated;
        }
        return $image;
    }

    private static function gd_flatten_white( $image ) {
        $width = imagesx( $image );
        $height = imagesy( $image );
        $canvas = imagecreatetruecolor( $width, $height );
        if ( ! $canvas ) {
            @imagedestroy( $image );
            return false;
        }
        $white = imagecolorallocate( $canvas, 255, 255, 255 );
        if ( $white === false
            || ! @imagefill( $canvas, 0, 0, $white )
            || ! @imagealphablending( $canvas, true )
            || ! @imagecopy( $canvas, $image, 0, 0, 0, 0, $width, $height )
        ) {
            @imagedestroy( $canvas );
            @imagedestroy( $image );
            return false;
        }
        @imagedestroy( $image );
        return $canvas;
    }

    private static function gd_resize_within( $image, $edge ) {
        $width = imagesx( $image );
        $height = imagesy( $image );
        if ( max( $width, $height ) <= $edge ) {
            return $image;
        }
        $ratio = $edge / max( $width, $height );
        $target_width = max( 1, (int) floor( $width * $ratio ) );
        $target_height = max( 1, (int) floor( $height * $ratio ) );
        $target = imagecreatetruecolor( $target_width, $target_height );
        if ( ! $target || ! imagecopyresampled( $target, $image, 0, 0, 0, 0, $target_width, $target_height, $width, $height ) ) {
            if ( $target ) {
                imagedestroy( $target );
            }
            imagedestroy( $image );
            return false;
        }
        imagedestroy( $image );
        return $target;
    }

    private static function ini_bytes( $value ) {
        if ( is_int( $value ) ) {
            return $value;
        }
        if ( ! is_string( $value ) || trim( $value ) === '' ) {
            return 0;
        }
        $value = trim( $value );
        if ( $value === '-1' ) {
            return -1;
        }
        if ( $value === '0' ) {
            return 0;
        }
        return Helpers::bytes_from_ini( $value );
    }

}
