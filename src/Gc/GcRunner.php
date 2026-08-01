<?php
/**
 * Garbage-collection runner for runtime artifacts.
 *
 * Contract: Uploads
 * Contract: Throttling
 * Contract: Anchors
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../DeclinedReviewLog.php';
require_once __DIR__ . '/../Submission/Ledger.php';
require_once __DIR__ . '/../Uploads/UploadBatchStore.php';
require_once __DIR__ . '/../Uploads/LocalPreviewProvider.php';
require_once __DIR__ . '/../Uploads/WorkerClient.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';
require_once __DIR__ . '/../Uploads/UploadStore.php';

class GcRunner {
    const LOCK_FILENAME = 'gc.lock';
    const PROGRESS_VERSION = 3;

    const TOKENS_DIR = 'tokens';
    const LEDGER_DIR = 'ledger';
    const UPLOADS_DIR = 'uploads';
    const THROTTLE_DIR = 'throttle';

    const TOKEN_SUFFIX = '.json';
    const LEDGER_SUFFIX = '.used';
    const THROTTLE_TALLY_SUFFIX = '.tally';
    const THROTTLE_COOLDOWN_SUFFIX = '.cooldown';

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

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            $summary['reason'] = 'upload_lifecycle_unavailable';
            self::emit_summary_log( $summary );
            return $summary;
        }

        $private_dir = $lifecycle->private_dir();
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
            $progress = self::read_progress( $handle );
            if ( ! $summary['dry_run'] && $summary['reconcile_capacity'] ) {
                self::reconcile_managed_capacity( $uploads_dir, $summary );
            }
            if ( ! self::remote_provider_failed( $summary['reason'] ) ) {
                self::run_targets( $private_dir, $config, $summary, $progress, $lifecycle );
            }
            if ( ! $summary['dry_run'] && ! self::write_progress( $handle, $progress ) && $summary['reason'] === '' ) {
                $summary['reason'] = 'gc_progress_write_failed';
            }
            $summary['ok'] = $summary['reason'] === '';
        } finally {
            self::release_lock( $handle );
        }

        self::emit_summary_log( $summary );
        return $summary;
    }

    private static function summary_template( $options ) {
        $dry_run = self::option_bool( $options, 'dry_run', false );
        $default_limit = Anchors::get( 'GC_DEFAULT_BATCH_LIMIT' );
        $limit = self::option_int( $options, 'limit', $default_limit );
        $now = self::option_int( $options, 'now', time() );
        if ( $limit < 1 ) {
            $limit = $default_limit;
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
            'reconcile_capacity' => self::option_bool( $options, 'reconcile_capacity', false ),
            'capacity_reconciled' => false,
            'capacity_before_bytes' => 0,
            'capacity_after_bytes' => 0,
            'stale_reservations_removed' => 0,
            'scanned' => 0,
            'candidates' => 0,
            'candidate_bytes' => 0,
            'deleted' => 0,
            'deleted_bytes' => 0,
            'by_type' => array(
                'tokens' => self::target_template(),
                'ledger' => self::target_template(),
                'uploads' => self::target_template(),
                'staged_batches' => self::target_template(),
                'finalized_submissions' => self::target_template(),
                'preview_fences' => self::target_template(),
                'throttle' => self::target_template(),
                'declined' => self::target_template(),
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
            'candidate_artifact_bytes' => 0,
            'deleted_artifact_bytes' => 0,
            'released_bytes' => 0,
            'errors' => 0,
            'reason' => '',
        );
    }

    private static function reconcile_managed_capacity( $uploads_dir, &$summary ) {
        $now = (int) $summary['now'];
        $remote_delete = function ( $object_key, $object_version, $artifact_store_identity ) use ( $now ) {
            return WorkerClient::delete_object(
                $object_key,
                $object_version,
                $artifact_store_identity,
                $now,
                null,
                'capacity_reconciliation'
            );
        };
        $result = UploadBatchStore::reconcile_capacity(
            $uploads_dir,
            $now - Anchors::get( 'MANAGED_RESERVATION_STALE_SECONDS' ),
            $now,
            $remote_delete
        );
        if ( empty( $result['ok'] ) || ! isset( $result['capacity']['total_bytes'] ) ) {
            $summary['reason'] = 'managed_capacity_' . ( isset( $result['reason'] ) ? $result['reason'] : 'reconcile_failed' );
            return;
        }

        $summary['capacity_reconciled'] = true;
        $summary['capacity_before_bytes'] = isset( $result['previous_total_bytes'] ) ? (int) $result['previous_total_bytes'] : 0;
        $summary['capacity_after_bytes'] = (int) $result['capacity']['total_bytes'];
        $summary['stale_reservations_removed'] = isset( $result['stale_reservations_removed'] )
            ? max( 0, (int) $result['stale_reservations_removed'] )
            : 0;
    }

    private static function run_targets( $private_dir, $config, &$summary, &$progress, $lifecycle ) {
        $now = (int) $summary['now'];
        $token_ttl_max = Anchors::get( 'TOKEN_TTL_MAX' );
        $ledger_grace = Anchors::get( 'LEDGER_GC_GRACE_SECONDS' );
        $retention_seconds = self::uploads_retention_seconds( $config );

        $targets = array(
            'tokens' => function ( $budget ) use ( $private_dir, $now, &$summary, &$progress ) {
                self::scan_tokens( $private_dir, $now, $summary, $budget, $progress['families']['tokens'] );
            },
            'ledger' => function ( $budget ) use ( $private_dir, $now, $token_ttl_max, $ledger_grace, &$summary, &$progress ) {
                self::scan_ledger( $private_dir, $now, $token_ttl_max + $ledger_grace, $summary, $budget, $progress['families']['ledger'] );
            },
            'uploads' => function ( $budget ) use ( $private_dir, $now, $retention_seconds, &$summary, &$progress ) {
                self::scan_uploads( $private_dir, $now, $retention_seconds, $summary, $budget, $progress['families']['uploads'] );
            },
            'staged_batches' => function ( $budget ) use ( $config, $now, &$summary, &$progress ) {
                self::scan_managed_aggregates( 'staged', 'staged_batches', $config, $now, $summary, $budget, $progress );
            },
            'finalized_submissions' => function ( $budget ) use ( $config, $now, &$summary, &$progress ) {
                self::scan_managed_aggregates( 'finalized', 'finalized_submissions', $config, $now, $summary, $budget, $progress );
            },
            'preview_fences' => function ( $budget ) use ( $lifecycle, $now, &$summary, &$progress ) {
                self::scan_preview_fences( $lifecycle, $now, $summary, $budget, $progress['families']['preview_fences'] );
            },
            'throttle' => function ( $budget ) use ( $private_dir, $now, &$summary, &$progress ) {
                self::scan_throttle( $private_dir, $now, $summary, $budget, $progress['families']['throttle'] );
            },
            'declined' => function ( $budget ) use ( $config, $now, &$summary, &$progress ) {
                self::scan_declined( $config, $now, $summary, $budget, $progress['families']['declined'] );
            },
        );

        $canonical_names = array_keys( $targets );
        $target_names = $canonical_names;
        $next_family = isset( $progress['next_family'] ) && is_string( $progress['next_family'] )
            ? $progress['next_family']
            : $target_names[0];
        $offset = array_search( $next_family, $target_names, true );
        $offset = $offset === false ? 0 : (int) $offset;
        $target_names = array_merge( array_slice( $target_names, $offset ), array_slice( $target_names, 0, $offset ) );

        $remaining_targets = count( $target_names );
        foreach ( $target_names as $target_name ) {
            $remaining = max( 0, (int) $summary['limit'] - (int) $summary['scanned'] );
            if ( $remaining <= 0 ) {
                $summary['reached_limit'] = true;
                return;
            }

            $budget = max( 1, (int) floor( $remaining / $remaining_targets ) );
            $prior_reason = $summary['reason'];
            call_user_func( $targets[ $target_name ], $budget );
            $target_reason = $summary['reason'];
            if ( $prior_reason !== '' ) {
                $summary['reason'] = $prior_reason;
            }
            $canonical_index = array_search( $target_name, $canonical_names, true );
            $progress['next_family'] = $canonical_names[ ( $canonical_index + 1 ) % count( $canonical_names ) ];
            $remaining_targets--;
            if ( self::remote_provider_failed( $target_reason ) ) {
                return;
            }
        }

        $summary['reached_limit'] = (int) $summary['scanned'] >= (int) $summary['limit'];
    }

    private static function remote_provider_failed( $reason ) {
        return is_string( $reason ) && strpos( $reason, 'remote_delete_failed' ) !== false;
    }

    private static function scan_managed_aggregates( $family, $target, $config, $now, &$summary, $budget, &$progress ) {
        if ( $budget <= 0 ) {
            return;
        }
        $remote_delete = function ( $object_key, $object_version, $artifact_store_identity ) use ( $now ) {
            return WorkerClient::delete_object(
                $object_key,
                $object_version,
                $artifact_store_identity,
                $now,
                null,
                'aggregate_gc'
            );
        };
        $result = UploadBatchStore::gc_aggregates(
            $family,
            self::uploads_dir( $config ),
            $now,
            $budget,
            ! empty( $summary['dry_run'] ),
            $progress['families'][ $target ],
            $remote_delete
        );
        $result = is_array( $result ) ? $result : array();
        if ( isset( $result['cursor'] ) && is_array( $result['cursor'] ) ) {
            $progress['families'][ $target ] = $result['cursor'];
        }
        foreach ( array( 'scanned', 'candidates', 'candidate_bytes', 'deleted', 'deleted_bytes' ) as $key ) {
            $value = isset( $result[ $key ] ) && is_numeric( $result[ $key ] ) ? max( 0, (int) $result[ $key ] ) : 0;
            $summary[ $key ] += $value;
            $summary['by_type'][ $target ][ $key ] += $value;
        }
        foreach ( array( 'candidate_artifact_bytes', 'deleted_artifact_bytes', 'released_bytes', 'errors' ) as $key ) {
            $summary['by_type'][ $target ][ $key ] = isset( $result[ $key ] ) && is_numeric( $result[ $key ] )
                ? max( 0, (int) $result[ $key ] )
                : 0;
        }
        $summary['by_type'][ $target ]['reason'] = isset( $result['reason'] ) && is_string( $result['reason'] ) ? $result['reason'] : '';
        if ( empty( $result['ok'] ) || $summary['by_type'][ $target ]['errors'] > 0 ) {
            $summary['reason'] = 'managed_' . $family . '_' . ( $summary['by_type'][ $target ]['reason'] !== '' ? $summary['by_type'][ $target ]['reason'] : 'gc_failed' );
        }
    }

    private static function scan_preview_fences( $lifecycle, $now, &$summary, $budget, &$cursor ) {
        if ( $budget <= 0 ) {
            return;
        }
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            $summary['by_type']['preview_fences']['errors']++;
            $summary['by_type']['preview_fences']['reason'] = 'preview_fence_lifecycle_unavailable';
            $summary['reason'] = 'preview_fences_lifecycle_unavailable';
            return;
        }
        $result = LocalPreviewProvider::gc_deleted_fences(
            $lifecycle,
            $now,
            $budget,
            ! empty( $summary['dry_run'] ),
            $cursor
        );
        $result = is_array( $result ) ? $result : array();
        $cursor = isset( $result['cursor'] ) && is_array( $result['cursor'] ) ? $result['cursor'] : array();
        foreach ( array( 'scanned', 'candidates', 'candidate_bytes', 'deleted', 'deleted_bytes' ) as $key ) {
            $value = isset( $result[ $key ] ) && is_numeric( $result[ $key ] ) ? max( 0, (int) $result[ $key ] ) : 0;
            $summary[ $key ] += $value;
            $summary['by_type']['preview_fences'][ $key ] += $value;
        }
        if ( empty( $result['ok'] ) ) {
            $summary['by_type']['preview_fences']['errors']++;
            $summary['by_type']['preview_fences']['reason'] = isset( $result['reason'] ) ? (string) $result['reason'] : 'preview_fence_gc_failed';
            $summary['reason'] = 'preview_fences_' . $summary['by_type']['preview_fences']['reason'];
        }
    }

    private static function scan_tokens( $private_dir, $now, &$summary, $budget, &$cursor ) {
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
            },
            $budget,
            $cursor
        );
    }

    private static function scan_ledger( $private_dir, $now, $eligible_age_seconds, &$summary, $budget, &$cursor ) {
        $ledger_dir = rtrim( $private_dir, '/\\' ) . '/' . self::LEDGER_DIR;
        self::scan_files(
            $ledger_dir,
            'ledger',
            $summary,
            function ( $path ) use ( $now, $eligible_age_seconds ) {
                $basename = basename( $path );
                $is_marker = substr( $path, -strlen( self::LEDGER_SUFFIX ) ) === self::LEDGER_SUFFIX;
                $is_lock = $basename === Ledger::SHARD_LOCK_FILENAME;
                if ( ! $is_marker && ! $is_lock ) {
                    return false;
                }
                if ( $is_lock && ( is_link( $path ) || self::ledger_shard_has_marker( dirname( $path ) ) ) ) {
                    return false;
                }

                $mtime = @filemtime( $path );
                if ( ! is_int( $mtime ) ) {
                    return false;
                }

                return $now >= ( $mtime + $eligible_age_seconds );
            },
            $budget,
            $cursor,
            function ( $path ) use ( $private_dir ) {
                if ( basename( $path ) === Ledger::SHARD_LOCK_FILENAME ) {
                    return Ledger::delete_orphan_shard_lock( $path, $private_dir );
                }
                return @unlink( $path );
            }
        );
    }

    private static function ledger_shard_has_marker( $shard_dir ) {
        if ( ! is_dir( $shard_dir ) || is_link( $shard_dir ) ) {
            return false;
        }

        $entries = @scandir( $shard_dir );
        if ( ! is_array( $entries ) ) {
            return true;
        }
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            if ( substr( $entry, -strlen( self::LEDGER_SUFFIX ) ) === self::LEDGER_SUFFIX
                && is_file( rtrim( $shard_dir, '/\\' ) . '/' . $entry )
            ) {
                return true;
            }
        }
        return false;
    }

    private static function scan_uploads( $private_dir, $now, $retention_seconds, &$summary, $budget, &$cursor ) {
        $uploads_dir = rtrim( $private_dir, '/\\' ) . '/' . self::UPLOADS_DIR;
        self::scan_files(
            $uploads_dir,
            'uploads',
            $summary,
            function ( $path ) use ( $private_dir, $uploads_dir, $now, $retention_seconds ) {
                if ( self::is_upload_control_file( $uploads_dir, $path ) ) {
                    return false;
                }

                $recovery_submission = UploadStore::staged_recovery_submission_id( $uploads_dir, $path );
                if ( $recovery_submission === '' && $retention_seconds <= 0 ) {
                    return false;
                }
                if ( $recovery_submission !== '' && ! UploadBatchStore::submission_aggregate_absent( $private_dir, $recovery_submission ) ) {
                    return false;
                }

                $mtime = @filemtime( $path );
                if ( ! is_int( $mtime ) ) {
                    return false;
                }

                $eligible_age = $recovery_submission !== ''
                    ? Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' )
                    : $retention_seconds;
                return $now >= ( $mtime + $eligible_age );
            },
            $budget,
            $cursor,
            function ( $path ) use ( $private_dir, $uploads_dir ) {
                $recovery_submission = UploadStore::staged_recovery_submission_id( $uploads_dir, $path );
                if ( $recovery_submission !== '' && ! UploadBatchStore::submission_aggregate_absent( $private_dir, $recovery_submission ) ) {
                    return false;
                }
                return @unlink( $path );
            }
        );
    }

    private static function scan_throttle( $private_dir, $now, &$summary, $budget, &$cursor ) {
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

                return ( $now - $mtime ) > Anchors::get( 'THROTTLE_STALE_SECONDS' );
            },
            $budget,
            $cursor
        );
    }

    private static function scan_declined( $config, $now, &$summary, $budget, &$cursor ) {
        if ( $budget <= 0 ) {
            return;
        }

        $result = DeclinedReviewLog::prune_expired(
            $config,
            $now,
            array(
                'dry_run' => ! empty( $summary['dry_run'] ),
                'limit' => $budget,
                'cursor' => $cursor,
            )
        );

        $result = is_array( $result ) ? $result : array();
        if ( isset( $result['cursor'] ) && is_array( $result['cursor'] ) ) {
            $cursor = $result['cursor'];
        }
        foreach ( array( 'scanned', 'candidates', 'candidate_bytes', 'deleted', 'deleted_bytes' ) as $key ) {
            $value = isset( $result[ $key ] ) && is_numeric( $result[ $key ] ) ? max( 0, (int) $result[ $key ] ) : 0;
            $summary[ $key ] += $value;
            $summary['by_type']['declined'][ $key ] += $value;
        }

    }

    private static function scan_files( $dir, $target, &$summary, $is_candidate, $budget, &$cursor, $delete_candidate = null ) {
        $family_scanned = 0;
        $after_path = isset( $cursor['path'] ) && is_string( $cursor['path'] ) ? $cursor['path'] : '';
        $last_path = $after_path;
        $scan_error = '';
        $complete = self::scan_file_tree( $dir, $dir, $target, $summary, $is_candidate, $budget, $family_scanned, $after_path, $last_path, $scan_error, $delete_candidate );
        if ( $scan_error !== '' ) {
            $summary['by_type'][ $target ]['errors']++;
            $summary['by_type'][ $target ]['reason'] = $scan_error;
            if ( $summary['reason'] === '' ) {
                $summary['reason'] = $target . '_' . $scan_error;
            }
            return;
        }
        $cursor = $complete || $last_path === '' ? array() : array( 'path' => $last_path );
    }

    private static function scan_file_tree( $root, $dir, $target, &$summary, $is_candidate, $budget, &$family_scanned, $after_path, &$last_path, &$scan_error, $delete_candidate = null ) {
        if ( $family_scanned >= $budget || $summary['scanned'] >= $summary['limit'] ) {
            return false;
        }
        if ( ! file_exists( $dir ) ) {
            return true;
        }
        if ( ! is_dir( $dir ) || is_link( $dir ) ) {
            $scan_error = 'directory_enumeration_failed';
            return false;
        }

        $entry_after = self::cursor_child( $root, $dir, $after_path );
        if ( $entry_after !== '' ) {
            $resume_path = $dir . '/' . $entry_after;
            if ( is_dir( $resume_path ) && ! is_link( $resume_path ) ) {
                if ( ! self::scan_file_tree( $root, $resume_path, $target, $summary, $is_candidate, $budget, $family_scanned, $after_path, $last_path, $scan_error, $delete_candidate ) ) {
                    return false;
                }
            }
        }
        while ( $family_scanned < $budget && $summary['scanned'] < $summary['limit'] ) {
            $page_limit = max( 1, $budget - $family_scanned );
            $page = PrivateDir::bounded_entries_result( $dir, $entry_after, $page_limit );
            if ( empty( $page['ok'] ) ) {
                $scan_error = 'directory_enumeration_failed';
                return false;
            }
            $entries = $page['entries'];
            if ( empty( $entries ) ) {
                return true;
            }

            foreach ( $entries as $entry ) {
                $entry_after = $entry;
                if ( $family_scanned >= $budget || $summary['scanned'] >= $summary['limit'] ) {
                    return false;
                }

                $path = $dir . '/' . $entry;
                if ( is_link( $path ) ) {
                    continue;
                }
                if ( is_dir( $path ) ) {
                    if ( ! self::scan_file_tree( $root, $path, $target, $summary, $is_candidate, $budget, $family_scanned, $after_path, $last_path, $scan_error, $delete_candidate ) ) {
                        return false;
                    }
                    continue;
                }

                if ( ! is_file( $path ) ) {
                    continue;
                }

                $relative_path = ltrim( substr( $path, strlen( rtrim( $root, '/\\' ) ) ), '/\\' );
                if ( $after_path !== '' && self::compare_relative_paths( $relative_path, $after_path ) <= 0 ) {
                    continue;
                }

                if ( ! self::count_scanned( $target, $summary ) ) {
                    return false;
                }
                $family_scanned++;
                $last_path = $relative_path;

                $candidate = call_user_func( $is_candidate, $path );
                if ( $candidate ) {
                    self::process_candidate( $target, $path, $summary, $delete_candidate );
                }
            }
        }
        return false;
    }

    private static function cursor_child( $root, $dir, $after_path ) {
        $after_path = str_replace( '\\', '/', (string) $after_path );
        if ( $after_path === '' ) {
            return '';
        }
        $relative_dir = ltrim( substr( rtrim( $dir, '/\\' ), strlen( rtrim( $root, '/\\' ) ) ), '/\\' );
        $relative_dir = str_replace( '\\', '/', $relative_dir );
        if ( $relative_dir === '' ) {
            $remaining = $after_path;
        } elseif ( strpos( $after_path, $relative_dir . '/' ) === 0 ) {
            $remaining = substr( $after_path, strlen( $relative_dir ) + 1 );
        } else {
            return '';
        }
        $parts = explode( '/', $remaining );
        return isset( $parts[0] ) && $parts[0] !== '' && basename( $parts[0] ) === $parts[0] ? $parts[0] : '';
    }

    private static function compare_relative_paths( $left, $right ) {
        $left_parts = explode( '/', str_replace( '\\', '/', (string) $left ) );
        $right_parts = explode( '/', str_replace( '\\', '/', (string) $right ) );
        $count = min( count( $left_parts ), count( $right_parts ) );
        for ( $index = 0; $index < $count; $index++ ) {
            $compared = strcmp( $left_parts[ $index ], $right_parts[ $index ] );
            if ( $compared !== 0 ) {
                return $compared;
            }
        }
        return count( $left_parts ) <=> count( $right_parts );
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

    private static function process_candidate( $target, $path, &$summary, $delete_candidate = null ) {
        $bytes = self::file_bytes( $path );

        $summary['candidates']++;
        $summary['candidate_bytes'] += $bytes;
        $summary['by_type'][ $target ]['candidates']++;
        $summary['by_type'][ $target ]['candidate_bytes'] += $bytes;

        $deleted = false;
        if ( ! $summary['dry_run'] ) {
            $deleted = is_callable( $delete_candidate ) ? (bool) call_user_func( $delete_candidate, $path ) : @unlink( $path );
        }

        if ( $deleted ) {
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
        if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
            return array(
                'ok' => false,
                'locked' => false,
                'path' => $path,
                'reason' => 'gc_lock_open_failed',
            );
        }
        $handle = @fopen( $path, 'c+' );
        if ( $handle === false ) {
            return array(
                'ok' => false,
                'locked' => false,
                'path' => $path,
                'reason' => 'gc_lock_open_failed',
            );
        }

        if ( ! @chmod( $path, 0600 ) ) {
            fclose( $handle );
            return array(
                'ok' => false,
                'locked' => false,
                'path' => $path,
                'reason' => 'gc_lock_open_failed',
            );
        }

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

    private static function read_progress( $handle ) {
        $family_defaults = array(
            'tokens' => array(),
            'ledger' => array(),
            'uploads' => array(),
            'staged_batches' => array(),
            'finalized_submissions' => array(),
            'preview_fences' => array(),
            'throttle' => array(),
            'declined' => array(),
        );
        $default = array(
            'version' => self::PROGRESS_VERSION,
            'next_family' => 'tokens',
            'families' => $family_defaults,
        );
        if ( ! is_resource( $handle ) || ! @rewind( $handle ) ) {
            return $default;
        }
        $raw = stream_get_contents( $handle );
        $value = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
        if ( ! is_array( $value )
            || ! isset( $value['version'], $value['families'] )
            || (int) $value['version'] !== self::PROGRESS_VERSION
            || ! is_array( $value['families'] )
        ) {
            return $default;
        }
        foreach ( $family_defaults as $family => $cursor ) {
            if ( ! isset( $value['families'][ $family ] ) || ! is_array( $value['families'][ $family ] ) ) {
                $value['families'][ $family ] = $cursor;
            }
        }
        if ( ! isset( $value['next_family'] )
            || ! is_string( $value['next_family'] )
            || ! array_key_exists( $value['next_family'], $family_defaults )
        ) {
            $value['next_family'] = 'tokens';
        }
        return $value;
    }

    private static function write_progress( $handle, $progress ) {
        $json = json_encode( $progress, JSON_UNESCAPED_SLASHES );
        if ( ! is_resource( $handle ) || ! is_string( $json ) || ! @rewind( $handle ) || ! @ftruncate( $handle, 0 ) ) {
            return false;
        }
        $written = fwrite( $handle, $json );
        if ( $written !== strlen( $json ) ) {
            return false;
        }
        return ! function_exists( 'fflush' ) || @fflush( $handle );
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
            'capacity_reconciled' => ! empty( $summary['capacity_reconciled'] ),
            'capacity_before_bytes' => isset( $summary['capacity_before_bytes'] ) ? (int) $summary['capacity_before_bytes'] : 0,
            'capacity_after_bytes' => isset( $summary['capacity_after_bytes'] ) ? (int) $summary['capacity_after_bytes'] : 0,
            'stale_reservations_removed' => isset( $summary['stale_reservations_removed'] ) ? (int) $summary['stale_reservations_removed'] : 0,
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
