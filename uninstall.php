<?php
/**
 * Uninstall cleanup entrypoint.
 *
 * Contract: Runtime storage cleanup
 * Contract: Configuration
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Admin/AdminSettingsStore.php';
require_once __DIR__ . '/src/Logging/Fail2banLogger.php';
require_once __DIR__ . '/src/Uploads/PrivateDir.php';
require_once __DIR__ . '/src/Uploads/UploadBatchStore.php';
require_once __DIR__ . '/src/Uploads/WorkerClient.php';

if ( ! function_exists( 'eforms_uninstall_remove_tree' ) ) {
    /**
     * Remove a file/dir tree recursively.
     *
     * @param string $path
     * @return void
     */
    function eforms_uninstall_remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' || ( ! file_exists( $path ) && ! is_link( $path ) ) ) {
            return;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
            return;
        }

        $entries = @scandir( $path );
        if ( ! is_array( $entries ) ) {
            return;
        }

        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            eforms_uninstall_remove_tree( rtrim( $path, '/\\' ) . '/' . $entry );
        }

        @rmdir( $path );
    }
}

if ( ! function_exists( 'eforms_uninstall_try_remove_empty_chain' ) ) {
    /**
     * Remove empty directories up to, but not including, the stop path.
     *
     * @param string $start
     * @param string $stop
     * @return void
     */
    function eforms_uninstall_try_remove_empty_chain( $start, $stop ) {
        if ( ! is_string( $start ) || $start === '' || ! is_string( $stop ) || $stop === '' ) {
            return;
        }

        $current = rtrim( $start, '/\\' );
        $stop = rtrim( $stop, '/\\' );

        while ( $current !== '' && $current !== $stop && is_dir( $current ) ) {
            $entries = @scandir( $current );
            if ( ! is_array( $entries ) ) {
                break;
            }

            $children = array_diff( $entries, array( '.', '..' ) );
            if ( ! empty( $children ) ) {
                break;
            }

            if ( ! @rmdir( $current ) ) {
                break;
            }

            $parent = dirname( $current );
            if ( ! is_string( $parent ) || $parent === $current ) {
                break;
            }
            $current = rtrim( $parent, '/\\' );
        }
    }
}

if ( ! function_exists( 'eforms_uninstall_ensure_wp_upload_dir' ) ) {
    /**
     * Ensure wp_upload_dir() is callable in uninstall context.
     *
     * @return bool
     */
    function eforms_uninstall_ensure_wp_upload_dir() {
        if ( function_exists( 'wp_upload_dir' ) ) {
            return true;
        }

        if ( defined( 'ABSPATH' ) ) {
            $file_api = rtrim( (string) ABSPATH, '/\\' ) . '/wp-admin/includes/file.php';
            if ( is_readable( $file_api ) ) {
                require_once $file_api;
            }
        }

        return function_exists( 'wp_upload_dir' );
    }
}

if ( ! function_exists( 'eforms_uninstall_run' ) ) {
    /**
     * Execute uninstall cleanup respecting purge flags.
     *
     * @return array{ok:bool, reason:string}
     */
    function eforms_uninstall_run( $options = array() ) {
        $options = is_array( $options ) ? $options : array();
        $remove_tree = isset( $options['remove_tree'] ) && is_callable( $options['remove_tree'] )
            ? $options['remove_tree']
            : 'eforms_uninstall_remove_tree';
        Config::bootstrap();
        $config = Config::get();

        $purge_logs = Config::bool( $config, array( 'install', 'uninstall', 'purge_logs' ), false );
        $purge_uploads = Config::bool( $config, array( 'install', 'uninstall', 'purge_uploads' ), false );
        if ( ! $purge_logs && ! $purge_uploads ) {
            AdminSettingsStore::delete_all();
            return array( 'ok' => true, 'reason' => '' );
        }

        if ( ! eforms_uninstall_ensure_wp_upload_dir() ) {
            return array( 'ok' => false, 'reason' => 'uploads_api_unavailable' );
        }

        $uploads_dir = '';
        if ( isset( $config['uploads']['dir'] ) && is_string( $config['uploads']['dir'] ) ) {
            $uploads_dir = rtrim( $config['uploads']['dir'], '/\\' );
        }
        if ( $uploads_dir === '' ) {
            $uploads = wp_upload_dir();
            if ( is_array( $uploads ) && isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ) {
                $uploads_dir = rtrim( $uploads['basedir'], '/\\' );
            }
        }

        if ( $uploads_dir === '' || ! is_dir( $uploads_dir ) ) {
            return array( 'ok' => false, 'reason' => 'uploads_dir_unavailable' );
        }

        $private_dir = PrivateDir::path( $uploads_dir );
        $private_exists = $private_dir !== '' && is_dir( $private_dir ) && ! is_link( $private_dir );

        if ( $purge_uploads ) {
            $lifecycle = PrivateDir::acquire_purge_lease( $uploads_dir );
            if ( ! $lifecycle instanceof PrivateDirLease ) {
                return array( 'ok' => false, 'reason' => 'upload_lifecycle_unavailable' );
            }
            $private_dir = $lifecycle->private_dir();
            $private_exists = true;
            $record_path = $private_dir . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME;
            $marker_path = $private_dir . '/' . PrivateDir::PURGE_MARKER_FILENAME;
            $remote_state = UploadBatchStore::remote_artifacts_present( $lifecycle );
            if ( empty( $remote_state['ok'] ) ) {
                $lifecycle->release();
                return array(
                    'ok' => false,
                    'reason' => isset( $remote_state['reason'] ) ? $remote_state['reason'] : 'manifest_invalid',
                );
            }
            if ( ! empty( $remote_state['present'] ) || file_exists( $record_path ) || is_link( $record_path ) ) {
                $now = isset( $options['now'] ) && is_numeric( $options['now'] ) ? (int) $options['now'] : time();
                $fingerprint = WorkerClient::composition_fingerprint();
                if ( $fingerprint === '' ) {
                    $lifecycle->release();
                    return array( 'ok' => false, 'reason' => 'upload_composition_unavailable' );
                }
                if ( ( file_exists( $record_path ) || is_link( $record_path ) )
                    && is_file( $marker_path )
                    && ! is_link( $marker_path )
                ) {
                    $remote_delete = isset( $options['remote_delete'] ) && is_callable( $options['remote_delete'] )
                        ? $options['remote_delete']
                        : function ( $object_key, $object_version, $artifact_store_identity ) use ( $now ) {
                            return WorkerClient::delete_object(
                                $object_key,
                                $object_version,
                                $artifact_store_identity,
                                $now,
                                null,
                                'uninstall_drain'
                            );
                        };
                    $remote = UploadBatchStore::resume_remote_purge(
                        $lifecycle,
                        $fingerprint,
                        $remote_delete,
                        $now
                    );
                } else {
                    $remote = UploadBatchStore::prepare_remote_purge( $lifecycle, $fingerprint, $now );
                }
                if ( empty( $remote['ok'] ) || empty( $remote['ready'] ) ) {
                    $lifecycle->release();
                    return array(
                        'ok' => false,
                        'reason' => empty( $remote['ok'] )
                            ? ( isset( $remote['reason'] ) ? $remote['reason'] : 'remote_purge_failed' )
                            : 'remote_purge_draining',
                        'retry_at' => isset( $remote['safe_after'] ) ? (int) $remote['safe_after'] : 0,
                    );
                }
            }
            $managed_lock = UploadBatchStore::acquire_purge_capacity_lock( $lifecycle );
            if ( ! is_resource( $managed_lock ) ) {
                $lifecycle->release();
                return array( 'ok' => false, 'reason' => 'managed_capacity_lock_unavailable' );
            }
            $staged_dir = $private_dir . '/' . UploadBatchStore::STAGED_DIR;
            $submissions_dir = $private_dir . '/' . UploadBatchStore::SUBMISSIONS_DIR;
            $artifacts_dir = $private_dir . '/' . UploadBatchStore::ARTIFACTS_DIR;
            $preview_cache_dir = $private_dir . '/' . UploadBatchStore::PREVIEW_CACHE_DIR;
            $aggregate_locks = UploadBatchStore::prelock_purge_aggregates( $lifecycle );
            if ( ! is_array( $aggregate_locks ) ) {
                UploadBatchStore::release_purge_locks( $managed_lock );
                $lifecycle->release();
                return array( 'ok' => false, 'reason' => 'managed_aggregate_lock_unavailable' );
            }
            if ( ! PrivateDir::mark_purged( $lifecycle ) ) {
                UploadBatchStore::release_purge_locks( $aggregate_locks );
                UploadBatchStore::release_purge_locks( $managed_lock );
                $lifecycle->release();
                return array( 'ok' => false, 'reason' => 'purge_barrier_unavailable' );
            }

            // The durable barrier blocks queued aggregate writers; close their
            // handles before deletion so Windows can unlink each lock inode.
            UploadBatchStore::release_purge_locks( $aggregate_locks );
            call_user_func( $remove_tree, $private_dir . '/tokens' );
            call_user_func( $remove_tree, $private_dir . '/ledger' );
            call_user_func( $remove_tree, $private_dir . '/uploads' );
            call_user_func( $remove_tree, $private_dir . '/throttle' );
            call_user_func( $remove_tree, $staged_dir );
            call_user_func( $remove_tree, $submissions_dir );
            call_user_func( $remove_tree, $artifacts_dir );
            call_user_func( $remove_tree, $preview_cache_dir );
            $managed_roots_removed = ! file_exists( $staged_dir ) && ! is_link( $staged_dir )
                && ! file_exists( $submissions_dir ) && ! is_link( $submissions_dir )
                && ! file_exists( $artifacts_dir ) && ! is_link( $artifacts_dir )
                && ! file_exists( $preview_cache_dir ) && ! is_link( $preview_cache_dir );
            if ( $managed_roots_removed ) {
                call_user_func( $remove_tree, $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME );
                call_user_func( $remove_tree, $private_dir . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME );
            }
            UploadBatchStore::release_purge_locks( $managed_lock );
            $lifecycle->release();
            if ( ! $managed_roots_removed
                || file_exists( $private_dir . '/tokens' )
                || is_link( $private_dir . '/tokens' )
                || file_exists( $private_dir . '/ledger' )
                || is_link( $private_dir . '/ledger' )
                || file_exists( $private_dir . '/uploads' )
                || is_link( $private_dir . '/uploads' )
                || file_exists( $private_dir . '/throttle' )
                || is_link( $private_dir . '/throttle' )
                || file_exists( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME )
                || is_link( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME )
                || file_exists( $private_dir . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME )
                || is_link( $private_dir . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME )
            ) {
                return array( 'ok' => false, 'reason' => 'upload_purge_incomplete' );
            }
        }

        if ( $purge_logs ) {
            if ( $private_exists ) {
                call_user_func( $remove_tree, $private_dir . '/logs' );
                call_user_func( $remove_tree, $private_dir . '/f2b' );
                call_user_func( $remove_tree, $private_dir . '/declined' );
            }

            $fail2ban_file = Fail2banLogger::target_path( $config, $uploads_dir );
            if ( $fail2ban_file !== '' ) {
                Fail2banLogger::delete_family( $fail2ban_file );
                if ( Fail2banLogger::target_uses_uploads_dir( $config ) ) {
                    eforms_uninstall_try_remove_empty_chain( dirname( $fail2ban_file ), $uploads_dir );
                }
            }
        }

        if ( $private_exists ) {
            eforms_uninstall_try_remove_empty_chain( $private_dir, $uploads_dir );
        }

        AdminSettingsStore::delete_all();
        return array( 'ok' => true, 'reason' => '' );
    }
}

$eforms_uninstall_result = eforms_uninstall_run();
if ( empty( $eforms_uninstall_result['ok'] ) ) {
    $reason = isset( $eforms_uninstall_result['reason'] ) ? $eforms_uninstall_result['reason'] : 'unknown';
    $message = 'eForms uninstall could not safely purge runtime data (' . $reason . '). Plugin deletion was stopped.';
    if ( ! empty( $eforms_uninstall_result['retry_at'] ) ) {
        $message .= ' Retry after ' . gmdate( 'Y-m-d H:i:s', (int) $eforms_uninstall_result['retry_at'] ) . ' UTC.';
    }
    if ( function_exists( 'wp_die' ) ) {
        wp_die( $message );
    }
    throw new RuntimeException( $message );
}
