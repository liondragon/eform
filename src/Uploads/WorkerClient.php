<?php
/**
 * Bounded WordPress-to-Worker operations for managed remote artifacts.
 *
 * This owner reads deployment constants, signs exact capabilities, and
 * verifies signed results. It never mutates manifests or capacity state.
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../FormProtocol.php';
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

    public static function review_url( $claims, $expected_composition_fingerprint, $now = null ) {
        $configuration = self::configuration();
        $now = is_numeric( $now ) ? (int) $now : time();
        $expires_at = is_array( $claims ) && isset( $claims['expires_at'] ) && is_int( $claims['expires_at'] )
            ? $claims['expires_at']
            : 0;
        if ( $configuration === null
            || ! self::composition_matches( $expected_composition_fingerprint )
            || ! is_array( $claims )
            || $expires_at <= $now
            || $expires_at > $now + Anchors::get( 'WORKER_REVIEW_GRANT_TTL_SECONDS' )
        ) {
            return '';
        }
        $token = WorkerProtocol::sign_review_grant(
            $claims,
            $configuration['active_id'],
            $configuration['active'],
            $configuration['environment']
        );
        return $token === ''
            ? ''
            : $configuration['origin'] . '/v1/review?' . http_build_query( array( self::REVIEW_QUERY => $token ), '', '&', PHP_QUERY_RFC3986 );
    }

    public static function delete_object( $object_key, $object_version, $expected_composition_fingerprint, $now = null, $requester = null, $phase = 'direct_cleanup' ) {
        $started_at = microtime( true );
        $result = self::object_operation( 'delete', $object_key, $object_version, $expected_composition_fingerprint, $now, $requester );
        self::emit_operation_event( 'delete', $phase, $result, $started_at );
        return $result;
    }

    public static function inspect_object( $object_key, $object_version, $expected_composition_fingerprint, $now = null, $requester = null, $phase = 'restore_signoff' ) {
        $started_at = microtime( true );
        if ( ! is_string( $object_version ) || $object_version === '' ) {
            $result = self::failure( 'object_version_required' );
        } else {
            $result = self::object_operation( 'inspect', $object_key, $object_version, $expected_composition_fingerprint, $now, $requester );
        }
        self::emit_operation_event( 'inspect', $phase, $result, $started_at );
        return $result;
    }

    private static function object_operation( $action, $object_key, $object_version, $expected_composition_fingerprint, $now, $requester ) {
        $configuration = self::configuration();
        $now = is_numeric( $now ) ? (int) $now : time();
        $version = is_string( $object_version ) && $object_version !== '' ? $object_version : '-';
        if ( $configuration === null || ! self::composition_matches( $expected_composition_fingerprint ) || ! is_string( $object_key ) ) {
            return self::failure( 'configuration_unavailable' );
        }
        $expires_at = $now + Anchors::get( 'WORKER_OPERATION_GRANT_TTL_SECONDS' );
        $claims = array(
            'request_id' => self::request_id( $action, $object_key, $version, $expires_at, $configuration['environment'] ),
            'object_key' => $object_key,
            'object_version' => $version,
            'action' => $action,
            'expires_at' => $expires_at,
        );
        $token = WorkerProtocol::sign_object_request(
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
        $verified = strlen( $envelope ) <= Anchors::get( 'WORKER_ENVELOPE_MAX_CHARS' )
            ? WorkerProtocol::verify_object_result( $envelope, $configuration['keys'], $configuration['environment'], $now )
            : array( 'ok' => false );
        $result = ! empty( $verified['ok'] ) && isset( $verified['claims'] ) ? $verified['claims'] : null;
        if ( ! is_array( $result )
            || $result['request_id'] !== $claims['request_id']
            || $result['object_key'] !== $object_key
            || $result['object_version'] !== $version
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
        $now = is_numeric( $now ) ? (int) $now : time();
        if ( $configuration === null ) {
            return self::failure( 'configuration_unavailable' );
        }
        $expires_at = $now + Anchors::get( 'WORKER_OPERATION_GRANT_TTL_SECONDS' );
        $claims = array(
            'request_id' => self::request_id( 'health', '', '', $expires_at, $configuration['environment'] ),
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
        $verified = strlen( $envelope ) <= Anchors::get( 'WORKER_ENVELOPE_MAX_CHARS' )
            ? WorkerProtocol::verify_health_result( $envelope, $configuration['keys'], $configuration['environment'], $now )
            : array( 'ok' => false );
        $result = ! empty( $verified['ok'] ) && isset( $verified['claims'] ) ? $verified['claims'] : null;
        if ( ! is_array( $result ) || $result['request_id'] !== $claims['request_id'] ) {
            return self::failure( 'result_invalid' );
        }
        return array(
            'ok' => ! empty( $result['storage_ready'] ) && ! empty( $result['inspection_ready'] ),
            'storage_ready' => ! empty( $result['storage_ready'] ),
            'inspection_ready' => ! empty( $result['inspection_ready'] ),
            'outcome' => ! empty( $result['storage_ready'] ) && ! empty( $result['inspection_ready'] ) ? 'ready' : 'dependency_unavailable',
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
                'operation' => in_array( $operation, array( 'delete', 'inspect', 'health' ), true ) ? $operation : 'unknown',
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
            'operator_review_delete',
            'uninstall_drain',
            'restore_signoff',
            'runtime_readiness',
        );
        return is_string( $phase ) && in_array( $phase, $allowed, true ) ? $phase : 'unspecified';
    }

    public static function composition_matches( $expected ) {
        $current = self::composition_fingerprint();
        return is_string( $expected )
            && preg_match( '/^[0-9a-f]{64}$/', $expected ) === 1
            && $current !== ''
            && hash_equals( $expected, $current );
    }

    private static function request( $url, $header, $token, $requester ) {
        $arguments = array(
            'headers' => array( $header => $token ),
            'body' => '',
            'timeout' => Anchors::get( 'WORKER_SERVER_REQUEST_TIMEOUT_SECONDS' ),
            'limit_response_size' => Anchors::get( 'WORKER_RESPONSE_MAX_BYTES' ),
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
        if ( (int) $status !== 200 || ! is_string( $body ) || strlen( $body ) > Anchors::get( 'WORKER_RESPONSE_MAX_BYTES' ) ) {
            return self::failure( (int) $status >= 500 || (int) $status === 0 ? 'transport_failed' : 'request_rejected' );
        }
        $decoded = json_decode( $body, true );
        return is_array( $decoded )
            ? array( 'ok' => true, 'body' => $decoded )
            : self::failure( 'result_invalid' );
    }

    private static function request_id( $operation, $object_key, $object_version, $expires_at, $environment ) {
        $bytes = hash( 'sha256', implode( "\0", array( $operation, $object_key, $object_version, (string) $expires_at, $environment ) ), true );
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    private static function valid_origin( $origin ) {
        if ( ! is_string( $origin ) || $origin === '' ) {
            return false;
        }
        $parts = parse_url( $origin );
        return is_array( $parts )
            && isset( $parts['scheme'], $parts['host'] )
            && strtolower( $parts['scheme'] ) === 'https'
            && ! isset( $parts['user'], $parts['pass'], $parts['path'], $parts['query'], $parts['fragment'] )
            && $origin === 'https://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
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
        $host = strtolower( $parts['host'] );
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : ( $scheme === 'https' ? 443 : ( $scheme === 'http' ? 80 : 0 ) );
        if ( $port < 1 || $port > 65535 ) {
            return '';
        }
        $default_port = ( $scheme === 'https' && $port === 443 ) || ( $scheme === 'http' && $port === 80 );
        return $scheme . '://' . $host . ( $default_port ? '' : ':' . $port );
    }

    private static function failure( $reason ) {
        return array( 'ok' => false, 'reason' => $reason, 'outcome' => $reason );
    }
}
