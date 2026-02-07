<?php
/**
 * JSONL logging sink with rotation and retention.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

require_once __DIR__ . '/../Uploads/PrivateDir.php';

class JsonlLogger {
    const LOG_DIR = 'logs';
    const FILE_PREFIX = 'events-';
    const FILE_EXT = '.jsonl';
    const DEFAULT_MAX_BYTES = 1048576; // Internal cap; not user-configurable.

    private static $max_bytes_override = null;

    /**
     * Write one JSON event line.
     *
     * @param array $event
     * @param array $config
     * @return bool
     */
    public static function write_event( $event, $config ) {
        if ( ! is_array( $event ) ) {
            return false;
        }

        $dir = self::logs_dir( $config );
        if ( $dir === '' ) {
            return false;
        }

        $line = self::json_line( $event );
        if ( $line === '' ) {
            return false;
        }

        self::prune_old_files( $dir, self::retention_days( $config ) );
        $path = self::active_file_path( $dir, self::max_bytes() );
        if ( $path === '' ) {
            return false;
        }

        return self::append_with_rotation( $path, $line, self::max_bytes() );
    }

    /**
     * Test helper to make rotation deterministic.
     *
     * @param int|null $bytes
     * @return void
     */
    public static function set_max_bytes_for_tests( $bytes ) {
        if ( $bytes === null ) {
            self::$max_bytes_override = null;
            return;
        }

        $value = (int) $bytes;
        self::$max_bytes_override = $value > 0 ? $value : 1;
    }

    public static function reset_for_tests() {
        self::$max_bytes_override = null;
    }

    private static function logs_dir( $config ) {
        $uploads_dir = self::uploads_dir( $config );
        if ( $uploads_dir === '' || ! is_dir( $uploads_dir ) || ! is_writable( $uploads_dir ) ) {
            return '';
        }

        $private = PrivateDir::ensure( $uploads_dir );
        if ( ! is_array( $private ) || empty( $private['ok'] ) || ! isset( $private['path'] ) || ! is_string( $private['path'] ) ) {
            return '';
        }

        $dir = rtrim( $private['path'], '/\\' ) . '/' . self::LOG_DIR;
        if ( ! self::ensure_dir( $dir ) ) {
            return '';
        }

        return $dir;
    }

    private static function uploads_dir( $config ) {
        if ( is_array( $config ) && isset( $config['uploads'] ) && is_array( $config['uploads'] ) ) {
            if ( isset( $config['uploads']['dir'] ) && is_string( $config['uploads']['dir'] ) ) {
                return rtrim( $config['uploads']['dir'], '/\\' );
            }
        }

        return '';
    }

    private static function retention_days( $config ) {
        if ( is_array( $config ) && isset( $config['logging'] ) && is_array( $config['logging'] ) ) {
            if ( isset( $config['logging']['retention_days'] ) && is_numeric( $config['logging']['retention_days'] ) ) {
                $days = (int) $config['logging']['retention_days'];
                return $days > 0 ? $days : 1;
            }
        }

        return 1;
    }

    private static function max_bytes() {
        if ( is_int( self::$max_bytes_override ) && self::$max_bytes_override > 0 ) {
            return self::$max_bytes_override;
        }

        return self::DEFAULT_MAX_BYTES;
    }

    private static function active_file_path( $dir, $max_bytes ) {
        $prefix = self::FILE_PREFIX . gmdate( 'Ymd' );
        $primary = rtrim( $dir, '/\\' ) . '/' . $prefix . self::FILE_EXT;

        if ( ! file_exists( $primary ) ) {
            return $primary;
        }

        $size = @filesize( $primary );
        if ( is_numeric( $size ) && (int) $size < $max_bytes ) {
            return $primary;
        }

        return self::next_rotated_path( $dir, $prefix, self::FILE_EXT );
    }

    private static function next_rotated_path( $dir, $prefix, $ext ) {
        $index = 1;
        $base = rtrim( $dir, '/\\' ) . '/';
        while ( $index < 10000 ) {
            $candidate = $base . $prefix . '-' . $index . $ext;
            if ( ! file_exists( $candidate ) ) {
                return $candidate;
            }

            $index++;
        }

        return '';
    }

    private static function append_with_rotation( $path, $line, $max_bytes ) {
        $handle = @fopen( $path, 'ab' );
        if ( $handle === false ) {
            return false;
        }

        if ( ! flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            return false;
        }

        clearstatcache( true, $path );
        $size = @filesize( $path );
        $size = is_numeric( $size ) ? (int) $size : 0;

        if ( $size >= $max_bytes ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );

            $dir = dirname( $path );
            $basename = basename( $path );
            $ext_pos = strrpos( $basename, self::FILE_EXT );
            $prefix = $ext_pos === false ? $basename : substr( $basename, 0, $ext_pos );
            $rotated = self::next_rotated_path( $dir, $prefix, self::FILE_EXT );
            if ( $rotated === '' ) {
                return false;
            }

            return self::append_with_rotation( $rotated, $line, $max_bytes );
        }

        $written = @fwrite( $handle, $line );
        if ( function_exists( 'fflush' ) ) {
            @fflush( $handle );
        }
        @chmod( $path, 0600 );
        flock( $handle, LOCK_UN );
        fclose( $handle );

        return is_int( $written ) && $written === strlen( $line );
    }

    private static function prune_old_files( $dir, $retention_days ) {
        $entries = @scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return;
        }

        $cutoff = time() - ( (int) $retention_days * 86400 );
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            if ( strpos( $entry, self::FILE_PREFIX ) !== 0 ) {
                continue;
            }

            if ( substr( $entry, -strlen( self::FILE_EXT ) ) !== self::FILE_EXT ) {
                continue;
            }

            $path = rtrim( $dir, '/\\' ) . '/' . $entry;
            if ( ! is_file( $path ) ) {
                continue;
            }

            $mtime = @filemtime( $path );
            if ( ! is_int( $mtime ) ) {
                continue;
            }

            if ( $mtime < $cutoff ) {
                @unlink( $path );
            }
        }
    }

    private static function ensure_dir( $dir ) {
        if ( is_dir( $dir ) ) {
            return @chmod( $dir, 0700 );
        }

        $created = @mkdir( $dir, 0700, true );
        if ( ! $created && ! is_dir( $dir ) ) {
            return false;
        }

        return @chmod( $dir, 0700 );
    }

    private static function json_line( $value ) {
        if ( function_exists( 'wp_json_encode' ) ) {
            $encoded = wp_json_encode( $value );
        } else {
            $encoded = json_encode( $value );
        }

        if ( ! is_string( $encoded ) || $encoded === '' ) {
            return '';
        }

        return $encoded . "\n";
    }
}
