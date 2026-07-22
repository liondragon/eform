<?php
/**
 * Canonical signed envelopes shared with the eForms media Worker.
 *
 * WordPress signs capabilities and verifies immutable Worker facts. The
 * browser may carry these tokens, but it never gets signing authority.
 */

require_once __DIR__ . '/../Anchors.php';

final class WorkerProtocol {
    const VERSION = '1';
    const REVIEW_RECIPE_VERSION = 'review-jpeg-v1';
    const UPLOAD_GRANT_DOMAIN = 'eforms-worker-upload-grant';
    const UPLOAD_RECEIPT_DOMAIN = 'eforms-worker-upload-receipt';
    const REVIEW_GRANT_DOMAIN = 'eforms-worker-review-grant';
    const OBJECT_REQUEST_DOMAIN = 'eforms-worker-object-request';
    const OBJECT_RESULT_DOMAIN = 'eforms-worker-object-result';
    const HEALTH_REQUEST_DOMAIN = 'eforms-worker-health-request';
    const HEALTH_RESULT_DOMAIN = 'eforms-worker-health-result';

    const SCHEMAS = array(
        'upload_grant' => array(
            'domain' => self::UPLOAD_GRANT_DOMAIN,
            'fields' => array(
                'intent_id' => 'digest',
                'batch_id' => 'digest',
                'upload_id' => 'managed_id',
                'ordinal' => 'uint',
                'object_key' => 'object_key',
                'declared_bytes' => 'positive_int',
                'declared_mime' => 'mime',
                'policy_fingerprint' => 'hex_digest',
                'max_bytes' => 'positive_int',
                'max_edge' => 'positive_int',
                'max_pixels' => 'positive_int',
                'container_entry_limit' => 'positive_int',
                'intent_expires_at' => 'positive_int',
                'grant_expires_at' => 'positive_int',
                'upload_max_seconds' => 'positive_int',
                'receipt_ttl_seconds' => 'positive_int',
            ),
        ),
        'upload_receipt' => array(
            'domain' => self::UPLOAD_RECEIPT_DOMAIN,
            'fields' => array(
                'intent_id' => 'digest',
                'batch_id' => 'digest',
                'upload_id' => 'managed_id',
                'ordinal' => 'uint',
                'object_key' => 'object_key',
                'object_version' => 'opaque',
                'etag' => 'opaque',
                'bytes' => 'positive_int',
                'mime' => 'mime',
                'width' => 'positive_int',
                'height' => 'positive_int',
                'policy_fingerprint' => 'hex_digest',
                'expires_at' => 'positive_int',
            ),
        ),
        'review_grant' => array(
            'domain' => self::REVIEW_GRANT_DOMAIN,
            'fields' => array(
                'submission_id' => 'managed_id',
                'upload_id' => 'managed_id',
                'object_key' => 'object_key',
                'object_version' => 'opaque',
                'action' => 'review_action',
                'recipe_version' => 'opaque',
                'expires_at' => 'positive_int',
            ),
        ),
        'object_request' => array(
            'domain' => self::OBJECT_REQUEST_DOMAIN,
            'fields' => array(
                'request_id' => 'managed_id',
                'object_key' => 'object_key',
                'object_version' => 'opaque',
                'action' => 'object_action',
                'expires_at' => 'positive_int',
            ),
        ),
        'object_result' => array(
            'domain' => self::OBJECT_RESULT_DOMAIN,
            'fields' => array(
                'request_id' => 'managed_id',
                'object_key' => 'object_key',
                'object_version' => 'opaque',
                'status' => 'object_status',
                'expires_at' => 'positive_int',
            ),
        ),
        'health_request' => array(
            'domain' => self::HEALTH_REQUEST_DOMAIN,
            'fields' => array(
                'request_id' => 'managed_id',
                'expires_at' => 'positive_int',
            ),
        ),
        'health_result' => array(
            'domain' => self::HEALTH_RESULT_DOMAIN,
            'fields' => array(
                'request_id' => 'managed_id',
                'storage_ready' => 'boolean',
                'inspection_ready' => 'boolean',
                'checked_at' => 'positive_int',
                'expires_at' => 'positive_int',
            ),
        ),
    );

    public static function sign_upload_grant( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'upload_grant', $claims, $key_id, $secret, $environment );
    }

    public static function verify_upload_receipt( $token, $keys, $environment, $now = null ) {
        return self::verify( 'upload_receipt', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    public static function sign_review_grant( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'review_grant', $claims, $key_id, $secret, $environment );
    }

    public static function sign_health_request( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'health_request', $claims, $key_id, $secret, $environment );
    }

    public static function sign_object_request( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'object_request', $claims, $key_id, $secret, $environment );
    }

    public static function verify_object_result( $token, $keys, $environment, $now = null ) {
        return self::verify( 'object_result', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    public static function verify_health_result( $token, $keys, $environment, $now = null ) {
        return self::verify( 'health_result', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    /**
     * Decode one deployment secret without accepting padded or non-canonical
     * base64url. Deployment owners can use this before building a keyring.
     */
    public static function decode_integration_key( $encoded ) {
        $decoded = self::base64url_decode( $encoded );
        return is_string( $decoded ) && strlen( $decoded ) === Anchors::get( 'WORKER_INTEGRATION_KEY_BYTES' ) ? $decoded : '';
    }

    /**
     * Validate one deployment keyring without placing secret material in the
     * Config snapshot, diagnostics, manifests, or browser settings.
     */
    public static function key_configuration( $environment, $active_id, $active_encoded, $secondary_id = '', $secondary_encoded = '' ) {
        $active = self::decode_integration_key( $active_encoded );
        if ( ! self::valid_binding( $environment ) || ! self::valid_binding( $active_id ) || $active === '' ) {
            return null;
        }
        $keys = array( $active_id => $active );
        if ( $secondary_id !== '' || $secondary_encoded !== '' ) {
            $secondary = self::decode_integration_key( $secondary_encoded );
            if ( ! self::valid_binding( $secondary_id ) || hash_equals( $active_id, $secondary_id ) || $secondary === '' ) {
                return null;
            }
            $keys[ $secondary_id ] = $secondary;
        }
        return array(
            'environment' => $environment,
            'active_id' => $active_id,
            'active' => $active,
            'keys' => $keys,
        );
    }

    private static function sign( $schema_name, $claims, $key_id, $secret, $environment ) {
        $schema = isset( self::SCHEMAS[ $schema_name ] ) ? self::SCHEMAS[ $schema_name ] : null;
        if ( ! is_array( $schema ) || ! self::valid_binding( $key_id ) || ! self::valid_binding( $environment ) || ! is_string( $secret ) || strlen( $secret ) !== Anchors::get( 'WORKER_INTEGRATION_KEY_BYTES' ) ) {
            return '';
        }
        $normalized = self::normalize_claims( $claims, $schema['fields'] );
        if ( $normalized === null ) {
            return '';
        }
        $parts = array( $schema['domain'], self::VERSION, $key_id, $environment );
        foreach ( $schema['fields'] as $field => $type ) {
            $parts[] = $normalized[ $field ];
        }
        $payload = self::encode_parts( $parts );
        if ( $payload === '' ) {
            return '';
        }
        $signature = hash_hmac( 'sha256', $payload, $secret, true );
        return self::base64url_encode( $payload ) . '.' . self::base64url_encode( $signature );
    }

    private static function verify( $schema_name, $token, $keys, $environment, $now, $clock_skew = 0 ) {
        $failure = array( 'ok' => false, 'reason' => 'invalid_envelope' );
        $schema = isset( self::SCHEMAS[ $schema_name ] ) ? self::SCHEMAS[ $schema_name ] : null;
        if ( ! is_array( $schema ) || ! is_string( $token ) || ! is_array( $keys ) || ! self::valid_binding( $environment ) ) {
            return $failure;
        }
        $segments = explode( '.', $token );
        if ( count( $segments ) !== 2 ) {
            return $failure;
        }
        $payload = self::base64url_decode( $segments[0] );
        $signature = self::base64url_decode( $segments[1] );
        if ( ! is_string( $payload ) || ! is_string( $signature ) || strlen( $signature ) !== 32 ) {
            return $failure;
        }
        $expected_count = 4 + count( $schema['fields'] );
        $parts = self::decode_parts( $payload, $expected_count );
        if ( $parts === null
            || $parts[0] !== $schema['domain']
            || $parts[1] !== self::VERSION
            || ! self::valid_binding( $parts[2] )
            || $parts[3] !== $environment
            || ! isset( $keys[ $parts[2] ] )
            || ! is_string( $keys[ $parts[2] ] )
            || strlen( $keys[ $parts[2] ] ) !== Anchors::get( 'WORKER_INTEGRATION_KEY_BYTES' )
        ) {
            return $failure;
        }
        $expected = hash_hmac( 'sha256', $payload, $keys[ $parts[2] ], true );
        if ( ! hash_equals( $expected, $signature ) ) {
            return $failure;
        }
        $claims = array();
        $index = 4;
        foreach ( $schema['fields'] as $field => $type ) {
            $value = self::canonical_value( $parts[ $index ], $type );
            if ( $value === null ) {
                return $failure;
            }
            $claims[ $field ] = self::typed_value( $value, $type );
            $index++;
        }
        $expiry_field = $schema_name === 'upload_grant' ? 'grant_expires_at' : 'expires_at';
        $clock = is_numeric( $now ) ? (int) $now : time();
        $clock_skew = is_int( $clock_skew ) && $clock_skew >= 0 ? $clock_skew : 0;
        if ( ! isset( $claims[ $expiry_field ] ) || $claims[ $expiry_field ] < $clock - $clock_skew ) {
            return array( 'ok' => false, 'reason' => 'expired_envelope' );
        }
        return array(
            'ok' => true,
            'key_id' => $parts[2],
            'claims' => $claims,
        );
    }

    private static function normalize_claims( $claims, $fields ) {
        if ( ! is_array( $claims ) ) {
            return null;
        }
        $actual = array_keys( $claims );
        $expected = array_keys( $fields );
        sort( $actual, SORT_STRING );
        $sorted_expected = $expected;
        sort( $sorted_expected, SORT_STRING );
        if ( $actual !== $sorted_expected ) {
            return null;
        }
        $normalized = array();
        foreach ( $fields as $field => $type ) {
            $value = self::canonical_value( $claims[ $field ], $type );
            if ( $value === null ) {
                return null;
            }
            $normalized[ $field ] = $value;
        }
        return $normalized;
    }

    private static function canonical_value( $value, $type ) {
        if ( $type === 'uint' || $type === 'positive_int' ) {
            if ( is_int( $value ) ) {
                $value = (string) $value;
            }
            if ( ! is_string( $value ) || preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) !== 1 || strlen( $value ) > 19 ) {
                return null;
            }
            if ( $type === 'positive_int' && $value === '0' ) {
                return null;
            }
            $integer = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
            return is_int( $integer )
                && $integer <= 9007199254740991
                && (string) $integer === $value
                ? $value
                : null;
        }
        if ( $type === 'boolean' ) {
            if ( $value === true || $value === 1 ) {
                return '1';
            }
            if ( $value === false || $value === 0 ) {
                return '0';
            }
            return $value === '0' || $value === '1' ? $value : null;
        }
        if ( ! is_string( $value ) || preg_match( '//u', $value ) !== 1 ) {
            return null;
        }
        $patterns = array(
            'digest' => '/^[A-Za-z0-9_-]{43}$/D',
            'managed_id' => '/^[A-Za-z0-9_-]{1,' . Anchors::get( 'MANAGED_ID_MAX_CHARS' ) . '}$/D',
            'object_key' => '#^artifacts/[0-9a-f]{2}/[0-9a-f]{64}$#D',
            'opaque' => '/^[A-Za-z0-9._:-]{1,' . Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) . '}$/D',
            'hex_digest' => '/^[0-9a-f]{64}$/D',
            'mime' => '#^image/(?:jpeg|png|webp|heic|heif)$#D',
            'review_action' => '/^(?:preview|download)$/D',
            'object_action' => '/^(?:delete|inspect)$/D',
            'object_status' => '/^(?:present|absent|version_mismatch)$/D',
        );
        return isset( $patterns[ $type ] ) && preg_match( $patterns[ $type ], $value ) === 1 ? $value : null;
    }

    private static function typed_value( $value, $type ) {
        if ( $type === 'uint' || $type === 'positive_int' ) {
            return (int) $value;
        }
        if ( $type === 'boolean' ) {
            return $value === '1';
        }
        return $value;
    }

    private static function valid_binding( $value ) {
        return is_string( $value ) && preg_match( '/^[A-Za-z0-9._-]{1,64}$/D', $value ) === 1;
    }

    private static function encode_parts( $parts ) {
        $encoded = '';
        foreach ( $parts as $part ) {
            if ( ! is_string( $part ) || strlen( $part ) > 0xffffffff ) {
                return '';
            }
            $encoded .= pack( 'N', strlen( $part ) ) . $part;
        }
        return $encoded;
    }

    private static function decode_parts( $payload, $expected_count ) {
        $parts = array();
        $offset = 0;
        $length = strlen( $payload );
        while ( $offset < $length && count( $parts ) <= $expected_count ) {
            if ( $length - $offset < 4 ) {
                return null;
            }
            $header = unpack( 'Nlength', substr( $payload, $offset, 4 ) );
            $part_length = isset( $header['length'] ) ? $header['length'] : -1;
            $offset += 4;
            if ( ! is_int( $part_length ) || $part_length < 0 || $part_length > $length - $offset ) {
                return null;
            }
            $part = substr( $payload, $offset, $part_length );
            if ( preg_match( '//u', $part ) !== 1 ) {
                return null;
            }
            $parts[] = $part;
            $offset += $part_length;
        }
        return $offset === $length && count( $parts ) === $expected_count ? $parts : null;
    }

    private static function base64url_encode( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    private static function base64url_decode( $encoded ) {
        if ( ! is_string( $encoded ) || $encoded === '' || preg_match( '/^[A-Za-z0-9_-]+$/D', $encoded ) !== 1 || strlen( $encoded ) % 4 === 1 ) {
            return false;
        }
        $padding = ( 4 - strlen( $encoded ) % 4 ) % 4;
        $decoded = base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', $padding ), true );
        return is_string( $decoded ) && self::base64url_encode( $decoded ) === $encoded ? $decoded : false;
    }
}
