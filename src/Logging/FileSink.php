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
        $cutoff = time() - ( (int) $retention_days * 86400 );
        self::delete_matching_files(
            $dir,
            $match_callback,
            function ( $entry, $path ) use ( $cutoff ) {
                $mtime = @filemtime( $path );
                return is_int( $mtime ) && $mtime < $cutoff;
            }
        );
    }

    public static function delete_matching_files( $dir, $match_callback, $eligible_callback = null, $options = array() ) {
        $summary = self::delete_summary( true, '' );
        if ( ! is_string( $dir ) || $dir === '' || ! is_callable( $match_callback ) ) {
            return self::delete_summary( false, 'invalid_args' );
        }
        if ( $eligible_callback !== null && ! is_callable( $eligible_callback ) ) {
            return self::delete_summary( false, 'invalid_args' );
        }
        $options = is_array( $options ) ? $options : array();
        $dry_run = ! empty( $options['dry_run'] );
        $limit = isset( $options['limit'] ) && is_numeric( $options['limit'] ) ? (int) $options['limit'] : 0;
        if ( $limit < 0 ) {
            $limit = 0;
        }
        if ( ! is_dir( $dir ) ) {
            return $summary;
        }

        $entries = @scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return self::delete_summary( false, 'scan_failed' );
        }

        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            $path = rtrim( $dir, '/\\' ) . '/' . $entry;
            if ( ! is_file( $path ) ) {
                continue;
            }

            if ( $limit > 0 && $summary['scanned'] >= $limit ) {
                $summary['reached_limit'] = true;
                break;
            }

            $summary['scanned']++;
            if ( ! call_user_func( $match_callback, $entry, $path ) ) {
                continue;
            }
            if ( $eligible_callback !== null && ! call_user_func( $eligible_callback, $entry, $path ) ) {
                continue;
            }

            $bytes = self::file_bytes( $path );
            $summary['candidates']++;
            $summary['candidate_bytes'] += $bytes;
            if ( $dry_run ) {
                continue;
            }

            if ( @unlink( $path ) ) {
                $summary['deleted']++;
                $summary['deleted_bytes'] += $bytes;
            } else {
                $summary['failed']++;
            }
        }

        if ( $summary['failed'] > 0 ) {
            $summary['ok'] = false;
            $summary['reason'] = 'delete_failed';
        }

        return $summary;
    }

    private static function delete_summary( $ok, $reason ) {
        return array(
            'ok' => (bool) $ok,
            'reason' => (string) $reason,
            'scanned' => 0,
            'candidates' => 0,
            'candidate_bytes' => 0,
            'deleted' => 0,
            'deleted_bytes' => 0,
            'failed' => 0,
            'reached_limit' => false,
        );
    }

    private static function file_bytes( $path ) {
        $size = @filesize( $path );
        return is_int( $size ) && $size > 0 ? $size : 0;
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
