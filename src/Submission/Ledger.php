<?php
/**
 * Ledger reservation for duplicate-submission suppression.
 *
 * Educational note: ledger markers are created via exclusive-create to ensure
 * concurrent submissions cannot both succeed without a database.
 *
 * Contract: Ledger reservation contract
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';

class Ledger {
    const LEDGER_DIR = 'ledger';
    const MARKER_SUFFIX = '.used';
    const SHARD_LOCK_FILENAME = '.lock';
    const ROOT_LOCK_FILENAME = 'ledger.lock';
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
        $identity_error = self::identity_error( $form_id, $submission_id );
        if ( $identity_error !== '' ) {
            return self::error_result( $identity_error );
        }
        $resolved_uploads_dir = self::resolve_uploads_dir( $uploads_dir );
        $lifecycle = PrivateDir::acquire_write_lease( $resolved_uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::error_result( 'upload_lifecycle_unavailable' );
        }
        $state = self::prepare_write_state( $form_id, $submission_id, $lifecycle );
        if ( empty( $state['ok'] ) ) {
            return $state;
        }
        $path = $state['path'];
        $locks = self::acquire_operation_locks( $state );
        if ( empty( $locks['ok'] ) ) {
            $failed_path = isset( $locks['path'] ) ? $locks['path'] : $state['lock_path'];
            self::log_io_failure( $form_id, $submission_id, $failed_path, 'lock_failed', $request );
            return self::error_result( 'lock_failed', true, $failed_path );
        }

        $handle = @fopen( $path, 'xb' );
        if ( $handle === false ) {
            clearstatcache( true, $path );
            if ( file_exists( $path ) && is_file( $path ) ) {
                self::release_operation_locks( $locks );
                return array(
                    'ok' => false,
                    'duplicate' => true,
                    'logged' => false,
                    'path' => $path,
                    'reason' => 'exists',
                );
            }

            self::release_operation_locks( $locks );
            self::log_io_failure( $form_id, $submission_id, $path, 'create_failed', $request );
            return self::error_result( 'create_failed', true, $path );
        }

        fclose( $handle );

        if ( ! self::ensure_permissions( $path, 0600 ) ) {
            self::release_operation_locks( $locks );
            self::log_io_failure( $form_id, $submission_id, $path, 'chmod_failed', $request );
            return self::error_result( 'chmod_failed', true, $path );
        }
        self::release_operation_locks( $locks );

        return array(
            'ok' => true,
            'duplicate' => false,
            'logged' => false,
            'path' => $path,
        );
    }

    /**
     * Run one mutation while the submission token is still unused.
     * Ledger reservation uses the same lock, so the check and callback form one
     * lifecycle boundary even when another request is finalizing concurrently.
     *
     * @return array{ok: bool, duplicate: bool, result?: mixed, reason?: string}
     */
    public static function run_if_unused( $form_id, $submission_id, $uploads_dir, $callback, $request = null ) {
        $form_id = is_string( $form_id ) ? $form_id : '';
        $submission_id = is_string( $submission_id ) ? $submission_id : '';
        if ( ! is_callable( $callback ) ) {
            return self::error_result( 'callback_invalid' );
        }

        $identity_error = self::identity_error( $form_id, $submission_id );
        if ( $identity_error !== '' ) {
            return self::error_result( $identity_error );
        }
        $resolved_uploads_dir = self::resolve_uploads_dir( $uploads_dir );
        $lifecycle = PrivateDir::acquire_write_lease( $resolved_uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::error_result( 'upload_lifecycle_unavailable' );
        }

        $state = self::prepare_write_state( $form_id, $submission_id, $lifecycle );
        if ( empty( $state['ok'] ) ) {
            return $state;
        }
        $locks = self::acquire_operation_locks( $state );
        if ( empty( $locks['ok'] ) ) {
            $failed_path = isset( $locks['path'] ) ? $locks['path'] : $state['lock_path'];
            self::log_io_failure( $form_id, $submission_id, $failed_path, 'lock_failed', $request );
            return self::error_result( 'lock_failed', true, $failed_path );
        }

        clearstatcache( true, $state['path'] );
        if ( file_exists( $state['path'] ) ) {
            $used = is_file( $state['path'] );
            self::release_operation_locks( $locks );
            return $used
                ? array( 'ok' => false, 'duplicate' => true, 'logged' => false, 'path' => $state['path'], 'reason' => 'exists' )
                : self::error_result( 'ledger_marker_invalid' );
        }

        try {
            $result = call_user_func( $callback );
        } finally {
            self::release_operation_locks( $locks );
        }
        return array( 'ok' => true, 'duplicate' => false, 'result' => $result );
    }

    private static function identity_error( $form_id, $submission_id ) {
        if ( $form_id === '' || $submission_id === '' ) {
            return 'missing_inputs';
        }
        if ( preg_match( self::SUBMISSION_ID_REGEX, $submission_id ) !== 1 ) {
            return 'submission_id_invalid';
        }
        return preg_match( '/[\\\\\\/]/', $form_id ) === 1 ? 'form_id_invalid' : '';
    }

    private static function prepare_write_state( $form_id, $submission_id, $lifecycle ) {
        $identity_error = self::identity_error( $form_id, $submission_id );
        if ( $identity_error !== '' ) {
            return self::error_result( $identity_error );
        }

        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::error_result( 'private_dir_unavailable' );
        }

        $shard_dir = PrivateDir::leased_relative_dir( $lifecycle, self::LEDGER_DIR . '/' . $form_id . '/' . Helpers::h2( $submission_id ), true );
        if ( $shard_dir === '' ) {
            return self::error_result( 'shard_dir_unavailable' );
        }

        return array(
            'ok' => true,
            'path' => $shard_dir . '/' . $submission_id . self::MARKER_SUFFIX,
            'lock_path' => $shard_dir . '/' . self::SHARD_LOCK_FILENAME,
            'root_lock_path' => rtrim( $lifecycle->private_dir(), '/\\' ) . '/' . self::ROOT_LOCK_FILENAME,
        );
    }

    /**
     * Delete an orphan shard lock only while the stable ledger-root guard
     * excludes operations that could already have opened that inode.
     */
    public static function delete_orphan_shard_lock( $path, $private_dir ) {
        $private_dir = is_string( $private_dir ) ? rtrim( $private_dir, '/\\' ) : '';
        $ledger_root = $private_dir === '' ? '' : $private_dir . '/' . self::LEDGER_DIR;
        $shard_dir = is_string( $path ) ? dirname( $path ) : '';
        $form_dir = $shard_dir === '' ? '' : dirname( $shard_dir );
        if ( $ledger_root === ''
            || ! is_string( $path )
            || basename( $path ) !== self::SHARD_LOCK_FILENAME
            || dirname( $form_dir ) !== $ledger_root
            || preg_match( '/^[0-9a-f]{2}$/', basename( $shard_dir ) ) !== 1
            || is_link( $ledger_root )
            || is_link( $form_dir )
            || is_link( $shard_dir )
            || is_link( $path )
            || ! is_dir( $form_dir )
            || ! is_dir( $shard_dir )
            || ! is_file( $path )
        ) {
            return false;
        }

        $root_lock = self::acquire_lock( $private_dir . '/' . self::ROOT_LOCK_FILENAME, LOCK_EX | LOCK_NB );
        if ( $root_lock === false ) {
            return false;
        }
        $shard_lock = self::acquire_existing_lock( $path, LOCK_EX | LOCK_NB );
        if ( $shard_lock === false ) {
            self::release_lock( $root_lock );
            return false;
        }

        clearstatcache( true, $path );
        $deleted = is_file( $path )
            && ! is_link( $path )
            && ! self::shard_has_marker( $shard_dir )
            && @unlink( $path );
        self::release_lock( $shard_lock );
        self::release_lock( $root_lock );
        return $deleted;
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

    private static function ensure_permissions( $path, $mode ) {
        if ( @chmod( $path, $mode ) ) {
            return true;
        }

        return false;
    }

    private static function acquire_operation_locks( $state ) {
        $root_lock = self::acquire_lock( $state['root_lock_path'], LOCK_SH );
        if ( $root_lock === false ) {
            return array( 'ok' => false, 'path' => $state['root_lock_path'] );
        }
        $shard_lock = self::acquire_lock( $state['lock_path'], LOCK_EX );
        if ( $shard_lock === false ) {
            self::release_lock( $root_lock );
            return array( 'ok' => false, 'path' => $state['lock_path'] );
        }
        return array( 'ok' => true, 'root' => $root_lock, 'shard' => $shard_lock );
    }

    private static function release_operation_locks( $locks ) {
        self::release_lock( isset( $locks['shard'] ) ? $locks['shard'] : false );
        self::release_lock( isset( $locks['root'] ) ? $locks['root'] : false );
    }

    private static function acquire_lock( $path, $operation = LOCK_EX ) {
        if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
            return false;
        }
        $handle = @fopen( $path, 'c+b' );
        if ( $handle === false ) {
            return false;
        }
        if ( ! @chmod( $path, 0600 ) ) {
            fclose( $handle );
            return false;
        }
        if ( ! @flock( $handle, $operation ) ) {
            fclose( $handle );
            return false;
        }
        return $handle;
    }

    private static function acquire_existing_lock( $path, $operation ) {
        if ( is_link( $path ) || ! is_file( $path ) ) {
            return false;
        }
        $handle = @fopen( $path, 'r+b' );
        if ( $handle === false || ! @flock( $handle, $operation ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
            return false;
        }
        return $handle;
    }

    private static function shard_has_marker( $shard_dir ) {
        $entries = @scandir( $shard_dir );
        if ( ! is_array( $entries ) ) {
            return true;
        }
        foreach ( $entries as $entry ) {
            if ( substr( $entry, -strlen( self::MARKER_SUFFIX ) ) === self::MARKER_SUFFIX
                && is_file( rtrim( $shard_dir, '/\\' ) . '/' . $entry )
            ) {
                return true;
            }
        }
        return false;
    }

    private static function release_lock( $handle ) {
        if ( is_resource( $handle ) ) {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
        }
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
