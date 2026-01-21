<?php
/**
 * Storage health checks for private uploads.
 *
 * Spec: Shared lifecycle and storage contract (docs/Canonical_Spec.md#sec-shared-lifecycle)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 */

require_once __DIR__ . '/../Uploads/PrivateDir.php';

class StorageHealth {
    private static $memoized = null;
    private static $memoized_dir = null;
    private static $logged_warning = false;

    /**
     * Run or return the per-request storage health check.
     */
    public static function check( $uploads_dir, $force = false ) {
        $uploads_dir = is_string( $uploads_dir ) ? rtrim( $uploads_dir, '/\\' ) : '';

        if ( ! $force && self::$memoized !== null && self::$memoized_dir === $uploads_dir ) {
            return self::$memoized;
        }

        $result = self::run_check( $uploads_dir );

        self::$memoized = $result;
        self::$memoized_dir = $uploads_dir;

        if ( ! $result['ok'] ) {
            self::log_warning_once( $result );
        }

        return $result;
    }

    /**
     * Test helper to reset memoized state.
     */
    public static function reset_for_tests() {
        self::$memoized = null;
        self::$memoized_dir = null;
        self::$logged_warning = false;
    }

    private static function run_check( $uploads_dir ) {
        $uploads_dir = rtrim( $uploads_dir, '/\\' );
        if ( $uploads_dir === '' ) {
            return self::fail_result( $uploads_dir, '', 'uploads_dir_missing' );
        }

        if ( ! is_dir( $uploads_dir ) ) {
            return self::fail_result( $uploads_dir, '', 'uploads_dir_missing' );
        }

        if ( ! is_writable( $uploads_dir ) ) {
            return self::fail_result( $uploads_dir, '', 'uploads_dir_unwritable' );
        }

        $private = PrivateDir::ensure( $uploads_dir );
        if ( ! $private['ok'] ) {
            $reason = $private['error'];
            if ( $reason === '' ) {
                $reason = 'private_dir_unavailable';
            }
            return self::fail_result( $uploads_dir, $private['path'], $reason );
        }

        $private_dir = $private['path'];
        if ( ! is_dir( $private_dir ) || ! is_writable( $private_dir ) ) {
            return self::fail_result( $uploads_dir, $private_dir, 'private_dir_unwritable' );
        }

        $probe_error = self::probe_private_dir( $private_dir );
        if ( $probe_error !== null ) {
            return self::fail_result( $uploads_dir, $private_dir, $probe_error );
        }

        return self::ok_result( $uploads_dir, $private_dir );
    }

    /**
     * Exercise directory creation, atomic rename, and exclusive-create semantics.
     */
    private static function probe_private_dir( $private_dir ) {
        $salt = self::probe_salt();
        $probe_dir = $private_dir . '/.eforms-health-' . $salt;

        if ( file_exists( $probe_dir ) ) {
            return 'probe_collision';
        }

        $created = @mkdir( $probe_dir, 0700, true );
        if ( ! $created && ! is_dir( $probe_dir ) ) {
            return 'probe_dir_create_failed';
        }

        if ( ! self::ensure_permissions( $probe_dir, 0700 ) ) {
            self::probe_cleanup( $probe_dir, array() );
            return 'probe_dir_perms_failed';
        }

        $tmp = $probe_dir . '/probe-' . $salt . '.tmp';
        $final = $probe_dir . '/probe-' . $salt . '.final';
        $xb_path = $probe_dir . '/probe-' . $salt . '.xb';

        $handle = @fopen( $tmp, 'xb' );
        if ( $handle === false ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'probe_write_failed';
        }

        $written = @fwrite( $handle, 'eforms-health' );
        fclose( $handle );
        if ( $written === false ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'probe_write_failed';
        }

        if ( ! self::ensure_permissions( $tmp, 0600 ) ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'probe_write_perms_failed';
        }

        if ( file_exists( $final ) ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'probe_collision';
        }

        $renamed = @rename( $tmp, $final );
        if ( ! $renamed || ! file_exists( $final ) ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'probe_rename_failed';
        }

        if ( ! self::ensure_permissions( $final, 0600 ) ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'probe_rename_perms_failed';
        }

        $handle = @fopen( $xb_path, 'xb' );
        if ( $handle === false ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'probe_xb_failed';
        }
        fclose( $handle );

        if ( ! self::ensure_permissions( $xb_path, 0600 ) ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'probe_xb_perms_failed';
        }

        $second = @fopen( $xb_path, 'xb' );
        if ( $second !== false ) {
            fclose( $second );
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'probe_xb_not_exclusive';
        }

        self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
        return null;
    }

    private static function probe_salt() {
        if ( function_exists( 'random_bytes' ) ) {
            try {
                return bin2hex( random_bytes( 4 ) );
            } catch ( Exception $e ) {
                // Fall back to pid-based salt when the CSPRNG is unavailable.
            }
        }

        return (string) getmypid();
    }

    private static function probe_cleanup( $probe_dir, $paths ) {
        foreach ( $paths as $path ) {
            if ( is_string( $path ) && $path !== '' && file_exists( $path ) ) {
                @unlink( $path );
            }
        }

        if ( is_dir( $probe_dir ) ) {
            @rmdir( $probe_dir );
        }
    }

    private static function ensure_permissions( $path, $mode ) {
        if ( @chmod( $path, $mode ) ) {
            return true;
        }

        return false;
    }

    private static function log_warning_once( $result ) {
        if ( self::$logged_warning ) {
            return;
        }

        if ( ! class_exists( 'Logging' ) ) {
            return;
        }

        self::$logged_warning = true;

        $meta = array(
            'reason' => $result['reason'],
        );
        if ( isset( $result['uploads_dir'] ) && is_string( $result['uploads_dir'] ) && $result['uploads_dir'] !== '' ) {
            $meta['uploads_dir'] = $result['uploads_dir'];
        }
        if ( isset( $result['private_dir'] ) && is_string( $result['private_dir'] ) && $result['private_dir'] !== '' ) {
            $meta['private_dir'] = $result['private_dir'];
        }

        Logging::event( 'warning', 'EFORMS_ERR_STORAGE_UNAVAILABLE', $meta );
    }

    private static function ok_result( $uploads_dir, $private_dir ) {
        return array(
            'ok' => true,
            'code' => '',
            'reason' => '',
            'uploads_dir' => $uploads_dir,
            'private_dir' => $private_dir,
        );
    }

    private static function fail_result( $uploads_dir, $private_dir, $reason ) {
        return array(
            'ok' => false,
            'code' => 'EFORMS_ERR_STORAGE_UNAVAILABLE',
            'reason' => $reason,
            'uploads_dir' => $uploads_dir,
            'private_dir' => $private_dir,
        );
    }
}
