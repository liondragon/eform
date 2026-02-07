<?php
/**
 * Garbage-collection runner for runtime artifacts.
 *
 * Spec: Uploads (docs/Canonical_Spec.md#sec-uploads)
 * Spec: Throttling (docs/Canonical_Spec.md#sec-throttling)
 * Spec: Anchors (docs/Canonical_Spec.md#sec-anchors)
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';

class GcRunner {
    const LOCK_FILENAME = 'gc.lock';

    const TOKENS_DIR = 'tokens';
    const LEDGER_DIR = 'ledger';
    const UPLOADS_DIR = 'uploads';
    const THROTTLE_DIR = 'throttle';

    const TOKEN_SUFFIX = '.json';
    const LEDGER_SUFFIX = '.used';
    const THROTTLE_TALLY_SUFFIX = '.tally';
    const THROTTLE_COOLDOWN_SUFFIX = '.cooldown';

    const THROTTLE_STALE_SECONDS = 172800; // 2 days.
    const DEFAULT_BATCH_LIMIT = 500;

    /**
     * Run one GC batch.
     *
     * @param array $options {dry_run?:bool, limit?:int, now?:int}
     * @return array
     */
    public static function run( $options = array() ) {
        $summary = self::summary_template( $options );
        $config = Config::get();
        $uploads_dir = self::uploads_dir( $config );
        if ( $uploads_dir === '' || ! is_dir( $uploads_dir ) || ! is_writable( $uploads_dir ) ) {
            $summary['reason'] = 'uploads_dir_unavailable';
            self::emit_summary_log( $summary );
            return $summary;
        }

        $private = PrivateDir::ensure( $uploads_dir );
        if ( ! is_array( $private ) || empty( $private['ok'] ) ) {
            $summary['reason'] = is_array( $private ) && isset( $private['error'] ) ? $private['error'] : 'private_dir_unavailable';
            self::emit_summary_log( $summary );
            return $summary;
        }

        $private_dir = $private['path'];
        $summary['private_dir'] = $private_dir;

        $lock = self::acquire_lock( $private_dir );
        $summary['lock_path'] = $lock['path'];
        if ( empty( $lock['ok'] ) ) {
            $summary['locked'] = ! empty( $lock['locked'] );
            $summary['reason'] = isset( $lock['reason'] ) ? $lock['reason'] : 'gc_lock_failed';
            self::emit_summary_log( $summary );
            return $summary;
        }

        $handle = $lock['handle'];
        try {
            self::run_targets( $private_dir, $config, $summary );
            $summary['ok'] = true;
            $summary['reason'] = '';
        } finally {
            self::release_lock( $handle );
        }

        self::emit_summary_log( $summary );
        return $summary;
    }

    private static function summary_template( $options ) {
        $dry_run = self::option_bool( $options, 'dry_run', false );
        $limit = self::option_int( $options, 'limit', self::DEFAULT_BATCH_LIMIT );
        $now = self::option_int( $options, 'now', time() );
        if ( $limit < 1 ) {
            $limit = self::DEFAULT_BATCH_LIMIT;
        }

        return array(
            'ok' => false,
            'dry_run' => $dry_run,
            'locked' => false,
            'reason' => '',
            'private_dir' => '',
            'lock_path' => '',
            'limit' => $limit,
            'now' => $now,
            'reached_limit' => false,
            'scanned' => 0,
            'candidates' => 0,
            'candidate_bytes' => 0,
            'deleted' => 0,
            'deleted_bytes' => 0,
            'by_type' => array(
                'tokens' => self::target_template(),
                'ledger' => self::target_template(),
                'uploads' => self::target_template(),
                'throttle' => self::target_template(),
            ),
        );
    }

    private static function target_template() {
        return array(
            'scanned' => 0,
            'candidates' => 0,
            'candidate_bytes' => 0,
            'deleted' => 0,
            'deleted_bytes' => 0,
        );
    }

    private static function run_targets( $private_dir, $config, &$summary ) {
        $now = (int) $summary['now'];

        self::scan_tokens( $private_dir, $now, $summary );
        if ( $summary['reached_limit'] ) {
            return;
        }

        $token_ttl_max = self::anchor_value( 'TOKEN_TTL_MAX', 86400 );
        $ledger_grace = self::anchor_value( 'LEDGER_GC_GRACE_SECONDS', 3600 );
        self::scan_ledger( $private_dir, $now, $token_ttl_max + $ledger_grace, $summary );
        if ( $summary['reached_limit'] ) {
            return;
        }

        $retention_seconds = self::uploads_retention_seconds( $config );
        if ( $retention_seconds > 0 ) {
            self::scan_uploads( $private_dir, $now, $retention_seconds, $summary );
            if ( $summary['reached_limit'] ) {
                return;
            }
        }

        self::scan_throttle( $private_dir, $now, $summary );
    }

    private static function scan_tokens( $private_dir, $now, &$summary ) {
        $tokens_dir = rtrim( $private_dir, '/\\' ) . '/' . self::TOKENS_DIR;
        self::scan_files(
            $tokens_dir,
            'tokens',
            $summary,
            function ( $path ) use ( $now ) {
                if ( substr( $path, -strlen( self::TOKEN_SUFFIX ) ) !== self::TOKEN_SUFFIX ) {
                    return false;
                }

                $raw = @file_get_contents( $path );
                if ( ! is_string( $raw ) || $raw === '' ) {
                    return false;
                }

                $record = json_decode( $raw, true );
                if ( ! is_array( $record ) || ! isset( $record['expires'] ) || ! is_numeric( $record['expires'] ) ) {
                    return false;
                }

                return ( (int) $record['expires'] ) <= $now;
            }
        );
    }

    private static function scan_ledger( $private_dir, $now, $eligible_age_seconds, &$summary ) {
        $ledger_dir = rtrim( $private_dir, '/\\' ) . '/' . self::LEDGER_DIR;
        self::scan_files(
            $ledger_dir,
            'ledger',
            $summary,
            function ( $path ) use ( $now, $eligible_age_seconds ) {
                if ( substr( $path, -strlen( self::LEDGER_SUFFIX ) ) !== self::LEDGER_SUFFIX ) {
                    return false;
                }

                $mtime = @filemtime( $path );
                if ( ! is_int( $mtime ) ) {
                    return false;
                }

                return $now >= ( $mtime + $eligible_age_seconds );
            }
        );
    }

    private static function scan_uploads( $private_dir, $now, $retention_seconds, &$summary ) {
        $uploads_dir = rtrim( $private_dir, '/\\' ) . '/' . self::UPLOADS_DIR;
        self::scan_files(
            $uploads_dir,
            'uploads',
            $summary,
            function ( $path ) use ( $uploads_dir, $now, $retention_seconds ) {
                if ( self::is_upload_control_file( $uploads_dir, $path ) ) {
                    return false;
                }

                $mtime = @filemtime( $path );
                if ( ! is_int( $mtime ) ) {
                    return false;
                }

                return $now >= ( $mtime + $retention_seconds );
            }
        );
    }

    private static function scan_throttle( $private_dir, $now, &$summary ) {
        $throttle_dir = rtrim( $private_dir, '/\\' ) . '/' . self::THROTTLE_DIR;
        self::scan_files(
            $throttle_dir,
            'throttle',
            $summary,
            function ( $path ) use ( $now ) {
                $basename = basename( $path );
                $is_tally = substr( $basename, -strlen( self::THROTTLE_TALLY_SUFFIX ) ) === self::THROTTLE_TALLY_SUFFIX;
                $is_cooldown = substr( $basename, -strlen( self::THROTTLE_COOLDOWN_SUFFIX ) ) === self::THROTTLE_COOLDOWN_SUFFIX;
                if ( ! $is_tally && ! $is_cooldown ) {
                    return false;
                }

                $mtime = @filemtime( $path );
                if ( ! is_int( $mtime ) ) {
                    return false;
                }

                return ( $now - $mtime ) > self::THROTTLE_STALE_SECONDS;
            }
        );
    }

    private static function scan_files( $dir, $target, &$summary, $is_candidate ) {
        if ( ! is_dir( $dir ) || $summary['reached_limit'] ) {
            return;
        }

        $entries = @scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return;
        }

        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            if ( $summary['reached_limit'] ) {
                return;
            }

            $path = $dir . '/' . $entry;
            if ( is_dir( $path ) ) {
                self::scan_files( $path, $target, $summary, $is_candidate );
                continue;
            }

            if ( ! is_file( $path ) ) {
                continue;
            }

            if ( ! self::count_scanned( $target, $summary ) ) {
                return;
            }

            $candidate = call_user_func( $is_candidate, $path );
            if ( $candidate ) {
                self::process_candidate( $target, $path, $summary );
            }
        }
    }

    private static function count_scanned( $target, &$summary ) {
        if ( $summary['scanned'] >= $summary['limit'] ) {
            $summary['reached_limit'] = true;
            return false;
        }

        $summary['scanned']++;
        $summary['by_type'][ $target ]['scanned']++;
        return true;
    }

    private static function process_candidate( $target, $path, &$summary ) {
        $bytes = self::file_bytes( $path );

        $summary['candidates']++;
        $summary['candidate_bytes'] += $bytes;
        $summary['by_type'][ $target ]['candidates']++;
        $summary['by_type'][ $target ]['candidate_bytes'] += $bytes;

        if ( ! $summary['dry_run'] && @unlink( $path ) ) {
            $summary['deleted']++;
            $summary['deleted_bytes'] += $bytes;
            $summary['by_type'][ $target ]['deleted']++;
            $summary['by_type'][ $target ]['deleted_bytes'] += $bytes;
        }
    }

    private static function is_upload_control_file( $uploads_dir, $path ) {
        $base = basename( $path );
        if ( $base !== PrivateDir::INDEX_FILENAME
            && $base !== PrivateDir::HTACCESS_FILENAME
            && $base !== PrivateDir::WEBCONFIG_FILENAME
        ) {
            return false;
        }

        $parent = rtrim( dirname( $path ), '/\\' );
        $uploads_root = rtrim( $uploads_dir, '/\\' );
        return $parent === $uploads_root;
    }

    private static function file_bytes( $path ) {
        $size = @filesize( $path );
        if ( ! is_int( $size ) || $size < 0 ) {
            return 0;
        }

        return $size;
    }

    private static function acquire_lock( $private_dir ) {
        $path = rtrim( $private_dir, '/\\' ) . '/' . self::LOCK_FILENAME;
        $handle = @fopen( $path, 'c+' );
        if ( $handle === false ) {
            return array(
                'ok' => false,
                'locked' => false,
                'path' => $path,
                'reason' => 'gc_lock_open_failed',
            );
        }

        @chmod( $path, 0600 );

        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle );
            return array(
                'ok' => false,
                'locked' => true,
                'path' => $path,
                'reason' => 'gc_lock_held',
            );
        }

        return array(
            'ok' => true,
            'locked' => false,
            'path' => $path,
            'handle' => $handle,
        );
    }

    private static function release_lock( $handle ) {
        if ( ! is_resource( $handle ) ) {
            return;
        }

        flock( $handle, LOCK_UN );
        fclose( $handle );
    }

    private static function emit_summary_log( $summary ) {
        if ( ! class_exists( 'Logging' ) || ! method_exists( 'Logging', 'event' ) ) {
            return;
        }

        $meta = array(
            'ok' => (bool) $summary['ok'],
            'dry_run' => ! empty( $summary['dry_run'] ),
            'locked' => ! empty( $summary['locked'] ),
            'reason' => isset( $summary['reason'] ) ? (string) $summary['reason'] : '',
            'scanned' => isset( $summary['scanned'] ) ? (int) $summary['scanned'] : 0,
            'candidates' => isset( $summary['candidates'] ) ? (int) $summary['candidates'] : 0,
            'candidate_bytes' => isset( $summary['candidate_bytes'] ) ? (int) $summary['candidate_bytes'] : 0,
            'deleted' => isset( $summary['deleted'] ) ? (int) $summary['deleted'] : 0,
            'deleted_bytes' => isset( $summary['deleted_bytes'] ) ? (int) $summary['deleted_bytes'] : 0,
            'reached_limit' => ! empty( $summary['reached_limit'] ),
            'by_type' => isset( $summary['by_type'] ) && is_array( $summary['by_type'] ) ? $summary['by_type'] : array(),
        );

        Logging::event( 'info', 'EFORMS_GC_SUMMARY', $meta );
    }

    private static function uploads_dir( $config ) {
        if ( is_array( $config ) && isset( $config['uploads'] ) && is_array( $config['uploads'] ) ) {
            if ( isset( $config['uploads']['dir'] ) && is_string( $config['uploads']['dir'] ) ) {
                return rtrim( $config['uploads']['dir'], '/\\' );
            }
        }

        return '';
    }

    private static function uploads_retention_seconds( $config ) {
        if ( is_array( $config )
            && isset( $config['uploads'] )
            && is_array( $config['uploads'] )
            && isset( $config['uploads']['retention_seconds'] )
            && is_numeric( $config['uploads']['retention_seconds'] )
        ) {
            $value = (int) $config['uploads']['retention_seconds'];
            return $value > 0 ? $value : 0;
        }

        return 0;
    }

    private static function anchor_value( $name, $fallback ) {
        if ( class_exists( 'Anchors' ) ) {
            $value = Anchors::get( $name );
            if ( is_int( $value ) && $value >= 0 ) {
                return $value;
            }
        }

        return $fallback;
    }

    private static function option_bool( $options, $key, $default ) {
        if ( ! is_array( $options ) || ! array_key_exists( $key, $options ) ) {
            return (bool) $default;
        }

        $value = $options[ $key ];
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value !== 0;
        }
        if ( is_string( $value ) ) {
            $value = strtolower( trim( $value ) );
            if ( $value === '' ) {
                return true;
            }

            return ! in_array( $value, array( '0', 'false', 'no', 'off' ), true );
        }

        return (bool) $default;
    }

    private static function option_int( $options, $key, $default ) {
        if ( ! is_array( $options ) || ! array_key_exists( $key, $options ) ) {
            return (int) $default;
        }

        $value = $options[ $key ];
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }

        return (int) $default;
    }
}
