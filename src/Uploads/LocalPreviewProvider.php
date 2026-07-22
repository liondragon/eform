<?php
/**
 * Optional, bounded lazy previews for locally stored authoritative artifacts.
 *
 * Preview bytes are replaceable cache data. They never participate in upload,
 * manifest, finalization, or managed-capacity state.
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Security/Entropy.php';
require_once __DIR__ . '/PrivateDir.php';
require_once __DIR__ . '/UploadPolicy.php';
require_once __DIR__ . '/WorkerProtocol.php';

final class LocalPreviewProvider {
    const ROOT_DIR = 'preview-cache';
    const RECIPE_VERSION = WorkerProtocol::REVIEW_RECIPE_VERSION;
    const CACHE_FILENAME = 'preview.jpg';
    const LOCK_FILENAME = '.producer.lock';
    const DELETED_FILENAME = '.deleted';
    const SLOTS_DIR = '.slots';

    public static function render( $artifact, $uploads_dir, $concurrency, $encoder = null, $admission = null ) {
        if ( ! self::valid_artifact( $artifact )
            || ! isset( $artifact['source_path'], $artifact['bytes'] )
            || ! is_string( $artifact['source_path'] )
            || ! is_int( $artifact['bytes'] )
            || $artifact['bytes'] < 1
            || ! self::valid_concurrency( $concurrency )
        ) {
            return self::unavailable( 'configuration_invalid' );
        }
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::unavailable( 'lifecycle_unavailable' );
        }
        $producer = false;
        $slot = false;
        try {
            $root = PrivateDir::leased_subdir( $lifecycle, self::ROOT_DIR, true, true );
            $directory = $root !== '' ? self::object_directory( $root, $artifact, true ) : '';
            if ( $directory === '' ) {
                return self::unavailable( 'cache_unavailable' );
            }
            $producer = self::acquire_lock( $directory . '/' . self::LOCK_FILENAME, true );
            if ( $producer === false ) {
                return self::busy();
            }
            if ( self::deleted( $directory ) ) {
                return self::unavailable( 'artifact_deleted' );
            }
            $cached = self::open_cache( $directory . '/' . self::CACHE_FILENAME );
            if ( ! empty( $cached['ok'] ) ) {
                return $cached;
            }
            if ( ! self::remove_cache_members( $directory ) ) {
                return self::unavailable( 'cache_invalid' );
            }

            $slot = self::acquire_slot( $root, $concurrency );
            if ( $slot === false ) {
                return self::busy();
            }
            $source = $artifact['source_path'];
            if ( is_link( $source ) || ! is_file( $source ) || @filesize( $source ) !== $artifact['bytes'] ) {
                return self::unavailable( 'artifact_unavailable' );
            }
            $version = Entropy::uuid_v4();
            $temporary = $version !== '' ? $directory . '/.' . $version . '.tmp' : '';
            $allocated = false;
            if ( is_callable( $admission ) && $temporary !== '' ) {
                try {
                    $allocated = (bool) call_user_func( $admission, $lifecycle, $temporary, Anchors::get( 'REVIEW_PREVIEW_MAX_BYTES' ) );
                } catch ( Throwable $error ) {
                    $allocated = false;
                }
            }
            $generated = $allocated && self::encode( $source, $artifact['mime'], $temporary, $encoder );
            $destination = $directory . '/' . self::CACHE_FILENAME;
            if ( ! $generated
                || self::deleted( $directory )
                || ! @rename( $temporary, $destination )
                || ! @chmod( $destination, 0600 )
            ) {
                @unlink( $temporary );
                @unlink( $destination );
                return self::unavailable( 'preview_failed' );
            }
            $result = self::open_cache( $destination );
            return ! empty( $result['ok'] ) ? $result : self::unavailable( 'preview_failed' );
        } finally {
            if ( $slot !== false ) {
                self::release_lock( $slot );
            }
            if ( $producer !== false ) {
                self::release_lock( $producer );
            }
            $lifecycle->release();
        }
    }

    /**
     * Fence and remove one cache without waiting for an in-flight conversion.
     * The caller can retry rather than holding aggregate/capacity locks.
     */
    public static function delete_cache( $lifecycle, $object_key, $object_version ) {
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return false;
        }
        $artifact = array(
            'object_key' => $object_key,
            'object_version' => $object_version,
            'mime' => 'image/jpeg',
        );
        if ( ! self::valid_artifact( $artifact ) ) {
            return false;
        }
        // Create the private cache root and the deterministic object directory
        // even when no preview has existed yet. The per-object producer lock
        // then serializes this durable deletion fence with first-time render.
        $root = PrivateDir::leased_subdir( $lifecycle, self::ROOT_DIR, true, true );
        $directory = $root !== '' ? self::object_directory( $root, $artifact, true ) : '';
        if ( $directory === '' ) {
            return false;
        }
        $producer = self::acquire_lock( $directory . '/' . self::LOCK_FILENAME, true );
        if ( $producer === false ) {
            return false;
        }
        $ok = self::remove_cache_members( $directory ) && self::write_fence( $directory );
        self::release_lock( $producer );
        return $ok;
    }

    /**
     * Reclaim old deletion fences after any request authorized before artifact
     * removal has exceeded the shared orphan/request grace.
     */
    public static function gc_deleted_fences( $lifecycle, $now, $limit, $dry_run, $cursor = array() ) {
        $out = array(
            'ok' => false,
            'reason' => '',
            'scanned' => 0,
            'candidates' => 0,
            'candidate_bytes' => 0,
            'deleted' => 0,
            'deleted_bytes' => 0,
            'cursor' => array(),
        );
        if ( ! $lifecycle instanceof PrivateDirLease || ! is_int( $now ) || ! is_int( $limit ) || $limit < 1 ) {
            $out['reason'] = 'preview_fence_gc_invalid';
            return $out;
        }
        $private_dir = rtrim( $lifecycle->private_dir(), '/\\' );
        $root_path = $private_dir . '/' . self::ROOT_DIR;
        if ( ! file_exists( $root_path ) && ! is_link( $root_path ) ) {
            $out['ok'] = true;
            return $out;
        }
        $root = PrivateDir::leased_subdir( $lifecycle, self::ROOT_DIR, false, true );
        if ( $root === '' ) {
            $out['reason'] = 'preview_fence_root_invalid';
            return $out;
        }

        $cursor = is_array( $cursor ) ? $cursor : array();
        $cursor_shard = isset( $cursor['shard'] ) && is_string( $cursor['shard'] ) ? $cursor['shard'] : '';
        $cursor_identity = isset( $cursor['identity'] ) && is_string( $cursor['identity'] ) ? $cursor['identity'] : '';
        $shards = PrivateDir::bounded_entries_result( $root, '', 256, true, '/^[0-9a-f]{2}$/D' );
        if ( empty( $shards['ok'] ) ) {
            $out['reason'] = 'preview_fence_enumeration_failed';
            return $out;
        }

        foreach ( $shards['entries'] as $shard ) {
            if ( $cursor_shard !== '' && strcmp( $shard, $cursor_shard ) < 0 ) {
                continue;
            }
            $after = $shard === $cursor_shard ? $cursor_identity : '';
            $page = PrivateDir::bounded_entries_result(
                $root . '/' . $shard,
                $after,
                $limit - $out['scanned'],
                true,
                '/^[0-9a-f]{64}$/D'
            );
            if ( empty( $page['ok'] ) ) {
                $out['reason'] = 'preview_fence_enumeration_failed';
                return $out;
            }
            foreach ( $page['entries'] as $identity ) {
                $out['scanned']++;
                $out['cursor'] = array( 'shard' => $shard, 'identity' => $identity );
                if ( Helpers::h2( $identity ) !== $shard ) {
                    $out['reason'] = 'preview_fence_identity_invalid';
                    return $out;
                }
                $directory = $root . '/' . $shard . '/' . $identity;
                $candidate = self::deleted_fence_candidate( $directory, $now );
                if ( $candidate === null ) {
                    $out['reason'] = 'preview_fence_state_invalid';
                    return $out;
                }
                if ( $candidate['eligible'] ) {
                    $out['candidates']++;
                    $out['candidate_bytes'] += $candidate['bytes'];
                    if ( ! $dry_run && self::delete_fence_directory( $directory, $now ) ) {
                        $out['deleted']++;
                        $out['deleted_bytes'] += $candidate['bytes'];
                        @rmdir( $root . '/' . $shard );
                    }
                }
                if ( $out['scanned'] >= $limit ) {
                    $out['ok'] = true;
                    return $out;
                }
            }
        }
        $out['ok'] = true;
        $out['cursor'] = array();
        return $out;
    }

    public static function readiness( $concurrency, $overrides = array() ) {
        if ( ! self::valid_concurrency( $concurrency ) ) {
            return array( 'ok' => false, 'reason' => 'concurrency_invalid' );
        }
        if ( array_key_exists( 'imagick', $overrides ) ) {
            $available = ! empty( $overrides['imagick'] );
            $overrides['imagick_support'] = function () use ( $available ) {
                return $available;
            };
            $overrides['imagick_jpeg_encode'] = $available;
        }
        $memory = array_key_exists( 'memory_limit', $overrides ) ? $overrides['memory_limit'] : ini_get( 'memory_limit' );
        $execution = array_key_exists( 'execution_limit', $overrides ) ? $overrides['execution_limit'] : ini_get( 'max_execution_time' );
        $memory_bytes = self::ini_bytes( $memory );
        $execution_seconds = is_numeric( $execution ) ? (int) $execution : -1;
        if ( $memory_bytes !== -1 && $memory_bytes < Anchors::get( 'LOCAL_PREVIEW_MIN_MEMORY_BYTES' ) ) {
            return array( 'ok' => false, 'reason' => 'memory_limit', 'missing_mimes' => array(), 'missing_operations' => array() );
        }
        if ( $execution_seconds > 0 && $execution_seconds < Anchors::get( 'LOCAL_PREVIEW_MIN_EXECUTION_SECONDS' ) ) {
            return array( 'ok' => false, 'reason' => 'execution_limit', 'missing_mimes' => array(), 'missing_operations' => array() );
        }

        $missing_mimes = array();
        foreach ( UploadPolicy::staged_mimes() as $mime ) {
            if ( ! self::imagick_supports( $mime, $overrides ) ) {
                $missing_mimes[] = $mime;
            }
        }
        $missing_operations = self::imagick_encodes_jpeg( $overrides ) ? array() : array( 'jpeg_encode' );
        return empty( $missing_mimes ) && empty( $missing_operations )
            ? array( 'ok' => true )
            : array(
                'ok' => false,
                'reason' => 'imagick_unavailable',
                'missing_mimes' => $missing_mimes,
                'missing_operations' => $missing_operations,
            );
    }

    private static function encode( $source, $mime, $destination, $encoder ) {
        if ( is_callable( $encoder ) ) {
            try {
                $ok = (bool) call_user_func( $encoder, $source, $mime, $destination );
            } catch ( Throwable $error ) {
                $ok = false;
            }
            return $ok && self::valid_jpeg_file( $destination );
        }
        if ( ! class_exists( 'Imagick' ) ) {
            return false;
        }
        $image = null;
        try {
            $image = new Imagick();
            $image->readImage( ( $mime === 'image/heic' || $mime === 'image/heif' ) ? $source . '[0]' : $source );
            if ( $image->getNumberImages() !== 1 ) {
                return false;
            }
            $image->setIteratorIndex( 0 );
            self::orient( $image );
            if ( defined( 'Imagick::ORIENTATION_TOPLEFT' ) ) {
                $image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
            }
            self::to_srgb( $image );
            $image->setImageBackgroundColor( new ImagickPixel( '#ffffff' ) );
            $flattened = $image->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
            $image->clear();
            $image = $flattened;
            $image->stripImage();
            for ( $attempt = 0; $attempt < Anchors::get( 'REVIEW_PREVIEW_MAX_ATTEMPTS' ); $attempt++ ) {
                $candidate = clone $image;
                $edge = Anchors::get( 'REVIEW_PREVIEW_MAX_EDGE' ) - ( $attempt * Anchors::get( 'REVIEW_PREVIEW_EDGE_STEP' ) );
                $quality = Anchors::get( 'REVIEW_PREVIEW_JPEG_QUALITY_INITIAL' ) - ( $attempt * Anchors::get( 'REVIEW_PREVIEW_JPEG_QUALITY_STEP' ) );
                if ( max( $candidate->getImageWidth(), $candidate->getImageHeight() ) > $edge ) {
                    $candidate->thumbnailImage( $edge, $edge, true, false );
                }
                $candidate->setImageFormat( 'jpeg' );
                $candidate->setImageCompression( Imagick::COMPRESSION_JPEG );
                $candidate->setImageCompressionQuality( $quality );
                $candidate->stripImage();
                $blob = $candidate->getImageBlob();
                $candidate->clear();
                $candidate->destroy();
                $bytes = is_string( $blob ) ? strlen( $blob ) : 0;
                if ( $bytes > 0
                    && $bytes <= Anchors::get( 'REVIEW_PREVIEW_MAX_BYTES' )
                    && self::write_reserved_file( $destination, $blob )
                ) {
                    return self::valid_jpeg_file( $destination );
                }
            }
        } catch ( Throwable $error ) {
            @unlink( $destination );
        } finally {
            if ( $image instanceof Imagick ) {
                $image->clear();
                $image->destroy();
            }
        }
        return false;
    }

    private static function write_reserved_file( $path, $bytes ) {
        if ( ! is_string( $bytes )
            || $bytes === ''
            || is_link( $path )
            || ! is_file( $path )
            || @filesize( $path ) !== Anchors::get( 'REVIEW_PREVIEW_MAX_BYTES' )
        ) {
            return false;
        }
        $handle = @fopen( $path, 'r+b' );
        if ( $handle === false ) {
            return false;
        }
        $offset = 0;
        $length = strlen( $bytes );
        $ok = @flock( $handle, LOCK_EX );
        while ( $ok && $offset < $length ) {
            $written = @fwrite( $handle, substr( $bytes, $offset ) );
            $ok = is_int( $written ) && $written > 0;
            $offset += $ok ? $written : 0;
        }
        $ok = $ok && @ftruncate( $handle, $length );
        if ( $ok && function_exists( 'fflush' ) ) {
            $ok = @fflush( $handle );
        }
        if ( is_resource( $handle ) ) {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
        }
        return $ok && @chmod( $path, 0600 );
    }

    private static function object_directory( $root, $artifact, $create ) {
        $identity = hash( 'sha256', $artifact['object_key'] . "\0" . $artifact['object_version'] . "\0" . self::RECIPE_VERSION );
        $shard = Helpers::h2( $identity );
        $path = rtrim( $root, '/\\' ) . '/' . $shard;
        foreach ( array( $path, $path . '/' . $identity ) as $directory ) {
            if ( is_link( $directory ) || ( file_exists( $directory ) && ! is_dir( $directory ) ) ) {
                return '';
            }
            if ( ! is_dir( $directory ) && ( ! $create || ! @mkdir( $directory, 0700 ) ) ) {
                return '';
            }
            if ( is_link( $directory ) || ! is_dir( $directory ) || ! @chmod( $directory, 0700 ) ) {
                return '';
            }
            $path = $directory;
        }
        return $path;
    }

    private static function open_cache( $path ) {
        if ( ! self::valid_jpeg_file( $path ) ) {
            return self::unavailable( 'cache_missing' );
        }
        $stream = @fopen( $path, 'rb' );
        $stat = is_resource( $stream ) ? @fstat( $stream ) : false;
        $bytes = is_array( $stat ) && isset( $stat['size'] ) && is_int( $stat['size'] ) ? $stat['size'] : 0;
        $regular = is_array( $stat ) && isset( $stat['mode'] ) && ( $stat['mode'] & 0170000 ) === 0100000;
        if ( ! is_resource( $stream ) || is_link( $path ) || ! $regular || $bytes < 4 || $bytes > Anchors::get( 'REVIEW_PREVIEW_MAX_BYTES' ) ) {
            if ( is_resource( $stream ) ) {
                fclose( $stream );
            }
            return self::unavailable( 'cache_invalid' );
        }
        return array( 'ok' => true, 'stream' => $stream, 'bytes' => $bytes, 'mime' => 'image/jpeg' );
    }

    private static function valid_jpeg_file( $path ) {
        if ( ! is_string( $path ) || $path === '' || is_link( $path ) || ! is_file( $path ) ) {
            return false;
        }
        $bytes = @filesize( $path );
        if ( ! is_int( $bytes ) || $bytes < 4 || $bytes > Anchors::get( 'REVIEW_PREVIEW_MAX_BYTES' ) ) {
            return false;
        }
        $handle = @fopen( $path, 'rb' );
        $start = is_resource( $handle ) ? @fread( $handle, 2 ) : false;
        if ( is_resource( $handle ) ) {
            fclose( $handle );
        }
        return is_string( $start ) && $start === "\xff\xd8";
    }

    private static function acquire_slot( $root, $concurrency ) {
        $directory = rtrim( $root, '/\\' ) . '/' . self::SLOTS_DIR;
        if ( is_link( $directory ) || ( file_exists( $directory ) && ! is_dir( $directory ) ) ) {
            return false;
        }
        if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700 ) ) {
            return false;
        }
        if ( is_link( $directory ) || ! @chmod( $directory, 0700 ) ) {
            return false;
        }
        for ( $index = 0; $index < $concurrency; $index++ ) {
            $slot = self::acquire_lock( $directory . '/slot-' . $index . '.lock', true );
            if ( $slot !== false ) {
                return $slot;
            }
        }
        return false;
    }

    private static function acquire_lock( $path, $nonblocking ) {
        if ( is_link( $path ) ) {
            return false;
        }
        $handle = @fopen( $path, 'c+b' );
        $operation = LOCK_EX | ( $nonblocking ? LOCK_NB : 0 );
        if ( $handle === false || is_link( $path ) || ! @chmod( $path, 0600 ) || ! @flock( $handle, $operation ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
            return false;
        }
        $stat = @fstat( $handle );
        if ( ! is_array( $stat ) || ( $stat['mode'] & 0170000 ) !== 0100000 ) {
            self::release_lock( $handle );
            return false;
        }
        return $handle;
    }

    private static function release_lock( $handle ) {
        if ( is_resource( $handle ) ) {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }

    private static function remove_cache_members( $directory ) {
        $handle = @opendir( $directory );
        if ( $handle === false ) {
            return false;
        }
        while ( ( $entry = readdir( $handle ) ) !== false ) {
            if ( $entry === '.' || $entry === '..' || $entry === self::LOCK_FILENAME || $entry === self::DELETED_FILENAME ) {
                continue;
            }
            $path = $directory . '/' . $entry;
            if ( $entry !== self::CACHE_FILENAME && preg_match( '/^\.[0-9a-f-]+\.tmp$/D', $entry ) !== 1 ) {
                closedir( $handle );
                return false;
            }
            if ( is_link( $path ) || ! is_file( $path ) || ! @unlink( $path ) ) {
                closedir( $handle );
                return false;
            }
        }
        closedir( $handle );
        return true;
    }

    private static function write_fence( $directory ) {
        $path = $directory . '/' . self::DELETED_FILENAME;
        if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
            return false;
        }
        if ( is_file( $path ) ) {
            return @chmod( $path, 0600 );
        }
        $written = @file_put_contents( $path, "deleted\n", LOCK_EX );
        return $written === 8 && @chmod( $path, 0600 );
    }

    private static function deleted_fence_candidate( $directory, $now ) {
        if ( is_link( $directory ) || ! is_dir( $directory ) ) {
            return null;
        }
        $entries = PrivateDir::bounded_entries_result( $directory, '', 3 );
        if ( empty( $entries['ok'] ) ) {
            return null;
        }
        if ( ! in_array( self::DELETED_FILENAME, $entries['entries'], true ) ) {
            return array( 'eligible' => false, 'bytes' => 0 );
        }
        if ( $entries['entries'] !== array( self::DELETED_FILENAME, self::LOCK_FILENAME ) ) {
            return null;
        }
        $fence = $directory . '/' . self::DELETED_FILENAME;
        $lock = $directory . '/' . self::LOCK_FILENAME;
        if ( is_link( $fence ) || is_link( $lock ) || ! is_file( $fence ) || ! is_file( $lock ) ) {
            return null;
        }
        $mtime = @filemtime( $fence );
        $fence_bytes = @filesize( $fence );
        $lock_bytes = @filesize( $lock );
        if ( ! is_int( $mtime ) || ! is_int( $fence_bytes ) || ! is_int( $lock_bytes ) ) {
            return null;
        }
        return array(
            'eligible' => $now >= $mtime + Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' ),
            'bytes' => max( 0, $fence_bytes ) + max( 0, $lock_bytes ),
        );
    }

    private static function delete_fence_directory( $directory, $now ) {
        $candidate = self::deleted_fence_candidate( $directory, $now );
        if ( $candidate === null || ! $candidate['eligible'] ) {
            return false;
        }
        $lock_path = $directory . '/' . self::LOCK_FILENAME;
        $producer = self::acquire_lock( $lock_path, true );
        if ( $producer === false ) {
            return false;
        }
        $candidate = self::deleted_fence_candidate( $directory, $now );
        $fence_path = $directory . '/' . self::DELETED_FILENAME;
        $removed_fence = $candidate !== null && $candidate['eligible'] && @unlink( $fence_path );
        self::release_lock( $producer );
        if ( ! $removed_fence ) {
            return false;
        }
        return @unlink( $lock_path ) && @rmdir( $directory );
    }

    private static function deleted( $directory ) {
        $path = $directory . '/' . self::DELETED_FILENAME;
        return is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) || is_file( $path );
    }

    private static function valid_artifact( $artifact ) {
        if ( ! is_array( $artifact )
            || ! isset( $artifact['object_key'], $artifact['object_version'], $artifact['mime'] )
        ) {
            return false;
        }
        if ( ! is_string( $artifact['object_key'] )
            || preg_match( '#^artifacts/([0-9a-f]{2})/([0-9a-f]{64})$#D', $artifact['object_key'], $matches ) !== 1
        ) {
            return false;
        }
        return Helpers::h2( $matches[2] ) === $matches[1]
            && is_string( $artifact['object_version'] )
            && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $artifact['object_version'] ) === 1
            && in_array( $artifact['mime'], UploadPolicy::staged_mimes(), true );
    }

    private static function valid_concurrency( $value ) {
        return is_int( $value ) && $value >= 1 && $value <= Anchors::get( 'LOCAL_PREVIEW_CONCURRENCY_MAX' );
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


    private static function orient( $image ) {
        if ( method_exists( $image, 'autoOrientImage' ) ) {
            $image->autoOrientImage();
        } elseif ( method_exists( $image, 'autoOrient' ) ) {
            $image->autoOrient();
        } elseif ( method_exists( $image, 'autoOrientate' ) ) {
            $image->autoOrientate();
        }
    }

    private static function to_srgb( $image ) {
        $profiles = $image->getImageProfiles( 'icc', false );
        if ( ! empty( $profiles ) ) {
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

    private static function busy() {
        return array(
            'ok' => false,
            'transient' => true,
            'reason' => 'preview_busy',
            'retry_after' => Anchors::get( 'LOCAL_PREVIEW_RETRY_AFTER_SECONDS' ),
        );
    }

    private static function unavailable( $reason ) {
        return array( 'ok' => false, 'transient' => false, 'reason' => $reason );
    }
}
