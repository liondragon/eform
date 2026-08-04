<?php
/**
 * Bounded WordPress-to-Worker operations for managed remote artifacts.
 *
 * This owner reads deployment constants, signs exact capabilities, and
 * verifies signed results. It never mutates manifests or capacity state.
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../Security/Entropy.php';
if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}
require_once __DIR__ . '/WorkerProtocol.php';

final class WorkerClient {
    const OBJECT_HEADER = 'X-EForms-Worker-Object';
    const HEALTH_HEADER = 'X-EForms-Worker-Health';
    const REVIEW_QUERY = 'grant';

    const COMPOSITION_LOCAL_NONE = 'local_no_processing';
    const COMPOSITION_LOCAL_PREVIEW = 'local_imagick_preview';
    const COMPOSITION_WORKER = 'worker_r2_cloudflare';

    public static function composition() {
        if ( defined( 'EFORMS_UPLOAD_COMPOSITION' ) && ! is_string( constant( 'EFORMS_UPLOAD_COMPOSITION' ) ) ) {
            return null;
        }
        $value = self::composition_name();
        if ( in_array( $value, array( self::COMPOSITION_LOCAL_NONE, self::COMPOSITION_LOCAL_PREVIEW ), true ) ) {
            return FormProtocol::UPLOAD_TRANSPORT_LOCAL;
        }
        return $value === self::COMPOSITION_WORKER && self::configuration() !== null
            ? FormProtocol::UPLOAD_TRANSPORT_WORKER
            : null;
    }

    public static function composition_name() {
        if ( defined( 'EFORMS_UPLOAD_COMPOSITION' ) && ! is_string( constant( 'EFORMS_UPLOAD_COMPOSITION' ) ) ) {
            return '';
        }
        return defined( 'EFORMS_UPLOAD_COMPOSITION' )
            ? constant( 'EFORMS_UPLOAD_COMPOSITION' )
            : self::COMPOSITION_LOCAL_NONE;
    }

    public static function review_provider() {
        $composition = self::composition_name();
        if ( $composition === self::COMPOSITION_LOCAL_NONE ) {
            return 'none';
        }
        if ( $composition === self::COMPOSITION_LOCAL_PREVIEW ) {
            return self::local_preview_concurrency() === null ? 'unavailable' : 'local';
        }
        return $composition === self::COMPOSITION_WORKER && self::configuration() !== null ? 'worker' : 'unavailable';
    }

    public static function local_preview_concurrency() {
        if ( ! defined( 'EFORMS_LOCAL_PREVIEW_CONCURRENCY' ) ) {
            return 1;
        }
        $value = constant( 'EFORMS_LOCAL_PREVIEW_CONCURRENCY' );
        return is_int( $value ) && $value >= 1 && $value <= Anchors::get( 'LOCAL_PREVIEW_CONCURRENCY_MAX' )
            ? $value
            : null;
    }

    public static function configuration() {
        $origin = defined( 'EFORMS_WORKER_URL' ) && is_string( constant( 'EFORMS_WORKER_URL' ) ) ? constant( 'EFORMS_WORKER_URL' ) : '';
        if ( ! self::valid_origin( $origin ) || self::is_wordpress_origin( $origin ) ) {
            return null;
        }
        $origin = self::canonical_origin( $origin );
        if ( $origin === '' ) {
            return null;
        }
        $required = array( 'EFORMS_WORKER_ENVIRONMENT_ID', 'EFORMS_WORKER_ACTIVE_KEY_ID', 'EFORMS_WORKER_ACTIVE_KEY_B64' );
        foreach ( $required as $name ) {
            if ( ! defined( $name ) || ! is_string( constant( $name ) ) ) {
                return null;
            }
        }
        foreach ( array( 'EFORMS_WORKER_SECONDARY_KEY_ID', 'EFORMS_WORKER_SECONDARY_KEY_B64' ) as $name ) {
            if ( defined( $name ) && ! is_string( constant( $name ) ) ) {
                return null;
            }
        }
        $keys = WorkerProtocol::key_configuration(
            constant( 'EFORMS_WORKER_ENVIRONMENT_ID' ),
            constant( 'EFORMS_WORKER_ACTIVE_KEY_ID' ),
            constant( 'EFORMS_WORKER_ACTIVE_KEY_B64' ),
            defined( 'EFORMS_WORKER_SECONDARY_KEY_ID' ) ? constant( 'EFORMS_WORKER_SECONDARY_KEY_ID' ) : '',
            defined( 'EFORMS_WORKER_SECONDARY_KEY_B64' ) ? constant( 'EFORMS_WORKER_SECONDARY_KEY_B64' ) : ''
        );
        if ( $keys === null ) {
            return null;
        }
        $keys['origin'] = $origin;
        return $keys;
    }

    public static function composition_fingerprint() {
        $configuration = self::configuration();
        if ( $configuration === null ) {
            return '';
        }
        $parts = array(
            'worker_r2_cloudflare',
            $configuration['origin'],
            $configuration['environment'],
        );
        $encoded = json_encode( $parts, JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
    }

    public static function origin() {
        $configuration = self::configuration();
        return $configuration === null ? '' : $configuration['origin'];
    }

    public static function worker_review_url( $claims, $expected_composition_fingerprint, $now = null ) {
        $configuration = self::configuration();
        $now = is_numeric( $now ) ? (int) $now : time();
        $expires_at = is_array( $claims ) && isset( $claims['expires_at'] ) && is_int( $claims['expires_at'] )
            ? $claims['expires_at']
            : 0;
        if ( $configuration === null
            || ! is_array( $claims )
            || ! isset( $claims['storage_identity'] )
            || ! self::worker_identity_matches( $claims['storage_identity'], $expected_composition_fingerprint )
            || $expires_at <= $now
            || $expires_at > $now + Anchors::get( 'WORKER_REVIEW_GRANT_TTL_SECONDS' )
        ) {
            return '';
        }
        $token = WorkerProtocol::sign_worker_review_grant(
            $claims,
            $configuration['active_id'],
            $configuration['active'],
            $configuration['environment']
        );
        return $token === ''
            ? ''
            : $configuration['origin'] . '/v1/review?' . http_build_query( array( self::REVIEW_QUERY => $token ), '', '&', PHP_QUERY_RFC3986 );
    }

    public static function worker_gallery_status( $submission_id, $storage_identity, $items, $expected_composition_fingerprint, $now = null, $requester = null ) {
        $started_at = microtime( true );
        $result = self::worker_gallery_status_operation( $submission_id, $storage_identity, $items, $expected_composition_fingerprint, $now, $requester );
        self::emit_operation_event( 'gallery_status', 'review_status', $result, $started_at );
        return $result;
    }

    private static function worker_gallery_status_operation( $submission_id, $storage_identity, $items, $expected_composition_fingerprint, $now, $requester ) {
        $configuration = self::configuration();
        $request_now = self::operation_now( $now );
        $normalized_items = WorkerProtocol::normalize_worker_gallery_items( $items );
        if ( $configuration === null
            || $request_now === null
            || ! is_string( $submission_id )
            || ! is_string( $storage_identity )
            || ! self::worker_identity_matches( $storage_identity, $expected_composition_fingerprint )
            || $normalized_items === null
        ) {
            return self::failure( 'configuration_unavailable' );
        }

        $expires_at = $request_now + Anchors::get( 'WORKER_OPERATION_GRANT_TTL_SECONDS' );
        $items_sha256 = WorkerProtocol::worker_gallery_items_sha256( $normalized_items );
        $request_identity = hash( 'sha256', $storage_identity . "\0" . $items_sha256 );
        $claims = array(
            'request_id' => self::request_id(
                'worker_gallery_status',
                $submission_id,
                $request_identity,
                $expires_at,
                $configuration['environment']
            ),
            'submission_id' => $submission_id,
            'storage_identity' => $storage_identity,
            'items_sha256' => $items_sha256,
            'item_count' => count( $normalized_items ),
            'expires_at' => $expires_at,
        );
        $token = WorkerProtocol::sign_worker_gallery_status_request(
            $claims,
            $configuration['active_id'],
            $configuration['active'],
            $configuration['environment']
        );
        $body = WorkerProtocol::worker_gallery_status_request_body_bytes( $token, $normalized_items );
        if ( $token === '' || $body === '' ) {
            return self::failure( 'request_invalid' );
        }

        $response = self::gallery_status_request(
            $configuration['origin'] . '/v1/gallery-status',
            $body,
            $requester
        );
        if ( empty( $response['ok'] ) ) {
            return $response;
        }

        $decoded = json_decode( $response['body'], true );
        if ( ! is_array( $decoded )
            || array_keys( $decoded ) !== array( 'result', 'statuses' )
            || ! isset( $decoded['result'], $decoded['statuses'] )
            || ! is_string( $decoded['result'] )
            || strlen( $decoded['result'] ) > Anchors::get( 'WORKER_ENVELOPE_MAX_CHARS' )
        ) {
            return self::failure( 'result_invalid' );
        }
        $statuses = WorkerProtocol::normalize_worker_gallery_statuses( $decoded['statuses'], $normalized_items );
        $canonical = $statuses === null
            ? ''
            : WorkerProtocol::worker_gallery_status_result_body_bytes( $decoded['result'], $statuses, $normalized_items );
        if ( $canonical === '' || ! hash_equals( $canonical, $response['body'] ) ) {
            return self::failure( 'result_invalid' );
        }

        $verification_now = self::operation_now( $now );
        if ( $verification_now === null ) {
            return self::failure( 'result_invalid' );
        }
        $verified = WorkerProtocol::verify_worker_gallery_status_result(
            $decoded['result'],
            $configuration['keys'],
            $configuration['environment'],
            $verification_now
        );
        $result = ! empty( $verified['ok'] ) && isset( $verified['claims'] ) ? $verified['claims'] : null;
        if ( ! is_array( $result )
            || $result['request_id'] !== $claims['request_id']
            || $result['submission_id'] !== $submission_id
            || $result['items_sha256'] !== $claims['items_sha256']
            || $result['item_count'] !== $claims['item_count']
            || $result['expires_at'] !== $claims['expires_at']
            || ! WorkerProtocol::worker_gallery_status_result_claims_match_statuses( $result, $statuses, $normalized_items )
        ) {
            return self::failure( 'result_invalid' );
        }
        return array(
            'ok' => true,
            'statuses' => $statuses,
            'checked_at' => $result['checked_at'],
        );
    }

    public static function worker_delete_object( $authority, $now = null, $requester = null, $phase = 'direct_cleanup' ) {
        $authority = is_array( $authority ) ? $authority : array();
        $started_at = microtime( true );
        $result = self::worker_object_operation(
            'delete',
            isset( $authority['upload_id'] ) ? $authority['upload_id'] : '',
            isset( $authority['storage_identity'] ) ? $authority['storage_identity'] : '',
            isset( $authority['validation_contract_version'] ) ? $authority['validation_contract_version'] : '',
            isset( $authority['object_key'] ) ? $authority['object_key'] : '',
            isset( $authority['object_version'] ) ? $authority['object_version'] : '',
            isset( $authority['etag'] ) ? $authority['etag'] : '',
            isset( $authority['bytes'] ) ? (int) $authority['bytes'] : 0,
            isset( $authority['policy_fingerprint'] ) ? $authority['policy_fingerprint'] : '',
            isset( $authority['expected_composition_fingerprint'] ) ? $authority['expected_composition_fingerprint'] : '',
            $now,
            $requester
        );
        self::emit_operation_event( 'delete', $phase, $result, $started_at );
        return $result;
    }

    public static function worker_inspect_object( $upload_id, $storage_identity, $validation_contract_version, $object_key, $object_version, $etag, $bytes, $policy_fingerprint, $expected_composition_fingerprint, $now = null, $requester = null, $phase = 'restore_signoff' ) {
        $started_at = microtime( true );
        $result = self::worker_object_operation(
            'inspect',
            $upload_id,
            $storage_identity,
            $validation_contract_version,
            $object_key,
            $object_version,
            $etag,
            $bytes,
            $policy_fingerprint,
            $expected_composition_fingerprint,
            $now,
            $requester
        );
        self::emit_operation_event( 'inspect', $phase, $result, $started_at );
        return $result;
    }

    private static function worker_object_operation( $action, $upload_id, $storage_identity, $validation_contract_version, $object_key, $object_version, $etag, $bytes, $policy_fingerprint, $expected_composition_fingerprint, $now, $requester ) {
        $configuration = self::configuration();
        $request_now = self::operation_now( $now );
        $parts = ManagedArtifactKey::parse( $object_key );
        if ( $configuration === null
            || $request_now === null
            || ! self::worker_identity_matches( $storage_identity, $expected_composition_fingerprint )
        ) {
            return self::failure( 'configuration_unavailable' );
        }
        if ( ! in_array( $action, array( 'delete', 'inspect' ), true ) || $parts === null ) {
            return self::failure( 'request_invalid' );
        }
        if ( ! is_string( $object_version ) || ! is_string( $etag ) || ( $object_version === '-' ) !== ( $etag === '-' ) ) {
            return self::failure( 'request_invalid' );
        }
        if ( $action === 'inspect' && ( $object_version === '-' || $etag === '-' ) ) {
            return self::failure( 'object_version_required' );
        }

        $expires_at = $request_now + Anchors::get( 'WORKER_OPERATION_GRANT_TTL_SECONDS' );
        $claims = array(
            'request_id' => '',
            'batch_id' => $parts['namespace'],
            'intent_id' => $parts['intent_id'],
            'upload_id' => $upload_id,
            'ordinal' => $parts['ordinal'],
            'storage_identity' => $storage_identity,
            'validation_contract_version' => $validation_contract_version,
            'object_key' => $object_key,
            'object_version' => $object_version,
            'etag' => $etag,
            'bytes' => $bytes,
            'policy_fingerprint' => $policy_fingerprint,
            'action' => $action,
            'expires_at' => $expires_at,
        );
        $claims['request_id'] = self::worker_object_request_id( $claims, $configuration['environment'] );
        $token = WorkerProtocol::sign_worker_object_request(
            $claims,
            $configuration['active_id'],
            $configuration['active'],
            $configuration['environment']
        );
        if ( $token === '' ) {
            return self::failure( 'request_invalid' );
        }

        $response = self::request(
            $configuration['origin'] . '/v1/object',
            self::OBJECT_HEADER,
            $token,
            $requester
        );
        if ( empty( $response['ok'] ) ) {
            return $response;
        }
        $envelope = isset( $response['body']['result'] ) && is_string( $response['body']['result'] )
            ? $response['body']['result']
            : '';
        $verification_now = self::operation_now( $now );
        $verified = $verification_now !== null && strlen( $envelope ) <= Anchors::get( 'WORKER_ENVELOPE_MAX_CHARS' )
            ? WorkerProtocol::verify_worker_object_result( $envelope, $configuration['keys'], $configuration['environment'], $verification_now )
            : array( 'ok' => false );
        $result = ! empty( $verified['ok'] ) && isset( $verified['claims'] ) ? $verified['claims'] : null;
        if ( ! is_array( $result )
            || $result['request_id'] !== $claims['request_id']
            || $result['object_key'] !== $object_key
            || $result['object_version'] !== $object_version
            || $result['expires_at'] !== $expires_at
        ) {
            return self::failure( 'result_invalid' );
        }
        if ( $action === 'delete' ) {
            return $result['status'] === 'absent'
                ? array( 'ok' => true, 'absent' => true, 'outcome' => 'confirmed_absent' )
                : self::failure( 'version_mismatch' );
        }
        if ( $result['status'] === 'present' ) {
            return array( 'ok' => true, 'present' => true, 'outcome' => 'confirmed_present' );
        }
        return self::failure( $result['status'] === 'absent' ? 'object_absent' : 'version_mismatch' );
    }

    public static function health( $now = null, $requester = null, $phase = 'runtime_readiness' ) {
        $started_at = microtime( true );
        $result = self::health_operation( $now, $requester );
        self::emit_operation_event( 'health', $phase, $result, $started_at );
        return $result;
    }

    private static function health_operation( $now, $requester ) {
        $configuration = self::configuration();
        $request_now = self::operation_now( $now );
        if ( $configuration === null || $request_now === null ) {
            return self::failure( 'configuration_unavailable' );
        }
        $expires_at = $request_now + Anchors::get( 'WORKER_OPERATION_GRANT_TTL_SECONDS' );
        $request_id = Entropy::base64url_id( Anchors::get( 'RUNTIME_HEALTH_PROBE_ENTROPY_BYTES' ) );
        if ( $request_id === '' ) {
            return self::failure( 'request_invalid' );
        }
        $claims = array(
            'request_id' => $request_id,
            'storage_identity' => self::composition_fingerprint(),
            'validation_contract_version' => WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION,
            'expires_at' => $expires_at,
        );
        $token = WorkerProtocol::sign_health_request(
            $claims,
            $configuration['active_id'],
            $configuration['active'],
            $configuration['environment']
        );
        if ( $token === '' ) {
            return self::failure( 'request_invalid' );
        }
        $response = self::request(
            $configuration['origin'] . '/v1/health',
            self::HEALTH_HEADER,
            $token,
            $requester
        );
        if ( empty( $response['ok'] ) ) {
            return $response;
        }
        $envelope = isset( $response['body']['result'] ) && is_string( $response['body']['result'] )
            ? $response['body']['result']
            : '';
        $verification_now = self::operation_now( $now );
        $verified = $verification_now !== null && strlen( $envelope ) <= Anchors::get( 'WORKER_ENVELOPE_MAX_CHARS' )
            ? WorkerProtocol::verify_health_result( $envelope, $configuration['keys'], $configuration['environment'], $verification_now )
            : array( 'ok' => false );
        $result = ! empty( $verified['ok'] ) && isset( $verified['claims'] ) ? $verified['claims'] : null;
        if ( ! is_array( $result )
            || $result['request_id'] !== $claims['request_id']
            || $result['storage_identity'] !== $claims['storage_identity']
            || $result['validation_contract_version'] !== $claims['validation_contract_version']
            || $result['expires_at'] !== $claims['expires_at']
        ) {
            return self::failure( 'result_invalid' );
        }
        $worker_ready = ! empty( $result['storage_ready'] )
            && ! empty( $result['inspection_ready'] )
            && ! empty( $result['queue_producer_ready'] )
            && ! empty( $result['limiter_ready'] )
            && ! empty( $result['keys_ready'] )
            && ! empty( $result['storage_identity_ready'] )
            && ! empty( $result['validation_contract_ready'] );
        return array(
            'ok' => $worker_ready,
            'storage_ready' => ! empty( $result['storage_ready'] ),
            'inspection_ready' => ! empty( $result['inspection_ready'] ),
            'worker_ready' => $worker_ready,
            'queue_producer_ready' => ! empty( $result['queue_producer_ready'] ),
            'limiter_ready' => ! empty( $result['limiter_ready'] ),
            'keys_ready' => ! empty( $result['keys_ready'] ),
            'storage_identity_ready' => ! empty( $result['storage_identity_ready'] ),
            'validation_contract_ready' => ! empty( $result['validation_contract_ready'] ),
            'storage_identity' => isset( $result['storage_identity'] ) ? (string) $result['storage_identity'] : '',
            'validation_contract_version' => isset( $result['validation_contract_version'] ) ? (string) $result['validation_contract_version'] : '',
            'outcome' => $worker_ready ? 'ready' : 'dependency_unavailable',
        );
    }

    private static function emit_operation_event( $operation, $phase, $result, $started_at ) {
        if ( ! class_exists( 'Logging' ) || ! method_exists( 'Logging', 'event' ) ) {
            return;
        }
        $result = is_array( $result ) ? $result : self::failure( 'result_invalid' );
        $ok = ! empty( $result['ok'] );
        $outcome = isset( $result['outcome'] ) && is_string( $result['outcome'] ) ? $result['outcome'] : '';
        Logging::event(
            $ok ? 'info' : 'warning',
            'EFORMS_WORKER_OPERATION',
            array(
                'operation' => in_array( $operation, array( 'gallery_status', 'delete', 'inspect', 'health' ), true ) ? $operation : 'unknown',
                'operation_category' => self::operation_category( $phase ),
                'outcome_class' => self::outcome_class( $ok, $outcome ),
                'latency_bucket' => self::latency_bucket( $started_at ),
                'retry' => $ok ? 'not_needed' : 'required',
                'cleanup_phase' => self::operation_phase( $phase ),
            )
        );
    }

    private static function outcome_class( $ok, $outcome ) {
        if ( $ok ) {
            return 'success';
        }
        if ( in_array( $outcome, array( 'transport_failed', 'http_unavailable', 'dependency_unavailable' ), true ) ) {
            return 'dependency_unavailable';
        }
        if ( in_array( $outcome, array( 'configuration_unavailable', 'request_invalid', 'object_version_required' ), true ) ) {
            return 'configuration_invalid';
        }
        if ( in_array( $outcome, array( 'request_rejected', 'version_mismatch', 'object_absent' ), true ) ) {
            return 'authoritative_rejection';
        }
        return $outcome === 'result_invalid' ? 'invalid_result' : 'operation_failed';
    }

    private static function latency_bucket( $started_at ) {
        $elapsed_ms = is_float( $started_at ) || is_int( $started_at )
            ? max( 0, (int) floor( ( microtime( true ) - $started_at ) * 1000 ) )
            : Anchors::get( 'WORKER_LATENCY_SLOW_MAX_MS' ) + 1;
        if ( $elapsed_ms <= Anchors::get( 'WORKER_LATENCY_FAST_MAX_MS' ) ) {
            return 'fast';
        }
        if ( $elapsed_ms <= Anchors::get( 'WORKER_LATENCY_NORMAL_MAX_MS' ) ) {
            return 'normal';
        }
        return $elapsed_ms <= Anchors::get( 'WORKER_LATENCY_SLOW_MAX_MS' ) ? 'slow' : 'very_slow';
    }

    private static function operation_phase( $phase ) {
        $allowed = array(
            'direct_cleanup',
            'capacity_reconciliation',
            'aggregate_gc',
            'operator_delete',
            'operator_review_delete',
            'uninstall_drain',
            'restore_signoff',
            'runtime_readiness',
            'upload_grant_readiness',
            'review_status',
            'validation_retirement_complete',
        );
        return is_string( $phase ) && in_array( $phase, $allowed, true ) ? $phase : 'unspecified';
    }

    private static function operation_category( $phase ) {
        $phase = self::operation_phase( $phase );
        if ( in_array( $phase, array( 'direct_cleanup', 'capacity_reconciliation', 'aggregate_gc', 'uninstall_drain' ), true ) ) {
            return 'cleanup';
        }
        if ( $phase === 'operator_delete' || $phase === 'operator_review_delete' || $phase === 'review_status' ) {
            return 'review_readiness';
        }
        if ( $phase === 'restore_signoff' ) {
            return 'residue';
        }
        if ( $phase === 'runtime_readiness' || $phase === 'validation_retirement_complete' ) {
            return 'validation';
        }
        if ( $phase === 'upload_grant_readiness' ) {
            return 'transfer';
        }
        return 'unspecified';
    }

    public static function composition_matches( $expected ) {
        $current = self::composition_fingerprint();
        return is_string( $expected )
            && preg_match( '/^[0-9a-f]{64}$/', $expected ) === 1
            && $current !== ''
            && hash_equals( $expected, $current );
    }

    private static function worker_identity_matches( $storage_identity, $expected ) {
        return is_string( $storage_identity )
            && is_string( $expected )
            && preg_match( '/^[0-9a-f]{64}$/', $storage_identity ) === 1
            && preg_match( '/^[0-9a-f]{64}$/', $expected ) === 1
            && hash_equals( $expected, $storage_identity )
            && self::composition_matches( $expected );
    }

    private static function request( $url, $header, $token, $requester ) {
        $response = self::post_request(
            $url,
            array( $header => $token ),
            '',
            Anchors::get( 'WORKER_RESPONSE_MAX_BYTES' ),
            $requester
        );
        if ( empty( $response['ok'] ) ) {
            return $response;
        }
        $decoded = json_decode( $response['body'], true );
        return is_array( $decoded ) && self::exact_keys( $decoded, array( 'result' ) ) && is_string( $decoded['result'] )
            ? array( 'ok' => true, 'body' => $decoded )
            : self::failure( 'result_invalid' );
    }

    private static function gallery_status_request( $url, $body, $requester ) {
        if ( ! is_string( $body ) || $body === '' || strlen( $body ) > Anchors::get( 'WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES' ) ) {
            return self::failure( 'request_invalid' );
        }
        return self::post_request(
            $url,
            array( 'Content-Type' => 'application/json' ),
            $body,
            Anchors::get( 'WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES' ),
            $requester
        );
    }

    private static function post_request( $url, $headers, $body, $response_max_bytes, $requester ) {
        $arguments = array(
            'headers' => $headers,
            'body' => $body,
            'timeout' => Anchors::get( 'WORKER_SERVER_REQUEST_TIMEOUT_SECONDS' ),
            'limit_response_size' => $response_max_bytes,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'sslverify' => true,
        );
        $injected = is_callable( $requester );
        if ( $injected ) {
            $response = call_user_func( $requester, $url, $arguments );
        } elseif ( function_exists( 'wp_remote_post' ) ) {
            $response = wp_remote_post( $url, $arguments );
        } else {
            return self::failure( 'http_unavailable' );
        }
        if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
            return self::failure( 'transport_failed' );
        }
        $status = ! $injected && function_exists( 'wp_remote_retrieve_response_code' )
            ? wp_remote_retrieve_response_code( $response )
            : ( is_array( $response ) && isset( $response['status'] ) ? $response['status'] : 0 );
        $body = ! $injected && function_exists( 'wp_remote_retrieve_body' )
            ? wp_remote_retrieve_body( $response )
            : ( is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '' );
        if ( (int) $status !== 200 || ! is_string( $body ) || strlen( $body ) > $response_max_bytes ) {
            return self::failure( (int) $status >= 500 || (int) $status === 0 ? 'transport_failed' : 'request_rejected' );
        }
        return array( 'ok' => true, 'body' => $body );
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

    private static function operation_now( $clock ) {
        $sample = is_callable( $clock ) ? call_user_func( $clock ) : $clock;
        if ( $sample === null ) {
            return time();
        }
        return is_numeric( $sample ) ? (int) $sample : null;
    }

    private static function request_id( $operation, $object_key, $object_version, $expires_at, $environment ) {
        $bytes = hash( 'sha256', implode( "\0", array( $operation, $object_key, $object_version, (string) $expires_at, $environment ) ), true );
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    private static function worker_object_request_id( $claims, $environment ) {
        $parts = array(
            $claims['action'],
            $claims['batch_id'],
            $claims['intent_id'],
            $claims['upload_id'],
            (string) $claims['ordinal'],
            $claims['storage_identity'],
            $claims['validation_contract_version'],
            $claims['object_key'],
            $claims['object_version'],
            $claims['etag'],
            (string) $claims['bytes'],
            $claims['policy_fingerprint'],
            (string) $claims['expires_at'],
            $environment,
        );
        $bytes = hash( 'sha256', implode( "\0", $parts ), true );
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    private static function valid_origin( $origin ) {
        return self::canonical_worker_origin( $origin ) !== '';
    }

    private static function is_wordpress_origin( $origin ) {
        if ( ! function_exists( 'home_url' ) ) {
            return false;
        }
        $home = home_url( '/' );
        $home_origin = self::canonical_origin( $home );
        $worker_origin = self::canonical_origin( $origin );
        if ( $home_origin === '' || $worker_origin === '' ) {
            return true;
        }
        return hash_equals( $home_origin, $worker_origin );
    }

    private static function canonical_origin( $url ) {
        $parts = is_string( $url ) ? parse_url( $url ) : false;
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
            return '';
        }
        $scheme = strtolower( $parts['scheme'] );
        $host = self::canonical_origin_host( (string) $parts['host'] );
        if ( $host === '' ) {
            return '';
        }
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : ( $scheme === 'https' ? 443 : ( $scheme === 'http' ? 80 : 0 ) );
        if ( $port < 1 || $port > 65535 ) {
            return '';
        }
        $default_port = ( $scheme === 'https' && $port === 443 ) || ( $scheme === 'http' && $port === 80 );
        return $scheme . '://' . $host . ( $default_port ? '' : ':' . $port );
    }

    private static function canonical_worker_origin( $origin ) {
        if ( ! is_string( $origin ) || $origin === '' ) {
            return '';
        }
        if ( preg_match( '/^https:\/\/([a-z0-9.-]+)(?::([1-9][0-9]{0,4}))?\z/', $origin, $matches ) !== 1 ) {
            return '';
        }
        $host = self::canonical_origin_host( $matches[1] );
        if ( $host === '' || $host !== $matches[1] ) {
            return '';
        }
        $port = isset( $matches[2] ) ? (int) $matches[2] : 443;
        if ( $port < 1 || $port > 65535 ) {
            return '';
        }
        return 'https://' . $host . ( $port === 443 ? '' : ':' . $port );
    }

    private static function canonical_origin_host( $host ) {
        if ( ! is_string( $host ) || $host === '' || strtolower( $host ) !== $host ) {
            return '';
        }
        if ( strlen( $host ) > 253 ) {
            return '';
        }
        if ( preg_match( '/^\d+(?:\.\d+){3}$/', $host ) === 1 ) {
            $octets = explode( '.', $host );
            foreach ( $octets as $octet ) {
                if ( ( strlen( $octet ) > 1 && $octet[0] === '0' ) || (int) $octet > 255 ) {
                    return '';
                }
            }
            return $host;
        }
        if ( preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host ) !== 1 ) {
            return '';
        }
        return $host;
    }

    private static function failure( $reason ) {
        return array( 'ok' => false, 'reason' => $reason, 'outcome' => $reason );
    }
}
