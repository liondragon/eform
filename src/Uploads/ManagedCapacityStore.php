<?php
/**
 * Private persistence and arithmetic owner for managed upload capacity.
 *
 * UploadBatchStore remains the public facade and owns aggregate traversal and
 * lock ordering. This collaborator owns only the capacity record itself.
 */

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
                'updated_at' => $now === null ? time() : (int) $now,
            );
        }
        if ( ! is_file( $path ) ) {
            return null;
        }
        $json = @file_get_contents( $path );
        $record = is_string( $json ) && $json !== '' ? json_decode( $json, true ) : null;
        if ( ! is_array( $record )
            || ! isset( $record['version'], $record['total_bytes'], $record['reservations'] )
            || (int) $record['version'] !== (int) $version
            || ! is_int( $record['total_bytes'] )
            || $record['total_bytes'] < 0
            || ! is_array( $record['reservations'] )
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
                || ! is_int( $reservation['created_at'] )
                || $reserved_total > PHP_INT_MAX - $reservation['bytes']
            ) {
                return null;
            }
            $reserved_total += $reservation['bytes'];
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

    public static function reserve( $record, $reservation_id, $attempt_id, $batch_id, $upload_id, $bytes, $free_bytes, $minimum_free_bytes, $maximum_bytes, $source_bytes, $now ) {
        if ( ! is_string( $attempt_id ) || $attempt_id === '' ) {
            return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
        }
        $reusing = false;
        if ( isset( $record['reservations'][ $reservation_id ] ) ) {
            $existing = $record['reservations'][ $reservation_id ];
            if ( ! isset( $existing['batch_id'], $existing['upload_id'], $existing['bytes'] )
                || $existing['batch_id'] !== $batch_id
                || $existing['upload_id'] !== $upload_id
                || (int) $existing['bytes'] !== (int) $bytes
            ) {
                return array( 'ok' => false, 'reason' => 'capacity_reservation_conflict' );
            }
            $reusing = true;
        }
        if ( ! $reusing && $record['total_bytes'] > (int) $maximum_bytes - (int) $bytes ) {
            return array( 'ok' => false, 'reason' => 'managed_capacity_exceeded' );
        }
        $outstanding = 0;
        foreach ( $record['reservations'] as $reservation ) {
            if ( $outstanding > PHP_INT_MAX - $reservation['bytes'] ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $outstanding += $reservation['bytes'];
        }
        $additional_reservation = $reusing ? 0 : (int) $bytes;
        if ( $outstanding > PHP_INT_MAX - $additional_reservation || $outstanding + $additional_reservation > PHP_INT_MAX - (int) $source_bytes ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $projected = $outstanding + $additional_reservation + (int) $source_bytes;
        if ( $free_bytes === null || $free_bytes < $projected || $free_bytes - $projected < (int) $minimum_free_bytes ) {
            return array( 'ok' => false, 'reason' => $free_bytes === null ? 'free_space_unavailable' : 'free_space_reserve' );
        }

        if ( ! $reusing ) {
            $record['total_bytes'] += (int) $bytes;
        }
        $record['reservations'][ $reservation_id ] = array(
            'batch_id' => $batch_id,
            'upload_id' => $upload_id,
            'bytes' => (int) $bytes,
            'created_at' => (int) $now,
            'attempt_id' => $attempt_id,
        );
        $record['updated_at'] = (int) $now;
        return array( 'ok' => true, 'record' => $record );
    }

    public static function finish( $record, $reservation_id, $attempt_id, $actual_bytes, $now ) {
        if ( ! isset( $record['reservations'][ $reservation_id ] ) ) {
            return $record;
        }
        $reservation = $record['reservations'][ $reservation_id ];
        if ( ! isset( $reservation['attempt_id'] )
            || ! is_string( $reservation['attempt_id'] )
            || ! is_string( $attempt_id )
            || $attempt_id === ''
            || ! hash_equals( $reservation['attempt_id'], $attempt_id )
        ) {
            return $record;
        }
        return self::settle( $record, $reservation_id, $actual_bytes, $now );
    }

    public static function finish_committed( $record, $reservation_id, $batch_id, $upload_id, $actual_bytes, $now ) {
        if ( ! isset( $record['reservations'][ $reservation_id ] ) ) {
            return $record;
        }
        $reservation = $record['reservations'][ $reservation_id ];
        if ( $reservation['batch_id'] !== $batch_id || $reservation['upload_id'] !== $upload_id ) {
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

    public static function prepare_item_release( $record, $batch_id, $upload_id, $bytes, $created_at, $create_if_missing, $now ) {
        $matching = 0;
        foreach ( $record['reservations'] as $reservation ) {
            if ( $reservation['batch_id'] === $batch_id && $reservation['upload_id'] === $upload_id ) {
                $matching++;
            }
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

    public static function release_aggregate( $record, $batch_id, $manifest_bytes, $attributed_bytes, $missing_tombstone_bytes, $now ) {
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

        $ambiguous_tombstone_bytes = 0;
        foreach ( array_diff_key( $missing_tombstone_bytes, $reserved_items ) as $bytes ) {
            if ( $ambiguous_tombstone_bytes > PHP_INT_MAX - $bytes ) {
                return array( 'ok' => false, 'reason' => 'capacity_invalid' );
            }
            $ambiguous_tombstone_bytes += $bytes;
        }
        if ( $reserved_item_bytes > (int) $manifest_bytes
            || (int) $manifest_bytes - $reserved_item_bytes < $ambiguous_tombstone_bytes
        ) {
            return array( 'ok' => false, 'reason' => 'capacity_invalid' );
        }
        $base_release = (int) $manifest_bytes - $reserved_item_bytes - $ambiguous_tombstone_bytes;
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

    public static function source_bytes_on_managed_filesystem( $source_path, $private_dir, $source_bytes ) {
        $source_stat = is_string( $source_path ) && $source_path !== '' ? @stat( $source_path ) : false;
        $private_stat = @stat( $private_dir );
        return is_array( $source_stat )
            && is_array( $private_stat )
            && isset( $source_stat['dev'], $private_stat['dev'] )
            && $source_stat['dev'] === $private_stat['dev']
            ? max( 0, (int) $source_bytes )
            : 0;
    }

    public static function health( $record, $file_bytes, $committed, $orphaned ) {
        $reserved_bytes = 0;
        foreach ( $record['reservations'] as $reservation ) {
            if ( $reserved_bytes > PHP_INT_MAX - $reservation['bytes'] ) {
                return null;
            }
            $reserved_bytes += $reservation['bytes'];
        }
        $committed_bytes = array_sum( $committed );
        $orphaned_bytes = array_sum( $orphaned );
        $materialized_bytes = $committed_bytes + $orphaned_bytes;
        return array(
            'total_bytes' => $record['total_bytes'],
            'file_bytes' => $file_bytes,
            'reserved_bytes' => $reserved_bytes,
            'committing_bytes' => $materialized_bytes,
            'orphaned_bytes' => $orphaned_bytes,
            'consistent' => $materialized_bytes <= $file_bytes
                && $file_bytes - $materialized_bytes <= PHP_INT_MAX - $reserved_bytes
                && $record['total_bytes'] === $file_bytes - $materialized_bytes + $reserved_bytes,
        );
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
}
