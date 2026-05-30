<?php
/**
 * Shared locked file-write mechanics for logging sinks.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

class FileSink {
    const DEFAULT_MAX_BYTES = 1048576; // Internal cap; not user-configurable.

    public static function json_line( $value ) {
        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
        return is_string( $encoded ) && $encoded !== '' ? $encoded . "\n" : '';
    }

    public static function dated_file_date( $entry, $prefix, $ext ) {
        if ( is_string( $entry ) && preg_match( self::dated_pattern( $prefix, $ext ), $entry, $matches ) === 1 ) {
            return $matches[1];
        }
        return '';
    }

    public static function append_dated_jsonl( $dir, $prefix, $ext, $line, $max_bytes ) {
        return self::append_with_rotation(
            self::dated_path( $dir, $prefix, $ext ),
            $line,
            $max_bytes,
            function ( $current ) use ( $prefix, $ext ) {
                return self::next_dated_path( $current, $prefix, $ext );
            }
        );
    }

    public static function append_with_rotation( $path, $line, $max_bytes, $next_path_callback, $depth = 0 ) {
        if ( ! is_string( $path ) || $path === '' || ! is_string( $line ) || ! is_callable( $next_path_callback ) ) {
            return false;
        }

        if ( $depth > 10000 ) {
            return false;
        }

        $max_bytes = is_numeric( $max_bytes ) ? (int) $max_bytes : 0;
        if ( $max_bytes <= 0 ) {
            return false;
        }

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

            $rotated = call_user_func( $next_path_callback, $path );
            if ( ! is_string( $rotated ) || $rotated === '' || $rotated === $path ) {
                return false;
            }

            return self::append_with_rotation( $rotated, $line, $max_bytes, $next_path_callback, $depth + 1 );
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

    public static function prune_old_files( $dir, $retention_days, $match_callback ) {
        if ( ! is_string( $dir ) || $dir === '' || ! is_callable( $match_callback ) ) {
            return;
        }

        $entries = @scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return;
        }

        $cutoff = time() - ( (int) $retention_days * 86400 );
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            if ( ! call_user_func( $match_callback, $entry ) ) {
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

    private static function dated_path( $dir, $prefix, $ext ) {
        return rtrim( $dir, '/\\' ) . '/' . $prefix . gmdate( 'Ymd' ) . $ext;
    }

    private static function next_dated_path( $current, $prefix, $ext ) {
        $date = self::dated_file_date( basename( $current ), $prefix, $ext );
        if ( $date === '' ) {
            return '';
        }

        $base = $prefix . $date;
        $dir = dirname( $current );
        for ( $index = 1; $index < 10000; $index++ ) {
            $candidate = rtrim( $dir, '/\\' ) . '/' . $base . '-' . $index . $ext;
            if ( ! file_exists( $candidate ) ) {
                return $candidate;
            }
        }

        return '';
    }

    private static function dated_pattern( $prefix, $ext ) {
        return '/^' . preg_quote( $prefix, '/' ) . '([0-9]{8})(?:-[0-9]+)?' . preg_quote( $ext, '/' ) . '$/';
    }
}
