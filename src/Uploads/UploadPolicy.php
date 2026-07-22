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
        if ( $mime === 'image/webp' && self::webp_animation_state( $path ) !== false ) {
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

        $readiness = self::staged_host_readiness( $options );
        if ( empty( $readiness['ok'] ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'Photo processing is unavailable on this server.' );
        }

        $result['width'] = $width;
        $result['height'] = $height;
        $result['sha256'] = hash_file( 'sha256', $path );
        if ( ! is_string( $result['sha256'] ) || strlen( $result['sha256'] ) !== 64 ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        }
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

    public static function staged_host_readiness( $options = array() ) {
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

        $missing_mimes = array();
        foreach ( self::staged_mimes() as $required_mime ) {
            if ( ! self::imagick_supports( $required_mime, $options ) ) {
                $missing_mimes[] = $required_mime;
            }
        }
        $missing_operations = self::imagick_encodes_jpeg( $options ) ? array() : array( 'jpeg_encode' );
        $ready = empty( $missing_mimes ) && empty( $missing_operations );
        return array(
            'ok' => $ready,
            'reason' => $ready ? '' : 'backend',
            'backend' => $ready ? 'imagick' : '',
            'missing_mimes' => $missing_mimes,
            'missing_operations' => $missing_operations,
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

    public static function master_attempts() {
        $attempts = array();
        for ( $index = 0; $index < Anchors::get( 'STAGED_MASTER_MAX_ATTEMPTS' ); $index++ ) {
            $attempts[] = array(
                'edge' => Anchors::get( 'STAGED_MASTER_MAX_EDGE' ) - ( $index * Anchors::get( 'STAGED_MASTER_EDGE_STEP' ) ),
                'quality' => Anchors::get( 'STAGED_MASTER_JPEG_QUALITY_INITIAL' ) - ( $index * Anchors::get( 'STAGED_MASTER_JPEG_QUALITY_STEP' ) ),
            );
        }
        return $attempts;
    }

    public static function create_staged_derivatives( $validated, $destination_directory ) {
        if ( ! is_array( $validated ) || empty( $validated['ok'] ) || empty( $validated['tmp_name'] ) ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        }
        if ( ! is_string( $destination_directory )
            || $destination_directory === ''
            || ! is_dir( $destination_directory )
        ) {
            @unlink( $validated['tmp_name'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        }

        $directory = rtrim( $destination_directory, '/\\' );
        $master_path = $directory . '/master.jpg';
        $preview_path = $directory . '/preview.jpg';
        if ( file_exists( $master_path ) || file_exists( $preview_path ) ) {
            @unlink( $validated['tmp_name'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        }

        return self::create_derivatives_imagick( $validated['tmp_name'], $validated['mime'], $master_path, $preview_path );
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
            while ( $offset + 8 <= $length ) {
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
            return false;
        } finally {
            fclose( $handle );
        }
    }

    private static function create_derivatives_imagick( $source, $mime, $master_path, $preview_path ) {
        $image = null;
        $source_cleanup_failed = false;
        try {
            $image = new Imagick();
            // HEIC/HEIF containers may include auxiliary images; other staged
            // formats must load every frame so unsupported multi-image input is rejected.
            $image->readImage( self::is_heic_mime( $mime ) ? $source . '[0]' : $source );
            // Once Imagick owns the decoded pixels, the raw upload is no longer
            // needed. Fail before writing durable derivatives if it cannot be removed.
            if ( ( file_exists( $source ) || is_link( $source ) )
                && ( ! @unlink( $source ) || file_exists( $source ) || is_link( $source ) )
            ) {
                $source_cleanup_failed = true;
                throw new RuntimeException( 'source_cleanup_failed' );
            }
            if ( $image->getNumberImages() !== 1 ) {
                throw new RuntimeException( 'animated_image' );
            }
            $image->setIteratorIndex( 0 );
            self::imagick_orient( $image );
            if ( defined( 'Imagick::ORIENTATION_TOPLEFT' ) ) {
                $image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
            }
            self::convert_imagick_to_srgb( $image );
            $image->setImageBackgroundColor( new ImagickPixel( '#ffffff' ) );
            $flattened = $image->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
            $image->clear();
            $image = $flattened;
            $image->stripImage();

            $master = self::commit_derivative_attempts(
                $image,
                $master_path,
                self::master_attempts(),
                Anchors::get( 'STAGED_MASTER_MAX_BYTES' )
            );
            if ( empty( $master['ok'] ) ) {
                throw new RuntimeException( 'master_derivative' );
            }
            $preview = self::commit_derivative_attempts(
                $image,
                $preview_path,
                self::preview_attempts(),
                Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' )
            );
            if ( empty( $preview['ok'] ) ) {
                throw new RuntimeException( 'preview_derivative' );
            }

            return array(
                'ok' => true,
                'code' => '',
                'message' => '',
                'master' => $master,
                'preview' => $preview,
                'managed_bytes' => $master['bytes'] + $preview['bytes'],
            );
        } catch ( Throwable $error ) {
            @unlink( $master_path );
            @unlink( $preview_path );
            if ( $source_cleanup_failed ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'Photo processing is unavailable on this server.', 'source_cleanup_failed' );
            }
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        } finally {
            if ( $image instanceof Imagick ) {
                $image->clear();
                $image->destroy();
            }
            @unlink( $source );
        }
    }

    private static function commit_derivative_attempts( $normalized, $destination, $attempts, $max_bytes ) {
        $candidate = '';
        try {
            foreach ( $attempts as $index => $attempt ) {
                $image = clone $normalized;
                if ( max( $image->getImageWidth(), $image->getImageHeight() ) > $attempt['edge'] ) {
                    $image->thumbnailImage( $attempt['edge'], $attempt['edge'], true, false );
                }
                $image->setImageFormat( 'jpeg' );
                $image->setImageCompression( Imagick::COMPRESSION_JPEG );
                $image->setImageCompressionQuality( $attempt['quality'] );
                $image->stripImage();
                $blob = $image->getImageBlob();
                $width = $image->getImageWidth();
                $height = $image->getImageHeight();
                $image->clear();
                $image->destroy();
                $bytes = is_string( $blob ) ? strlen( $blob ) : 0;
                if ( $bytes <= 0 || $bytes > $max_bytes ) {
                    unset( $blob );
                    continue;
                }

                $candidate = $destination . '.attempt-' . $index;
                if ( file_exists( $candidate ) || @file_put_contents( $candidate, $blob, LOCK_EX ) !== $bytes ) {
                    throw new RuntimeException( 'preview_write' );
                }
                unset( $blob );
                if ( ! @rename( $candidate, $destination ) ) {
                    throw new RuntimeException( 'derivative_commit' );
                }
                if ( ! @chmod( $destination, 0600 ) ) {
                    @unlink( $destination );
                    throw new RuntimeException( 'derivative_permissions' );
                }
                return self::derivative_success( $destination, $bytes, $width, $height );
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

    private static function derivative_success( $path, $bytes, $width, $height ) {
        $sha256 = @hash_file( 'sha256', $path );
        if ( ! is_string( $sha256 ) || strlen( $sha256 ) !== 64 ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'This image could not be processed.' );
        }
        return array(
            'ok' => true,
            'code' => '',
            'message' => '',
            'bytes' => (int) $bytes,
            'width' => (int) $width,
            'height' => (int) $height,
            'mime' => 'image/jpeg',
            'extension' => 'jpg',
            'sha256' => $sha256,
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
        $probe = self::imagick_probe_bytes( $mime );
        if ( $probe === '' ) {
            return false;
        }
        $image = null;
        try {
            if ( empty( Imagick::queryFormats( $format ) ) ) {
                return false;
            }
            $image = new Imagick();
            $image->readImageBlob( $probe );
            if ( $image->getNumberImages() < 1 ) {
                return false;
            }
            $image->setIteratorIndex( 0 );
            return $image->getImageWidth() > 0 && $image->getImageHeight() > 0;
        } catch ( Throwable $error ) {
            return false;
        } finally {
            if ( $image instanceof Imagick ) {
                $image->clear();
                $image->destroy();
            }
        }
    }

    private static function imagick_encodes_jpeg( $options = array() ) {
        if ( array_key_exists( 'imagick_jpeg_encode', $options ) ) {
            try {
                return is_callable( $options['imagick_jpeg_encode'] )
                    ? (bool) call_user_func( $options['imagick_jpeg_encode'] )
                    : (bool) $options['imagick_jpeg_encode'];
            } catch ( Throwable $error ) {
                return false;
            }
        }
        // Tests that inject the decoder seam remain independent of the host's
        // Imagick installation unless they explicitly inject the encoder seam.
        if ( isset( $options['imagick_support'] ) && is_callable( $options['imagick_support'] ) ) {
            return true;
        }
        if ( ! class_exists( 'Imagick' ) ) {
            return false;
        }
        $image = null;
        try {
            $image = new Imagick();
            $image->newImage( 1, 1, new ImagickPixel( 'white' ) );
            $image->setImageFormat( 'jpeg' );
            $blob = $image->getImageBlob();
            return is_string( $blob ) && strlen( $blob ) > 2 && substr( $blob, 0, 2 ) === "\xff\xd8";
        } catch ( Throwable $error ) {
            return false;
        } finally {
            if ( $image instanceof Imagick ) {
                $image->clear();
                $image->destroy();
            }
        }
    }

    private static function imagick_probe_bytes( $mime ) {
        $encoded = array(
            'image/jpeg' => '/9j/4QAiRXhpZgAASUkqAAgAAAABABIBAwABAAAABgAAAAAAAAD/4AAQSkZJRgABAQAAAAAAAAD//gAYZWZvcm1zLW1ldGFkYXRhLW1hcmtlcv/bAEMAAwICAgICAwICAgMDAwMEBgQEBAQECAYGBQYJCAoKCQgJCQoMDwwKCw4LCQkNEQ0ODxAQERAKDBITEhATDxAQEP/bAEMBAwMDBAMECAQECBALCQsQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEP/AABEIADwAeAMBEQACEQEDEQH/xAAVAAEBAAAAAAAAAAAAAAAAAAAAB//EABQQAQAAAAAAAAAAAAAAAAAAAAD/xAAVAQEBAAAAAAAAAAAAAAAAAAAAB//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AJEraXgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP/Z',
            'image/png' => 'iVBORw0KGgoAAAANSUhEUgAAAHgAAAA8AgMAAADQw5Y7AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAJUExURQAAAP8AAP///2cZZB4AAAACdFJOUwCAmytOGAAAAAFiS0dEAmYLfGQAAAAfSURBVDjLY2AYBeSCUCzAYVR6VHpUelR6+EqPAjQAAA9NGpTFWdU2AAAAAElFTkSuQmCC',
            'image/webp' => 'UklGRlwAAABXRUJQVlA4IFAAAADQBACdASp4ADwAPpFIoUylpCMiIQgAsBIJaQDWIoAACLUSrzW19OnTp06dOnTfAAD+6zb/+1MEkZAH/0KNlEn3UZOi2BEtUYcQLgQAAAAAAA==',
        );
        if ( self::is_heic_mime( $mime ) ) {
            $path = __DIR__ . '/imagick-heic-probe.b64';
            if ( is_link( $path ) || ! is_file( $path ) ) {
                return '';
            }
            $value = @file_get_contents( $path );
        } else {
            $value = isset( $encoded[ $mime ] ) ? $encoded[ $mime ] : '';
        }
        if ( ! is_string( $value ) || $value === '' ) {
            return '';
        }
        $value = preg_replace( '/\s+/', '', $value );
        $decoded = is_string( $value ) ? base64_decode( $value, true ) : false;
        return is_string( $decoded ) ? $decoded : '';
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

    private static function convert_imagick_to_srgb( $image ) {
        $profiles = $image->getImageProfiles( 'icc', false );
        if ( ! empty( $profiles ) ) {
            // Imagick may label ICC-tagged RGB pixels as sRGB before converting them;
            // applying the destination profile performs the actual profile-aware transform.
            $encoded = @file_get_contents( __DIR__ . '/srgb.icc.b64' );
            $profile = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
            if ( ! is_string( $profile ) || strlen( $profile ) < 128 ) {
                throw new RuntimeException( 'srgb_profile_unavailable' );
            }
            $image->profileImage( 'icc', $profile );
        } else {
            $image->transformImageColorspace( Imagick::COLORSPACE_SRGB );
        }
        $image->setImageColorspace( Imagick::COLORSPACE_SRGB );
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
