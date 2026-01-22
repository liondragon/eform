<?php
/**
 * Ledger reservation for duplicate-submission suppression.
 *
 * Educational note: ledger markers are created via exclusive-create to ensure
 * concurrent submissions cannot both succeed without a database.
 *
 * Spec: Ledger reservation contract (docs/Canonical_Spec.md#sec-ledger-contract)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';

class Ledger {
    const LEDGER_DIR = 'ledger';
    const MARKER_SUFFIX = '.used';
    const SUBMISSION_ID_REGEX = '/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i';

    /**
     * Reserve the ledger marker for a submission id.
     *
     * @param string $form_id
     * @param string $submission_id
     * @param string|null $uploads_dir
     * @param mixed $request
     * @return array{ok: bool, duplicate: bool, logged?: bool, path?: string, reason?: string}
     */
    public static function reserve( $form_id, $submission_id, $uploads_dir = null, $request = null ) {
        $form_id = is_string( $form_id ) ? $form_id : '';
        $submission_id = is_string( $submission_id ) ? $submission_id : '';

        if ( $form_id === '' || $submission_id === '' ) {
            return self::error_result( 'missing_inputs' );
        }

        if ( preg_match( self::SUBMISSION_ID_REGEX, $submission_id ) !== 1 ) {
            return self::error_result( 'submission_id_invalid' );
        }

        if ( preg_match( '/[\\\\\\/]/', $form_id ) === 1 ) {
            return self::error_result( 'form_id_invalid' );
        }

        $uploads_dir = self::resolve_uploads_dir( $uploads_dir );
        if ( $uploads_dir === '' || ! is_dir( $uploads_dir ) || ! is_writable( $uploads_dir ) ) {
            return self::error_result( 'uploads_dir_unavailable' );
        }

        $private = PrivateDir::ensure( $uploads_dir );
        if ( ! is_array( $private ) || empty( $private['ok'] ) ) {
            $reason = is_array( $private ) && isset( $private['error'] ) ? $private['error'] : 'private_dir_unavailable';
            return self::error_result( $reason );
        }

        $ledger_dir = rtrim( $private['path'], '/\\' ) . '/' . self::LEDGER_DIR;
        if ( ! self::ensure_dir( $ledger_dir, 0700 ) ) {
            return self::error_result( 'ledger_dir_unavailable' );
        }

        $form_dir = $ledger_dir . '/' . $form_id;
        if ( ! self::ensure_dir( $form_dir, 0700 ) ) {
            return self::error_result( 'form_dir_unavailable' );
        }

        $shard = Helpers::h2( $submission_id );
        $shard_dir = $form_dir . '/' . $shard;
        if ( ! self::ensure_dir( $shard_dir, 0700 ) ) {
            return self::error_result( 'shard_dir_unavailable' );
        }

        $path = $shard_dir . '/' . $submission_id . self::MARKER_SUFFIX;

        $handle = @fopen( $path, 'xb' );
        if ( $handle === false ) {
            clearstatcache( true, $path );
            if ( file_exists( $path ) && is_file( $path ) ) {
                return array(
                    'ok' => false,
                    'duplicate' => true,
                    'logged' => false,
                    'path' => $path,
                    'reason' => 'exists',
                );
            }

            self::log_io_failure( $form_id, $submission_id, $path, 'create_failed', $request );
            return self::error_result( 'create_failed', true, $path );
        }

        fclose( $handle );

        if ( ! self::ensure_permissions( $path, 0600 ) ) {
            self::log_io_failure( $form_id, $submission_id, $path, 'chmod_failed', $request );
            return self::error_result( 'chmod_failed', true, $path );
        }

        return array(
            'ok' => true,
            'duplicate' => false,
            'logged' => false,
            'path' => $path,
        );
    }

    private static function resolve_uploads_dir( $uploads_dir ) {
        if ( is_string( $uploads_dir ) && $uploads_dir !== '' ) {
            return rtrim( $uploads_dir, '/\\' );
        }

        $config = Config::get();
        if ( is_array( $config ) && isset( $config['uploads'] ) && is_array( $config['uploads'] ) ) {
            if ( isset( $config['uploads']['dir'] ) && is_string( $config['uploads']['dir'] ) && $config['uploads']['dir'] !== '' ) {
                return rtrim( $config['uploads']['dir'], '/\\' );
            }
        }

        return '';
    }

    private static function ensure_dir( $path, $mode ) {
        if ( is_dir( $path ) ) {
            return self::ensure_permissions( $path, $mode );
        }

        if ( file_exists( $path ) ) {
            return false;
        }

        $created = @mkdir( $path, $mode, true );
        if ( ! $created && ! is_dir( $path ) ) {
            return false;
        }

        return self::ensure_permissions( $path, $mode );
    }

    private static function ensure_permissions( $path, $mode ) {
        if ( @chmod( $path, $mode ) ) {
            return true;
        }

        return false;
    }

    private static function error_result( $reason, $logged = false, $path = '' ) {
        $result = array(
            'ok' => false,
            'duplicate' => false,
            'logged' => (bool) $logged,
            'reason' => $reason,
        );

        if ( is_string( $path ) && $path !== '' ) {
            $result['path'] = $path;
        }

        return $result;
    }

    private static function log_io_failure( $form_id, $submission_id, $path, $reason, $request ) {
        if ( ! class_exists( 'Logging' ) ) {
            return;
        }

        $meta = array(
            'form_id' => $form_id,
            'submission_id' => $submission_id,
            'path' => $path,
            'reason' => $reason,
        );

        Logging::event( 'error', 'EFORMS_LEDGER_IO', $meta, $request );
    }
}
