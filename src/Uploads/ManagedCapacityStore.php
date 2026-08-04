<?php
/**
 * Private persistence and arithmetic owner for managed upload capacity.
 *
 * UploadBatchStore remains the public facade and owns aggregate traversal and
 * lock ordering. This collaborator owns only the capacity record itself.
 */

require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Security/Entropy.php';
require_once __DIR__ . '/ManagedArtifactKey.php';
require_once __DIR__ . '/PrivateDir.php';

final class ManagedCapacityStore {
    private const JSON_TEMP_ENTROPY_BYTES = 8;

    public static function acquire_lock( $path, $exclusive = true, $nonblocking = false, $existing_only = false ) {
        if ( is_link( $path ) || ( $existing_only && ! is_file( $path ) ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
            return false;
        }
        $handle = @fopen( $path, $existing_only ? 'r+b' : 'c+b' );
        if ( $handle === false ) {
            return false;
        }
        $operation = $exclusive ? LOCK_EX : LOCK_SH;
        if ( $nonblocking ) {
            $operation |= LOCK_NB;
        }
        if ( ( ! $existing_only && ! @chmod( $path, PrivateDir::FILE_MODE ) ) || ! @flock( $handle, $operation ) ) {
            fclose( $handle );
            return false;
        }
        return $handle;
    }

    public static function read( $path, $version, $now = null ) {
        if ( is_link( $path ) ) {
            return null;
        }
        if ( ! file_exists( $path ) ) {
            return array(
                'version' => (int) $version,
                'total_bytes' => 0,
                'store_bytes' => array(
                    FormProtocol::UPLOAD_TRANSPORT_LOCAL => 0,
                    FormProtocol::UPLOAD_TRANSPORT_WORKER => 0,
                ),
                'reservations' => array(),
                'releases' => array(),
                'updated_at' => $now === null ? time() : (int) $now,
            );
        }
        if ( ! is_file( $path ) ) {
            return null;
        }
        $json = @file_get_contents( $path );
        $record = is_string( $json ) && $json !== '' ? json_decode( $json, true ) : null;
        if ( ! is_array( $record )
            || ! self::exact_keys( $record, array( 'releases', 'reservations', 'store_bytes', 'total_bytes', 'updated_at', 'version' ) )
            || ! is_int( $record['version'] )
            || $record['version'] !== (int) $version
            || ! is_int( $record['total_bytes'] )
            || $record['total_bytes'] < 0
            || ! self::valid_store_bytes( $record['store_bytes'], $record['total_bytes'] )
            || ! is_array( $record['reservations'] )
            || ! is_array( $record['releases'] )
            || ! is_int( $record['updated_at'] )
            || $record['updated_at'] < 0
        ) {
            return null;
        }
        $reserved_total = 0;
        $reserved_by_store = array(
            FormProtocol::UPLOAD_TRANSPORT_LOCAL => 0,
            FormProtocol::UPLOAD_TRANSPORT_WORKER => 0,
        );
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            if ( ! is_array( $reservation )
                || ! is_string( $reservation_id )
                || preg_match( '/^[0-9a-f]{64}$/D', $reservation_id ) !== 1
                || ! self::valid_reservation_keys( $reservation, $reservation_id )
                || ! isset( $reservation['batch_id'], $reservation['upload_id'], $reservation['bytes'], $reservation['created_at'], $reservation['object_key'] )
                || ! is_string( $reservation['batch_id'] )
                || preg_match( FormProtocol::upload_batch_id_pattern(), $reservation['batch_id'] ) !== 1
                || ! is_string( $reservation['upload_id'] )
                || preg_match( FormProtocol::managed_id_pattern(), $reservation['upload_id'] ) !== 1
                || ! is_int( $reservation['bytes'] )
                || $reservation['bytes'] < 0
                || ! isset( $reservation['transient_bytes'] )
                || ! is_int( $reservation['transient_bytes'] )
                || $reservation['transient_bytes'] < 0
                || ! isset( $reservation['artifact_store'] )
                || ! in_array( $reservation['artifact_store'], array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ), true )
                || ! isset( $reservation['artifact_store_identity'] )
                || ! self::valid_store_identity( $reservation['artifact_store'], $reservation['artifact_store_identity'] )
                || ! isset( $reservation['cleanup_started'] )
                || ! is_bool( $reservation['cleanup_started'] )
                || ! isset( $reservation['object_key'] )
                || ! is_string( $reservation['object_key'] )
                || ! ManagedArtifactKey::valid( $reservation['object_key'] )
                || ! self::reservation_object_key_matches( $reservation )
                || ( $reservation['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER
                    && $reservation['transient_bytes'] !== 0 )
                || ( isset( $reservation['intent_id'] )
                    && ( ! is_string( $reservation['intent_id'] ) || ! ManagedArtifactKey::valid_digest( $reservation['intent_id'] ) ) )
                || ! is_int( $reservation['created_at'] )
                || $reservation['created_at'] < 0
                || $reserved_total > PHP_INT_MAX - $reservation['bytes']
                || $reserved_by_store[ $reservation['artifact_store'] ] > PHP_INT_MAX - $reservation['bytes']
                || ! self::valid_cleanup_fields( $reservation['artifact_store'], $reservation )
            ) {
                return null;
            }
            $reserved_total += $reservation['bytes'];
            $reserved_by_store[ $reservation['artifact_store'] ] += $reservation['bytes'];
        }
        foreach ( $record['releases'] as $batch_id => $release ) {
            if ( ! is_string( $batch_id )
                || preg_match( FormProtocol::upload_batch_id_pattern(), $batch_id ) !== 1
                || ! is_array( $release )
                || array_keys( $release ) !== array( 'bytes', 'artifact_store', 'artifact_store_identity', 'created_at' )
                || ! is_int( $release['bytes'] )
                || $release['bytes'] < 0
                || $release['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
                || ! self::valid_store_identity( $release['artifact_store'], $release['artifact_store_identity'] )
                || ! is_int( $release['created_at'] )
                || $release['created_at'] < 0
            ) {
                return null;
            }
        }
        return $reserved_total <= $record['total_bytes']
            && $reserved_by_store[ FormProtocol::UPLOAD_TRANSPORT_LOCAL ] <= $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_LOCAL ]
            && $reserved_by_store[ FormProtocol::UPLOAD_TRANSPORT_WORKER ] <= $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ]
                ? $record
                : null;
    }

    public static function write( $path, $record ) {
        $json = json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $suffix = Entropy::hex( self::JSON_TEMP_ENTROPY_BYTES );
        if ( ! is_string( $json ) || $suffix === '' || is_link( $path ) ) {
            return false;
        }
        $temp = dirname( $path ) . '/.' . basename( $path ) . '.' . $suffix . '.tmp';
        $handle = @fopen( $temp, 'xb' );
        if ( $handle === false ) {
            return false;
        }
        $offset = 0;
        $length = strlen( $json );
        while ( $offset < $length ) {
            $written = @fwrite( $handle, substr( $json, $offset ) );
            if ( ! is_int( $written ) || $written <= 0 ) {
                break;
            }
            $offset += $written;
        }
        $ok = $offset === $length && ( ! function_exists( 'fflush' ) || @fflush( $handle ) );
        fclose( $handle );
        if ( ! $ok || ! @chmod( $temp, PrivateDir::FILE_MODE ) || ! @rename( $temp, $path ) ) {
            @unlink( $temp );
            return false;
        }
        return @chmod( $path, PrivateDir::FILE_MODE );
    }

    public static function reserve( $record, $reservation_id, $intent_id, $object_key, $batch_id, $upload_id, $bytes, $free_bytes, $minimum_free_bytes, $maximum_bytes, $transient_bytes, $now, $artifact_store, $artifact_store_identity, $materialized_transient_bytes = 0, $worker_cleanup = array() ) {
        $worker_cleanup = self::normalize_cleanup_fields( $artifact_store, $worker_cleanup );
        if ( ! is_string( $intent_id )
            || $intent_id === ''
            || ! is_string( $object_key )
            || $object_key === ''
            || ! is_int( $transient_bytes )
            || $transient_bytes < 0
            || ! is_int( $materialized_transient_bytes )
            || ( $materialized_transient_bytes !== 0 && $materialized_transient_bytes !== $transient_bytes )
            || ! in_array( $artifact_store, array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ), true )
            || ! self::valid_store_identity( $artifact_store, $artifact_store_identity )
            || $worker_cleanup === null
        ) {
            return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
        }
        $reusing = false;
        if ( isset( $record['reservations'][ $reservation_id ] ) ) {
            $existing = $record['reservations'][ $reservation_id ];
            if ( ! isset( $existing['batch_id'], $existing['upload_id'], $existing['bytes'] )
                || $existing['batch_id'] !== $batch_id
                || $existing['upload_id'] !== $upload_id
                || (int) $existing['bytes'] !== (int) $bytes
                || ! isset( $existing['intent_id'] )
                || ! is_string( $existing['intent_id'] )
                || ! hash_equals( $existing['intent_id'], $intent_id )
                || ! isset( $existing['object_key'] )
                || ! is_string( $existing['object_key'] )
                || ! hash_equals( $existing['object_key'], $object_key )
                || ! isset( $existing['transient_bytes'] )
                || $existing['transient_bytes'] !== $transient_bytes
                || ! isset( $existing['artifact_store'] )
                || $existing['artifact_store'] !== $artifact_store
                || ! isset( $existing['artifact_store_identity'] )
                || ! hash_equals( $existing['artifact_store_identity'], $artifact_store_identity )
                || ! self::cleanup_fields_match( $artifact_store, $existing, $worker_cleanup )
                || ! empty( $existing['cleanup_started'] )
            ) {
                return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
            }
            $reusing = true;
        } elseif ( $materialized_transient_bytes !== 0 ) {
            return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
        }
        if ( ! $reusing && $record['total_bytes'] > (int) $maximum_bytes - (int) $bytes ) {
            return array( 'ok' => false, 'reason' => 'managed_capacity_exceeded' );
        }
        $outstanding = self::local_outstanding_allocation_bytes( $record );
        if ( $outstanding === null ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        if ( (int) $bytes > PHP_INT_MAX - $transient_bytes ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $additional_claim = $reusing || $artifact_store !== FormProtocol::UPLOAD_TRANSPORT_LOCAL
            ? 0
            : (int) $bytes + $transient_bytes;
        if ( $outstanding > PHP_INT_MAX - $additional_claim ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $projected = $outstanding
            - ( $reusing && $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL ? $materialized_transient_bytes : 0 )
            + $additional_claim;
        if ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL
            && ( $free_bytes === null || $free_bytes < $projected || $free_bytes - $projected < (int) $minimum_free_bytes )
        ) {
            return array( 'ok' => false, 'reason' => $free_bytes === null ? 'free_space_unavailable' : 'free_space_reserve' );
        }

        if ( ! $reusing ) {
            $record['total_bytes'] += (int) $bytes;
            $record['store_bytes'][ $artifact_store ] += (int) $bytes;
        }
        $reservation = array(
            'batch_id' => $batch_id,
            'upload_id' => $upload_id,
            'bytes' => (int) $bytes,
            'transient_bytes' => $transient_bytes,
            'artifact_store' => $artifact_store,
            'artifact_store_identity' => $artifact_store_identity,
            'cleanup_started' => false,
            'created_at' => (int) $now,
            'intent_id' => $intent_id,
            'object_key' => $object_key,
        );
        if ( ! empty( $worker_cleanup ) ) {
            $reservation = array_merge( $reservation, $worker_cleanup );
        }
        $record['reservations'][ $reservation_id ] = $reservation;
        $record['updated_at'] = (int) $now;
        return array( 'ok' => true, 'record' => $record );
    }

    public static function local_outstanding_allocation_bytes( $record ) {
        if ( ! is_array( $record ) || ! isset( $record['reservations'] ) || ! is_array( $record['reservations'] ) ) {
            return null;
        }
        $outstanding = 0;
        foreach ( $record['reservations'] as $reservation ) {
            if ( ! is_array( $reservation )
                || ! isset( $reservation['artifact_store'], $reservation['bytes'], $reservation['transient_bytes'] )
            ) {
                return null;
            }
            if ( $reservation['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
                continue;
            }
            if ( ! is_int( $reservation['bytes'] )
                || ! is_int( $reservation['transient_bytes'] )
                || $reservation['bytes'] < 0
                || $reservation['transient_bytes'] < 0
                || $reservation['bytes'] > PHP_INT_MAX - $reservation['transient_bytes']
            ) {
                return null;
            }
            $claim = $reservation['bytes'] + $reservation['transient_bytes'];
            if ( $outstanding > PHP_INT_MAX - $claim ) {
                return null;
            }
            $outstanding += $claim;
        }
        return $outstanding;
    }

    public static function matches_intent_reservation( $record, $reservation_id, $intent_id, $object_key, $batch_id, $upload_id, $bytes, $created_at, $artifact_store, $artifact_store_identity ) {
        if ( ! is_array( $record ) || ! isset( $record['reservations'][ $reservation_id ] ) || ! is_array( $record['reservations'][ $reservation_id ] ) ) {
            return false;
        }
        $reservation = $record['reservations'][ $reservation_id ];
        return isset( $reservation['intent_id'], $reservation['object_key'], $reservation['batch_id'], $reservation['upload_id'], $reservation['bytes'], $reservation['created_at'], $reservation['artifact_store'], $reservation['artifact_store_identity'], $reservation['cleanup_started'] )
            && is_string( $reservation['intent_id'] )
            && hash_equals( $reservation['intent_id'], $intent_id )
            && is_string( $reservation['object_key'] )
            && hash_equals( $reservation['object_key'], $object_key )
            && $reservation['batch_id'] === $batch_id
            && $reservation['upload_id'] === $upload_id
            && $reservation['bytes'] === $bytes
            && $reservation['created_at'] === $created_at
            && $reservation['artifact_store'] === $artifact_store
            && is_string( $artifact_store_identity )
            && hash_equals( $reservation['artifact_store_identity'], $artifact_store_identity )
            && $reservation['cleanup_started'] === false;
    }

    public static function finish_committed( $record, $reservation_id, $batch_id, $upload_id, $object_key, $actual_bytes, $now ) {
        if ( ! isset( $record['reservations'][ $reservation_id ] ) ) {
            return $record;
        }
        $reservation = $record['reservations'][ $reservation_id ];
        if ( $reservation['batch_id'] !== $batch_id
            || $reservation['upload_id'] !== $upload_id
            || ! is_string( $object_key )
            || $object_key === ''
            || ! hash_equals( $reservation['object_key'], $object_key )
        ) {
            return null;
        }
        return self::settle( $record, $reservation_id, $actual_bytes, $now );
    }

    public static function finish_remote_committed( $record, $reservation_id, $authority, $actual_bytes, $now ) {
        if ( ! isset( $record['reservations'][ $reservation_id ] ) ) {
            return $record;
        }
        if ( ! self::remote_reservation_matches( $record['reservations'][ $reservation_id ], $authority ) ) {
            return null;
        }
        return self::settle( $record, $reservation_id, $actual_bytes, $now );
    }

    private static function settle( $record, $reservation_id, $actual_bytes, $now ) {
        $reservation = $record['reservations'][ $reservation_id ];
        $reserved_bytes = $reservation['bytes'];
        $artifact_store = $reservation['artifact_store'];
        $actual_bytes = (int) $actual_bytes;
        if ( $actual_bytes < 0
            || $record['total_bytes'] < (int) $reserved_bytes
            || $record['store_bytes'][ $artifact_store ] < (int) $reserved_bytes
            || $record['total_bytes'] - (int) $reserved_bytes > PHP_INT_MAX - $actual_bytes
            || $record['store_bytes'][ $artifact_store ] - (int) $reserved_bytes > PHP_INT_MAX - $actual_bytes
        ) {
            return null;
        }
        unset( $record['reservations'][ $reservation_id ] );
        $record['total_bytes'] = $record['total_bytes'] - (int) $reserved_bytes + $actual_bytes;
        $record['store_bytes'][ $artifact_store ] = $record['store_bytes'][ $artifact_store ] - (int) $reserved_bytes + $actual_bytes;
        $record['updated_at'] = (int) $now;
        return $record;
    }

    public static function prepare_item_release( $record, $batch_id, $upload_id, $bytes, $object_key, $created_at, $create_if_missing, $now, $artifact_store, $artifact_store_identity, $worker_cleanup = array() ) {
        $worker_cleanup = self::normalize_cleanup_fields( $artifact_store, $worker_cleanup );
        if ( ! is_string( $object_key )
            || ( (int) $bytes > 0 && $object_key === '' )
            || ! in_array( $artifact_store, array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ), true )
            || ! self::valid_store_identity( $artifact_store, $artifact_store_identity )
            || $worker_cleanup === null
        ) {
            return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
        }
        $matching = 0;
        foreach ( $record['reservations'] as $reservation ) {
            if ( $reservation['batch_id'] === $batch_id && $reservation['upload_id'] === $upload_id ) {
                $matching++;
                if ( ! hash_equals( $reservation['object_key'], $object_key )
                    || $reservation['artifact_store'] !== $artifact_store
                    || ! hash_equals( $reservation['artifact_store_identity'], $artifact_store_identity )
                    || ! self::cleanup_fields_match( $artifact_store, $reservation, $worker_cleanup )
                ) {
                    return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
                }
            }
        }
        if ( $matching > 1 ) {
            return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
        }

        $changed = false;
        if ( $matching === 0 && $create_if_missing && (int) $bytes > 0 ) {
            $reservation_id = hash( 'sha256', $batch_id . "\0" . $upload_id );
            if ( isset( $record['reservations'][ $reservation_id ] ) ) {
                return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
            }
            $reservation = array(
                'batch_id' => $batch_id,
                'upload_id' => $upload_id,
                'bytes' => (int) $bytes,
                'transient_bytes' => 0,
                'artifact_store' => $artifact_store,
                'artifact_store_identity' => $artifact_store_identity,
                'cleanup_started' => false,
                'object_key' => $object_key,
                'created_at' => (int) $created_at,
            );
            $record['reservations'][ $reservation_id ] = array_merge( $reservation, $worker_cleanup );
            $record['updated_at'] = (int) $now;
            $matching = 1;
            $changed = true;
        }

        return array(
            'ok' => true,
            'record' => $record,
            'matching_reservations' => $matching,
            'changed' => $changed,
        );
    }

    public static function finish_item_release( $record, $batch_id, $upload_id, $now ) {
        $released_bytes = 0;
        $released_by_store = array(
            FormProtocol::UPLOAD_TRANSPORT_LOCAL => 0,
            FormProtocol::UPLOAD_TRANSPORT_WORKER => 0,
        );
        $changed = false;
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            if ( $reservation['batch_id'] !== $batch_id || $reservation['upload_id'] !== $upload_id ) {
                continue;
            }
            if ( $released_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $released_bytes += $reservation['bytes'];
            $store = $reservation['artifact_store'];
            if ( $released_by_store[ $store ] > PHP_INT_MAX - $reservation['bytes'] ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $released_by_store[ $store ] += $reservation['bytes'];
            unset( $record['reservations'][ $reservation_id ] );
            $changed = true;
        }
        if ( $record['total_bytes'] < $released_bytes
            || $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_LOCAL ] < $released_by_store[ FormProtocol::UPLOAD_TRANSPORT_LOCAL ]
            || $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] < $released_by_store[ FormProtocol::UPLOAD_TRANSPORT_WORKER ]
        ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        if ( $changed ) {
            $record['total_bytes'] -= $released_bytes;
            foreach ( $released_by_store as $store => $bytes ) {
                $record['store_bytes'][ $store ] -= $bytes;
            }
            $record['updated_at'] = (int) $now;
        }
        return array(
            'ok' => true,
            'record' => $record,
            'released_bytes' => $released_bytes,
            'changed' => $changed,
        );
    }

    public static function release_aggregate( $record, $batch_id, $manifest_bytes, $attributed_bytes, $already_released_bytes, $artifact_store, $now ) {
        if ( ! in_array( $artifact_store, array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ), true ) ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $reservation_bytes = 0;
        $reserved_item_bytes = 0;
        $reserved_items = array();
        $remaining_reservations = array();
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            if ( $reservation['batch_id'] !== $batch_id ) {
                $remaining_reservations[ $reservation_id ] = $reservation;
                continue;
            }
            if ( $reservation['artifact_store'] !== $artifact_store ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            if ( $reservation_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $reservation_bytes += $reservation['bytes'];
            $upload_id = $reservation['upload_id'];
            if ( isset( $reserved_items[ $upload_id ] ) || ! isset( $attributed_bytes[ $upload_id ] ) ) {
                continue;
            }
            $bytes = $attributed_bytes[ $upload_id ];
            if ( $reserved_item_bytes > PHP_INT_MAX - $bytes ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $reserved_item_bytes += $bytes;
            $reserved_items[ $upload_id ] = true;
        }

        $unreserved_released_bytes = 0;
        foreach ( array_diff_key( $already_released_bytes, $reserved_items ) as $bytes ) {
            if ( $unreserved_released_bytes > PHP_INT_MAX - $bytes ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $unreserved_released_bytes += $bytes;
        }
        if ( $reserved_item_bytes > (int) $manifest_bytes
            || (int) $manifest_bytes - $reserved_item_bytes < $unreserved_released_bytes
        ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $base_release = (int) $manifest_bytes - $reserved_item_bytes - $unreserved_released_bytes;
        if ( $base_release > PHP_INT_MAX - $reservation_bytes ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $released_bytes = $base_release + $reservation_bytes;
        if ( $record['total_bytes'] < $released_bytes || $record['store_bytes'][ $artifact_store ] < $released_bytes ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }

        $record['reservations'] = $remaining_reservations;
        $record['total_bytes'] -= $released_bytes;
        $record['store_bytes'][ $artifact_store ] -= $released_bytes;
        $record['updated_at'] = (int) $now;
        return array(
            'ok' => true,
            'record' => $record,
            'released_bytes' => $released_bytes,
        );
    }

    public static function release_remote_aggregate_once( $record, $batch_id, $manifest_bytes, $attributed_bytes, $already_released_bytes, $artifact_store_identity, $now ) {
        if ( ! self::valid_store_identity( FormProtocol::UPLOAD_TRANSPORT_WORKER, $artifact_store_identity ) ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        if ( isset( $record['releases'][ $batch_id ] ) ) {
            $release = $record['releases'][ $batch_id ];
            return $release['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER
                && hash_equals( $release['artifact_store_identity'], $artifact_store_identity )
                    ? array( 'ok' => true, 'record' => $record, 'released_bytes' => $release['bytes'], 'changed' => false )
                    : array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $released = self::release_aggregate(
            $record,
            $batch_id,
            $manifest_bytes,
            $attributed_bytes,
            $already_released_bytes,
            FormProtocol::UPLOAD_TRANSPORT_WORKER,
            $now
        );
        if ( empty( $released['ok'] ) ) {
            return $released;
        }
        $released['record']['releases'][ $batch_id ] = array(
            'bytes' => (int) $released['released_bytes'],
            'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
            'artifact_store_identity' => $artifact_store_identity,
            'created_at' => (int) $now,
        );
        $released['changed'] = true;
        return $released;
    }

    public static function finish_remote_aggregate_release( $record, $batch_id, $artifact_store_identity, $now ) {
        if ( ! isset( $record['releases'][ $batch_id ] ) ) {
            return $record;
        }
        $release = $record['releases'][ $batch_id ];
        if ( $release['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
            || ! is_string( $artifact_store_identity )
            || ! hash_equals( $release['artifact_store_identity'], $artifact_store_identity )
        ) {
            return null;
        }
        unset( $record['releases'][ $batch_id ] );
        $record['updated_at'] = (int) $now;
        return $record;
    }

    public static function begin_remote_reservation_cleanup( $record, $reservation_id, $artifact_store_identity, $now ) {
        if ( ! isset( $record['reservations'][ $reservation_id ] ) ) {
            return null;
        }
        $reservation = $record['reservations'][ $reservation_id ];
        if ( $reservation['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
            || ! is_string( $artifact_store_identity )
            || ! hash_equals( $reservation['artifact_store_identity'], $artifact_store_identity )
        ) {
            return null;
        }
        $record['reservations'][ $reservation_id ]['cleanup_started'] = true;
        $record['updated_at'] = (int) $now;
        return $record;
    }

    public static function finish_remote_reservation_cleanup( $record, $reservation_id, $artifact_store_identity, $now ) {
        if ( ! isset( $record['reservations'][ $reservation_id ] ) ) {
            return null;
        }
        $reservation = $record['reservations'][ $reservation_id ];
        if ( $reservation['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
            || empty( $reservation['cleanup_started'] )
            || ! is_string( $artifact_store_identity )
            || ! hash_equals( $reservation['artifact_store_identity'], $artifact_store_identity )
            || $record['total_bytes'] < $reservation['bytes']
            || $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] < $reservation['bytes']
        ) {
            return null;
        }
        unset( $record['reservations'][ $reservation_id ] );
        $record['total_bytes'] -= $reservation['bytes'];
        $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] -= $reservation['bytes'];
        $record['updated_at'] = (int) $now;
        return $record;
    }

    public static function remote_reservation_matches( $reservation, $authority ) {
        if ( ! is_array( $reservation ) || ! is_array( $authority ) ) {
            return false;
        }
        return isset(
            $reservation['batch_id'],
            $reservation['upload_id'],
            $reservation['bytes'],
            $reservation['transient_bytes'],
            $reservation['artifact_store'],
            $reservation['artifact_store_identity'],
            $reservation['object_key'],
            $authority['batch_id'],
            $authority['upload_id'],
            $authority['bytes'],
            $authority['artifact_store_identity'],
            $authority['object_key']
        )
            && $reservation['batch_id'] === $authority['batch_id']
            && $reservation['upload_id'] === $authority['upload_id']
            && $reservation['bytes'] === $authority['bytes']
            && $reservation['transient_bytes'] === 0
            && $reservation['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER
            && hash_equals( $reservation['artifact_store_identity'], $authority['artifact_store_identity'] )
            && hash_equals( $reservation['object_key'], $authority['object_key'] )
            && self::cleanup_fields_match( FormProtocol::UPLOAD_TRANSPORT_WORKER, $reservation, $authority );
    }

    public static function reconcile( $record, $committed, $orphaned, $file_bytes, $stale_before, $now ) {
        $reservations = array();
        $reserved_bytes = 0;
        foreach ( $record['reservations'] as $id => $reservation ) {
            if ( isset( $committed[ $id ] ) ) {
                continue;
            }
            if ( isset( $orphaned[ $id ] ) ) {
                $reservation['bytes'] = max( $reservation['bytes'], (int) $orphaned[ $id ] );
            } elseif ( $reservation['created_at'] <= (int) $stale_before ) {
                continue;
            }
            if ( $reserved_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                return null;
            }
            $reservations[ $id ] = $reservation;
            $reserved_bytes += $reservation['bytes'];
        }
        $orphaned_file_bytes = array_sum( $orphaned );
        if ( $orphaned_file_bytes > $file_bytes || $file_bytes - $orphaned_file_bytes > PHP_INT_MAX - $reserved_bytes ) {
            return null;
        }
        $record['reservations'] = $reservations;
        $record['total_bytes'] = $file_bytes - $orphaned_file_bytes + $reserved_bytes;
        $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_LOCAL ] = $record['total_bytes'];
        $record['store_bytes'][ FormProtocol::UPLOAD_TRANSPORT_WORKER ] = 0;
        $record['updated_at'] = (int) $now;
        return $record;
    }

    private static function valid_store_identity( $artifact_store, $identity ) {
        if ( ! is_string( $identity ) ) {
            return false;
        }
        return $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL
            ? $identity === 'local'
            : ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER
                && preg_match( '/^[a-f0-9]{64}$/D', $identity ) === 1 );
    }

    private static function valid_store_bytes( $store_bytes, $total_bytes ) {
        return is_array( $store_bytes )
            && self::exact_keys( $store_bytes, array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ) )
            && is_int( $store_bytes[ FormProtocol::UPLOAD_TRANSPORT_LOCAL ] )
            && $store_bytes[ FormProtocol::UPLOAD_TRANSPORT_LOCAL ] >= 0
            && is_int( $store_bytes[ FormProtocol::UPLOAD_TRANSPORT_WORKER ] )
            && $store_bytes[ FormProtocol::UPLOAD_TRANSPORT_WORKER ] >= 0
            && $store_bytes[ FormProtocol::UPLOAD_TRANSPORT_LOCAL ] <= $total_bytes
            && $store_bytes[ FormProtocol::UPLOAD_TRANSPORT_WORKER ] === $total_bytes - $store_bytes[ FormProtocol::UPLOAD_TRANSPORT_LOCAL ];
    }

    private static function normalize_cleanup_fields( $artifact_store, $fields ) {
        if ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
            return $fields === array() ? array() : null;
        }
        if ( ! is_array( $fields )
            || array_keys( $fields ) !== array( 'validation_contract_version', 'policy_fingerprint', 'validation_until' )
            || ! self::valid_cleanup_fields( FormProtocol::UPLOAD_TRANSPORT_WORKER, $fields )
        ) {
            return null;
        }
        return array(
            'validation_contract_version' => $fields['validation_contract_version'],
            'policy_fingerprint' => $fields['policy_fingerprint'],
            'validation_until' => (int) $fields['validation_until'],
        );
    }

    private static function exact_keys( $value, $expected ) {
        if ( ! is_array( $value ) || ! is_array( $expected ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $expected, SORT_STRING );
        return $actual === $expected;
    }

    private static function valid_reservation_keys( $reservation, $reservation_id ) {
        $expected = array(
            'artifact_store',
            'artifact_store_identity',
            'batch_id',
            'bytes',
            'cleanup_started',
            'created_at',
            'object_key',
            'transient_bytes',
            'upload_id',
        );
        if ( ! isset( $reservation['batch_id'], $reservation['upload_id'] )
            || ! is_string( $reservation['batch_id'] )
            || ! is_string( $reservation['upload_id'] )
            || $reservation_id !== hash( 'sha256', $reservation['batch_id'] . "\0" . $reservation['upload_id'] )
        ) {
            return false;
        }
        if ( isset( $reservation['intent_id'] ) ) {
            $expected[] = 'intent_id';
        }
        if ( isset( $reservation['artifact_store'] ) && $reservation['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
            $expected[] = 'validation_contract_version';
            $expected[] = 'policy_fingerprint';
            $expected[] = 'validation_until';
        }
        return self::exact_keys( $reservation, $expected );
    }

    private static function reservation_object_key_matches( $reservation ) {
        $parts = ManagedArtifactKey::parse( $reservation['object_key'] );
        if ( $parts === null || ! hash_equals( $parts['namespace'], $reservation['batch_id'] ) ) {
            return false;
        }
        return ! isset( $reservation['intent_id'] ) || hash_equals( $parts['intent_id'], $reservation['intent_id'] );
    }

    private static function valid_cleanup_fields( $artifact_store, $record ) {
        $keys = array( 'validation_contract_version', 'policy_fingerprint', 'validation_until' );
        $present = 0;
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $record ) ) {
                $present++;
            }
        }
        if ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
            return $present === 0;
        }
        return $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER
            && $present === count( $keys )
            && isset( $record['validation_contract_version'], $record['policy_fingerprint'], $record['validation_until'] )
            && is_string( $record['validation_contract_version'] )
            && $record['validation_contract_version'] !== ''
            && strlen( $record['validation_contract_version'] ) <= Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' )
            && preg_match( '/^[A-Za-z0-9._:-]+$/D', $record['validation_contract_version'] ) === 1
            && is_string( $record['policy_fingerprint'] )
            && preg_match( '/^[0-9a-f]{64}$/D', $record['policy_fingerprint'] ) === 1
            && is_int( $record['validation_until'] )
            && $record['validation_until'] >= 0;
    }

    private static function cleanup_fields_match( $artifact_store, $reservation, $authority ) {
        foreach ( array( 'validation_contract_version', 'policy_fingerprint', 'validation_until' ) as $key ) {
            if ( array_key_exists( $key, $reservation ) !== array_key_exists( $key, $authority ) ) {
                return false;
            }
        }
        if ( ! self::valid_cleanup_fields( $artifact_store, $reservation )
            || ! self::valid_cleanup_fields( $artifact_store, $authority )
        ) {
            return false;
        }
        if ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
            return true;
        }
        return hash_equals( $reservation['validation_contract_version'], $authority['validation_contract_version'] )
            && hash_equals( $reservation['policy_fingerprint'], $authority['policy_fingerprint'] )
            && $reservation['validation_until'] === $authority['validation_until'];
    }
}
