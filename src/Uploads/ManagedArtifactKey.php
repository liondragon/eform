<?php
/**
 * Canonical managed-artifact object keys shared by local and Worker storage.
 *
 * One upload batch owns one stable storage namespace. Finalization associates
 * that namespace with a submission without moving authoritative bytes.
 */

require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/UploadPolicy.php';

final class ManagedArtifactKey {
    const ROOT_DIR = 'artifacts';

    public static function create( $batch_id, $ordinal, $intent_id, $mime ) {
        $extension = UploadPolicy::staged_extension_for_mime( $mime );
        if ( ! self::valid_digest( $batch_id )
            || ! is_int( $ordinal )
            || $ordinal < 0
            || ! self::valid_digest( $intent_id )
            || $extension === ''
        ) {
            return '';
        }
        return self::ROOT_DIR
            . '/' . Helpers::h2( $batch_id )
            . '/' . $batch_id
            . '/' . $ordinal . '-' . $intent_id . '.' . $extension;
    }

    public static function parse( $object_key ) {
        $digest_pattern = '[A-Za-z0-9_-]{' . Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) . '}';
        if ( ! is_string( $object_key )
            || preg_match(
                '#^artifacts/([0-9a-f]{2})/(' . $digest_pattern . ')/((?:0|[1-9][0-9]*))-(' . $digest_pattern . ')\.([a-z0-9]{1,16})$#D',
                $object_key,
                $matches
            ) !== 1
            || Helpers::h2( $matches[2] ) !== $matches[1]
            || ! UploadPolicy::staged_extension_supported( $matches[5] )
        ) {
            return null;
        }
        $ordinal = filter_var( $matches[3], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
        if ( ! is_int( $ordinal ) || (string) $ordinal !== $matches[3] ) {
            return null;
        }
        return array(
            'shard' => $matches[1],
            'namespace' => $matches[2],
            'ordinal' => $ordinal,
            'intent_id' => $matches[4],
            'extension' => $matches[5],
            'filename' => $matches[3] . '-' . $matches[4] . '.' . $matches[5],
        );
    }

    public static function valid( $object_key ) {
        return self::parse( $object_key ) !== null;
    }

    public static function matches( $object_key, $batch_id, $ordinal = null, $mime = null ) {
        $parts = self::parse( $object_key );
        if ( $parts === null || ! is_string( $batch_id ) || ! hash_equals( $parts['namespace'], $batch_id ) ) {
            return false;
        }
        if ( $ordinal !== null && ( ! is_int( $ordinal ) || $parts['ordinal'] !== $ordinal ) ) {
            return false;
        }
        if ( $mime !== null ) {
            if ( ! UploadPolicy::staged_mime_matches_extension( $mime, $parts['extension'] ) ) {
                return false;
            }
        }
        return true;
    }

    public static function valid_digest( $value ) {
        return is_string( $value )
            && preg_match( '/^[A-Za-z0-9_-]{' . Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) . '}$/D', $value ) === 1;
    }
}
