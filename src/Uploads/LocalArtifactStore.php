<?php
/**
 * Private write-once local artifact persistence.
 *
 * Aggregate authority stays in UploadBatchStore. This owner only maps one
 * canonical object key and immutable local version to private bytes.
 */

require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Security/Entropy.php';
require_once __DIR__ . '/ManagedArtifactKey.php';
require_once __DIR__ . '/PrivateDir.php';

final class LocalArtifactStore {
    const ROOT_DIR = ManagedArtifactKey::ROOT_DIR;
    const LOCK_FILENAME = '.lock';
    const DELETED_FILENAME = '.deleted';

    public static function write( $lifecycle, $object_key, $source_path, $expected_bytes ) {
        if ( ! $lifecycle instanceof PrivateDirLease
            || ! is_string( $source_path )
            || $source_path === ''
            || is_link( $source_path )
            || ! is_file( $source_path )
            || ! is_int( $expected_bytes )
            || $expected_bytes < 1
        ) {
            return self::failure( 'artifact_source_invalid' );
        }
        $parts = self::key_parts( $object_key );
        if ( $parts === null ) {
            return self::failure( 'artifact_key_invalid' );
        }
        $root_result = self::leased_root_result( $lifecycle, true );
        if ( $root_result === null || empty( $root_result['exists'] ) ) {
            return self::failure( 'artifact_root_unavailable' );
        }
        $root = $root_result['path'];
        $directory = self::ensure_object_directory( $root, $parts );
        if ( $directory === '' ) {
            return self::failure( 'artifact_directory_unavailable' );
        }
        $lock = self::acquire_object_lock( $directory );
        if ( $lock === false ) {
            return self::failure( 'artifact_lock_failed' );
        }

        $summary = self::directory_summary( $directory, $parts );
        if ( $summary === false ) {
            self::release_object_lock( $lock );
            return self::failure( 'artifact_layout_invalid' );
        }
        if ( ! empty( $summary['deleted'] ) ) {
            self::release_object_lock( $lock );
            return self::failure( 'artifact_deleted' );
        }
        $existing = $summary['artifact'];
        if ( is_array( $existing ) ) {
            $result = $existing['bytes'] === $expected_bytes
                ? self::success( $existing + array( 'object_key' => $object_key, 'existing' => true ) )
                : self::failure( 'artifact_write_conflict' );
            self::release_object_lock( $lock );
            return $result;
        }
        if ( ! self::remove_temps( $directory ) ) {
            self::release_object_lock( $lock );
            return self::failure( 'artifact_cleanup_failed' );
        }

        $version = Entropy::uuid_v4();
        if ( $version === '' ) {
            self::release_object_lock( $lock );
            return self::failure( 'artifact_version_failed' );
        }
        $temp = $directory . '/.' . $version . '.tmp';
        $destination = $directory . '/' . $version . '.' . $parts['extension'];
        $input = @fopen( $source_path, 'rb' );
        $output = @fopen( $temp, 'xb' );
        if ( $input === false || $output === false ) {
            if ( is_resource( $input ) ) {
                fclose( $input );
            }
            if ( is_resource( $output ) ) {
                fclose( $output );
            }
            @unlink( $temp );
            self::release_object_lock( $lock );
            return self::failure( 'artifact_write_failed' );
        }

        $written = 0;
        $ok = true;
        while ( ! feof( $input ) ) {
            $chunk = fread( $input, 8192 );
            if ( ! is_string( $chunk ) ) {
                $ok = false;
                break;
            }
            if ( $chunk === '' ) {
                continue;
            }
            $length = strlen( $chunk );
            if ( $written > $expected_bytes - $length ) {
                $ok = false;
                break;
            }
            $offset = 0;
            while ( $offset < $length ) {
                $one = @fwrite( $output, substr( $chunk, $offset ) );
                if ( ! is_int( $one ) || $one < 1 ) {
                    $ok = false;
                    break 2;
                }
                $offset += $one;
            }
            $written += $length;
        }
        $ok = $ok && $written === $expected_bytes && ( ! function_exists( 'fflush' ) || @fflush( $output ) );
        fclose( $input );
        fclose( $output );
        if ( ! $ok || ! @chmod( $temp, PrivateDir::FILE_MODE ) || ! @rename( $temp, $destination ) || ! @chmod( $destination, PrivateDir::FILE_MODE ) ) {
            @unlink( $temp );
            @unlink( $destination );
            self::release_object_lock( $lock );
            return self::failure( 'artifact_write_failed' );
        }

        $result = self::success(
            array(
                'object_key' => $object_key,
                'object_version' => $version,
                'path' => $destination,
                'bytes' => $written,
                'existing' => false,
            )
        );
        self::release_object_lock( $lock );
        return $result;
    }

    public static function locate( $uploads_dir, $object_key, $object_version, $lifecycle = null ) {
        $parts = self::key_parts( $object_key );
        if ( $parts === null || ! self::valid_version( $object_version ) ) {
            return '';
        }
        $owns_lifecycle = false;
        if ( $lifecycle === null ) {
            $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
            $owns_lifecycle = true;
        }
        if ( ! $lifecycle instanceof PrivateDirLease
            || rtrim( $lifecycle->private_dir(), '/\\' ) !== rtrim( PrivateDir::path( $uploads_dir ), '/\\' )
        ) {
            if ( $owns_lifecycle && $lifecycle instanceof PrivateDirLease ) {
                $lifecycle->release();
            }
            return '';
        }
        try {
            $root_result = self::root_result( $uploads_dir );
            if ( $root_result === null || empty( $root_result['exists'] ) ) {
                return '';
            }
            $root = $root_result['path'];
            $directory = self::object_directory( $root, $parts );
            $path = $directory . '/' . $object_version . '.' . $parts['extension'];
            if ( ! self::object_path_valid( $root, $parts ) || is_link( $path ) || ! is_file( $path ) ) {
                return '';
            }
            foreach ( self::review_directories( $root, $parts ) as $review_directory ) {
                if ( ! PrivateDir::ensure_existing_review_directory( $review_directory ) ) {
                    return '';
                }
            }
            if ( ! PrivateDir::ensure_existing_private_file( $directory . '/' . self::LOCK_FILENAME )
                || ! PrivateDir::ensure_existing_review_file( $path )
            ) {
                return '';
            }
            return $path;
        } finally {
            if ( $owns_lifecycle ) {
                $lifecycle->release();
            }
        }
    }

    public static function delete( $lifecycle, $object_key, $object_version = '' ) {
        return self::remove( $lifecycle, $object_key, $object_version );
    }

    private static function remove( $lifecycle, $object_key, $object_version ) {
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return false;
        }
        $parts = self::key_parts( $object_key );
        if ( $parts === null || ( $object_version !== '' && ! self::valid_version( $object_version ) ) ) {
            return false;
        }
        $root_result = self::leased_root_result( $lifecycle, true );
        if ( $root_result === null || empty( $root_result['exists'] ) ) {
            return false;
        }
        $root = $root_result['path'];
        $directory = self::object_directory( $root, $parts );
        if ( ! self::object_parent_valid_for_create( $root, $parts ) || is_link( $directory ) ) {
            return false;
        }
        if ( ! file_exists( $directory ) ) {
            $directory = self::ensure_object_directory( $root, $parts );
            if ( $directory === '' ) {
                return false;
            }
        }
        if ( ! is_dir( $directory ) ) {
            return false;
        }
        $lock = self::acquire_object_lock( $directory );
        if ( $lock === false ) {
            return false;
        }
        if ( ! self::remove_temps( $directory ) ) {
            self::release_object_lock( $lock );
            return false;
        }
        $summary = self::directory_summary( $directory );
        if ( $summary === false ) {
            self::release_object_lock( $lock );
            return false;
        }
        $existing = $summary['artifact'];
        if ( is_array( $existing ) ) {
            if ( $object_version !== '' && ! hash_equals( $object_version, $existing['object_version'] ) ) {
                self::release_object_lock( $lock );
                return false;
            }
            if ( ! @unlink( $existing['path'] ) ) {
                self::release_object_lock( $lock );
                return false;
            }
        }
        // Aggregate expiry cannot prove a previously authorized writer has
        // reached this lock. Normal deletion therefore never removes the
        // fence; only an exclusive whole-root lifecycle purge may do so.
        $fenced = self::ensure_deletion_fence( $directory );
        self::release_object_lock( $lock );
        return $fenced;
    }

    public static function bytes_for_key( $uploads_dir, $object_key ) {
        $parts = self::key_parts( $object_key );
        if ( $parts === null ) {
            return null;
        }
        $root_result = self::root_result( $uploads_dir );
        if ( $root_result === null ) {
            return null;
        }
        if ( empty( $root_result['exists'] ) ) {
            return 0;
        }
        $root = $root_result['path'];
        $directory = self::object_directory( $root, $parts );
        if ( ! self::object_parent_valid_for_create( $root, $parts ) || is_link( $directory ) ) {
            return null;
        }
        if ( ! is_dir( rtrim( $root, '/\\' ) . '/' . $parts['shard'] ) ) {
            return 0;
        }
        if ( ! file_exists( $directory ) ) {
            return 0;
        }
        if ( ! is_dir( $directory ) ) {
            return null;
        }
        $summary = self::directory_summary( $directory, $parts );
        if ( $summary === false || ( $summary['bytes'] === 0 && $summary['has_temp'] ) ) {
            return null;
        }
        return $summary['bytes'];
    }

    public static function total_bytes( $uploads_dir ) {
        $root_result = self::root_result( $uploads_dir );
        if ( $root_result === null ) {
            return null;
        }
        if ( empty( $root_result['exists'] ) ) {
            return 0;
        }
        return self::scan_root( $root_result['path'] );
    }

    public static function reconcile_bytes( $lifecycle, $stale_before ) {
        if ( ! $lifecycle instanceof PrivateDirLease || ! is_int( $stale_before ) || $stale_before < 0 ) {
            return null;
        }
        $root_result = self::leased_root_result( $lifecycle, false );
        if ( $root_result === null ) {
            return null;
        }
        if ( empty( $root_result['exists'] ) ) {
            return 0;
        }
        return self::scan_root( $root_result['path'], $stale_before );
    }

    private static function root_result( $uploads_dir ) {
        $private = PrivateDir::path( $uploads_dir );
        if ( $private === '' || is_link( $private ) || ( file_exists( $private ) && ! is_dir( $private ) ) ) {
            return null;
        }
        if ( ! file_exists( $private ) ) {
            return array( 'exists' => false, 'path' => '' );
        }
        $path = rtrim( $private, '/\\' ) . '/' . self::ROOT_DIR;
        if ( is_link( $path ) || ( file_exists( $path ) && ! is_dir( $path ) ) ) {
            return null;
        }
        if ( ! file_exists( $path ) ) {
            return array( 'exists' => false, 'path' => '' );
        }
        $protected = PrivateDir::existing_protected_review_subdir( $uploads_dir, self::ROOT_DIR );
        return $protected === '' ? null : array( 'exists' => true, 'path' => $protected );
    }

    private static function leased_root_result( $lifecycle, $create ) {
        $existing = PrivateDir::leased_existing_review_relative_dir_result( $lifecycle, self::ROOT_DIR );
        if ( empty( $existing['ok'] ) ) {
            return null;
        }
        if ( empty( $existing['exists'] ) && ! $create ) {
            return array( 'exists' => false, 'path' => '' );
        }
        $path = PrivateDir::leased_review_subdir( $lifecycle, self::ROOT_DIR, (bool) $create, true );
        return $path === '' ? null : array( 'exists' => true, 'path' => $path );
    }

    private static function ensure_object_directory( $root, $parts ) {
        $shard = $root . '/' . $parts['shard'];
        if ( is_link( $shard ) || ( file_exists( $shard ) && ! is_dir( $shard ) ) ) {
            return '';
        }
        if ( ! is_dir( $shard ) && ! @mkdir( $shard, PrivateDir::DIRECTORY_MODE ) && ! is_dir( $shard ) ) {
            return '';
        }
        if ( is_link( $shard ) || ! is_dir( $shard ) || ! @chmod( $shard, PrivateDir::DIRECTORY_MODE ) ) {
            return '';
        }
        $directory = $shard;
        foreach ( array( $parts['namespace'], $parts['filename'] ) as $entry ) {
            $directory .= '/' . $entry;
            if ( is_link( $directory ) || ( file_exists( $directory ) && ! is_dir( $directory ) ) ) {
                return '';
            }
            if ( ! is_dir( $directory ) && ! @mkdir( $directory, PrivateDir::DIRECTORY_MODE ) && ! is_dir( $directory ) ) {
                return '';
            }
            if ( is_link( $directory ) || ! is_dir( $directory ) || ! @chmod( $directory, PrivateDir::DIRECTORY_MODE ) ) {
                return '';
            }
        }
        return $directory;
    }

    private static function remove_temps( $directory, $stale_before = null ) {
        $handle = @opendir( $directory );
        if ( $handle === false ) {
            return false;
        }
        while ( ( $entry = readdir( $handle ) ) !== false ) {
            $version = self::temp_version( $entry );
            if ( $version === '' ) {
                continue;
            }
            $path = $directory . '/' . $entry;
            $modified = $stale_before === null ? null : @filemtime( $path );
            if ( $stale_before !== null && ( ! is_int( $modified ) || $modified > $stale_before ) ) {
                continue;
            }
            if ( is_link( $path ) || ! is_file( $path ) || ! @unlink( $path ) ) {
                closedir( $handle );
                return false;
            }
        }
        closedir( $handle );
        return true;
    }

    private static function ensure_deletion_fence( $directory ) {
        $path = $directory . '/' . self::DELETED_FILENAME;
        if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
            return false;
        }
        if ( is_file( $path ) ) {
            return @chmod( $path, PrivateDir::FILE_MODE );
        }
        $handle = @fopen( $path, 'xb' );
        if ( $handle === false ) {
            return false;
        }
        $written = @fwrite( $handle, "deleted\n" );
        $flushed = ! function_exists( 'fflush' ) || @fflush( $handle );
        fclose( $handle );
        if ( $written !== 8 || ! $flushed || ! @chmod( $path, PrivateDir::FILE_MODE ) ) {
            @unlink( $path );
            return false;
        }
        return true;
    }

    private static function acquire_object_lock( $directory, $nonblocking = false ) {
        $path = $directory . '/' . self::LOCK_FILENAME;
        if ( is_link( $path ) ) {
            return false;
        }
        $handle = @fopen( $path, 'c+b' );
        $operation = LOCK_EX | ( $nonblocking ? LOCK_NB : 0 );
        if ( $handle === false || is_link( $path ) || ! @chmod( $path, PrivateDir::FILE_MODE ) || ! @flock( $handle, $operation ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
            return false;
        }
        $stat = @fstat( $handle );
        if ( ! is_array( $stat ) || ( $stat['mode'] & 0170000 ) !== 0100000 ) {
            self::release_object_lock( $handle );
            return false;
        }
        return $handle;
    }

    private static function release_object_lock( $handle ) {
        if ( is_resource( $handle ) ) {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }

    private static function scan_root( $root, $stale_before = null ) {
        $total = 0;
        $shards = @opendir( $root );
        if ( $shards === false ) {
            return null;
        }
        while ( ( $shard = readdir( $shards ) ) !== false ) {
            if ( $shard === '.' || $shard === '..' || self::is_protection_file( $shard ) ) {
                continue;
            }
            $shard_path = $root . '/' . $shard;
            if ( preg_match( '/^[0-9a-f]{2}$/D', $shard ) !== 1 || is_link( $shard_path ) || ! is_dir( $shard_path ) ) {
                closedir( $shards );
                return null;
            }
            $namespaces = @opendir( $shard_path );
            if ( $namespaces === false ) {
                closedir( $shards );
                return null;
            }
            while ( ( $namespace = readdir( $namespaces ) ) !== false ) {
                if ( $namespace === '.' || $namespace === '..' ) {
                    continue;
                }
                $namespace_path = $shard_path . '/' . $namespace;
                if ( is_link( $namespace_path ) || ! is_dir( $namespace_path ) ) {
                    closedir( $namespaces );
                    closedir( $shards );
                    return null;
                }
                if ( ! ManagedArtifactKey::valid_digest( $namespace ) || Helpers::h2( $namespace ) !== $shard ) {
                    closedir( $namespaces );
                    closedir( $shards );
                    return null;
                }
                $objects = @opendir( $namespace_path );
                if ( $objects === false ) {
                    closedir( $namespaces );
                    closedir( $shards );
                    return null;
                }
                while ( ( $filename = readdir( $objects ) ) !== false ) {
                    if ( $filename === '.' || $filename === '..' ) {
                        continue;
                    }
                    $object_key = self::ROOT_DIR . '/' . $shard . '/' . $namespace . '/' . $filename;
                    $directory = $namespace_path . '/' . $filename;
                    $parts = ManagedArtifactKey::parse( $object_key );
                    if ( $parts === null || is_link( $directory ) || ! is_dir( $directory ) ) {
                        closedir( $objects );
                        closedir( $namespaces );
                        closedir( $shards );
                        return null;
                    }
                    $summary = self::scan_one_directory( $directory, $parts, $stale_before );
                    if ( $summary === null || $total > PHP_INT_MAX - $summary ) {
                        closedir( $objects );
                        closedir( $namespaces );
                        closedir( $shards );
                        return null;
                    }
                    $total += $summary;
                }
                closedir( $objects );
            }
            closedir( $namespaces );
        }
        closedir( $shards );
        return $total;
    }

    private static function scan_one_directory( $directory, $parts, $stale_before ) {
        $lock = null;
        if ( $stale_before !== null ) {
            $lock = self::acquire_object_lock( $directory, true );
            if ( $lock === false || ! self::remove_temps( $directory, $stale_before ) ) {
                self::release_object_lock( $lock );
                return null;
            }
        }
        $summary = self::directory_summary( $directory, $parts );
        self::release_object_lock( $lock );
        return $summary === false ? null : $summary['bytes'];
    }

    private static function directory_summary( $directory, $parts = null ) {
        if ( is_link( $directory ) || ! is_dir( $directory ) ) {
            return false;
        }
        $parts = $parts === null
            ? ManagedArtifactKey::parse(
                self::ROOT_DIR
                . '/' . basename( dirname( dirname( $directory ) ) )
                . '/' . basename( dirname( $directory ) )
                . '/' . basename( $directory )
            )
            : $parts;
        if ( $parts === null ) {
            return false;
        }
        $handle = @opendir( $directory );
        if ( $handle === false ) {
            return false;
        }
        $artifact = null;
        $bytes = 0;
        $has_temp = false;
        $deleted = false;
        while ( ( $entry = readdir( $handle ) ) !== false ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $path = $directory . '/' . $entry;
            if ( $entry === self::LOCK_FILENAME ) {
                if ( is_link( $path ) || ! is_file( $path ) ) {
                    closedir( $handle );
                    return false;
                }
                continue;
            }
            if ( $entry === self::DELETED_FILENAME ) {
                if ( is_link( $path ) || ! is_file( $path ) ) {
                    closedir( $handle );
                    return false;
                }
                $deleted = true;
                continue;
            }
            $temp_version = self::temp_version( $entry );
            $artifact_version = self::artifact_version_for_extension( $entry, $parts['extension'] );
            if ( $temp_version === '' && $artifact_version === '' ) {
                closedir( $handle );
                return false;
            }
            $one = ! is_link( $path ) && is_file( $path ) ? @filesize( $path ) : false;
            if ( ! is_int( $one ) || $one < ( $artifact_version === '' ? 0 : 1 ) || $bytes > PHP_INT_MAX - $one ) {
                closedir( $handle );
                return false;
            }
            $bytes += $one;
            $has_temp = $has_temp || $temp_version !== '';
            if ( $artifact_version !== '' ) {
                if ( $artifact !== null ) {
                    closedir( $handle );
                    return false;
                }
                $artifact = array(
                    'object_version' => $artifact_version,
                    'path' => $path,
                    'bytes' => $one,
                );
            }
        }
        closedir( $handle );
        if ( $deleted && ( $artifact !== null || $has_temp ) ) {
            return false;
        }
        return array( 'artifact' => $artifact, 'bytes' => $bytes, 'has_temp' => $has_temp, 'deleted' => $deleted );
    }

    private static function temp_version( $entry ) {
        return is_string( $entry )
            && preg_match( '/^\.([0-9a-f-]+)\.tmp$/D', $entry, $matches ) === 1
            && self::valid_version( $matches[1] )
                ? $matches[1]
                : '';
    }

    private static function artifact_version_for_extension( $entry, $extension ) {
        return is_string( $entry )
            && is_string( $extension )
            && preg_match( '/^([0-9a-f-]+)\.' . preg_quote( $extension, '/' ) . '$/D', $entry, $matches ) === 1
            && self::valid_version( $matches[1] )
                ? $matches[1]
                : '';
    }

    private static function key_parts( $object_key ) {
        return ManagedArtifactKey::parse( $object_key );
    }

    private static function object_directory( $root, $parts ) {
        $shard = rtrim( $root, '/\\' ) . '/' . $parts['shard'];
        return $shard . '/' . $parts['namespace'] . '/' . $parts['filename'];
    }

    private static function review_directories( $root, $parts ) {
        $shard = rtrim( $root, '/\\' ) . '/' . $parts['shard'];
        $namespace = $shard . '/' . $parts['namespace'];
        return array( $root, $shard, $namespace, $namespace . '/' . $parts['filename'] );
    }

    private static function object_path_valid( $root, $parts ) {
        foreach ( self::review_directories( $root, $parts ) as $directory ) {
            if ( is_link( $directory ) || ! is_dir( $directory ) ) {
                return false;
            }
        }
        return true;
    }

    private static function object_parent_valid_for_create( $root, $parts ) {
        $shard = rtrim( $root, '/\\' ) . '/' . $parts['shard'];
        if ( is_link( $shard ) || ( file_exists( $shard ) && ! is_dir( $shard ) ) ) {
            return false;
        }
        $namespace = $shard . '/' . $parts['namespace'];
        return ! is_link( $namespace ) && ( ! file_exists( $namespace ) || is_dir( $namespace ) );
    }

    private static function valid_version( $version ) {
        return is_string( $version )
            && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $version ) === 1;
    }

    private static function is_protection_file( $entry ) {
        return in_array(
            $entry,
            array( PrivateDir::INDEX_FILENAME, PrivateDir::HTACCESS_FILENAME, PrivateDir::WEBCONFIG_FILENAME ),
            true
        );
    }

    private static function success( $extra = array() ) {
        return array_merge( array( 'ok' => true ), $extra );
    }

    private static function failure( $reason ) {
        return array( 'ok' => false, 'code' => 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'reason' => $reason );
    }
}
