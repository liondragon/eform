<?php
/**
 * Private persistence and arithmetic owner for managed upload capacity.
 *
 * UploadBatchStore remains the public facade and owns aggregate traversal and
 * lock ordering. This collaborator owns only the capacity record itself.
 */

require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../Security/Entropy.php';

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
        if ( ( ! $existing_only && ! @chmod( $path, 0600 ) ) || ! @flock( $handle, $operation ) ) {
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
            || ! isset( $record['version'], $record['total_bytes'], $record['reservations'], $record['releases'] )
            || (int) $record['version'] !== (int) $version
            || ! is_int( $record['total_bytes'] )
            || $record['total_bytes'] < 0
            || ! is_array( $record['reservations'] )
            || ! is_array( $record['releases'] )
        ) {
            return null;
        }
        $reserved_total = 0;
        foreach ( $record['reservations'] as $reservation ) {
            if ( ! is_array( $reservation )
                || ! isset( $reservation['batch_id'], $reservation['upload_id'], $reservation['bytes'], $reservation['created_at'] )
                || ! is_string( $reservation['batch_id'] )
                || ! is_string( $reservation['upload_id'] )
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
                || $reservation['object_key'] === ''
                || ! is_int( $reservation['created_at'] )
                || $reserved_total > PHP_INT_MAX - $reservation['bytes']
            ) {
                return null;
            }
            $reserved_total += $reservation['bytes'];
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
            ) {
                return null;
            }
        }
        return $reserved_total <= $record['total_bytes'] ? $record : null;
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
        if ( ! $ok || ! @chmod( $temp, 0600 ) || ! @rename( $temp, $path ) ) {
            @unlink( $temp );
            return false;
        }
        return @chmod( $path, 0600 );
    }

    public static function reserve( $record, $reservation_id, $intent_id, $object_key, $batch_id, $upload_id, $bytes, $free_bytes, $minimum_free_bytes, $maximum_bytes, $transient_bytes, $now, $artifact_store, $artifact_store_identity, $materialized_transient_bytes = 0 ) {
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
        }
        $record['reservations'][ $reservation_id ] = array(
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

    private static function settle( $record, $reservation_id, $actual_bytes, $now ) {
        $reserved_bytes = $record['reservations'][ $reservation_id ]['bytes'];
        $actual_bytes = (int) $actual_bytes;
        if ( $actual_bytes < 0
            || $record['total_bytes'] < (int) $reserved_bytes
            || $record['total_bytes'] - (int) $reserved_bytes > PHP_INT_MAX - $actual_bytes
        ) {
            return null;
        }
        unset( $record['reservations'][ $reservation_id ] );
        $record['total_bytes'] = $record['total_bytes'] - (int) $reserved_bytes + $actual_bytes;
        $record['updated_at'] = (int) $now;
        return $record;
    }

    public static function prepare_item_release( $record, $batch_id, $upload_id, $bytes, $object_key, $created_at, $create_if_missing, $now, $artifact_store, $artifact_store_identity ) {
        if ( ! is_string( $object_key )
            || ( (int) $bytes > 0 && $object_key === '' )
            || ! in_array( $artifact_store, array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ), true )
            || ! self::valid_store_identity( $artifact_store, $artifact_store_identity )
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
            $record['reservations'][ $reservation_id ] = array(
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
        $changed = false;
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            if ( $reservation['batch_id'] !== $batch_id || $reservation['upload_id'] !== $upload_id ) {
                continue;
            }
            if ( $released_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $released_bytes += $reservation['bytes'];
            unset( $record['reservations'][ $reservation_id ] );
            $changed = true;
        }
        if ( $record['total_bytes'] < $released_bytes ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        if ( $changed ) {
            $record['total_bytes'] -= $released_bytes;
            $record['updated_at'] = (int) $now;
        }
        return array(
            'ok' => true,
            'record' => $record,
            'released_bytes' => $released_bytes,
            'changed' => $changed,
        );
    }

    public static function release_aggregate( $record, $batch_id, $manifest_bytes, $attributed_bytes, $already_released_bytes, $now ) {
        $reservation_bytes = 0;
        $reserved_item_bytes = 0;
        $reserved_items = array();
        $remaining_reservations = array();
        foreach ( $record['reservations'] as $reservation_id => $reservation ) {
            if ( $reservation['batch_id'] !== $batch_id ) {
                $remaining_reservations[ $reservation_id ] = $reservation;
                continue;
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
        if ( $record['total_bytes'] < $released_bytes ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }

        $record['reservations'] = $remaining_reservations;
        $record['total_bytes'] -= $released_bytes;
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
        ) {
            return null;
        }
        unset( $record['reservations'][ $reservation_id ] );
        $record['total_bytes'] -= $reservation['bytes'];
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
            && hash_equals( $reservation['object_key'], $authority['object_key'] );
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
}
