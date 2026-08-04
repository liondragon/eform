<?php
/**
 * Shared locked file-write mechanics for logging sinks.
 *
 * Contract: Logging
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

        if ( ! self::existing_path_is_regular_file( $path ) ) {
            return false;
        }

        $old_umask = umask( 0177 );
        $handle = @fopen( $path, 'c+b' );
        umask( $old_umask );
        if ( $handle === false ) {
            return false;
        }

        if ( ! flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            return false;
        }

        if ( ! self::opened_regular_path( $handle, $path ) ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );
            return false;
        }
        if ( ! self::enforce_open_file_mode( $handle ) ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );
            return false;
        }
        $stat = @fstat( $handle );
        $size = is_array( $stat ) && isset( $stat['size'] ) && is_numeric( $stat['size'] ) ? (int) $stat['size'] : 0;

        if ( $size >= $max_bytes ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );

            $rotated = call_user_func( $next_path_callback, $path );
            if ( ! is_string( $rotated ) || $rotated === '' || $rotated === $path ) {
                return false;
            }

            return self::append_with_rotation( $rotated, $line, $max_bytes, $next_path_callback, $depth + 1 );
        }

        if ( @fseek( $handle, 0, SEEK_END ) !== 0 ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );
            return false;
        }

        $written = @fwrite( $handle, $line );
        if ( function_exists( 'fflush' ) ) {
            @fflush( $handle );
        }
        flock( $handle, LOCK_UN );
        fclose( $handle );

        return is_int( $written ) && $written === strlen( $line );
    }

    private static function opened_regular_path( $handle, $path ) {
        clearstatcache( true, $path );
        if ( is_link( $path ) ) {
            return false;
        }
        $path_stat = @stat( $path );
        $open_stat = @fstat( $handle );
        if ( ! is_array( $path_stat ) || ! is_array( $open_stat ) ) {
            return false;
        }
        if ( isset( $open_stat['mode'] ) && ( (int) $open_stat['mode'] & 0170000 ) !== 0100000 ) {
            return false;
        }
        foreach ( array( 'dev', 'ino' ) as $key ) {
            if ( isset( $path_stat[ $key ], $open_stat[ $key ] ) && (string) $path_stat[ $key ] !== (string) $open_stat[ $key ] ) {
                return false;
            }
        }
        return true;
    }

    private static function existing_path_is_regular_file( $path ) {
        clearstatcache( true, $path );
        $stat = @lstat( $path );
        if ( ! is_array( $stat ) ) {
            return ! file_exists( $path ) && ! is_link( $path );
        }
        return isset( $stat['mode'] ) && ( (int) $stat['mode'] & 0170000 ) === 0100000;
    }

    private static function enforce_open_file_mode( $handle ) {
        if ( function_exists( 'fchmod' ) && ! @fchmod( $handle, 0600 ) ) {
            return false;
        }
        $stat = @fstat( $handle );
        return is_array( $stat )
            && isset( $stat['mode'] )
            && ( (int) $stat['mode'] & 0777 ) === 0600;
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
        $cursor = isset( $options['cursor'] ) && is_array( $options['cursor'] ) ? $options['cursor'] : array();
        $after_entry = isset( $cursor['entry'] ) && is_string( $cursor['entry'] ) ? $cursor['entry'] : '';
        $last_entry = $after_entry;
        if ( $limit < 0 ) {
            $limit = 0;
        }
        if ( ! is_dir( $dir ) ) {
            return $summary;
        }

        if ( $limit > 0 ) {
            $entries = self::bounded_entries_after_cursor( $dir, $after_entry, $limit, $summary );
            if ( $entries === null ) {
                return self::delete_summary( false, 'scan_failed' );
            }

            foreach ( $entries as $entry ) {
                $path = rtrim( $dir, '/\\' ) . '/' . $entry;
                if ( ! is_file( $path ) ) {
                    continue;
                }
                self::delete_matching_entry( $entry, $path, $match_callback, $eligible_callback, $dry_run, $summary, $last_entry );
            }
        } else {
            $handle = @opendir( $dir );
            if ( $handle === false ) {
                return self::delete_summary( false, 'scan_failed' );
            }

            while ( ( $entry = readdir( $handle ) ) !== false ) {
                if ( $entry === '.' || $entry === '..' ) {
                    continue;
                }

                // A filename cursor remains valid even when the previously scanned
                // file was deleted, so bounded cleanup can resume without an index.
                if ( $after_entry !== '' && strcmp( $entry, $after_entry ) <= 0 ) {
                    continue;
                }

                $path = rtrim( $dir, '/\\' ) . '/' . $entry;
                if ( ! is_file( $path ) ) {
                    continue;
                }
                self::delete_matching_entry( $entry, $path, $match_callback, $eligible_callback, $dry_run, $summary, $last_entry );
            }
            closedir( $handle );
        }

        if ( $summary['failed'] > 0 ) {
            $summary['ok'] = false;
            $summary['reason'] = 'delete_failed';
        }

        $summary['cursor'] = $summary['reached_limit'] && $last_entry !== ''
            ? array( 'entry' => $last_entry )
            : array();

        return $summary;
    }

    private static function delete_matching_entry( $entry, $path, $match_callback, $eligible_callback, $dry_run, &$summary, &$last_entry ) {
        $summary['scanned']++;
        $last_entry = $entry;
        if ( ! call_user_func( $match_callback, $entry, $path ) ) {
            return;
        }
        if ( $eligible_callback !== null && ! call_user_func( $eligible_callback, $entry, $path ) ) {
            return;
        }

        $bytes = self::file_bytes( $path );
        $summary['candidates']++;
        $summary['candidate_bytes'] += $bytes;
        if ( $dry_run ) {
            return;
        }

        if ( @unlink( $path ) ) {
            $summary['deleted']++;
            $summary['deleted_bytes'] += $bytes;
        } else {
            $summary['failed']++;
        }
    }

    private static function bounded_entries_after_cursor( $dir, $after_entry, $limit, &$summary ) {
        $handle = @opendir( $dir );
        if ( $handle === false ) {
            return null;
        }

        $entries = array();
        $limit = (int) $limit;
        while ( ( $entry = readdir( $handle ) ) !== false ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            // A filename cursor remains valid even when the previously scanned
            // file was deleted, so bounded cleanup can resume without an index.
            if ( $after_entry !== '' && strcmp( $entry, $after_entry ) <= 0 ) {
                continue;
            }

            $path = rtrim( $dir, '/\\' ) . '/' . $entry;
            if ( ! is_file( $path ) ) {
                continue;
            }

            if ( count( $entries ) < $limit ) {
                $entries[] = $entry;
                continue;
            }

            $largest = self::largest_entry( $entries );
            if ( $largest !== '' && strcmp( $entry, $largest ) < 0 ) {
                $entries[ array_search( $largest, $entries, true ) ] = $entry;
                $summary['reached_limit'] = true;
                continue;
            }

            $summary['reached_limit'] = true;
        }
        closedir( $handle );

        sort( $entries, SORT_STRING );
        return $entries;
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
            'cursor' => array(),
        );
    }

    private static function largest_entry( $entries ) {
        $largest = '';
        foreach ( $entries as $entry ) {
            if ( is_string( $entry ) && ( $largest === '' || strcmp( $entry, $largest ) > 0 ) ) {
                $largest = $entry;
            }
        }

        return $largest;
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
