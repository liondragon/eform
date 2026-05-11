<?php
/**
 * Shared locked file-write mechanics for logging sinks.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

class FileSink {
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
}
