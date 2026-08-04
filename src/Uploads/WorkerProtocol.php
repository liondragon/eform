<?php
/**
 * Canonical signed envelopes shared with the eForms media Worker.
 *
 * WordPress signs capabilities and verifies immutable Worker facts. The
 * browser may carry these tokens, but it never gets signing authority.
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/ManagedArtifactKey.php';

final class WorkerProtocol {
    const VERSION = '3';
    const WORKER_VALIDATION_CONTRACT_VERSION = 'managed-image-v1';
    const REVIEW_RECIPE_VERSION = 'review-jpeg-v1';
    const UPLOAD_GRANT_DOMAIN = 'eforms-worker-upload-grant';
    const WORKER_STORED_RECEIPT_DOMAIN = 'eforms-worker-stored-receipt';
    const WORKER_GALLERY_STATUS_REQUEST_DOMAIN = 'eforms-worker-gallery-status-request';
    const WORKER_GALLERY_STATUS_RESULT_DOMAIN = 'eforms-worker-gallery-status-result';
    const REVIEW_GRANT_DOMAIN = 'eforms-worker-review-grant';
    const OBJECT_REQUEST_DOMAIN = 'eforms-worker-object-request';
    const OBJECT_RESULT_DOMAIN = 'eforms-worker-object-result';
    const HEALTH_REQUEST_DOMAIN = 'eforms-worker-health-request';
    const HEALTH_RESULT_DOMAIN = 'eforms-worker-health-result';

    public static function valid_validation_contract_version( $version ) {
        return self::canonical_value( $version, 'opaque' ) !== null;
    }

    const SCHEMAS = array(
        'health_request' => array(
            'domain' => self::HEALTH_REQUEST_DOMAIN,
            'fields' => array(
                'request_id' => 'managed_id',
                'storage_identity' => 'hex_digest',
                'validation_contract_version' => 'opaque',
                'expires_at' => 'positive_int',
            ),
        ),
        'health_result' => array(
            'domain' => self::HEALTH_RESULT_DOMAIN,
            'fields' => array(
                'request_id' => 'managed_id',
                'storage_ready' => 'boolean',
                'inspection_ready' => 'boolean',
                'queue_producer_ready' => 'boolean',
                'limiter_ready' => 'boolean',
                'keys_ready' => 'boolean',
                'storage_identity_ready' => 'boolean',
                'validation_contract_ready' => 'boolean',
                'storage_identity' => 'hex_digest',
                'validation_contract_version' => 'opaque',
                'checked_at' => 'positive_int',
                'expires_at' => 'positive_int',
            ),
        ),
        'worker_upload_grant' => array(
            'domain' => self::UPLOAD_GRANT_DOMAIN,
            'version' => self::VERSION,
            'expiry' => 'grant_expires_at',
            'deadline_order' => true,
            'fields' => array(
                'intent_id' => 'digest',
                'batch_id' => 'digest',
                'upload_id' => 'managed_id',
                'ordinal' => 'uint',
                'storage_identity' => 'hex_digest',
                'validation_contract_version' => 'opaque',
                'object_key' => 'object_key',
                'declared_bytes' => 'positive_int',
                'declared_mime' => 'mime',
                'policy_fingerprint' => 'hex_digest',
                'max_bytes' => 'positive_int',
                'max_edge' => 'positive_int',
                'max_pixels' => 'positive_int',
                'container_entry_limit' => 'positive_int',
                'upload_until' => 'positive_int',
                'accept_until' => 'positive_int',
                'validation_until' => 'positive_int',
                'staged_delete_after' => 'positive_int',
                'grant_expires_at' => 'positive_int',
            ),
        ),
        'worker_stored_receipt' => array(
            'domain' => self::WORKER_STORED_RECEIPT_DOMAIN,
            'version' => self::VERSION,
            'fields' => array(
                'intent_id' => 'digest',
                'batch_id' => 'digest',
                'upload_id' => 'managed_id',
                'ordinal' => 'uint',
                'storage_identity' => 'hex_digest',
                'validation_contract_version' => 'opaque',
                'object_key' => 'object_key',
                'object_version' => 'known_opaque',
                'etag' => 'known_opaque',
                'bytes' => 'positive_int',
                'policy_fingerprint' => 'hex_digest',
                'expires_at' => 'positive_int',
            ),
        ),
        'worker_gallery_status_request' => array(
            'domain' => self::WORKER_GALLERY_STATUS_REQUEST_DOMAIN,
            'version' => self::VERSION,
            'closed_at_equality' => true,
            'fields' => array(
                'request_id' => 'managed_id',
                'submission_id' => 'managed_id',
                'storage_identity' => 'hex_digest',
                'items_sha256' => 'hex_digest',
                'item_count' => 'uint',
                'expires_at' => 'positive_int',
            ),
        ),
        'worker_gallery_status_result' => array(
            'domain' => self::WORKER_GALLERY_STATUS_RESULT_DOMAIN,
            'version' => self::VERSION,
            'closed_at_equality' => true,
            'fields' => array(
                'request_id' => 'managed_id',
                'submission_id' => 'managed_id',
                'items_sha256' => 'hex_digest',
                'statuses_sha256' => 'hex_digest',
                'item_count' => 'uint',
                'checked_at' => 'positive_int',
                'expires_at' => 'positive_int',
            ),
        ),
        'worker_review_grant' => array(
            'domain' => self::REVIEW_GRANT_DOMAIN,
            'version' => self::VERSION,
            'closed_at_equality' => true,
            'fields' => array(
                'submission_id' => 'managed_id',
                'upload_id' => 'managed_id',
                'storage_identity' => 'hex_digest',
                'validation_contract_version' => 'opaque',
                'object_key' => 'object_key',
                'object_version' => 'known_opaque',
                'etag' => 'known_opaque',
                'bytes' => 'positive_int',
                'policy_fingerprint' => 'hex_digest',
                'validation_until' => 'positive_int',
                'action' => 'review_action',
                'recipe_version' => 'opaque',
                'expires_at' => 'positive_int',
            ),
        ),
        'worker_object_request' => array(
            'domain' => self::OBJECT_REQUEST_DOMAIN,
            'version' => self::VERSION,
            'closed_at_equality' => true,
            'worker_object_request' => true,
            'fields' => array(
                'request_id' => 'managed_id',
                'batch_id' => 'digest',
                'intent_id' => 'digest',
                'upload_id' => 'managed_id',
                'ordinal' => 'uint',
                'storage_identity' => 'hex_digest',
                'validation_contract_version' => 'opaque',
                'object_key' => 'object_key',
                'object_version' => 'worker_object_version_or_unknown',
                'etag' => 'worker_etag_or_unknown',
                'bytes' => 'positive_int',
                'policy_fingerprint' => 'hex_digest',
                'action' => 'object_action',
                'expires_at' => 'positive_int',
            ),
        ),
        'worker_object_result' => array(
            'domain' => self::OBJECT_RESULT_DOMAIN,
            'version' => self::VERSION,
            'closed_at_equality' => true,
            'fields' => array(
                'request_id' => 'managed_id',
                'object_key' => 'object_key',
                'object_version' => 'worker_object_version_or_unknown',
                'status' => 'object_status',
                'expires_at' => 'positive_int',
            ),
        ),
    );

    const WORKER_GALLERY_ITEM_FIELDS = array(
        'upload_id' => 'managed_id',
        'ordinal' => 'uint',
        'validation_contract_version' => 'opaque',
        'object_key' => 'object_key',
        'object_version' => 'known_opaque',
        'etag' => 'known_opaque',
        'bytes' => 'positive_int',
        'policy_fingerprint' => 'hex_digest',
        'validation_until' => 'positive_int',
    );

    const WORKER_GALLERY_STATUS_PENDING_FIELDS = array(
        'upload_id' => 'managed_id',
        'status' => 'gallery_status',
    );

    const WORKER_GALLERY_STATUS_ACCEPTED_FIELDS = array(
        'upload_id' => 'managed_id',
        'status' => 'gallery_status',
        'mime' => 'mime',
        'width' => 'positive_int',
        'height' => 'positive_int',
    );

    public static function sign_worker_upload_grant( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'worker_upload_grant', $claims, $key_id, $secret, $environment );
    }

    public static function verify_worker_upload_grant( $token, $keys, $environment, $now = null ) {
        return self::verify( 'worker_upload_grant', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    public static function sign_worker_stored_receipt( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'worker_stored_receipt', $claims, $key_id, $secret, $environment );
    }

    public static function verify_worker_stored_receipt( $token, $keys, $environment, $now = null ) {
        return self::verify( 'worker_stored_receipt', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    public static function sign_worker_gallery_status_request( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'worker_gallery_status_request', $claims, $key_id, $secret, $environment );
    }

    public static function verify_worker_gallery_status_request( $token, $keys, $environment, $now = null ) {
        return self::verify( 'worker_gallery_status_request', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    public static function sign_worker_gallery_status_result( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'worker_gallery_status_result', $claims, $key_id, $secret, $environment );
    }

    public static function verify_worker_gallery_status_result( $token, $keys, $environment, $now = null ) {
        return self::verify( 'worker_gallery_status_result', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    public static function sign_worker_review_grant( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'worker_review_grant', $claims, $key_id, $secret, $environment );
    }

    public static function verify_worker_review_grant( $token, $keys, $environment, $now = null ) {
        return self::verify( 'worker_review_grant', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    public static function sign_worker_object_request( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'worker_object_request', $claims, $key_id, $secret, $environment );
    }

    public static function verify_worker_object_request( $token, $keys, $environment, $now = null ) {
        return self::verify( 'worker_object_request', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    public static function sign_worker_object_result( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'worker_object_result', $claims, $key_id, $secret, $environment );
    }

    public static function verify_worker_object_result( $token, $keys, $environment, $now = null ) {
        return self::verify( 'worker_object_result', $token, $keys, $environment, $now, Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) );
    }

    public static function normalize_worker_gallery_items( $items ) {
        if ( ! self::is_list_array( $items ) || count( $items ) > Anchors::get( 'MANAGED_STAGED_MAX_FILES' ) ) {
            return null;
        }
        $normalized = array();
        $seen_uploads = array();
        $seen_ordinals = array();
        $namespace = null;
        foreach ( $items as $item ) {
            $candidate = self::normalize_exact_json_object_fields( $item, self::WORKER_GALLERY_ITEM_FIELDS );
            $object_key = $candidate === null ? null : ManagedArtifactKey::parse( $candidate['object_key'] );
            if ( $candidate === null
                || $object_key === null
                || $object_key['ordinal'] !== $candidate['ordinal']
                || ( $namespace !== null && ! hash_equals( $namespace, $object_key['namespace'] ) )
                || isset( $seen_uploads[ $candidate['upload_id'] ] )
                || isset( $seen_ordinals[ $candidate['ordinal'] ] )
            ) {
                return null;
            }
            $namespace = $namespace === null ? $object_key['namespace'] : $namespace;
            $seen_uploads[ $candidate['upload_id'] ] = true;
            $seen_ordinals[ $candidate['ordinal'] ] = true;
            $normalized[] = $candidate;
        }
        $expected = $normalized;
        usort(
            $expected,
            function ( $a, $b ) {
                if ( $a['ordinal'] === $b['ordinal'] ) {
                    return strcmp( $a['upload_id'], $b['upload_id'] );
                }
                return $a['ordinal'] < $b['ordinal'] ? -1 : 1;
            }
        );
        return $normalized === $expected ? $normalized : null;
    }

    public static function normalize_worker_gallery_statuses( $statuses, $items = null ) {
        if ( ! self::is_list_array( $statuses ) || count( $statuses ) > Anchors::get( 'MANAGED_STAGED_MAX_FILES' ) ) {
            return null;
        }
        $normalized_items = null;
        if ( $items !== null ) {
            $normalized_items = self::normalize_worker_gallery_items( $items );
            if ( $normalized_items === null || count( $normalized_items ) !== count( $statuses ) ) {
                return null;
            }
        }
        $normalized = array();
        $seen_uploads = array();
        foreach ( $statuses as $index => $status ) {
            if ( ! is_array( $status ) || ! isset( $status['status'] ) || ! is_string( $status['status'] ) ) {
                return null;
            }
            if ( $status['status'] === 'accepted' ) {
                $candidate = self::normalize_exact_json_object_fields( $status, self::WORKER_GALLERY_STATUS_ACCEPTED_FIELDS );
            } elseif ( $status['status'] === 'pending' || $status['status'] === 'unavailable' ) {
                $candidate = self::normalize_exact_json_object_fields( $status, self::WORKER_GALLERY_STATUS_PENDING_FIELDS );
            } else {
                return null;
            }
            if ( $candidate === null || isset( $seen_uploads[ $candidate['upload_id'] ] ) ) {
                return null;
            }
            if ( $normalized_items !== null && $candidate['upload_id'] !== $normalized_items[ $index ]['upload_id'] ) {
                return null;
            }
            $seen_uploads[ $candidate['upload_id'] ] = true;
            $normalized[] = $candidate;
        }
        return $normalized;
    }

    public static function worker_gallery_items_sha256( $items ) {
        $normalized = self::normalize_worker_gallery_items( $items );
        return $normalized === null ? '' : self::canonical_sha256( $normalized );
    }

    public static function worker_gallery_statuses_sha256( $statuses, $items = null ) {
        $normalized = self::normalize_worker_gallery_statuses( $statuses, $items );
        return $normalized === null ? '' : self::canonical_sha256( $normalized );
    }

    public static function worker_gallery_status_request_claims_match_items( $claims, $items ) {
        $normalized_claims = self::normalize_claims( $claims, self::SCHEMAS['worker_gallery_status_request']['fields'] );
        $normalized_items = self::normalize_worker_gallery_items( $items );
        return $normalized_claims !== null
            && $normalized_items !== null
            && (int) $normalized_claims['item_count'] === count( $normalized_items )
            && hash_equals( $normalized_claims['items_sha256'], self::canonical_sha256( $normalized_items ) );
    }

    public static function worker_gallery_status_result_claims_match_statuses( $claims, $statuses, $items ) {
        $normalized_claims = self::normalize_claims( $claims, self::SCHEMAS['worker_gallery_status_result']['fields'] );
        $normalized_statuses = self::normalize_worker_gallery_statuses( $statuses, $items );
        $normalized_items = self::normalize_worker_gallery_items( $items );
        if ( $normalized_claims === null || $normalized_statuses === null || $normalized_items === null ) {
            return false;
        }
        if ( ! hash_equals( $normalized_claims['items_sha256'], self::canonical_sha256( $normalized_items ) ) ) {
            return false;
        }
        return (int) $normalized_claims['item_count'] === count( $normalized_statuses )
            && hash_equals( $normalized_claims['statuses_sha256'], self::canonical_sha256( $normalized_statuses ) );
    }

    public static function worker_gallery_status_request_body_bytes( $token, $items ) {
        if ( ! is_string( $token ) || $token === '' || preg_match( '//u', $token ) !== 1 ) {
            return '';
        }
        $normalized = self::normalize_worker_gallery_items( $items );
        if ( $normalized === null ) {
            return '';
        }
        $bytes = self::canonical_json_bytes( array( 'request' => $token, 'items' => $normalized ) );
        return is_string( $bytes ) && strlen( $bytes ) <= Anchors::get( 'WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES' ) ? $bytes : '';
    }

    public static function worker_gallery_status_result_body_bytes( $token, $statuses, $items = null ) {
        if ( ! is_string( $token ) || $token === '' || preg_match( '//u', $token ) !== 1 ) {
            return '';
        }
        $normalized = self::normalize_worker_gallery_statuses( $statuses, $items );
        if ( $normalized === null ) {
            return '';
        }
        $bytes = self::canonical_json_bytes( array( 'result' => $token, 'statuses' => $normalized ) );
        return is_string( $bytes ) && strlen( $bytes ) <= Anchors::get( 'WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES' ) ? $bytes : '';
    }

    public static function sign_health_request( $claims, $key_id, $secret, $environment ) {
        return self::sign( 'health_request', $claims, $key_id, $secret, $environment );
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
        if ( ! self::schema_claims_allowed( $schema, $normalized ) ) {
            return '';
        }
        $parts = array( $schema['domain'], self::schema_version( $schema ), $key_id, $environment );
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
            || $parts[1] !== self::schema_version( $schema )
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
        if ( ! self::schema_claims_allowed( $schema, $claims ) ) {
            return $failure;
        }
        $expiry_field = isset( $schema['expiry'] ) ? $schema['expiry'] : 'expires_at';
        $clock = is_numeric( $now ) ? (int) $now : time();
        $clock_skew = is_int( $clock_skew ) && $clock_skew >= 0 ? $clock_skew : 0;
        if ( ! isset( $claims[ $expiry_field ] )
            || ( ! empty( $schema['closed_at_equality'] )
                ? $claims[ $expiry_field ] <= $clock
                : $claims[ $expiry_field ] < $clock - $clock_skew )
        ) {
            return array( 'ok' => false, 'reason' => 'expired_envelope' );
        }
        return array(
            'ok' => true,
            'key_id' => $parts[2],
            'claims' => $claims,
        );
    }

    private static function schema_version( $schema ) {
        return isset( $schema['version'] ) ? $schema['version'] : self::VERSION;
    }

    private static function schema_claims_allowed( $schema, $claims ) {
        if ( ! empty( $schema['worker_object_request'] ) && ! self::worker_object_request_claims_allowed( $claims ) ) {
            return false;
        }
        if ( empty( $schema['deadline_order'] ) ) {
            return true;
        }
        foreach ( array( 'upload_until', 'accept_until', 'validation_until', 'staged_delete_after', 'grant_expires_at' ) as $field ) {
            if ( ! isset( $claims[ $field ] ) || ! is_numeric( $claims[ $field ] ) ) {
                return false;
            }
        }
        return (int) $claims['upload_until'] < min( (int) $claims['accept_until'], (int) $claims['validation_until'] )
            && (int) $claims['validation_until'] < (int) $claims['staged_delete_after']
            && (int) $claims['grant_expires_at'] === (int) $claims['upload_until'];
    }

    private static function worker_object_request_claims_allowed( $claims ) {
        $parts = ManagedArtifactKey::parse( $claims['object_key'] );
        if ( $parts === null
            || ! hash_equals( $parts['namespace'], $claims['batch_id'] )
            || ! hash_equals( $parts['intent_id'], $claims['intent_id'] )
            || (int) $parts['ordinal'] !== (int) $claims['ordinal']
        ) {
            return false;
        }
        $unknown = $claims['object_version'] === '-' || $claims['etag'] === '-';
        if ( $unknown ) {
            return $claims['object_version'] === '-'
                && $claims['etag'] === '-'
                && $claims['action'] === 'delete';
        }
        return self::canonical_value( $claims['object_version'], 'opaque' ) !== null
            && self::canonical_value( $claims['etag'], 'opaque' ) !== null;
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
        if ( $type === 'worker_protocol_version' ) {
            return (string) $value === self::VERSION ? self::VERSION : null;
        }
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
        if ( $type === 'worker_object_version_or_unknown' || $type === 'worker_etag_or_unknown' ) {
            return $value === '-' || preg_match( '/^[A-Za-z0-9._:-]{1,' . Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) . '}$/D', $value ) === 1 ? $value : null;
        }
        if ( $type === 'known_opaque' ) {
            return $value !== '-' && preg_match( '/^[A-Za-z0-9._:-]{1,' . Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) . '}$/D', $value ) === 1 ? $value : null;
        }
        if ( $type === 'object_key' ) {
            return ManagedArtifactKey::valid( $value ) ? $value : null;
        }
        if ( $type === 'digest' ) {
            return ManagedArtifactKey::valid_digest( $value ) ? $value : null;
        }
        $patterns = array(
            'managed_id' => '/^[A-Za-z0-9_-]{1,' . Anchors::get( 'MANAGED_ID_MAX_CHARS' ) . '}$/D',
            'opaque' => '/^[A-Za-z0-9._:-]{1,' . Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) . '}$/D',
            'hex_digest' => '/^[0-9a-f]{64}$/D',
            'mime' => '#^image/(?:jpeg|png|webp|heic|heif)$#D',
            'review_action' => '/^(?:preview|download)$/D',
            'object_action' => '/^(?:delete|inspect)$/D',
            'object_status' => '/^(?:present|absent|version_mismatch)$/D',
            'binding' => '/^[A-Za-z0-9._-]{1,64}$/D',
            'gallery_status' => '/^(?:pending|unavailable|accepted)$/D',
        );
        return isset( $patterns[ $type ] ) && preg_match( $patterns[ $type ], $value ) === 1 ? $value : null;
    }

    private static function typed_value( $value, $type ) {
        if ( $type === 'uint' || $type === 'positive_int' || $type === 'worker_protocol_version' ) {
            return (int) $value;
        }
        if ( $type === 'boolean' ) {
            return $value === '1';
        }
        return $value;
    }

    private static function normalize_exact_json_object_fields( $value, $fields ) {
        if ( ! is_array( $value ) || self::is_list_array( $value ) ) {
            return null;
        }
        $actual = array_keys( $value );
        $expected = array_keys( $fields );
        sort( $actual, SORT_STRING );
        $sorted_expected = $expected;
        sort( $sorted_expected, SORT_STRING );
        if ( $actual !== $sorted_expected ) {
            return null;
        }
        $normalized = array();
        foreach ( $fields as $field => $type ) {
            $candidate = self::canonical_json_value( $value[ $field ], $type );
            if ( $candidate === null ) {
                return null;
            }
            $normalized[ $field ] = $candidate;
        }
        return $normalized;
    }

    private static function canonical_json_value( $value, $type ) {
        if ( $type === 'uint' || $type === 'positive_int' || $type === 'worker_protocol_version' ) {
            if ( ! is_int( $value ) ) {
                return null;
            }
        } elseif ( $type === 'boolean' ) {
            if ( ! is_bool( $value ) ) {
                return null;
            }
        } elseif ( ! is_string( $value ) ) {
            return null;
        }
        $canonical = self::canonical_value( $value, $type );
        return $canonical === null ? null : self::typed_value( $canonical, $type );
    }

    private static function valid_binding( $value ) {
        return is_string( $value ) && preg_match( '/^[A-Za-z0-9._-]{1,64}$/D', $value ) === 1;
    }

    private static function canonical_sha256( $value ) {
        $bytes = self::canonical_json_bytes( $value );
        return is_string( $bytes ) ? hash( 'sha256', $bytes ) : '';
    }

    private static function canonical_json_bytes( $value ) {
        $canonical = self::canonical_json_value_tree( $value );
        if ( $canonical === null ) {
            return null;
        }
        $encoded = json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $encoded ) ? $encoded : null;
    }

    private static function canonical_json_value_tree( $value ) {
        if ( is_array( $value ) ) {
            if ( self::is_list_array( $value ) ) {
                $result = array();
                foreach ( $value as $entry ) {
                    $candidate = self::canonical_json_value_tree( $entry );
                    if ( $candidate === null ) {
                        return null;
                    }
                    $result[] = $candidate;
                }
                return $result;
            }
            $keys = array_keys( $value );
            foreach ( $keys as $key ) {
                if ( ! is_string( $key ) ) {
                    return null;
                }
            }
            sort( $keys, SORT_STRING );
            $result = array();
            foreach ( $keys as $key ) {
                $candidate = self::canonical_json_value_tree( $value[ $key ] );
                if ( $candidate === null ) {
                    return null;
                }
                $result[ $key ] = $candidate;
            }
            return $result;
        }
        if ( is_string( $value ) ) {
            return preg_match( '//u', $value ) === 1 ? $value : null;
        }
        return is_int( $value ) || is_bool( $value ) ? $value : null;
    }

    private static function is_list_array( $value ) {
        return is_array( $value ) && ( $value === array() || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
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
