<?php
/**
 * Locked aggregate storage for managed staged uploads.
 *
 * Contract: Managed Aggregate Contract
 * Contract: Managed staged images
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Security/Entropy.php';
require_once __DIR__ . '/../Submission/SubmissionReviewSnapshot.php';
require_once __DIR__ . '/PrivateDir.php';
require_once __DIR__ . '/ManagedCapacityStore.php';
require_once __DIR__ . '/ManagedArtifactKey.php';
require_once __DIR__ . '/LocalArtifactStore.php';
require_once __DIR__ . '/LocalPreviewProvider.php';
require_once __DIR__ . '/UploadPolicy.php';
require_once __DIR__ . '/UploadValue.php';

class UploadBatchStore {
    private const JSON_TEMP_ENTROPY_BYTES = 8;
    private const AGGREGATE_CLEANUP_GC = 'gc';
    private const AGGREGATE_CLEANUP_OPERATOR_DELETE = 'operator_delete';
    private const REMOTE_PURGE_RESERVATIONS = 'reservations';
    private const VALIDATION_RETIREMENT_DIR = 'validation-retirements';

    const STAGED_DIR = 'staged';
    const SUBMISSIONS_DIR = 'submissions';
    const ARTIFACTS_DIR = LocalArtifactStore::ROOT_DIR;
    const PREVIEW_CACHE_DIR = LocalPreviewProvider::ROOT_DIR;
    const MANIFEST_FILENAME = 'manifest.json';
    const REVIEW_SNAPSHOT_FILENAME = 'review.json';
    const LOCK_FILENAME = '.lock';
    const CAPACITY_FILENAME = 'managed-capacity.json';
    const CAPACITY_LOCK_FILENAME = 'managed-capacity.lock';
    const REMOTE_PURGE_FILENAME = 'remote-purge.json';
    const MANIFEST_VERSION = 6;
    const WORKER_MANIFEST_VERSION = 7;
    const CAPACITY_VERSION = 7;
    const REMOTE_PURGE_VERSION = 1;
    const VALIDATION_RETIREMENT_VERSION = 1;
    const LOCAL_ARTIFACT_STORE_IDENTITY = 'local';

    public static function aggregate_lock_path( $family, $aggregate ) {
        if ( ! is_string( $aggregate ) || $aggregate === '' ) {
            return '';
        }
        $aggregate = rtrim( $aggregate, '/\\' );
        if ( $family === self::STAGED_DIR ) {
            // A staged aggregate moves into submissions during finalization.
            // Keep its lock beside—not inside—the moved directory so Windows
            // can rename the directory while this handle is held.
            return dirname( $aggregate ) . '/.' . basename( $aggregate ) . self::LOCK_FILENAME;
        }
        return $family === self::SUBMISSIONS_DIR ? $aggregate . '/' . self::LOCK_FILENAME : '';
    }

    public static function acquire_purge_capacity_lock( $lifecycle ) {
        if ( ! $lifecycle instanceof PrivateDirLease || ! $lifecycle->exclusive() ) {
            return false;
        }
        $private_dir = $lifecycle->private_dir();
        if ( ! is_string( $private_dir ) || $private_dir === '' || ! is_dir( $private_dir ) || is_link( $private_dir ) ) {
            return false;
        }
        $path = rtrim( $private_dir, '/\\' ) . '/' . self::CAPACITY_LOCK_FILENAME;
        if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
            return false;
        }
        return ManagedCapacityStore::acquire_lock( $path, true, true );
    }

    public static function prelock_purge_aggregates( $lifecycle, $repair_locks = true, $create_staged_locks = true ) {
        if ( ! $lifecycle instanceof PrivateDirLease || ! $lifecycle->exclusive() ) {
            return false;
        }
        $private_dir = $lifecycle->private_dir();
        if ( ! is_string( $private_dir ) || $private_dir === '' || ! is_dir( $private_dir ) || is_link( $private_dir ) ) {
            return false;
        }

        $handles = array();
        foreach ( array( self::STAGED_DIR, self::SUBMISSIONS_DIR ) as $family ) {
            $root = rtrim( $private_dir, '/\\' ) . '/' . $family;
            if ( is_link( $root ) ) {
                self::release_purge_locks( $handles );
                return false;
            }
            if ( ! is_dir( $root ) ) {
                continue;
            }
            $shards = @scandir( $root, SCANDIR_SORT_ASCENDING );
            if ( ! is_array( $shards ) ) {
                self::release_purge_locks( $handles );
                return false;
            }
            foreach ( $shards as $shard ) {
                if ( $shard === '.' || $shard === '..' ) {
                    continue;
                }
                $shard_path = $root . '/' . $shard;
                if ( is_link( $shard_path ) ) {
                    self::release_purge_locks( $handles );
                    return false;
                }
                if ( ! is_dir( $shard_path ) ) {
                    continue;
                }
                $aggregates = @scandir( $shard_path, SCANDIR_SORT_ASCENDING );
                if ( ! is_array( $aggregates ) ) {
                    self::release_purge_locks( $handles );
                    return false;
                }
                foreach ( $aggregates as $aggregate ) {
                    if ( $aggregate === '.' || $aggregate === '..' ) {
                        continue;
                    }
                    $aggregate_path = $shard_path . '/' . $aggregate;
                    if ( is_link( $aggregate_path ) ) {
                        self::release_purge_locks( $handles );
                        return false;
                    }
                    if ( ! is_dir( $aggregate_path ) ) {
                        continue;
                    }
                    $handle = $family === self::STAGED_DIR
                        ? self::acquire_staged_lock( $aggregate_path, $create_staged_locks, true, $repair_locks )
                        : self::acquire_existing_lock( self::aggregate_lock_path( $family, $aggregate_path ), true, $repair_locks );
                    if ( $handle === false ) {
                        self::release_purge_locks( $handles );
                        return false;
                    }
                    $handles[] = $handle;
                }
            }
        }

        return $handles;
    }

    public static function release_purge_locks( $handles ) {
        foreach ( is_array( $handles ) ? $handles : array( $handles ) as $handle ) {
            if ( is_resource( $handle ) ) {
                @flock( $handle, LOCK_UN );
                fclose( $handle );
            }
        }
    }

    public static function prepare_worker_remote_purge( $lifecycle, $composition_fingerprint, $now = null ) {
        if ( ! $lifecycle instanceof PrivateDirLease
            || ! $lifecycle->exclusive()
            || ! self::valid_composition_fingerprint( $composition_fingerprint )
        ) {
            return array( 'ok' => false, 'reason' => 'remote_purge_invalid' );
        }
        $now = self::now( $now );
        $private_dir = rtrim( $lifecycle->private_dir(), '/\\' );
        $record_path = $private_dir . '/' . self::REMOTE_PURGE_FILENAME;
        $marker_path = $private_dir . '/' . PrivateDir::PURGE_MARKER_FILENAME;
        $marker_exists = is_file( $marker_path ) && ! is_link( $marker_path );
        if ( ( file_exists( $marker_path ) || is_link( $marker_path ) ) && ! $marker_exists ) {
            return array( 'ok' => false, 'reason' => 'purge_barrier_unavailable' );
        }

        $capacity_lock = self::acquire_purge_capacity_lock( $lifecycle );
        if ( ! is_resource( $capacity_lock ) ) {
            return array( 'ok' => false, 'reason' => 'managed_capacity_lock_unavailable' );
        }
        $aggregate_locks = self::prelock_purge_aggregates( $lifecycle, false, false );
        if ( ! is_array( $aggregate_locks ) ) {
            self::release_purge_locks( $capacity_lock );
            return array( 'ok' => false, 'reason' => 'managed_aggregate_lock_unavailable' );
        }

        $record = $marker_exists ? self::read_remote_purge_record( $record_path ) : null;
        if ( $marker_exists && is_array( $record ) ) {
            self::release_purge_locks( $aggregate_locks );
            self::release_purge_locks( $capacity_lock );
            if ( ! hash_equals( $record['composition_fingerprint'], $composition_fingerprint ) ) {
                return array( 'ok' => false, 'reason' => 'remote_purge_composition_changed' );
            }
            return array( 'ok' => true, 'ready' => false, 'safe_after' => $record['safe_after'], 'phase' => $record['phase'] );
        }

        $safe_after = self::worker_remote_purge_safe_after( $private_dir, $now );
        if ( $safe_after === null ) {
            self::release_purge_locks( $aggregate_locks );
            self::release_purge_locks( $capacity_lock );
            return array( 'ok' => false, 'reason' => 'worker_remote_purge_authority_invalid' );
        }
        $record = array(
            'version' => self::REMOTE_PURGE_VERSION,
            'phase' => 'draining',
            'started_at' => $now,
            'safe_after' => $safe_after,
            'composition_fingerprint' => $composition_fingerprint,
            'next_family' => self::STAGED_DIR,
            'cursor' => array(),
            'updated_at' => $now,
            'last_failure_class' => '',
        );
        $record_written = self::write_remote_purge_record( $record_path, $record );
        $barrier_written = $record_written && ( $marker_exists || PrivateDir::mark_purged( $lifecycle ) );
        if ( ! $record_written || ! $barrier_written ) {
            self::release_purge_locks( $aggregate_locks );
            self::release_purge_locks( $capacity_lock );
            return array(
                'ok' => false,
                'reason' => $record_written ? 'purge_barrier_unavailable' : 'remote_purge_record_write_failed',
            );
        }
        self::release_purge_locks( $aggregate_locks );
        self::release_purge_locks( $capacity_lock );
        return array( 'ok' => true, 'ready' => false, 'safe_after' => $safe_after, 'phase' => 'draining' );
    }

    public static function resume_worker_remote_purge( $lifecycle, $composition_fingerprint, $remote_delete, $now = null ) {
        if ( ! $lifecycle instanceof PrivateDirLease
            || ! $lifecycle->exclusive()
            || ! self::valid_composition_fingerprint( $composition_fingerprint )
            || ! is_callable( $remote_delete )
        ) {
            return array( 'ok' => false, 'reason' => 'remote_purge_invalid' );
        }
        $now = self::now( $now );
        $private_dir = rtrim( $lifecycle->private_dir(), '/\\' );
        $marker_path = $private_dir . '/' . PrivateDir::PURGE_MARKER_FILENAME;
        $record_path = $private_dir . '/' . self::REMOTE_PURGE_FILENAME;
        if ( ! is_file( $marker_path ) || is_link( $marker_path ) ) {
            return array( 'ok' => false, 'reason' => 'purge_barrier_unavailable' );
        }
        $record = self::read_remote_purge_record( $record_path );
        if ( ! is_array( $record ) ) {
            return array( 'ok' => false, 'reason' => 'remote_purge_record_invalid' );
        }
        if ( ! hash_equals( $record['composition_fingerprint'], $composition_fingerprint ) ) {
            return array( 'ok' => false, 'reason' => 'remote_purge_composition_changed' );
        }
        if ( $record['next_family'] === 'done' ) {
            return array( 'ok' => true, 'ready' => true, 'phase' => 'purging' );
        }
        if ( $now < $record['safe_after'] ) {
            return array( 'ok' => true, 'ready' => false, 'safe_after' => $record['safe_after'], 'phase' => 'draining' );
        }

        $record['phase'] = 'purging';
        $record['updated_at'] = $now;
        $record['last_failure_class'] = '';
        if ( ! self::write_remote_purge_record( $record_path, $record ) ) {
            return array( 'ok' => false, 'reason' => 'remote_purge_record_write_failed' );
        }

        if ( $record['next_family'] === self::REMOTE_PURGE_RESERVATIONS ) {
            $purged = self::purge_worker_remote_reservation_page( $remote_delete, $now, $lifecycle );
            if ( empty( $purged['ok'] ) ) {
                $record['updated_at'] = $now;
                $record['last_failure_class'] = isset( $purged['reason'] ) && $purged['reason'] === 'remote_delete_failed'
                    ? 'provider_failure'
                    : 'local_state_failure';
                self::write_remote_purge_record( $record_path, $record );
                return array( 'ok' => false, 'reason' => isset( $purged['reason'] ) ? $purged['reason'] : 'remote_purge_failed' );
            }
            $record['next_family'] = ! empty( $purged['complete'] ) ? 'done' : self::REMOTE_PURGE_RESERVATIONS;
            $record['cursor'] = array();
            $record['updated_at'] = $now;
            if ( ! self::write_remote_purge_record( $record_path, $record ) ) {
                return array( 'ok' => false, 'reason' => 'remote_purge_record_write_failed' );
            }
            return $record['next_family'] === 'done'
                ? array( 'ok' => true, 'ready' => true, 'phase' => 'purging' )
                : array( 'ok' => true, 'ready' => false, 'safe_after' => $record['safe_after'], 'phase' => 'purging' );
        }

        $families = array( self::STAGED_DIR, self::SUBMISSIONS_DIR );
        $family_index = array_search( $record['next_family'], $families, true );
        $family_index = $family_index === false ? 0 : (int) $family_index;
        $family = $families[ $family_index ];
        $purged = self::purge_worker_remote_family(
            $family,
            $private_dir,
            $record['cursor'],
            $remote_delete,
            $now,
            $lifecycle
        );
        if ( empty( $purged['ok'] ) ) {
            $record['next_family'] = $family;
            $record['cursor'] = isset( $purged['cursor'] ) && is_array( $purged['cursor'] ) ? $purged['cursor'] : $record['cursor'];
            $record['updated_at'] = $now;
            $record['last_failure_class'] = isset( $purged['reason'] ) && $purged['reason'] === 'remote_delete_failed'
                ? 'provider_failure'
                : 'local_state_failure';
            self::write_remote_purge_record( $record_path, $record );
            return array( 'ok' => false, 'reason' => isset( $purged['reason'] ) ? $purged['reason'] : 'remote_purge_failed' );
        }
        if ( empty( $purged['complete'] ) ) {
            $record['next_family'] = $family;
            $record['cursor'] = $purged['cursor'];
        } else {
            $record['next_family'] = $family_index + 1 < count( $families )
                ? $families[ $family_index + 1 ]
                : self::REMOTE_PURGE_RESERVATIONS;
            $record['cursor'] = array();
        }
        $record['updated_at'] = $now;
        if ( ! self::write_remote_purge_record( $record_path, $record ) ) {
            return array( 'ok' => false, 'reason' => 'remote_purge_record_write_failed' );
        }
        return $record['next_family'] === 'done'
            ? array( 'ok' => true, 'ready' => true, 'phase' => 'purging' )
            : array( 'ok' => true, 'ready' => false, 'safe_after' => $record['safe_after'], 'phase' => 'purging' );
    }

    public static function remote_artifacts_present( $lifecycle, $capacity_locked = false, $strict = true ) {
        if ( ! $lifecycle instanceof PrivateDirLease || ( ! $lifecycle->exclusive() && ! $capacity_locked ) ) {
            return array( 'ok' => false, 'present' => false, 'reason' => 'upload_lifecycle_unavailable' );
        }
        $private_dir = $lifecycle->private_dir();
        $capacity_lock = $capacity_locked ? null : self::acquire_purge_capacity_lock( $lifecycle );
        if ( ! $capacity_locked && ! is_resource( $capacity_lock ) ) {
            return array( 'ok' => false, 'present' => false, 'reason' => 'managed_capacity_lock_unavailable' );
        }
        $capacity = ManagedCapacityStore::read(
            rtrim( $private_dir, '/\\' ) . '/' . self::CAPACITY_FILENAME,
            self::CAPACITY_VERSION
        );
        if ( is_resource( $capacity_lock ) ) {
            self::release_lock( $capacity_lock );
        }
        if ( ! is_array( $capacity ) ) {
            return array( 'ok' => false, 'present' => false, 'reason' => 'capacity_invalid' );
        }
        $worker_reservation_present = false;
        foreach ( $capacity['reservations'] as $reservation ) {
            if ( $reservation['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
                $worker_reservation_present = true;
                break;
            }
        }
        $worker_manifest_present = false;
        foreach ( array( self::STAGED_DIR, self::SUBMISSIONS_DIR ) as $root_name ) {
            $root = rtrim( $private_dir, '/\\' ) . '/' . $root_name;
            if ( is_link( $root ) || ( file_exists( $root ) && ! is_dir( $root ) ) ) {
                return array( 'ok' => false, 'present' => false, 'reason' => 'aggregate_enumeration_failed' );
            }
            if ( ! is_dir( $root ) ) {
                continue;
            }
            $cursor = array();
            do {
                $page = self::aggregate_paths( $root, Anchors::get( 'MANAGED_REMOTE_PURGE_AGGREGATE_PAGE_SIZE' ), $cursor, false );
                if ( empty( $page['ok'] ) ) {
                    return array( 'ok' => false, 'present' => false, 'reason' => 'aggregate_enumeration_failed' );
                }
                foreach ( $page['paths'] as $path ) {
                    $manifest_path = rtrim( $path, '/\\' ) . '/' . self::MANIFEST_FILENAME;
                    if ( ! is_file( $manifest_path ) ) {
                        if ( $root_name === self::STAGED_DIR && ( ! $strict || self::initializable_partial_batch( $path ) ) ) {
                            continue;
                        }
                        return array( 'ok' => false, 'present' => false, 'reason' => 'manifest_invalid' );
                    }
                    $aggregate_family = $root_name === self::STAGED_DIR ? 'staged' : 'submission';
                    $worker_version = self::worker_manifest_file_version( $manifest_path );
                    if ( $worker_version === false ) {
                        return array( 'ok' => false, 'present' => false, 'reason' => 'manifest_invalid' );
                    }
                    if ( $worker_version === self::WORKER_MANIFEST_VERSION ) {
                        $manifest = self::read_worker_manifest( $manifest_path, $aggregate_family, basename( $path ), true, false );
                    } else {
                        $manifest = self::read_manifest( $manifest_path, $aggregate_family, basename( $path ), false );
                    }
                    if ( $manifest === null ) {
                        return array( 'ok' => false, 'present' => false, 'reason' => 'manifest_invalid' );
                    }
                    if ( $worker_version !== self::WORKER_MANIFEST_VERSION && $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
                        return array( 'ok' => false, 'present' => false, 'reason' => 'manifest_invalid' );
                    }
                    if ( $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
                        $worker_manifest_present = true;
                    }
                }
                $cursor = $page['cursor'];
            } while ( ! empty( $cursor ) );
        }
        if ( ( $worker_manifest_present || $worker_reservation_present )
            && ! self::worker_capacity_globally_consistent( $private_dir, $capacity )
        ) {
            return array( 'ok' => false, 'present' => false, 'reason' => 'capacity_invalid' );
        }
        return array( 'ok' => true, 'present' => $worker_manifest_present || $worker_reservation_present, 'reason' => '' );
    }

    public static function purge_managed_storage_roots( $lifecycle, $remove_tree ) {
        if ( ! $lifecycle instanceof PrivateDirLease || ! $lifecycle->exclusive() || ! is_callable( $remove_tree ) ) {
            return array( 'ok' => false, 'removed' => false, 'reason' => 'upload_lifecycle_unavailable' );
        }
        foreach ( self::purge_managed_root_paths( $lifecycle ) as $path ) {
            call_user_func( $remove_tree, $path );
        }
        if ( ! self::purge_managed_roots_absent( $lifecycle ) ) {
            return array( 'ok' => false, 'removed' => false, 'reason' => 'upload_purge_incomplete' );
        }
        if ( ! self::finish_purged_capacity_state( $lifecycle, $remove_tree ) ) {
            return array( 'ok' => false, 'removed' => true, 'reason' => 'capacity_purge_incomplete' );
        }
        return array( 'ok' => true, 'removed' => true, 'reason' => '' );
    }

    private static function purge_managed_root_paths( $lifecycle ) {
        if ( ! $lifecycle instanceof PrivateDirLease || ! $lifecycle->exclusive() ) {
            return array();
        }
        $private_dir = rtrim( $lifecycle->private_dir(), '/\\' );
        return array(
            'staged' => $private_dir . '/' . self::STAGED_DIR,
            'submissions' => $private_dir . '/' . self::SUBMISSIONS_DIR,
            'artifacts' => $private_dir . '/' . self::ARTIFACTS_DIR,
            'preview_cache' => $private_dir . '/' . self::PREVIEW_CACHE_DIR,
            'validation_retirements' => $private_dir . '/' . self::VALIDATION_RETIREMENT_DIR,
        );
    }

    private static function purge_managed_roots_absent( $lifecycle ) {
        $paths = self::purge_managed_root_paths( $lifecycle );
        if ( empty( $paths ) ) {
            return false;
        }
        foreach ( $paths as $path ) {
            if ( file_exists( $path ) || is_link( $path ) ) {
                return false;
            }
        }
        return true;
    }

    private static function finish_purged_capacity_state( $lifecycle, $remove_tree ) {
        if ( ! $lifecycle instanceof PrivateDirLease || ! $lifecycle->exclusive() || ! is_callable( $remove_tree ) || ! self::purge_managed_roots_absent( $lifecycle ) ) {
            return false;
        }
        $private_dir = rtrim( $lifecycle->private_dir(), '/\\' );
        $capacity_path = $private_dir . '/' . self::CAPACITY_FILENAME;
        $record_path = $private_dir . '/' . self::REMOTE_PURGE_FILENAME;
        if ( is_link( $capacity_path ) || is_link( $record_path ) ) {
            return false;
        }
        if ( ! is_file( $capacity_path ) ) {
            return ! file_exists( $capacity_path ) && self::remove_purged_remote_record( $record_path, $remove_tree );
        }
        call_user_func( $remove_tree, $capacity_path );
        if ( file_exists( $capacity_path ) || is_link( $capacity_path ) ) {
            return false;
        }
        return self::remove_purged_remote_record( $record_path, $remove_tree );
    }

    private static function remove_purged_remote_record( $record_path, $remove_tree ) {
        if ( is_link( $record_path ) || ( file_exists( $record_path ) && ! is_file( $record_path ) ) ) {
            return false;
        }
        if ( is_file( $record_path ) ) {
            call_user_func( $remove_tree, $record_path );
        }
        return ! file_exists( $record_path ) && ! is_link( $record_path );
    }

    public static function capacity_platform_supported( $integer_bytes = null ) {
        $integer_bytes = $integer_bytes === null ? PHP_INT_SIZE : $integer_bytes;
        return is_numeric( $integer_bytes ) && (int) $integer_bytes >= 8;
    }

    public static function canonical_policy( $field ) {
        $field = is_array( $field ) ? $field : array();
        $accept = isset( $field['accept'] ) && is_array( $field['accept'] )
            ? UploadPolicy::canonical_tokens( $field['accept'] )
            : UploadPolicy::canonical_tokens( UploadPolicy::default_tokens() );
        $limits = UploadPolicy::effective_staged_limits( $field );

        return array(
            'accept' => array_values( $accept ),
            'max_file_bytes' => $limits['max_file_bytes'],
            'max_files' => $limits['max_files'],
            'max_total_bytes' => $limits['max_total_bytes'],
            'upload_mode' => isset( $field['upload_mode'] ) && $field['upload_mode'] === 'staged' ? 'staged' : 'synchronous',
        );
    }

    public static function policy_fingerprint( $field ) {
        $json = json_encode( self::canonical_policy( $field ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }

    public static function encode_parts( $parts ) {
        if ( ! is_array( $parts ) ) {
            return '';
        }

        $encoded = '';
        foreach ( $parts as $part ) {
            if ( ! is_string( $part ) || strlen( $part ) > 0xffffffff ) {
                return '';
            }
            $encoded .= pack( 'N', strlen( $part ) ) . $part;
        }
        return $encoded;
    }

    public static function derive_batch_id( $raw_token, $form_id, $instance_id, $field_key, $policy_fingerprint ) {
        foreach ( array( $raw_token, $form_id, $instance_id, $field_key, $policy_fingerprint ) as $value ) {
            if ( ! is_string( $value ) || $value === '' ) {
                return '';
            }
        }

        $message = self::encode_parts(
            array(
                'eforms-upload-batch-id',
                '1',
                $form_id,
                $instance_id,
                hash( 'sha256', $raw_token ),
                $field_key,
                $policy_fingerprint,
            )
        );
        if ( $message === '' ) {
            return '';
        }

        return self::base64url( hash_hmac( 'sha256', $message, $raw_token, true ) );
    }

    public static function create_batch( $binding, $batch_secret, $field, $uploads_dir, $now = null, $artifact_store = FormProtocol::UPLOAD_TRANSPORT_LOCAL, $artifact_store_identity = self::LOCAL_ARTIFACT_STORE_IDENTITY ) {
        $binding = is_array( $binding ) ? $binding : array();
        $now = self::now( $now );
        $raw_token = self::string_value( $binding, 'raw_token' );
        $form_id = self::string_value( $binding, 'form_id' );
        $instance_id = self::string_value( $binding, 'instance_id' );
        $field_key = self::string_value( $binding, 'field_key' );
        $accept_until = self::int_value( $binding, 'accept_until' );
        $policy = self::canonical_policy( $field );

        if ( $raw_token === ''
            || $form_id === ''
            || $instance_id === ''
            || $field_key === ''
            || ! self::valid_artifact_store( $artifact_store )
            || ! self::valid_artifact_store_identity( $artifact_store, $artifact_store_identity )
            || ! self::valid_staged_policy( $policy )
        ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'binding_invalid' );
        }
        if ( $accept_until <= $now ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'token_expired' );
        }

        $secret_digest = self::secret_digest( $batch_secret );
        if ( $secret_digest === '' ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_secret_invalid' );
        }

        $policy_fingerprint = self::policy_fingerprint( $field );
        $batch_id = self::derive_batch_id( $raw_token, $form_id, $instance_id, $field_key, $policy_fingerprint );
        if ( $batch_id === '' ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_id_failed' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }

        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            $lifecycle->release();
            return $capacity;
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        if ( ! is_array( $record ) ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }

        $root = self::managed_root( $uploads_dir, self::STAGED_DIR, true, $lifecycle );
        if ( $root === '' ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'staged_root_unavailable' );
        }
        $shard = $root . '/' . Helpers::h2( $batch_id );
        if ( ! self::ensure_dir( $shard ) ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'staged_shard_unavailable' );
        }

        $aggregate = $shard . '/' . $batch_id;
        $created = false;
        if ( is_link( $aggregate ) || ! is_dir( $aggregate ) ) {
            if ( is_link( $aggregate ) || file_exists( $aggregate ) || ( ! @mkdir( $aggregate, PrivateDir::DIRECTORY_MODE ) && ! is_dir( $aggregate ) ) || is_link( $aggregate ) ) {
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_dir_unavailable' );
            }
            $created = true;
        }
        if ( ! @chmod( $aggregate, PrivateDir::DIRECTORY_MODE ) ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_dir_permissions' );
        }

        $lock = self::acquire_staged_lock( $aggregate, true );
        if ( $lock === false ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_lock_failed' );
        }

        $manifest_path = $aggregate . '/' . self::MANIFEST_FILENAME;
        if ( is_file( $manifest_path ) ) {
            $manifest = $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER
                ? self::read_worker_manifest( $manifest_path, 'staged', $batch_id )
                : self::read_manifest( $manifest_path, 'staged', $batch_id );
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            if ( $manifest === null ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
            }
            if ( ! self::binding_matches( $manifest, $form_id, $instance_id, hash( 'sha256', $raw_token ), $field_key, $policy_fingerprint )
                || ! hash_equals( $manifest['batch_secret_digest'], $secret_digest )
                || $manifest['artifact_store'] !== $artifact_store
                || ! hash_equals( $manifest['artifact_store_identity'], $artifact_store_identity )
            ) {
                return self::failure( 'EFORMS_ERR_TOKEN', 'batch_conflict' );
            }
            return self::success( array( 'batch' => self::manifest_summary( $manifest ) ) );
        }

        if ( ( ! $created && ! self::initializable_partial_batch( $aggregate ) )
            || ! self::remove_initial_manifest_temps( $aggregate )
        ) {
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_files_unavailable' );
        }

        $manifest = array(
            'version' => $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER ? self::WORKER_MANIFEST_VERSION : self::MANIFEST_VERSION,
            'batch_id' => $batch_id,
            'state' => 'open',
            'artifact_store' => $artifact_store,
            'artifact_store_identity' => $artifact_store_identity,
            'binding' => array(
                'form_id' => $form_id,
                'instance_id' => $instance_id,
                'token_digest' => hash( 'sha256', $raw_token ),
                'field_key' => $field_key,
                'policy_fingerprint' => $policy_fingerprint,
            ),
            'batch_secret_digest' => $secret_digest,
            'policy' => $policy,
            'created_at' => $now,
            'accept_until' => $accept_until,
            'delete_after' => $accept_until + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
            'intents' => array(),
            'items' => array(),
            'tombstones' => array(),
            'artifact_bytes' => 0,
        );
        $written = self::write_json_atomic( $manifest_path, $manifest );
        self::release_lock( $lock );
        self::release_lock( $capacity['lock'] );
        if ( ! $written ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
        }

        return self::success( array( 'batch' => self::manifest_summary( $manifest ) ) );
    }

    public static function status( $batch_id, $batch_secret, $uploads_dir, $now = null ) {
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, true, false );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }

        $worker_manifest = self::read_worker_manifest( $locked['manifest_path'], 'staged', $batch_id, true, false );
        $manifest = $worker_manifest;
        if ( $manifest === null ) {
            $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id, false );
        }
        if ( $manifest === null || ( $worker_manifest === null && $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( self::now( $now ) >= $manifest['delete_after'] ) {
            self::release_lock( $locked['lock'] );
            return self::gone();
        }
        if ( ! self::secret_matches( $manifest, $batch_secret ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_secret_mismatch' );
        }

        $summary = self::manifest_summary( $manifest );
        self::release_lock( $locked['lock'] );
        return self::success( array( 'batch' => $summary ) );
    }

    public static function resolve_open( $batch_id, $batch_secret, $binding, $field, $uploads_dir, $now = null ) {
        $binding = is_array( $binding ) ? $binding : array();
        $raw_token = self::string_value( $binding, 'raw_token' );
        $expected_id = self::derive_batch_id(
            $raw_token,
            self::string_value( $binding, 'form_id' ),
            self::string_value( $binding, 'instance_id' ),
            self::string_value( $binding, 'field_key' ),
            self::policy_fingerprint( $field )
        );
        if ( $expected_id === '' || ! is_string( $batch_id ) || ! hash_equals( $expected_id, $batch_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'binding_mismatch' );
        }

        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, true, false );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $worker_manifest = self::read_worker_manifest( $locked['manifest_path'], 'staged', $batch_id, true, false );
        $manifest = $worker_manifest;
        if ( $manifest === null ) {
            $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id, false );
        }
        $now = self::now( $now );
        if ( $manifest === null || ( $worker_manifest === null && $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( $now >= $manifest['delete_after'] ) {
            self::release_lock( $locked['lock'] );
            return self::gone();
        }
        if ( $manifest['state'] !== 'open' || $now >= $manifest['accept_until'] || ! self::secret_matches( $manifest, $batch_secret ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_not_open' );
        }
        if ( ! self::binding_matches(
            $manifest,
            self::string_value( $binding, 'form_id' ),
            self::string_value( $binding, 'instance_id' ),
            hash( 'sha256', $raw_token ),
            self::string_value( $binding, 'field_key' ),
            self::policy_fingerprint( $field )
        ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'binding_mismatch' );
        }

        $items = $worker_manifest === null ? self::resolved_items( $manifest ) : self::worker_resolved_items( $manifest );
        $summary = self::manifest_summary( $manifest );
        self::release_lock( $locked['lock'] );
        return self::success( array( 'batch' => $summary, 'items' => $items ) );
    }

    public static function authorize_intent( $batch_id, $batch_secret, $upload_id, $ordinal, $display_name, $declared_bytes, $declared_mime, $transient_bytes, $uploads_dir, $options = array() ) {
        $ordinal = is_numeric( $ordinal ) ? (int) $ordinal : -1;
        $declared_bytes = is_numeric( $declared_bytes ) ? (int) $declared_bytes : 0;
        $transient_bytes = is_numeric( $transient_bytes ) ? (int) $transient_bytes : -1;
        $display_name = UploadValue::sanitize_display_name( $display_name );
        $declared_mime = is_string( $declared_mime ) ? strtolower( trim( $declared_mime ) ) : '';
        if ( ! self::valid_upload_id( $upload_id )
            || $ordinal < 0
            || $display_name === ''
            || $declared_bytes < 1
            || ( $transient_bytes !== 0 && $transient_bytes !== $declared_bytes )
        ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'item_identity_invalid' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        $authorization_record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        if ( ! is_array( $authorization_record ) ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return $locked;
        }

        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $now = self::now( isset( $options['now'] ) ? $options['now'] : null );
        $check = self::check_item_mutation( $manifest, $batch_secret, $now );
        if ( empty( $check['ok'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $check;
        }
        if ( $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'artifact_store_mismatch' );
        }

        $declared_mime = self::declared_mime( array( 'type' => $declared_mime ), $display_name, $manifest['policy'] );

        $extension = UploadPolicy::extension_from_name( $display_name );
        $mime_policy = UploadPolicy::policy_for_tokens( $manifest['policy']['accept'], 'staged' );
        if ( $declared_bytes > $manifest['policy']['max_file_bytes']
            || $declared_bytes > Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' )
            || ! UploadPolicy::mime_allowed( $declared_mime, $extension, $mime_policy )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'artifact_declaration_invalid' );
        }

        $intent_id = self::derive_intent_id(
            $batch_id,
            $upload_id,
            $ordinal,
            $display_name,
            $declared_bytes,
            $declared_mime,
            $manifest['binding']['policy_fingerprint']
        );
        $object_key = ManagedArtifactKey::create( $batch_id, $ordinal, $intent_id, $declared_mime );
        if ( $intent_id === '' || $object_key === '' ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'intent_identity_failed' );
        }

        if ( isset( $manifest['items'][ $upload_id ] ) ) {
            $item = $manifest['items'][ $upload_id ];
            $matches = $item['ordinal'] === $ordinal
                && $item['display_name'] === $display_name
                && $item['bytes'] === $declared_bytes
                && $item['object_key'] === $object_key;
            if ( ! $matches ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
            }
            $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
            $settled = self::settle_committed_capacity(
                $record,
                $capacity['path'],
                $batch_id,
                $upload_id,
                $item['object_key'],
                $item['bytes'],
                $now
            );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $settled
                ? self::success( array( 'committed' => true, 'item' => self::item_summary( $item ) ) )
                : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_settlement_failed' );
        }
        if ( isset( $manifest['tombstones'][ $upload_id ] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'item_deleted' );
        }
        if ( isset( $manifest['intents'][ $upload_id ] ) ) {
            $intent = $manifest['intents'][ $upload_id ];
            $matches = $intent['intent_id'] === $intent_id
                && $intent['ordinal'] === $ordinal
                && $intent['display_name'] === $display_name
                && $intent['declared_bytes'] === $declared_bytes
                && $intent['declared_mime'] === $declared_mime
                && $intent['object_key'] === $object_key;
            if ( ! $matches ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
            }
            if ( $now >= $intent['expires_at'] ) {
                // The intent is no longer usable, but it must not remain as an
                // invisible finalization blocker. Reuse the normal tombstone
                // protocol so a delayed writer is fenced before capacity is
                // released. The upload ID stays terminal; the browser may
                // create a fresh ID without risking resurrection of this one.
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                $lifecycle->release();
                $expired = self::delete_item( $batch_id, $batch_secret, $upload_id, $uploads_dir, $now );
                return empty( $expired['ok'] )
                    ? $expired
                    : self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
            }
            $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
            if ( ! is_array( $record ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
            if ( ! ManagedCapacityStore::matches_intent_reservation(
                $record,
                self::reservation_id( $batch_id, $upload_id ),
                $intent['intent_id'],
                $intent['object_key'],
                $batch_id,
                $upload_id,
                $intent['reserved_bytes'],
                $intent['created_at'],
                $manifest['artifact_store'],
                $manifest['artifact_store_identity']
            ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reservation_missing' );
            }
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::success( array( 'committed' => false, 'intent' => self::intent_summary( $intent ) ) );
        }

        if ( count( $manifest['intents'] ) + count( $manifest['items'] ) + count( $manifest['tombstones'] ) >= self::tombstone_limit( $manifest['policy'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'upload_lifetime_exceeded' );
        }
        foreach ( array_merge( $manifest['intents'], $manifest['items'] ) as $existing ) {
            if ( $existing['ordinal'] === $ordinal ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'ordinal_conflict' );
            }
        }
        if ( count( $manifest['intents'] ) + count( $manifest['items'] ) >= $manifest['policy']['max_files'] ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'max_files_exceeded' );
        }
        if ( self::active_artifact_bytes( $manifest ) > $manifest['policy']['max_total_bytes'] - $declared_bytes ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'max_total_bytes_exceeded' );
        }

        $record = $authorization_record;
        $reservation_id = self::reservation_id( $batch_id, $upload_id );
        $reservation = is_array( $record )
            ? self::reserve_capacity(
                $record,
                $reservation_id,
                $intent_id,
                $object_key,
                $batch_id,
                $upload_id,
                $declared_bytes,
                $transient_bytes,
                $manifest['artifact_store_identity'],
                $capacity['private_dir'],
                array_replace( $options, array( 'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_LOCAL ) ),
                $now
            )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        if ( empty( $reservation['ok'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $reservation;
        }
        if ( ! ManagedCapacityStore::write( $capacity['path'], $reservation['record'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_write_failed' );
        }

        $expires_at = min( $manifest['accept_until'], $now + Anchors::get( 'MANAGED_UPLOAD_INTENT_TTL_SECONDS' ) );
        $intent = array(
            'intent_id' => $intent_id,
            'upload_id' => $upload_id,
            'ordinal' => $ordinal,
            'display_name' => $display_name,
            'declared_bytes' => $declared_bytes,
            'declared_mime' => $declared_mime,
            'object_key' => $object_key,
            'policy_fingerprint' => $manifest['binding']['policy_fingerprint'],
            'created_at' => $now,
            'expires_at' => $expires_at,
            'reserved_bytes' => $declared_bytes,
        );
        $manifest['intents'][ $upload_id ] = $intent;
        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        return $written
            ? self::success( array( 'committed' => false, 'intent' => self::intent_summary( $intent ) ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
    }

    public static function worker_authorize_intent( $batch_id, $batch_secret, $upload_id, $ordinal, $display_name, $declared_bytes, $declared_mime, $uploads_dir, $options = array() ) {
        $ordinal = is_numeric( $ordinal ) ? (int) $ordinal : -1;
        $declared_bytes = is_numeric( $declared_bytes ) ? (int) $declared_bytes : 0;
        $display_name = UploadValue::sanitize_display_name( $display_name );
        $declared_mime = is_string( $declared_mime ) ? strtolower( trim( $declared_mime ) ) : '';
        $now = self::now( isset( $options['now'] ) ? $options['now'] : null );
        $storage_identity = self::string_value( $options, 'storage_identity' );
        $validation_contract_version = self::string_value( $options, 'validation_contract_version' );
        $upload_until = self::int_value( $options, 'upload_until' );
        $accept_until = self::int_value( $options, 'accept_until' );
        $validation_until = self::int_value( $options, 'validation_until' );
        $staged_delete_after = self::int_value( $options, 'staged_delete_after' );
        $committed_only = ! empty( $options['committed_only'] );

        if ( ( $committed_only
                ? ! self::worker_committed_retry_deadlines_ordered( $now, $upload_until, $accept_until, $validation_until, $staged_delete_after )
                : ! self::worker_deadlines_ordered( $now, $upload_until, $accept_until, $validation_until, $staged_delete_after ) )
            || ! self::valid_artifact_store_identity( FormProtocol::UPLOAD_TRANSPORT_WORKER, $storage_identity )
            || ! self::valid_validation_contract_version( $validation_contract_version )
        ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'worker_deadlines_invalid' );
        }
        if ( ! self::valid_upload_id( $upload_id )
            || $ordinal < 0
            || $display_name === ''
            || $declared_bytes < 1
        ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'item_identity_invalid' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            $lifecycle->release();
            return $capacity;
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, false, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return $locked;
        }

        $manifest = self::read_worker_authorization_manifest( $locked['manifest_path'], $batch_id, false );
        $check = self::check_worker_item_mutation( $manifest, $batch_secret, $now, $accept_until, $staged_delete_after );
        if ( empty( $check['ok'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $check;
        }
        if ( $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
            || ! hash_equals( $manifest['artifact_store_identity'], $storage_identity )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'artifact_store_mismatch' );
        }
        if ( ! self::worker_policy_within_staged_ceiling( $manifest['policy'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'staged_max_files_exceeded' );
        }

        $declared_mime = self::declared_mime( array( 'type' => $declared_mime ), $display_name, $manifest['policy'] );
        $extension = UploadPolicy::extension_from_name( $display_name );
        $mime_policy = UploadPolicy::policy_for_tokens( $manifest['policy']['accept'], 'staged' );
        if ( $declared_bytes > $manifest['policy']['max_file_bytes']
            || $declared_bytes > Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' )
            || ! UploadPolicy::mime_allowed( $declared_mime, $extension, $mime_policy )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'artifact_declaration_invalid' );
        }

        $intent_id = self::derive_intent_id(
            $batch_id,
            $upload_id,
            $ordinal,
            $display_name,
            $declared_bytes,
            $declared_mime,
            $manifest['binding']['policy_fingerprint']
        );
        $object_key = ManagedArtifactKey::create( $batch_id, $ordinal, $intent_id, $declared_mime );
        if ( $intent_id === '' || $object_key === '' ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'intent_identity_failed' );
        }

        if ( isset( $manifest['items'][ $upload_id ] ) ) {
            $item = $manifest['items'][ $upload_id ];
            $matches = $item['intent_id'] === $intent_id
                && $item['ordinal'] === $ordinal
                && $item['display_name'] === $display_name
                && $item['bytes'] === $declared_bytes
                && $item['object_key'] === $object_key
                && $item['policy_fingerprint'] === $manifest['binding']['policy_fingerprint']
                && $item['storage_identity'] === $storage_identity
                && $item['validation_contract_version'] === $validation_contract_version
                && $item['validation_until'] === $validation_until
                && $item['staged_delete_after'] === $staged_delete_after;
            if ( $matches ) {
                $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
                $settled = self::settle_worker_committed_capacity(
                    $record,
                    $capacity['path'],
                    $capacity['private_dir'],
                    $upload_id,
                    $manifest,
                    $item,
                    $now,
                    'staged'
                );
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return $settled
                    ? self::success( array( 'committed' => true, 'item' => self::worker_item_summary( $item ) ) )
                    : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_settlement_failed' );
            }
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
        }
        if ( isset( $manifest['tombstones'][ $upload_id ] ) ) {
            if ( ! self::worker_cancellation_tombstone_valid( $manifest['tombstones'][ $upload_id ] ) ) {
                $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
                if ( self::worker_capacity_invalid_for_manifest( $capacity['private_dir'], $record, $manifest, 'staged' ) ) {
                    self::release_lock( $locked['lock'] );
                    self::release_lock( $capacity['lock'] );
                    return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
                }
            }
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'item_deleted' );
        }
        if ( $committed_only ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'worker_grants_unavailable' );
        }
        $retirement = self::validation_retirement_marker_status( $lifecycle->private_dir(), $validation_contract_version );
        if ( empty( $retirement['ok'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'validation_retirement_marker_invalid' );
        }
        if ( ! empty( $retirement['retiring'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'validation_contract_retiring' );
        }
        if ( isset( $manifest['intents'][ $upload_id ] ) ) {
            $intent = $manifest['intents'][ $upload_id ];
            $matches = $intent['intent_id'] === $intent_id
                && $intent['ordinal'] === $ordinal
                && $intent['display_name'] === $display_name
                && $intent['declared_bytes'] === $declared_bytes
                && $intent['declared_mime'] === $declared_mime
                && $intent['object_key'] === $object_key
                && $intent['storage_identity'] === $storage_identity
                && $intent['validation_contract_version'] === $validation_contract_version
                && $intent['accept_until'] === $accept_until
                && $intent['validation_until'] === $validation_until
                && $intent['staged_delete_after'] === $staged_delete_after;
            if ( ! $matches ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
            }
            $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
            if ( ! is_array( $record ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
            $authority = self::worker_intent_capacity_authority( $manifest, $upload_id, $intent );
            $reservation_id = self::reservation_id( $batch_id, $upload_id );
            if ( $authority === null
                || ! isset( $record['reservations'][ $reservation_id ] )
                || ! ManagedCapacityStore::matches_intent_reservation(
                    $record,
                    $reservation_id,
                    $intent['intent_id'],
                    $intent['object_key'],
                    $batch_id,
                    $upload_id,
                    $intent['reserved_bytes'],
                    $intent['created_at'],
                    $manifest['artifact_store'],
                    $manifest['artifact_store_identity']
                )
                || ! ManagedCapacityStore::remote_reservation_matches( $record['reservations'][ $reservation_id ], $authority )
            ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reservation_missing' );
            }
            if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $record, $manifest, 'staged' ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::success( array( 'committed' => false, 'intent' => self::worker_intent_summary( $intent ) ) );
        }

        if ( count( $manifest['intents'] ) + count( $manifest['items'] ) + count( $manifest['tombstones'] ) >= self::tombstone_limit( $manifest['policy'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'upload_lifetime_exceeded' );
        }
        foreach ( array_merge( $manifest['intents'], $manifest['items'] ) as $existing ) {
            if ( $existing['ordinal'] === $ordinal ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'ordinal_conflict' );
            }
        }
        if ( count( $manifest['intents'] ) + count( $manifest['items'] ) >= $manifest['policy']['max_files'] ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'max_files_exceeded' );
        }
        if ( self::active_artifact_bytes( $manifest ) > $manifest['policy']['max_total_bytes'] - $declared_bytes ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'max_total_bytes_exceeded' );
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        if ( self::worker_capacity_invalid_for_manifest( $capacity['private_dir'], $record, $manifest, 'staged' ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }

        $capacity_before_reservation = is_array( $record ) ? $record : null;
        $reservation_id = self::reservation_id( $batch_id, $upload_id );
        $reservation = is_array( $record )
            ? self::reserve_capacity(
                $record,
                $reservation_id,
                $intent_id,
                $object_key,
                $batch_id,
                $upload_id,
                $declared_bytes,
                0,
                $manifest['artifact_store_identity'],
                $capacity['private_dir'],
                array(
                    'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
                    'worker_cleanup' => array(
                        'validation_contract_version' => $validation_contract_version,
                        'policy_fingerprint' => $manifest['binding']['policy_fingerprint'],
                        'validation_until' => $validation_until,
                    ),
                ),
                $now
            )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        if ( empty( $reservation['ok'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $reservation;
        }
        if ( ! ManagedCapacityStore::write( $capacity['path'], $reservation['record'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_write_failed' );
        }

        $intent = array(
            'intent_id' => $intent_id,
            'upload_id' => $upload_id,
            'ordinal' => $ordinal,
            'display_name' => $display_name,
            'declared_bytes' => $declared_bytes,
            'declared_mime' => $declared_mime,
            'object_key' => $object_key,
            'policy_fingerprint' => $manifest['binding']['policy_fingerprint'],
            'storage_identity' => $storage_identity,
            'validation_contract_version' => $validation_contract_version,
            'created_at' => $now,
            'upload_until' => $upload_until,
            'accept_until' => $accept_until,
            'validation_until' => $validation_until,
            'staged_delete_after' => $staged_delete_after,
            'reserved_bytes' => $declared_bytes,
        );
        $manifest['version'] = self::WORKER_MANIFEST_VERSION;
        $manifest['intents'][ $upload_id ] = $intent;
        $written = empty( $options['worker_fail_before_manifest_write'] )
            && self::write_json_atomic( $locked['manifest_path'], $manifest );
        if ( ! $written ) {
            $rolled_back = is_array( $capacity_before_reservation )
                && ManagedCapacityStore::write( $capacity['path'], $capacity_before_reservation );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $rolled_back
                ? self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' )
                : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_rollback_failed' );
        }
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        return self::success( array( 'committed' => false, 'intent' => self::worker_intent_summary( $intent ) ) );
    }

    public static function complete_intent( $batch_id, $batch_secret, $upload_id, $intent_id, $facts, $uploads_dir, $now = null ) {
        return self::complete_intent_transition( $batch_id, $batch_secret, $upload_id, $intent_id, $facts, $uploads_dir, $now );
    }

    public static function worker_complete_stored_receipt( $batch_id, $batch_secret, $upload_id, $receipt, $uploads_dir, $now = null, $options = array() ) {
        if ( ! self::valid_worker_stored_receipt_claims( $receipt, $batch_id, $upload_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'completion_invalid' );
        }
        if ( $receipt['batch_id'] !== $batch_id || $receipt['upload_id'] !== $upload_id ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'completion_invalid' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            $lifecycle->release();
            return $capacity;
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, false, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return $locked;
        }

        $manifest = self::read_worker_manifest( $locked['manifest_path'], 'staged', $batch_id, true, false );
        $now = self::now( $now );
        if ( $manifest === null
            || $manifest['state'] !== 'open'
            || $now >= $manifest['accept_until']
            || $now >= $manifest['delete_after']
            || ! self::secret_matches( $manifest, $batch_secret )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'completion_denied' );
        }
        if ( isset( $manifest['tombstones'][ $upload_id ] ) ) {
            $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
            if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $record, $manifest, 'staged' ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'item_deleted' );
        }
        if ( isset( $manifest['items'][ $upload_id ] ) ) {
            $existing = $manifest['items'][ $upload_id ];
            if ( ! self::worker_receipt_matches_item( $receipt, $manifest, $existing, $now ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_TOKEN', 'completion_conflict' );
            }
            if ( self::worker_capacity_missing_for_remote_state( $capacity['path'], $manifest ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
            $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
            $settled = self::settle_worker_committed_capacity(
                $record,
                $capacity['path'],
                $capacity['private_dir'],
                $upload_id,
                $manifest,
                $existing,
                $now,
                'staged'
            );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $settled
                ? self::success( array( 'item' => self::worker_item_summary( $existing ) ) )
                : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_settlement_failed' );
        }

        $intent = isset( $manifest['intents'][ $upload_id ] ) ? $manifest['intents'][ $upload_id ] : null;
        if ( ! is_array( $intent )
            || ! self::worker_receipt_matches_intent( $receipt, $manifest, $intent, $now )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'completion_conflict' );
        }

        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        if ( ! is_array( $record ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $stored = array(
            'upload_id' => $upload_id,
            'intent_id' => $intent['intent_id'],
            'ordinal' => $intent['ordinal'],
            'display_name' => $intent['display_name'],
            'bytes' => $receipt['bytes'],
            'object_key' => $receipt['object_key'],
            'object_version' => $receipt['object_version'],
            'etag' => $receipt['etag'],
            'policy_fingerprint' => $receipt['policy_fingerprint'],
            'storage_identity' => $receipt['storage_identity'],
            'validation_contract_version' => $receipt['validation_contract_version'],
            'validation_until' => $intent['validation_until'],
            'staged_delete_after' => $intent['staged_delete_after'],
        );
        $authority = self::worker_committed_item_capacity_authority( $manifest, $upload_id, $stored );
        $reservation_id = self::reservation_id( $batch_id, $upload_id );
        if ( $authority === null
            || ! ManagedCapacityStore::matches_intent_reservation(
                $record,
                $reservation_id,
                $intent['intent_id'],
                $intent['object_key'],
                $batch_id,
                $upload_id,
                $intent['reserved_bytes'],
                $intent['created_at'],
                $manifest['artifact_store'],
                $manifest['artifact_store_identity']
            )
            || ! isset( $record['reservations'][ $reservation_id ] )
            || ! ManagedCapacityStore::remote_reservation_matches( $record['reservations'][ $reservation_id ], $authority )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reservation_missing' );
        }
        if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $record, $manifest, 'staged' ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        if ( ! empty( $options['worker_fail_before_completion_manifest_write'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
        }
        unset( $manifest['intents'][ $upload_id ] );
        $manifest['items'][ $upload_id ] = $stored;
        $manifest['artifact_bytes'] += $stored['bytes'];
        if ( ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
        }
        if ( ! empty( $options['worker_fail_after_completion_manifest_write_before_capacity'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_settlement_failed' );
        }

        $settled = self::settle_worker_committed_capacity(
            $record,
            $capacity['path'],
            $capacity['private_dir'],
            $upload_id,
            $manifest,
            $stored,
            $now,
            'staged'
        );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        return $settled
            ? self::success( array( 'item' => self::worker_item_summary( $stored ) ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_settlement_failed' );
    }

    private static function complete_intent_transition( $batch_id, $batch_secret, $upload_id, $intent_id, $facts, $uploads_dir, $now ) {
        if ( ! self::valid_upload_id( $upload_id ) || ! is_string( $intent_id ) || $intent_id === '' || ! is_array( $facts ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'completion_invalid' );
        }
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $now = self::now( $now );
        if ( $manifest === null
            || $manifest['state'] !== 'open'
            || $now >= $manifest['delete_after']
            || ! self::secret_matches( $manifest, $batch_secret )
            || $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_LOCAL
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'completion_denied' );
        }
        if ( isset( $manifest['tombstones'][ $upload_id ] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'item_deleted' );
        }
        if ( isset( $manifest['items'][ $upload_id ] ) ) {
            $existing = $manifest['items'][ $upload_id ];
            $matches = self::facts_match_item( $facts, $existing )
                && ManagedArtifactKey::matches( $existing['object_key'], $batch_id, $existing['ordinal'], $existing['mime'] );
            if ( $matches ) {
                $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
                $settled = self::settle_committed_capacity( $record, $capacity['path'], $batch_id, $upload_id, $existing['object_key'], $existing['bytes'], $now );
            }
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return ! empty( $matches ) && ! empty( $settled )
                ? self::success( array( 'item' => self::item_summary( $existing ) ) )
                : self::failure( 'EFORMS_ERR_TOKEN', 'completion_conflict' );
        }

        $intent = isset( $manifest['intents'][ $upload_id ] ) ? $manifest['intents'][ $upload_id ] : null;
        if ( ! is_array( $intent )
            || ! hash_equals( $intent['intent_id'], $intent_id )
            || $now >= $intent['expires_at']
            || ! self::valid_artifact_facts( $facts, $intent, $manifest['policy'] )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'completion_conflict' );
        }

        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        if ( ! is_array( $record ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $reservation_id = self::reservation_id( $batch_id, $upload_id );
        if ( ! ManagedCapacityStore::matches_intent_reservation(
            $record,
            $reservation_id,
            $intent['intent_id'],
            $intent['object_key'],
            $batch_id,
            $upload_id,
                $intent['reserved_bytes'],
                $intent['created_at'],
                $manifest['artifact_store'],
                $manifest['artifact_store_identity']
        ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reservation_missing' );
        }
        $stored = array(
            'upload_id' => $upload_id,
            'ordinal' => $intent['ordinal'],
            'display_name' => $intent['display_name'],
            'bytes' => $facts['bytes'],
            'mime' => $facts['mime'],
            'width' => $facts['width'],
            'height' => $facts['height'],
            'object_key' => $facts['object_key'],
            'object_version' => $facts['object_version'],
            'accepted_at' => $now,
        );
        unset( $manifest['intents'][ $upload_id ] );
        $manifest['items'][ $upload_id ] = $stored;
        $manifest['artifact_bytes'] += $stored['bytes'];
        if ( ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
        }
        $settled = self::settle_committed_capacity( $record, $capacity['path'], $batch_id, $upload_id, $stored['object_key'], $stored['bytes'], $now );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        return $settled
            ? self::success( array( 'item' => self::item_summary( $stored ) ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_settlement_failed' );
    }

    public static function put_item( $batch_id, $batch_secret, $upload_id, $ordinal, $item, $uploads_dir, $options = array() ) {
        $source = UploadValue::temporary_path( $item );
        $result = null;
        try {
            $result = self::put_item_consuming_source( $batch_id, $batch_secret, $upload_id, $ordinal, $item, $uploads_dir, $options );
        } finally {
            if ( $source !== '' && ( file_exists( $source ) || is_link( $source ) ) ) {
                @unlink( $source );
            }
        }
        if ( $source !== '' && ( file_exists( $source ) || is_link( $source ) ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'source_cleanup_failed' );
        }
        return $result;
    }

    private static function put_item_consuming_source( $batch_id, $batch_secret, $upload_id, $ordinal, $item, $uploads_dir, $options ) {
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        if ( ! is_array( $manifest ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'artifact_store_mismatch' );
        }
        $mutation = self::check_item_mutation(
            $manifest,
            $batch_secret,
            self::now( isset( $options['now'] ) ? $options['now'] : null )
        );
        if ( empty( $mutation['ok'] ) ) {
            self::release_lock( $locked['lock'] );
            return $mutation;
        }
        $policy = $manifest['policy'];
        $display_name = UploadValue::sanitize_display_name( UploadValue::original_name( $item ) );
        $existing = isset( $manifest['items'][ $upload_id ] ) ? $manifest['items'][ $upload_id ] : null;
        $preauthorized = isset( $manifest['intents'][ $upload_id ] );
        self::release_lock( $locked['lock'] );
        if ( is_array( $existing ) && ( (int) $ordinal !== $existing['ordinal'] || $display_name !== $existing['display_name'] ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
        }
        if ( ! empty( $options['require_preauthorized_intent'] ) && ! $preauthorized && ! is_array( $existing ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'intent_missing' );
        }
        $envelope = UploadPolicy::validate_item_envelope( $item, $policy );
        if ( empty( $envelope['ok'] ) ) {
            return $envelope;
        }
        $declared_mime = self::declared_mime( $item, $display_name, $policy );
        // A browser-preauthorized local request retains its declared transient
        // claim while PHP owns the multipart temp file. Direct internal calls
        // authorize only after that temp allocation is already observable.
        $authorized = self::authorize_intent(
            $batch_id,
            $batch_secret,
            $upload_id,
            $ordinal,
            $display_name,
            $envelope['bytes'],
            $declared_mime,
            ! empty( $options['require_preauthorized_intent'] ) ? $envelope['bytes'] : 0,
            $uploads_dir,
            array_merge(
                $options,
                array(
                    // Only this path knows that PHP already owns the exact
                    // multipart temp allocation represented by the durable
                    // transient claim.
                    'materialized_transient_bytes' => ! empty( $options['require_preauthorized_intent'] ) ? $envelope['bytes'] : 0,
                )
            )
        );
        if ( empty( $authorized['ok'] ) || ! empty( $authorized['committed'] ) ) {
            return $authorized;
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $intent = $authorized['intent'];
        $written = LocalArtifactStore::write( $lifecycle, $intent['object_key'], $envelope['tmp_name'], $envelope['bytes'] );
        if ( empty( $written['ok'] ) ) {
            $lifecycle->release();
            return $written;
        }
        $inspected = UploadPolicy::inspect_staged_artifact( $written['path'], $display_name, $policy );
        if ( empty( $inspected['ok'] ) ) {
            $lifecycle->release();
            $cleaned = self::delete_item( $batch_id, $batch_secret, $upload_id, $uploads_dir, self::now( isset( $options['now'] ) ? $options['now'] : null ) );
            return empty( $cleaned['ok'] ) ? $cleaned : $inspected;
        }
        $facts = array(
            'object_key' => $intent['object_key'],
            'object_version' => $written['object_version'],
            'bytes' => $inspected['bytes'],
            'mime' => $inspected['mime'],
            'width' => $inspected['width'],
            'height' => $inspected['height'],
        );
        $lifecycle->release();
        $completion_now = isset( $options['completion_now'] ) ? $options['completion_now'] : ( isset( $options['now'] ) ? $options['now'] : null );
        return self::complete_intent( $batch_id, $batch_secret, $upload_id, $intent['intent_id'], $facts, $uploads_dir, $completion_now );
    }

    public static function delete_item( $batch_id, $batch_secret, $upload_id, $uploads_dir, $now = null ) {
        return self::delete_item_transition( $batch_id, $batch_secret, $upload_id, $uploads_dir, $now );
    }

    public static function worker_delete_item( $batch_id, $batch_secret, $upload_id, $uploads_dir, $now = null ) {
        if ( ! self::valid_upload_id( $upload_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'item_identity_invalid' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, false, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            return $locked;
        }

        $manifest = self::read_worker_manifest( $locked['manifest_path'], 'staged', $batch_id, true, false );
        $now = self::now( $now );
        if ( $manifest === null
            || $manifest['state'] !== 'open'
            || $now >= $manifest['delete_after']
            || ! self::secret_matches( $manifest, $batch_secret )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_not_open' );
        }

        if ( isset( $manifest['tombstones'][ $upload_id ] ) ) {
            $tombstone = $manifest['tombstones'][ $upload_id ];
            if ( self::worker_cancellation_tombstone_valid( $tombstone ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::success( array( 'deleted' => true ) );
            }
        } else {
            $tombstone = null;
        }
        $stored = isset( $manifest['items'][ $upload_id ] ) ? $manifest['items'][ $upload_id ] : null;
        $intent = isset( $manifest['intents'][ $upload_id ] ) ? $manifest['intents'][ $upload_id ] : null;
        $lifetime_count = count( $manifest['intents'] ) + count( $manifest['items'] ) + count( $manifest['tombstones'] );
        if ( ! is_array( $tombstone ) && ! is_array( $stored ) && ! is_array( $intent ) && $lifetime_count >= self::tombstone_limit( $manifest['policy'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::success();
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        if ( self::worker_capacity_invalid_for_manifest( $capacity['private_dir'], $record, $manifest, 'staged' ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        if ( is_array( $tombstone ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::success( array( 'deleted' => true ) );
        }

        if ( is_array( $stored ) || is_array( $intent ) ) {
            $source = is_array( $stored ) ? $stored : $intent;
            $manifest['tombstones'][ $upload_id ] = self::worker_tombstone_from_source( $source, $now );
            if ( is_array( $stored ) ) {
                unset( $manifest['items'][ $upload_id ] );
                $manifest['artifact_bytes'] -= (int) $stored['bytes'];
            }
            if ( is_array( $intent ) ) {
                unset( $manifest['intents'][ $upload_id ] );
            }
        } else {
            $manifest['tombstones'][ $upload_id ] = self::worker_cancellation_tombstone( $manifest, $now );
        }

        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        return $written
            ? self::success( array( 'deleted' => true ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
    }

    private static function delete_item_transition( $batch_id, $batch_secret, $upload_id, $uploads_dir, $now ) {
        if ( ! self::valid_upload_id( $upload_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'item_identity_invalid' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }

        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $now = self::now( $now );
        $check = self::check_cleanup_mutation( $manifest, $batch_secret, $now );
        if ( empty( $check['ok'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $check;
        }
        $owned_remote = $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER;
        $defer_physical_delete = $owned_remote;

        $stored = isset( $manifest['items'][ $upload_id ] ) ? $manifest['items'][ $upload_id ] : null;
        $intent = isset( $manifest['intents'][ $upload_id ] ) ? $manifest['intents'][ $upload_id ] : null;
        $tombstone = isset( $manifest['tombstones'][ $upload_id ] ) ? $manifest['tombstones'][ $upload_id ] : null;
        if ( is_array( $tombstone ) && ! empty( $tombstone['capacity_released'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::success( array( 'deleted' => true ) );
        }
        $lifetime_count = count( $manifest['intents'] ) + count( $manifest['items'] ) + count( $manifest['tombstones'] );
        if ( ! is_array( $tombstone )
            && ! is_array( $stored )
            && ! is_array( $intent )
            && $lifetime_count >= self::tombstone_limit( $manifest['policy'] )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::success();
        }

        if ( ! is_array( $tombstone ) ) {
            $source = is_array( $stored ) ? $stored : $intent;
            $tombstone = array(
                'deleted_at' => $now,
                'bytes' => is_array( $stored ) ? (int) $stored['bytes'] : ( is_array( $intent ) ? (int) $intent['reserved_bytes'] : 0 ),
                'object_key' => is_array( $source ) ? $source['object_key'] : '',
                'object_version' => is_array( $stored ) ? $stored['object_version'] : '',
                'capacity_release_started' => false,
                'capacity_released' => false,
            );
            $manifest['tombstones'][ $upload_id ] = $tombstone;
        }
        if ( is_array( $stored ) ) {
            unset( $manifest['items'][ $upload_id ] );
            $manifest['artifact_bytes'] -= (int) $stored['bytes'];
        }
        if ( is_array( $intent ) ) {
            unset( $manifest['intents'][ $upload_id ] );
        }

        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        $release = is_array( $record )
            ? ManagedCapacityStore::prepare_item_release(
                $record,
                $manifest['batch_id'],
                $upload_id,
                $tombstone['bytes'],
                $tombstone['object_key'],
                $tombstone['deleted_at'],
                is_array( $stored ) && empty( $tombstone['capacity_release_started'] ),
                $now,
                $manifest['artifact_store'],
                $manifest['artifact_store_identity']
            )
            : array( 'ok' => false, 'reason' => 'capacity_invalid' );
        if ( empty( $release['ok'] )
            || ( ! empty( $release['changed'] ) && ! ManagedCapacityStore::write( $capacity['path'], $release['record'] ) )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', isset( $release['reason'] ) ? $release['reason'] : 'capacity_write_failed' );
        }
        $manifest['tombstones'][ $upload_id ]['capacity_release_started'] = true;
        if ( ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
        }
        $tombstone = $manifest['tombstones'][ $upload_id ];
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );

        if ( $defer_physical_delete ) {
            $lifecycle->release();
            return self::success( array( 'deleted' => true, 'physical_delete_pending' => true ) );
        }

        if ( $tombstone['object_key'] !== '' && ! self::delete_local_artifact( $lifecycle, $tombstone['object_key'], $tombstone['object_version'] ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_delete_failed' );
        }

        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $current = is_array( $manifest ) && isset( $manifest['tombstones'][ $upload_id ] ) ? $manifest['tombstones'][ $upload_id ] : null;
        if ( ! is_array( $current )
            || $current['object_key'] !== $tombstone['object_key']
            || $current['object_version'] !== $tombstone['object_version']
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'tombstone_changed' );
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        $settled = is_array( $record )
            ? ManagedCapacityStore::finish_item_release( $record, $manifest['batch_id'], $upload_id, $now )
            : array( 'ok' => false, 'reason' => 'capacity_invalid' );
        if ( empty( $settled['ok'] )
            || ( ! empty( $settled['changed'] ) && ! ManagedCapacityStore::write( $capacity['path'], $settled['record'] ) )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', isset( $settled['reason'] ) ? $settled['reason'] : 'capacity_write_failed' );
        }
        $manifest['tombstones'][ $upload_id ]['capacity_released'] = true;
        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        return $written
            ? self::success( array( 'deleted' => true ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
    }

    public static function claim_finalization( $batch_id, $batch_secret, $binding, $field, $expected_items, $submission_id, $uploads_dir, $now = null ) {
        if ( ! self::valid_submission_id( $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_id_invalid' );
        }
        $binding = is_array( $binding ) ? $binding : array();
        $raw_token = self::string_value( $binding, 'raw_token' );
        $expected_id = self::derive_batch_id(
            $raw_token,
            self::string_value( $binding, 'form_id' ),
            self::string_value( $binding, 'instance_id' ),
            self::string_value( $binding, 'field_key' ),
            self::policy_fingerprint( $field )
        );
        if ( $expected_id === '' || ! is_string( $batch_id ) || ! hash_equals( $expected_id, $batch_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'binding_mismatch' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }

        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        $capacity_record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        self::release_lock( $capacity['lock'] );
        if ( $capacity_record === null ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }

        $locked = self::lock_staged_batch( $batch_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $now = self::now( $now );
        if ( $manifest === null ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( $now >= $manifest['accept_until']
            || ! self::secret_matches( $manifest, $batch_secret )
            || ! self::binding_matches(
                $manifest,
                self::string_value( $binding, 'form_id' ),
                self::string_value( $binding, 'instance_id' ),
                hash( 'sha256', $raw_token ),
                self::string_value( $binding, 'field_key' ),
                self::policy_fingerprint( $field )
            )
        ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'claim_denied' );
        }
        if ( ! is_array( $expected_items ) || self::resolved_items( $manifest ) !== $expected_items ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_items_changed' );
        }
        if ( ! empty( $manifest['intents'] ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_uploads_pending' );
        }

        if ( $manifest['state'] === 'finalizing' ) {
            $matches = isset( $manifest['claim']['submission_id'] ) && hash_equals( $manifest['claim']['submission_id'], $submission_id );
            $summary = self::batch_summary( $manifest );
            self::release_lock( $locked['lock'] );
            return $matches
                ? self::success( array( 'batch' => $summary, 'recovered' => true ) )
                : self::failure( 'EFORMS_ERR_TOKEN', 'claim_conflict' );
        }
        if ( $manifest['state'] !== 'open' ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'claim_denied' );
        }

        $manifest['state'] = 'finalizing';
        $manifest['claim'] = array( 'submission_id' => $submission_id, 'claimed_at' => $now );
        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        $summary = self::batch_summary( $manifest );
        self::release_lock( $locked['lock'] );
        return $written
            ? self::success( array( 'batch' => $summary, 'recovered' => false ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
    }

    public static function worker_claim_finalization( $batch_id, $batch_secret, $binding, $field, $expected_items, $submission_id, $uploads_dir, $now = null ) {
        if ( ! self::valid_submission_id( $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_id_invalid' );
        }
        $binding = is_array( $binding ) ? $binding : array();
        $raw_token = self::string_value( $binding, 'raw_token' );
        $expected_id = self::derive_batch_id(
            $raw_token,
            self::string_value( $binding, 'form_id' ),
            self::string_value( $binding, 'instance_id' ),
            self::string_value( $binding, 'field_key' ),
            self::policy_fingerprint( $field )
        );
        if ( $expected_id === '' || ! is_string( $batch_id ) || ! hash_equals( $expected_id, $batch_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'binding_mismatch' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        $capacity_record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        if ( $capacity_record === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, false, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            return $locked;
        }
        $manifest = self::read_worker_manifest( $locked['manifest_path'], 'staged', $batch_id, true, false );
        $now = self::now( $now );
        if ( $manifest === null ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( $now >= $manifest['accept_until']
            || ! self::secret_matches( $manifest, $batch_secret )
            || ! self::binding_matches(
                $manifest,
                self::string_value( $binding, 'form_id' ),
                self::string_value( $binding, 'instance_id' ),
                hash( 'sha256', $raw_token ),
                self::string_value( $binding, 'field_key' ),
                self::policy_fingerprint( $field )
            )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'claim_denied' );
        }
        if ( ! is_array( $expected_items ) || self::worker_resolved_items( $manifest ) !== UploadValue::review_staged_items( $expected_items ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_items_changed' );
        }
        if ( ! empty( $manifest['intents'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_uploads_pending' );
        }
        if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $capacity_record, $manifest, 'staged' ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }

        if ( $manifest['state'] === 'finalizing' ) {
            $matches = isset( $manifest['claim']['submission_id'] ) && hash_equals( $manifest['claim']['submission_id'], $submission_id );
            $summary = self::worker_batch_summary( $manifest );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $matches
                ? self::success( array( 'batch' => $summary, 'recovered' => true ) )
                : self::failure( 'EFORMS_ERR_TOKEN', 'claim_conflict' );
        }
        if ( $manifest['state'] !== 'open' ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'claim_denied' );
        }

        $manifest['state'] = 'finalizing';
        $manifest['claim'] = array( 'submission_id' => $submission_id, 'claimed_at' => $now );
        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        $summary = self::worker_batch_summary( $manifest );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        return $written
            ? self::success( array( 'batch' => $summary, 'recovered' => false ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
    }

    public static function resolve_recovery( $batch_id, $batch_secret, $binding, $field, $submission_id, $uploads_dir, $now = null ) {
        if ( ! self::valid_submission_id( $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'recovery_denied' );
        }
        $binding = is_array( $binding ) ? $binding : array();
        $raw_token = self::string_value( $binding, 'raw_token' );
        $form_id = self::string_value( $binding, 'form_id' );
        $instance_id = self::string_value( $binding, 'instance_id' );
        $field_key = self::string_value( $binding, 'field_key' );
        $policy_fingerprint = self::policy_fingerprint( $field );
        $expected_id = self::derive_batch_id( $raw_token, $form_id, $instance_id, $field_key, $policy_fingerprint );
        if ( $expected_id === '' || ! is_string( $batch_id ) || ! hash_equals( $expected_id, $batch_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'recovery_denied' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }

        $locked = self::lock_staged_batch( $batch_id, $uploads_dir );
        $phase = 'finalizing';
        $aggregate_family = 'staged';
        if ( empty( $locked['ok'] ) ) {
            if ( empty( $locked['gone'] ) ) {
                return $locked;
            }
            $locked = self::lock_submission( $submission_id, $uploads_dir );
            $aggregate_family = 'submission';
        }
        if ( empty( $locked['ok'] ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'recovery_denied' );
        }

        $manifest = self::read_worker_manifest(
            $locked['manifest_path'],
            $aggregate_family,
            $aggregate_family === 'staged' ? $batch_id : $submission_id
        );
        $worker_manifest = $manifest !== null;
        if ( $manifest === null ) {
            $manifest = self::read_manifest(
                $locked['manifest_path'],
                $aggregate_family,
                $aggregate_family === 'staged' ? $batch_id : $submission_id
            );
        }
        if ( $manifest !== null && $manifest['state'] === 'finalized' ) {
            $phase = 'finalized';
        }
        $valid_state = $manifest !== null && $manifest['state'] === $phase;
        $now = self::now( $now );
        $valid = $valid_state
            && $manifest['batch_id'] === $batch_id
            && isset( $manifest['claim']['submission_id'] )
            && hash_equals( $manifest['claim']['submission_id'], $submission_id )
            && ! isset( $manifest['email_attempted_at'] )
            && ( $manifest['delete_after'] === null ? $manifest['state'] === 'finalized' : $now < (int) $manifest['delete_after'] )
            && self::secret_matches( $manifest, $batch_secret )
            && self::binding_matches( $manifest, $form_id, $instance_id, hash( 'sha256', $raw_token ), $field_key, $policy_fingerprint );
        if ( ! $valid ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'recovery_denied' );
        }

        $items = $worker_manifest ? self::worker_resolved_items( $manifest ) : self::resolved_items( $manifest );
        self::release_lock( $locked['lock'] );
        return self::success(
            array(
                'phase' => $phase,
                'items' => $items,
                'accept_expired' => $now >= (int) $manifest['accept_until'],
                'batch' => self::manifest_summary( $manifest ),
            )
        );
    }

    public static function reopen_claim( $batch_id, $submission_id, $uploads_dir, $now = null ) {
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            $lifecycle->release();
            return $capacity;
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, false, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return $locked;
        }
        $manifest = self::read_worker_manifest( $locked['manifest_path'], 'staged', $batch_id, true, false );
        $worker_manifest = $manifest !== null;
        if ( $manifest === null ) {
            $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id, false );
        }
        $now = self::now( $now );
        if ( $manifest === null
            || $manifest['state'] !== 'finalizing'
            || ! isset( $manifest['claim']['submission_id'] )
            || ! hash_equals( $manifest['claim']['submission_id'], (string) $submission_id )
            || isset( $manifest['email_attempted_at'] )
            || $now >= (int) $manifest['delete_after']
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_TOKEN', 'claim_reopen_denied' );
        }
        if ( $worker_manifest ) {
            $capacity_record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
            if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $capacity_record, $manifest, 'staged' ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                $lifecycle->release();
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
        }
        $manifest['state'] = 'open';
        unset( $manifest['claim'] );
        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        $lifecycle->release();
        return $written ? self::success() : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
    }

    public static function finalize( $batch_id, $submission_id, $uploads_dir, $now = null, $review_snapshot = null ) {
        if ( ! is_string( $batch_id ) || preg_match( FormProtocol::upload_batch_id_pattern(), $batch_id ) !== 1 || ! self::valid_submission_id( $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_identity_invalid' );
        }
        if ( $review_snapshot !== null && ! self::valid_review_snapshot_for_submission( $review_snapshot, $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'review_snapshot_invalid' );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }

        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        if ( self::read_capacity( $capacity['path'], $capacity['private_dir'] ) === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $result = self::finalize_with_capacity_lock( $batch_id, $submission_id, $uploads_dir, $lifecycle, $now, $review_snapshot );
        self::release_lock( $capacity['lock'] );
        return $result;
    }

    public static function worker_finalize( $batch_id, $submission_id, $uploads_dir, $now = null ) {
        if ( ! is_string( $batch_id ) || preg_match( FormProtocol::upload_batch_id_pattern(), $batch_id ) !== 1 || ! self::valid_submission_id( $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_identity_invalid' );
        }
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        $capacity_record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        if ( $capacity_record === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $result = self::worker_finalize_with_capacity_lock( $batch_id, $submission_id, $uploads_dir, $lifecycle, $now, $capacity_record );
        self::release_lock( $capacity['lock'] );
        return $result;
    }

    public static function worker_submission( $submission_id, $uploads_dir, $now = null ) {
        $locked = self::lock_submission( $submission_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_worker_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( ! self::finalized_available( $manifest, self::now( $now ) ) ) {
            self::release_submission_lock( $locked );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $summary = self::worker_submission_summary( $manifest );
        self::release_submission_lock( $locked );
        return self::success( array( 'submission' => $summary ) );
    }

    private static function finalize_with_capacity_lock( $batch_id, $submission_id, $uploads_dir, $lifecycle, $now, $review_snapshot ) {
        $now = self::now( $now );
        $submission_root = self::managed_root( $uploads_dir, self::SUBMISSIONS_DIR, true, $lifecycle );
        if ( $submission_root === '' ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_root_unavailable' );
        }
        $destination_shard = $submission_root . '/' . Helpers::h2( $submission_id );
        if ( ! self::ensure_dir( $destination_shard, PrivateDir::REVIEW_DIRECTORY_MODE ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_shard_unavailable' );
        }
        $destination = $destination_shard . '/' . $submission_id;
        $staged = self::lock_staged_batch( $batch_id, $uploads_dir, true, false );

        if ( ! empty( $staged['ok'] ) ) {
            $source = $staged['path'];
            $lock = $staged['lock'];
            $manifest = self::read_manifest( $staged['manifest_path'], 'staged', $batch_id );
            $matches = $manifest !== null
                && $manifest['state'] === 'finalizing'
                && isset( $manifest['claim']['submission_id'] )
                && hash_equals( $manifest['claim']['submission_id'], $submission_id )
                && $now < (int) $manifest['delete_after'];
            if ( ! $matches ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_claim_mismatch' );
            }
            if ( ! self::staged_batch_paths_valid( $staged )
                || file_exists( $destination )
                || ! @rename( $source, $destination )
            ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'aggregate_rename_failed' );
            }
            if ( ! @chmod( $destination, PrivateDir::REVIEW_DIRECTORY_MODE ) ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_permissions_failed' );
            }
            self::release_lock( $lock );
        } elseif ( empty( $staged['gone'] ) ) {
            return $staged;
        } elseif ( ! is_dir( $destination ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_unavailable' );
        }

        if ( ! PrivateDir::ensure_existing_review_directory( $destination ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_permissions_failed' );
        }

        $lock = self::acquire_lock( self::aggregate_lock_path( self::SUBMISSIONS_DIR, $destination ) );
        if ( $lock === false ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_lock_failed' );
        }
        $manifest_path = $destination . '/' . self::MANIFEST_FILENAME;
        $manifest = self::read_manifest( $manifest_path, 'submission', $submission_id );
        if ( $manifest === null
            || ! hash_equals( $manifest['batch_id'], $batch_id )
            || ! isset( $manifest['claim']['submission_id'] )
            || ! hash_equals( $manifest['claim']['submission_id'], $submission_id )
            || $now >= (int) $manifest['delete_after']
        ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_claim_mismatch' );
        }
        self::remove_staged_lock_file_for_batch( $batch_id, $uploads_dir );
        if ( $manifest['state'] !== 'finalized' ) {
            $finalized_at = $now;
            $manifest['state'] = 'finalized';
            $manifest['finalized_at'] = $finalized_at;
            $manifest['delete_after'] = $finalized_at + Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' );
            if ( ! self::write_json_atomic( $manifest_path, $manifest ) ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
            }
        }
        if ( $review_snapshot !== null && isset( $manifest['email_attempted_at'] ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_TOKEN', 'review_snapshot_denied' );
        }
        if ( $review_snapshot !== null && ! self::review_snapshot_matches_manifest( $review_snapshot, $manifest, $submission_id ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'review_snapshot_invalid' );
        }
        if ( $review_snapshot !== null && ! self::write_review_snapshot_locked( $destination, self::review_snapshot_for_manifest( $review_snapshot, $manifest ) ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'review_snapshot_write_failed' );
        }
        $summary = self::submission_summary( $manifest );
        self::release_lock( $lock );
        return self::success( array( 'submission' => $summary ) );
    }

    private static function worker_finalize_with_capacity_lock( $batch_id, $submission_id, $uploads_dir, $lifecycle, $now, $capacity_record ) {
        $now = self::now( $now );
        $private_dir = $lifecycle->private_dir();
        $staged = self::lock_staged_batch( $batch_id, $uploads_dir, true, false );
        $source = '';
        $destination = '';

        if ( ! empty( $staged['ok'] ) ) {
            $source = $staged['path'];
            $lock = $staged['lock'];
            $manifest = self::read_worker_manifest( $staged['manifest_path'], 'staged', $batch_id, true, false );
            $matches = $manifest !== null
                && $manifest['state'] === 'finalizing'
                && isset( $manifest['claim']['submission_id'] )
                && hash_equals( $manifest['claim']['submission_id'], $submission_id )
                && $now < (int) $manifest['delete_after'];
            if ( ! $matches ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_claim_mismatch' );
            }
            if ( ! self::worker_capacity_consistent_for_manifest( $private_dir, $capacity_record, $manifest, 'staged' ) ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
            $submission_root = self::managed_root( $uploads_dir, self::SUBMISSIONS_DIR, true, $lifecycle );
            if ( $submission_root === '' ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_root_unavailable' );
            }
            $destination_shard = $submission_root . '/' . Helpers::h2( $submission_id );
            if ( ! self::ensure_dir( $destination_shard, PrivateDir::REVIEW_DIRECTORY_MODE ) ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_shard_unavailable' );
            }
            $destination = $destination_shard . '/' . $submission_id;
            if ( ! self::staged_batch_paths_valid( $staged )
                || file_exists( $destination )
                || ! @rename( $source, $destination )
            ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'aggregate_rename_failed' );
            }
            if ( ! @chmod( $destination, PrivateDir::REVIEW_DIRECTORY_MODE ) ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_permissions_failed' );
            }
            self::release_lock( $lock );
        } elseif ( empty( $staged['gone'] ) ) {
            return $staged;
        } else {
            $submission_root = rtrim( $private_dir, '/\\' ) . '/' . self::SUBMISSIONS_DIR;
            if ( is_link( $submission_root ) || ! is_dir( $submission_root ) ) {
                return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_unavailable' );
            }
            $destination_shard = $submission_root . '/' . Helpers::h2( $submission_id );
            $destination = $destination_shard . '/' . $submission_id;
            if ( is_link( $destination_shard ) || is_link( $destination ) || ! is_dir( $destination ) ) {
                return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_unavailable' );
            }
            $manifest = self::read_worker_manifest( $destination . '/' . self::MANIFEST_FILENAME, 'submission', $submission_id, true, false );
            if ( $manifest === null
                || ! hash_equals( $manifest['batch_id'], $batch_id )
                || ! isset( $manifest['claim']['submission_id'] )
                || ! hash_equals( $manifest['claim']['submission_id'], $submission_id )
                || $now >= (int) $manifest['delete_after']
            ) {
                return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_claim_mismatch' );
            }
            if ( ! self::worker_capacity_consistent_for_manifest( $private_dir, $capacity_record, $manifest, 'submission' ) ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
        }

        if ( ! PrivateDir::ensure_existing_review_directory( $destination ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_permissions_failed' );
        }

        $lock = self::acquire_lock( self::aggregate_lock_path( self::SUBMISSIONS_DIR, $destination ) );
        if ( $lock === false ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_lock_failed' );
        }
        $manifest_path = $destination . '/' . self::MANIFEST_FILENAME;
        $manifest = self::read_worker_manifest( $manifest_path, 'submission', $submission_id );
        if ( $manifest === null
            || ! hash_equals( $manifest['batch_id'], $batch_id )
            || ! isset( $manifest['claim']['submission_id'] )
            || ! hash_equals( $manifest['claim']['submission_id'], $submission_id )
            || $now >= (int) $manifest['delete_after']
        ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_claim_mismatch' );
        }
        if ( ! self::worker_capacity_consistent_for_manifest( $private_dir, $capacity_record, $manifest, 'submission' ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        self::remove_staged_lock_file_for_batch( $batch_id, $uploads_dir );
        if ( $manifest['state'] !== 'finalized' ) {
            $finalized_at = $now;
            $manifest['state'] = 'finalized';
            $manifest['finalized_at'] = $finalized_at;
            $manifest['delete_after'] = $finalized_at + Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' );
            if ( ! self::write_json_atomic( $manifest_path, $manifest ) ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
            }
        }
        $summary = self::worker_submission_summary( $manifest );
        self::release_lock( $lock );
        return self::success( array( 'submission' => $summary ) );
    }

    public static function store_review_snapshot( $submission_id, $uploads_dir, $review_snapshot ) {
        if ( ! self::valid_review_snapshot_for_submission( $review_snapshot, $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'review_snapshot_invalid' );
        }
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            $lifecycle->release();
            return $capacity;
        }
        $locked = self::lock_submission( $submission_id, $uploads_dir, false, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return $locked;
        }
        $is_worker = self::worker_manifest_file_version( $locked['manifest_path'] ) === self::WORKER_MANIFEST_VERSION;
        $manifest = $is_worker
            ? self::read_worker_manifest( $locked['manifest_path'], 'submission', $submission_id, true, false )
            : self::read_manifest( $locked['manifest_path'], 'submission', $submission_id, false );
        if ( $manifest === null || $manifest['state'] !== 'finalized' || isset( $manifest['email_attempted_at'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_TOKEN', 'review_snapshot_denied' );
        }
        if ( $is_worker ) {
            $capacity_record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
            if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $capacity_record, $manifest, 'submission' ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                $lifecycle->release();
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
        }
        if ( ! self::review_snapshot_matches_manifest( $review_snapshot, $manifest, $submission_id ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'review_snapshot_invalid' );
        }
        $written = self::write_review_snapshot_locked( $locked['path'], self::review_snapshot_for_manifest( $review_snapshot, $manifest ) );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        $lifecycle->release();
        return $written ? self::success() : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'review_snapshot_write_failed' );
    }

    public static function submission( $submission_id, $uploads_dir, $now = null ) {
        $locked = self::lock_submission( $submission_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( ! self::finalized_available( $manifest, self::now( $now ) ) ) {
            self::release_submission_lock( $locked );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $summary = self::submission_summary( $manifest );
        self::release_submission_lock( $locked );
        return self::success( array( 'submission' => $summary ) );
    }

    public static function review_snapshot( $submission_id, $uploads_dir ) {
        $locked = self::lock_submission( $submission_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $is_worker = self::worker_manifest_file_version( $locked['manifest_path'] ) === self::WORKER_MANIFEST_VERSION;
        $manifest = $is_worker
            ? self::read_worker_manifest( $locked['manifest_path'], 'submission', $submission_id )
            : self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( $manifest === null || $manifest['state'] !== 'finalized' ) {
            self::release_submission_lock( $locked );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $snapshot = self::read_review_snapshot_file( $locked['path'] . '/' . self::REVIEW_SNAPSHOT_FILENAME );
        $validated = SubmissionReviewSnapshot::validate( $snapshot );
        if ( empty( $validated['ok'] ) || ! self::review_snapshot_matches_manifest( $validated['snapshot'], $manifest, $submission_id ) ) {
            self::release_submission_lock( $locked );
            return self::failure( 'EFORMS_ERR_TOKEN', 'review_snapshot_unavailable' );
        }
        self::release_submission_lock( $locked );
        return self::success( array( 'snapshot' => $validated['snapshot'] ) );
    }

    public static function retained_photo_submissions( $uploads_dir, $now = null, $limit = null, $cursor = array() ) {
        $now = self::now( $now );
        $limit = $limit === null ? Anchors::get( 'RETAINED_SUBMISSIONS_PAGE_SIZE' ) : $limit;
        $limit = max( 0, (int) $limit );
        $empty = array(
            'submissions' => array(),
            'scanned' => 0,
            'cursor' => array(),
            'reached_limit' => false,
        );
        if ( $limit < 1 ) {
            return self::success( $empty );
        }

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'private_dir_unavailable' );
        }

        $private_dir = PrivateDir::path( $uploads_dir );
        $root = rtrim( $private_dir, '/\\' ) . '/' . self::SUBMISSIONS_DIR;
        if ( is_link( $root ) || ( file_exists( $root ) && ! is_dir( $root ) ) ) {
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'aggregate_enumeration_failed' );
        }
        if ( ! is_dir( $root ) ) {
            $lifecycle->release();
            return self::success( $empty );
        }
        if ( ! PrivateDir::ensure_existing_review_directory( $root ) ) {
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'aggregate_enumeration_failed' );
        }

        $submissions = array();
        $scanned = 0;
        $scan_cursor = is_array( $cursor ) ? $cursor : array();
        $scanned_cursor = array();
        $display_cursor = array();
        $scan_limit = max( $limit, Anchors::get( 'RETAINED_SUBMISSIONS_SCAN_PAGE_SIZE' ) );
        $has_more = false;
        while ( count( $submissions ) < $limit + 1 && $scanned < $scan_limit ) {
            $remaining = ( $limit + 1 ) - count( $submissions );
            $remaining_scan = $scan_limit - $scanned;
            $page_limit = min( $remaining, $remaining_scan );
            if ( $page_limit < 1 ) {
                break;
            }
            $page = self::aggregate_paths( $root, $page_limit, $scan_cursor );
            if ( empty( $page['ok'] ) ) {
                $lifecycle->release();
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'aggregate_enumeration_failed', array( 'cursor' => $scan_cursor ) );
            }

            foreach ( $page['paths'] as $path ) {
                ++$scanned;
                $scanned_cursor = array( 'shard' => basename( dirname( $path ) ), 'aggregate' => basename( $path ) );
                $row = self::retained_photo_submission_row( $path, $now );
                if ( is_array( $row ) ) {
                    $submissions[] = $row;
                    if ( count( $submissions ) > $limit ) {
                        $has_more = true;
                        break 2;
                    }
                    $display_cursor = array( 'shard' => basename( dirname( $path ) ), 'aggregate' => basename( $path ) );
                }
            }

            if ( empty( $page['cursor'] ) || empty( $page['paths'] ) ) {
                break;
            }
            $scan_cursor = $page['cursor'];
        }

        $lifecycle->release();
        $scan_limited = $scanned >= $scan_limit && ! empty( $scanned_cursor );
        return self::success(
            array(
                'submissions' => array_slice( $submissions, 0, $limit ),
                'scanned' => $scanned,
                'cursor' => $has_more ? $display_cursor : ( $scan_limited ? $scanned_cursor : array() ),
                'reached_limit' => $has_more || $scan_limited,
            )
        );
    }

    public static function begin_validation_contract_retirement( $uploads_dir, $validation_contract_version, $now = null ) {
        $validation_contract_version = is_string( $validation_contract_version ) ? $validation_contract_version : '';
        if ( ! self::valid_validation_contract_version( $validation_contract_version ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'validation_contract_version_invalid' );
        }
        $lifecycle = PrivateDir::acquire_exclusive_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $marker_path = self::validation_retirement_marker_path_for_lease( $lifecycle, $validation_contract_version, true );
        if ( $marker_path === '' ) {
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'validation_retirement_marker_unavailable' );
        }
        $existing = self::read_validation_retirement_marker( $marker_path, $validation_contract_version );
        if ( $existing !== null ) {
            $lifecycle->release();
            return self::success(
                array(
                    'validation_contract_version' => $validation_contract_version,
                    'state' => $existing['state'],
                    'marker' => $existing,
                )
            );
        }
        if ( file_exists( $marker_path ) || is_link( $marker_path ) ) {
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'validation_retirement_marker_invalid' );
        }

        $now = self::now( $now );
        $marker = array(
            'version' => self::VALIDATION_RETIREMENT_VERSION,
            'validation_contract_version' => $validation_contract_version,
            'state' => 'active',
            'started_at' => $now,
            'updated_at' => $now,
        );
        $written = self::write_json_atomic( $marker_path, $marker );
        $lifecycle->release();
        return $written
            ? self::success(
                array(
                    'validation_contract_version' => $validation_contract_version,
                    'state' => 'active',
                    'marker' => $marker,
                )
            )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'validation_retirement_marker_write_failed' );
    }

    public static function validation_contract_retirement_status( $uploads_dir, $validation_contract_version, $now = null ) {
        $validation_contract_version = is_string( $validation_contract_version ) ? $validation_contract_version : '';
        $result = array(
            'ok' => false,
            'blocked' => false,
            'reason' => '',
            'validation_contract_version' => $validation_contract_version,
            'scanned' => 0,
            'references' => 0,
            'accepted_artifacts' => 0,
            'pending' => 0,
            'tombstones' => 0,
            'by_family' => array(
                'staged' => 0,
                'finalized' => 0,
                'capacity' => 0,
            ),
        );
        if ( ! self::valid_validation_contract_version( $validation_contract_version ) ) {
            $result['reason'] = 'validation_contract_version_invalid';
            return $result;
        }
        $lifecycle = PrivateDir::acquire_exclusive_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            $result['reason'] = 'upload_lifecycle_unavailable';
            return $result;
        }
        $marker_path = self::validation_retirement_marker_path_for_lease( $lifecycle, $validation_contract_version, false );
        $marker = $marker_path === '' ? null : self::read_validation_retirement_marker( $marker_path, $validation_contract_version );
        if ( $marker === null ) {
            $lifecycle->release();
            $result['reason'] = ( $marker_path !== '' && ( file_exists( $marker_path ) || is_link( $marker_path ) ) )
                ? 'validation_retirement_marker_invalid'
                : 'retirement_marker_missing';
            return $result;
        }

        $scanned = self::scan_validation_contract_references( $uploads_dir, $validation_contract_version, $result );
        if ( empty( $scanned['ok'] ) ) {
            $lifecycle->release();
            return $result;
        }

        $result['ok'] = true;
        $result['blocked'] = $result['references'] > 0;
        $result['reason'] = $result['blocked'] ? 'retained_validation_contract_references' : 'ready';
        if ( ! $result['blocked'] ) {
            $now = self::now( $now );
            $marker['state'] = 'ready';
            $marker['ready_at'] = $now;
            $marker['updated_at'] = $now;
            $marker['ready'] = self::validation_retirement_marker_ready_summary( $result );
            if ( ! self::write_json_atomic( $marker_path, $marker ) ) {
                $lifecycle->release();
                $result['ok'] = false;
                $result['blocked'] = false;
                $result['reason'] = 'validation_retirement_marker_write_failed';
                return $result;
            }
        }
        $result['marker_state'] = $marker['state'];
        $lifecycle->release();
        return $result;
    }

    public static function complete_validation_contract_retirement( $uploads_dir, $validation_contract_version ) {
        $validation_contract_version = is_string( $validation_contract_version ) ? $validation_contract_version : '';
        if ( ! self::valid_validation_contract_version( $validation_contract_version ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'validation_contract_version_invalid' );
        }
        $lifecycle = PrivateDir::acquire_exclusive_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $marker_path = self::validation_retirement_marker_path_for_lease( $lifecycle, $validation_contract_version, false );
        $marker = $marker_path === '' ? null : self::read_validation_retirement_marker( $marker_path, $validation_contract_version );
        if ( $marker === null ) {
            $lifecycle->release();
            return self::failure(
                'EFORMS_ERR_STORAGE_UNAVAILABLE',
                ( $marker_path !== '' && ( file_exists( $marker_path ) || is_link( $marker_path ) ) )
                    ? 'validation_retirement_marker_invalid'
                    : 'retirement_marker_missing'
            );
        }
        if ( $marker['state'] !== 'ready' ) {
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_TOKEN', 'retirement_not_ready' );
        }
        if ( is_link( $marker_path ) || ! @unlink( $marker_path ) ) {
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'validation_retirement_marker_remove_failed' );
        }
        @rmdir( dirname( $marker_path ) );
        $lifecycle->release();
        return self::success(
            array(
                'validation_contract_version' => $validation_contract_version,
                'state' => 'complete',
            )
        );
    }

    private static function scan_validation_contract_references( $uploads_dir, $validation_contract_version, &$result ) {
        $uploads_dir = is_string( $uploads_dir ) ? rtrim( $uploads_dir, '/\\' ) : '';
        if ( $uploads_dir === '' || ! is_dir( $uploads_dir ) ) {
            $result['reason'] = 'uploads_dir_unavailable';
            return array( 'ok' => false );
        }

        foreach ( array( self::STAGED_DIR => 'staged', self::SUBMISSIONS_DIR => 'submission' ) as $root_name => $manifest_family ) {
            $family_key = $root_name === self::STAGED_DIR ? 'staged' : 'finalized';
            $private_dir = PrivateDir::path( $uploads_dir );
            $root = $private_dir === '' ? '' : rtrim( $private_dir, '/\\' ) . '/' . $root_name;
            if ( $root === '' || is_link( $root ) || ( file_exists( $root ) && ! is_dir( $root ) ) ) {
                if ( $root !== '' && ( file_exists( $root ) || is_link( $root ) ) ) {
                    $result['reason'] = 'aggregate_enumeration_failed';
                    return array( 'ok' => false );
                }
                continue;
            }
            if ( ! is_dir( $root ) ) {
                continue;
            }

            $cursor = array();
            do {
                $page = self::aggregate_paths( $root, Anchors::get( 'GC_DEFAULT_BATCH_LIMIT' ), $cursor, false );
                if ( empty( $page['ok'] ) ) {
                    $result['reason'] = isset( $page['reason'] ) ? $page['reason'] : 'aggregate_enumeration_failed';
                    return array( 'ok' => false );
                }
                foreach ( $page['paths'] as $path ) {
                    $manifest_path = rtrim( $path, '/\\' ) . '/' . self::MANIFEST_FILENAME;
                    $manifest_version = self::worker_manifest_file_version( $manifest_path );
                    if ( $manifest_version === null ) {
                        if ( $root_name === self::STAGED_DIR
                            && ! file_exists( $manifest_path )
                            && self::initializable_partial_batch( $path )
                        ) {
                            continue;
                        }
                        $unsupported_worker = self::unsupported_worker_manifest_file_version( $manifest_path );
                        if ( $unsupported_worker === null ) {
                            $result['reason'] = 'manifest_invalid';
                            return array( 'ok' => false );
                        }
                        if ( $unsupported_worker ) {
                            $result['reason'] = 'unsupported_worker_manifest_version';
                            return array( 'ok' => false );
                        }
                        $local_manifest = self::read_manifest( $manifest_path, $manifest_family, basename( rtrim( $path, '/\\' ) ), false );
                        if ( $local_manifest === null ) {
                            $result['reason'] = 'manifest_invalid';
                            return array( 'ok' => false );
                        }
                        continue;
                    }
                    if ( $manifest_version === false ) {
                        $result['reason'] = 'manifest_invalid';
                        return array( 'ok' => false );
                    }
                    $manifest = self::read_worker_manifest( $manifest_path, $manifest_family, basename( rtrim( $path, '/\\' ) ), true, false );
                    if ( $manifest === null ) {
                        $result['reason'] = 'manifest_invalid';
                        return array( 'ok' => false );
                    }
                    ++$result['scanned'];
                    $counts = self::validation_contract_reference_counts( $manifest, $validation_contract_version );
                    if ( $counts['references'] < 1 ) {
                        continue;
                    }
                    $result['references'] += $counts['references'];
                    $result['accepted_artifacts'] += $counts['accepted_artifacts'];
                    $result['pending'] += $counts['pending'];
                    $result['tombstones'] += $counts['tombstones'];
                    $result['by_family'][ $family_key ] += $counts['references'];
                }
                $cursor = isset( $page['cursor'] ) && is_array( $page['cursor'] ) ? $page['cursor'] : array();
            } while ( ! empty( $cursor ) );
        }

        $private_dir = PrivateDir::path( $uploads_dir );
        if ( $private_dir === '' ) {
            $result['reason'] = 'upload_lifecycle_unavailable';
            return array( 'ok' => false );
        }
        $capacity = ManagedCapacityStore::read(
            rtrim( $private_dir, '/\\' ) . '/' . self::CAPACITY_FILENAME,
            self::CAPACITY_VERSION
        );
        if ( $capacity === null ) {
            $result['reason'] = 'capacity_invalid';
            return array( 'ok' => false );
        }
        foreach ( $capacity['reservations'] as $reservation ) {
            if ( $reservation['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
                continue;
            }
            if ( ! isset( $reservation['validation_contract_version'] ) || ! is_string( $reservation['validation_contract_version'] ) ) {
                $result['reason'] = 'capacity_invalid';
                return array( 'ok' => false );
            }
            if ( hash_equals( $reservation['validation_contract_version'], $validation_contract_version ) ) {
                ++$result['references'];
                ++$result['pending'];
                ++$result['by_family']['capacity'];
            }
        }

        return array( 'ok' => true );
    }

    public static function submission_management_status( $submission_id, $uploads_dir, $now = null ) {
        $now = self::now( $now );
        $locked = self::lock_submission( $submission_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $is_worker = self::worker_manifest_file_version( $locked['manifest_path'] ) === self::WORKER_MANIFEST_VERSION;
        $manifest = $is_worker
            ? self::read_worker_manifest( $locked['manifest_path'], 'submission', $submission_id )
            : self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( $manifest === null || $manifest['state'] !== 'finalized' ) {
            self::release_submission_lock( $locked );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $delete_after = $manifest['delete_after'];
        $status = array(
            'submission_id' => $manifest['claim']['submission_id'],
            'finalized_at' => (int) $manifest['finalized_at'],
            'delete_after' => $delete_after === null ? null : (int) $delete_after,
            'expired' => ! self::finalized_available( $manifest, $now ),
            'artifact_store' => isset( $manifest['artifact_store'] ) ? $manifest['artifact_store'] : '',
            'artifact_store_identity' => isset( $manifest['artifact_store_identity'] ) ? $manifest['artifact_store_identity'] : '',
        );
        self::release_submission_lock( $locked );
        return self::success( array( 'submission' => $status ) );
    }

    public static function submission_file( $submission_id, $upload_id, $uploads_dir, $now = null ) {
        return self::submission_member( $submission_id, $upload_id, $uploads_dir, $now, 'download' );
    }

    public static function submission_preview_source( $submission_id, $upload_id, $uploads_dir, $now = null ) {
        return self::submission_member( $submission_id, $upload_id, $uploads_dir, $now, 'preview' );
    }

    /**
     * Return provider-neutral exact artifact facts and, for local storage, the
     * one action-specific access handle. Review callers never resolve managed
     * object paths themselves.
     */
    private static function submission_member( $submission_id, $upload_id, $uploads_dir, $now, $action ) {
        if ( ! self::valid_upload_id( $upload_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'file_unavailable' );
        }
        $locked = self::lock_submission( $submission_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( ! self::finalized_available( $manifest, self::now( $now ) )
            || ! isset( $manifest['items'][ $upload_id ] )
            || ! in_array( $action, array( 'download', 'preview' ), true )
        ) {
            self::release_submission_lock( $locked );
            return self::failure( 'EFORMS_ERR_TOKEN', 'file_unavailable' );
        }
        $item = $manifest['items'][ $upload_id ];
        $artifact = array(
            'artifact_store' => $manifest['artifact_store'],
            'artifact_store_identity' => $manifest['artifact_store_identity'],
            'object_key' => $item['object_key'],
            'object_version' => $item['object_version'],
            'mime' => $item['mime'],
            'bytes' => (int) $item['bytes'],
            'width' => (int) $item['width'],
            'height' => (int) $item['height'],
            'display_name' => $item['display_name'],
            'delete_after' => $manifest['delete_after'] === null ? null : (int) $manifest['delete_after'],
        );
        if ( $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
            $path = LocalArtifactStore::locate( $uploads_dir, $item['object_key'], $item['object_version'], $locked['lifecycle'] );
            if ( $path === '' || is_link( $path ) || ! is_file( $path ) || @filesize( $path ) !== $artifact['bytes'] ) {
                self::release_submission_lock( $locked );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'file_missing' );
            }
            if ( $action === 'preview' ) {
                $artifact['source_path'] = $path;
            } else {
                $stream = @fopen( $path, 'rb' );
                $stat = is_resource( $stream ) ? fstat( $stream ) : false;
                if ( ! is_resource( $stream ) || ! is_array( $stat ) || ! isset( $stat['size'] ) || $stat['size'] !== $artifact['bytes'] ) {
                    if ( is_resource( $stream ) ) {
                        fclose( $stream );
                    }
                    self::release_submission_lock( $locked );
                    return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'file_missing' );
                }
                $artifact['stream'] = $stream;
            }
        }
        self::release_submission_lock( $locked );
        return self::success( array( 'artifact' => $artifact ) );
    }

    public static function update_finalized_availability( $submission_id, $uploads_dir, $delete_after, $now = null ) {
        if ( ! self::valid_submission_id( $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $now = self::now( $now );
        if ( $delete_after !== null && ( ! is_int( $delete_after ) || $delete_after <= $now ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'availability_invalid' );
        }
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            $lifecycle->release();
            return $capacity;
        }
        $locked = self::lock_submission( $submission_id, $uploads_dir, false, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return $locked;
        }
        $is_worker = self::worker_manifest_file_version( $locked['manifest_path'] ) === self::WORKER_MANIFEST_VERSION;
        $manifest = $is_worker
            ? self::read_worker_manifest( $locked['manifest_path'], 'submission', $submission_id, true, false )
            : self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( ! self::finalized_available( $manifest, $now ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        if ( $is_worker ) {
            $capacity_record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
            if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $capacity_record, $manifest, 'submission' ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                $lifecycle->release();
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
        }
        $manifest['delete_after'] = $delete_after;
        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        $summary = $written ? ( $is_worker ? self::worker_submission_summary( $manifest ) : self::submission_summary( $manifest ) ) : null;
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        $lifecycle->release();
        return $written
            ? self::success( array( 'submission' => $summary ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
    }

    public static function mark_email_attempted( $submission_id, $uploads_dir, $now = null ) {
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            $lifecycle->release();
            return $capacity;
        }
        $locked = self::lock_submission( $submission_id, $uploads_dir, false, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return $locked;
        }
        $is_worker = self::worker_manifest_file_version( $locked['manifest_path'] ) === self::WORKER_MANIFEST_VERSION;
        $manifest = $is_worker
            ? self::read_worker_manifest( $locked['manifest_path'], 'submission', $submission_id, true, false )
            : self::read_manifest( $locked['manifest_path'], 'submission', $submission_id, false );
        if ( $manifest === null || $manifest['state'] !== 'finalized' || isset( $manifest['email_attempted_at'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            $lifecycle->release();
            return self::failure( 'EFORMS_ERR_TOKEN', 'email_attempt_denied' );
        }
        if ( $is_worker ) {
            $capacity_record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
            if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $capacity_record, $manifest, 'submission' ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                $lifecycle->release();
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
            }
        }
        $manifest['email_attempted_at'] = self::now( $now );
        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        $lifecycle->release();
        return $written ? self::success() : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
    }

    /**
     * Serialize the initial or recovered synchronous-file commit for one
     * finalized submission before its durable email-attempt marker.
     * The callback performs only synchronous upload storage and takes no
     * managed-capacity or ledger locks.
     */
    public static function run_synchronous_commit( $submission_id, $uploads_dir, $callback ) {
        if ( ! is_callable( $callback ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'synchronous_commit_callback_invalid' );
        }
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $locked = self::lock_submission( $submission_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $is_worker = self::worker_manifest_file_version( $locked['manifest_path'] ) === self::WORKER_MANIFEST_VERSION;
        $manifest = $is_worker
            ? self::read_worker_manifest( $locked['manifest_path'], 'submission', $submission_id )
            : self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( $manifest === null || $manifest['state'] !== 'finalized' || isset( $manifest['email_attempted_at'] ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'synchronous_commit_denied' );
        }

        try {
            $result = call_user_func( $callback, $lifecycle );
        } finally {
            self::release_lock( $locked['lock'] );
        }
        return is_array( $result )
            ? $result
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'synchronous_commit_callback_invalid' );
    }

    /**
     * Whether the exact finalized aggregate is safely absent. Recovery files
     * are created only after this aggregate exists, so absence is monotonic for
     * one ledger-owned submission id and does not require creating a lock.
     */
    public static function submission_aggregate_absent( $private_dir, $submission_id ) {
        if ( ! self::valid_submission_id( $submission_id ) || ! is_string( $private_dir ) || $private_dir === '' || is_link( $private_dir ) || ! is_dir( $private_dir ) ) {
            return false;
        }

        $root = rtrim( $private_dir, '/\\' ) . '/' . self::SUBMISSIONS_DIR;
        if ( is_link( $root ) || ( file_exists( $root ) && ! is_dir( $root ) ) ) {
            return false;
        }
        if ( ! file_exists( $root ) ) {
            return true;
        }

        $shard = $root . '/' . Helpers::h2( $submission_id );
        if ( is_link( $shard ) || ( file_exists( $shard ) && ! is_dir( $shard ) ) ) {
            return false;
        }
        if ( ! file_exists( $shard ) ) {
            return true;
        }

        $aggregate = $shard . '/' . $submission_id;
        return ! is_link( $aggregate ) && ! file_exists( $aggregate );
    }

    /**
     * Inspect managed-capacity accounting without creating private storage or lock files.
     */
    public static function capacity_health( $uploads_dir, $lifecycle ) {
        $capacity = self::lock_capacity_for_health( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        if ( ! is_resource( $capacity['lock'] ) ) {
            return self::success(
                array(
                    'capacity' => array(
                        'total_bytes' => 0,
                        'file_bytes' => 0,
                        'reserved_bytes' => 0,
                        'committing_bytes' => 0,
                        'orphaned_bytes' => 0,
                        'consistent' => true,
                        'artifact_stores' => array(),
                        'artifact_store_identities' => array(),
                    ),
                )
            );
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        if ( $record === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $health = self::mixed_capacity_health( $capacity['private_dir'], $record );
        self::release_lock( $capacity['lock'] );
        if ( $health === null ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_scan_failed' );
        }
        return self::success(
            array( 'capacity' => $health )
        );
    }

    /**
     * Reserve one preview-sized physical allocation while serialized with
     * upload capacity admission. The preallocated file makes the claim visible
     * to later disk_free_space() checks without turning cache bytes into
     * authoritative managed-capacity state.
     */
    public static function reserve_preview_cache_allocation( $uploads_dir, $lifecycle, $path, $bytes, $options = array() ) {
        $bytes = is_numeric( $bytes ) ? (int) $bytes : 0;
        if ( ! $lifecycle instanceof PrivateDirLease
            || $bytes !== Anchors::get( 'REVIEW_PREVIEW_MAX_BYTES' )
            || ! self::valid_preview_allocation_path( $lifecycle, $path )
        ) {
            return false;
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return false;
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        $outstanding = ManagedCapacityStore::local_outstanding_allocation_bytes( $record );
        $free = self::free_bytes( $capacity['private_dir'], $options );
        $minimum = Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' );
        $admitted = is_int( $outstanding )
            && is_int( $free )
            && $outstanding <= PHP_INT_MAX - $bytes
            && $outstanding + $bytes <= $free
            && $free - $outstanding - $bytes >= $minimum
            && self::preallocate_file( $path, $bytes );
        self::release_lock( $capacity['lock'] );
        return $admitted;
    }

    public static function reconcile_capacity( $uploads_dir, $stale_reservation_before, $now = null, $remote_delete = null ) {
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return $capacity;
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        if ( $record === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $now = self::now( $now );
        $previous_total_bytes = (int) $record['total_bytes'];
        $previous_reservation_count = count( $record['reservations'] );
        $remote = self::remote_capacity_authorities( $capacity['private_dir'], $record, array(), false );
        if ( $remote === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
        }
        if ( self::worker_capacity_record_has_remote_state( $record, $remote )
            && ! self::worker_capacity_globally_consistent( $capacity['private_dir'], $record )
        ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        foreach ( $remote['authorities'] as $reservation_id => $authority ) {
            if ( empty( $authority['requires_reservation'] ) ) {
                continue;
            }
            if ( ! isset( $record['reservations'][ $reservation_id ] )
                || ! ManagedCapacityStore::remote_reservation_matches( $record['reservations'][ $reservation_id ], $authority )
            ) {
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
            }
        }

        $local_reservations = array();
        $remote_reservations = array();
        $remote_orphan_bytes = 0;
        $remote_committed_settled = 0;
        $cleanup = null;
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            if ( $reservation['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
                $local_reservations[ $reservation_id ] = $reservation;
                continue;
            }
            $authority = isset( $remote['authorities'][ $reservation_id ] ) ? $remote['authorities'][ $reservation_id ] : null;
            if ( is_array( $authority ) ) {
                if ( ! ManagedCapacityStore::remote_reservation_matches( $reservation, $authority )
                    || ! empty( $reservation['cleanup_started'] )
                ) {
                    self::release_lock( $capacity['lock'] );
                    return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
                }
                if ( $authority['kind'] === 'item' ) {
                    $settled = ManagedCapacityStore::finish_committed(
                        $record,
                        $reservation_id,
                        $reservation['batch_id'],
                        $reservation['upload_id'],
                        $reservation['object_key'],
                        $authority['bytes'],
                        $now
                    );
                    if ( ! is_array( $settled ) ) {
                        self::release_lock( $capacity['lock'] );
                        return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
                    }
                    $record = $settled;
                    $remote_committed_settled++;
                    continue;
                }
                $remote_reservations[ $reservation_id ] = $reservation;
                continue;
            }
            if ( empty( $reservation['cleanup_started'] )
                && ! self::remote_reservation_cleanup_ready( $reservation, $now, $stale_reservation_before )
            ) {
                $remote_reservations[ $reservation_id ] = $reservation;
                if ( $remote_orphan_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                    self::release_lock( $capacity['lock'] );
                    return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
                }
                $remote_orphan_bytes += $reservation['bytes'];
                continue;
            }
            if ( $cleanup === null ) {
                $cleanup = array( 'id' => $reservation_id, 'reservation' => $reservation );
            } else {
                $remote_reservations[ $reservation_id ] = $reservation;
                if ( $remote_orphan_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                    self::release_lock( $capacity['lock'] );
                    return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
                }
                $remote_orphan_bytes += $reservation['bytes'];
            }
        }

        if ( is_array( $cleanup ) ) {
            if ( ! is_callable( $remote_delete ) ) {
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'remote_capacity_reconcile_unsupported' );
            }
            $authority = self::worker_remote_reservation_authority( $cleanup['reservation'] );
            if ( $authority === null ) {
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
            }
            $started = ManagedCapacityStore::begin_remote_reservation_cleanup(
                $record,
                $cleanup['id'],
                $cleanup['reservation']['artifact_store_identity'],
                $now
            );
            if ( ! is_array( $started ) || ! ManagedCapacityStore::write( $capacity['path'], $started ) ) {
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_write_failed' );
            }
            self::release_lock( $capacity['lock'] );
            $deleted = call_user_func( $remote_delete, $authority );
            if ( ! is_array( $deleted ) || empty( $deleted['ok'] ) || empty( $deleted['absent'] ) ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'remote_delete_failed' );
            }
            $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
            if ( empty( $capacity['ok'] ) ) {
                return $capacity;
            }
            $current = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
            $current_reservation = is_array( $current ) && isset( $current['reservations'][ $cleanup['id'] ] )
                ? $current['reservations'][ $cleanup['id'] ]
                : null;
            $expected_remote = array(
                'batch_id' => $cleanup['reservation']['batch_id'],
                'upload_id' => $cleanup['reservation']['upload_id'],
                'bytes' => $cleanup['reservation']['bytes'],
                'artifact_store_identity' => $cleanup['reservation']['artifact_store_identity'],
                'object_key' => $cleanup['reservation']['object_key'],
            );
            foreach ( array( 'validation_contract_version', 'policy_fingerprint', 'validation_until' ) as $worker_field ) {
                if ( isset( $cleanup['reservation'][ $worker_field ] ) ) {
                    $expected_remote[ $worker_field ] = $cleanup['reservation'][ $worker_field ];
                }
            }
            if ( ! is_array( $current_reservation )
                || empty( $current_reservation['cleanup_started'] )
                || ! ManagedCapacityStore::remote_reservation_matches( $current_reservation, $expected_remote )
            ) {
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
            }
            $finished = self::finish_exact_remote_reservation_release( $current, $cleanup['id'], $expected_remote, $now );
            if ( empty( $finished['ok'] ) || ! ManagedCapacityStore::write( $capacity['path'], $finished['record'] ) ) {
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_write_failed' );
            }
            self::release_lock( $capacity['lock'] );
            return self::success(
                array(
                    'capacity' => $finished['record'],
                    'previous_total_bytes' => $previous_total_bytes,
                    'stale_reservations_removed' => 1,
                    'committed_reservations_settled' => $remote_committed_settled,
                    'materialized_reservations_retained' => 0,
                )
            );
        }

        $file_bytes = LocalArtifactStore::reconcile_bytes(
            $lifecycle,
            max( 0, $now - Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' ) )
        );
        if ( $file_bytes === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
        }
        $materialization = self::reservation_materialization( $capacity['private_dir'], $local_reservations );
        if ( $materialization === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
        }
        // Make absence durable before releasing a stale reservation. A writer
        // authorized earlier may not have reached the object lock yet.
        foreach ( $local_reservations as $reservation_id => $reservation ) {
            if ( isset( $materialization['committed'][ $reservation_id ] )
                || isset( $materialization['orphaned'][ $reservation_id ] )
                || $reservation['created_at'] > (int) $stale_reservation_before
            ) {
                continue;
            }
            if ( ! self::delete_local_artifact( $lifecycle, $reservation['object_key'], '' ) ) {
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
            }
        }
        $local_record = $record;
        $local_record['reservations'] = $local_reservations;
        $local_record['total_bytes'] = 0;
        $local_record['store_bytes'] = array(
            FormProtocol::UPLOAD_TRANSPORT_LOCAL => 0,
            FormProtocol::UPLOAD_TRANSPORT_WORKER => 0,
        );
        $local_record = ManagedCapacityStore::reconcile(
            $local_record,
            $materialization['committed'],
            $materialization['orphaned'],
            $file_bytes,
            $stale_reservation_before,
            $now
        );
        if ( $local_record === null
            || $remote['bytes'] > PHP_INT_MAX - $remote_orphan_bytes
            || $local_record['total_bytes'] > PHP_INT_MAX - $remote['bytes'] - $remote_orphan_bytes
        ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
        }
        $record['reservations'] = $local_record['reservations'] + $remote_reservations;
        $record['total_bytes'] = $local_record['total_bytes'] + $remote['bytes'] + $remote_orphan_bytes;
        $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_LOCAL ] = $local_record['total_bytes'];
        $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] = $remote['bytes'] + $remote_orphan_bytes;
        $record['updated_at'] = $now;
        foreach ( array_keys( $record['releases'] ) as $batch_id ) {
            if ( empty( $remote['batches'][ $batch_id ] ) ) {
                unset( $record['releases'][ $batch_id ] );
            }
        }
        $written = ManagedCapacityStore::write( $capacity['path'], $record );
        self::release_lock( $capacity['lock'] );
        return $written
            ? self::success(
                array(
                    'capacity' => $record,
                    'previous_total_bytes' => $previous_total_bytes,
                    'stale_reservations_removed' => $previous_reservation_count - count( $record['reservations'] ) - count( $materialization['committed'] ) - $remote_committed_settled,
                    'committed_reservations_settled' => count( $materialization['committed'] ) + $remote_committed_settled,
                    'materialized_reservations_retained' => count( $materialization['orphaned'] ),
                )
            )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_write_failed' );
    }

    public static function gc_aggregates( $family, $uploads_dir, $now, $limit, $dry_run = false, $cursor = array(), $remote_delete = null ) {
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return array( 'ok' => false, 'reason' => 'upload_lifecycle_unavailable' );
        }
        $result = array(
            'ok' => true,
            'reason' => '',
            'scanned' => 0,
            'candidates' => 0,
            'candidate_bytes' => 0,
            'candidate_artifact_bytes' => 0,
            'deleted' => 0,
            'deleted_bytes' => 0,
            'deleted_artifact_bytes' => 0,
            'released_bytes' => 0,
            'errors' => 0,
            'reached_limit' => false,
            'cursor' => array(),
        );
        $root_name = $family === 'staged' ? self::STAGED_DIR : ( $family === 'finalized' ? self::SUBMISSIONS_DIR : '' );
        $limit = is_numeric( $limit ) ? max( 0, (int) $limit ) : 0;
        if ( $root_name === '' || $limit < 1 ) {
            return $result;
        }
        $private_dir = PrivateDir::path( $uploads_dir );
        $root = $private_dir === '' ? '' : rtrim( $private_dir, '/\\' ) . '/' . $root_name;
        if ( $root === '' || is_link( $root ) || ( file_exists( $root ) && ! is_dir( $root ) ) ) {
            if ( $root !== '' && ( file_exists( $root ) || is_link( $root ) ) ) {
                $result['ok'] = false;
                $result['errors'] = 1;
                $result['reason'] = 'aggregate_enumeration_failed';
                $result['cursor'] = is_array( $cursor ) ? $cursor : array();
            }
            return $result;
        }
        if ( ! is_dir( $root ) ) {
            return $result;
        }

        $discovered = self::aggregate_paths( $root, $limit, $cursor, false );
        if ( empty( $discovered['ok'] ) ) {
            $result['ok'] = false;
            $result['errors'] = 1;
            $result['reason'] = isset( $discovered['reason'] ) ? $discovered['reason'] : 'aggregate_enumeration_failed';
            $result['cursor'] = is_array( $cursor ) ? $cursor : array();
            return $result;
        }
        $result['cursor'] = $discovered['cursor'];
        $completed_cursor = is_array( $cursor ) ? $cursor : array();
        $first_error_cursor = null;
        foreach ( $discovered['paths'] as $path ) {
            if ( $result['scanned'] >= $limit ) {
                $result['reached_limit'] = true;
                break;
            }
            $result['scanned']++;
            $one = self::gc_aggregate( $family, $path, $uploads_dir, $lifecycle, self::now( $now ), (bool) $dry_run, $remote_delete );
            if ( empty( $one['ok'] ) ) {
                $result['errors']++;
                if ( $first_error_cursor === null ) {
                    $first_error_cursor = $completed_cursor;
                }
                if ( $result['reason'] === '' ) {
                    $result['reason'] = isset( $one['reason'] ) ? $one['reason'] : 'aggregate_gc_failed';
                }
                if ( isset( $one['reason'] ) && $one['reason'] === 'remote_delete_failed' ) {
                    $result['ok'] = false;
                    $result['cursor'] = $completed_cursor;
                    break;
                }
                if ( ! empty( $one['fatal'] ) ) {
                    $result['ok'] = false;
                    $result['cursor'] = $completed_cursor;
                    break;
                }
                $completed_cursor = array( 'shard' => basename( dirname( $path ) ), 'aggregate' => basename( $path ) );
                continue;
            }
            $completed_cursor = array( 'shard' => basename( dirname( $path ) ), 'aggregate' => basename( $path ) );
            if ( empty( $one['candidate'] ) ) {
                continue;
            }
            $result['candidates']++;
            $result['candidate_bytes'] += $one['managed_bytes'];
            $result['candidate_artifact_bytes'] += $one['artifact_bytes'];
            $result['released_bytes'] += isset( $one['released_bytes'] ) ? max( 0, (int) $one['released_bytes'] ) : 0;
            if ( ! empty( $one['deleted'] ) ) {
                $result['deleted']++;
                $result['deleted_bytes'] += $one['managed_bytes'];
                $result['deleted_artifact_bytes'] += $one['artifact_bytes'];
            }
        }
        if ( $first_error_cursor !== null ) {
            $result['cursor'] = $first_error_cursor;
        }
        return $result;
    }

    public static function delete_finalized_submission( $submission_id, $uploads_dir, $now = null, $remote_delete = null ) {
        if ( ! self::valid_submission_id( $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $root = PrivateDir::existing_protected_review_subdir( $uploads_dir, self::SUBMISSIONS_DIR );
        if ( $root === '' ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $path = $root . '/' . Helpers::h2( $submission_id ) . '/' . $submission_id;
        if ( is_link( $path ) || ! is_dir( $path ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $manifest_path = rtrim( $path, '/\\' ) . '/' . self::MANIFEST_FILENAME;
        $is_worker = self::worker_manifest_file_version( $manifest_path ) === self::WORKER_MANIFEST_VERSION;
        $now = self::now( $now );
        if ( $is_worker ) {
            $deleted = self::worker_gc_aggregate( 'finalized', $path, $uploads_dir, $lifecycle, $now, false, $remote_delete, self::AGGREGATE_CLEANUP_OPERATOR_DELETE );
            if ( empty( $deleted['ok'] ) || ( empty( $deleted['deleted'] ) && empty( $deleted['candidate'] ) ) ) {
                return self::failure(
                    'EFORMS_ERR_STORAGE_UNAVAILABLE',
                    isset( $deleted['reason'] ) && is_string( $deleted['reason'] ) ? $deleted['reason'] : 'submission_delete_failed'
                );
            }
            return self::success(
                array(
                    'deleted' => true,
                    'released_bytes' => isset( $deleted['released_bytes'] ) ? (int) $deleted['released_bytes'] : 0,
                    'physical_delete_pending' => empty( $deleted['deleted'] ),
                )
            );
        } else {
            $deleted = self::gc_aggregate( 'finalized', $path, $uploads_dir, $lifecycle, $now, false, $remote_delete, self::AGGREGATE_CLEANUP_OPERATOR_DELETE );
        }
        if ( empty( $deleted['ok'] ) || empty( $deleted['deleted'] ) ) {
            return self::failure(
                'EFORMS_ERR_STORAGE_UNAVAILABLE',
                isset( $deleted['reason'] ) && is_string( $deleted['reason'] ) ? $deleted['reason'] : 'submission_delete_failed'
            );
        }
        return self::success(
            array(
                'deleted' => true,
                'released_bytes' => isset( $deleted['released_bytes'] ) ? (int) $deleted['released_bytes'] : 0,
            )
        );
    }

    private static function gc_aggregate( $family, $path, $uploads_dir, $lifecycle, $now, $dry_run, $remote_delete, $cleanup_mode = self::AGGREGATE_CLEANUP_GC ) {
        $cleanup_mode = self::aggregate_cleanup_mode( $cleanup_mode );
        $manifest_path = rtrim( $path, '/\\' ) . '/' . self::MANIFEST_FILENAME;
        if ( ! file_exists( $manifest_path ) ) {
            return self::gc_local_aggregate( $family, $path, $uploads_dir, $lifecycle, $now, $dry_run, $cleanup_mode );
        }
        if ( self::worker_manifest_file_version( $manifest_path ) === self::WORKER_MANIFEST_VERSION ) {
            return self::worker_gc_aggregate( $family, $path, $uploads_dir, $lifecycle, $now, $dry_run, $remote_delete, $cleanup_mode );
        }
        $manifest = self::read_manifest(
            $manifest_path,
            $family === 'staged' ? 'staged' : 'submission',
            basename( rtrim( $path, '/\\' ) )
        );
        if ( $manifest === null ) {
            return array( 'ok' => false, 'reason' => 'manifest_invalid' );
        }
        if ( $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
            return array( 'ok' => false, 'reason' => 'manifest_invalid' );
        }
        return self::gc_local_aggregate( $family, $path, $uploads_dir, $lifecycle, $now, $dry_run, $cleanup_mode );
    }

    private static function aggregate_cleanup_mode( $cleanup_mode ) {
        return $cleanup_mode === self::AGGREGATE_CLEANUP_OPERATOR_DELETE
            ? self::AGGREGATE_CLEANUP_OPERATOR_DELETE
            : self::AGGREGATE_CLEANUP_GC;
    }

    private static function aggregate_cleanup_state_eligible( $family, $manifest, $cleanup_mode ) {
        $state = isset( $manifest['state'] ) ? $manifest['state'] : '';
        if ( $cleanup_mode === self::AGGREGATE_CLEANUP_OPERATOR_DELETE ) {
            return $family === 'finalized' && $state === 'finalized';
        }
        return $family === 'staged'
            ? in_array( $state, array( 'open', 'finalizing' ), true )
            : in_array( $state, array( 'finalizing', 'finalized' ), true );
    }

    private static function aggregate_cleanup_time_eligible( $manifest, $now, $cleanup_mode ) {
        if ( $cleanup_mode === self::AGGREGATE_CLEANUP_OPERATOR_DELETE ) {
            return true;
        }
        return isset( $manifest['delete_after'] )
            && is_numeric( $manifest['delete_after'] )
            && (int) $manifest['delete_after'] <= $now;
    }

    private static function delete_local_artifact( $lifecycle, $object_key, $object_version ) {
        if ( $object_version !== '' && ! LocalPreviewProvider::delete_cache( $lifecycle, $object_key, $object_version ) ) {
            return false;
        }
        return LocalArtifactStore::delete( $lifecycle, $object_key, $object_version );
    }

    private static function gc_local_aggregate( $family, $path, $uploads_dir, $lifecycle, $now, $dry_run, $cleanup_mode = self::AGGREGATE_CLEANUP_GC ) {
        $cleanup_mode = self::aggregate_cleanup_mode( $cleanup_mode );
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_lock_failed' );
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        if ( $record === null ) {
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_invalid' );
        }
        $path = rtrim( $path, '/\\' );
        $manifest_path = $path . '/' . self::MANIFEST_FILENAME;
        $lock_family = $family === 'staged' ? self::STAGED_DIR : self::SUBMISSIONS_DIR;
        $lock_path = self::aggregate_lock_path( $lock_family, $path );
        if ( is_link( $path )
            || is_link( dirname( $path ) )
            || is_link( $manifest_path )
            || is_link( $lock_path )
        ) {
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'aggregate_layout_invalid' );
        }
        $partial_observed_at = $family === 'staged' && ! file_exists( $manifest_path )
            ? self::partial_batch_observed_at( $path )
            : null;
        $lock = $lock_family === self::STAGED_DIR
            ? self::acquire_staged_lock( $path, $partial_observed_at !== null )
            : self::acquire_lock( $lock_path );
        if ( $lock === false ) {
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'aggregate_lock_failed' );
        }
        $manifest = self::read_manifest(
            $manifest_path,
            $family === 'staged' ? 'staged' : 'submission',
            basename( rtrim( $path, '/\\' ) )
        );
        if ( $manifest === null ) {
            if ( $family === 'staged'
                && ! file_exists( $manifest_path )
                && $partial_observed_at !== null
                && self::initializable_partial_batch( $path )
            ) {
                $stale_after = $partial_observed_at
                    + Anchors::get( 'TOKEN_TTL_MAX' )
                    + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
                if ( $stale_after > $now ) {
                    self::release_lock( $lock );
                    self::release_lock( $capacity['lock'] );
                    return array( 'ok' => true, 'candidate' => false );
                }
                $out = array(
                    'ok' => true,
                    'candidate' => true,
                    'deleted' => false,
                    'managed_bytes' => 0,
                    'artifact_bytes' => 0,
                    'released_bytes' => 0,
                );
                if ( $dry_run ) {
                    self::release_lock( $lock );
                    self::release_lock( $capacity['lock'] );
                    return $out;
                }
                if ( ! self::delete_locked_aggregate( $path, $lock, false ) ) {
                    self::release_lock( $capacity['lock'] );
                    return array( 'ok' => false, 'reason' => 'aggregate_delete_failed' );
                }
                $out['deleted'] = true;
                self::release_lock( $capacity['lock'] );
                if ( $family === 'staged' ) {
                    self::remove_staged_lock_file( $path );
                }
                @rmdir( dirname( $path ) );
                return $out;
            }
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'manifest_invalid' );
        }
        if ( ! self::aggregate_cleanup_state_eligible( $family, $manifest, $cleanup_mode )
            || ! self::aggregate_cleanup_time_eligible( $manifest, $now, $cleanup_mode )
        ) {
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => true, 'candidate' => false );
        }
        $plan = self::local_aggregate_cleanup_plan( $manifest, $uploads_dir, $record, $now );
        if ( empty( $plan['ok'] ) ) {
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return array(
                'ok' => false,
                'fatal' => ! empty( $plan['fatal'] ),
                'reason' => isset( $plan['reason'] ) ? $plan['reason'] : 'capacity_inconsistent',
            );
        }
        $out = array(
            'ok' => true,
            'candidate' => true,
            'deleted' => false,
            'managed_bytes' => $plan['parts']['managed_bytes'],
            'artifact_bytes' => $plan['parts']['artifact_bytes'],
            'released_bytes' => 0,
        );
        if ( $dry_run ) {
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return $out;
        }
        if ( $cleanup_mode === self::AGGREGATE_CLEANUP_GC ) {
            $manifest_fingerprint = self::local_gc_manifest_fingerprint( $manifest );
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            foreach ( $plan['objects'] as $object_key => $object_version ) {
                if ( ! self::delete_local_artifact( $lifecycle, $object_key, $object_version ) ) {
                    return array( 'ok' => false, 'reason' => 'artifact_delete_failed' );
                }
            }

            $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
            if ( empty( $capacity['ok'] ) ) {
                return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_lock_failed' );
            }
            $lock = $lock_family === self::STAGED_DIR
                ? self::acquire_staged_lock( $path )
                : self::acquire_lock( $lock_path );
            if ( $lock === false ) {
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'aggregate_lock_failed' );
            }
            $current = self::read_manifest(
                $manifest_path,
                $family === 'staged' ? 'staged' : 'submission',
                basename( rtrim( $path, '/\\' ) )
            );
            $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
            if ( $current === null
                || $record === null
                || ! hash_equals( $manifest_fingerprint, self::local_gc_manifest_fingerprint( $current ) )
            ) {
                self::release_lock( $lock );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => $record === null ? 'capacity_invalid' : 'aggregate_changed' );
            }
            $plan = self::local_aggregate_cleanup_plan( $current, $uploads_dir, $record, $now );
            if ( empty( $plan['ok'] ) ) {
                self::release_lock( $lock );
                self::release_lock( $capacity['lock'] );
                return array(
                    'ok' => false,
                    'fatal' => ! empty( $plan['fatal'] ),
                    'reason' => isset( $plan['reason'] ) ? $plan['reason'] : 'capacity_inconsistent',
                );
            }
        } else {
            foreach ( $plan['objects'] as $object_key => $object_version ) {
                if ( ! self::delete_local_artifact( $lifecycle, $object_key, $object_version ) ) {
                    self::release_lock( $lock );
                    self::release_lock( $capacity['lock'] );
                    return array( 'ok' => false, 'reason' => 'artifact_delete_failed' );
                }
            }
        }
        $released = $plan['released'];
        if ( ! self::delete_locked_aggregate( $path, $lock ) ) {
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'aggregate_delete_failed' );
        }
        $out['deleted'] = true;
        if ( ! ManagedCapacityStore::write( $capacity['path'], $released['record'] ) ) {
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_write_failed' );
        }
        $out['released_bytes'] = $released['released_bytes'];
        self::release_lock( $capacity['lock'] );
        if ( $family === 'staged' ) {
            self::remove_staged_lock_file( $path );
        }
        @rmdir( dirname( $path ) );
        return $out;
    }

    private static function local_aggregate_cleanup_plan( $manifest, $uploads_dir, $record, $now ) {
        $parts = self::aggregate_byte_parts( $manifest );
        if ( $parts === null ) {
            return array( 'ok' => false, 'reason' => 'manifest_bytes_invalid' );
        }
        $pending_tombstone_bytes = 0;
        $already_released_bytes = array();
        $attributed_bytes = array();
        $objects = array();
        foreach ( $manifest['items'] as $upload_id => $item ) {
            $attributed_bytes[ $upload_id ] = (int) $item['bytes'];
            if ( ! empty( $item['object_key'] ) ) {
                $objects[ $item['object_key'] ] = isset( $item['object_version'] ) ? $item['object_version'] : '';
            }
        }
        foreach ( $manifest['intents'] as $upload_id => $intent ) {
            $attributed_bytes[ $upload_id ] = (int) $intent['reserved_bytes'];
            if ( ! empty( $intent['object_key'] ) ) {
                $objects[ $intent['object_key'] ] = '';
            }
            $materialized = LocalArtifactStore::bytes_for_key( $uploads_dir, $intent['object_key'] );
            if ( $materialized === 0 ) {
                $already_released_bytes[ $upload_id ] = (int) $intent['reserved_bytes'];
            } elseif ( $materialized === null ) {
                return array( 'ok' => false, 'reason' => 'artifact_layout_invalid' );
            }
        }
        foreach ( $manifest['tombstones'] as $upload_id => $tombstone ) {
            if ( ! empty( $tombstone['object_key'] ) ) {
                $objects[ $tombstone['object_key'] ] = isset( $tombstone['object_version'] ) ? $tombstone['object_version'] : '';
            }
            if ( ! empty( $tombstone['capacity_released'] ) ) {
                continue;
            }
            $pending_tombstone_bytes += (int) $tombstone['bytes'];
            $attributed_bytes[ $upload_id ] = (int) $tombstone['bytes'];
            $materialized = $tombstone['object_key'] === '' ? 0 : LocalArtifactStore::bytes_for_key( $uploads_dir, $tombstone['object_key'] );
            if ( $materialized === 0 ) {
                $already_released_bytes[ $upload_id ] = (int) $tombstone['bytes'];
            } elseif ( $materialized === null ) {
                return array( 'ok' => false, 'reason' => 'artifact_layout_invalid' );
            }
        }
        $intent_bytes = self::active_artifact_bytes( $manifest ) - $manifest['artifact_bytes'];
        $manifest_capacity_bytes = $parts['managed_bytes'] + $intent_bytes + $pending_tombstone_bytes;
        $released = ManagedCapacityStore::release_aggregate(
            $record,
            $manifest['batch_id'],
            $manifest_capacity_bytes,
            $attributed_bytes,
            $already_released_bytes,
            FormProtocol::UPLOAD_TRANSPORT_LOCAL,
            $now
        );
        if ( empty( $released['ok'] ) ) {
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
        }
        return array(
            'ok' => true,
            'parts' => $parts,
            'objects' => $objects,
            'released' => $released,
        );
    }

    private static function local_gc_manifest_fingerprint( $manifest ) {
        $encoded = json_encode( $manifest, JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
    }

    private static function worker_gc_context( $family, $path ) {
        $path = is_string( $path ) ? rtrim( $path, '/\\' ) : '';
        if ( $path === '' ) {
            return null;
        }
        $normalized_family = null;
        if ( $family === self::STAGED_DIR || $family === 'staged' ) {
            $normalized_family = self::STAGED_DIR;
            $manifest_family = 'staged';
            $aggregate_id = basename( $path );
            $staged_lock_cleanup = true;
            $expiry_states = array( 'open', 'finalizing' );
        } elseif ( $family === self::SUBMISSIONS_DIR || $family === 'finalized' ) {
            $normalized_family = self::SUBMISSIONS_DIR;
            $manifest_family = 'submission';
            $aggregate_id = basename( $path );
            $staged_lock_cleanup = false;
            $expiry_states = array( 'finalizing', 'finalized', 'operator_deleted' );
        } else {
            return null;
        }
        $manifest_path = $path . '/' . self::MANIFEST_FILENAME;
        $lock_path = self::aggregate_lock_path( $normalized_family, $path );
        if ( $lock_path === ''
            || is_link( $path )
            || is_link( dirname( $path ) )
            || is_link( $manifest_path )
            || is_link( $lock_path )
        ) {
            return null;
        }
        return array(
            'family' => $normalized_family,
            'path' => $path,
            'manifest_path' => $manifest_path,
            'lock_path' => $lock_path,
            'manifest_family' => $manifest_family,
            'aggregate_id' => $aggregate_id,
            'staged_lock_cleanup' => $staged_lock_cleanup,
            'expiry_states' => $expiry_states,
        );
    }

    private static function worker_gc_lock_context( $context, $uploads_dir, $repair_lock = true ) {
        if ( ! is_array( $context ) || ! isset( $context['family'], $context['path'], $context['manifest_path'], $context['lock_path'], $context['aggregate_id'] ) ) {
            return array( 'ok' => false, 'reason' => 'aggregate_layout_invalid' );
        }
        if ( $context['family'] === self::STAGED_DIR ) {
            clearstatcache( true, $context['path'] );
            if ( is_link( $context['path'] ) || ! is_dir( $context['path'] ) ) {
                return array( 'ok' => false, 'gone' => true );
            }
            $lock = self::acquire_staged_lock( $context['path'], false, false, $repair_lock );
            if ( $lock === false ) {
                clearstatcache( true, $context['path'] );
                if ( is_link( $context['path'] ) || ! is_dir( $context['path'] ) ) {
                    return array( 'ok' => false, 'gone' => true );
                }
                return array( 'ok' => false, 'reason' => 'aggregate_lock_failed' );
            }
            clearstatcache( true, $context['manifest_path'] );
            if ( ! is_dir( $context['path'] ) || ! is_file( $context['manifest_path'] ) ) {
                self::release_lock( $lock );
                return array( 'ok' => false, 'gone' => true );
            }
            return array(
                'ok' => true,
                'path' => $context['path'],
                'manifest_path' => $context['manifest_path'],
                'lock' => $lock,
            );
        }
        if ( $context['family'] !== self::SUBMISSIONS_DIR ) {
            return array( 'ok' => false, 'reason' => 'aggregate_layout_invalid' );
        }
        clearstatcache( true, $context['path'] );
        if ( is_link( $context['path'] ) || ! is_dir( $context['path'] ) ) {
            return array( 'ok' => false, 'gone' => true );
        }
        $lock = self::acquire_existing_lock( $context['lock_path'], false, $repair_lock );
        if ( $lock === false ) {
            clearstatcache( true, $context['path'] );
            if ( is_link( $context['path'] ) || ! is_dir( $context['path'] ) ) {
                return array( 'ok' => false, 'gone' => true );
            }
            return array( 'ok' => false, 'reason' => 'aggregate_lock_failed' );
        }
        clearstatcache( true, $context['manifest_path'] );
        if ( ! is_dir( $context['path'] ) || ! is_file( $context['manifest_path'] ) ) {
            self::release_lock( $lock );
            return array( 'ok' => false, 'gone' => true );
        }
        if ( self::managed_purged( $uploads_dir ) ) {
            self::release_lock( $lock );
            return array( 'ok' => false, 'reason' => 'managed_purged' );
        }
        return array(
            'ok' => true,
            'path' => $context['path'],
            'manifest_path' => $context['manifest_path'],
            'lock' => $lock,
        );
    }

    private static function worker_purge_lock_context( $context, $uploads_dir, $repair_lock = true ) {
        if ( ! is_array( $context ) || ! isset( $context['family'], $context['path'], $context['manifest_path'], $context['lock_path'], $context['aggregate_id'] ) ) {
            return array( 'ok' => false, 'reason' => 'aggregate_layout_invalid' );
        }
        if ( $context['family'] === self::STAGED_DIR ) {
            clearstatcache( true, $context['path'] );
            if ( is_link( $context['path'] ) || ! is_dir( $context['path'] ) ) {
                return array( 'ok' => false, 'gone' => true );
            }
            $lock = self::acquire_staged_lock( $context['path'], false, false, $repair_lock );
            if ( $lock === false ) {
                clearstatcache( true, $context['path'] );
                if ( is_link( $context['path'] ) || ! is_dir( $context['path'] ) ) {
                    return array( 'ok' => false, 'gone' => true );
                }
                return array( 'ok' => false, 'reason' => 'aggregate_lock_failed' );
            }
            clearstatcache( true, $context['manifest_path'] );
            if ( ! is_dir( $context['path'] ) || ! is_file( $context['manifest_path'] ) ) {
                self::release_lock( $lock );
                return array( 'ok' => false, 'gone' => true );
            }
            return array(
                'ok' => true,
                'path' => $context['path'],
                'manifest_path' => $context['manifest_path'],
                'lock' => $lock,
            );
        }
        if ( $context['family'] !== self::SUBMISSIONS_DIR ) {
            return array( 'ok' => false, 'reason' => 'aggregate_layout_invalid' );
        }
        clearstatcache( true, $context['path'] );
        if ( is_link( $context['path'] ) || ! is_dir( $context['path'] ) ) {
            return array( 'ok' => false, 'gone' => true );
        }
        $lock = self::acquire_existing_lock( $context['lock_path'], false, $repair_lock );
        if ( $lock === false ) {
            clearstatcache( true, $context['path'] );
            if ( is_link( $context['path'] ) || ! is_dir( $context['path'] ) ) {
                return array( 'ok' => false, 'gone' => true );
            }
            return array( 'ok' => false, 'reason' => 'aggregate_lock_failed' );
        }
        clearstatcache( true, $context['manifest_path'] );
        if ( ! is_dir( $context['path'] ) || ! is_file( $context['manifest_path'] ) ) {
            self::release_lock( $lock );
            return array( 'ok' => false, 'gone' => true );
        }
        return array(
            'ok' => true,
            'path' => $context['path'],
            'manifest_path' => $context['manifest_path'],
            'lock' => $lock,
        );
    }

    private static function cleanup_deleted_worker_aggregate_context( $context ) {
        if ( ! empty( $context['staged_lock_cleanup'] ) ) {
            self::remove_staged_lock_file( $context['path'] );
        }
        @rmdir( dirname( $context['path'] ) );
    }

    private static function worker_gc_aggregate( $family, $path, $uploads_dir, $lifecycle, $now, $dry_run, $remote_delete, $cleanup_mode = self::AGGREGATE_CLEANUP_GC ) {
        $context = self::worker_gc_context( $family, $path );
        if ( $context === null ) {
            return array( 'ok' => false, 'reason' => 'aggregate_layout_invalid' );
        }
        $cleanup_mode = self::aggregate_cleanup_mode( $cleanup_mode );
        if ( $cleanup_mode === self::AGGREGATE_CLEANUP_OPERATOR_DELETE ) {
            $context['expiry_states'] = array( 'finalized', 'operator_deleted' );
        }
        $path = $context['path'];

        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_lock_failed' );
        }
        $locked = self::worker_gc_lock_context( $context, $uploads_dir, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            return ! empty( $locked['gone'] )
                ? array( 'ok' => true, 'candidate' => false )
                : array( 'ok' => false, 'reason' => isset( $locked['reason'] ) ? $locked['reason'] : 'aggregate_lock_failed' );
        }

        $manifest = self::read_worker_manifest( $locked['manifest_path'], $context['manifest_family'], $context['aggregate_id'], true, false );
        if ( $manifest === null || $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => true, 'candidate' => false );
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        if ( $record === null ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_invalid' );
        }

        $force_expired = $cleanup_mode === self::AGGREGATE_CLEANUP_OPERATOR_DELETE;
        $aggregate_converted = self::worker_gc_convert_expired_aggregate( $context, $manifest, $record, $now, $force_expired );
        if ( $aggregate_converted === null ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
        }
        if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $record, $aggregate_converted['manifest'], $context['manifest_family'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
        }
        if ( ! empty( $aggregate_converted['changed'] ) ) {
            $manifest = $aggregate_converted['manifest'];
            if ( ! $dry_run && ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'manifest_write_failed' );
            }
        }

        $converted = self::worker_gc_convert_expired_intents( $manifest, $record, $now );
        if ( $converted === null ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
        }
        if ( ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $record, $converted['manifest'], $context['manifest_family'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
        }
        if ( ! empty( $converted['changed'] ) && ! $dry_run ) {
            $manifest = $converted['manifest'];
            if ( ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'manifest_write_failed' );
            }
        }

        $selected = self::worker_gc_targets( $manifest, $record, $now );
        if ( $selected === null ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
        }
        $converted_bytes = self::worker_gc_unselected_converted_bytes( $aggregate_converted, $selected )
            + self::worker_gc_unselected_converted_bytes( $converted, $selected );
        $out = array(
            'ok' => true,
            'candidate' => ! empty( $selected ) || ! empty( $aggregate_converted['changed'] ) || ! empty( $converted['changed'] ),
            'deleted' => false,
            'managed_bytes' => self::worker_gc_target_bytes( $selected ) + $converted_bytes,
            'artifact_bytes' => self::worker_gc_target_bytes( $selected ) + $converted_bytes,
            'released_bytes' => 0,
        );
        $ready = self::worker_gc_delete_ready( $context, $manifest, $record, $now, $force_expired );
        if ( $ready === null ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
        }
        if ( empty( $selected ) && $ready ) {
            $parts = self::aggregate_byte_parts( $manifest );
            if ( $parts === null ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'manifest_bytes_invalid' );
            }
            $out['candidate'] = true;
            $out['managed_bytes'] = $parts['managed_bytes'];
            $out['artifact_bytes'] = $parts['artifact_bytes'];
            if ( $dry_run ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return $out;
            }
            $released = self::release_remote_aggregate_capacity( $record, $manifest, $now );
            if ( empty( $released['ok'] ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
            }
            if ( ! ManagedCapacityStore::write( $capacity['path'], $released['record'] ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_write_failed' );
            }
            if ( ! self::delete_locked_aggregate( $path, $locked['lock'] ) ) {
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'aggregate_delete_failed' );
            }
            $finished = ManagedCapacityStore::finish_remote_aggregate_release(
                $released['record'],
                $manifest['batch_id'],
                $manifest['artifact_store_identity'],
                $now
            );
            if ( ! is_array( $finished ) ) {
                self::release_lock( $capacity['lock'] );
                self::cleanup_deleted_worker_aggregate_context( $context );
                return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
            }
            if ( ! ManagedCapacityStore::write( $capacity['path'], $finished ) ) {
                self::release_lock( $capacity['lock'] );
                self::cleanup_deleted_worker_aggregate_context( $context );
                return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_write_failed' );
            }
            self::release_lock( $capacity['lock'] );
            self::cleanup_deleted_worker_aggregate_context( $context );
            $out['deleted'] = true;
            $out['released_bytes'] = ! empty( $released['changed'] ) ? (int) $released['released_bytes'] : 0;
            return $out;
        }
        if ( empty( $selected ) || $dry_run ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $out;
        }

        $remote_targets = array();
        $manifest_changed = false;
        $capacity_changed = false;
        foreach ( $selected as $upload_id => $target ) {
            if ( ! empty( $target['repair_only'] ) ) {
                continue;
            }
            $tombstone = $manifest['tombstones'][ $upload_id ];
            $prepared = ManagedCapacityStore::prepare_item_release(
                $record,
                $manifest['batch_id'],
                $upload_id,
                (int) $tombstone['bytes'],
                $tombstone['object_key'],
                $tombstone['deleted_at'],
                empty( $tombstone['capacity_release_started'] ),
                $now,
                $manifest['artifact_store'],
                $manifest['artifact_store_identity'],
                array(
                    'validation_contract_version' => $tombstone['validation_contract_version'],
                    'policy_fingerprint' => $tombstone['policy_fingerprint'],
                    'validation_until' => $tombstone['validation_until'],
                )
            );
            if ( empty( $prepared['ok'] ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'fatal' => true, 'reason' => isset( $prepared['reason'] ) ? $prepared['reason'] : 'capacity_inconsistent' );
            }
            if ( (int) $tombstone['bytes'] > 0
                && ! empty( $tombstone['capacity_release_started'] )
                && (int) $prepared['matching_reservations'] !== 1
            ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
            }
            $record = $prepared['record'];
            if ( ! empty( $prepared['changed'] ) ) {
                $capacity_changed = true;
            }
            if ( empty( $manifest['tombstones'][ $upload_id ]['capacity_release_started'] ) ) {
                $manifest['tombstones'][ $upload_id ]['capacity_release_started'] = true;
                $manifest_changed = true;
            }
            $remote_targets[ $upload_id ] = $target['authority'];
        }
        if ( $capacity_changed && ! ManagedCapacityStore::write( $capacity['path'], $record ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_write_failed' );
        }
        if ( $manifest_changed && ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'manifest_write_failed' );
        }

        $manifest_fingerprint = self::worker_gc_manifest_fingerprint( $manifest );
        $capacity_fingerprint = self::worker_gc_capacity_fingerprint( $record, $manifest['batch_id'], array_keys( $selected ) );
        if ( $manifest_fingerprint === '' || $capacity_fingerprint === '' ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'manifest_invalid' );
        }
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );

        if ( ! empty( $remote_targets ) && ! is_callable( $remote_delete ) ) {
            return array( 'ok' => false, 'reason' => 'remote_delete_unavailable' );
        }
        foreach ( $remote_targets as $authority ) {
            $deleted = call_user_func( $remote_delete, $authority );
            if ( ! is_array( $deleted ) || empty( $deleted['ok'] ) || empty( $deleted['absent'] ) ) {
                return array( 'ok' => false, 'reason' => 'remote_delete_failed' );
            }
        }

        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_lock_failed' );
        }
        $locked = self::worker_gc_lock_context( $context, $uploads_dir, false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'aggregate_lock_failed' );
        }
        $current = self::read_worker_manifest( $locked['manifest_path'], $context['manifest_family'], $context['aggregate_id'], true, false );
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        if ( $current === null || $record === null ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => $record === null ? 'capacity_invalid' : 'manifest_invalid' );
        }
        if ( ! hash_equals( $manifest_fingerprint, self::worker_gc_manifest_fingerprint( $current ) )
            || ! hash_equals( $capacity_fingerprint, self::worker_gc_capacity_fingerprint( $record, $current['batch_id'], array_keys( $selected ) ) )
            || ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $record, $current, $context['manifest_family'] )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'remote_state_changed' );
        }

        $current_changed = false;
        foreach ( $selected as $upload_id => $target ) {
            if ( ! isset( $current['tombstones'][ $upload_id ] )
                || ! is_array( $current['tombstones'][ $upload_id ] )
                || self::worker_tombstone_authority( $upload_id, $current['tombstones'][ $upload_id ] ) !== $target['authority']
            ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'remote_state_changed' );
            }
            if ( empty( $current['tombstones'][ $upload_id ]['capacity_released'] ) ) {
                $current['tombstones'][ $upload_id ]['capacity_released'] = true;
                $current_changed = true;
            }
        }
        if ( $current_changed && ! self::write_json_atomic( $locked['manifest_path'], $current ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'manifest_write_failed' );
        }

        $released_bytes = 0;
        foreach ( $selected as $upload_id => $target ) {
            $settled = self::finish_worker_tombstone_capacity_release(
                $record,
                $current,
                $upload_id,
                $current['tombstones'][ $upload_id ],
                $now
            );
            $requires_item_release = $context['family'] !== self::SUBMISSIONS_DIR
                && empty( $target['repair_only'] )
                && (int) $target['bytes'] > 0;
            if ( empty( $settled['ok'] )
                || ( $requires_item_release && empty( $settled['changed'] ) )
            ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
            }
            $record = $settled['record'];
            $released_bytes += (int) $settled['released_bytes'];
        }
        if ( ! ManagedCapacityStore::write( $capacity['path'], $record ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_write_failed' );
        }
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        $out['released_bytes'] = $released_bytes;
        return $out;
    }

    private static function worker_gc_convert_expired_aggregate( $context, $manifest, $record, $now, $force = false ) {
        if ( ! is_array( $context ) || ! is_array( $manifest ) || ! is_array( $record ) ) {
            return null;
        }
        $expiry_states = isset( $context['expiry_states'] ) && is_array( $context['expiry_states'] )
            ? $context['expiry_states']
            : array();
        if ( ! isset( $manifest['state'], $manifest['delete_after'] )
            || ! in_array( $manifest['state'], $expiry_states, true )
            || ( ! $force && ( ! is_numeric( $manifest['delete_after'] ) || (int) $manifest['delete_after'] > (int) $now ) )
        ) {
            return array(
                'changed' => false,
                'bytes' => 0,
                'converted' => array(),
                'manifest' => $manifest,
            );
        }

        $deleted_at = $force ? (int) $now : (int) $manifest['delete_after'];
        $converted_bytes = 0;
        $converted = array();
        foreach ( $manifest['intents'] as $upload_id => $intent ) {
            $upload_id = (string) $upload_id;
            if ( ! ManagedCapacityStore::matches_intent_reservation(
                $record,
                self::reservation_id( $manifest['batch_id'], $upload_id ),
                $intent['intent_id'],
                $intent['object_key'],
                $manifest['batch_id'],
                $upload_id,
                $intent['reserved_bytes'],
                $intent['created_at'],
                $manifest['artifact_store'],
                $manifest['artifact_store_identity']
            ) ) {
                return null;
            }
            if ( $converted_bytes > PHP_INT_MAX - (int) $intent['reserved_bytes'] ) {
                return null;
            }
            $converted_bytes += (int) $intent['reserved_bytes'];
            $converted[ $upload_id ] = (int) $intent['reserved_bytes'];
            $manifest['tombstones'][ $upload_id ] = array(
                'deleted_at' => $deleted_at,
                'bytes' => (int) $intent['reserved_bytes'],
                'object_key' => $intent['object_key'],
                'object_version' => '-',
                'etag' => '-',
                'policy_fingerprint' => $intent['policy_fingerprint'],
                'storage_identity' => $intent['storage_identity'],
                'validation_contract_version' => $intent['validation_contract_version'],
                'validation_until' => (int) $intent['validation_until'],
                'capacity_release_started' => false,
                'capacity_released' => false,
            );
            unset( $manifest['intents'][ $upload_id ] );
        }

        $artifact_bytes = (int) $manifest['artifact_bytes'];
        foreach ( $manifest['items'] as $upload_id => $item ) {
            $upload_id = (string) $upload_id;
            if ( $converted_bytes > PHP_INT_MAX - (int) $item['bytes'] || $artifact_bytes < (int) $item['bytes'] ) {
                return null;
            }
            $converted_bytes += (int) $item['bytes'];
            $converted[ $upload_id ] = (int) $item['bytes'];
            $artifact_bytes -= (int) $item['bytes'];
            $manifest['tombstones'][ $upload_id ] = array(
                'deleted_at' => $deleted_at,
                'bytes' => (int) $item['bytes'],
                'object_key' => $item['object_key'],
                'object_version' => $item['object_version'],
                'etag' => $item['etag'],
                'policy_fingerprint' => $item['policy_fingerprint'],
                'storage_identity' => $item['storage_identity'],
                'validation_contract_version' => $item['validation_contract_version'],
                'validation_until' => (int) $item['validation_until'],
                'capacity_release_started' => false,
                'capacity_released' => false,
            );
            unset( $manifest['items'][ $upload_id ] );
        }
        $manifest['artifact_bytes'] = $artifact_bytes;
        if ( $force && ! empty( $converted ) ) {
            $manifest['state'] = 'operator_deleted';
            $manifest['delete_after'] = self::worker_operator_delete_after( $manifest, $now );
        }

        return array(
            'changed' => ! empty( $converted ),
            'bytes' => $converted_bytes,
            'converted' => $converted,
            'manifest' => $manifest,
        );
    }

    private static function worker_gc_convert_expired_intents( $manifest, $record, $now ) {
        if ( ! is_array( $manifest ) || ! is_array( $record ) ) {
            return null;
        }
        if ( ! isset( $manifest['state'] ) || $manifest['state'] !== 'open' ) {
            return array(
                'changed' => false,
                'bytes' => 0,
                'converted' => array(),
                'manifest' => $manifest,
            );
        }
        $converted_bytes = 0;
        $changed = false;
        $converted = array();
        foreach ( $manifest['intents'] as $upload_id => $intent ) {
            if ( (int) $now < (int) $intent['accept_until'] ) {
                continue;
            }
            if ( ! ManagedCapacityStore::matches_intent_reservation(
                $record,
                self::reservation_id( $manifest['batch_id'], (string) $upload_id ),
                $intent['intent_id'],
                $intent['object_key'],
                $manifest['batch_id'],
                (string) $upload_id,
                $intent['reserved_bytes'],
                $intent['created_at'],
                $manifest['artifact_store'],
                $manifest['artifact_store_identity']
            ) ) {
                return null;
            }
            if ( $converted_bytes > PHP_INT_MAX - (int) $intent['reserved_bytes'] ) {
                return null;
            }
            $converted_bytes += (int) $intent['reserved_bytes'];
            $converted[ (string) $upload_id ] = (int) $intent['reserved_bytes'];
            $manifest['tombstones'][ (string) $upload_id ] = array(
                'deleted_at' => (int) $now,
                'bytes' => (int) $intent['reserved_bytes'],
                'object_key' => $intent['object_key'],
                'object_version' => '-',
                'etag' => '-',
                'policy_fingerprint' => $intent['policy_fingerprint'],
                'storage_identity' => $intent['storage_identity'],
                'validation_contract_version' => $intent['validation_contract_version'],
                'validation_until' => (int) $intent['validation_until'],
                'capacity_release_started' => false,
                'capacity_released' => false,
            );
            unset( $manifest['intents'][ (string) $upload_id ] );
            $changed = true;
        }
        return array(
            'changed' => $changed,
            'bytes' => $converted_bytes,
            'converted' => $converted,
            'manifest' => $manifest,
        );
    }

    private static function worker_gc_unselected_converted_bytes( $converted, $selected ) {
        if ( ! is_array( $converted ) || ! isset( $converted['converted'] ) || ! is_array( $converted['converted'] ) ) {
            return 0;
        }
        $bytes = 0;
        foreach ( $converted['converted'] as $upload_id => $value ) {
            if ( isset( $selected[ $upload_id ] ) ) {
                continue;
            }
            if ( $bytes > PHP_INT_MAX - (int) $value ) {
                return PHP_INT_MAX;
            }
            $bytes += (int) $value;
        }
        return $bytes;
    }

    private static function worker_gc_delete_ready( $context, $manifest, $record, $now, $force = false ) {
        if ( ! is_array( $context ) || ! is_array( $manifest ) || ! is_array( $record ) ) {
            return null;
        }
        $expiry_states = isset( $context['expiry_states'] ) && is_array( $context['expiry_states'] )
            ? $context['expiry_states']
            : array();
        if ( ! isset( $manifest['state'], $manifest['delete_after'], $manifest['batch_id'] )
            || ! in_array( $manifest['state'], $expiry_states, true )
            || ( ! $force && ( ! is_numeric( $manifest['delete_after'] ) || (int) $manifest['delete_after'] > (int) $now ) )
        ) {
            return false;
        }
        if ( ! empty( $manifest['items'] ) || ! empty( $manifest['intents'] ) ) {
            return false;
        }
        foreach ( $manifest['tombstones'] as $tombstone ) {
            if ( empty( $tombstone['capacity_released'] ) ) {
                return false;
            }
        }
        $reservations = self::remote_batch_reservations( $record, $manifest );
        return $reservations === null ? null : empty( $reservations );
    }

    private static function worker_gc_targets( $manifest, $record, $now ) {
        $targets = array();
        foreach ( $manifest['tombstones'] as $upload_id => $tombstone ) {
            if ( self::worker_cancellation_tombstone_valid( $tombstone ) ) {
                continue;
            }
            $authority = self::worker_tombstone_authority( (string) $upload_id, $tombstone );
            if ( $authority === null ) {
                return null;
            }
            if ( ! empty( $tombstone['capacity_released'] ) ) {
                $settled = self::finish_worker_tombstone_capacity_release( $record, $manifest, (string) $upload_id, $tombstone, $now );
                if ( empty( $settled['ok'] ) ) {
                    return null;
                }
                if ( ! empty( $settled['changed'] ) ) {
                    $targets[ (string) $upload_id ] = array(
                        'authority' => $authority,
                        'bytes' => (int) $tombstone['bytes'],
                        'repair_only' => true,
                    );
                }
                continue;
            }
            if ( self::worker_tombstone_remote_eligible( $tombstone, $now ) ) {
                $targets[ (string) $upload_id ] = array(
                    'authority' => $authority,
                    'bytes' => (int) $tombstone['bytes'],
                    'repair_only' => false,
                );
            }
        }
        ksort( $targets, SORT_STRING );
        return $targets;
    }

    private static function finish_worker_tombstone_capacity_release( $record, $manifest, $upload_id, $tombstone, $now ) {
        if ( ! is_array( $record ) || ! isset( $record['reservations'] ) || ! is_array( $record['reservations'] ) ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $authority = self::worker_tombstone_capacity_authority( $manifest, $upload_id, $tombstone );
        if ( $authority === null ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $reservation_id = self::reservation_id( $manifest['batch_id'], $upload_id );
        if ( ! isset( $record['reservations'][ $reservation_id ] ) ) {
            return array(
                'ok' => true,
                'record' => $record,
                'released_bytes' => 0,
                'changed' => false,
            );
        }
        $reservation = $record['reservations'][ $reservation_id ];
        if ( ! ManagedCapacityStore::remote_reservation_matches( $reservation, $authority )
            || $record['total_bytes'] < $reservation['bytes']
            || $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] < $reservation['bytes']
        ) {
            return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
        }
        unset( $record['reservations'][ $reservation_id ] );
        $record['total_bytes'] -= $reservation['bytes'];
        $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] -= $reservation['bytes'];
        $record['updated_at'] = (int) $now;
        return array(
            'ok' => true,
            'record' => $record,
            'released_bytes' => $reservation['bytes'],
            'changed' => true,
        );
    }

    private static function finish_exact_remote_reservation_release( $record, $reservation_id, $authority, $now ) {
        if ( ! is_array( $record )
            || ! isset( $record['reservations'] )
            || ! is_array( $record['reservations'] )
            || ! is_string( $reservation_id )
            || $reservation_id === ''
            || ! isset( $record['reservations'][ $reservation_id ] )
            || ! is_array( $authority )
        ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $reservation = $record['reservations'][ $reservation_id ];
        if ( ! ManagedCapacityStore::remote_reservation_matches( $reservation, $authority )
            || $record['total_bytes'] < $reservation['bytes']
            || $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] < $reservation['bytes']
        ) {
            return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
        }
        unset( $record['reservations'][ $reservation_id ] );
        $record['total_bytes'] -= $reservation['bytes'];
        $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] -= $reservation['bytes'];
        $record['updated_at'] = (int) $now;
        return array(
            'ok' => true,
            'record' => $record,
            'released_bytes' => $reservation['bytes'],
            'changed' => true,
        );
    }

    private static function worker_tombstone_remote_eligible( $tombstone, $now ) {
        $safe_after = self::worker_tombstone_safe_after( $tombstone );
        return $safe_after !== null && (int) $now > $safe_after;
    }

    private static function worker_operator_delete_after( $manifest, $now ) {
        $delete_after = (int) $now;
        foreach ( $manifest['tombstones'] as $tombstone ) {
            if ( ! is_array( $tombstone ) || ! empty( $tombstone['capacity_released'] ) ) {
                continue;
            }
            $safe_after = self::worker_tombstone_safe_after( $tombstone );
            if ( $safe_after === null || $safe_after >= PHP_INT_MAX ) {
                return PHP_INT_MAX;
            }
            $delete_after = max( $delete_after, $safe_after + 1 );
        }
        return $delete_after;
    }

    private static function worker_tombstone_safe_after( $tombstone ) {
        if ( ! is_array( $tombstone ) || ! isset( $tombstone['deleted_at'], $tombstone['validation_until'] ) ) {
            return null;
        }
        $drain = Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' )
            + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' )
            + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
        $deleted_at = (int) $tombstone['deleted_at'];
        $deleted_drain_until = $deleted_at > PHP_INT_MAX - $drain ? PHP_INT_MAX : $deleted_at + $drain;
        $validation_drain = Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_WALL_SECONDS' )
            + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
        $validation_until = (int) $tombstone['validation_until'];
        $validation_drain_until = $validation_until > PHP_INT_MAX - $validation_drain
            ? PHP_INT_MAX
            : $validation_until + $validation_drain;
        return max( $validation_drain_until, $deleted_drain_until );
    }

    private static function worker_tombstone_authority( $upload_id, $tombstone ) {
        if ( ! self::valid_upload_id( $upload_id )
            || ! is_array( $tombstone )
            || ! isset(
                $tombstone['storage_identity'],
                $tombstone['validation_contract_version'],
                $tombstone['object_key'],
                $tombstone['object_version'],
                $tombstone['etag'],
                $tombstone['bytes'],
                $tombstone['policy_fingerprint']
            )
            || ! self::valid_worker_tombstone_object_pair( $tombstone['object_key'], $tombstone['object_version'], $tombstone['etag'] )
            || ! self::valid_artifact_store_identity( FormProtocol::UPLOAD_TRANSPORT_WORKER, $tombstone['storage_identity'] )
            || ! self::valid_validation_contract_version( $tombstone['validation_contract_version'] )
            || ! self::nonnegative_int( $tombstone['bytes'] )
            || ! is_string( $tombstone['policy_fingerprint'] )
            || preg_match( '/^[0-9a-f]{64}$/D', $tombstone['policy_fingerprint'] ) !== 1
        ) {
            return null;
        }
        return array(
            'upload_id' => $upload_id,
            'storage_identity' => $tombstone['storage_identity'],
            'expected_composition_fingerprint' => $tombstone['storage_identity'],
            'validation_contract_version' => $tombstone['validation_contract_version'],
            'object_key' => $tombstone['object_key'],
            'object_version' => $tombstone['object_version'],
            'etag' => $tombstone['etag'],
            'bytes' => (int) $tombstone['bytes'],
            'policy_fingerprint' => $tombstone['policy_fingerprint'],
        );
    }

    private static function worker_tombstone_capacity_authority( $manifest, $upload_id, $tombstone ) {
        $authority = self::worker_tombstone_authority( $upload_id, $tombstone );
        if ( $authority === null
            || ! is_array( $manifest )
            || ! isset( $manifest['batch_id'], $manifest['artifact_store_identity'] )
            || ! is_string( $manifest['batch_id'] )
            || ! is_string( $manifest['artifact_store_identity'] )
            || ! isset( $tombstone['validation_until'] )
            || ! is_int( $tombstone['validation_until'] )
        ) {
            return null;
        }
        $authority['batch_id'] = $manifest['batch_id'];
        $authority['artifact_store_identity'] = $manifest['artifact_store_identity'];
        $authority['validation_until'] = $tombstone['validation_until'];
        return $authority;
    }

    private static function worker_cancellation_tombstone_valid( $tombstone ) {
        return is_array( $tombstone )
            && isset( $tombstone['bytes'], $tombstone['object_key'], $tombstone['object_version'], $tombstone['etag'], $tombstone['capacity_release_started'], $tombstone['capacity_released'] )
            && $tombstone['bytes'] === 0
            && $tombstone['object_key'] === ''
            && $tombstone['object_version'] === '-'
            && $tombstone['etag'] === '-'
            && $tombstone['capacity_release_started'] === true
            && $tombstone['capacity_released'] === true;
    }

    private static function worker_gc_manifest_fingerprint( $manifest ) {
        $encoded = json_encode( $manifest, JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
    }

    private static function worker_gc_capacity_fingerprint( $record, $batch_id, $upload_ids ) {
        if ( ! is_array( $record ) || ! is_string( $batch_id ) || ! is_array( $upload_ids ) ) {
            return '';
        }
        $upload_map = array_fill_keys( $upload_ids, true );
        $reservations = array();
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            if ( ! is_array( $reservation )
                || ! isset( $reservation['batch_id'], $reservation['upload_id'] )
                || $reservation['batch_id'] !== $batch_id
                || ! isset( $upload_map[ $reservation['upload_id'] ] )
            ) {
                continue;
            }
            $reservations[ $reservation_id ] = $reservation;
        }
        ksort( $reservations, SORT_STRING );
        $encoded = json_encode(
            array(
                'version' => isset( $record['version'] ) ? $record['version'] : null,
                'total_bytes' => isset( $record['total_bytes'] ) ? $record['total_bytes'] : null,
                'reservations' => $reservations,
            ),
            JSON_UNESCAPED_SLASHES
        );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
    }

    private static function worker_gc_target_bytes( $targets ) {
        $bytes = 0;
        foreach ( $targets as $target ) {
            if ( $bytes > PHP_INT_MAX - (int) $target['bytes'] ) {
                return PHP_INT_MAX;
            }
            $bytes += (int) $target['bytes'];
        }
        return $bytes;
    }

    private static function release_remote_aggregate_capacity( $record, $manifest, $now ) {
        $parts = self::aggregate_byte_parts( $manifest );
        if ( $parts === null ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $tombstone_bytes = 0;
        $already_released_bytes = array();
        $attributed_bytes = array();
        foreach ( $manifest['items'] as $upload_id => $item ) {
            $attributed_bytes[ $upload_id ] = (int) $item['bytes'];
        }
        foreach ( $manifest['intents'] as $upload_id => $intent ) {
            $attributed_bytes[ $upload_id ] = (int) $intent['reserved_bytes'];
        }
        foreach ( $manifest['tombstones'] as $upload_id => $tombstone ) {
            if ( $tombstone_bytes > PHP_INT_MAX - (int) $tombstone['bytes'] ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $tombstone_bytes += (int) $tombstone['bytes'];
            $attributed_bytes[ $upload_id ] = (int) $tombstone['bytes'];
            if ( ! empty( $tombstone['capacity_released'] ) ) {
                $already_released_bytes[ $upload_id ] = (int) $tombstone['bytes'];
            }
        }
        $intent_bytes = self::active_artifact_bytes( $manifest ) - $manifest['artifact_bytes'];
        if ( $intent_bytes < 0
            || $parts['managed_bytes'] > PHP_INT_MAX - $intent_bytes
            || $parts['managed_bytes'] + $intent_bytes > PHP_INT_MAX - $tombstone_bytes
        ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        return ManagedCapacityStore::release_remote_aggregate_once(
            $record,
            $manifest['batch_id'],
            $parts['managed_bytes'] + $intent_bytes + $tombstone_bytes,
            $attributed_bytes,
            $already_released_bytes,
            $manifest['artifact_store_identity'],
            $now
        );
    }

    private static function remote_batch_reservations( $record, $manifest ) {
        if ( ! is_array( $record )
            || ! isset( $record['reservations'] )
            || ! is_array( $record['reservations'] )
            || ! is_array( $manifest )
            || ! isset( $manifest['batch_id'], $manifest['artifact_store_identity'] )
        ) {
            return null;
        }
        $reservations = array();
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            if ( ! is_array( $reservation ) || ! isset( $reservation['batch_id'] ) || $reservation['batch_id'] !== $manifest['batch_id'] ) {
                continue;
            }
            if ( ! isset( $reservation['artifact_store'], $reservation['artifact_store_identity'], $reservation['object_key'] )
                || $reservation['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
                || ! hash_equals( $manifest['artifact_store_identity'], $reservation['artifact_store_identity'] )
                || ! is_string( $reservation['object_key'] )
                || $reservation['object_key'] === ''
            ) {
                return null;
            }
            $reservations[ $reservation_id ] = $reservation;
        }
        ksort( $reservations, SORT_STRING );
        return $reservations;
    }

    private static function aggregate_paths( $root, $limit, $cursor, $repair_directories = true ) {
        $out = array();
        $limit = max( 1, (int) $limit );
        $shard_count = hexdec( str_repeat( 'f', Helpers::H2_LENGTH ) ) + 1;
        $shard_pattern = '/^[0-9a-f]{' . Helpers::H2_LENGTH . '}$/';
        $aggregate_pattern = self::aggregate_name_pattern( $root );
        $shard_page = PrivateDir::bounded_entries_result( $root, '', $shard_count, true, $shard_pattern );
        if ( empty( $shard_page['ok'] ) ) {
            return array( 'ok' => false, 'paths' => array(), 'cursor' => is_array( $cursor ) ? $cursor : array(), 'reason' => 'aggregate_enumeration_failed' );
        }
        $shards = $shard_page['entries'];
        if ( empty( $shards ) ) {
            return array( 'ok' => true, 'paths' => $out, 'cursor' => array(), 'reason' => '' );
        }

        $cursor = is_array( $cursor ) ? $cursor : array();
        $cursor_shard = isset( $cursor['shard'] ) && is_string( $cursor['shard'] ) ? $cursor['shard'] : '';
        $cursor_aggregate = isset( $cursor['aggregate'] ) && is_string( $cursor['aggregate'] ) ? $cursor['aggregate'] : '';
        $last = array();
        $review_directories = basename( rtrim( $root, '/\\' ) ) === self::SUBMISSIONS_DIR;

        foreach ( $shards as $shard ) {
            if ( $cursor_shard !== '' && strcmp( $shard, $cursor_shard ) < 0 ) {
                continue;
            }
            $shard_path = $root . '/' . $shard;
            $valid_shard = $repair_directories
                ? ( $review_directories
                    ? PrivateDir::ensure_existing_review_directory( $shard_path )
                    : PrivateDir::ensure_existing_private_directory( $shard_path ) )
                : ( ! is_link( $shard_path ) && is_dir( $shard_path ) );
            if ( ! $valid_shard ) {
                return array( 'ok' => false, 'paths' => array(), 'cursor' => $cursor, 'reason' => 'aggregate_enumeration_failed' );
            }
            $after = $shard === $cursor_shard ? $cursor_aggregate : '';
            while ( count( $out ) < $limit ) {
                $page_limit = $limit - count( $out );
                $entry_page = PrivateDir::bounded_entries_result( $shard_path, $after, $page_limit, true, $aggregate_pattern );
                if ( empty( $entry_page['ok'] ) ) {
                    return array( 'ok' => false, 'paths' => array(), 'cursor' => $cursor, 'reason' => 'aggregate_enumeration_failed' );
                }
                $entries = $entry_page['entries'];
                if ( empty( $entries ) ) {
                    break;
                }

                foreach ( $entries as $aggregate ) {
                    $after = $aggregate;
                    $path = $shard_path . '/' . $aggregate;
                    // A directory can disappear between enumeration and use.
                    // Keep paging from the examined name so skipped entries
                    // cannot make this bounded scan look complete early.
                    if ( ! is_dir( $path ) && ! is_link( $path ) ) {
                        continue;
                    }
                    $valid_aggregate = $repair_directories
                        ? ( $review_directories
                            ? PrivateDir::ensure_existing_review_directory( $path )
                            : PrivateDir::ensure_existing_private_directory( $path ) )
                        : ( ! is_link( $path ) && is_dir( $path ) );
                    if ( ! $valid_aggregate ) {
                        return array( 'ok' => false, 'paths' => array(), 'cursor' => $cursor, 'reason' => 'aggregate_enumeration_failed' );
                    }
                    $out[] = $path;
                    $last = array( 'shard' => $shard, 'aggregate' => $aggregate );
                    if ( count( $out ) >= $limit ) {
                        return array( 'ok' => true, 'paths' => $out, 'cursor' => $last, 'reason' => '' );
                    }
                }

                if ( count( $entries ) < $page_limit ) {
                    break;
                }
            }
        }

        return array( 'ok' => true, 'paths' => $out, 'cursor' => array(), 'reason' => '' );
    }

    private static function aggregate_name_pattern( $root ) {
        $name = is_string( $root ) ? basename( rtrim( $root, '/\\' ) ) : '';
        if ( $name === self::STAGED_DIR ) {
            return FormProtocol::upload_batch_id_pattern();
        }
        if ( $name === self::SUBMISSIONS_DIR ) {
            return FormProtocol::managed_id_pattern();
        }
        return '';
    }

    private static function worker_remote_purge_safe_after( $private_dir, $now ) {
        $drain = Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' )
            + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' )
            + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' )
            + 1;
        $validation_drain = Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_WALL_SECONDS' )
            + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
        $max_validation_until = (int) $now;
        foreach ( array( self::STAGED_DIR => 'staged', self::SUBMISSIONS_DIR => 'submission' ) as $root_name => $manifest_family ) {
            $root = rtrim( $private_dir, '/\\' ) . '/' . $root_name;
            if ( is_link( $root ) || ( file_exists( $root ) && ! is_dir( $root ) ) ) {
                return null;
            }
            if ( ! is_dir( $root ) ) {
                continue;
            }
            $cursor = array();
            do {
                $page = self::aggregate_paths( $root, Anchors::get( 'MANAGED_REMOTE_PURGE_AGGREGATE_PAGE_SIZE' ), $cursor, false );
                if ( empty( $page['ok'] ) ) {
                    return null;
                }
                foreach ( $page['paths'] as $path ) {
                    $manifest_path = rtrim( $path, '/\\' ) . '/' . self::MANIFEST_FILENAME;
                    $manifest_version = self::worker_manifest_file_version( $manifest_path );
                    if ( $manifest_version === null ) {
                        continue;
                    }
                    if ( $manifest_version === false ) {
                        return null;
                    }
                    $manifest = self::read_worker_manifest( $manifest_path, $manifest_family, basename( rtrim( $path, '/\\' ) ), true, false );
                    if ( $manifest === null ) {
                        return null;
                    }
                    foreach ( array( $manifest['intents'], $manifest['items'], $manifest['tombstones'] ) as $records ) {
                        foreach ( $records as $record ) {
                            if ( isset( $record['validation_until'] ) && is_int( $record['validation_until'] ) ) {
                                $max_validation_until = max( $max_validation_until, $record['validation_until'] );
                            }
                        }
                    }
                }
                $cursor = isset( $page['cursor'] ) && is_array( $page['cursor'] ) ? $page['cursor'] : array();
            } while ( ! empty( $cursor ) );
        }

        $capacity = ManagedCapacityStore::read(
            rtrim( $private_dir, '/\\' ) . '/' . self::CAPACITY_FILENAME,
            self::CAPACITY_VERSION,
            $now
        );
        if ( ! is_array( $capacity ) ) {
            return null;
        }
        if ( ! self::worker_capacity_globally_consistent( $private_dir, $capacity ) ) {
            return null;
        }
        foreach ( $capacity['reservations'] as $reservation ) {
            if ( $reservation['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
                || ! isset( $reservation['validation_until'] )
            ) {
                continue;
            }
            $authority = self::worker_remote_reservation_authority( $reservation );
            if ( $authority === null ) {
                return null;
            }
            $max_validation_until = max( $max_validation_until, (int) $reservation['validation_until'] );
        }

        $upload_safe_after = $max_validation_until > PHP_INT_MAX - $drain
            ? PHP_INT_MAX
            : $max_validation_until + $drain;
        $validation_safe_after = $max_validation_until > PHP_INT_MAX - $validation_drain
            ? PHP_INT_MAX
            : $max_validation_until + $validation_drain;
        return max( $upload_safe_after, $validation_safe_after );
    }

    private static function worker_manifest_file_version( $manifest_path ) {
        if ( ! is_file( $manifest_path ) || is_link( $manifest_path ) ) {
            return null;
        }
        $raw = @file_get_contents( $manifest_path );
        $manifest = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
        if ( ! is_array( $manifest ) || ! isset( $manifest['version'] ) || ! is_int( $manifest['version'] ) ) {
            return false;
        }
        return $manifest['version'] === self::WORKER_MANIFEST_VERSION ? self::WORKER_MANIFEST_VERSION : null;
    }

    private static function unsupported_worker_manifest_file_version( $manifest_path ) {
        if ( ! is_file( $manifest_path ) || is_link( $manifest_path ) ) {
            return false;
        }
        $raw = @file_get_contents( $manifest_path );
        $manifest = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
        if ( ! is_array( $manifest )
            || ! isset( $manifest['version'], $manifest['artifact_store'] )
            || ! is_int( $manifest['version'] )
            || ! is_string( $manifest['artifact_store'] )
        ) {
            return null;
        }
        return $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER
            && $manifest['version'] !== self::WORKER_MANIFEST_VERSION;
    }

    private static function purge_worker_remote_family( $family, $private_dir, $cursor, $remote_delete, $now, $lifecycle ) {
        $root = rtrim( $private_dir, '/\\' ) . '/' . $family;
        if ( is_link( $root ) || ( file_exists( $root ) && ! is_dir( $root ) ) ) {
            return array( 'ok' => false, 'reason' => 'aggregate_enumeration_failed', 'cursor' => $cursor );
        }
        if ( ! is_dir( $root ) ) {
            return array( 'ok' => true, 'complete' => true, 'cursor' => array() );
        }

        $page = self::aggregate_paths( $root, Anchors::get( 'MANAGED_REMOTE_PURGE_AGGREGATE_PAGE_SIZE' ), $cursor, false );
        if ( empty( $page['ok'] ) ) {
            return array( 'ok' => false, 'reason' => 'aggregate_enumeration_failed', 'cursor' => $cursor );
        }
        foreach ( $page['paths'] as $path ) {
            $one = self::purge_worker_remote_aggregate( $family, $path, $remote_delete, $now, $lifecycle );
            if ( empty( $one['ok'] ) ) {
                return array( 'ok' => false, 'reason' => $one['reason'], 'cursor' => $cursor );
            }
            $cursor = array( 'shard' => basename( dirname( $path ) ), 'aggregate' => basename( $path ) );
        }
        if ( ! empty( $page['cursor'] ) ) {
            return array( 'ok' => true, 'complete' => false, 'cursor' => $page['cursor'] );
        }

        @rmdir( $root );
        return array( 'ok' => true, 'complete' => true, 'cursor' => array() );
    }

    private static function purge_worker_remote_aggregate( $family, $path, $remote_delete, $now, $lifecycle ) {
        $context = self::worker_gc_context( $family, $path );
        if ( $context === null ) {
            return array( 'ok' => false, 'reason' => 'aggregate_layout_invalid' );
        }
        $manifest_version = self::worker_manifest_file_version( $context['manifest_path'] );
        if ( $manifest_version === null ) {
            return array( 'ok' => true );
        }
        if ( $manifest_version === false ) {
            return array( 'ok' => false, 'reason' => 'manifest_invalid' );
        }

        $locked = self::worker_purge_lock_context( $context, dirname( $lifecycle->private_dir() ), false );
        if ( empty( $locked['ok'] ) ) {
            return ! empty( $locked['gone'] )
                ? array( 'ok' => true )
                : array( 'ok' => false, 'reason' => isset( $locked['reason'] ) ? $locked['reason'] : 'aggregate_lock_failed' );
        }
        $manifest = self::read_worker_manifest( $locked['manifest_path'], $context['manifest_family'], $context['aggregate_id'], true, false );
        if ( $manifest === null ) {
            self::release_lock( $locked['lock'] );
            return array( 'ok' => false, 'reason' => 'manifest_invalid' );
        }
        if ( $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
            self::release_lock( $locked['lock'] );
            return array( 'ok' => true );
        }
        $encoded = json_encode( $manifest, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) ) {
            self::release_lock( $locked['lock'] );
            return array( 'ok' => false, 'reason' => 'manifest_invalid' );
        }
        $fingerprint = hash( 'sha256', $encoded );
        self::release_lock( $locked['lock'] );

        $capacity = self::lock_capacity( dirname( $lifecycle->private_dir() ), $lifecycle, true );
        if ( empty( $capacity['ok'] ) ) {
            return array( 'ok' => false, 'reason' => 'capacity_lock_failed' );
        }
        $locked = self::worker_purge_lock_context( $context, dirname( $lifecycle->private_dir() ), false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            return ! empty( $locked['gone'] )
                ? array( 'ok' => true )
                : array( 'ok' => false, 'reason' => isset( $locked['reason'] ) ? $locked['reason'] : 'aggregate_lock_failed' );
        }
        $current = self::read_worker_manifest( $locked['manifest_path'], $context['manifest_family'], $context['aggregate_id'], true, false );
        $current_encoded = is_array( $current ) ? json_encode( $current, JSON_UNESCAPED_SLASHES ) : false;
        $capacity_record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        $batch_reservations = is_array( $capacity_record ) && is_array( $current )
            ? self::remote_batch_reservations( $capacity_record, $current )
            : null;
        if ( ! is_string( $current_encoded )
            || ! hash_equals( $fingerprint, hash( 'sha256', $current_encoded ) )
            || $batch_reservations === null
            || ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $capacity_record, $current, $context['manifest_family'] )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'remote_state_changed' );
        }
        $objects = self::worker_remote_deletion_targets( $current, $batch_reservations );
        if ( $objects === null ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'worker_remote_purge_authority_invalid' );
        }
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );

        foreach ( $objects as $authority ) {
            $deleted = call_user_func( $remote_delete, $authority );
            if ( ! is_array( $deleted ) || empty( $deleted['ok'] ) || empty( $deleted['absent'] ) ) {
                return array( 'ok' => false, 'reason' => 'remote_delete_failed' );
            }
        }

        $capacity = self::lock_capacity( dirname( $lifecycle->private_dir() ), $lifecycle, true );
        if ( empty( $capacity['ok'] ) ) {
            return array( 'ok' => false, 'reason' => 'capacity_lock_failed' );
        }
        $locked = self::worker_purge_lock_context( $context, dirname( $lifecycle->private_dir() ), false );
        if ( empty( $locked['ok'] ) ) {
            self::release_lock( $capacity['lock'] );
            return ! empty( $locked['gone'] )
                ? array( 'ok' => true )
                : array( 'ok' => false, 'reason' => isset( $locked['reason'] ) ? $locked['reason'] : 'aggregate_lock_failed' );
        }
        $current = self::read_worker_manifest( $locked['manifest_path'], $context['manifest_family'], $context['aggregate_id'], true, false );
        $current_encoded = is_array( $current ) ? json_encode( $current, JSON_UNESCAPED_SLASHES ) : false;
        if ( ! is_string( $current_encoded ) || ! hash_equals( $fingerprint, hash( 'sha256', $current_encoded ) ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'remote_state_changed' );
        }
        $capacity_record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        $current_reservations = is_array( $capacity_record ) && is_array( $current )
            ? self::remote_batch_reservations( $capacity_record, $current )
            : null;
        if ( $current_reservations === null
            || $current_reservations !== $batch_reservations
            || ! self::worker_capacity_consistent_for_manifest( $capacity['private_dir'], $capacity_record, $current, $context['manifest_family'] )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'remote_state_changed' );
        }
        $released = is_array( $capacity_record )
            ? self::release_remote_aggregate_capacity( $capacity_record, $current, $now )
            : array( 'ok' => false );
        if ( empty( $released['ok'] )
            || ! ManagedCapacityStore::write( $capacity['path'], $released['record'] )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'capacity_write_failed' );
        }
        if ( ! self::delete_locked_aggregate( $context['path'], $locked['lock'] ) ) {
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'aggregate_delete_failed' );
        }
        $finished = ManagedCapacityStore::finish_remote_aggregate_release(
            $released['record'],
            $current['batch_id'],
            $current['artifact_store_identity'],
            $now
        );
        if ( ! is_array( $finished ) ) {
            self::release_lock( $capacity['lock'] );
            self::cleanup_deleted_worker_aggregate_context( $context );
            return array( 'ok' => false, 'reason' => 'capacity_inconsistent' );
        }
        if ( ! ManagedCapacityStore::write( $capacity['path'], $finished ) ) {
            self::release_lock( $capacity['lock'] );
            self::cleanup_deleted_worker_aggregate_context( $context );
            return array( 'ok' => false, 'reason' => 'capacity_write_failed' );
        }
        self::release_lock( $capacity['lock'] );
        self::cleanup_deleted_worker_aggregate_context( $context );
        return array( 'ok' => true );
    }

    private static function worker_remote_deletion_targets( $manifest, $batch_reservations ) {
        $objects = array();
        foreach ( array( $manifest['items'], $manifest['intents'], $manifest['tombstones'] ) as $records ) {
            foreach ( $records as $upload_id => $artifact ) {
                if ( empty( $artifact['object_key'] ) || ! empty( $artifact['capacity_released'] ) ) {
                    continue;
                }
                $authority = self::worker_remote_artifact_authority( (string) $upload_id, $artifact );
                if ( $authority === null ) {
                    return null;
                }
                $objects[ $authority['object_key'] . "\0" . $authority['object_version'] ] = $authority;
            }
        }
        foreach ( $batch_reservations as $reservation ) {
            $object_owned = false;
            foreach ( $objects as $authority ) {
                if ( hash_equals( $authority['object_key'], $reservation['object_key'] ) ) {
                    $object_owned = true;
                    break;
                }
            }
            if ( ! $object_owned ) {
                $authority = self::worker_remote_reservation_authority( $reservation );
                if ( $authority === null ) {
                    return null;
                }
                $objects[ $authority['object_key'] . "\0-" ] = $authority;
            }
        }
        return $objects;
    }

    private static function worker_remote_artifact_authority( $upload_id, $artifact ) {
        if ( ! is_array( $artifact ) ) {
            return null;
        }
        if ( ! isset( $artifact['object_version'], $artifact['etag'] ) ) {
            $artifact['object_version'] = '-';
            $artifact['etag'] = '-';
        }
        return self::worker_tombstone_authority( $upload_id, $artifact );
    }

    private static function worker_remote_reservation_authority( $reservation ) {
        if ( ! is_array( $reservation )
            || ! isset(
                $reservation['upload_id'],
                $reservation['artifact_store_identity'],
                $reservation['validation_contract_version'],
                $reservation['object_key'],
                $reservation['bytes'],
                $reservation['policy_fingerprint'],
                $reservation['validation_until']
            )
            || $reservation['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
            || ! self::valid_upload_id( $reservation['upload_id'] )
            || ! self::valid_artifact_store_identity( FormProtocol::UPLOAD_TRANSPORT_WORKER, $reservation['artifact_store_identity'] )
            || ! self::valid_validation_contract_version( $reservation['validation_contract_version'] )
            || ! self::valid_worker_tombstone_object_pair( $reservation['object_key'], '-', '-' )
            || ! self::nonnegative_int( $reservation['bytes'] )
            || (int) $reservation['bytes'] < 1
            || ! is_string( $reservation['policy_fingerprint'] )
            || preg_match( '/^[0-9a-f]{64}$/D', $reservation['policy_fingerprint'] ) !== 1
        ) {
            return null;
        }
        return array(
            'upload_id' => $reservation['upload_id'],
            'storage_identity' => $reservation['artifact_store_identity'],
            'expected_composition_fingerprint' => $reservation['artifact_store_identity'],
            'validation_contract_version' => $reservation['validation_contract_version'],
            'object_key' => $reservation['object_key'],
            'object_version' => '-',
            'etag' => '-',
            'bytes' => (int) $reservation['bytes'],
            'policy_fingerprint' => $reservation['policy_fingerprint'],
            'validation_until' => (int) $reservation['validation_until'],
        );
    }

    private static function purge_worker_remote_reservation_page( $remote_delete, $now, $lifecycle ) {
        $page_size = Anchors::get( 'MANAGED_REMOTE_PURGE_AGGREGATE_PAGE_SIZE' );
        for ( $processed = 0; $processed < $page_size; $processed++ ) {
            $capacity = self::lock_capacity( dirname( $lifecycle->private_dir() ), $lifecycle, true );
            if ( empty( $capacity['ok'] ) ) {
                return array( 'ok' => false, 'reason' => 'capacity_lock_failed' );
            }
            $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
            if ( ! is_array( $record ) ) {
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            if ( ! self::worker_capacity_globally_consistent( $capacity['private_dir'], $record ) ) {
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }

            $candidate = null;
            foreach ( $record['reservations'] as $reservation_id => $reservation ) {
                $authority = self::worker_remote_reservation_authority( $reservation );
                if ( $authority !== null ) {
                    $candidate = array( 'id' => $reservation_id, 'reservation' => $reservation, 'authority' => $authority );
                    break;
                }
            }
            if ( ! is_array( $candidate ) ) {
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => true, 'complete' => true );
            }

            if ( empty( $candidate['reservation']['cleanup_started'] ) ) {
                $started = ManagedCapacityStore::begin_remote_reservation_cleanup(
                    $record,
                    $candidate['id'],
                    $candidate['reservation']['artifact_store_identity'],
                    $now
                );
                if ( ! is_array( $started ) || ! ManagedCapacityStore::write( $capacity['path'], $started ) ) {
                    self::release_lock( $capacity['lock'] );
                    return array( 'ok' => false, 'reason' => 'capacity_write_failed' );
                }
            }
            self::release_lock( $capacity['lock'] );

            $deleted = call_user_func( $remote_delete, $candidate['authority'] );
            if ( ! is_array( $deleted ) || empty( $deleted['ok'] ) || empty( $deleted['absent'] ) ) {
                return array( 'ok' => false, 'reason' => 'remote_delete_failed' );
            }

            $capacity = self::lock_capacity( dirname( $lifecycle->private_dir() ), $lifecycle, true );
            if ( empty( $capacity['ok'] ) ) {
                return array( 'ok' => false, 'reason' => 'capacity_lock_failed' );
            }
            $current = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
            $current_reservation = is_array( $current ) && isset( $current['reservations'][ $candidate['id'] ] )
                ? $current['reservations'][ $candidate['id'] ]
                : null;
            if ( ! is_array( $current_reservation )
                || empty( $current_reservation['cleanup_started'] )
                || ! ManagedCapacityStore::remote_reservation_matches( $current_reservation, array(
                    'batch_id' => $candidate['reservation']['batch_id'],
                    'upload_id' => $candidate['reservation']['upload_id'],
                    'bytes' => $candidate['reservation']['bytes'],
                    'artifact_store_identity' => $candidate['reservation']['artifact_store_identity'],
                    'object_key' => $candidate['reservation']['object_key'],
                    'validation_contract_version' => $candidate['reservation']['validation_contract_version'],
                    'policy_fingerprint' => $candidate['reservation']['policy_fingerprint'],
                    'validation_until' => $candidate['reservation']['validation_until'],
                ) )
            ) {
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'remote_state_changed' );
            }
            if ( ! self::worker_capacity_globally_consistent( $capacity['private_dir'], $current ) ) {
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $finished = ManagedCapacityStore::finish_remote_reservation_cleanup(
                $current,
                $candidate['id'],
                $candidate['reservation']['artifact_store_identity'],
                $now
            );
            if ( ! is_array( $finished ) || ! ManagedCapacityStore::write( $capacity['path'], $finished ) ) {
                self::release_lock( $capacity['lock'] );
                return array( 'ok' => false, 'reason' => 'capacity_write_failed' );
            }
            self::release_lock( $capacity['lock'] );
        }

        return array( 'ok' => true, 'complete' => false );
    }

    private static function read_remote_purge_record( $path ) {
        if ( ! PrivateDir::ensure_existing_private_file( $path ) ) {
            return null;
        }
        $raw = @file_get_contents( $path );
        $record = is_string( $raw ) && strlen( $raw ) <= 4096 ? json_decode( $raw, true ) : null;
        if ( ! is_array( $record ) || array_keys( $record ) !== array(
            'version', 'phase', 'started_at', 'safe_after', 'composition_fingerprint',
            'next_family', 'cursor', 'updated_at', 'last_failure_class',
        ) ) {
            return null;
        }
        $cursor = $record['cursor'];
        $valid_cursor = $cursor === array()
            || ( array_keys( $cursor ) === array( 'shard', 'aggregate' )
                && is_string( $cursor['shard'] )
                && preg_match( '/^[0-9a-f]{' . Helpers::H2_LENGTH . '}$/D', $cursor['shard'] ) === 1
                && is_string( $cursor['aggregate'] )
                && strlen( $cursor['aggregate'] ) <= Anchors::get( 'MANAGED_ID_MAX_CHARS' )
                && preg_match( '/^[A-Za-z0-9_-]+$/D', $cursor['aggregate'] ) === 1 );
        return (int) $record['version'] === self::REMOTE_PURGE_VERSION
            && in_array( $record['phase'], array( 'draining', 'purging' ), true )
            && is_int( $record['started_at'] )
            && is_int( $record['safe_after'] )
            && $record['safe_after'] >= $record['started_at']
            && self::valid_composition_fingerprint( $record['composition_fingerprint'] )
            && in_array( $record['next_family'], array( self::STAGED_DIR, self::SUBMISSIONS_DIR, self::REMOTE_PURGE_RESERVATIONS, 'done' ), true )
            && $valid_cursor
            && is_int( $record['updated_at'] )
            && in_array( $record['last_failure_class'], array( '', 'provider_failure', 'local_state_failure' ), true )
                ? $record
                : null;
    }

    private static function valid_composition_fingerprint( $value ) {
        return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) === 1;
    }

    private static function aggregate_byte_parts( $manifest ) {
        $artifact = 0;
        foreach ( $manifest['items'] as $item ) {
            if ( ! is_array( $item )
                || ! isset( $item['bytes'] )
                || ! is_int( $item['bytes'] )
                || $item['bytes'] < 0
                || $artifact > PHP_INT_MAX - $item['bytes']
            ) {
                return null;
            }
            $artifact += $item['bytes'];
        }
        if ( $artifact !== (int) $manifest['artifact_bytes'] ) {
            return null;
        }
        return array( 'artifact_bytes' => $artifact, 'managed_bytes' => $artifact );
    }

    private static function initializable_partial_batch( $aggregate ) {
        if ( is_link( $aggregate ) || ! is_dir( $aggregate ) ) {
            return false;
        }
        $entries = @scandir( $aggregate );
        if ( ! is_array( $entries ) ) {
            return false;
        }
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $entry_path = $aggregate . '/' . $entry;
            if ( $entry === self::LOCK_FILENAME ) {
                if ( is_link( $entry_path ) || ! is_file( $entry_path ) ) {
                    return false;
                }
                continue;
            }
            if ( self::is_json_temp_filename( $entry, self::MANIFEST_FILENAME ) ) {
                if ( is_link( $entry_path ) || ! is_file( $entry_path ) ) {
                    return false;
                }
                continue;
            }
            return false;
        }
        return true;
    }

    private static function remove_initial_manifest_temps( $aggregate ) {
        $entries = @scandir( $aggregate );
        if ( ! is_array( $entries ) ) {
            return false;
        }
        foreach ( $entries as $entry ) {
            if ( ! self::is_json_temp_filename( $entry, self::MANIFEST_FILENAME ) ) {
                continue;
            }
            $entry_path = $aggregate . '/' . $entry;
            // A stopped initial atomic write is safe to discard only under the aggregate lock.
            if ( is_link( $entry_path ) || ! is_file( $entry_path ) || ! @unlink( $entry_path ) ) {
                return false;
            }
        }
        return true;
    }

    private static function is_json_temp_filename( $entry, $filename ) {
        if ( ! is_string( $entry ) || ! is_string( $filename ) || $filename === '' ) {
            return false;
        }
        $prefix = '.' . $filename . '.';
        $tail = '.tmp';
        if ( strncmp( $entry, $prefix, strlen( $prefix ) ) !== 0 || substr( $entry, -strlen( $tail ) ) !== $tail ) {
            return false;
        }
        $entropy = substr( $entry, strlen( $prefix ), -strlen( $tail ) );
        return strlen( $entropy ) === self::JSON_TEMP_ENTROPY_BYTES * 2
            && preg_match( '/^[a-f0-9]+$/D', $entropy ) === 1;
    }

    private static function partial_batch_observed_at( $aggregate ) {
        $aggregate = rtrim( $aggregate, '/\\' );
        $batch_id = basename( $aggregate );
        if ( is_link( $aggregate )
            || is_link( dirname( $aggregate ) )
            || preg_match( FormProtocol::upload_batch_id_pattern(), $batch_id ) !== 1
            || basename( dirname( $aggregate ) ) !== Helpers::h2( $batch_id )
        ) {
            return null;
        }

        $observed_at = @filemtime( $aggregate );
        return is_int( $observed_at ) && $observed_at >= 0 ? $observed_at : null;
    }

    private static function delete_locked_aggregate( $path, $lock, $manifest_required = true ) {
        $path = rtrim( $path, '/\\' );
        if ( is_link( $path ) || is_link( dirname( $path ) ) ) {
            self::release_lock( $lock );
            return false;
        }
        $entries = @scandir( $path );
        if ( ! is_array( $entries ) ) {
            self::release_lock( $lock );
            return false;
        }
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' || $entry === self::LOCK_FILENAME || $entry === self::MANIFEST_FILENAME ) {
                continue;
            }
            if ( ! self::remove_tree( $path . '/' . $entry ) || file_exists( $path . '/' . $entry ) || is_link( $path . '/' . $entry ) ) {
                self::release_lock( $lock );
                return false;
            }
        }
        $manifest_path = $path . '/' . self::MANIFEST_FILENAME;
        if ( is_file( $manifest_path ) ) {
            if ( ! @unlink( $manifest_path ) ) {
                self::release_lock( $lock );
                return false;
            }
        } elseif ( $manifest_required || file_exists( $manifest_path ) || is_link( $manifest_path ) ) {
            self::release_lock( $lock );
            return false;
        }
        self::release_lock( $lock );
        if ( is_file( $path . '/' . self::LOCK_FILENAME ) && ! @unlink( $path . '/' . self::LOCK_FILENAME ) ) {
            return false;
        }
        return @rmdir( $path );
    }

    private static function settle_committed_capacity( $record, $path, $batch_id, $upload_id, $object_key, $managed_bytes, $now ) {
        if ( ! is_array( $record ) ) {
            return false;
        }
        $reservation_id = hash( 'sha256', $batch_id . "\0" . $upload_id );
        $finished = ManagedCapacityStore::finish_committed(
            $record,
            $reservation_id,
            $batch_id,
            $upload_id,
            $object_key,
            $managed_bytes,
            $now
        );
        return is_array( $finished )
            && ( $finished === $record || ManagedCapacityStore::write( $path, $finished ) );
    }

    private static function settle_worker_committed_capacity( $record, $path, $private_dir, $upload_id, $manifest, $item, $now, $manifest_family ) {
        if ( ! is_array( $record ) || ! is_array( $manifest ) || ! is_array( $item ) ) {
            return false;
        }
        $reservation_id = self::reservation_id( $manifest['batch_id'], $upload_id );
        $authority = self::worker_committed_item_capacity_authority( $manifest, $upload_id, $item );
        if ( $authority === null ) {
            return false;
        }
        if ( ! self::worker_capacity_consistent_for_manifest( $private_dir, $record, $manifest, $manifest_family ) ) {
            return false;
        }
        if ( isset( $record['reservations'][ $reservation_id ] ) ) {
            $finished = ManagedCapacityStore::finish_remote_committed(
                $record,
                $reservation_id,
                $authority,
                $item['bytes'],
                $now
            );
            return is_array( $finished )
                && ( $finished === $record || ManagedCapacityStore::write( $path, $finished ) );
        }
        return true;
    }

    private static function worker_intent_capacity_authority( $manifest, $upload_id, $intent ) {
        return self::worker_capacity_authority_from_record( $manifest, $upload_id, $intent, 'reserved_bytes' );
    }

    private static function worker_committed_item_capacity_authority( $manifest, $upload_id, $item ) {
        return self::worker_capacity_authority_from_record( $manifest, $upload_id, $item, 'bytes' );
    }

    private static function worker_capacity_authority_from_record( $manifest, $upload_id, $record, $bytes_field ) {
        if ( ! is_array( $manifest )
            || ! is_array( $record )
            || ! is_string( $bytes_field )
            || ! isset( $manifest['batch_id'], $manifest['artifact_store_identity'] )
            || ! isset( $record[ $bytes_field ], $record['object_key'] )
        ) {
            return null;
        }
        $authority = array(
            'batch_id' => $manifest['batch_id'],
            'upload_id' => $upload_id,
            'bytes' => $record[ $bytes_field ],
            'artifact_store_identity' => $manifest['artifact_store_identity'],
            'object_key' => $record['object_key'],
        );
        foreach ( array( 'validation_contract_version', 'policy_fingerprint', 'validation_until' ) as $field ) {
            if ( isset( $record[ $field ] ) ) {
                $authority[ $field ] = $record[ $field ];
            }
        }
        return $authority;
    }

    private static function reserve_capacity( $record, $reservation_id, $intent_id, $object_key, $batch_id, $upload_id, $bytes, $transient_bytes, $artifact_store_identity, $private_dir, $options, $now ) {
        $artifact_store = isset( $options['artifact_store'] ) ? $options['artifact_store'] : FormProtocol::UPLOAD_TRANSPORT_LOCAL;
        $materialized_transient_bytes = isset( $options['materialized_transient_bytes'] ) && is_int( $options['materialized_transient_bytes'] )
            ? $options['materialized_transient_bytes']
            : 0;
        $free_bytes = $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL ? self::free_bytes( $private_dir, $options ) : null;
        $reserved = ManagedCapacityStore::reserve(
            $record,
            $reservation_id,
            $intent_id,
            $object_key,
            $batch_id,
            $upload_id,
            $bytes,
            $free_bytes,
            Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
            Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ),
            $transient_bytes,
            $now,
            $artifact_store,
            $artifact_store_identity,
            $materialized_transient_bytes,
            isset( $options['worker_cleanup'] ) ? $options['worker_cleanup'] : array()
        );
        return ! empty( $reserved['ok'] )
            ? self::success( array( 'record' => $reserved['record'] ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', isset( $reserved['reason'] ) ? $reserved['reason'] : 'capacity_invalid' );
    }

    private static function derive_intent_id( $batch_id, $upload_id, $ordinal, $display_name, $bytes, $mime, $policy_fingerprint ) {
        $encoded = self::encode_parts(
            array(
                'eforms-upload-intent',
                '1',
                (string) $batch_id,
                (string) $upload_id,
                (string) $ordinal,
                (string) $display_name,
                (string) $bytes,
                (string) $mime,
                (string) $policy_fingerprint,
            )
        );
        return $encoded === '' ? '' : self::base64url( hash( 'sha256', $encoded, true ) );
    }

    private static function reservation_id( $batch_id, $upload_id ) {
        return hash( 'sha256', $batch_id . "\0" . $upload_id );
    }

    private static function active_artifact_bytes( $manifest ) {
        $total = isset( $manifest['artifact_bytes'] ) && is_int( $manifest['artifact_bytes'] ) ? $manifest['artifact_bytes'] : PHP_INT_MAX;
        foreach ( isset( $manifest['intents'] ) && is_array( $manifest['intents'] ) ? $manifest['intents'] : array() as $intent ) {
            $bytes = isset( $intent['reserved_bytes'] ) && is_int( $intent['reserved_bytes'] ) ? $intent['reserved_bytes'] : PHP_INT_MAX;
            if ( $bytes < 0 || $total > PHP_INT_MAX - $bytes ) {
                return PHP_INT_MAX;
            }
            $total += $bytes;
        }
        return $total;
    }

    private static function intent_summary( $intent ) {
        return array(
            'intent_id' => $intent['intent_id'],
            'upload_id' => $intent['upload_id'],
            'ordinal' => (int) $intent['ordinal'],
            'display_name' => $intent['display_name'],
            'declared_bytes' => (int) $intent['declared_bytes'],
            'declared_mime' => $intent['declared_mime'],
            'object_key' => $intent['object_key'],
            'policy_fingerprint' => $intent['policy_fingerprint'],
            'expires_at' => (int) $intent['expires_at'],
        );
    }

    private static function worker_intent_summary( $intent ) {
        return array(
            'intent_id' => $intent['intent_id'],
            'upload_id' => $intent['upload_id'],
            'ordinal' => (int) $intent['ordinal'],
            'display_name' => $intent['display_name'],
            'declared_bytes' => (int) $intent['declared_bytes'],
            'declared_mime' => $intent['declared_mime'],
            'object_key' => $intent['object_key'],
            'policy_fingerprint' => $intent['policy_fingerprint'],
            'storage_identity' => $intent['storage_identity'],
            'validation_contract_version' => $intent['validation_contract_version'],
            'upload_until' => (int) $intent['upload_until'],
            'accept_until' => (int) $intent['accept_until'],
            'validation_until' => (int) $intent['validation_until'],
            'staged_delete_after' => (int) $intent['staged_delete_after'],
        );
    }

    private static function worker_tombstone_from_source( $source, $now ) {
        $stored = isset( $source['object_version'], $source['etag'] );
        return array(
            'deleted_at' => $now,
            'bytes' => $stored ? (int) $source['bytes'] : (int) $source['reserved_bytes'],
            'object_key' => $source['object_key'],
            'object_version' => $stored ? $source['object_version'] : '-',
            'etag' => $stored ? $source['etag'] : '-',
            'policy_fingerprint' => $source['policy_fingerprint'],
            'storage_identity' => $source['storage_identity'],
            'validation_contract_version' => $source['validation_contract_version'],
            'validation_until' => $source['validation_until'],
            'capacity_release_started' => false,
            'capacity_released' => false,
        );
    }

    private static function worker_cancellation_tombstone( $manifest, $now ) {
        return array(
            'deleted_at' => $now,
            'bytes' => 0,
            'object_key' => '',
            'object_version' => '-',
            'etag' => '-',
            'policy_fingerprint' => $manifest['binding']['policy_fingerprint'],
            'storage_identity' => $manifest['artifact_store_identity'],
            'validation_contract_version' => WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION,
            'validation_until' => (int) $manifest['accept_until'],
            'capacity_release_started' => true,
            'capacity_released' => true,
        );
    }

    private static function valid_worker_stored_receipt_claims( $receipt, $batch_id, $upload_id ) {
        $expected = array(
            'batch_id', 'bytes', 'etag', 'expires_at', 'intent_id', 'object_key', 'object_version',
            'ordinal', 'policy_fingerprint', 'storage_identity', 'upload_id', 'validation_contract_version',
        );
        $actual = is_array( $receipt ) ? array_keys( $receipt ) : array();
        sort( $actual, SORT_STRING );
        return is_array( $receipt )
            && $actual === $expected
            && is_string( $batch_id )
            && is_string( $upload_id )
            && $receipt['batch_id'] === $batch_id
            && $receipt['upload_id'] === $upload_id
            && ! isset( $receipt['mime'], $receipt['width'], $receipt['height'], $receipt['accepted_at'], $receipt['outcome'] )
            && self::valid_intent_id( $receipt['intent_id'] )
            && self::nonnegative_int( $receipt['ordinal'] )
            && self::valid_artifact_store_identity( FormProtocol::UPLOAD_TRANSPORT_WORKER, $receipt['storage_identity'] )
            && self::valid_validation_contract_version( $receipt['validation_contract_version'] )
            && self::valid_object_identity( $receipt['object_key'], $receipt['object_version'] )
            && self::valid_known_worker_provider_fact( $receipt['object_version'] )
            && self::valid_known_worker_provider_fact( $receipt['etag'] )
            && is_int( $receipt['bytes'] )
            && $receipt['bytes'] > 0
            && is_string( $receipt['policy_fingerprint'] )
            && preg_match( '/^[0-9a-f]{64}$/D', $receipt['policy_fingerprint'] ) === 1
            && self::nonnegative_int( $receipt['expires_at'] );
    }

    private static function worker_receipt_matches_intent( $receipt, $manifest, $intent, $now ) {
        return $receipt['ordinal'] === $intent['ordinal']
            && hash_equals( $receipt['intent_id'], $intent['intent_id'] )
            && hash_equals( $receipt['object_key'], $intent['object_key'] )
            && hash_equals( $receipt['policy_fingerprint'], $intent['policy_fingerprint'] )
            && hash_equals( $receipt['policy_fingerprint'], $manifest['binding']['policy_fingerprint'] )
            && hash_equals( $receipt['storage_identity'], $intent['storage_identity'] )
            && hash_equals( $receipt['storage_identity'], $manifest['artifact_store_identity'] )
            && hash_equals( $receipt['validation_contract_version'], $intent['validation_contract_version'] )
            && $receipt['bytes'] === $intent['declared_bytes']
            && $now < $intent['accept_until']
            && $now < $intent['staged_delete_after']
            && $receipt['expires_at'] >= $now - Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' )
            && $receipt['expires_at'] <= $intent['accept_until'];
    }

    private static function worker_receipt_matches_item( $receipt, $manifest, $item, $now ) {
        return $receipt['ordinal'] === $item['ordinal']
            && hash_equals( $receipt['intent_id'], $item['intent_id'] )
            && hash_equals( $receipt['object_key'], $item['object_key'] )
            && hash_equals( $receipt['object_version'], $item['object_version'] )
            && hash_equals( $receipt['etag'], $item['etag'] )
            && hash_equals( $receipt['policy_fingerprint'], $item['policy_fingerprint'] )
            && hash_equals( $receipt['policy_fingerprint'], $manifest['binding']['policy_fingerprint'] )
            && hash_equals( $receipt['storage_identity'], $item['storage_identity'] )
            && hash_equals( $receipt['storage_identity'], $manifest['artifact_store_identity'] )
            && hash_equals( $receipt['validation_contract_version'], $item['validation_contract_version'] )
            && $receipt['bytes'] === $item['bytes']
            && $receipt['expires_at'] >= $now - Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
    }

    private static function declared_mime( $item, $display_name, $policy ) {
        $declared = is_array( $item ) && isset( $item['type'] ) && is_string( $item['type'] )
            ? strtolower( trim( $item['type'] ) )
            : '';
        $mime_policy = UploadPolicy::policy_for_tokens( isset( $policy['accept'] ) ? $policy['accept'] : array(), 'staged' );
        $extension = UploadPolicy::extension_from_name( $display_name );
        if ( UploadPolicy::mime_allowed( $declared, $extension, $mime_policy ) ) {
            return $declared;
        }
        $mapped = isset( $mime_policy['ext_to_mime'][ $extension ] ) ? $mime_policy['ext_to_mime'][ $extension ] : '';
        return is_array( $mapped ) ? ( isset( $mapped[0] ) ? $mapped[0] : '' ) : ( is_string( $mapped ) ? $mapped : '' );
    }

    private static function valid_artifact_facts( $facts, $intent, $policy ) {
        $keys = is_array( $facts ) ? array_keys( $facts ) : array();
        sort( $keys, SORT_STRING );
        if ( ! is_array( $facts )
            || $keys !== array( 'bytes', 'height', 'mime', 'object_key', 'object_version', 'width' )
            || ! is_string( $facts['object_key'] )
            || ! hash_equals( $intent['object_key'], $facts['object_key'] )
            || ! self::valid_object_identity( $facts['object_key'], $facts['object_version'] )
            || ! is_int( $facts['bytes'] )
            || $facts['bytes'] !== $intent['declared_bytes']
            || ! is_int( $facts['width'] )
            || ! is_int( $facts['height'] )
            || ! UploadPolicy::staged_dimensions_allowed( $facts['width'], $facts['height'] )
            || ! is_string( $facts['mime'] )
        ) {
            return false;
        }
        $mime_policy = UploadPolicy::policy_for_tokens( $policy['accept'], 'staged' );
        return UploadPolicy::mime_allowed( $facts['mime'], UploadPolicy::extension_from_name( $intent['display_name'] ), $mime_policy );
    }

    private static function facts_match_item( $facts, $item ) {
        return is_array( $facts )
            && isset( $facts['object_key'], $facts['object_version'], $facts['bytes'], $facts['mime'], $facts['width'], $facts['height'] )
            && $facts['object_key'] === $item['object_key']
            && $facts['object_version'] === $item['object_version']
            && $facts['bytes'] === $item['bytes']
            && $facts['mime'] === $item['mime']
            && $facts['width'] === $item['width']
            && $facts['height'] === $item['height'];
    }

    private static function valid_intent_id( $intent_id ) {
        return ManagedArtifactKey::valid_digest( $intent_id );
    }

    private static function valid_display_name( $display_name ) {
        return is_string( $display_name )
            && $display_name !== ''
            && UploadValue::sanitize_display_name( $display_name ) === $display_name;
    }

    private static function valid_object_identity( $object_key, $object_version, $allow_empty_version = false ) {
        return ManagedArtifactKey::valid( $object_key )
            && is_string( $object_version )
            && ( ( $allow_empty_version && $object_version === '' )
                || preg_match( '/^[A-Za-z0-9._:-]{1,' . Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) . '}$/D', $object_version ) === 1 );
    }

    private static function check_item_mutation( $manifest, $batch_secret, $now ) {
        if ( $manifest === null ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( $now >= $manifest['delete_after'] ) {
            return self::gone();
        }
        if ( ! self::secret_matches( $manifest, $batch_secret ) || $manifest['state'] !== 'open' || $now >= $manifest['accept_until'] ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_not_open' );
        }
        return self::success();
    }

    private static function check_worker_item_mutation( $manifest, $batch_secret, $now, $accept_until, $staged_delete_after ) {
        if ( $manifest === null ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( $manifest['version'] !== self::WORKER_MANIFEST_VERSION && ( ! empty( $manifest['intents'] ) || ! empty( $manifest['items'] ) || ! empty( $manifest['tombstones'] ) ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( $now >= $manifest['delete_after'] ) {
            return self::gone();
        }
        if ( ! self::secret_matches( $manifest, $batch_secret )
            || $manifest['state'] !== 'open'
            || $now >= $manifest['accept_until']
            || $manifest['accept_until'] !== $accept_until
            || $manifest['delete_after'] !== $staged_delete_after
        ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_not_open' );
        }
        return self::success();
    }

    private static function check_cleanup_mutation( $manifest, $batch_secret, $now ) {
        if ( $manifest === null ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( $now >= $manifest['delete_after'] ) {
            return self::gone();
        }
        if ( ! self::secret_matches( $manifest, $batch_secret ) || $manifest['state'] !== 'open' ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'batch_not_open' );
        }
        return self::success();
    }

    private static function lock_staged_batch( $batch_id, $uploads_dir, $existing_only = false, $repair_lock = true ) {
        if ( ! is_string( $batch_id ) || preg_match( FormProtocol::upload_batch_id_pattern(), $batch_id ) !== 1 ) {
            return self::gone();
        }
        if ( ! $repair_lock ) {
            $root = $existing_only
                ? PrivateDir::existing_protected_subdir( $uploads_dir, self::STAGED_DIR )
                : rtrim( PrivateDir::path( $uploads_dir ), '/\\' ) . '/' . self::STAGED_DIR;
            if ( $root === '/' . self::STAGED_DIR || is_link( $root ) || ! is_dir( $root ) ) {
                $root = '';
            }
        } else {
            $root = $existing_only
                ? PrivateDir::existing_protected_subdir( $uploads_dir, self::STAGED_DIR )
                : self::managed_root( $uploads_dir, self::STAGED_DIR, false );
        }
        if ( $root === '' ) {
            return self::gone();
        }
        $shard = $root . '/' . Helpers::h2( $batch_id );
        if ( is_link( $shard ) ) {
            return self::gone();
        }
        $path = $shard . '/' . $batch_id;
        if ( is_link( $path ) || ! is_dir( $path ) ) {
            return self::gone();
        }
        $lock = self::acquire_staged_lock( $path, false, false, $repair_lock );
        if ( $lock === false ) {
            clearstatcache( true, $path );
            if ( is_link( $path ) || ! is_dir( $path ) ) {
                return self::gone();
            }
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_lock_failed' );
        }
        $manifest_path = $path . '/' . self::MANIFEST_FILENAME;
        $locked_paths = array(
            'shard' => $shard,
            'path' => $path,
            'manifest_path' => $manifest_path,
        );
        if ( ! self::staged_batch_paths_valid( $locked_paths ) ) {
            self::release_lock( $lock );
            return self::gone();
        }
        if ( self::managed_purged( $uploads_dir ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'managed_purged' );
        }
        return self::success(
            array(
                'shard' => $shard,
                'path' => $path,
                'manifest_path' => $manifest_path,
                'lock' => $lock,
            )
        );
    }

    private static function staged_batch_paths_valid( $locked ) {
        if ( ! is_array( $locked ) || ! isset( $locked['shard'], $locked['path'], $locked['manifest_path'] ) ) {
            return false;
        }
        clearstatcache( true, $locked['shard'] );
        clearstatcache( true, $locked['path'] );
        clearstatcache( true, $locked['manifest_path'] );
        return ! is_link( $locked['shard'] )
            && is_dir( $locked['shard'] )
            && ! is_link( $locked['path'] )
            && is_dir( $locked['path'] )
            && ! is_link( $locked['manifest_path'] )
            && is_file( $locked['manifest_path'] );
    }

    private static function lock_capacity( $uploads_dir, $lifecycle, $allow_purged = false ) {
        if ( ! self::capacity_platform_supported() ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'integer_width_unsupported' );
        }
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $uploads_dir = is_string( $uploads_dir ) ? rtrim( $uploads_dir, '/\\' ) : '';
        $expected_private = PrivateDir::path( $uploads_dir );
        $private_dir = rtrim( $lifecycle->private_dir(), '/\\' );
        if ( $expected_private === '' || $private_dir !== $expected_private || is_link( $private_dir ) || ! is_dir( $private_dir ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'private_dir_unavailable' );
        }
        $lock = ManagedCapacityStore::acquire_lock( $private_dir . '/' . self::CAPACITY_LOCK_FILENAME );
        if ( $lock === false ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_lock_failed' );
        }
        if ( self::managed_purged( $uploads_dir ) && ( ! $allow_purged || ! $lifecycle->exclusive() ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'managed_purged' );
        }
        return self::success(
            array(
                'lock' => $lock,
                'path' => $private_dir . '/' . self::CAPACITY_FILENAME,
                'private_dir' => $private_dir,
            )
        );
    }

    private static function lock_capacity_for_health( $uploads_dir, $lifecycle ) {
        if ( ! self::capacity_platform_supported() ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'integer_width_unsupported' );
        }
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }

        $uploads_dir = is_string( $uploads_dir ) ? rtrim( $uploads_dir, '/\\' ) : '';
        $expected_private = PrivateDir::path( $uploads_dir );
        $private_dir = rtrim( $lifecycle->private_dir(), '/\\' );
        if ( $expected_private === '' || $private_dir !== $expected_private || is_link( $private_dir ) || ! is_dir( $private_dir ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'private_dir_unavailable' );
        }

        $lock = false;
        $lock_path = $private_dir . '/' . self::CAPACITY_LOCK_FILENAME;
        $capacity_path = $private_dir . '/' . self::CAPACITY_FILENAME;
        clearstatcache( true, $lock_path );
        clearstatcache( true, $capacity_path );
        if ( is_link( $lock_path ) || ( file_exists( $lock_path ) && ! is_file( $lock_path ) ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_lock_failed' );
        }
        if ( is_file( $lock_path ) ) {
            $lock = ManagedCapacityStore::acquire_lock( $lock_path, false, false, true );
            if ( $lock === false ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_lock_failed' );
            }
        } elseif ( is_link( $capacity_path ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        } elseif ( file_exists( $capacity_path ) || ! self::managed_roots_empty_for_health( $private_dir ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_lock_failed' );
        }
        if ( self::managed_purged( $uploads_dir ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'managed_purged' );
        }
        return self::success(
            array(
                'lock' => $lock,
                'path' => $capacity_path,
                'private_dir' => $private_dir,
            )
        );
    }

    private static function managed_roots_empty_for_health( $private_dir ) {
        foreach ( array( self::STAGED_DIR, self::SUBMISSIONS_DIR, LocalArtifactStore::ROOT_DIR ) as $name ) {
            $root = rtrim( $private_dir, '/\\' ) . '/' . $name;
            if ( is_link( $root ) ) {
                return false;
            }
            if ( ! file_exists( $root ) ) {
                continue;
            }
            if ( ! is_dir( $root ) ) {
                return false;
            }
            try {
                $iterator = new FilesystemIterator( $root, FilesystemIterator::SKIP_DOTS );
                foreach ( $iterator as $entry ) {
                    if ( ! self::is_private_deny_filename( $entry->getFilename() ) ) {
                        return false;
                    }
                }
            } catch ( Throwable $error ) {
                return false;
            }
        }
        return true;
    }

    private static function is_private_deny_filename( $filename ) {
        return in_array(
            (string) $filename,
            array( PrivateDir::INDEX_FILENAME, PrivateDir::HTACCESS_FILENAME, PrivateDir::WEBCONFIG_FILENAME ),
            true
        );
    }

    private static function managed_purged( $uploads_dir ) {
        return PrivateDir::is_purged( $uploads_dir );
    }

    private static function lock_submission( $submission_id, $uploads_dir, $existing_only = false, $repair_lock = true ) {
        if ( ! self::valid_submission_id( $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $lifecycle = null;
        if ( $existing_only ) {
            $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
            if ( ! $lifecycle instanceof PrivateDirLease ) {
                return self::failure(
                    'EFORMS_ERR_STORAGE_UNAVAILABLE',
                    self::managed_purged( $uploads_dir ) ? 'managed_purged' : 'upload_lifecycle_unavailable'
                );
            }
        }
        if ( ! $repair_lock ) {
            $private_dir = PrivateDir::path( $uploads_dir );
            $root = $private_dir === '' ? '' : rtrim( $private_dir, '/\\' ) . '/' . self::SUBMISSIONS_DIR;
            if ( is_link( $root ) || ! is_dir( $root ) ) {
                $root = '';
            }
        } else {
            $root = $existing_only
                ? PrivateDir::existing_protected_review_subdir( $uploads_dir, self::SUBMISSIONS_DIR )
                : self::managed_root( $uploads_dir, self::SUBMISSIONS_DIR, false );
        }
        if ( $root === '' ) {
            self::release_submission_lock( array( 'lifecycle' => $lifecycle ) );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $shard = $root . '/' . Helpers::h2( $submission_id );
        if ( is_link( $shard ) || ! is_dir( $shard ) ) {
            self::release_submission_lock( array( 'lifecycle' => $lifecycle ) );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $path = $shard . '/' . $submission_id;
        if ( is_link( $path ) || ! is_dir( $path ) ) {
            self::release_submission_lock( array( 'lifecycle' => $lifecycle ) );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $lock_path = self::aggregate_lock_path( self::SUBMISSIONS_DIR, $path );
        $lock = $existing_only
            ? self::acquire_existing_lock( $lock_path, false, $repair_lock )
            : ( $repair_lock ? self::acquire_lock( $lock_path ) : self::acquire_existing_lock( $lock_path, false, false ) );
        if ( $lock === false ) {
            self::release_submission_lock( array( 'lifecycle' => $lifecycle ) );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_lock_failed' );
        }
        $directories_ok = $repair_lock
            ? ( PrivateDir::ensure_existing_review_directory( $shard ) && PrivateDir::ensure_existing_review_directory( $path ) )
            : ( ! is_link( $shard ) && is_dir( $shard ) && ! is_link( $path ) && is_dir( $path ) );
        if ( ! $directories_ok ) {
            self::release_submission_lock( array( 'lock' => $lock, 'lifecycle' => $lifecycle ) );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        if ( self::managed_purged( $uploads_dir ) ) {
            self::release_submission_lock( array( 'lock' => $lock, 'lifecycle' => $lifecycle ) );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'managed_purged' );
        }
        $manifest_path = $path . '/' . self::MANIFEST_FILENAME;
        return self::success(
            array(
                'path' => $path,
                'manifest_path' => $manifest_path,
                'lock' => $lock,
                'lifecycle' => $lifecycle,
            )
        );
    }

    private static function write_review_snapshot_locked( $submission_path, $review_snapshot ) {
        if ( is_link( $submission_path ) || ! is_dir( $submission_path ) || empty( SubmissionReviewSnapshot::validate( $review_snapshot )['ok'] ) ) {
            return false;
        }
        return self::write_json_atomic( rtrim( $submission_path, '/\\' ) . '/' . self::REVIEW_SNAPSHOT_FILENAME, $review_snapshot, PrivateDir::REVIEW_FILE_MODE );
    }

    private static function review_snapshot_for_manifest( $review_snapshot, $manifest ) {
        if ( is_array( $review_snapshot ) && is_array( $manifest ) && isset( $manifest['finalized_at'] ) && is_int( $manifest['finalized_at'] ) ) {
            $review_snapshot['submitted_at'] = gmdate( 'c', $manifest['finalized_at'] );
        }
        return $review_snapshot;
    }

    private static function valid_review_snapshot_for_submission( $review_snapshot, $submission_id ) {
        $validated = SubmissionReviewSnapshot::validate( $review_snapshot );
        return ! empty( $validated['ok'] )
            && isset( $review_snapshot['submission_id'] )
            && is_string( $review_snapshot['submission_id'] )
            && hash_equals( $review_snapshot['submission_id'], $submission_id );
    }

    private static function read_manifest( $path, $aggregate_family, $aggregate_id, $repair_file = true ) {
        if ( $repair_file ) {
            if ( ! PrivateDir::ensure_existing_private_file( $path ) ) {
                return null;
            }
        } elseif ( ! is_string( $path ) || $path === '' || is_link( $path ) || ! is_file( $path ) ) {
            return null;
        }
        $manifest = self::read_json( $path );
        if ( ! is_array( $manifest )
            || ! isset( $manifest['version'], $manifest['batch_id'], $manifest['state'], $manifest['artifact_store'], $manifest['artifact_store_identity'], $manifest['binding'], $manifest['batch_secret_digest'], $manifest['policy'] )
            || ! is_int( $manifest['version'] )
            || $manifest['version'] !== self::MANIFEST_VERSION
            || ! is_string( $manifest['batch_id'] )
            || preg_match( FormProtocol::upload_batch_id_pattern(), $manifest['batch_id'] ) !== 1
            || ! in_array( $manifest['state'], array( 'open', 'finalizing', 'finalized' ), true )
            || ! self::valid_manifest_keys( $manifest )
            || ! self::valid_artifact_store( $manifest['artifact_store'] )
            || ! self::valid_artifact_store_identity( $manifest['artifact_store'], $manifest['artifact_store_identity'] )
            || ! is_array( $manifest['binding'] )
            || ! self::valid_manifest_binding( $manifest['binding'] )
            || ! is_string( $manifest['batch_secret_digest'] )
            || preg_match( '/^[0-9a-f]{64}$/', $manifest['batch_secret_digest'] ) !== 1
            || ! is_array( $manifest['policy'] )
            || ! self::valid_manifest_policy( $manifest['policy'] )
            || ! array_key_exists( 'delete_after', $manifest )
            || ! isset( $manifest['accept_until'], $manifest['intents'], $manifest['items'], $manifest['tombstones'], $manifest['artifact_bytes'] )
            || ! isset( $manifest['created_at'] )
            || ! self::nonnegative_int( $manifest['created_at'] )
            || ! self::nonnegative_int( $manifest['accept_until'] )
            || $manifest['created_at'] > $manifest['accept_until']
            || ! self::valid_manifest_delete_after( $manifest )
            || ! is_array( $manifest['intents'] )
            || ! is_array( $manifest['items'] )
            || ! is_array( $manifest['tombstones'] )
            || ! self::nonnegative_int( $manifest['artifact_bytes'] )
        ) {
            return null;
        }

        $ordinals = array();
        foreach ( $manifest['intents'] as $upload_id => $intent ) {
            $upload_id = (string) $upload_id;
            if ( ! self::valid_manifest_intent( $upload_id, $intent, $manifest )
                || isset( $ordinals[ $intent['ordinal'] ] )
            ) {
                return null;
            }
            $ordinals[ $intent['ordinal'] ] = true;
        }
        foreach ( $manifest['items'] as $upload_id => $item ) {
            $upload_id = (string) $upload_id;
            if ( ! self::valid_manifest_item( $upload_id, $item, $manifest )
                || $item['accepted_at'] < $manifest['created_at']
                || ( $manifest['delete_after'] !== null && $item['accepted_at'] >= $manifest['delete_after'] )
                || isset( $ordinals[ $item['ordinal'] ] )
            ) {
                return null;
            }
            $ordinals[ $item['ordinal'] ] = true;
        }
        foreach ( $manifest['tombstones'] as $upload_id => $tombstone ) {
            if ( ! self::valid_manifest_tombstone( (string) $upload_id, $tombstone, $manifest )
                || $tombstone['deleted_at'] < $manifest['created_at']
                || ( $manifest['delete_after'] !== null && $tombstone['deleted_at'] > $manifest['delete_after'] )
            ) {
                return null;
            }
        }
        if ( count( $manifest['intents'] ) + count( $manifest['items'] ) > $manifest['policy']['max_files']
            || count( $manifest['intents'] ) + count( $manifest['items'] ) + count( $manifest['tombstones'] ) > self::tombstone_limit( $manifest['policy'] )
            || ! empty( array_intersect_key( $manifest['intents'], $manifest['items'] ) )
            || ! empty( array_intersect_key( $manifest['intents'], $manifest['tombstones'] ) )
            || ! empty( array_intersect_key( $manifest['items'], $manifest['tombstones'] ) )
            || self::active_artifact_bytes( $manifest ) > $manifest['policy']['max_total_bytes']
            || self::aggregate_byte_parts( $manifest ) === null
            || ! hash_equals( $manifest['binding']['policy_fingerprint'], self::policy_fingerprint( $manifest['policy'] ) )
            || ! self::valid_manifest_state( $manifest )
        ) {
            return null;
        }
        if ( $aggregate_family === 'staged' ) {
            if ( ! is_string( $aggregate_id )
                || preg_match( FormProtocol::upload_batch_id_pattern(), $aggregate_id ) !== 1
                || ! hash_equals( $manifest['batch_id'], $aggregate_id )
            ) {
                return null;
            }
        } elseif ( $aggregate_family === 'submission' ) {
            if ( ! self::valid_submission_id( $aggregate_id )
                || ! isset( $manifest['claim']['submission_id'] )
                || ! hash_equals( $manifest['claim']['submission_id'], $aggregate_id )
            ) {
                return null;
            }
        } else {
            return null;
        }
        return $manifest;
    }

    private static function read_worker_authorization_manifest( $path, $batch_id, $repair_file = true ) {
        $manifest = self::read_worker_manifest( $path, 'staged', $batch_id, false, $repair_file );
        return is_array( $manifest ) && $manifest['state'] === 'open' ? $manifest : null;
    }

    private static function read_worker_manifest( $path, $aggregate_family, $aggregate_id, $enforce_worker_ceiling = true, $repair_file = true ) {
        if ( $repair_file ) {
            if ( ! PrivateDir::ensure_existing_private_file( $path ) ) {
                return null;
            }
        } elseif ( ! is_string( $path ) || $path === '' || is_link( $path ) || ! is_file( $path ) ) {
            return null;
        }
        $manifest = self::read_json( $path );
        if ( ! is_array( $manifest )
            || ! isset( $manifest['version'], $manifest['batch_id'], $manifest['state'], $manifest['artifact_store'], $manifest['artifact_store_identity'], $manifest['binding'], $manifest['batch_secret_digest'], $manifest['policy'] )
            || ! is_int( $manifest['version'] )
            || $manifest['version'] !== self::WORKER_MANIFEST_VERSION
            || ! is_string( $manifest['batch_id'] )
            || preg_match( FormProtocol::upload_batch_id_pattern(), $manifest['batch_id'] ) !== 1
            || ! in_array( $manifest['state'], array( 'open', 'finalizing', 'finalized', 'operator_deleted' ), true )
            || ! self::valid_manifest_keys( $manifest )
            || $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
            || ! self::valid_artifact_store_identity( $manifest['artifact_store'], $manifest['artifact_store_identity'] )
            || ! is_array( $manifest['binding'] )
            || ! self::valid_manifest_binding( $manifest['binding'] )
            || ! is_string( $manifest['batch_secret_digest'] )
            || preg_match( '/^[0-9a-f]{64}$/', $manifest['batch_secret_digest'] ) !== 1
            || ! is_array( $manifest['policy'] )
            || ! self::valid_manifest_policy( $manifest['policy'] )
            || ( $enforce_worker_ceiling && ! self::worker_policy_within_staged_ceiling( $manifest['policy'] ) )
            || ! array_key_exists( 'delete_after', $manifest )
            || ! isset( $manifest['accept_until'], $manifest['intents'], $manifest['items'], $manifest['tombstones'], $manifest['artifact_bytes'] )
            || ! isset( $manifest['created_at'] )
            || ! self::nonnegative_int( $manifest['created_at'] )
            || ! self::nonnegative_int( $manifest['accept_until'] )
            || $manifest['created_at'] > $manifest['accept_until']
            || ! self::valid_manifest_delete_after( $manifest )
            || ! is_array( $manifest['intents'] )
            || ! is_array( $manifest['items'] )
            || ! is_array( $manifest['tombstones'] )
            || ! self::nonnegative_int( $manifest['artifact_bytes'] )
        ) {
            return null;
        }

        $ordinals = array();
        foreach ( $manifest['intents'] as $upload_id => $intent ) {
            $upload_id = (string) $upload_id;
            if ( ! self::valid_worker_manifest_intent( $upload_id, $intent, $manifest )
                || isset( $ordinals[ $intent['ordinal'] ] )
            ) {
                return null;
            }
            $ordinals[ $intent['ordinal'] ] = true;
        }
        foreach ( $manifest['items'] as $upload_id => $item ) {
            $upload_id = (string) $upload_id;
            if ( ! self::valid_worker_manifest_item( $upload_id, $item, $manifest )
                || isset( $ordinals[ $item['ordinal'] ] )
            ) {
                return null;
            }
            $ordinals[ $item['ordinal'] ] = true;
        }
        foreach ( $manifest['tombstones'] as $upload_id => $tombstone ) {
            if ( ! self::valid_worker_manifest_tombstone( (string) $upload_id, $tombstone, $manifest )
                || $tombstone['deleted_at'] < $manifest['created_at']
                || ( $manifest['delete_after'] !== null && $tombstone['deleted_at'] > $manifest['delete_after'] )
            ) {
                return null;
            }
        }
        if ( count( $manifest['intents'] ) + count( $manifest['items'] ) > $manifest['policy']['max_files']
            || count( $manifest['intents'] ) + count( $manifest['items'] ) + count( $manifest['tombstones'] ) > self::tombstone_limit( $manifest['policy'] )
            || ! empty( array_intersect_key( $manifest['intents'], $manifest['items'] ) )
            || ! empty( array_intersect_key( $manifest['intents'], $manifest['tombstones'] ) )
            || ! empty( array_intersect_key( $manifest['items'], $manifest['tombstones'] ) )
            || self::active_artifact_bytes( $manifest ) > $manifest['policy']['max_total_bytes']
            || self::aggregate_byte_parts( $manifest ) === null
            || ! hash_equals( $manifest['binding']['policy_fingerprint'], self::policy_fingerprint( $manifest['policy'] ) )
            || ! self::valid_manifest_state( $manifest )
        ) {
            return null;
        }
        if ( $aggregate_family === 'staged' ) {
            return is_string( $aggregate_id )
                && preg_match( FormProtocol::upload_batch_id_pattern(), $aggregate_id ) === 1
                && hash_equals( $manifest['batch_id'], $aggregate_id )
                    ? $manifest
                    : null;
        }
        if ( $aggregate_family === 'submission' ) {
            return self::valid_submission_id( $aggregate_id )
                && isset( $manifest['claim']['submission_id'] )
                && hash_equals( $manifest['claim']['submission_id'], $aggregate_id )
                    ? $manifest
                    : null;
        }
        return null;
    }

    private static function valid_manifest_keys( $manifest ) {
        $expected = array(
            'accept_until', 'artifact_bytes', 'artifact_store', 'artifact_store_identity', 'batch_id',
            'batch_secret_digest', 'binding', 'created_at', 'delete_after', 'intents', 'items', 'policy',
            'state', 'tombstones', 'version',
        );
        if ( isset( $manifest['state'] ) && $manifest['state'] !== 'open' ) {
            $expected[] = 'claim';
        }
        if ( isset( $manifest['state'] ) && in_array( $manifest['state'], array( 'finalized', 'operator_deleted' ), true ) ) {
            $expected[] = 'finalized_at';
            if ( isset( $manifest['email_attempted_at'] ) ) {
                $expected[] = 'email_attempted_at';
            }
        }
        return self::exact_keys( $manifest, $expected );
    }

    private static function valid_manifest_delete_after( $manifest ) {
        if ( ! is_array( $manifest ) || ! array_key_exists( 'delete_after', $manifest ) || ! isset( $manifest['state'], $manifest['accept_until'] ) ) {
            return false;
        }
        if ( in_array( $manifest['state'], array( 'finalized', 'operator_deleted' ), true ) ) {
            return $manifest['delete_after'] === null || self::nonnegative_int( $manifest['delete_after'] );
        }
        return self::nonnegative_int( $manifest['delete_after'] ) && $manifest['accept_until'] <= $manifest['delete_after'];
    }

    private static function valid_manifest_binding( $binding ) {
        if ( ! self::exact_keys( $binding, array( 'field_key', 'form_id', 'instance_id', 'policy_fingerprint', 'token_digest' ) ) ) {
            return false;
        }
        foreach ( array( 'form_id', 'instance_id', 'token_digest', 'field_key', 'policy_fingerprint' ) as $key ) {
            if ( ! isset( $binding[ $key ] ) || ! is_string( $binding[ $key ] ) || $binding[ $key ] === '' ) {
                return false;
            }
        }
        return preg_match( '/^[0-9a-f]{64}$/', $binding['token_digest'] ) === 1
            && preg_match( '/^[0-9a-f]{64}$/', $binding['policy_fingerprint'] ) === 1;
    }

    private static function valid_manifest_policy( $policy ) {
        return self::exact_keys( $policy, array( 'accept', 'max_file_bytes', 'max_files', 'max_total_bytes', 'upload_mode' ) )
            && isset( $policy['accept'], $policy['max_file_bytes'], $policy['max_files'], $policy['max_total_bytes'], $policy['upload_mode'] )
            && is_array( $policy['accept'] )
            && is_int( $policy['max_file_bytes'] )
            && is_int( $policy['max_files'] )
            && is_int( $policy['max_total_bytes'] )
            && is_string( $policy['upload_mode'] )
            && self::valid_staged_policy( $policy );
    }

    private static function valid_manifest_intent( $upload_id, $intent, $manifest ) {
        $expected_keys = array(
            'created_at', 'declared_bytes', 'declared_mime', 'display_name', 'expires_at', 'intent_id', 'object_key',
            'ordinal', 'policy_fingerprint', 'reserved_bytes', 'upload_id',
        );
        if ( ! self::valid_upload_id( $upload_id )
            || ! is_array( $intent )
            || ! self::exact_keys( $intent, $expected_keys )
            || $intent['upload_id'] !== $upload_id
            || ! self::valid_intent_id( $intent['intent_id'] )
            || ! self::nonnegative_int( $intent['ordinal'] )
            || ! self::valid_display_name( $intent['display_name'] )
            || ! self::nonnegative_int( $intent['declared_bytes'] )
            || $intent['declared_bytes'] < 1
            || $intent['declared_bytes'] > $manifest['policy']['max_file_bytes']
            || $intent['reserved_bytes'] !== $intent['declared_bytes']
            || ! is_string( $intent['declared_mime'] )
            || ! is_string( $intent['object_key'] )
            || ! hash_equals( ManagedArtifactKey::create( $manifest['batch_id'], $intent['ordinal'], $intent['intent_id'], $intent['declared_mime'] ), $intent['object_key'] )
            || ! is_string( $intent['policy_fingerprint'] )
            || ! hash_equals( $manifest['binding']['policy_fingerprint'], $intent['policy_fingerprint'] )
            || ! self::nonnegative_int( $intent['created_at'] )
            || ! self::nonnegative_int( $intent['expires_at'] )
            || $intent['created_at'] < $manifest['created_at']
            || $intent['created_at'] >= $manifest['accept_until']
            || $intent['expires_at'] < $intent['created_at']
            || $intent['expires_at'] > $manifest['accept_until']
        ) {
            return false;
        }
        $mime_policy = UploadPolicy::policy_for_tokens( $manifest['policy']['accept'], 'staged' );
        return UploadPolicy::mime_allowed( $intent['declared_mime'], UploadPolicy::extension_from_name( $intent['display_name'] ), $mime_policy );
    }

    private static function valid_worker_manifest_intent( $upload_id, $intent, $manifest ) {
        $expected_keys = array(
            'accept_until', 'created_at', 'declared_bytes', 'declared_mime', 'display_name', 'intent_id', 'object_key',
            'ordinal', 'policy_fingerprint', 'reserved_bytes', 'staged_delete_after', 'storage_identity', 'upload_id',
            'upload_until', 'validation_contract_version', 'validation_until',
        );
        if ( ! self::valid_upload_id( $upload_id )
            || ! is_array( $intent )
            || ! self::exact_keys( $intent, $expected_keys )
            || $intent['upload_id'] !== $upload_id
            || ! self::valid_intent_id( $intent['intent_id'] )
            || ! self::nonnegative_int( $intent['ordinal'] )
            || ! self::valid_display_name( $intent['display_name'] )
            || ! self::nonnegative_int( $intent['declared_bytes'] )
            || $intent['declared_bytes'] < 1
            || $intent['declared_bytes'] > $manifest['policy']['max_file_bytes']
            || $intent['reserved_bytes'] !== $intent['declared_bytes']
            || ! is_string( $intent['declared_mime'] )
            || ! is_string( $intent['object_key'] )
            || ! hash_equals( ManagedArtifactKey::create( $manifest['batch_id'], $intent['ordinal'], $intent['intent_id'], $intent['declared_mime'] ), $intent['object_key'] )
            || ! is_string( $intent['policy_fingerprint'] )
            || ! hash_equals( $manifest['binding']['policy_fingerprint'], $intent['policy_fingerprint'] )
            || ! hash_equals( $manifest['artifact_store_identity'], $intent['storage_identity'] )
            || ! self::valid_validation_contract_version( $intent['validation_contract_version'] )
            || ! self::nonnegative_int( $intent['created_at'] )
            || ! self::worker_deadlines_ordered( $intent['created_at'], $intent['upload_until'], $intent['accept_until'], $intent['validation_until'], $intent['staged_delete_after'] )
            || $intent['created_at'] < $manifest['created_at']
            || $intent['accept_until'] !== $manifest['accept_until']
            || $intent['staged_delete_after'] !== $manifest['delete_after']
        ) {
            return false;
        }
        $mime_policy = UploadPolicy::policy_for_tokens( $manifest['policy']['accept'], 'staged' );
        return UploadPolicy::mime_allowed( $intent['declared_mime'], UploadPolicy::extension_from_name( $intent['display_name'] ), $mime_policy );
    }

    private static function valid_manifest_item( $upload_id, $item, $manifest ) {
        $policy = isset( $manifest['policy'] ) && is_array( $manifest['policy'] ) ? $manifest['policy'] : array();
        $expected_keys = array( 'accepted_at', 'bytes', 'display_name', 'height', 'mime', 'object_key', 'object_version', 'ordinal', 'upload_id', 'width' );
        if ( ! self::valid_upload_id( $upload_id )
            || ! is_array( $item )
            || ! self::exact_keys( $item, $expected_keys )
            || $item['upload_id'] !== $upload_id
            || ! self::nonnegative_int( $item['ordinal'] )
            || ! self::valid_display_name( $item['display_name'] )
            || ! self::nonnegative_int( $item['bytes'] )
            || $item['bytes'] < 1
            || $item['bytes'] > $policy['max_file_bytes']
            || ! self::valid_object_identity( $item['object_key'], $item['object_version'] )
            || ! ManagedArtifactKey::matches( $item['object_key'], $manifest['batch_id'], $item['ordinal'], $item['mime'] )
            || ! is_int( $item['width'] )
            || ! is_int( $item['height'] )
            || ! UploadPolicy::staged_dimensions_allowed( $item['width'], $item['height'] )
            || ! self::nonnegative_int( $item['accepted_at'] )
        ) {
            return false;
        }
        $mime_policy = UploadPolicy::policy_for_tokens( $policy['accept'], 'staged' );
        return is_string( $item['mime'] )
            && UploadPolicy::mime_allowed( $item['mime'], UploadPolicy::extension_from_name( $item['display_name'] ), $mime_policy );
    }

    private static function valid_worker_manifest_item( $upload_id, $item, $manifest ) {
        $expected_keys = array(
            'bytes', 'display_name', 'etag', 'intent_id', 'object_key', 'object_version', 'ordinal',
            'policy_fingerprint', 'staged_delete_after', 'storage_identity', 'upload_id',
            'validation_contract_version', 'validation_until',
        );
        $parts = is_array( $item ) && isset( $item['object_key'] ) ? ManagedArtifactKey::parse( $item['object_key'] ) : null;
        return self::valid_upload_id( $upload_id )
            && is_array( $item )
            && self::exact_keys( $item, $expected_keys )
            && $item['upload_id'] === $upload_id
            && ! isset( $item['mime'], $item['width'], $item['height'], $item['accepted_at'] )
            && self::valid_intent_id( $item['intent_id'] )
            && self::nonnegative_int( $item['ordinal'] )
            && self::valid_display_name( $item['display_name'] )
            && self::nonnegative_int( $item['bytes'] )
            && $item['bytes'] > 0
            && $item['bytes'] <= $manifest['policy']['max_file_bytes']
            && self::valid_object_identity( $item['object_key'], $item['object_version'] )
            && self::valid_known_worker_provider_fact( $item['object_version'] )
            && is_array( $parts )
            && $parts['namespace'] === $manifest['batch_id']
            && $parts['ordinal'] === $item['ordinal']
            && hash_equals( $parts['intent_id'], $item['intent_id'] )
            && self::valid_known_worker_provider_fact( $item['etag'] )
            && is_string( $item['policy_fingerprint'] )
            && hash_equals( $manifest['binding']['policy_fingerprint'], $item['policy_fingerprint'] )
            && hash_equals( $manifest['artifact_store_identity'], $item['storage_identity'] )
            && self::valid_validation_contract_version( $item['validation_contract_version'] )
            && self::nonnegative_int( $item['validation_until'] )
            && self::nonnegative_int( $item['staged_delete_after'] )
            && $item['validation_until'] < $item['staged_delete_after']
            && (
                $item['staged_delete_after'] === $manifest['delete_after']
                || $manifest['state'] === 'finalized'
            );
    }

    private static function valid_manifest_tombstone( $upload_id, $tombstone, $manifest ) {
        $expected_keys = array( 'bytes', 'capacity_release_started', 'capacity_released', 'deleted_at', 'object_key', 'object_version' );
        if ( ! self::valid_upload_id( $upload_id )
            || ! is_array( $tombstone )
            || ! self::exact_keys( $tombstone, $expected_keys )
            || ! self::nonnegative_int( $tombstone['deleted_at'] )
            || ! self::nonnegative_int( $tombstone['bytes'] )
            || ! is_string( $tombstone['object_key'] )
            || ! is_string( $tombstone['object_version'] )
            || ( $tombstone['object_key'] === '' ) !== ( $tombstone['bytes'] === 0 )
            || ( $tombstone['object_key'] !== '' && ! self::valid_object_identity( $tombstone['object_key'], $tombstone['object_version'], true ) )
            || ( $tombstone['object_key'] !== '' && ! ManagedArtifactKey::matches( $tombstone['object_key'], $manifest['batch_id'] ) )
            || ! is_bool( $tombstone['capacity_release_started'] )
            || ! is_bool( $tombstone['capacity_released'] )
            || ( $tombstone['capacity_released'] && ! $tombstone['capacity_release_started'] )
        ) {
            return false;
        }
        return true;
    }

    private static function valid_worker_manifest_tombstone( $upload_id, $tombstone, $manifest ) {
        $expected_keys = array(
            'bytes', 'capacity_release_started', 'capacity_released', 'deleted_at', 'etag', 'object_key',
            'object_version', 'policy_fingerprint', 'storage_identity', 'validation_contract_version', 'validation_until',
        );
        $parts = is_array( $tombstone ) && isset( $tombstone['object_key'] ) ? ManagedArtifactKey::parse( $tombstone['object_key'] ) : null;
        $empty = is_array( $tombstone )
            && isset( $tombstone['bytes'], $tombstone['object_key'], $tombstone['object_version'], $tombstone['etag'], $tombstone['capacity_release_started'], $tombstone['capacity_released'] )
            && $tombstone['bytes'] === 0
            && $tombstone['object_key'] === ''
            && $tombstone['object_version'] === '-'
            && $tombstone['etag'] === '-'
            && $tombstone['capacity_release_started'] === true
            && $tombstone['capacity_released'] === true;
        if ( ! self::valid_upload_id( $upload_id )
            || ! is_array( $tombstone )
            || ! self::exact_keys( $tombstone, $expected_keys )
            || ! self::nonnegative_int( $tombstone['deleted_at'] )
            || ! self::nonnegative_int( $tombstone['bytes'] )
            || ( ! $empty && $tombstone['bytes'] < 1 )
            || $tombstone['bytes'] > $manifest['policy']['max_file_bytes']
            || ( ! $empty && ( ! is_array( $parts ) || $parts['namespace'] !== $manifest['batch_id'] ) )
            || ( ! $empty && ! self::valid_worker_tombstone_object_pair( $tombstone['object_key'], $tombstone['object_version'], $tombstone['etag'] ) )
            || ! is_string( $tombstone['policy_fingerprint'] )
            || ! hash_equals( $manifest['binding']['policy_fingerprint'], $tombstone['policy_fingerprint'] )
            || ! is_string( $tombstone['storage_identity'] )
            || ! hash_equals( $manifest['artifact_store_identity'], $tombstone['storage_identity'] )
            || ! self::valid_validation_contract_version( $tombstone['validation_contract_version'] )
            || ! self::nonnegative_int( $tombstone['validation_until'] )
            || $tombstone['validation_until'] <= $manifest['created_at']
            || (
                $manifest['delete_after'] !== null
                && in_array( $manifest['state'], array( 'open', 'finalizing' ), true )
                && $tombstone['validation_until'] >= $manifest['delete_after']
            )
            || ! is_bool( $tombstone['capacity_release_started'] )
            || ! is_bool( $tombstone['capacity_released'] )
            || ( $tombstone['capacity_released'] && ! $tombstone['capacity_release_started'] )
        ) {
            return false;
        }
        return true;
    }

    private static function valid_worker_tombstone_object_pair( $object_key, $object_version, $etag ) {
        if ( ! ManagedArtifactKey::valid( $object_key ) || ! is_string( $object_version ) || ! is_string( $etag ) ) {
            return false;
        }
        if ( $object_version === '-' || $etag === '-' ) {
            return $object_version === '-' && $etag === '-';
        }
        return self::valid_known_worker_provider_fact( $object_version )
            && self::valid_known_worker_provider_fact( $etag );
    }

    private static function valid_known_worker_provider_fact( $value ) {
        return is_string( $value )
            && $value !== '-'
            && preg_match( '/^[A-Za-z0-9._:-]{1,' . Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) . '}$/D', $value ) === 1;
    }

    private static function worker_capacity_missing_for_remote_state( $capacity_path, $manifest ) {
        if ( ! is_array( $manifest )
            || ! isset( $manifest['artifact_store'] )
            || $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
            || ! is_string( $capacity_path )
            || $capacity_path === ''
        ) {
            return false;
        }
        if ( ! is_link( $capacity_path ) && is_file( $capacity_path ) ) {
            return false;
        }
        return ! empty( $manifest['intents'] )
            || ! empty( $manifest['items'] )
            || ! empty( $manifest['tombstones'] );
    }

    private static function worker_capacity_invalid_for_manifest( $private_dir, $record, $manifest, $manifest_family ) {
        return ! is_array( $record )
            || ! self::worker_capacity_consistent_for_manifest( $private_dir, $record, $manifest, $manifest_family );
    }

    private static function worker_capacity_globally_consistent( $private_dir, $record ) {
        if ( ! is_array( $record ) ) {
            return false;
        }
        $health = self::mixed_capacity_health( $private_dir, $record, array(), false );
        return is_array( $health ) && ! empty( $health['consistent'] );
    }

    private static function worker_capacity_record_has_remote_state( $record, $remote ) {
        if ( is_array( $remote ) && ! empty( $remote['authorities'] ) ) {
            return true;
        }
        if ( ! is_array( $record ) || ! isset( $record['reservations'] ) || ! is_array( $record['reservations'] ) ) {
            return false;
        }
        foreach ( $record['reservations'] as $reservation ) {
            if ( is_array( $reservation )
                && isset( $reservation['artifact_store'] )
                && $reservation['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER
            ) {
                return true;
            }
        }
        return false;
    }

    private static function valid_manifest_state( $manifest ) {
        if ( $manifest['state'] === 'open' ) {
            return ! isset( $manifest['claim'] )
                && ! isset( $manifest['finalized_at'] )
                && ! isset( $manifest['email_attempted_at'] );
        }
        if ( ! isset( $manifest['claim'] )
            || ! is_array( $manifest['claim'] )
            || ! self::exact_keys( $manifest['claim'], array( 'claimed_at', 'submission_id' ) )
            || ! isset( $manifest['claim']['submission_id'], $manifest['claim']['claimed_at'] )
            || ! self::valid_submission_id( $manifest['claim']['submission_id'] )
            || ! self::nonnegative_int( $manifest['claim']['claimed_at'] )
            || $manifest['claim']['claimed_at'] < $manifest['created_at']
            || $manifest['claim']['claimed_at'] >= $manifest['accept_until']
        ) {
            return false;
        }
        if ( $manifest['state'] === 'finalizing' ) {
            return empty( $manifest['intents'] )
                && ! isset( $manifest['finalized_at'] )
                && ! isset( $manifest['email_attempted_at'] );
        }
        if ( $manifest['state'] === 'operator_deleted' && ! empty( $manifest['items'] ) ) {
            return false;
        }
        return empty( $manifest['intents'] )
            && isset( $manifest['finalized_at'] )
            && self::nonnegative_int( $manifest['finalized_at'] )
            && $manifest['finalized_at'] >= $manifest['claim']['claimed_at']
            && ( $manifest['delete_after'] === null || $manifest['delete_after'] >= $manifest['finalized_at'] )
            && ( ! isset( $manifest['email_attempted_at'] )
                || ( self::nonnegative_int( $manifest['email_attempted_at'] ) && $manifest['email_attempted_at'] >= $manifest['finalized_at'] )
            );
    }

    private static function exact_keys( $value, $expected ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $expected, SORT_STRING );
        return $actual === $expected;
    }

    private static function nonnegative_int( $value ) {
        return is_int( $value ) && $value >= 0;
    }

    private static function read_capacity( $path, $_private_dir = '', $_require_empty_consistency = false ) {
        return ManagedCapacityStore::read( $path, self::CAPACITY_VERSION );
    }

    private static function batch_summary( $manifest ) {
        $intents = array();
        foreach ( $manifest['intents'] as $intent ) {
            if ( is_array( $intent ) ) {
                $intents[] = array(
                    'upload_id' => $intent['upload_id'],
                    'ordinal' => (int) $intent['ordinal'],
                    'display_name' => $intent['display_name'],
                    'bytes' => (int) $intent['declared_bytes'],
                );
            }
        }
        usort( $intents, function ( $left, $right ) {
            return $left['ordinal'] <=> $right['ordinal'];
        } );

        return array(
            'batch_id' => $manifest['batch_id'],
            'artifact_store' => $manifest['artifact_store'],
            'artifact_store_identity' => $manifest['artifact_store_identity'],
            'state' => $manifest['state'] === 'open' ? 'open' : 'finalizing',
            'accept_until' => (int) $manifest['accept_until'],
            'delete_after' => (int) $manifest['delete_after'],
            'items' => self::item_summaries( $manifest ),
            'intents' => $intents,
            'limits' => array(
                'max_file_bytes' => (int) $manifest['policy']['max_file_bytes'],
                'max_files' => (int) $manifest['policy']['max_files'],
                'max_total_bytes' => (int) $manifest['policy']['max_total_bytes'],
            ),
        );
    }

    private static function manifest_summary( $manifest ) {
        return isset( $manifest['version'] ) && $manifest['version'] === self::WORKER_MANIFEST_VERSION
            ? self::worker_batch_summary( $manifest )
            : self::batch_summary( $manifest );
    }

    private static function item_summaries( $manifest ) {
        $items = array();
        foreach ( $manifest['items'] as $item ) {
            if ( is_array( $item ) ) {
                $items[] = self::item_summary( $item );
            }
        }
        usort( $items, function ( $left, $right ) {
            return $left['ordinal'] <=> $right['ordinal'];
        } );
        return $items;
    }

    private static function item_summary( $item ) {
        return array(
            'upload_id' => $item['upload_id'],
            'ordinal' => (int) $item['ordinal'],
            'display_name' => $item['display_name'],
            'bytes' => (int) $item['bytes'],
            'mime' => $item['mime'],
            'width' => (int) $item['width'],
            'height' => (int) $item['height'],
        );
    }

    private static function worker_batch_summary( $manifest ) {
        $intents = array();
        foreach ( $manifest['intents'] as $intent ) {
            if ( is_array( $intent ) ) {
                $intents[] = self::worker_intent_summary( $intent );
            }
        }
        usort( $intents, function ( $left, $right ) {
            return $left['ordinal'] <=> $right['ordinal'];
        } );

        return array(
            'batch_id' => $manifest['batch_id'],
            'artifact_store' => $manifest['artifact_store'],
            'artifact_store_identity' => $manifest['artifact_store_identity'],
            'state' => $manifest['state'] === 'open' ? 'open' : 'finalizing',
            'accept_until' => (int) $manifest['accept_until'],
            'delete_after' => (int) $manifest['delete_after'],
            'items' => self::worker_item_summaries( $manifest ),
            'intents' => $intents,
            'limits' => array(
                'max_file_bytes' => (int) $manifest['policy']['max_file_bytes'],
                'max_files' => (int) $manifest['policy']['max_files'],
                'max_total_bytes' => (int) $manifest['policy']['max_total_bytes'],
            ),
        );
    }

    private static function worker_item_summaries( $manifest ) {
        $items = array();
        foreach ( $manifest['items'] as $item ) {
            if ( is_array( $item ) ) {
                $items[] = self::worker_item_summary( $item );
            }
        }
        usort( $items, function ( $left, $right ) {
            return $left['ordinal'] <=> $right['ordinal'];
        } );
        return $items;
    }

    private static function worker_item_summary( $item ) {
        return array(
            'upload_id' => $item['upload_id'],
            'intent_id' => $item['intent_id'],
            'ordinal' => (int) $item['ordinal'],
            'display_name' => $item['display_name'],
            'bytes' => (int) $item['bytes'],
            'object_key' => $item['object_key'],
            'object_version' => $item['object_version'],
            'etag' => $item['etag'],
            'policy_fingerprint' => $item['policy_fingerprint'],
            'storage_identity' => $item['storage_identity'],
            'validation_contract_version' => $item['validation_contract_version'],
            'validation_until' => (int) $item['validation_until'],
            'staged_delete_after' => (int) $item['staged_delete_after'],
        );
    }

    private static function resolved_items( $manifest ) {
        $items = array();
        foreach ( self::item_summaries( $manifest ) as $item ) {
            $value = UploadValue::staged_item( $item );
            if ( ! empty( $value ) ) {
                $items[] = $value;
            }
        }
        return $items;
    }

    private static function worker_resolved_items( $manifest ) {
        $items = array();
        foreach ( self::worker_item_summaries( $manifest ) as $item ) {
            $value = UploadValue::review_staged_item( $item );
            if ( ! empty( $value ) ) {
                $items[] = $value;
            }
        }
        return $items;
    }

    private static function submission_summary( $manifest ) {
        return array(
            'submission_id' => $manifest['claim']['submission_id'],
            'artifact_store' => $manifest['artifact_store'],
            'artifact_store_identity' => $manifest['artifact_store_identity'],
            'finalized_at' => (int) $manifest['finalized_at'],
            'delete_after' => $manifest['delete_after'] === null ? null : (int) $manifest['delete_after'],
            'items' => self::item_summaries( $manifest ),
            'email_attempted_at' => isset( $manifest['email_attempted_at'] ) ? (int) $manifest['email_attempted_at'] : null,
        );
    }

    private static function worker_submission_summary( $manifest ) {
        return array(
            'submission_id' => $manifest['claim']['submission_id'],
            'artifact_store' => $manifest['artifact_store'],
            'artifact_store_identity' => $manifest['artifact_store_identity'],
            'finalized_at' => (int) $manifest['finalized_at'],
            'delete_after' => $manifest['delete_after'] === null ? null : (int) $manifest['delete_after'],
            'items' => self::worker_item_summaries( $manifest ),
            'email_attempted_at' => isset( $manifest['email_attempted_at'] ) ? (int) $manifest['email_attempted_at'] : null,
        );
    }

    private static function validation_contract_reference_counts( $manifest, $validation_contract_version ) {
        $counts = array(
            'references' => 0,
            'accepted_artifacts' => 0,
            'pending' => 0,
            'tombstones' => 0,
        );
        foreach ( array(
            'intents' => 'pending',
            'items' => 'accepted_artifacts',
            'tombstones' => 'tombstones',
        ) as $source => $counter ) {
            $records = isset( $manifest[ $source ] ) && is_array( $manifest[ $source ] ) ? $manifest[ $source ] : array();
            foreach ( $records as $record ) {
                if ( $source === 'tombstones' && self::worker_cancellation_tombstone_valid( $record ) ) {
                    continue;
                }
                if ( isset( $record['validation_contract_version'] )
                    && is_string( $record['validation_contract_version'] )
                    && hash_equals( $record['validation_contract_version'], $validation_contract_version )
                ) {
                    ++$counts['references'];
                    ++$counts[ $counter ];
                }
            }
        }
        return $counts;
    }

    private static function validation_retirement_marker_ready_summary( $result ) {
        return array(
            'scanned' => isset( $result['scanned'] ) ? (int) $result['scanned'] : 0,
            'references' => isset( $result['references'] ) ? (int) $result['references'] : 0,
            'accepted_artifacts' => isset( $result['accepted_artifacts'] ) ? (int) $result['accepted_artifacts'] : 0,
            'pending' => isset( $result['pending'] ) ? (int) $result['pending'] : 0,
            'tombstones' => isset( $result['tombstones'] ) ? (int) $result['tombstones'] : 0,
            'by_family' => isset( $result['by_family'] ) && is_array( $result['by_family'] )
                ? array(
                    'staged' => isset( $result['by_family']['staged'] ) ? (int) $result['by_family']['staged'] : 0,
                    'finalized' => isset( $result['by_family']['finalized'] ) ? (int) $result['by_family']['finalized'] : 0,
                    'capacity' => isset( $result['by_family']['capacity'] ) ? (int) $result['by_family']['capacity'] : 0,
                )
                : array( 'staged' => 0, 'finalized' => 0, 'capacity' => 0 ),
        );
    }

    private static function validation_retirement_marker_status( $private_dir, $validation_contract_version ) {
        if ( ! self::valid_validation_contract_version( $validation_contract_version )
            || ! is_string( $private_dir )
            || $private_dir === ''
            || is_link( $private_dir )
            || ! is_dir( $private_dir )
        ) {
            return array( 'ok' => false, 'retiring' => true );
        }
        $dir = rtrim( $private_dir, '/\\' ) . '/' . self::VALIDATION_RETIREMENT_DIR;
        if ( ! file_exists( $dir ) && ! is_link( $dir ) ) {
            return array( 'ok' => true, 'retiring' => false );
        }
        if ( is_link( $dir ) || ! is_dir( $dir ) ) {
            return array( 'ok' => false, 'retiring' => true );
        }
        $path = self::validation_retirement_marker_path( $private_dir, $validation_contract_version );
        if ( $path === '' ) {
            return array( 'ok' => false, 'retiring' => true );
        }
        if ( ! file_exists( $path ) && ! is_link( $path ) ) {
            return array( 'ok' => true, 'retiring' => false );
        }
        if ( is_link( $path ) || ! is_file( $path ) ) {
            return array( 'ok' => false, 'retiring' => true );
        }
        return array( 'ok' => true, 'retiring' => true );
    }

    private static function validation_retirement_marker_path_for_lease( $lifecycle, $validation_contract_version, $create ) {
        if ( ! $lifecycle instanceof PrivateDirLease || ! $lifecycle->exclusive() || ! self::valid_validation_contract_version( $validation_contract_version ) ) {
            return '';
        }
        $dir = PrivateDir::leased_subdir( $lifecycle, self::VALIDATION_RETIREMENT_DIR, $create );
        if ( $dir === '' ) {
            return '';
        }
        return rtrim( $dir, '/\\' ) . '/' . hash( 'sha256', $validation_contract_version ) . '.json';
    }

    private static function validation_retirement_marker_path( $private_dir, $validation_contract_version ) {
        if ( ! self::valid_validation_contract_version( $validation_contract_version )
            || ! is_string( $private_dir )
            || $private_dir === ''
            || is_link( $private_dir )
            || ! is_dir( $private_dir )
        ) {
            return '';
        }
        return rtrim( $private_dir, '/\\' ) . '/' . self::VALIDATION_RETIREMENT_DIR . '/' . hash( 'sha256', $validation_contract_version ) . '.json';
    }

    private static function read_validation_retirement_marker( $path, $validation_contract_version ) {
        $marker = self::read_json( $path );
        if ( ! is_array( $marker )
            || ! isset( $marker['version'], $marker['validation_contract_version'], $marker['state'], $marker['started_at'], $marker['updated_at'] )
            || $marker['version'] !== self::VALIDATION_RETIREMENT_VERSION
            || ! is_string( $marker['validation_contract_version'] )
            || ! hash_equals( $marker['validation_contract_version'], $validation_contract_version )
            || ! in_array( $marker['state'], array( 'active', 'ready' ), true )
            || ! self::nonnegative_int( $marker['started_at'] )
            || ! self::nonnegative_int( $marker['updated_at'] )
            || $marker['updated_at'] < $marker['started_at']
        ) {
            return null;
        }
        if ( $marker['state'] === 'ready' ) {
            if ( ! isset( $marker['ready_at'], $marker['ready'] )
                || ! self::nonnegative_int( $marker['ready_at'] )
                || $marker['ready_at'] < $marker['started_at']
                || ! self::valid_validation_retirement_ready_marker( $marker['ready'] )
            ) {
                return null;
            }
        }
        return $marker;
    }

    private static function valid_validation_retirement_ready_marker( $ready ) {
        if ( ! is_array( $ready )
            || array_keys( $ready ) !== array( 'scanned', 'references', 'accepted_artifacts', 'pending', 'tombstones', 'by_family' )
            || ! self::nonnegative_int( $ready['scanned'] )
            || ! self::nonnegative_int( $ready['references'] )
            || ! self::nonnegative_int( $ready['accepted_artifacts'] )
            || ! self::nonnegative_int( $ready['pending'] )
            || ! self::nonnegative_int( $ready['tombstones'] )
            || ! is_array( $ready['by_family'] )
            || array_keys( $ready['by_family'] ) !== array( 'staged', 'finalized', 'capacity' )
        ) {
            return false;
        }
        foreach ( $ready['by_family'] as $count ) {
            if ( ! self::nonnegative_int( $count ) ) {
                return false;
            }
        }
        return $ready['references'] === 0
            && $ready['accepted_artifacts'] === 0
            && $ready['pending'] === 0
            && $ready['tombstones'] === 0
            && $ready['by_family']['staged'] === 0
            && $ready['by_family']['finalized'] === 0
            && $ready['by_family']['capacity'] === 0;
    }

    private static function retained_photo_submission_row( $path, $now ) {
        $path = is_string( $path ) ? rtrim( $path, '/\\' ) : '';
        if ( $path === '' || is_link( $path ) || ! is_dir( $path ) ) {
            return null;
        }

        $submission_id = basename( $path );
        $lock = self::acquire_existing_lock( self::aggregate_lock_path( self::SUBMISSIONS_DIR, $path ) );
        if ( $lock === false ) {
            return null;
        }

        $manifest = self::read_aggregate_manifest( $path . '/' . self::MANIFEST_FILENAME, 'submission', $submission_id );
        if ( $manifest === null || $manifest['state'] !== 'finalized' ) {
            self::release_lock( $lock );
            return null;
        }
        if ( empty( $manifest['items'] ) ) {
            self::release_lock( $lock );
            return null;
        }

        $snapshot = self::read_review_snapshot_file( $path . '/' . self::REVIEW_SNAPSHOT_FILENAME );
        $validated = SubmissionReviewSnapshot::validate( $snapshot );
        if ( empty( $validated['ok'] ) || ! self::review_snapshot_matches_manifest( $validated['snapshot'], $manifest, $submission_id ) ) {
            self::release_lock( $lock );
            return null;
        }
        $summary = SubmissionReviewSnapshot::summary( $validated['snapshot'] );

        $delete_after = $manifest['delete_after'] === null ? null : (int) $manifest['delete_after'];
        $finalized_at = (int) $manifest['finalized_at'];
        $row = array(
            'submission_id' => $manifest['claim']['submission_id'],
            'submitted_at' => $finalized_at,
            'submitted_label' => self::timestamp_label( $finalized_at ),
            'photo_count' => count( $manifest['items'] ),
            'availability' => array(
                'delete_after' => $delete_after,
                'label' => $delete_after === null ? 'until deleted' : self::timestamp_label( $delete_after ),
                'expired' => ! self::finalized_available( $manifest, $now ),
            ),
            'view' => array(
                'submission_id' => $manifest['claim']['submission_id'],
            ),
            'summary' => $summary['summary'],
        );
        self::release_lock( $lock );
        return $row;
    }

    private static function read_aggregate_manifest( $path, $aggregate_family, $aggregate_id ) {
        $worker_version = self::worker_manifest_file_version( $path );
        if ( $worker_version === self::WORKER_MANIFEST_VERSION ) {
            return self::read_worker_manifest( $path, $aggregate_family, $aggregate_id );
        }
        $manifest = self::read_manifest( $path, $aggregate_family, $aggregate_id );
        return is_array( $manifest ) && $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_LOCAL
            ? $manifest
            : null;
    }

    private static function timestamp_label( $timestamp ) {
        $timestamp = (int) $timestamp;
        if ( function_exists( 'wp_date' ) ) {
            return wp_date( 'F j, Y \a\t g:i a', $timestamp );
        }
        return gmdate( 'F j, Y \a\t g:i a', $timestamp );
    }

    private static function finalized_available( $manifest, $now ) {
        if ( ! is_array( $manifest ) || ! isset( $manifest['state'] ) || $manifest['state'] !== 'finalized' || ! array_key_exists( 'delete_after', $manifest ) ) {
            return false;
        }
        if ( $manifest['delete_after'] === null ) {
            return true;
        }
        return self::nonnegative_int( $manifest['delete_after'] ) && (int) $now < $manifest['delete_after'];
    }

    private static function binding_matches( $manifest, $form_id, $instance_id, $token_digest, $field_key, $policy_fingerprint ) {
        $binding = $manifest['binding'];
        return isset( $binding['form_id'], $binding['instance_id'], $binding['token_digest'], $binding['field_key'], $binding['policy_fingerprint'] )
            && hash_equals( $binding['form_id'], $form_id )
            && hash_equals( $binding['instance_id'], $instance_id )
            && hash_equals( $binding['token_digest'], $token_digest )
            && hash_equals( $binding['field_key'], $field_key )
            && hash_equals( $binding['policy_fingerprint'], $policy_fingerprint );
    }

    private static function secret_matches( $manifest, $secret ) {
        $digest = self::secret_digest( $secret );
        return $digest !== '' && hash_equals( $manifest['batch_secret_digest'], $digest );
    }

    private static function secret_digest( $secret ) {
        if ( ! is_string( $secret ) || preg_match( FormProtocol::upload_batch_secret_pattern(), $secret ) !== 1 ) {
            return '';
        }
        $padding = str_repeat( '=', ( 4 - strlen( $secret ) % 4 ) % 4 );
        $decoded = base64_decode( strtr( $secret, '-_', '+/' ) . $padding, true );
        return is_string( $decoded ) && strlen( $decoded ) === Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ? hash( 'sha256', $decoded ) : '';
    }

    private static function valid_staged_policy( $policy ) {
        return $policy['upload_mode'] === 'staged'
            && UploadPolicy::staged_tokens_allowed( $policy['accept'] )
            && $policy['max_file_bytes'] > 0
            && $policy['max_files'] > 0
            && $policy['max_total_bytes'] >= $policy['max_file_bytes']
            && $policy['max_file_bytes'] <= intdiv( PHP_INT_MAX, $policy['max_files'] )
            && $policy['max_total_bytes'] <= $policy['max_file_bytes'] * $policy['max_files'];
    }

    private static function worker_policy_within_staged_ceiling( $policy ) {
        return is_array( $policy )
            && isset( $policy['max_files'] )
            && is_int( $policy['max_files'] )
            && $policy['max_files'] <= Anchors::get( 'MANAGED_STAGED_MAX_FILES' );
    }

    private static function valid_artifact_store( $artifact_store ) {
        return in_array(
            $artifact_store,
            array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ),
            true
        );
    }

    private static function review_snapshot_matches_manifest( $snapshot, $manifest, $submission_id ) {
        if ( ! is_array( $snapshot ) || ! is_array( $manifest ) || ! isset( $snapshot['submission_id'] ) || ! is_string( $snapshot['submission_id'] ) ) {
            return false;
        }
        $manifest_submission_id = isset( $manifest['claim']['submission_id'] ) && is_string( $manifest['claim']['submission_id'] )
            ? $manifest['claim']['submission_id']
            : '';
        if ( $manifest_submission_id === '' || ! hash_equals( $manifest_submission_id, (string) $submission_id ) || ! hash_equals( $manifest_submission_id, $snapshot['submission_id'] ) ) {
            return false;
        }

        $manifest_form_id = isset( $manifest['binding']['form_id'] ) && is_string( $manifest['binding']['form_id'] )
            ? $manifest['binding']['form_id']
            : '';
        return $manifest_form_id === ''
            || ( isset( $snapshot['form_id'] ) && is_string( $snapshot['form_id'] ) && hash_equals( $manifest_form_id, $snapshot['form_id'] ) );
    }

    private static function valid_artifact_store_identity( $artifact_store, $identity ) {
        if ( ! is_string( $identity ) ) {
            return false;
        }
        return $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL
            ? $identity === self::LOCAL_ARTIFACT_STORE_IDENTITY
            : preg_match( '/^[0-9a-f]{64}$/', $identity ) === 1;
    }

    private static function valid_validation_contract_version( $version ) {
        return is_string( $version )
            && preg_match( '/^[A-Za-z0-9._:-]{1,' . Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) . '}$/D', $version ) === 1;
    }

    private static function worker_deadlines_ordered( $now, $upload_until, $accept_until, $validation_until, $staged_delete_after ) {
        return self::nonnegative_int( $now )
            && self::nonnegative_int( $upload_until )
            && self::nonnegative_int( $accept_until )
            && self::nonnegative_int( $validation_until )
            && self::nonnegative_int( $staged_delete_after )
            && $now < $upload_until
            && $upload_until < min( $accept_until, $validation_until )
            && $validation_until < $staged_delete_after;
    }

    private static function worker_committed_retry_deadlines_ordered( $now, $upload_until, $accept_until, $validation_until, $staged_delete_after ) {
        return self::nonnegative_int( $now )
            && self::nonnegative_int( $upload_until )
            && self::nonnegative_int( $accept_until )
            && self::nonnegative_int( $validation_until )
            && self::nonnegative_int( $staged_delete_after )
            && $now < $accept_until
            && $upload_until < min( $accept_until, $validation_until )
            && $validation_until < $staged_delete_after;
    }

    private static function valid_upload_id( $upload_id ) {
        return is_string( $upload_id ) && preg_match( FormProtocol::managed_id_pattern(), $upload_id ) === 1;
    }

    private static function valid_submission_id( $submission_id ) {
        return is_string( $submission_id ) && preg_match( FormProtocol::managed_id_pattern(), $submission_id ) === 1;
    }

    private static function member_path( $aggregate, $relpath ) {
        if ( ! is_string( $relpath ) || $relpath === '' || strpos( $relpath, '..' ) !== false || strpos( $relpath, '\\' ) !== false || $relpath[0] === '/' ) {
            return '';
        }
        $base = rtrim( $aggregate, '/\\' );
        if ( $base === '' || is_link( $base ) || ! is_dir( $base ) ) {
            return '';
        }
        $parts = explode( '/', $relpath );
        $path = $base;
        foreach ( $parts as $index => $part ) {
            if ( $part === '' || $part === '.' || $part === '..' ) {
                return '';
            }
            $path .= '/' . $part;
            if ( is_link( $path ) ) {
                return '';
            }
            if ( $index < count( $parts ) - 1 && ! is_dir( $path ) ) {
                return '';
            }
        }
        return is_file( $path ) ? $path : '';
    }

    private static function managed_root( $uploads_dir, $name, $create, $lifecycle = null ) {
        $review = $name === self::SUBMISSIONS_DIR;
        if ( $create ) {
            if ( ! $lifecycle instanceof PrivateDirLease ) {
                return '';
            }
            return $review
                ? PrivateDir::leased_review_subdir( $lifecycle, $name, true, true )
                : PrivateDir::leased_subdir( $lifecycle, $name, true, true );
        }
        return $review
            ? PrivateDir::protected_review_subdir( $uploads_dir, $name, false )
            : PrivateDir::protected_subdir( $uploads_dir, $name, false );
    }

    private static function reservation_materialization( $private_dir, $reservations ) {
        if ( ! is_array( $reservations ) || empty( $reservations ) ) {
            return array( 'committed' => array(), 'orphaned' => array() );
        }
        $wanted = array();
        foreach ( $reservations as $reservation_id => $reservation ) {
            if ( ! is_array( $reservation )
                || ! isset( $reservation['batch_id'], $reservation['upload_id'] )
                || ! is_string( $reservation['batch_id'] )
                || preg_match( FormProtocol::upload_batch_id_pattern(), $reservation['batch_id'] ) !== 1
                || ! self::valid_upload_id( $reservation['upload_id'] )
            ) {
                return null;
            }
            if ( ! isset( $wanted[ $reservation['batch_id'] ] ) ) {
                $wanted[ $reservation['batch_id'] ] = array();
            }
            if ( isset( $wanted[ $reservation['batch_id'] ][ $reservation['upload_id'] ] ) ) {
                return null;
            }
            $wanted[ $reservation['batch_id'] ][ $reservation['upload_id'] ] = $reservation_id;
        }

        $committed = array();
        $pending_deletes = array();
        $families = array(
            self::STAGED_DIR => 'staged',
            self::SUBMISSIONS_DIR => 'submission',
        );
        foreach ( $families as $root_name => $family ) {
            $root = rtrim( $private_dir, '/\\' ) . '/' . $root_name;
            if ( ! is_dir( $root ) ) {
                continue;
            }
            $cursor = array();
            do {
                $discovered = self::aggregate_paths( $root, max( 1, count( $wanted ) ), $cursor );
                if ( empty( $discovered['ok'] ) ) {
                    return null;
                }
                foreach ( $discovered['paths'] as $path ) {
                    $manifest_path = rtrim( $path, '/\\' ) . '/' . self::MANIFEST_FILENAME;
                    if ( ! is_file( $manifest_path ) ) {
                        continue;
                    }
                    $lock_path = self::aggregate_lock_path( $root_name, $path );
                    $lock = $root_name === self::STAGED_DIR
                        ? self::acquire_staged_lock( $path )
                        : self::acquire_lock( $lock_path );
                    if ( $lock === false ) {
                        return null;
                    }
                    $manifest = self::read_manifest( $manifest_path, $family, basename( rtrim( $path, '/\\' ) ) );
                    self::release_lock( $lock );
                    if ( $manifest === null ) {
                        return null;
                    }
                    if ( ! isset( $wanted[ $manifest['batch_id'] ] ) ) {
                        continue;
                    }
                    if ( $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
                        return null;
                    }
                    foreach ( $wanted[ $manifest['batch_id'] ] as $upload_id => $reservation_id ) {
                        if ( isset( $manifest['items'][ $upload_id ] ) ) {
                            if ( isset( $committed[ $reservation_id ] ) ) {
                                return null;
                            }
                            $item = $manifest['items'][ $upload_id ];
                            $committed[ $reservation_id ] = $item['bytes'];
                        } elseif ( isset( $manifest['tombstones'][ $upload_id ] )
                            && ! empty( $manifest['tombstones'][ $upload_id ]['capacity_release_started'] )
                            && empty( $manifest['tombstones'][ $upload_id ]['capacity_released'] )
                            && hash_equals( $manifest['tombstones'][ $upload_id ]['object_key'], $reservations[ $reservation_id ]['object_key'] )
                        ) {
                            $pending_deletes[ $reservation_id ] = $manifest['tombstones'][ $upload_id ]['bytes'];
                        }
                    }
                }
                $cursor = $discovered['cursor'];
            } while ( ! empty( $cursor ) );
        }
        $uploads_dir = basename( rtrim( $private_dir, '/\\' ) ) === PrivateDir::DIR_NAME ? dirname( rtrim( $private_dir, '/\\' ) ) : '';
        if ( $uploads_dir === '' ) {
            return null;
        }
        $orphaned = $pending_deletes;
        foreach ( $reservations as $reservation_id => $reservation ) {
            if ( isset( $committed[ $reservation_id ] ) || isset( $pending_deletes[ $reservation_id ] ) ) {
                continue;
            }
            $bytes = LocalArtifactStore::bytes_for_key( $uploads_dir, $reservation['object_key'] );
            if ( $bytes === null ) {
                return null;
            }
            if ( $bytes > 0 ) {
                $orphaned[ $reservation_id ] = $bytes;
            }
        }
        return array( 'committed' => $committed, 'orphaned' => $orphaned );
    }

    private static function mixed_capacity_health( $private_dir, $record, $manifest_overrides = array(), $repair_directories = true ) {
        $remote = self::remote_capacity_authorities( $private_dir, $record, $manifest_overrides, $repair_directories );
        if ( $remote === null ) {
            return null;
        }
        $remote_authorities = $remote['authorities'];
        $remote_authority_bytes = $remote['bytes'];
        $artifact_stores = $remote['artifact_stores'];
        $artifact_store_identities = $remote['artifact_store_identities'];
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            $artifact_stores[ $reservation['artifact_store'] ] = true;
            $artifact_store_identities[ $reservation['artifact_store_identity'] ] = true;
            if ( $reservation['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
                || isset( $remote_authorities[ $reservation_id ] )
            ) {
                continue;
            }
            if ( $remote_authority_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                return null;
            }
            $remote_authority_bytes += $reservation['bytes'];
        }

        $local_reservations = array();
        $reserved_bytes = 0;
        $remote_committing_bytes = 0;
        $consistent = true;
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            if ( $reserved_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                return null;
            }
            $reserved_bytes += $reservation['bytes'];
            if ( $reservation['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
                $local_reservations[ $reservation_id ] = $reservation;
                continue;
            }
            $authority = isset( $remote_authorities[ $reservation_id ] ) ? $remote_authorities[ $reservation_id ] : null;
            if ( $authority === null ) {
                continue;
            }
            if ( ! ManagedCapacityStore::remote_reservation_matches( $reservation, $authority ) ) {
                $consistent = false;
                continue;
            }
            $remote_authorities[ $reservation_id ]['reserved'] = true;
            if ( $authority['kind'] === 'item' ) {
                $remote_committing_bytes += $authority['bytes'];
            }
        }
        foreach ( $remote_authorities as $authority ) {
            if ( $authority['requires_reservation'] && empty( $authority['reserved'] ) ) {
                $consistent = false;
            }
        }

        $materialization = self::reservation_materialization( $private_dir, $local_reservations );
        $local_file_bytes = self::managed_file_bytes( $private_dir );
        if ( $materialization === null || $local_file_bytes === null ) {
            return null;
        }
        $local_reserved_bytes = 0;
        foreach ( $local_reservations as $reservation ) {
            if ( $local_reserved_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                return null;
            }
            $local_reserved_bytes += $reservation['bytes'];
        }
        $local_committing_bytes = array_sum( $materialization['committed'] );
        $local_orphaned_bytes = array_sum( $materialization['orphaned'] );
        if ( $local_committing_bytes > PHP_INT_MAX - $local_orphaned_bytes ) {
            return null;
        }
        $local_materialized_bytes = $local_committing_bytes + $local_orphaned_bytes;
        if ( $local_materialized_bytes > $local_file_bytes
            || $local_file_bytes - $local_materialized_bytes > PHP_INT_MAX - $local_reserved_bytes
        ) {
            $consistent = false;
            $local_expected_bytes = 0;
        } else {
            $local_expected_bytes = $local_file_bytes - $local_materialized_bytes + $local_reserved_bytes;
        }
        if ( $local_expected_bytes > PHP_INT_MAX - $remote_authority_bytes ) {
            return null;
        }
        $consistent = $consistent
            && (int) $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_LOCAL ] === $local_expected_bytes
            && (int) $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] === $remote_authority_bytes
            && (int) $record['total_bytes'] === $local_expected_bytes + $remote_authority_bytes;
        $artifact_stores = array_keys( $artifact_stores );
        sort( $artifact_stores, SORT_STRING );
        $artifact_store_identities = array_keys( $artifact_store_identities );
        sort( $artifact_store_identities, SORT_STRING );
        return array(
            'total_bytes' => (int) $record['total_bytes'],
            'file_bytes' => $local_file_bytes,
            'authority_bytes' => $remote_authority_bytes,
            'reserved_bytes' => $reserved_bytes,
            'committing_bytes' => $local_committing_bytes + $remote_committing_bytes,
            'orphaned_bytes' => $local_orphaned_bytes,
            'consistent' => $consistent,
            'artifact_stores' => $artifact_stores,
            'artifact_store_identities' => $artifact_store_identities,
        );
    }

    private static function worker_capacity_consistent_for_manifest( $private_dir, $record, $manifest, $manifest_family ) {
        if ( ! is_array( $record )
            || ! is_array( $manifest )
            || ! isset( $manifest['batch_id'], $manifest['artifact_store'], $manifest['artifact_store_identity'] )
            || $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
            || ! isset( $record['reservations'], $record['releases'] )
            || ! is_array( $record['reservations'] )
            || ! is_array( $record['releases'] )
            || ! isset( $record['total_bytes'], $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] )
            || ! is_int( $record['total_bytes'] )
            || ! is_int( $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] )
        ) {
            return false;
        }
        if ( isset( $record['releases'][ $manifest['batch_id'] ] ) ) {
            $release = $record['releases'][ $manifest['batch_id'] ];
            if ( ! hash_equals( $release['artifact_store_identity'], $manifest['artifact_store_identity'] ) ) {
                return false;
            }
            $probe = $record;
            unset( $probe['releases'][ $manifest['batch_id'] ] );
            $probe['total_bytes'] = PHP_INT_MAX;
            $probe['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] = PHP_INT_MAX;
            $expected = self::release_remote_aggregate_capacity( $probe, $manifest, $release['created_at'] );
            return ! empty( $expected['ok'] ) && $release['bytes'] === $expected['released_bytes'];
        }

        $manifest_capacity = self::worker_manifest_capacity_authorities( $manifest );
        if ( $manifest_capacity === null ) {
            return false;
        }
        $worker_floor = 0;
        foreach ( $record['reservations'] as $reservation ) {
            if ( $reservation['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
                continue;
            }
            if ( $worker_floor > PHP_INT_MAX - $reservation['bytes'] ) {
                return false;
            }
            $worker_floor += $reservation['bytes'];
        }
        foreach ( $manifest_capacity['authorities'] as $reservation_id => $authority ) {
            if ( isset( $record['reservations'][ $reservation_id ] ) ) {
                if ( ! ManagedCapacityStore::remote_reservation_matches( $record['reservations'][ $reservation_id ], $authority ) ) {
                    return false;
                }
                continue;
            }
            if ( $authority['requires_reservation'] || $worker_floor > PHP_INT_MAX - $authority['bytes'] ) {
                return false;
            }
            $worker_floor += $authority['bytes'];
        }
        return $worker_floor <= $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ];
    }

    private static function worker_manifest_capacity_authorities( $manifest ) {
        if ( ! is_array( $manifest )
            || ! isset( $manifest['batch_id'], $manifest['artifact_store'], $manifest['artifact_store_identity'] )
            || $manifest['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
        ) {
            return null;
        }
        $authorities = array();
        $bytes = 0;
        foreach ( $manifest['items'] as $upload_id => $item ) {
            if ( ! self::add_remote_capacity_authority( $authorities, $bytes, $manifest['batch_id'], $upload_id, $item['bytes'], $item['object_key'], $manifest['artifact_store_identity'], false, 'item', self::worker_cleanup_fields_from_record( $item ) ) ) {
                return null;
            }
        }
        foreach ( $manifest['intents'] as $upload_id => $intent ) {
            if ( ! self::add_remote_capacity_authority( $authorities, $bytes, $manifest['batch_id'], $upload_id, $intent['reserved_bytes'], $intent['object_key'], $manifest['artifact_store_identity'], true, 'intent', self::worker_cleanup_fields_from_record( $intent ) ) ) {
                return null;
            }
        }
        foreach ( $manifest['tombstones'] as $upload_id => $tombstone ) {
            if ( self::worker_cancellation_tombstone_valid( $tombstone ) || ! empty( $tombstone['capacity_released'] ) ) {
                continue;
            }
            $requires_reservation = (int) $tombstone['bytes'] > 0 && ! empty( $tombstone['capacity_release_started'] );
            if ( ! self::add_remote_capacity_authority( $authorities, $bytes, $manifest['batch_id'], $upload_id, $tombstone['bytes'], $tombstone['object_key'], $manifest['artifact_store_identity'], $requires_reservation, 'tombstone', self::worker_cleanup_fields_from_record( $tombstone ) ) ) {
                return null;
            }
        }
        return array( 'authorities' => $authorities, 'bytes' => $bytes );
    }

    private static function remote_capacity_authorities( $private_dir, $record, $manifest_overrides = array(), $repair_directories = true ) {
        $authorities = array();
        $bytes = 0;
        $artifact_stores = array();
        $artifact_store_identities = array();
        $batches = array();
        $families = array(
            self::STAGED_DIR => 'staged',
            self::SUBMISSIONS_DIR => 'submission',
        );
        foreach ( $families as $root_name => $manifest_family ) {
            $root = rtrim( $private_dir, '/\\' ) . '/' . $root_name;
            if ( is_link( $root ) || ( file_exists( $root ) && ! is_dir( $root ) ) ) {
                return null;
            }
            if ( ! is_dir( $root ) ) {
                continue;
            }
            $cursor = array();
            do {
                $page = self::aggregate_paths( $root, Anchors::get( 'MANAGED_REMOTE_PURGE_AGGREGATE_PAGE_SIZE' ), $cursor, $repair_directories );
                if ( empty( $page['ok'] ) ) {
                    return null;
                }
                foreach ( $page['paths'] as $path ) {
                    $manifest_path = rtrim( $path, '/\\' ) . '/' . self::MANIFEST_FILENAME;
                    if ( ! is_file( $manifest_path ) ) {
                        if ( $root_name === self::STAGED_DIR && self::initializable_partial_batch( $path ) ) {
                            continue;
                        }
                        return null;
                    }
                    $aggregate_id = basename( $path );
                    $override_key = self::remote_capacity_authority_override_key( $root_name, $aggregate_id );
                    if ( isset( $manifest_overrides[ $override_key ] ) && is_array( $manifest_overrides[ $override_key ] ) ) {
                        $manifest = $manifest_overrides[ $override_key ];
                    } else {
                        $lock = null;
                        if ( $repair_directories ) {
                            $lock = $root_name === self::STAGED_DIR
                                ? self::acquire_staged_lock( $path )
                                : self::acquire_lock( self::aggregate_lock_path( $root_name, $path ) );
                            if ( $lock === false ) {
                                return null;
                            }
                        }
                        $manifest = self::read_worker_manifest( $manifest_path, $manifest_family, $aggregate_id, true, $repair_directories );
                        if ( $manifest === null ) {
                            $manifest = self::read_manifest( $manifest_path, $manifest_family, $aggregate_id, $repair_directories );
                        }
                        if ( is_resource( $lock ) ) {
                            self::release_lock( $lock );
                        }
                    }
                    if ( ! is_array( $manifest ) ) {
                        return null;
                    }
                    if ( $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER && $manifest['version'] !== self::WORKER_MANIFEST_VERSION ) {
                        return null;
                    }
                    $artifact_stores[ $manifest['artifact_store'] ] = true;
                    $artifact_store_identities[ $manifest['artifact_store_identity'] ] = true;
                    if ( $manifest['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
                        continue;
                    }
                    $batches[ $manifest['batch_id'] ] = true;
                    if ( isset( $record['releases'][ $manifest['batch_id'] ] ) ) {
                        if ( ! hash_equals(
                            $record['releases'][ $manifest['batch_id'] ]['artifact_store_identity'],
                            $manifest['artifact_store_identity']
                        ) ) {
                            return null;
                        }
                        $probe = $record;
                        unset( $probe['releases'][ $manifest['batch_id'] ] );
                        $probe['total_bytes'] = PHP_INT_MAX;
                        $probe['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] = PHP_INT_MAX;
                        $expected = self::release_remote_aggregate_capacity(
                            $probe,
                            $manifest,
                            $record['releases'][ $manifest['batch_id'] ]['created_at']
                        );
                        if ( empty( $expected['ok'] )
                            || $record['releases'][ $manifest['batch_id'] ]['bytes'] !== $expected['released_bytes']
                        ) {
                            return null;
                        }
                        continue;
                    }
                    $manifest_capacity = self::worker_manifest_capacity_authorities( $manifest );
                    if ( $manifest_capacity === null || $bytes > PHP_INT_MAX - $manifest_capacity['bytes'] ) {
                        return null;
                    }
                    foreach ( $manifest_capacity['authorities'] as $reservation_id => $authority ) {
                        if ( isset( $authorities[ $reservation_id ] ) ) {
                            return null;
                        }
                        $authorities[ $reservation_id ] = $authority;
                    }
                    $bytes += $manifest_capacity['bytes'];
                }
                $cursor = $page['cursor'];
            } while ( ! empty( $cursor ) );
        }
        return array(
            'authorities' => $authorities,
            'bytes' => $bytes,
            'artifact_stores' => $artifact_stores,
            'artifact_store_identities' => $artifact_store_identities,
            'batches' => $batches,
        );
    }

    private static function remote_capacity_authority_override_key( $root_name, $aggregate_id ) {
        return (string) $root_name . "\0" . (string) $aggregate_id;
    }

    private static function valid_preview_allocation_path( $lifecycle, $path ) {
        if ( ! is_string( $path ) || preg_match( '/^\.[0-9a-f-]+\.tmp$/D', basename( $path ) ) !== 1 ) {
            return false;
        }
        $private_dir = rtrim( $lifecycle->private_dir(), '/\\' );
        $root = $private_dir . '/' . self::PREVIEW_CACHE_DIR;
        $root_real = is_dir( $root ) && ! is_link( $root ) ? realpath( $root ) : false;
        $parent = dirname( $path );
        $parent_real = is_dir( $parent ) && ! is_link( $parent ) ? realpath( $parent ) : false;
        return is_string( $root_real )
            && is_string( $parent_real )
            && strncmp( $parent_real . '/', rtrim( $root_real, '/\\' ) . '/', strlen( rtrim( $root_real, '/\\' ) ) + 1 ) === 0
            && ! file_exists( $path )
            && ! is_link( $path );
    }

    private static function preallocate_file( $path, $bytes ) {
        $handle = @fopen( $path, 'xb' );
        if ( $handle === false ) {
            return false;
        }
        $chunk = str_repeat( "\0", 65536 );
        $remaining = $bytes;
        $ok = @chmod( $path, PrivateDir::FILE_MODE );
        while ( $ok && $remaining > 0 ) {
            $part = $remaining >= strlen( $chunk ) ? $chunk : substr( $chunk, 0, $remaining );
            $written = @fwrite( $handle, $part );
            $ok = is_int( $written ) && $written === strlen( $part );
            $remaining -= $ok ? $written : 0;
        }
        if ( $ok && function_exists( 'fflush' ) ) {
            $ok = @fflush( $handle );
        }
        fclose( $handle );
        if ( ! $ok ) {
            @unlink( $path );
        }
        return $ok;
    }

    private static function add_remote_capacity_authority( &$authorities, &$total, $batch_id, $upload_id, $bytes, $object_key, $artifact_store_identity, $requires_reservation, $kind, $worker_cleanup = array() ) {
        $reservation_id = self::reservation_id( $batch_id, $upload_id );
        $bytes = (int) $bytes;
        if ( isset( $authorities[ $reservation_id ] ) || $bytes < 0 || $total > PHP_INT_MAX - $bytes ) {
            return false;
        }
        $authority = array(
            'batch_id' => $batch_id,
            'upload_id' => $upload_id,
            'bytes' => $bytes,
            'object_key' => $object_key,
            'artifact_store_identity' => $artifact_store_identity,
            'requires_reservation' => (bool) $requires_reservation,
            'reserved' => false,
            'kind' => $kind,
        );
        if ( ! empty( $worker_cleanup ) ) {
            $authority = array_merge( $authority, $worker_cleanup );
        }
        $authorities[ $reservation_id ] = $authority;
        $total += $bytes;
        return true;
    }

    private static function worker_cleanup_fields_from_record( $record ) {
        if ( ! is_array( $record )
            || ! isset( $record['validation_contract_version'], $record['policy_fingerprint'], $record['validation_until'] )
        ) {
            return array();
        }
        return array(
            'validation_contract_version' => $record['validation_contract_version'],
            'policy_fingerprint' => $record['policy_fingerprint'],
            'validation_until' => $record['validation_until'],
        );
    }

    private static function remote_reservation_cleanup_ready( $reservation, $now, $stale_reservation_before ) {
        if ( ! is_array( $reservation )
            || ! isset( $reservation['created_at'] )
            || ! is_int( $reservation['created_at'] )
            || ! is_int( $now )
            || ! isset( $reservation['validation_until'] )
            || ! is_int( $reservation['validation_until'] )
        ) {
            return false;
        }
        $drain = Anchors::get( 'MANAGED_UPLOAD_INTENT_TTL_SECONDS' )
            + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' )
            + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
        $validation_drain = Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_WALL_SECONDS' )
            + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
        if ( $reservation['created_at'] > (int) $stale_reservation_before ) {
            return false;
        }
        return $reservation['created_at'] <= PHP_INT_MAX - $drain
            && $reservation['validation_until'] <= PHP_INT_MAX - $validation_drain
            && $now > max(
                $reservation['validation_until'] + $validation_drain,
                $reservation['created_at'] + $drain
            );
    }

    private static function managed_file_bytes( $private_dir ) {
        $private_dir = rtrim( (string) $private_dir, '/\\' );
        return basename( $private_dir ) === PrivateDir::DIR_NAME
            ? LocalArtifactStore::total_bytes( dirname( $private_dir ) )
            : null;
    }

    private static function free_bytes( $path, $options ) {
        if ( is_array( $options ) && array_key_exists( 'free_bytes', $options ) ) {
            $value = is_callable( $options['free_bytes'] ) ? call_user_func( $options['free_bytes'], $path ) : $options['free_bytes'];
        } else {
            $value = @disk_free_space( $path );
        }
        if ( ! is_int( $value ) && ! is_float( $value ) ) {
            return null;
        }
        if ( $value < 0 || $value > PHP_INT_MAX ) {
            return null;
        }
        return (int) $value;
    }

    private static function read_json( $path ) {
        if ( is_link( $path ) || ! is_file( $path ) ) {
            return null;
        }
        $json = @file_get_contents( $path );
        if ( ! is_string( $json ) || $json === '' ) {
            return null;
        }
        $value = json_decode( $json, true );
        return is_array( $value ) ? $value : null;
    }

    private static function read_review_snapshot_file( $path ) {
        return PrivateDir::ensure_existing_review_file( $path ) ? self::read_json( $path ) : null;
    }

    private static function write_remote_purge_record( $path, $value ) {
        return self::write_json_atomic( $path, $value, PrivateDir::FILE_MODE );
    }

    private static function write_json_atomic( $path, $value, $mode = PrivateDir::FILE_MODE ) {
        $json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $suffix = Entropy::hex( self::JSON_TEMP_ENTROPY_BYTES );
        if ( ! is_string( $json )
            || $suffix === ''
            || is_link( $path )
            || ! in_array( $mode, array( PrivateDir::FILE_MODE, PrivateDir::REVIEW_FILE_MODE ), true )
        ) {
            return false;
        }
        $temp = dirname( $path ) . '/.' . basename( $path ) . '.' . $suffix . '.tmp';
        $handle = @fopen( $temp, 'xb' );
        if ( $handle === false ) {
            return false;
        }
        $ok = self::write_all( $handle, $json );
        if ( $ok && function_exists( 'fflush' ) ) {
            $ok = @fflush( $handle );
        }
        fclose( $handle );
        if ( ! $ok || ! @chmod( $temp, $mode ) || ! @rename( $temp, $path ) ) {
            @unlink( $temp );
            return false;
        }
        return @chmod( $path, $mode );
    }

    private static function write_all( $handle, $bytes ) {
        $offset = 0;
        $length = strlen( $bytes );
        while ( $offset < $length ) {
            $written = @fwrite( $handle, substr( $bytes, $offset ) );
            if ( ! is_int( $written ) || $written <= 0 ) {
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    private static function acquire_lock( $path, $nonblocking = false ) {
        if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
            return false;
        }
        $handle = @fopen( $path, 'c+b' );
        if ( $handle === false ) {
            return false;
        }
        if ( ! @chmod( $path, PrivateDir::FILE_MODE ) ) {
            fclose( $handle );
            return false;
        }
        if ( ! @flock( $handle, LOCK_EX | ( $nonblocking ? LOCK_NB : 0 ) ) ) {
            fclose( $handle );
            return false;
        }
        return $handle;
    }

    private static function acquire_existing_lock( $path, $nonblocking = false, $repair_permissions = true ) {
        if ( is_link( $path ) || ! is_file( $path ) ) {
            return false;
        }
        $handle = @fopen( $path, 'r+b' );
        if ( $handle === false ) {
            return false;
        }
        if ( ! @flock( $handle, LOCK_EX | ( $nonblocking ? LOCK_NB : 0 ) ) ) {
            fclose( $handle );
            return false;
        }
        if ( $repair_permissions && ! @chmod( $path, PrivateDir::FILE_MODE ) ) {
            self::release_lock( $handle );
            return false;
        }
        return $handle;
    }

    private static function acquire_staged_lock( $aggregate, $create = false, $nonblocking = false, $repair_permissions = true ) {
        $lock_path = self::aggregate_lock_path( self::STAGED_DIR, $aggregate );
        if ( $lock_path === '' || is_link( $lock_path ) || ( file_exists( $lock_path ) && ! is_file( $lock_path ) ) ) {
            return false;
        }
        if ( is_file( $lock_path ) ) {
            return self::acquire_existing_lock( $lock_path, $nonblocking, $repair_permissions );
        }

        return $create ? self::acquire_lock( $lock_path, $nonblocking ) : false;
    }

    private static function remove_staged_lock_file( $aggregate ) {
        $path = self::aggregate_lock_path( self::STAGED_DIR, $aggregate );
        if ( is_file( $path ) && ! is_link( $path ) ) {
            @unlink( $path );
        }
    }

    private static function remove_staged_lock_file_for_batch( $batch_id, $uploads_dir ) {
        $root = PrivateDir::existing_protected_subdir( $uploads_dir, self::STAGED_DIR );
        if ( $root === '' ) {
            return;
        }
        $shard = $root . '/' . Helpers::h2( $batch_id );
        if ( is_link( $shard ) || ! is_dir( $shard ) ) {
            return;
        }
        $lock_path = $shard . '/.' . $batch_id . self::LOCK_FILENAME;
        clearstatcache( true, $lock_path );
        if ( is_file( $lock_path ) && ! is_link( $lock_path ) ) {
            @unlink( $lock_path );
            @rmdir( $shard );
        }
    }

    private static function release_lock( $handle ) {
        if ( is_resource( $handle ) ) {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }

    private static function release_submission_lock( $locked ) {
        if ( ! is_array( $locked ) ) {
            return;
        }
        self::release_lock( isset( $locked['lock'] ) ? $locked['lock'] : null );
        if ( isset( $locked['lifecycle'] ) && $locked['lifecycle'] instanceof PrivateDirLease ) {
            $locked['lifecycle']->release();
        }
    }

    private static function ensure_dir( $path, $mode = PrivateDir::DIRECTORY_MODE ) {
        if ( ! in_array( $mode, array( PrivateDir::DIRECTORY_MODE, PrivateDir::REVIEW_DIRECTORY_MODE ), true ) ) {
            return false;
        }
        if ( is_link( $path ) ) {
            return false;
        }
        if ( is_dir( $path ) ) {
            return @chmod( $path, $mode );
        }
        $parent = dirname( $path );
        while ( is_string( $parent ) && $parent !== '' && ! is_dir( $parent ) ) {
            if ( is_link( $parent ) || file_exists( $parent ) ) {
                return false;
            }
            $next = dirname( $parent );
            if ( $next === $parent ) {
                break;
            }
            $parent = $next;
        }
        if ( is_link( $parent ) || ( file_exists( $parent ) && ! is_dir( $parent ) ) ) {
            return false;
        }
        if ( file_exists( $path ) || ( ! @mkdir( $path, $mode, true ) && ! is_dir( $path ) ) || is_link( $path ) ) {
            return false;
        }
        return @chmod( $path, $mode );
    }

    private static function remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' ) {
            return true;
        }
        if ( is_link( $path ) ) {
            return @unlink( $path );
        }
        if ( ! file_exists( $path ) ) {
            return true;
        }
        if ( is_file( $path ) ) {
            return @unlink( $path );
        }
        $entries = @scandir( $path );
        if ( ! is_array( $entries ) ) {
            return false;
        }
        foreach ( array_diff( $entries, array( '.', '..' ) ) as $name ) {
            if ( ! self::remove_tree( $path . '/' . $name ) ) {
                return false;
            }
        }
        return @rmdir( $path );
    }

    private static function tombstone_limit( $policy ) {
        $max_files = max( 1, (int) $policy['max_files'] );
        return $max_files > intdiv( PHP_INT_MAX, 2 ) ? PHP_INT_MAX : $max_files * 2;
    }

    private static function string_value( $array, $key ) {
        return isset( $array[ $key ] ) && is_string( $array[ $key ] ) ? $array[ $key ] : '';
    }

    private static function int_value( $array, $key ) {
        return isset( $array[ $key ] ) && is_numeric( $array[ $key ] ) ? (int) $array[ $key ] : 0;
    }

    private static function now( $now ) {
        return is_numeric( $now ) ? (int) $now : time();
    }

    private static function base64url( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    private static function gone() {
        return self::failure( 'EFORMS_ERR_TOKEN', 'gone', array( 'gone' => true ) );
    }

    private static function success( $extra = array() ) {
        return array_merge( array( 'ok' => true ), is_array( $extra ) ? $extra : array() );
    }

    private static function failure( $code, $reason, $extra = array() ) {
        return array_merge(
            array( 'ok' => false, 'code' => $code, 'reason' => $reason ),
            is_array( $extra ) ? $extra : array()
        );
    }
}
