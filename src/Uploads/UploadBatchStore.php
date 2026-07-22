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
require_once __DIR__ . '/PrivateDir.php';
require_once __DIR__ . '/ManagedCapacityStore.php';
require_once __DIR__ . '/UploadPolicy.php';
require_once __DIR__ . '/UploadValue.php';

class UploadBatchStore {
    private const JSON_TEMP_ENTROPY_BYTES = 8;

    const STAGED_DIR = 'staged';
    const SUBMISSIONS_DIR = 'submissions';
    const MANIFEST_FILENAME = 'manifest.json';
    const LOCK_FILENAME = '.lock';
    const FILES_DIR = 'files';
    const CAPACITY_FILENAME = 'managed-capacity.json';
    const CAPACITY_LOCK_FILENAME = 'managed-capacity.lock';
    const MANIFEST_VERSION = 2;
    const CAPACITY_VERSION = 1;

    public static function aggregate_lock_path( $family, $aggregate ) {
        if ( ! is_string( $aggregate ) || $aggregate === '' ) {
            return '';
        }
        $aggregate = rtrim( $aggregate, '/\\' );
        if ( $family === self::STAGED_DIR ) {
            return $aggregate . self::LOCK_FILENAME;
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

    public static function prelock_purge_aggregates( $lifecycle ) {
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
                    $lock_path = self::aggregate_lock_path( $family, $aggregate_path );
                    if ( is_link( $lock_path ) || ! is_file( $lock_path ) ) {
                        self::release_purge_locks( $handles );
                        return false;
                    }
                    $handle = @fopen( $lock_path, 'r+b' );
                    if ( $handle === false || ! @flock( $handle, LOCK_EX | LOCK_NB ) ) {
                        if ( is_resource( $handle ) ) {
                            fclose( $handle );
                        }
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

    public static function capacity_platform_supported( $integer_bytes = null ) {
        $integer_bytes = $integer_bytes === null ? PHP_INT_SIZE : $integer_bytes;
        return is_numeric( $integer_bytes ) && (int) $integer_bytes >= 8;
    }

    public static function canonical_policy( $field ) {
        $field = is_array( $field ) ? $field : array();
        $accept = isset( $field['accept'] ) && is_array( $field['accept'] )
            ? UploadPolicy::canonical_tokens( $field['accept'] )
            : UploadPolicy::canonical_tokens( UploadPolicy::default_tokens() );

        return array(
            'accept' => array_values( $accept ),
            'max_file_bytes' => self::positive_int( isset( $field['max_file_bytes'] ) ? $field['max_file_bytes'] : 0 ),
            'max_files' => self::positive_int( isset( $field['max_files'] ) ? $field['max_files'] : 0 ),
            'max_total_bytes' => self::positive_int( isset( $field['max_total_bytes'] ) ? $field['max_total_bytes'] : 0 ),
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

    public static function create_batch( $binding, $batch_secret, $field, $uploads_dir, $now = null ) {
        $binding = is_array( $binding ) ? $binding : array();
        $now = self::now( $now );
        $raw_token = self::string_value( $binding, 'raw_token' );
        $form_id = self::string_value( $binding, 'form_id' );
        $instance_id = self::string_value( $binding, 'instance_id' );
        $field_key = self::string_value( $binding, 'field_key' );
        $accept_until = self::int_value( $binding, 'accept_until' );
        $policy = self::canonical_policy( $field );

        if ( $raw_token === '' || $form_id === '' || $instance_id === '' || $field_key === '' || ! self::valid_staged_policy( $policy ) ) {
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
            return $capacity;
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
            if ( is_link( $aggregate ) || file_exists( $aggregate ) || ( ! @mkdir( $aggregate, 0700 ) && ! is_dir( $aggregate ) ) || is_link( $aggregate ) ) {
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_dir_unavailable' );
            }
            $created = true;
        }
        if ( ! @chmod( $aggregate, 0700 ) ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_dir_permissions' );
        }

        $lock = self::acquire_lock( self::aggregate_lock_path( self::STAGED_DIR, $aggregate ) );
        if ( $lock === false ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_lock_failed' );
        }

        $manifest_path = $aggregate . '/' . self::MANIFEST_FILENAME;
        if ( is_file( $manifest_path ) ) {
            $manifest = self::read_manifest( $manifest_path, 'staged', $batch_id );
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            if ( $manifest === null ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
            }
            if ( ! self::binding_matches( $manifest, $form_id, $instance_id, hash( 'sha256', $raw_token ), $field_key, $policy_fingerprint )
                || ! hash_equals( $manifest['batch_secret_digest'], $secret_digest )
            ) {
                return self::failure( 'EFORMS_ERR_TOKEN', 'batch_conflict' );
            }
            return self::success( array( 'batch' => self::batch_summary( $manifest ) ) );
        }

        if ( ( ! $created && ! self::initializable_partial_batch( $aggregate ) )
            || ! self::remove_initial_manifest_temps( $aggregate )
            || ! self::ensure_dir( $aggregate . '/' . self::FILES_DIR )
        ) {
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_files_unavailable' );
        }

        $manifest = array(
            'version' => self::MANIFEST_VERSION,
            'batch_id' => $batch_id,
            'state' => 'open',
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
            'items' => array(),
            'tombstones' => array(),
            'source_bytes' => 0,
            'managed_bytes' => 0,
        );
        $written = self::write_json_atomic( $manifest_path, $manifest );
        self::release_lock( $lock );
        self::release_lock( $capacity['lock'] );
        if ( ! $written ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
        }

        return self::success( array( 'batch' => self::batch_summary( $manifest ) ) );
    }

    public static function status( $batch_id, $batch_secret, $uploads_dir, $now = null ) {
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }

        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        if ( $manifest === null ) {
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

        $summary = self::batch_summary( $manifest );
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

        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $now = self::now( $now );
        if ( $manifest === null ) {
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

        $items = self::resolved_items( $manifest );
        $summary = self::batch_summary( $manifest );
        self::release_lock( $locked['lock'] );
        return self::success( array( 'batch' => $summary, 'items' => $items ) );
    }

    public static function put_item( $batch_id, $batch_secret, $upload_id, $ordinal, $item, $uploads_dir, $options = array() ) {
        $incoming_source = UploadValue::temporary_path( $item );
        $result = null;
        $source_removed = true;
        try {
            $result = self::put_item_consuming_source( $batch_id, $batch_secret, $upload_id, $ordinal, $item, $uploads_dir, $options );
        } finally {
            if ( $incoming_source !== '' && ( file_exists( $incoming_source ) || is_link( $incoming_source ) ) ) {
                @unlink( $incoming_source );
                $source_removed = ! file_exists( $incoming_source ) && ! is_link( $incoming_source );
            }
        }
        if ( ! $source_removed ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'source_cleanup_failed' );
        }
        return $result;
    }

    private static function put_item_consuming_source( $batch_id, $batch_secret, $upload_id, $ordinal, $item, $uploads_dir, $options ) {
        if ( ! self::valid_upload_id( $upload_id ) || ! is_numeric( $ordinal ) || (int) $ordinal < 0 ) {
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'item_identity_invalid' );
        }
        $ordinal = (int) $ordinal;

        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }

        $locked = self::lock_staged_batch( $batch_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $preflight = self::check_item_mutation(
            $manifest,
            $batch_secret,
            self::now( isset( $options['now'] ) ? $options['now'] : null )
        );
        $field = is_array( $manifest ) && isset( $manifest['policy'] ) && is_array( $manifest['policy'] )
            ? $manifest['policy']
            : array();
        $existing = is_array( $manifest ) && isset( $manifest['items'][ $upload_id ] )
            ? $manifest['items'][ $upload_id ]
            : null;
        self::release_lock( $locked['lock'] );
        if ( empty( $preflight['ok'] ) ) {
            return $preflight;
        }

        if ( is_array( $existing ) ) {
            if ( $existing['ordinal'] !== $ordinal ) {
                return self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
            }
            $envelope = UploadPolicy::validate_item_envelope( $item, $field );
            if ( empty( $envelope['ok'] ) ) {
                return $envelope;
            }
            $sha256 = hash_file( 'sha256', $envelope['tmp_name'] );
            @unlink( $envelope['tmp_name'] );
            return self::recover_existing_item(
                $batch_id,
                $batch_secret,
                $upload_id,
                $ordinal,
                $sha256,
                $uploads_dir,
                $lifecycle,
                self::now( isset( $options['now'] ) ? $options['now'] : null )
            );
        }

        $validated = UploadPolicy::validate_item( $item, $field, $options );
        if ( empty( $validated['ok'] ) ) {
            $source_path = UploadValue::temporary_path( $item );
            if ( $source_path !== '' ) {
                @unlink( $source_path );
            }
            return $validated;
        }
        $sha256 = $validated['sha256'];
        $attempt_id = Entropy::uuid_v4();
        if ( $attempt_id === '' ) {
            @unlink( $validated['tmp_name'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'entropy_unavailable' );
        }

        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            @unlink( $validated['tmp_name'] );
            return $capacity;
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            @unlink( $validated['tmp_name'] );
            self::release_lock( $capacity['lock'] );
            return $locked;
        }

        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $now = self::now( isset( $options['now'] ) ? $options['now'] : null );
        $check = self::check_item_mutation( $manifest, $batch_secret, $now );
        if ( empty( $check['ok'] ) ) {
            @unlink( $validated['tmp_name'] );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $check;
        }

        if ( isset( $manifest['items'][ $upload_id ] ) ) {
            $existing = $manifest['items'][ $upload_id ];
            @unlink( $validated['tmp_name'] );
            if ( $existing['ordinal'] !== $ordinal ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
            }
            if ( isset( $existing['source_sha256'] ) && hash_equals( $existing['source_sha256'], $sha256 ) ) {
                $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
                $settled = self::settle_committed_capacity( $record, $capacity['path'], $batch_id, $upload_id, $existing['managed_bytes'], $now );
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                if ( ! $settled ) {
                    return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_settlement_failed' );
                }
                return self::success( array( 'item' => self::item_summary( $existing ) ) );
            }
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
        }
        if ( isset( $manifest['tombstones'][ $upload_id ] ) ) {
            @unlink( $validated['tmp_name'] );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'item_deleted' );
        }
        if ( count( $manifest['items'] ) + count( $manifest['tombstones'] ) >= self::tombstone_limit( $manifest['policy'] ) ) {
            @unlink( $validated['tmp_name'] );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'upload_lifetime_exceeded' );
        }
        foreach ( $manifest['items'] as $existing ) {
            if ( $existing['ordinal'] === $ordinal ) {
                @unlink( $validated['tmp_name'] );
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'ordinal_conflict' );
            }
        }
        if ( count( $manifest['items'] ) >= $manifest['policy']['max_files'] ) {
            @unlink( $validated['tmp_name'] );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'max_files_exceeded' );
        }
        if ( $manifest['source_bytes'] + $validated['bytes'] > $manifest['policy']['max_total_bytes'] ) {
            @unlink( $validated['tmp_name'] );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_UPLOAD_TYPE', 'max_total_bytes_exceeded' );
        }

        $reservation_id = hash( 'sha256', $batch_id . "\0" . $upload_id );
        $reserved_bytes = Anchors::get( 'STAGED_MASTER_MAX_BYTES' ) + Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' );
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        if ( $record === null ) {
            @unlink( $validated['tmp_name'] );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $reservation_options = $options;
        $reservation_options['source_path'] = $validated['tmp_name'];
        $reservation_options['source_bytes'] = $validated['bytes'];
        $reservation = self::reserve_capacity( $record, $reservation_id, $attempt_id, $batch_id, $upload_id, $reserved_bytes, $capacity['private_dir'], $reservation_options, $now );
        if ( empty( $reservation['ok'] ) || ! ManagedCapacityStore::write( $capacity['path'], $reservation['record'] ) ) {
            @unlink( $validated['tmp_name'] );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return empty( $reservation['ok'] ) ? $reservation : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_write_failed' );
        }
        self::release_lock( $capacity['lock'] );

        $files_dir = $locked['path'] . '/' . self::FILES_DIR;
        $item_dir = $files_dir . '/' . $upload_id;
        $commit = self::commit_item_files( $validated, $files_dir, $upload_id );
        if ( empty( $commit['ok'] ) ) {
            $cleanup_failed = isset( $commit['reason'] ) && $commit['reason'] === 'item_cleanup_failed';
            self::release_lock( $locked['lock'] );
            if ( $cleanup_failed || file_exists( $item_dir ) || is_link( $item_dir ) ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_cleanup_failed' );
            }
            self::finish_reservation( $uploads_dir, $lifecycle, $reservation_id, $attempt_id, 0 );
            return $commit;
        }

        $stored = array(
            'upload_id' => $upload_id,
            'ordinal' => $ordinal,
            'source_display_name' => $validated['display_name'],
            'source_bytes' => $validated['bytes'],
            'source_mime' => $validated['mime'],
            'source_width' => $validated['width'],
            'source_height' => $validated['height'],
            'source_sha256' => $sha256,
            'master_relpath' => self::FILES_DIR . '/' . $upload_id . '/master.jpg',
            'master_bytes' => $commit['master']['bytes'],
            'master_width' => $commit['master']['width'],
            'master_height' => $commit['master']['height'],
            'master_sha256' => $commit['master']['sha256'],
            'preview_relpath' => self::FILES_DIR . '/' . $upload_id . '/preview.jpg',
            'preview_bytes' => $commit['preview']['bytes'],
            'preview_width' => $commit['preview']['width'],
            'preview_height' => $commit['preview']['height'],
            'preview_sha256' => $commit['preview']['sha256'],
            'managed_bytes' => $commit['managed_bytes'],
            'created_at' => $now,
        );
        $manifest['items'][ $upload_id ] = $stored;
        $manifest['source_bytes'] += $validated['bytes'];
        $manifest['managed_bytes'] += $stored['managed_bytes'];
        if ( ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
            $cleaned = self::remove_tree( $item_dir );
            self::release_lock( $locked['lock'] );
            if ( ! $cleaned ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_cleanup_failed' );
            }
            self::finish_reservation( $uploads_dir, $lifecycle, $reservation_id, $attempt_id, 0 );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
        }

        self::release_lock( $locked['lock'] );
        if ( ! self::finish_reservation( $uploads_dir, $lifecycle, $reservation_id, $attempt_id, $stored['managed_bytes'] ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_settlement_failed' );
        }
        return self::success( array( 'item' => self::item_summary( $stored ) ) );
    }

    public static function delete_item( $batch_id, $batch_secret, $upload_id, $uploads_dir, $now = null ) {
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

        if ( isset( $manifest['tombstones'][ $upload_id ] ) ) {
            $result = self::finish_tombstone_delete( $manifest, $upload_id, $locked, $capacity );
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $result;
        }
        $stored = isset( $manifest['items'][ $upload_id ] ) ? $manifest['items'][ $upload_id ] : null;
        if ( count( $manifest['tombstones'] ) >= self::tombstone_limit( $manifest['policy'] ) ) {
            if ( ! is_array( $stored ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::success();
            }

            $released_ids = array();
            foreach ( $manifest['tombstones'] as $deleted_id => $tombstone ) {
                if ( ! empty( $tombstone['capacity_released'] ) ) {
                    $released_ids[] = $deleted_id;
                }
            }
            sort( $released_ids, SORT_STRING );
            if ( empty( $released_ids ) ) {
                self::release_lock( $locked['lock'] );
                self::release_lock( $capacity['lock'] );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'tombstone_limit' );
            }
            unset( $manifest['tombstones'][ $released_ids[0] ] );
        }

        $manifest['tombstones'][ $upload_id ] = array(
            'deleted_at' => $now,
            'managed_bytes' => is_array( $stored ) ? (int) $stored['managed_bytes'] : 0,
            'master_relpath' => is_array( $stored ) ? $stored['master_relpath'] : '',
            'preview_relpath' => is_array( $stored ) ? $stored['preview_relpath'] : '',
            'capacity_release_started' => ! is_array( $stored ),
            'capacity_released' => ! is_array( $stored ),
        );
        if ( is_array( $stored ) ) {
            unset( $manifest['items'][ $upload_id ] );
            $manifest['source_bytes'] -= (int) $stored['source_bytes'];
            $manifest['managed_bytes'] -= (int) $stored['managed_bytes'];
        }
        if ( ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
        }

        $result = self::finish_tombstone_delete( $manifest, $upload_id, $locked, $capacity );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        return $result;
    }

    public static function preview_bytes( $batch_id, $batch_secret, $upload_id, $uploads_dir, $now = null ) {
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $now = self::now( $now );
        if ( $manifest === null ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_invalid' );
        }
        if ( $now >= $manifest['delete_after'] ) {
            self::release_lock( $locked['lock'] );
            return self::gone();
        }
        if ( $manifest['state'] !== 'open' || ! self::secret_matches( $manifest, $batch_secret ) || ! isset( $manifest['items'][ $upload_id ] ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'preview_denied' );
        }
        $item = $manifest['items'][ $upload_id ];
        $path = self::member_path( $locked['path'], $item['preview_relpath'] );
        $bytes = $path !== '' ? @file_get_contents( $path ) : false;
        $expected_bytes = (int) $item['preview_bytes'];
        $expected_sha256 = isset( $item['preview_sha256'] ) ? $item['preview_sha256'] : '';
        $actual_sha256 = is_string( $bytes ) ? hash( 'sha256', $bytes ) : '';
        self::release_lock( $locked['lock'] );
        if ( ! is_string( $bytes )
            || strlen( $bytes ) !== $expected_bytes
            || ! is_string( $expected_sha256 )
            || strlen( $expected_sha256 ) !== 64
            || ! hash_equals( $expected_sha256, $actual_sha256 )
        ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'preview_missing' );
        }
        return self::success( array( 'body' => $bytes, 'mime' => 'image/jpeg', 'bytes' => $expected_bytes ) );
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

        $manifest = self::read_manifest(
            $locked['manifest_path'],
            $aggregate_family,
            $aggregate_family === 'staged' ? $batch_id : $submission_id
        );
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
            && $now < (int) $manifest['delete_after']
            && self::secret_matches( $manifest, $batch_secret )
            && self::binding_matches( $manifest, $form_id, $instance_id, hash( 'sha256', $raw_token ), $field_key, $policy_fingerprint );
        if ( ! $valid ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'recovery_denied' );
        }

        $items = self::resolved_items( $manifest );
        self::release_lock( $locked['lock'] );
        return self::success(
            array(
                'phase' => $phase,
                'items' => $items,
                'accept_expired' => $now >= (int) $manifest['accept_until'],
            )
        );
    }

    public static function reopen_claim( $batch_id, $submission_id, $uploads_dir, $now = null ) {
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $locked = self::lock_staged_batch( $batch_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'staged', $batch_id );
        $now = self::now( $now );
        if ( $manifest === null
            || $manifest['state'] !== 'finalizing'
            || ! isset( $manifest['claim']['submission_id'] )
            || ! hash_equals( $manifest['claim']['submission_id'], (string) $submission_id )
            || isset( $manifest['email_attempted_at'] )
            || $now >= (int) $manifest['delete_after']
        ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'claim_reopen_denied' );
        }
        $manifest['state'] = 'open';
        unset( $manifest['claim'] );
        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        self::release_lock( $locked['lock'] );
        return $written ? self::success() : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
    }

    public static function finalize( $batch_id, $submission_id, $uploads_dir, $now = null ) {
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
        if ( self::read_capacity( $capacity['path'], $capacity['private_dir'] ) === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $result = self::finalize_with_capacity_lock( $batch_id, $submission_id, $uploads_dir, $lifecycle, $now );
        self::release_lock( $capacity['lock'] );
        return $result;
    }

    private static function finalize_with_capacity_lock( $batch_id, $submission_id, $uploads_dir, $lifecycle, $now ) {
        $now = self::now( $now );
        $staged_root = self::managed_root( $uploads_dir, self::STAGED_DIR, false );
        $source = $staged_root === '' ? '' : $staged_root . '/' . Helpers::h2( $batch_id ) . '/' . $batch_id;
        $submission_root = self::managed_root( $uploads_dir, self::SUBMISSIONS_DIR, true, $lifecycle );
        if ( $submission_root === '' ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_root_unavailable' );
        }
        $destination_shard = $submission_root . '/' . Helpers::h2( $submission_id );
        if ( ! self::ensure_dir( $destination_shard ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_shard_unavailable' );
        }
        $destination = $destination_shard . '/' . $submission_id;

        if ( is_dir( $source ) ) {
            $lock = self::acquire_lock( self::aggregate_lock_path( self::STAGED_DIR, $source ) );
            if ( $lock === false ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_lock_failed' );
            }
            $manifest = self::read_manifest( $source . '/' . self::MANIFEST_FILENAME, 'staged', $batch_id );
            $matches = $manifest !== null
                && $manifest['state'] === 'finalizing'
                && isset( $manifest['claim']['submission_id'] )
                && hash_equals( $manifest['claim']['submission_id'], $submission_id )
                && $now < (int) $manifest['delete_after'];
            if ( ! $matches ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_claim_mismatch' );
            }
            if ( file_exists( $destination ) || ! @rename( $source, $destination ) ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'aggregate_rename_failed' );
            }
            self::release_lock( $lock );
            self::remove_staged_lock_file( $source );
            @rmdir( dirname( $source ) );
        } elseif ( ! is_dir( $destination ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_unavailable' );
        }

        $lock = self::acquire_lock( self::aggregate_lock_path( self::SUBMISSIONS_DIR, $destination ) );
        if ( $lock === false ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_lock_failed' );
        }
        $manifest_path = $destination . '/' . self::MANIFEST_FILENAME;
        $manifest = self::read_manifest( $manifest_path, 'submission', $submission_id );
        if ( $manifest === null
            || ! isset( $manifest['claim']['submission_id'] )
            || ! hash_equals( $manifest['claim']['submission_id'], $submission_id )
            || $now >= (int) $manifest['delete_after']
        ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_TOKEN', 'finalize_claim_mismatch' );
        }
        if ( $manifest['state'] !== 'finalized' ) {
            $finalized_at = $now;
            $manifest['state'] = 'finalized';
            $manifest['finalized_at'] = $finalized_at;
            $manifest['gallery_expires_at'] = $finalized_at + Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' );
            $manifest['delete_after'] = $manifest['gallery_expires_at'];
            if ( ! self::write_json_atomic( $manifest_path, $manifest ) ) {
                self::release_lock( $lock );
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
            }
        }
        $summary = self::submission_summary( $manifest );
        self::release_lock( $lock );
        return self::success( array( 'submission' => $summary ) );
    }

    public static function submission( $submission_id, $uploads_dir, $now = null ) {
        $locked = self::lock_submission( $submission_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( $manifest === null || $manifest['state'] !== 'finalized' || self::now( $now ) >= (int) $manifest['gallery_expires_at'] ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $summary = self::submission_summary( $manifest );
        self::release_lock( $locked['lock'] );
        return self::success( array( 'submission' => $summary ) );
    }

    public static function submission_file( $submission_id, $upload_id, $variant, $uploads_dir, $now = null ) {
        if ( ! self::valid_upload_id( $upload_id ) || ! in_array( $variant, array( 'preview', 'master' ), true ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'file_unavailable' );
        }
        $locked = self::lock_submission( $submission_id, $uploads_dir, true );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( $manifest === null
            || $manifest['state'] !== 'finalized'
            || self::now( $now ) >= (int) $manifest['gallery_expires_at']
            || ! isset( $manifest['items'][ $upload_id ] )
        ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'file_unavailable' );
        }
        $item = $manifest['items'][ $upload_id ];
        $relpath = $variant === 'preview' ? $item['preview_relpath'] : $item['master_relpath'];
        $path = self::member_path( $locked['path'], $relpath );
        $mime = 'image/jpeg';
        $bytes = $variant === 'preview' ? (int) $item['preview_bytes'] : (int) $item['master_bytes'];
        $expected_sha256 = $variant === 'preview' ? $item['preview_sha256'] : $item['master_sha256'];
        $stream = $path !== '' && is_file( $path ) ? @fopen( $path, 'rb' ) : false;
        $stat = is_resource( $stream ) ? fstat( $stream ) : false;
        $actual_bytes = is_array( $stat ) && isset( $stat['size'] ) && is_int( $stat['size'] ) ? $stat['size'] : -1;
        $hash = is_resource( $stream ) ? hash_init( 'sha256' ) : false;
        $hashed = $hash !== false && hash_update_stream( $hash, $stream ) === $actual_bytes;
        $actual_sha256 = $hashed ? hash_final( $hash ) : '';
        $rewound = is_resource( $stream ) && @rewind( $stream );
        if ( ! is_resource( $stream ) || $actual_bytes !== $bytes || ! $rewound || ! hash_equals( $expected_sha256, $actual_sha256 ) ) {
            if ( is_resource( $stream ) ) {
                fclose( $stream );
            }
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'file_missing' );
        }
        self::release_lock( $locked['lock'] );
        return self::success(
            array(
                'stream' => $stream,
                'mime' => $mime,
                'bytes' => $actual_bytes,
                'display_name' => $item['source_display_name'],
                'gallery_expires_at' => (int) $manifest['gallery_expires_at'],
            )
        );
    }

    public static function mark_email_attempted( $submission_id, $uploads_dir, $now = null ) {
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'upload_lifecycle_unavailable' );
        }
        $locked = self::lock_submission( $submission_id, $uploads_dir );
        if ( empty( $locked['ok'] ) ) {
            return $locked;
        }
        $manifest = self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
        if ( $manifest === null || $manifest['state'] !== 'finalized' || isset( $manifest['email_attempted_at'] ) ) {
            self::release_lock( $locked['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'email_attempt_denied' );
        }
        $manifest['email_attempted_at'] = self::now( $now );
        $written = self::write_json_atomic( $locked['manifest_path'], $manifest );
        self::release_lock( $locked['lock'] );
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
        $manifest = self::read_manifest( $locked['manifest_path'], 'submission', $submission_id );
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
                    ),
                )
            );
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'], false );
        if ( $record === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $materialization = self::reservation_materialization( $capacity['private_dir'], $record['reservations'] );
        if ( $materialization === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_scan_failed' );
        }
        $file_bytes = self::managed_file_bytes( $capacity['private_dir'] );
        if ( $file_bytes === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_scan_failed' );
        }
        $health = ManagedCapacityStore::health( $record, $file_bytes, $materialization['committed'], $materialization['orphaned'] );
        self::release_lock( $capacity['lock'] );
        if ( $health === null ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        return self::success(
            array( 'capacity' => $health )
        );
    }

    public static function reconcile_capacity( $uploads_dir, $stale_reservation_before, $now = null ) {
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

        $previous_total_bytes = (int) $record['total_bytes'];
        $previous_reservation_count = count( $record['reservations'] );
        $materialization = self::reservation_materialization( $capacity['private_dir'], $record['reservations'] );
        if ( $materialization === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
        }
        $file_bytes = self::managed_file_bytes( $capacity['private_dir'] );
        if ( $file_bytes === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
        }
        $record = ManagedCapacityStore::reconcile(
            $record,
            $materialization['committed'],
            $materialization['orphaned'],
            $file_bytes,
            $stale_reservation_before,
            self::now( $now )
        );
        if ( $record === null ) {
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_reconcile_failed' );
        }
        $written = ManagedCapacityStore::write( $capacity['path'], $record );
        self::release_lock( $capacity['lock'] );
        return $written
            ? self::success(
                array(
                    'capacity' => $record,
                    'previous_total_bytes' => $previous_total_bytes,
                    'stale_reservations_removed' => $previous_reservation_count - count( $record['reservations'] ) - count( $materialization['committed'] ),
                    'committed_reservations_settled' => count( $materialization['committed'] ),
                    'materialized_reservations_retained' => count( $materialization['orphaned'] ),
                )
            )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_write_failed' );
    }

    public static function gc_aggregates( $family, $uploads_dir, $now, $limit, $dry_run = false, $cursor = array() ) {
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
            'candidate_master_bytes' => 0,
            'candidate_preview_bytes' => 0,
            'deleted' => 0,
            'deleted_bytes' => 0,
            'deleted_master_bytes' => 0,
            'deleted_preview_bytes' => 0,
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
        $root = self::managed_root( $uploads_dir, $root_name, false );
        if ( $root === '' ) {
            $private_dir = PrivateDir::path( $uploads_dir );
            $expected_root = $private_dir === '' ? '' : rtrim( $private_dir, '/\\' ) . '/' . $root_name;
            if ( $expected_root !== '' && ( file_exists( $expected_root ) || is_link( $expected_root ) ) ) {
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

        $discovered = self::aggregate_paths( $root, $limit, $cursor );
        if ( empty( $discovered['ok'] ) ) {
            $result['ok'] = false;
            $result['errors'] = 1;
            $result['reason'] = isset( $discovered['reason'] ) ? $discovered['reason'] : 'aggregate_enumeration_failed';
            $result['cursor'] = is_array( $cursor ) ? $cursor : array();
            return $result;
        }
        $result['cursor'] = $discovered['cursor'];
        foreach ( $discovered['paths'] as $path ) {
            if ( $result['scanned'] >= $limit ) {
                $result['reached_limit'] = true;
                break;
            }
            $result['scanned']++;
            $one = self::gc_aggregate( $family, $path, $uploads_dir, $lifecycle, self::now( $now ), (bool) $dry_run );
            if ( empty( $one['ok'] ) ) {
                $result['errors']++;
                if ( $result['reason'] === '' ) {
                    $result['reason'] = isset( $one['reason'] ) ? $one['reason'] : 'aggregate_gc_failed';
                }
                if ( ! empty( $one['fatal'] ) ) {
                    $result['ok'] = false;
                    break;
                }
                continue;
            }
            if ( empty( $one['candidate'] ) ) {
                continue;
            }
            $result['candidates']++;
            $result['candidate_bytes'] += $one['managed_bytes'];
            $result['candidate_master_bytes'] += $one['master_bytes'];
            $result['candidate_preview_bytes'] += $one['preview_bytes'];
            if ( ! empty( $one['deleted'] ) ) {
                $result['deleted']++;
                $result['deleted_bytes'] += $one['managed_bytes'];
                $result['deleted_master_bytes'] += $one['master_bytes'];
                $result['deleted_preview_bytes'] += $one['preview_bytes'];
                $result['released_bytes'] += $one['released_bytes'];
            }
        }
        return $result;
    }

    private static function gc_aggregate( $family, $path, $uploads_dir, $lifecycle, $now, $dry_run ) {
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
        $lock = self::acquire_lock( $lock_path );
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
                    'master_bytes' => 0,
                    'preview_bytes' => 0,
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
        $eligible_state = $family === 'staged'
            ? in_array( $manifest['state'], array( 'open', 'finalizing' ), true )
            : in_array( $manifest['state'], array( 'finalizing', 'finalized' ), true );
        if ( ! $eligible_state || ! isset( $manifest['delete_after'] ) || ! is_numeric( $manifest['delete_after'] ) || (int) $manifest['delete_after'] > $now ) {
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => true, 'candidate' => false );
        }
        $parts = self::aggregate_byte_parts( $manifest );
        if ( $parts === null ) {
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'reason' => 'manifest_bytes_invalid' );
        }
        $out = array(
            'ok' => true,
            'candidate' => true,
            'deleted' => false,
            'managed_bytes' => $parts['managed_bytes'],
            'master_bytes' => $parts['master_bytes'],
            'preview_bytes' => $parts['preview_bytes'],
            'released_bytes' => 0,
        );
        $pending_tombstone_bytes = 0;
        $missing_tombstones = array();
        $attributed_bytes = array();
        foreach ( $manifest['items'] as $upload_id => $item ) {
            $attributed_bytes[ $upload_id ] = (int) $item['managed_bytes'];
        }
        foreach ( $manifest['tombstones'] as $upload_id => $tombstone ) {
            if ( ! empty( $tombstone['capacity_released'] ) ) {
                continue;
            }
            $pending_tombstone_bytes += (int) $tombstone['managed_bytes'];
            $attributed_bytes[ $upload_id ] = (int) $tombstone['managed_bytes'];
            $master = self::member_path( $path, $tombstone['master_relpath'] );
            $preview = self::member_path( $path, $tombstone['preview_relpath'] );
            if ( ( $master === '' || ! is_file( $master ) ) && ( $preview === '' || ! is_file( $preview ) ) ) {
                $missing_tombstones[ $upload_id ] = (int) $tombstone['managed_bytes'];
            }
        }
        $manifest_capacity_bytes = $parts['managed_bytes'] + $pending_tombstone_bytes;
        $released = ManagedCapacityStore::release_aggregate(
            $record,
            $manifest['batch_id'],
            $manifest_capacity_bytes,
            $attributed_bytes,
            $missing_tombstones,
            $now
        );
        if ( empty( $released['ok'] ) ) {
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return array( 'ok' => false, 'fatal' => true, 'reason' => 'capacity_inconsistent' );
        }
        if ( $dry_run ) {
            self::release_lock( $lock );
            self::release_lock( $capacity['lock'] );
            return $out;
        }
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

    private static function aggregate_paths( $root, $limit, $cursor ) {
        $out = array();
        $limit = max( 1, (int) $limit );
        $shard_count = hexdec( str_repeat( 'f', Helpers::H2_LENGTH ) ) + 1;
        $shard_pattern = '/^[0-9a-f]{' . Helpers::H2_LENGTH . '}$/';
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

        foreach ( $shards as $shard ) {
            if ( $cursor_shard !== '' && strcmp( $shard, $cursor_shard ) < 0 ) {
                continue;
            }
            $shard_path = $root . '/' . $shard;
            $after = $shard === $cursor_shard ? $cursor_aggregate : '';
            $entry_page = PrivateDir::bounded_entries_result( $shard_path, $after, $limit - count( $out ), true );
            if ( empty( $entry_page['ok'] ) ) {
                return array( 'ok' => false, 'paths' => array(), 'cursor' => $cursor, 'reason' => 'aggregate_enumeration_failed' );
            }
            $entries = $entry_page['entries'];
            foreach ( $entries as $aggregate ) {
                $path = $shard_path . '/' . $aggregate;
                if ( count( $out ) >= $limit ) {
                    return array( 'ok' => true, 'paths' => $out, 'cursor' => $last, 'reason' => '' );
                }
                $out[] = $path;
                $last = array( 'shard' => $shard, 'aggregate' => $aggregate );
                if ( count( $out ) >= $limit ) {
                    return array( 'ok' => true, 'paths' => $out, 'cursor' => $last, 'reason' => '' );
                }
            }
        }

        return array( 'ok' => true, 'paths' => $out, 'cursor' => array(), 'reason' => '' );
    }

    private static function aggregate_byte_parts( $manifest ) {
        $source = 0;
        $master = 0;
        $preview = 0;
        foreach ( $manifest['items'] as $item ) {
            if ( ! is_array( $item )
                || ! isset( $item['source_bytes'], $item['master_bytes'], $item['preview_bytes'] )
                || ! is_int( $item['source_bytes'] )
                || ! is_int( $item['master_bytes'] )
                || ! is_int( $item['preview_bytes'] )
                || $item['source_bytes'] < 0
                || $item['master_bytes'] < 0
                || $item['preview_bytes'] < 0
            ) {
                return null;
            }
            $source += $item['source_bytes'];
            $master += $item['master_bytes'];
            $preview += $item['preview_bytes'];
        }
        if ( $source !== (int) $manifest['source_bytes'] || $master + $preview !== (int) $manifest['managed_bytes'] ) {
            return null;
        }
        return array( 'source_bytes' => $source, 'master_bytes' => $master, 'preview_bytes' => $preview, 'managed_bytes' => $master + $preview );
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
            if ( $entry !== self::FILES_DIR || is_link( $entry_path ) || ! is_dir( $entry_path ) ) {
                return false;
            }
            $files = @scandir( $entry_path );
            if ( ! is_array( $files ) || array_diff( $files, array( '.', '..' ) ) !== array() ) {
                return false;
            }
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

        $files = $aggregate . '/' . self::FILES_DIR;
        $observed_at = is_dir( $files ) ? @filemtime( $files ) : @filemtime( $aggregate );
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

    private static function commit_item_files( $validated, $files_dir, $upload_id ) {
        $item_dir = rtrim( $files_dir, '/\\' ) . '/' . $upload_id;
        // The aggregate lock and absent manifest item make same-ID materialization
        // evidence of an interrupted commit. Clear it before regenerating the item.
        if ( ! self::remove_interrupted_item_files( $files_dir, $upload_id ) ) {
            @unlink( $validated['tmp_name'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_cleanup_failed' );
        }
        $suffix = Entropy::hex( self::JSON_TEMP_ENTROPY_BYTES );
        if ( $suffix === '' ) {
            @unlink( $validated['tmp_name'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_dir_failed' );
        }
        $pending_dir = rtrim( $files_dir, '/\\' ) . '/.' . $upload_id . '.' . $suffix . '.pending';
        if ( file_exists( $pending_dir ) || is_link( $pending_dir ) ) {
            @unlink( $validated['tmp_name'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_cleanup_failed' );
        }
        if ( ! @mkdir( $pending_dir, 0700 ) ) {
            @unlink( $validated['tmp_name'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_dir_failed' );
        }
        if ( ! @chmod( $pending_dir, 0700 ) ) {
            $cleaned = self::remove_tree( $pending_dir );
            @unlink( $validated['tmp_name'] );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', $cleaned ? 'item_dir_failed' : 'item_cleanup_failed' );
        }

        $derivatives = UploadPolicy::create_staged_derivatives( $validated, $pending_dir );
        if ( empty( $derivatives['ok'] ) ) {
            if ( ! self::remove_tree( $pending_dir ) ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_cleanup_failed' );
            }
            return $derivatives;
        }
        if ( ! @rename( $pending_dir, $item_dir ) ) {
            if ( ! self::remove_tree( $pending_dir ) ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_cleanup_failed' );
            }
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_commit_failed' );
        }
        return $derivatives;
    }

    private static function remove_interrupted_item_files( $files_dir, $upload_id ) {
        $files_dir = rtrim( $files_dir, '/\\' );
        $entries = @scandir( $files_dir );
        if ( ! is_array( $entries ) ) {
            return false;
        }
        $pending_pattern = '/^\\.' . preg_quote( $upload_id, '/' ) . '\\.[0-9a-f]+\\.pending$/';
        foreach ( $entries as $entry ) {
            if ( $entry !== $upload_id && preg_match( $pending_pattern, $entry ) !== 1 ) {
                continue;
            }
            $path = $files_dir . '/' . $entry;
            if ( ! self::remove_tree( $path ) || file_exists( $path ) || is_link( $path ) ) {
                return false;
            }
        }
        return true;
    }

    private static function finish_tombstone_delete( &$manifest, $upload_id, $locked, $capacity ) {
        $tombstone = $manifest['tombstones'][ $upload_id ];
        if ( ! empty( $tombstone['capacity_released'] ) ) {
            return self::success( array( 'deleted' => true ) );
        }

        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        if ( $record === null ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_invalid' );
        }
        $release = ManagedCapacityStore::prepare_item_release(
            $record,
            $manifest['batch_id'],
            $upload_id,
            $tombstone['managed_bytes'],
            $tombstone['deleted_at'],
            empty( $tombstone['capacity_release_started'] ),
            time()
        );
        if ( empty( $release['ok'] ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', isset( $release['reason'] ) ? $release['reason'] : 'capacity_invalid' );
        }
        $record = $release['record'];
        if ( ! empty( $release['changed'] ) && ! ManagedCapacityStore::write( $capacity['path'], $record ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_write_failed' );
        }

        if ( empty( $tombstone['capacity_release_started'] ) ) {
            // Persist release intent before unlinking so a retry can tell a
            // pending capacity write from one that already committed.
            $manifest['tombstones'][ $upload_id ]['capacity_release_started'] = true;
            if ( ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
            }
            $tombstone = $manifest['tombstones'][ $upload_id ];
        }

        if ( empty( $release['matching_reservations'] ) ) {
            foreach ( array( 'master_relpath', 'preview_relpath' ) as $key ) {
                $path = self::member_path( $locked['path'], isset( $tombstone[ $key ] ) ? $tombstone[ $key ] : '' );
                if ( $path !== '' && is_file( $path ) ) {
                    return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_release_state_invalid' );
                }
            }
        }

        foreach ( array( 'master_relpath', 'preview_relpath' ) as $key ) {
            $path = self::member_path( $locked['path'], isset( $tombstone[ $key ] ) ? $tombstone[ $key ] : '' );
            if ( $path !== '' && is_file( $path ) && ! @unlink( $path ) ) {
                return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'item_delete_failed' );
            }
        }
        $item_dir = $locked['path'] . '/' . self::FILES_DIR . '/' . $upload_id;
        if ( is_dir( $item_dir ) ) {
            @rmdir( $item_dir );
        }

        $settled = ManagedCapacityStore::finish_item_release( $record, $manifest['batch_id'], $upload_id, time() );
        if ( empty( $settled['ok'] ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', isset( $settled['reason'] ) ? $settled['reason'] : 'capacity_invalid' );
        }
        if ( ! empty( $settled['changed'] ) && ! ManagedCapacityStore::write( $capacity['path'], $settled['record'] ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_write_failed' );
        }

        $manifest['tombstones'][ $upload_id ]['capacity_released'] = true;
        if ( ! self::write_json_atomic( $locked['manifest_path'], $manifest ) ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'manifest_write_failed' );
        }
        return self::success( array( 'deleted' => true ) );
    }

    private static function recover_existing_item( $batch_id, $batch_secret, $upload_id, $ordinal, $sha256, $uploads_dir, $lifecycle, $now ) {
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
        $check = self::check_item_mutation( $manifest, $batch_secret, $now );
        $existing = is_array( $manifest ) && isset( $manifest['items'][ $upload_id ] )
            ? $manifest['items'][ $upload_id ]
            : null;
        if ( empty( $check['ok'] ) ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return $check;
        }
        if ( ! is_array( $existing )
            || $existing['ordinal'] !== $ordinal
            || ! is_string( $sha256 )
            || ! isset( $existing['source_sha256'] )
            || ! hash_equals( $existing['source_sha256'], $sha256 )
        ) {
            self::release_lock( $locked['lock'] );
            self::release_lock( $capacity['lock'] );
            return self::failure( 'EFORMS_ERR_TOKEN', 'upload_id_conflict' );
        }

        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        $settled = self::settle_committed_capacity( $record, $capacity['path'], $batch_id, $upload_id, $existing['managed_bytes'], $now );
        self::release_lock( $locked['lock'] );
        self::release_lock( $capacity['lock'] );
        return $settled
            ? self::success( array( 'item' => self::item_summary( $existing ) ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'capacity_settlement_failed' );
    }

    private static function settle_committed_capacity( $record, $path, $batch_id, $upload_id, $managed_bytes, $now ) {
        if ( ! is_array( $record ) ) {
            return false;
        }
        $reservation_id = hash( 'sha256', $batch_id . "\0" . $upload_id );
        $finished = ManagedCapacityStore::finish_committed(
            $record,
            $reservation_id,
            $batch_id,
            $upload_id,
            $managed_bytes,
            $now
        );
        return is_array( $finished )
            && ( $finished === $record || ManagedCapacityStore::write( $path, $finished ) );
    }

    private static function finish_reservation( $uploads_dir, $lifecycle, $reservation_id, $attempt_id, $actual_bytes ) {
        $capacity = self::lock_capacity( $uploads_dir, $lifecycle );
        if ( empty( $capacity['ok'] ) ) {
            return false;
        }
        $record = self::read_capacity( $capacity['path'], $capacity['private_dir'] );
        if ( $record === null ) {
            self::release_lock( $capacity['lock'] );
            return false;
        }
        $finished = ManagedCapacityStore::finish( $record, $reservation_id, $attempt_id, $actual_bytes, time() );
        $ok = is_array( $finished )
            && ( $finished === $record || ManagedCapacityStore::write( $capacity['path'], $finished ) );
        self::release_lock( $capacity['lock'] );
        return $ok;
    }

    private static function reserve_capacity( $record, $reservation_id, $attempt_id, $batch_id, $upload_id, $bytes, $private_dir, $options, $now ) {
        $free_bytes = self::free_bytes( $private_dir, $options );
        $source_bytes = ManagedCapacityStore::source_bytes_on_managed_filesystem(
            isset( $options['source_path'] ) ? $options['source_path'] : '',
            $private_dir,
            isset( $options['source_bytes'] ) ? $options['source_bytes'] : 0
        );
        $reserved = ManagedCapacityStore::reserve(
            $record,
            $reservation_id,
            $attempt_id,
            $batch_id,
            $upload_id,
            $bytes,
            $free_bytes,
            Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
            Anchors::get( 'MANAGED_UPLOAD_MAX_BYTES' ),
            $source_bytes,
            $now
        );
        return ! empty( $reserved['ok'] )
            ? self::success( array( 'record' => $reserved['record'] ) )
            : self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', isset( $reserved['reason'] ) ? $reserved['reason'] : 'capacity_invalid' );
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

    private static function lock_staged_batch( $batch_id, $uploads_dir, $existing_only = false ) {
        if ( ! is_string( $batch_id ) || preg_match( FormProtocol::upload_batch_id_pattern(), $batch_id ) !== 1 ) {
            return self::gone();
        }
        $root = $existing_only
            ? PrivateDir::existing_protected_subdir( $uploads_dir, self::STAGED_DIR )
            : self::managed_root( $uploads_dir, self::STAGED_DIR, false );
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
        $lock_path = self::aggregate_lock_path( self::STAGED_DIR, $path );
        $lock = $existing_only ? self::acquire_existing_lock( $lock_path ) : self::acquire_lock( $lock_path );
        if ( $lock === false ) {
            clearstatcache( true, $path );
            if ( is_link( $path ) || ! is_dir( $path ) ) {
                return self::gone();
            }
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'batch_lock_failed' );
        }
        $manifest_path = $path . '/' . self::MANIFEST_FILENAME;
        clearstatcache( true, $manifest_path );
        if ( ! is_dir( $path ) || ! is_file( $manifest_path ) ) {
            self::release_lock( $lock );
            return self::gone();
        }
        if ( self::managed_purged( $uploads_dir ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'managed_purged' );
        }
        return self::success(
            array(
                'path' => $path,
                'manifest_path' => $manifest_path,
                'lock' => $lock,
            )
        );
    }

    private static function lock_capacity( $uploads_dir, $lifecycle ) {
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
        if ( self::managed_purged( $uploads_dir ) ) {
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
        foreach ( array( self::STAGED_DIR, self::SUBMISSIONS_DIR ) as $name ) {
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

    private static function lock_submission( $submission_id, $uploads_dir, $existing_only = false ) {
        if ( ! self::valid_submission_id( $submission_id ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $root = $existing_only
            ? PrivateDir::existing_protected_subdir( $uploads_dir, self::SUBMISSIONS_DIR )
            : self::managed_root( $uploads_dir, self::SUBMISSIONS_DIR, false );
        if ( $root === '' ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $shard = $root . '/' . Helpers::h2( $submission_id );
        if ( is_link( $shard ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $path = $shard . '/' . $submission_id;
        if ( is_link( $path ) || ! is_dir( $path ) ) {
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        $lock_path = self::aggregate_lock_path( self::SUBMISSIONS_DIR, $path );
        $lock = $existing_only ? self::acquire_existing_lock( $lock_path ) : self::acquire_lock( $lock_path );
        if ( $lock === false ) {
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'submission_lock_failed' );
        }
        if ( is_link( $path ) || ! is_dir( $path ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_TOKEN', 'submission_unavailable' );
        }
        if ( self::managed_purged( $uploads_dir ) ) {
            self::release_lock( $lock );
            return self::failure( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'managed_purged' );
        }
        return self::success(
            array(
                'path' => $path,
                'manifest_path' => $path . '/' . self::MANIFEST_FILENAME,
                'lock' => $lock,
            )
        );
    }

    private static function read_manifest( $path, $aggregate_family, $aggregate_id ) {
        $manifest = self::read_json( $path );
        if ( ! is_array( $manifest )
            || ! isset( $manifest['version'], $manifest['batch_id'], $manifest['state'], $manifest['binding'], $manifest['batch_secret_digest'], $manifest['policy'] )
            || ! is_int( $manifest['version'] )
            || $manifest['version'] !== self::MANIFEST_VERSION
            || ! is_string( $manifest['batch_id'] )
            || preg_match( FormProtocol::upload_batch_id_pattern(), $manifest['batch_id'] ) !== 1
            || ! in_array( $manifest['state'], array( 'open', 'finalizing', 'finalized' ), true )
            || ! is_array( $manifest['binding'] )
            || ! self::valid_manifest_binding( $manifest['binding'] )
            || ! is_string( $manifest['batch_secret_digest'] )
            || preg_match( '/^[0-9a-f]{64}$/', $manifest['batch_secret_digest'] ) !== 1
            || ! is_array( $manifest['policy'] )
            || ! self::valid_manifest_policy( $manifest['policy'] )
            || ! isset( $manifest['accept_until'], $manifest['delete_after'], $manifest['items'], $manifest['tombstones'], $manifest['source_bytes'], $manifest['managed_bytes'] )
            || ! isset( $manifest['created_at'] )
            || ! self::nonnegative_int( $manifest['created_at'] )
            || ! self::nonnegative_int( $manifest['accept_until'] )
            || ! self::nonnegative_int( $manifest['delete_after'] )
            || $manifest['created_at'] > $manifest['accept_until']
            || $manifest['accept_until'] > $manifest['delete_after']
            || ! is_array( $manifest['items'] )
            || ! is_array( $manifest['tombstones'] )
            || ! self::nonnegative_int( $manifest['source_bytes'] )
            || ! self::nonnegative_int( $manifest['managed_bytes'] )
        ) {
            return null;
        }

        $ordinals = array();
        foreach ( $manifest['items'] as $upload_id => $item ) {
            $upload_id = (string) $upload_id;
            if ( ! self::valid_manifest_item( $upload_id, $item, $manifest['policy'] )
                || $item['created_at'] < $manifest['created_at']
                || $item['created_at'] >= $manifest['accept_until']
                || isset( $ordinals[ $item['ordinal'] ] )
            ) {
                return null;
            }
            $ordinals[ $item['ordinal'] ] = true;
        }
        foreach ( $manifest['tombstones'] as $upload_id => $tombstone ) {
            if ( ! self::valid_manifest_tombstone( (string) $upload_id, $tombstone )
                || $tombstone['deleted_at'] < $manifest['created_at']
                || $tombstone['deleted_at'] >= $manifest['delete_after']
            ) {
                return null;
            }
        }
        if ( count( $manifest['items'] ) > $manifest['policy']['max_files']
            || count( $manifest['tombstones'] ) > self::tombstone_limit( $manifest['policy'] )
            || ! empty( array_intersect_key( $manifest['items'], $manifest['tombstones'] ) )
            || $manifest['source_bytes'] > $manifest['policy']['max_total_bytes']
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

    private static function valid_manifest_binding( $binding ) {
        foreach ( array( 'form_id', 'instance_id', 'token_digest', 'field_key', 'policy_fingerprint' ) as $key ) {
            if ( ! isset( $binding[ $key ] ) || ! is_string( $binding[ $key ] ) || $binding[ $key ] === '' ) {
                return false;
            }
        }
        return preg_match( '/^[0-9a-f]{64}$/', $binding['token_digest'] ) === 1
            && preg_match( '/^[0-9a-f]{64}$/', $binding['policy_fingerprint'] ) === 1;
    }

    private static function valid_manifest_policy( $policy ) {
        return isset( $policy['accept'], $policy['max_file_bytes'], $policy['max_files'], $policy['max_total_bytes'], $policy['upload_mode'] )
            && is_array( $policy['accept'] )
            && is_int( $policy['max_file_bytes'] )
            && is_int( $policy['max_files'] )
            && is_int( $policy['max_total_bytes'] )
            && is_string( $policy['upload_mode'] )
            && self::valid_staged_policy( $policy );
    }

    private static function valid_manifest_item( $upload_id, $item, $policy ) {
        $expected_keys = array(
            'created_at', 'managed_bytes', 'master_bytes', 'master_height', 'master_relpath', 'master_sha256', 'master_width',
            'ordinal', 'preview_bytes', 'preview_height', 'preview_relpath', 'preview_sha256', 'preview_width', 'source_bytes',
            'source_display_name', 'source_height', 'source_mime', 'source_sha256', 'source_width', 'upload_id',
        );
        $actual_keys = is_array( $item ) ? array_keys( $item ) : array();
        sort( $actual_keys, SORT_STRING );
        if ( ! self::valid_upload_id( $upload_id )
            || ! is_array( $item )
            || $actual_keys !== $expected_keys
            || ! is_string( $item['upload_id'] )
            || ! hash_equals( $upload_id, $item['upload_id'] )
            || ! self::nonnegative_int( $item['ordinal'] )
            || ! is_string( $item['source_display_name'] )
            || $item['source_display_name'] === ''
            || ! self::nonnegative_int( $item['source_bytes'] )
            || $item['source_bytes'] > $policy['max_file_bytes']
            || ! is_string( $item['source_mime'] )
            || ! is_int( $item['source_width'] )
            || ! is_int( $item['source_height'] )
            || ! UploadPolicy::staged_dimensions_allowed( $item['source_width'], $item['source_height'] )
            || ! is_string( $item['source_sha256'] )
            || preg_match( '/^[0-9a-f]{64}$/', $item['source_sha256'] ) !== 1
            || ! is_string( $item['master_relpath'] )
            || ! self::nonnegative_int( $item['master_bytes'] )
            || $item['master_bytes'] > Anchors::get( 'STAGED_MASTER_MAX_BYTES' )
            || ! is_int( $item['master_width'] )
            || ! is_int( $item['master_height'] )
            || min( $item['master_width'], $item['master_height'] ) < 1
            || max( $item['master_width'], $item['master_height'] ) > Anchors::get( 'STAGED_MASTER_MAX_EDGE' )
            || ! is_string( $item['master_sha256'] )
            || preg_match( '/^[0-9a-f]{64}$/', $item['master_sha256'] ) !== 1
            || ! is_string( $item['preview_relpath'] )
            || ! self::nonnegative_int( $item['preview_bytes'] )
            || $item['preview_bytes'] > Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' )
            || ! is_int( $item['preview_width'] )
            || ! is_int( $item['preview_height'] )
            || min( $item['preview_width'], $item['preview_height'] ) < 1
            || max( $item['preview_width'], $item['preview_height'] ) > Anchors::get( 'STAGED_PREVIEW_MAX_EDGE' )
            || ! is_string( $item['preview_sha256'] )
            || preg_match( '/^[0-9a-f]{64}$/', $item['preview_sha256'] ) !== 1
            || ! self::nonnegative_int( $item['managed_bytes'] )
            || $item['master_bytes'] > PHP_INT_MAX - $item['preview_bytes']
            || $item['managed_bytes'] !== $item['master_bytes'] + $item['preview_bytes']
            || ! self::nonnegative_int( $item['created_at'] )
        ) {
            return false;
        }
        $prefix = self::FILES_DIR . '/' . $upload_id . '/';
        $extension = UploadPolicy::extension_from_name( $item['source_display_name'] );
        $mime_policy = UploadPolicy::policy_for_tokens( $policy['accept'], 'staged' );
        return $item['master_relpath'] === $prefix . 'master.jpg'
            && $item['preview_relpath'] === $prefix . 'preview.jpg'
            && UploadPolicy::mime_allowed( $item['source_mime'], $extension, $mime_policy );
    }

    private static function valid_manifest_tombstone( $upload_id, $tombstone ) {
        if ( ! self::valid_upload_id( $upload_id )
            || ! is_array( $tombstone )
            || ! isset( $tombstone['deleted_at'], $tombstone['managed_bytes'], $tombstone['master_relpath'], $tombstone['preview_relpath'], $tombstone['capacity_release_started'], $tombstone['capacity_released'] )
            || ! self::nonnegative_int( $tombstone['deleted_at'] )
            || ! self::nonnegative_int( $tombstone['managed_bytes'] )
            || ! is_string( $tombstone['master_relpath'] )
            || ! is_string( $tombstone['preview_relpath'] )
            || ! is_bool( $tombstone['capacity_release_started'] )
            || ! is_bool( $tombstone['capacity_released'] )
            || ( $tombstone['capacity_released'] && ! $tombstone['capacity_release_started'] )
        ) {
            return false;
        }
        $prefix = self::FILES_DIR . '/' . $upload_id . '/';
        return ( $tombstone['master_relpath'] === '' || $tombstone['master_relpath'] === $prefix . 'master.jpg' )
            && ( $tombstone['preview_relpath'] === '' || $tombstone['preview_relpath'] === $prefix . 'preview.jpg' );
    }

    private static function valid_manifest_state( $manifest ) {
        if ( $manifest['state'] === 'open' ) {
            return ! isset( $manifest['claim'] )
                && ! isset( $manifest['finalized_at'] )
                && ! isset( $manifest['gallery_expires_at'] )
                && ! isset( $manifest['email_attempted_at'] );
        }
        if ( ! isset( $manifest['claim'] )
            || ! is_array( $manifest['claim'] )
            || ! isset( $manifest['claim']['submission_id'], $manifest['claim']['claimed_at'] )
            || ! self::valid_submission_id( $manifest['claim']['submission_id'] )
            || ! self::nonnegative_int( $manifest['claim']['claimed_at'] )
            || $manifest['claim']['claimed_at'] < $manifest['created_at']
            || $manifest['claim']['claimed_at'] >= $manifest['accept_until']
        ) {
            return false;
        }
        if ( $manifest['state'] === 'finalizing' ) {
            return ! isset( $manifest['finalized_at'] )
                && ! isset( $manifest['gallery_expires_at'] )
                && ! isset( $manifest['email_attempted_at'] );
        }
        return isset( $manifest['finalized_at'], $manifest['gallery_expires_at'] )
            && self::nonnegative_int( $manifest['finalized_at'] )
            && self::nonnegative_int( $manifest['gallery_expires_at'] )
            && $manifest['finalized_at'] >= $manifest['claim']['claimed_at']
            && $manifest['gallery_expires_at'] === $manifest['finalized_at'] + Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' )
            && $manifest['delete_after'] === $manifest['gallery_expires_at']
            && ( ! isset( $manifest['email_attempted_at'] )
                || ( self::nonnegative_int( $manifest['email_attempted_at'] ) && $manifest['email_attempted_at'] >= $manifest['finalized_at'] )
            );
    }

    private static function nonnegative_int( $value ) {
        return is_int( $value ) && $value >= 0;
    }

    private static function read_capacity( $path, $private_dir, $require_empty_consistency = true ) {
        $record = ManagedCapacityStore::read( $path, self::CAPACITY_VERSION );
        if ( ! $require_empty_consistency
            || ! is_array( $record )
            || $record['total_bytes'] !== 0
            || $record['reservations'] !== array()
        ) {
            return $record;
        }
        $file_bytes = self::managed_file_bytes( $private_dir );
        return $file_bytes === 0 ? $record : null;
    }

    private static function batch_summary( $manifest ) {
        $items = array();
        foreach ( $manifest['items'] as $item ) {
            if ( is_array( $item ) ) {
                $items[] = self::item_summary( $item );
            }
        }
        usort( $items, function ( $left, $right ) {
            return $left['ordinal'] <=> $right['ordinal'];
        } );

        return array(
            'batch_id' => $manifest['batch_id'],
            'state' => $manifest['state'] === 'open' ? 'open' : 'finalizing',
            'accept_until' => (int) $manifest['accept_until'],
            'delete_after' => (int) $manifest['delete_after'],
            'items' => $items,
            'limits' => array(
                'max_file_bytes' => (int) $manifest['policy']['max_file_bytes'],
                'max_files' => (int) $manifest['policy']['max_files'],
                'max_total_bytes' => (int) $manifest['policy']['max_total_bytes'],
            ),
        );
    }

    private static function item_summary( $item ) {
        return array(
            'upload_id' => $item['upload_id'],
            'ordinal' => (int) $item['ordinal'],
            'display_name' => $item['source_display_name'],
            'bytes' => (int) $item['source_bytes'],
            'mime' => $item['source_mime'],
            'width' => (int) $item['preview_width'],
            'height' => (int) $item['preview_height'],
        );
    }

    private static function resolved_items( $manifest ) {
        $items = array();
        foreach ( $manifest['items'] as $item ) {
            $value = UploadValue::staged_item( self::item_summary( $item ) );
            if ( ! empty( $value ) ) {
                $items[] = $value;
            }
        }
        usort( $items, function ( $left, $right ) {
            return $left['ordinal'] <=> $right['ordinal'];
        } );
        return $items;
    }

    private static function submission_summary( $manifest ) {
        return array(
            'submission_id' => $manifest['claim']['submission_id'],
            'finalized_at' => (int) $manifest['finalized_at'],
            'gallery_expires_at' => (int) $manifest['gallery_expires_at'],
            'delete_after' => (int) $manifest['delete_after'],
            'items' => self::batch_summary( $manifest )['items'],
            'email_attempted_at' => isset( $manifest['email_attempted_at'] ) ? (int) $manifest['email_attempted_at'] : null,
        );
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
        if ( $create ) {
            return $lifecycle instanceof PrivateDirLease
                ? PrivateDir::leased_subdir( $lifecycle, $name, true, true )
                : '';
        }
        return PrivateDir::protected_subdir( $uploads_dir, $name, false );
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
        $orphaned = array();
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
                    $lock = self::acquire_lock( $lock_path );
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
                    foreach ( $wanted[ $manifest['batch_id'] ] as $upload_id => $reservation_id ) {
                        if ( isset( $manifest['items'][ $upload_id ] ) ) {
                            if ( isset( $committed[ $reservation_id ] ) || isset( $orphaned[ $reservation_id ] ) ) {
                                return null;
                            }
                            $item = $manifest['items'][ $upload_id ];
                            $committed[ $reservation_id ] = $item['managed_bytes'];
                            continue;
                        }
                        $bytes = self::reservation_item_bytes( $path, $upload_id );
                        if ( $bytes === null ) {
                            return null;
                        }
                        if ( $bytes > 0 ) {
                            if ( isset( $committed[ $reservation_id ] ) || isset( $orphaned[ $reservation_id ] ) ) {
                                return null;
                            }
                            $orphaned[ $reservation_id ] = $bytes;
                        }
                    }
                }
                $cursor = $discovered['cursor'];
            } while ( ! empty( $cursor ) );
        }
        return array( 'committed' => $committed, 'orphaned' => $orphaned );
    }

    private static function reservation_item_bytes( $aggregate_path, $upload_id ) {
        $files_dir = rtrim( $aggregate_path, '/\\' ) . '/' . self::FILES_DIR;
        $item_dir = $files_dir . '/' . $upload_id;
        if ( is_link( $files_dir ) || is_link( $item_dir ) ) {
            return null;
        }
        if ( ! file_exists( $item_dir ) ) {
            $matches = array();
            $file_entries = @scandir( $files_dir );
            if ( ! is_array( $file_entries ) ) {
                return null;
            }
            foreach ( $file_entries as $entry ) {
                if ( preg_match( '/^\.' . preg_quote( $upload_id, '/' ) . '\.[0-9a-f]+\.pending$/', $entry ) === 1 ) {
                    $matches[] = $files_dir . '/' . $entry;
                }
            }
            if ( count( $matches ) === 0 ) {
                return 0;
            }
            if ( count( $matches ) !== 1 || is_link( $matches[0] ) || ! is_dir( $matches[0] ) ) {
                return null;
            }
            $item_dir = $matches[0];
        }
        if ( ! is_dir( $item_dir ) ) {
            return null;
        }
        $entries = @scandir( $item_dir );
        if ( ! is_array( $entries ) ) {
            return null;
        }
        $bytes = 0;
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $path = $item_dir . '/' . $entry;
            if ( is_link( $path ) || ! is_file( $path ) ) {
                return null;
            }
            $size = filesize( $path );
            if ( ! is_int( $size ) || $size < 0 || $bytes > PHP_INT_MAX - $size ) {
                return null;
            }
            $bytes += $size;
        }
        return $bytes;
    }

    private static function managed_file_bytes( $private_dir ) {
        $total = 0;
        foreach ( array( self::STAGED_DIR, self::SUBMISSIONS_DIR ) as $root_name ) {
            $root = rtrim( $private_dir, '/\\' ) . '/' . $root_name;
            if ( is_link( $root ) ) {
                return null;
            }
            if ( ! is_dir( $root ) ) {
                continue;
            }
            try {
                $root_total = self::managed_tree_file_bytes( $root );
                if ( $root_total === null || $total > PHP_INT_MAX - $root_total ) {
                    return null;
                }
                $total += $root_total;
            } catch ( Throwable $error ) {
                return null;
            }
        }
        return $total;
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

    private static function write_json_atomic( $path, $value ) {
        $json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $suffix = Entropy::hex( self::JSON_TEMP_ENTROPY_BYTES );
        if ( ! is_string( $json ) || $suffix === '' || is_link( $path ) ) {
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
        if ( ! $ok || ! @chmod( $temp, 0600 ) || ! @rename( $temp, $path ) ) {
            @unlink( $temp );
            return false;
        }
        return @chmod( $path, 0600 );
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

    private static function acquire_lock( $path ) {
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
        if ( ! @flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            return false;
        }
        return $handle;
    }

    private static function acquire_existing_lock( $path ) {
        if ( is_link( $path ) || ! is_file( $path ) ) {
            return false;
        }
        $handle = @fopen( $path, 'r+b' );
        if ( $handle === false ) {
            return false;
        }
        if ( ! @flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            return false;
        }
        return $handle;
    }

    private static function remove_staged_lock_file( $aggregate ) {
        $path = self::aggregate_lock_path( self::STAGED_DIR, $aggregate );
        if ( is_file( $path ) && ! is_link( $path ) ) {
            @unlink( $path );
        }
    }

    private static function release_lock( $handle ) {
        if ( is_resource( $handle ) ) {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }

    private static function ensure_dir( $path ) {
        if ( is_link( $path ) ) {
            return false;
        }
        if ( is_dir( $path ) ) {
            return @chmod( $path, 0700 );
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
        if ( file_exists( $path ) || ( ! @mkdir( $path, 0700, true ) && ! is_dir( $path ) ) || is_link( $path ) ) {
            return false;
        }
        return @chmod( $path, 0700 );
    }

    private static function managed_tree_file_bytes( $root ) {
        if ( is_link( $root ) || ! is_dir( $root ) ) {
            return null;
        }

        $total = 0;
        $entries = scandir( $root );
        if ( ! is_array( $entries ) ) {
            return null;
        }
        foreach ( $entries as $shard ) {
            if ( $shard === '.' || $shard === '..' ) {
                continue;
            }
            $shard_path = $root . '/' . $shard;
            if ( is_link( $shard_path ) ) {
                return null;
            }
            if ( ! is_dir( $shard_path ) ) {
                continue;
            }
            $aggregates = scandir( $shard_path );
            if ( ! is_array( $aggregates ) ) {
                return null;
            }
            foreach ( $aggregates as $aggregate ) {
                if ( $aggregate === '.' || $aggregate === '..' ) {
                    continue;
                }
                $aggregate_path = $shard_path . '/' . $aggregate;
                if ( is_link( $aggregate_path ) ) {
                    return null;
                }
                if ( ! is_dir( $aggregate_path ) ) {
                    continue;
                }
                $files_path = $aggregate_path . '/' . self::FILES_DIR;
                if ( is_link( $files_path ) ) {
                    return null;
                }
                if ( ! is_dir( $files_path ) ) {
                    continue;
                }
                $bytes = self::managed_files_dir_bytes( $files_path );
                if ( $bytes === null || $total > PHP_INT_MAX - $bytes ) {
                    return null;
                }
                $total += $bytes;
            }
        }

        return $total;
    }

    private static function managed_files_dir_bytes( $dir ) {
        if ( is_link( $dir ) || ! is_dir( $dir ) ) {
            return null;
        }

        $total = 0;
        $uploads = scandir( $dir );
        if ( ! is_array( $uploads ) ) {
            return null;
        }
        foreach ( $uploads as $upload_id ) {
            if ( $upload_id === '.' || $upload_id === '..' ) {
                continue;
            }
            $upload_path = $dir . '/' . $upload_id;
            if ( is_link( $upload_path ) || ! is_dir( $upload_path ) ) {
                return null;
            }
            $members = scandir( $upload_path );
            if ( ! is_array( $members ) ) {
                return null;
            }
            foreach ( $members as $member ) {
                if ( $member === '.' || $member === '..' ) {
                    continue;
                }
                $path = $upload_path . '/' . $member;
                if ( is_link( $path ) || ! is_file( $path ) ) {
                    return null;
                }
                $size = filesize( $path );
                if ( ! is_int( $size ) || $size < 0 || $total > PHP_INT_MAX - $size ) {
                    return null;
                }
                $total += $size;
            }
        }

        return $total;
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

    private static function positive_int( $value ) {
        return is_numeric( $value ) && (int) $value > 0 ? (int) $value : 0;
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
