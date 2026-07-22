<?php
/**
 * Owner-level tests for bounded WordPress-to-Worker operations.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/WorkerClient.php';

$fixture = json_decode( file_get_contents( __DIR__ . '/../fixtures/worker_protocol.json' ), true );
$encoded_key = $fixture['active_key_b64'];
$secret = WorkerProtocol::decode_integration_key( $encoded_key );
$worker_client_path = realpath( __DIR__ . '/../../src/Uploads/WorkerClient.php' );
$same_origin_probe = 'function home_url($path = "/") { return "https://media.example.test/"; }'
    . 'define("EFORMS_UPLOAD_COMPOSITION", "worker_r2_cloudflare");'
    . 'define("EFORMS_WORKER_URL", "https://media.example.test");'
    . 'define("EFORMS_WORKER_ENVIRONMENT_ID", ' . var_export( $fixture['environment'], true ) . ');'
    . 'define("EFORMS_WORKER_ACTIVE_KEY_ID", ' . var_export( $fixture['active_key_id'], true ) . ');'
    . 'define("EFORMS_WORKER_ACTIVE_KEY_B64", ' . var_export( $encoded_key, true ) . ');'
    . 'require ' . var_export( $worker_client_path, true ) . ';'
    . 'exit(WorkerClient::configuration() === null ? 0 : 1);';
$same_origin_output = array();
$same_origin_status = 1;
exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $same_origin_probe ), $same_origin_output, $same_origin_status );
eforms_test_assert( $same_origin_status === 0, 'Worker wiring must reject the WordPress origin so browser uploads cannot inherit WordPress cookies.' );
$default_port_probe = 'function home_url($path = "/") { return "https://media.example.test:443/"; }'
    . 'define("EFORMS_UPLOAD_COMPOSITION", "worker_r2_cloudflare");'
    . 'define("EFORMS_WORKER_URL", "https://media.example.test");'
    . 'define("EFORMS_WORKER_ENVIRONMENT_ID", ' . var_export( $fixture['environment'], true ) . ');'
    . 'define("EFORMS_WORKER_ACTIVE_KEY_ID", ' . var_export( $fixture['active_key_id'], true ) . ');'
    . 'define("EFORMS_WORKER_ACTIVE_KEY_B64", ' . var_export( $encoded_key, true ) . ');'
    . 'require ' . var_export( $worker_client_path, true ) . ';'
    . 'exit(WorkerClient::configuration() === null ? 0 : 1);';
$default_port_output = array();
$default_port_status = 1;
exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $default_port_probe ), $default_port_output, $default_port_status );
eforms_test_assert( $default_port_status === 0, 'Equivalent implicit and explicit HTTPS default ports must be rejected as the same WordPress origin.' );
$canonical_origin_probe = 'function home_url($path = "/") { return "https://wordpress.example.test/"; }'
    . 'define("EFORMS_UPLOAD_COMPOSITION", "worker_r2_cloudflare");'
    . 'define("EFORMS_WORKER_URL", "https://media.example.test:443");'
    . 'define("EFORMS_WORKER_ENVIRONMENT_ID", ' . var_export( $fixture['environment'], true ) . ');'
    . 'define("EFORMS_WORKER_ACTIVE_KEY_ID", ' . var_export( $fixture['active_key_id'], true ) . ');'
    . 'define("EFORMS_WORKER_ACTIVE_KEY_B64", ' . var_export( $encoded_key, true ) . ');'
    . 'require ' . var_export( $worker_client_path, true ) . ';'
    . '$configuration = WorkerClient::configuration();'
    . 'exit(is_array($configuration) && $configuration["origin"] === "https://media.example.test" ? 0 : 1);';
$canonical_origin_output = array();
$canonical_origin_status = 1;
exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $canonical_origin_probe ), $canonical_origin_output, $canonical_origin_status );
eforms_test_assert( $canonical_origin_status === 0, 'Worker identity must canonicalize an explicit HTTPS default port before persistence or fingerprinting.' );
define( 'EFORMS_UPLOAD_COMPOSITION', 'worker_r2_cloudflare' );
define( 'EFORMS_WORKER_URL', 'https://media.example.test' );
define( 'EFORMS_WORKER_ENVIRONMENT_ID', $fixture['environment'] );
define( 'EFORMS_WORKER_ACTIVE_KEY_ID', $fixture['active_key_id'] );
define( 'EFORMS_WORKER_ACTIVE_KEY_B64', $encoded_key );

$now = $fixture['verification_now'];
$composition_fingerprint = WorkerClient::composition_fingerprint();
$object_claims = array();
Logging::reset_for_tests();
$delete = WorkerClient::delete_object(
    $fixture['claims']['object_request']['object_key'],
    $fixture['claims']['object_request']['object_version'],
    $composition_fingerprint,
    $now,
    function ( $url, $arguments ) use ( &$object_claims, $fixture, $secret ) {
        eforms_test_assert( $url === 'https://media.example.test/v1/object', 'Object operations should use the configured HTTPS Worker origin.' );
        eforms_test_assert( ! isset( $arguments['headers']['Authorization'], $arguments['headers']['Cookie'] ), 'Server operations must not send WordPress authority headers.' );
        eforms_test_assert( $arguments['timeout'] === Anchors::get( 'WORKER_SERVER_REQUEST_TIMEOUT_SECONDS' ), 'Server operations should use the bounded request timeout.' );
        eforms_test_assert( $arguments['limit_response_size'] === Anchors::get( 'WORKER_RESPONSE_MAX_BYTES' ), 'The WordPress transport must stop buffering at the response-size bound.' );
        $token = $arguments['headers'][ WorkerClient::OBJECT_HEADER ];
        $object_claims = eforms_test_worker_client_claims( $token, WorkerProtocol::OBJECT_REQUEST_DOMAIN, 5 );
        $result = array(
            'request_id' => $object_claims['request_id'],
            'object_key' => $object_claims['object_key'],
            'object_version' => $object_claims['object_version'],
            'status' => 'absent',
            'expires_at' => $object_claims['expires_at'],
        );
        return array(
            'status' => 200,
            'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::OBJECT_RESULT_DOMAIN, $result, $fixture, $secret ) ) ),
        );
    }
);
eforms_test_assert( WorkerClient::composition() === FormProtocol::UPLOAD_TRANSPORT_WORKER, 'Valid deployment constants should select the Worker composition.' );
eforms_test_assert( WorkerClient::review_provider() === 'worker', 'The Worker composition should bind the Cloudflare review provider.' );
eforms_test_assert(
    WorkerClient::composition_fingerprint() === hash( 'sha256', json_encode( array( 'worker_r2_cloudflare', 'https://media.example.test', $fixture['environment'] ), JSON_UNESCAPED_SLASHES ) ),
    'The persisted purge identity should bind stable storage deployment facts without rotating key IDs.'
);
eforms_test_assert( ! empty( $delete['ok'] ) && ! empty( $delete['absent'] ), 'A signed exact-object absence result should complete deletion.' );
eforms_test_assert( $object_claims['action'] === 'delete' && $object_claims['expires_at'] === $now + Anchors::get( 'WORKER_OPERATION_GRANT_TTL_SECONDS' ), 'Object authority should be delete-only and short-lived.' );
$delete_event = end( Logging::$events );
eforms_test_assert(
    is_array( $delete_event )
        && $delete_event['code'] === 'EFORMS_WORKER_OPERATION'
        && $delete_event['severity'] === 'info'
        && $delete_event['meta']['operation'] === 'delete'
        && $delete_event['meta']['outcome_class'] === 'success'
        && $delete_event['meta']['retry'] === 'not_needed'
        && $delete_event['meta']['cleanup_phase'] === 'direct_cleanup'
        && in_array( $delete_event['meta']['latency_bucket'], array( 'fast', 'normal', 'slow', 'very_slow' ), true ),
    'Worker operations should emit one closed, privacy-safe outcome event.'
);
eforms_test_assert(
    array_keys( $delete_event['meta'] ) === array( 'operation', 'outcome_class', 'latency_bucket', 'retry', 'cleanup_phase' )
        && strpos( json_encode( $delete_event['meta'] ), $fixture['claims']['object_request']['object_key'] ) === false
        && strpos( json_encode( $delete_event['meta'] ), $fixture['claims']['object_request']['object_version'] ) === false
        && strpos( json_encode( $delete_event['meta'] ), $encoded_key ) === false,
    'Worker observability must exclude locators, versions, grants, receipts, customer values, and secrets by construction.'
);
$operator_claims = array();
$operator_delete = WorkerClient::delete_object(
    $fixture['claims']['object_request']['object_key'],
    $fixture['claims']['object_request']['object_version'],
    $composition_fingerprint,
    $now,
    function ( $url, $arguments ) use ( &$operator_claims, $fixture, $secret ) {
        $operator_claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 5 );
        $result = array(
            'request_id' => $operator_claims['request_id'],
            'object_key' => $operator_claims['object_key'],
            'object_version' => $operator_claims['object_version'],
            'status' => 'absent',
            'expires_at' => $operator_claims['expires_at'],
        );
        return array(
            'status' => 200,
            'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::OBJECT_RESULT_DOMAIN, $result, $fixture, $secret ) ) ),
        );
    },
    'operator_review_delete'
);
$operator_delete_event = end( Logging::$events );
eforms_test_assert(
    ! empty( $operator_delete['ok'] )
        && $operator_claims['action'] === 'delete'
        && $operator_delete_event['meta']['cleanup_phase'] === 'operator_review_delete',
    'Operator review deletion should keep its closed Worker cleanup phase in operation events.'
);

$review_claims = $fixture['claims']['review_grant'];
$review_claims['recipe_version'] = WorkerProtocol::REVIEW_RECIPE_VERSION;
$review_url = WorkerClient::review_url( $review_claims, $composition_fingerprint, $now );
$review_query = array();
parse_str( (string) parse_url( $review_url, PHP_URL_QUERY ), $review_query );
$expected_review = WorkerProtocol::sign_review_grant( $review_claims, $fixture['active_key_id'], $secret, $fixture['environment'] );
eforms_test_assert(
    strpos( $review_url, 'https://media.example.test/v1/review?' ) === 0
        && isset( $review_query[ WorkerClient::REVIEW_QUERY ] )
        && hash_equals( $expected_review, $review_query[ WorkerClient::REVIEW_QUERY ] ),
    'Review URLs should carry one exact Worker-signed artifact/version/action/recipe grant.'
);

$mismatch = WorkerClient::delete_object(
    $fixture['claims']['object_request']['object_key'],
    $fixture['claims']['object_request']['object_version'],
    $composition_fingerprint,
    $now,
    function ( $url, $arguments ) use ( $fixture, $secret ) {
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 5 );
        $result = array(
            'request_id' => $claims['request_id'],
            'object_key' => $claims['object_key'],
            'object_version' => $claims['object_version'],
            'status' => 'version_mismatch',
            'expires_at' => $claims['expires_at'],
        );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::OBJECT_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    },
    'aggregate_gc'
);
eforms_test_assert( empty( $mismatch['ok'] ) && $mismatch['reason'] === 'version_mismatch', 'A changed remote version must fail closed without confirming absence.' );
$mismatch_event = end( Logging::$events );
eforms_test_assert(
    $mismatch_event['severity'] === 'warning'
        && $mismatch_event['meta']['outcome_class'] === 'authoritative_rejection'
        && $mismatch_event['meta']['retry'] === 'required'
        && $mismatch_event['meta']['cleanup_phase'] === 'aggregate_gc',
    'Failed cleanup should emit its closed outcome and caller-owned phase without provider details.'
);

$inspect = WorkerClient::inspect_object(
    $fixture['claims']['object_request']['object_key'],
    $fixture['claims']['object_request']['object_version'],
    $composition_fingerprint,
    $now,
    function ( $url, $arguments ) use ( $fixture, $secret ) {
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 5 );
        eforms_test_assert( $claims['action'] === 'inspect', 'Restore inspection should carry read-only exact-object authority.' );
        $result = array(
            'request_id' => $claims['request_id'],
            'object_key' => $claims['object_key'],
            'object_version' => $claims['object_version'],
            'status' => 'present',
            'expires_at' => $claims['expires_at'],
        );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::OBJECT_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    }
);
eforms_test_assert( ! empty( $inspect['ok'] ) && ! empty( $inspect['present'] ), 'A signed exact-version presence result should support restore verification without mutation.' );

$wrong_deployment_called = false;
$wrong_deployment = WorkerClient::delete_object(
    $fixture['claims']['object_request']['object_key'],
    $fixture['claims']['object_request']['object_version'],
    str_repeat( 'f', 64 ),
    $now,
    function () use ( &$wrong_deployment_called ) {
        $wrong_deployment_called = true;
        return array();
    }
);
eforms_test_assert(
    empty( $wrong_deployment['ok'] ) && ! $wrong_deployment_called,
    'An aggregate bound to another Worker deployment must fail before any remote request is issued.'
);

$health = WorkerClient::health(
    $now,
    function ( $url, $arguments ) use ( $fixture, $secret ) {
        eforms_test_assert( $url === 'https://media.example.test/v1/health', 'Health should use the signed data-plane endpoint.' );
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::HEALTH_HEADER ], WorkerProtocol::HEALTH_REQUEST_DOMAIN, 2 );
        $result = array(
            'request_id' => $claims['request_id'],
            'storage_ready' => 1,
            'inspection_ready' => 1,
            'checked_at' => $fixture['verification_now'],
            'expires_at' => $claims['expires_at'],
        );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::HEALTH_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    }
);
eforms_test_assert( ! empty( $health['ok'] ) && $health['outcome'] === 'ready', 'Signed storage and inspection readiness should pass.' );

$unhealthy = WorkerClient::health(
    $now,
    function ( $url, $arguments ) use ( $fixture, $secret ) {
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::HEALTH_HEADER ], WorkerProtocol::HEALTH_REQUEST_DOMAIN, 2 );
        $result = array(
            'request_id' => $claims['request_id'],
            'storage_ready' => 1,
            'inspection_ready' => 0,
            'checked_at' => $fixture['verification_now'],
            'expires_at' => $claims['expires_at'],
        );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::HEALTH_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    }
);
eforms_test_assert( empty( $unhealthy['ok'] ) && $unhealthy['outcome'] === 'dependency_unavailable', 'Signed degraded health should retain its explicit dependency outcome.' );
$unhealthy_event = end( Logging::$events );
eforms_test_assert(
    $unhealthy_event['meta']['outcome_class'] === 'dependency_unavailable'
        && $unhealthy_event['meta']['retry'] === 'required',
    'Signed degraded health should not collapse into a generic operation-failure event.'
);

$oversized = WorkerClient::health(
    $now,
    function () {
        return array( 'status' => 200, 'body' => str_repeat( 'x', Anchors::get( 'WORKER_RESPONSE_MAX_BYTES' ) + 1 ) );
    }
);
eforms_test_assert( empty( $oversized['ok'] ) && $oversized['reason'] === 'request_rejected', 'Oversized Worker responses should be rejected before decoding.' );

echo "Worker client tests passed.\n";

function eforms_test_worker_client_claims( $token, $domain, $claim_count ) {
    $segments = explode( '.', $token );
    eforms_test_assert( count( $segments ) === 2, 'The test transport should receive a canonical signed envelope.' );
    $padding = ( 4 - strlen( $segments[0] ) % 4 ) % 4;
    $payload = base64_decode( strtr( $segments[0], '-_', '+/' ) . str_repeat( '=', $padding ), true );
    $parts = array();
    $offset = 0;
    while ( is_string( $payload ) && $offset < strlen( $payload ) ) {
        $length = unpack( 'Nlength', substr( $payload, $offset, 4 ) );
        $offset += 4;
        $parts[] = substr( $payload, $offset, $length['length'] );
        $offset += $length['length'];
    }
    eforms_test_assert( count( $parts ) === 4 + $claim_count && $parts[0] === $domain, 'The test transport should receive the expected closed request schema.' );
    if ( $domain === WorkerProtocol::OBJECT_REQUEST_DOMAIN ) {
        return array(
            'request_id' => $parts[4],
            'object_key' => $parts[5],
            'object_version' => $parts[6],
            'action' => $parts[7],
            'expires_at' => (int) $parts[8],
        );
    }
    return array( 'request_id' => $parts[4], 'expires_at' => (int) $parts[5] );
}

function eforms_test_worker_client_signed_result( $domain, $claims, $fixture, $secret ) {
    $parts = array_merge(
        array( $domain, $fixture['version'], $fixture['active_key_id'], $fixture['environment'] ),
        array_map( 'strval', array_values( $claims ) )
    );
    $payload = '';
    foreach ( $parts as $part ) {
        $payload .= pack( 'N', strlen( $part ) ) . $part;
    }
    $encode = function ( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    };
    return $encode( $payload ) . '.' . $encode( hash_hmac( 'sha256', $payload, $secret, true ) );
}
