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
$malformed_origin_probe = 'function home_url($path = "/") { return "https://wordpress.example.test/"; }'
    . 'define("EFORMS_UPLOAD_COMPOSITION", "worker_r2_cloudflare");'
    . 'define("EFORMS_WORKER_URL", "https://media.example.test' . "\\n" . '");'
    . 'define("EFORMS_WORKER_ENVIRONMENT_ID", ' . var_export( $fixture['environment'], true ) . ');'
    . 'define("EFORMS_WORKER_ACTIVE_KEY_ID", ' . var_export( $fixture['active_key_id'], true ) . ');'
    . 'define("EFORMS_WORKER_ACTIVE_KEY_B64", ' . var_export( $encoded_key, true ) . ');'
    . 'require ' . var_export( $worker_client_path, true ) . ';'
    . 'exit(WorkerClient::configuration() === null ? 0 : 1);';
$malformed_origin_output = array();
$malformed_origin_status = 1;
exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $malformed_origin_probe ), $malformed_origin_output, $malformed_origin_status );
eforms_test_assert( $malformed_origin_status === 0, 'Worker identity must reject non-canonical raw origins before persistence or fingerprinting.' );
define( 'EFORMS_UPLOAD_COMPOSITION', 'worker_r2_cloudflare' );
define( 'EFORMS_WORKER_URL', 'https://media.example.test' );
define( 'EFORMS_WORKER_ENVIRONMENT_ID', $fixture['environment'] );
define( 'EFORMS_WORKER_ACTIVE_KEY_ID', $fixture['active_key_id'] );
define( 'EFORMS_WORKER_ACTIVE_KEY_B64', $encoded_key );

$now = $fixture['verification_now'];
$composition_fingerprint = WorkerClient::composition_fingerprint();
$worker_known_delete = array_replace(
    $fixture['worker_claims']['object_request_known_delete'],
    array( 'storage_identity' => $composition_fingerprint, 'expected_composition_fingerprint' => $composition_fingerprint )
);
$worker_unknown_delete = array_replace(
    $fixture['worker_claims']['object_request_unknown_delete'],
    array( 'storage_identity' => $composition_fingerprint, 'expected_composition_fingerprint' => $composition_fingerprint )
);
$worker_known_inspect = array_replace(
    $fixture['worker_claims']['object_request_known_inspect'],
    array( 'storage_identity' => $composition_fingerprint )
);
$object_claims = array();
Logging::reset_for_tests();
$delete = WorkerClient::worker_delete_object(
    $worker_known_delete,
    $now,
    function ( $url, $arguments ) use ( &$object_claims, $worker_known_delete, $fixture, $secret ) {
        eforms_test_assert( $url === 'https://media.example.test/v1/object', 'Object operations should use the configured HTTPS Worker origin.' );
        eforms_test_assert( ! isset( $arguments['headers']['Authorization'], $arguments['headers']['Cookie'] ), 'Server operations must not send WordPress authority headers.' );
        eforms_test_assert( $arguments['timeout'] === Anchors::get( 'WORKER_SERVER_REQUEST_TIMEOUT_SECONDS' ), 'Server operations should use the bounded request timeout.' );
        eforms_test_assert( $arguments['limit_response_size'] === Anchors::get( 'WORKER_RESPONSE_MAX_BYTES' ), 'The WordPress transport must stop buffering at the response-size bound.' );
        $token = $arguments['headers'][ WorkerClient::OBJECT_HEADER ];
        $object_claims = eforms_test_worker_client_claims( $token, WorkerProtocol::OBJECT_REQUEST_DOMAIN, 14 );
        eforms_test_worker_client_assert_worker_object_request( $object_claims, $worker_known_delete, 'delete' );
        return array(
            'status' => 200,
            'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( $object_claims, 'absent', $fixture, $secret ) ) ),
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
eforms_test_worker_client_assert_operation_event(
    $delete_event,
    array(
        'severity' => 'info',
        'operation' => 'delete',
        'operation_category' => 'cleanup',
        'outcome_class' => 'success',
        'retry' => 'not_needed',
        'cleanup_phase' => 'direct_cleanup',
    ),
    'Worker operations should emit one closed, privacy-safe outcome event.'
);
eforms_test_assert(
    strpos( json_encode( $delete_event['meta'] ), $worker_known_delete['object_key'] ) === false
        && strpos( json_encode( $delete_event['meta'] ), $worker_known_delete['object_version'] ) === false
        && strpos( json_encode( $delete_event['meta'] ), $encoded_key ) === false,
    'Worker observability must exclude locators, versions, grants, receipts, customer values, and secrets by construction.'
);
$operator_claims = array();
$operator_delete = WorkerClient::worker_delete_object(
    $worker_known_delete,
    $now,
    function ( $url, $arguments ) use ( &$operator_claims, $worker_known_delete, $fixture, $secret ) {
        $operator_claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 14 );
        eforms_test_worker_client_assert_worker_object_request( $operator_claims, $worker_known_delete, 'delete' );
        return array(
            'status' => 200,
            'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( $operator_claims, 'absent', $fixture, $secret ) ) ),
        );
    },
    'operator_delete'
);
$operator_delete_event = end( Logging::$events );
eforms_test_assert( ! empty( $operator_delete['ok'] ) && $operator_claims['action'] === 'delete', 'Operator review deletion should succeed with a delete-only grant.' );
eforms_test_worker_client_assert_operation_event(
    $operator_delete_event,
    array(
        'operation' => 'delete',
        'operation_category' => 'review_readiness',
        'cleanup_phase' => 'operator_delete',
    ),
    'Operator review deletion should keep its closed Worker cleanup phase in operation events.'
);

$worker_review_claims = $fixture['worker_claims']['review_grant'];
$artifact_store_identity = $worker_review_claims['storage_identity'];
$alternate_artifact_store_identity = str_repeat( 'f', 64 );
$worker_review_now = $worker_review_claims['expires_at'] - Anchors::get( 'WORKER_REVIEW_GRANT_TTL_SECONDS' );
$worker_review_url = WorkerClient::worker_review_url( $worker_review_claims, $artifact_store_identity, $worker_review_now );
$worker_review_query = array();
parse_str( (string) parse_url( $worker_review_url, PHP_URL_QUERY ), $worker_review_query );
$expected_worker_review = WorkerProtocol::sign_worker_review_grant( $worker_review_claims, $fixture['active_key_id'], $secret, $fixture['environment'] );
eforms_test_assert(
    strpos( $worker_review_url, 'https://media.example.test/v1/review?' ) === 0
        && isset( $worker_review_query[ WorkerClient::REVIEW_QUERY ] )
        && hash_equals( $expected_worker_review, $worker_review_query[ WorkerClient::REVIEW_QUERY ] ),
    'Dormant candidate review URLs should carry one exact v3 Worker-signed artifact/version/action/recipe grant.'
);

$worker_gallery_requests = array();
$empty_gallery = WorkerClient::worker_gallery_status(
    $fixture['worker_claims']['gallery_status_request']['submission_id'],
    $fixture['worker_claims']['gallery_status_request']['storage_identity'],
    array(),
    $artifact_store_identity,
    $now,
    function ( $url, $arguments ) use ( &$worker_gallery_requests, $fixture, $secret ) {
        eforms_test_worker_client_assert_gallery_request_transport( $url, $arguments );
        $request_claims = eforms_test_worker_client_gallery_request_claims( $arguments, array(), $fixture );
        $worker_gallery_requests[] = $request_claims;
        return array(
            'status' => 200,
            'body' => eforms_test_worker_client_gallery_response( $request_claims, array(), array(), $fixture, $secret ),
        );
    }
);
eforms_test_assert(
    ! empty( $empty_gallery['ok'] )
        && $empty_gallery['statuses'] === array()
        && $empty_gallery['checked_at'] === $now,
    'Dormant candidate gallery status should accept an exact empty canonical result.'
);

$mixed_items = eforms_test_worker_client_gallery_items( 3 );
$mixed_statuses = array(
    array( 'upload_id' => $mixed_items[0]['upload_id'], 'status' => 'accepted', 'mime' => 'image/png', 'width' => 32, 'height' => 24 ),
    array( 'upload_id' => $mixed_items[1]['upload_id'], 'status' => 'pending' ),
    array( 'upload_id' => $mixed_items[2]['upload_id'], 'status' => 'unavailable' ),
);
$mixed_gallery = WorkerClient::worker_gallery_status(
    $fixture['worker_claims']['gallery_status_request']['submission_id'],
    $fixture['worker_claims']['gallery_status_request']['storage_identity'],
    $mixed_items,
    $artifact_store_identity,
    $now,
    function ( $url, $arguments ) use ( &$worker_gallery_requests, $mixed_items, $mixed_statuses, $fixture, $secret ) {
        eforms_test_worker_client_assert_gallery_request_transport( $url, $arguments );
        $request_claims = eforms_test_worker_client_gallery_request_claims( $arguments, $mixed_items, $fixture );
        $worker_gallery_requests[] = $request_claims;
        return array(
            'status' => 200,
            'body' => eforms_test_worker_client_gallery_response( $request_claims, $mixed_statuses, $mixed_items, $fixture, $secret ),
        );
    }
);
eforms_test_assert(
    ! empty( $mixed_gallery['ok'] )
        && $mixed_gallery['statuses'] === $mixed_statuses
        && count( $worker_gallery_requests ) === 2,
    'Dormant candidate gallery status should return exact ordered accepted, pending, and unavailable statuses.'
);

$max_items = eforms_test_worker_client_gallery_items( Anchors::get( 'MANAGED_STAGED_MAX_FILES' ) );
$max_statuses = eforms_test_worker_client_gallery_statuses( $max_items );
$max_gallery = WorkerClient::worker_gallery_status(
    $fixture['worker_claims']['gallery_status_request']['submission_id'],
    $fixture['worker_claims']['gallery_status_request']['storage_identity'],
    $max_items,
    $artifact_store_identity,
    $now,
    function ( $url, $arguments ) use ( $max_items, $max_statuses, $fixture, $secret ) {
        eforms_test_worker_client_assert_gallery_request_transport( $url, $arguments );
        eforms_test_assert(
            strlen( $arguments['body'] ) <= Anchors::get( 'WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES' ),
            'The max candidate gallery status request should fit under the dedicated request cap.'
        );
        $request_claims = eforms_test_worker_client_gallery_request_claims( $arguments, $max_items, $fixture );
        return array(
            'status' => 200,
            'body' => eforms_test_worker_client_gallery_response( $request_claims, $max_statuses, $max_items, $fixture, $secret ),
        );
    }
);
eforms_test_assert(
    ! empty( $max_gallery['ok'] ) && count( $max_gallery['statuses'] ) === Anchors::get( 'MANAGED_STAGED_MAX_FILES' ),
    'Dormant candidate gallery status should accept exactly the Anchor-owned max item count.'
);
eforms_test_assert(
    $worker_gallery_requests[0]['expires_at'] === $now + Anchors::get( 'WORKER_OPERATION_GRANT_TTL_SECONDS' )
        && $worker_gallery_requests[1]['item_count'] === count( $mixed_items )
        && $worker_gallery_requests[1]['items_sha256'] === WorkerProtocol::worker_gallery_items_sha256( $mixed_items ),
    'Dormant candidate gallery requests should bind short-lived expiry, exact count, and canonical item hash.'
);

$worker_invalid_items = $mixed_items;
$worker_invalid_items[0]['extra'] = 'not allowed';
$worker_invalid_cases = array(
    array( 'storage identity mismatch', $fixture['worker_claims']['gallery_status_request']['submission_id'], $fixture['worker_claims']['gallery_status_request']['storage_identity'], $mixed_items, $alternate_artifact_store_identity ),
    array( 'bad submission id', 'not valid', $fixture['worker_claims']['gallery_status_request']['storage_identity'], $mixed_items, $artifact_store_identity ),
    array( 'bad storage identity', $fixture['worker_claims']['gallery_status_request']['submission_id'], 'not-hex', $mixed_items, $artifact_store_identity ),
    array( 'bad item shape', $fixture['worker_claims']['gallery_status_request']['submission_id'], $fixture['worker_claims']['gallery_status_request']['storage_identity'], $worker_invalid_items, $artifact_store_identity ),
    array( 'too many items', $fixture['worker_claims']['gallery_status_request']['submission_id'], $fixture['worker_claims']['gallery_status_request']['storage_identity'], eforms_test_worker_client_gallery_items( Anchors::get( 'MANAGED_STAGED_MAX_FILES' ) + 1 ), $artifact_store_identity ),
);
foreach ( $worker_invalid_cases as $case ) {
    $called = false;
    $result = WorkerClient::worker_gallery_status(
        $case[1],
        $case[2],
        $case[3],
        $case[4],
        $now,
        function () use ( &$called ) {
            $called = true;
            return array();
        }
    );
    eforms_test_assert( empty( $result['ok'] ) && ! isset( $result['statuses'] ) && ! $called, 'Invalid candidate gallery input should fail closed before transport: ' . $case[0] );
}
eforms_test_assert( WorkerClient::worker_review_url( $worker_review_claims, $alternate_artifact_store_identity, $worker_review_now ) === '', 'Dormant candidate review URLs should fail closed for storage identity mismatch.' );
eforms_test_assert( WorkerClient::worker_review_url( array_replace( $worker_review_claims, array( 'storage_identity' => $alternate_artifact_store_identity ) ), $artifact_store_identity, $worker_review_now ) === '', 'Dormant candidate review URLs should reject claims for a different artifact-store identity.' );
eforms_test_assert( WorkerClient::worker_review_url( array_replace( $worker_review_claims, array( 'expires_at' => $worker_review_now ) ), $artifact_store_identity, $worker_review_now ) === '', 'Dormant candidate review URLs should close at expiry equality.' );
eforms_test_assert( WorkerClient::worker_review_url( $worker_review_claims, $artifact_store_identity, $worker_review_now - 1 ) === '', 'Dormant candidate review URLs should reject grants outside the review TTL.' );

$alternate_request_claims = array();
$alternate_gallery = WorkerClient::worker_gallery_status(
    $fixture['worker_claims']['gallery_status_request']['submission_id'],
    $alternate_artifact_store_identity,
    $mixed_items,
    $alternate_artifact_store_identity,
    $now,
    function ( $url, $arguments ) use ( &$alternate_request_claims, $mixed_items, $mixed_statuses, $fixture, $secret, $alternate_artifact_store_identity ) {
        eforms_test_worker_client_assert_gallery_request_transport( $url, $arguments );
        $alternate_request_claims = eforms_test_worker_client_gallery_request_claims( $arguments, $mixed_items, $fixture, $alternate_artifact_store_identity );
        return array(
            'status' => 200,
            'body' => eforms_test_worker_client_gallery_response( $alternate_request_claims, $mixed_statuses, $mixed_items, $fixture, $secret ),
        );
    }
);
eforms_test_assert(
    empty( $alternate_gallery['ok'] )
        && empty( $alternate_request_claims ),
    'Dormant candidate gallery requests should fail before transport when the persisted Worker identity is not the current composition.'
);

$transport_failures = array(
    array( 'transport', array() ),
    array( 'rejected', array( 'status' => 403, 'body' => '{}' ) ),
    array( 'server', array( 'status' => 503, 'body' => '{}' ) ),
    array( 'oversize', array( 'status' => 200, 'body' => str_repeat( 'x', Anchors::get( 'WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES' ) + 1 ) ) ),
);
foreach ( $transport_failures as $case ) {
    $result = WorkerClient::worker_gallery_status(
        $fixture['worker_claims']['gallery_status_request']['submission_id'],
        $fixture['worker_claims']['gallery_status_request']['storage_identity'],
        $mixed_items,
        $artifact_store_identity,
        $now,
        function () use ( $case ) {
            return $case[1];
        }
    );
    eforms_test_assert( empty( $result['ok'] ) && ! isset( $result['statuses'] ), 'Worker gallery transport failure should return no partial statuses: ' . $case[0] );
}

$response_failure_cases = array(
    'missing result envelope' => function ( $request_claims, $items, $statuses, $fixture, $secret ) {
        return json_encode( array( 'statuses' => $statuses ), JSON_UNESCAPED_SLASHES );
    },
    'noncanonical whitespace' => function ( $request_claims, $items, $statuses, $fixture, $secret ) {
        $token = eforms_test_worker_client_gallery_result_token( $request_claims, $statuses, $fixture, $secret );
        return json_encode( array( 'result' => $token, 'statuses' => $statuses ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    },
    'wrong request id' => function ( $request_claims, $items, $statuses, $fixture, $secret ) {
        return eforms_test_worker_client_gallery_response( $request_claims, $statuses, $items, $fixture, $secret, array( 'request_id' => 'wrong_request' ) );
    },
    'status order mismatch' => function ( $request_claims, $items, $statuses, $fixture, $secret ) {
        $token = eforms_test_worker_client_gallery_result_token( $request_claims, $statuses, $fixture, $secret );
        return json_encode( array( 'result' => $token, 'statuses' => array_reverse( $statuses ) ), JSON_UNESCAPED_SLASHES );
    },
);
foreach ( $response_failure_cases as $name => $response_factory ) {
    $result = WorkerClient::worker_gallery_status(
        $fixture['worker_claims']['gallery_status_request']['submission_id'],
        $fixture['worker_claims']['gallery_status_request']['storage_identity'],
        $mixed_items,
        $artifact_store_identity,
        $now,
        function ( $url, $arguments ) use ( $mixed_items, $mixed_statuses, $response_factory, $fixture, $secret ) {
            $request_claims = eforms_test_worker_client_gallery_request_claims( $arguments, $mixed_items, $fixture );
            return array( 'status' => 200, 'body' => $response_factory( $request_claims, $mixed_items, $mixed_statuses, $fixture, $secret ) );
        }
    );
    eforms_test_assert( empty( $result['ok'] ) && ! isset( $result['statuses'] ), 'Invalid candidate gallery response should fail with no partial statuses: ' . $name );
}

$worker_delete_claims = array();
$worker_delete = WorkerClient::worker_delete_object(
    $worker_known_delete,
    $now,
    function ( $url, $arguments ) use ( &$worker_delete_claims, $worker_known_delete, $fixture, $secret ) {
        eforms_test_worker_client_assert_worker_object_transport( $url, $arguments );
        $worker_delete_claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 14 );
        eforms_test_worker_client_assert_worker_object_request( $worker_delete_claims, $worker_known_delete, 'delete' );
        return array(
            'status' => 200,
            'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( $worker_delete_claims, 'absent', $fixture, $secret ) ) ),
        );
    }
);
eforms_test_assert( ! empty( $worker_delete['ok'] ) && ! empty( $worker_delete['absent'] ), 'Dormant candidate known delete should accept signed absent results only.' );

$worker_unknown_claims = array();
$worker_unknown = WorkerClient::worker_delete_object(
    $worker_unknown_delete,
    $now,
    function ( $url, $arguments ) use ( &$worker_unknown_claims, $worker_unknown_delete, $fixture, $secret ) {
        eforms_test_worker_client_assert_worker_object_transport( $url, $arguments );
        $worker_unknown_claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 14 );
        eforms_test_worker_client_assert_worker_object_request( $worker_unknown_claims, $worker_unknown_delete, 'delete' );
        return array(
            'status' => 200,
            'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( $worker_unknown_claims, 'absent', $fixture, $secret ) ) ),
        );
    }
);
eforms_test_assert(
    ! empty( $worker_unknown['ok'] )
        && ! empty( $worker_unknown['absent'] )
        && $worker_unknown_claims['object_version'] === '-',
    'Dormant candidate unknown delete should preserve the unknown object-version sentinel through request and result validation.'
);

$worker_inspect_claims = array();
$worker_inspect = WorkerClient::worker_inspect_object(
    $worker_known_inspect['upload_id'],
    $worker_known_inspect['storage_identity'],
    $worker_known_inspect['validation_contract_version'],
    $worker_known_inspect['object_key'],
    $worker_known_inspect['object_version'],
    $worker_known_inspect['etag'],
    $worker_known_inspect['bytes'],
    $worker_known_inspect['policy_fingerprint'],
    $composition_fingerprint,
    $now,
    function ( $url, $arguments ) use ( &$worker_inspect_claims, $worker_known_inspect, $fixture, $secret ) {
        eforms_test_worker_client_assert_worker_object_transport( $url, $arguments );
        $worker_inspect_claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 14 );
        eforms_test_worker_client_assert_worker_object_request( $worker_inspect_claims, $worker_known_inspect, 'inspect' );
        return array(
            'status' => 200,
            'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( $worker_inspect_claims, 'present', $fixture, $secret ) ) ),
        );
    }
);
eforms_test_assert( ! empty( $worker_inspect['ok'] ) && ! empty( $worker_inspect['present'] ), 'Dormant candidate inspect should accept signed present results only.' );

$parsed_worker_key = ManagedArtifactKey::parse( $worker_known_delete['object_key'] );
eforms_test_assert(
    $worker_delete_claims['batch_id'] === $parsed_worker_key['namespace']
        && $worker_delete_claims['intent_id'] === $parsed_worker_key['intent_id']
        && $worker_delete_claims['ordinal'] === $parsed_worker_key['ordinal']
        && $worker_delete_claims['expires_at'] === $now + Anchors::get( 'WORKER_OPERATION_GRANT_TTL_SECONDS' ),
    'Dormant candidate object requests should bind batch, intent, ordinal, and short-lived expiry from manifest-owned facts.'
);

$worker_invalid_inputs = array(
    'storage identity mismatch' => array_replace( $worker_known_delete, array( 'storage_identity' => str_repeat( 'f', 64 ) ) ),
    'composition mismatch' => array_replace( $worker_known_delete, array( 'expected' => str_repeat( 'f', 64 ) ) ),
    'malformed key' => array_replace( $worker_known_delete, array( 'object_key' => 'not/a/managed/key' ) ),
    'one-sided sentinel' => array_replace( $worker_known_delete, array( 'object_version' => '-', 'etag' => 'etag-known' ) ),
    'inspect unknown sentinel' => array_replace( $worker_known_inspect, array( 'object_version' => '-', 'etag' => '-' ) ),
);
foreach ( $worker_invalid_inputs as $name => $claims ) {
    $called = false;
    $requester = function () use ( &$called ) {
            $called = true;
            return array();
        };
    if ( $name === 'inspect unknown sentinel' ) {
        $result = WorkerClient::worker_inspect_object(
            $claims['upload_id'], $claims['storage_identity'], $claims['validation_contract_version'],
            $claims['object_key'], $claims['object_version'], $claims['etag'], $claims['bytes'],
            $claims['policy_fingerprint'], $composition_fingerprint, $now, $requester
        );
    } else {
        $claims['expected_composition_fingerprint'] = isset( $claims['expected'] ) ? $claims['expected'] : $composition_fingerprint;
        $result = WorkerClient::worker_delete_object( $claims, $now, $requester );
    }
    eforms_test_assert( empty( $result['ok'] ) && ! $called, 'Invalid dormant candidate object input should fail before transport: ' . $name );
}

$worker_status_cases = array(
    'delete present' => array( 'method' => 'worker_delete_object', 'claims' => $worker_known_delete, 'status' => 'present', 'reason' => 'version_mismatch' ),
    'inspect absent' => array( 'method' => 'worker_inspect_object', 'claims' => $worker_known_inspect, 'status' => 'absent', 'reason' => 'object_absent' ),
    'inspect mismatch' => array( 'method' => 'worker_inspect_object', 'claims' => $worker_known_inspect, 'status' => 'version_mismatch', 'reason' => 'version_mismatch' ),
);
foreach ( $worker_status_cases as $name => $case ) {
    $requester = function ( $url, $arguments ) use ( $case, $fixture, $secret ) {
            $request_claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 14 );
            return array(
                'status' => 200,
                'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( $request_claims, $case['status'], $fixture, $secret ) ) ),
            );
        };
    $result = $case['method'] === 'worker_delete_object'
        ? WorkerClient::worker_delete_object( $case['claims'], $now, $requester )
        : WorkerClient::worker_inspect_object(
            $case['claims']['upload_id'], $case['claims']['storage_identity'], $case['claims']['validation_contract_version'],
            $case['claims']['object_key'], $case['claims']['object_version'], $case['claims']['etag'], $case['claims']['bytes'],
            $case['claims']['policy_fingerprint'], $composition_fingerprint, $now, $requester
        );
    eforms_test_assert( empty( $result['ok'] ) && $result['reason'] === $case['reason'], 'Dormant candidate object status should map to safe typed failure: ' . $name );
}

$worker_response_failures = array(
    'provider transport' => array(
        'reason' => 'transport_failed',
        'response' => function () {
            return array();
        },
    ),
    'provider rejection' => array(
        'reason' => 'request_rejected',
        'response' => function () {
            return array( 'status' => 403, 'body' => '{}' );
        },
    ),
    'provider unavailable' => array(
        'reason' => 'transport_failed',
        'response' => function () {
            return array( 'status' => 503, 'body' => '{}' );
        },
    ),
    'oversized response' => array(
        'reason' => 'request_rejected',
        'response' => function () {
            return array( 'status' => 200, 'body' => str_repeat( 'x', Anchors::get( 'WORKER_RESPONSE_MAX_BYTES' ) + 1 ) );
        },
    ),
    'malformed JSON' => array(
        'reason' => 'result_invalid',
        'response' => function () {
            return array( 'status' => 200, 'body' => 'not-json' );
        },
    ),
    'malformed result' => array(
        'reason' => 'result_invalid',
        'response' => function () {
            return array( 'status' => 200, 'body' => json_encode( array( 'result' => 'not-a-token' ) ) );
        },
    ),
    'wrong request' => array(
        'reason' => 'result_invalid',
        'response' => function ( $request_claims, $fixture, $secret ) {
            return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( array_replace( $request_claims, array( 'request_id' => 'wrong_request' ) ), 'absent', $fixture, $secret ) ) ) );
        },
    ),
    'wrong object key' => array(
        'reason' => 'result_invalid',
        'response' => function ( $request_claims, $fixture, $secret ) {
            $alternate_key = ManagedArtifactKey::create( str_repeat( 'c', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ), 2, str_repeat( 'j', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ), 'image/png' );
            return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( array_replace( $request_claims, array( 'object_key' => $alternate_key ) ), 'absent', $fixture, $secret ) ) ) );
        },
    ),
    'wrong expiry' => array(
        'reason' => 'result_invalid',
        'response' => function ( $request_claims, $fixture, $secret ) {
            return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( array_replace( $request_claims, array( 'expires_at' => $request_claims['expires_at'] + 1 ) ), 'absent', $fixture, $secret ) ) ) );
        },
    ),
);
foreach ( $worker_response_failures as $name => $case ) {
    $result = WorkerClient::worker_delete_object(
        $worker_known_delete,
        $now,
        function ( $url, $arguments ) use ( $case, $fixture, $secret ) {
            $request_claims = isset( $arguments['headers'][ WorkerClient::OBJECT_HEADER ] )
                ? eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 14 )
                : array();
            return call_user_func( $case['response'], $request_claims, $fixture, $secret );
        }
    );
    eforms_test_assert(
        empty( $result['ok'] ) && $result['reason'] === $case['reason'],
        'Dormant candidate object response failure should map exactly to ' . $case['reason'] . ': ' . $name
    );
}

$mismatch = WorkerClient::worker_delete_object(
    $worker_known_delete,
    $now,
    function ( $url, $arguments ) use ( $worker_known_delete, $fixture, $secret ) {
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 14 );
        eforms_test_worker_client_assert_worker_object_request( $claims, $worker_known_delete, 'delete' );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( $claims, 'version_mismatch', $fixture, $secret ) ) ) );
    },
    'aggregate_gc'
);
eforms_test_assert( empty( $mismatch['ok'] ) && $mismatch['reason'] === 'version_mismatch', 'A changed remote version must fail closed without confirming absence.' );
$mismatch_event = end( Logging::$events );
eforms_test_worker_client_assert_operation_event(
    $mismatch_event,
    array(
        'severity' => 'warning',
        'operation_category' => 'cleanup',
        'outcome_class' => 'authoritative_rejection',
        'retry' => 'required',
        'cleanup_phase' => 'aggregate_gc',
    ),
    'Failed cleanup should emit its closed outcome and caller-owned phase without provider details.'
);

$inspect = WorkerClient::worker_inspect_object(
    $worker_known_inspect['upload_id'],
    $worker_known_inspect['storage_identity'],
    $worker_known_inspect['validation_contract_version'],
    $worker_known_inspect['object_key'],
    $worker_known_inspect['object_version'],
    $worker_known_inspect['etag'],
    $worker_known_inspect['bytes'],
    $worker_known_inspect['policy_fingerprint'],
    $composition_fingerprint,
    $now,
    function ( $url, $arguments ) use ( $worker_known_inspect, $fixture, $secret ) {
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::OBJECT_HEADER ], WorkerProtocol::OBJECT_REQUEST_DOMAIN, 14 );
        eforms_test_worker_client_assert_worker_object_request( $claims, $worker_known_inspect, 'inspect' );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_worker_object_result( $claims, 'present', $fixture, $secret ) ) ) );
    }
);
eforms_test_assert( ! empty( $inspect['ok'] ) && ! empty( $inspect['present'] ), 'A signed exact-version presence result should support restore verification without mutation.' );

$wrong_deployment_called = false;
$wrong_deployment = WorkerClient::worker_delete_object(
    array_replace( $worker_known_delete, array(
        'storage_identity' => str_repeat( 'f', 64 ),
        'expected_composition_fingerprint' => str_repeat( 'f', 64 ),
    ) ),
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

$health_claims = null;
$health = WorkerClient::health(
    $now,
    function ( $url, $arguments ) use ( &$health_claims, $fixture, $secret ) {
        eforms_test_assert( $url === 'https://media.example.test/v1/health', 'Health should use the signed data-plane endpoint.' );
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::HEALTH_HEADER ], WorkerProtocol::HEALTH_REQUEST_DOMAIN, 4 );
        $health_claims = $claims;
        eforms_test_assert(
            $claims['storage_identity'] === WorkerClient::composition_fingerprint()
                && $claims['validation_contract_version'] === WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION,
            'Health requests should bind the dormant candidate storage identity and requested validation contract.'
        );
        $result = eforms_test_worker_client_health_result( $claims, $fixture );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::HEALTH_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    },
    'upload_grant_readiness'
);
eforms_test_assert( ! empty( $health['ok'] ) && ! empty( $health['worker_ready'] ) && $health['outcome'] === 'ready', 'Signed storage and inspection readiness should pass while exposing dormant candidate readiness.' );
$health_event = end( Logging::$events );
eforms_test_worker_client_assert_operation_event(
    $health_event,
    array(
        'operation_category' => 'transfer',
        'cleanup_phase' => 'upload_grant_readiness',
    ),
    'Upload-grant readiness should emit the closed Worker transfer category.'
);

$second_health_claims = null;
$second_health = WorkerClient::health(
    $now,
    function ( $url, $arguments ) use ( &$second_health_claims, $fixture, $secret ) {
        $second_health_claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::HEALTH_HEADER ], WorkerProtocol::HEALTH_REQUEST_DOMAIN, 4 );
        $result = eforms_test_worker_client_health_result( $second_health_claims, $fixture );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::HEALTH_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    },
    'upload_grant_readiness'
);
eforms_test_assert(
    ! empty( $second_health['ok'] )
        && is_array( $health_claims )
        && is_array( $second_health_claims )
        && $health_claims['expires_at'] === $second_health_claims['expires_at']
        && $health_claims['request_id'] !== $second_health_claims['request_id'],
    'Same-second Worker health probes should use distinct limiter identities.'
);

$response_clock_samples = array(
    $now,
    $now + Anchors::get( 'WORKER_OPERATION_GRANT_TTL_SECONDS' ) + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) + 1,
);
$expired_during_transport = WorkerClient::health(
    function () use ( &$response_clock_samples ) {
        return array_shift( $response_clock_samples );
    },
    function ( $url, $arguments ) use ( $fixture, $secret ) {
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::HEALTH_HEADER ], WorkerProtocol::HEALTH_REQUEST_DOMAIN, 4 );
        $result = eforms_test_worker_client_health_result( $claims, $fixture );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::HEALTH_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    }
);
eforms_test_assert( empty( $expired_during_transport['ok'] ) && $expired_during_transport['reason'] === 'result_invalid', 'Worker responses must be verified at the post-transport clock boundary.' );

$unhealthy = WorkerClient::health(
    $now,
    function ( $url, $arguments ) use ( $fixture, $secret ) {
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::HEALTH_HEADER ], WorkerProtocol::HEALTH_REQUEST_DOMAIN, 4 );
        $result = eforms_test_worker_client_health_result( $claims, $fixture, array( 'inspection_ready' => 0 ) );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::HEALTH_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    },
    'runtime_readiness'
);
eforms_test_assert( empty( $unhealthy['ok'] ) && $unhealthy['outcome'] === 'dependency_unavailable', 'Signed degraded health should retain its explicit dependency outcome.' );
$unhealthy_event = end( Logging::$events );
eforms_test_worker_client_assert_operation_event(
    $unhealthy_event,
    array(
        'operation_category' => 'validation',
        'outcome_class' => 'dependency_unavailable',
        'retry' => 'required',
        'cleanup_phase' => 'runtime_readiness',
    ),
    'Signed degraded health should not collapse into a generic operation-failure event.'
);

$queue_unready = WorkerClient::health(
    $now,
    function ( $url, $arguments ) use ( $fixture, $secret ) {
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::HEALTH_HEADER ], WorkerProtocol::HEALTH_REQUEST_DOMAIN, 4 );
        $result = eforms_test_worker_client_health_result( $claims, $fixture, array( 'queue_producer_ready' => 0 ) );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::HEALTH_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    }
);
eforms_test_assert( empty( $queue_unready['ok'] ) && empty( $queue_unready['worker_ready'] ) && $queue_unready['outcome'] === 'dependency_unavailable', 'Queue readiness must participate in the single Worker health success authority.' );

$wrong_binding = WorkerClient::health(
    $now,
    function ( $url, $arguments ) use ( $fixture, $secret ) {
        $claims = eforms_test_worker_client_claims( $arguments['headers'][ WorkerClient::HEALTH_HEADER ], WorkerProtocol::HEALTH_REQUEST_DOMAIN, 4 );
        $result = eforms_test_worker_client_health_result(
            $claims,
            $fixture,
            array(
                'storage_identity' => str_repeat( '9', 64 ),
                'validation_contract_version' => 'wrong-contract',
            )
        );
        return array( 'status' => 200, 'body' => json_encode( array( 'result' => eforms_test_worker_client_signed_result( WorkerProtocol::HEALTH_RESULT_DOMAIN, $result, $fixture, $secret ) ) ) );
    }
);
eforms_test_assert( empty( $wrong_binding['ok'] ) && $wrong_binding['reason'] === 'result_invalid', 'Health result verification must correlate storage identity and validation contract with the request.' );

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
    if ( $domain === WorkerProtocol::OBJECT_REQUEST_DOMAIN && $claim_count === 14 ) {
        eforms_test_assert( $parts[1] === WorkerProtocol::VERSION, 'Worker object requests should use the v3 envelope version.' );
        return array(
            'request_id' => $parts[4],
            'batch_id' => $parts[5],
            'intent_id' => $parts[6],
            'upload_id' => $parts[7],
            'ordinal' => (int) $parts[8],
            'storage_identity' => $parts[9],
            'validation_contract_version' => $parts[10],
            'object_key' => $parts[11],
            'object_version' => $parts[12],
            'etag' => $parts[13],
            'bytes' => (int) $parts[14],
            'policy_fingerprint' => $parts[15],
            'action' => $parts[16],
            'expires_at' => (int) $parts[17],
        );
    }
    if ( $domain === WorkerProtocol::OBJECT_REQUEST_DOMAIN ) {
        return array(
            'request_id' => $parts[4],
            'object_key' => $parts[5],
            'object_version' => $parts[6],
            'action' => $parts[7],
            'expires_at' => (int) $parts[8],
        );
    }
    if ( $domain === WorkerProtocol::WORKER_GALLERY_STATUS_REQUEST_DOMAIN ) {
        eforms_test_assert( $parts[1] === WorkerProtocol::VERSION, 'Worker gallery-status requests should use the v3 envelope version.' );
        return array(
            'request_id' => $parts[4],
            'submission_id' => $parts[5],
            'storage_identity' => $parts[6],
            'items_sha256' => $parts[7],
            'item_count' => (int) $parts[8],
            'expires_at' => (int) $parts[9],
        );
    }
    if ( $domain === WorkerProtocol::REVIEW_GRANT_DOMAIN && $claim_count === 13 ) {
        eforms_test_assert( $parts[1] === WorkerProtocol::VERSION, 'Worker review grants should use the v3 envelope version.' );
        return array(
            'submission_id' => $parts[4],
            'upload_id' => $parts[5],
            'storage_identity' => $parts[6],
            'validation_contract_version' => $parts[7],
            'object_key' => $parts[8],
            'object_version' => $parts[9],
            'etag' => $parts[10],
            'bytes' => (int) $parts[11],
            'policy_fingerprint' => $parts[12],
            'action' => $parts[13],
            'recipe_version' => $parts[14],
            'expires_at' => (int) $parts[15],
        );
    }
    if ( $domain === WorkerProtocol::HEALTH_REQUEST_DOMAIN ) {
        return array(
            'request_id' => $parts[4],
            'storage_identity' => $parts[5],
            'validation_contract_version' => $parts[6],
            'expires_at' => (int) $parts[7],
        );
    }
    return array( 'request_id' => $parts[4], 'expires_at' => (int) $parts[5] );
}

function eforms_test_worker_client_assert_operation_event( $event, $expected, $label ) {
    eforms_test_assert( is_array( $event ) && $event['code'] === 'EFORMS_WORKER_OPERATION', $label . ' should emit the Worker operation event code.' );
    if ( isset( $expected['severity'] ) ) {
        eforms_test_assert( $event['severity'] === $expected['severity'], $label . ' should use the expected severity.' );
    }
    eforms_test_assert(
        array_keys( $event['meta'] ) === array( 'operation', 'operation_category', 'outcome_class', 'latency_bucket', 'retry', 'cleanup_phase' ),
        $label . ' should keep the closed Worker operation meta schema.'
    );
    eforms_test_assert(
        in_array( $event['meta']['latency_bucket'], array( 'fast', 'normal', 'slow', 'very_slow' ), true ),
        $label . ' should classify latency into the closed bucket set.'
    );
    foreach ( $expected as $key => $value ) {
        if ( $key === 'severity' ) {
            continue;
        }
        eforms_test_assert( $event['meta'][ $key ] === $value, $label . ' should set meta.' . $key . ' correctly.' );
    }
}

function eforms_test_worker_client_health_result( $claims, $fixture, $overrides = array() ) {
    return array_replace(
        array(
            'request_id' => $claims['request_id'],
            'storage_ready' => 1,
            'inspection_ready' => 1,
            'queue_producer_ready' => 1,
            'limiter_ready' => 1,
            'keys_ready' => 1,
            'storage_identity_ready' => 1,
            'validation_contract_ready' => 1,
            'storage_identity' => $claims['storage_identity'],
            'validation_contract_version' => $claims['validation_contract_version'],
            'checked_at' => $fixture['verification_now'],
            'expires_at' => $claims['expires_at'],
        ),
        $overrides
    );
}

function eforms_test_worker_client_assert_worker_object_transport( $url, $arguments ) {
    eforms_test_assert( $url === 'https://media.example.test/v1/object', 'Worker object operations should POST to the dormant Worker object endpoint.' );
    eforms_test_assert( array_keys( $arguments['headers'] ) === array( WorkerClient::OBJECT_HEADER ), 'Worker object operations should send only the object authority header.' );
    eforms_test_assert( ! isset( $arguments['headers']['Authorization'], $arguments['headers']['Cookie'], $arguments['headers']['X-EForms-Worker-Grant'], $arguments['headers']['X-EForms-Worker-Health'] ), 'Worker object operations must not send WordPress authority or other Worker headers.' );
    eforms_test_assert( $arguments['body'] === '', 'Worker object operations should not send a request body.' );
    eforms_test_assert( $arguments['timeout'] === Anchors::get( 'WORKER_SERVER_REQUEST_TIMEOUT_SECONDS' ), 'Worker object operations should use the bounded Worker timeout.' );
    eforms_test_assert( $arguments['limit_response_size'] === Anchors::get( 'WORKER_RESPONSE_MAX_BYTES' ), 'Worker object operations should use the existing object response cap.' );
    eforms_test_assert( $arguments['redirection'] === 0 && ! empty( $arguments['reject_unsafe_urls'] ) && ! empty( $arguments['sslverify'] ), 'Worker object operations should disable redirects and require safe TLS URLs.' );
}

function eforms_test_worker_client_assert_worker_object_request( $claims, $expected, $action ) {
    $parts = ManagedArtifactKey::parse( $expected['object_key'] );
    eforms_test_assert( is_array( $parts ), 'Worker object fixture key should parse.' );
    eforms_test_assert(
        $claims['batch_id'] === $parts['namespace']
            && $claims['intent_id'] === $parts['intent_id']
            && $claims['ordinal'] === $parts['ordinal']
            && $claims['upload_id'] === $expected['upload_id']
            && $claims['storage_identity'] === $expected['storage_identity']
            && $claims['validation_contract_version'] === $expected['validation_contract_version']
            && $claims['object_key'] === $expected['object_key']
            && $claims['object_version'] === $expected['object_version']
            && $claims['etag'] === $expected['etag']
            && $claims['bytes'] === $expected['bytes']
            && $claims['policy_fingerprint'] === $expected['policy_fingerprint']
            && $claims['action'] === $action,
        'Worker object requests should bind only manifest-owned identity and exact artifact facts.'
    );
}

function eforms_test_worker_client_worker_object_result( $request_claims, $status, $fixture, $secret, $version = WorkerProtocol::VERSION, $environment = null ) {
    $claims = array(
        'request_id' => $request_claims['request_id'],
        'object_key' => $request_claims['object_key'],
        'object_version' => $request_claims['object_version'],
        'status' => $status,
        'expires_at' => $request_claims['expires_at'],
    );
    return eforms_test_worker_client_signed_result( WorkerProtocol::OBJECT_RESULT_DOMAIN, $claims, $fixture, $secret, $version, $environment );
}

function eforms_test_worker_client_assert_gallery_request_transport( $url, $arguments ) {
    eforms_test_assert( $url === 'https://media.example.test/v1/gallery-status', 'Worker gallery status should POST to the dormant Worker gallery-status endpoint.' );
    eforms_test_assert( $arguments['headers'] === array( 'Content-Type' => 'application/json' ), 'Worker gallery status should send only the exact JSON content type header.' );
    eforms_test_assert( ! isset( $arguments['headers']['Authorization'], $arguments['headers']['Cookie'], $arguments['headers']['X-EForms-Worker-Grant'], $arguments['headers']['X-EForms-Worker-Health'], $arguments['headers']['X-EForms-Worker-Object'] ), 'Worker gallery status must not send WordPress or Worker authority headers.' );
    eforms_test_assert( $arguments['timeout'] === Anchors::get( 'WORKER_SERVER_REQUEST_TIMEOUT_SECONDS' ), 'Worker gallery status should use the bounded Worker timeout.' );
    eforms_test_assert( $arguments['limit_response_size'] === Anchors::get( 'WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES' ), 'Worker gallery status should use the dedicated response cap.' );
    eforms_test_assert( $arguments['redirection'] === 0 && ! empty( $arguments['reject_unsafe_urls'] ) && ! empty( $arguments['sslverify'] ), 'Worker gallery status should disable redirects and require safe TLS URLs.' );
    eforms_test_assert( is_string( $arguments['body'] ) && strlen( $arguments['body'] ) <= Anchors::get( 'WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES' ), 'Worker gallery status should send bounded canonical request bytes.' );
}

function eforms_test_worker_client_gallery_request_claims( $arguments, $items, $fixture, $expected_storage_identity = null ) {
    $decoded = json_decode( $arguments['body'], true );
    eforms_test_assert( is_array( $decoded ) && array_keys( $decoded ) === array( 'items', 'request' ), 'Worker gallery status should send the exact canonical request wrapper.' );
    $claims = eforms_test_worker_client_claims( $decoded['request'], WorkerProtocol::WORKER_GALLERY_STATUS_REQUEST_DOMAIN, 6 );
    $expected_storage_identity = is_string( $expected_storage_identity )
        ? $expected_storage_identity
        : $fixture['worker_claims']['gallery_status_request']['storage_identity'];
    eforms_test_assert(
        $claims['submission_id'] === $fixture['worker_claims']['gallery_status_request']['submission_id']
            && $claims['storage_identity'] === $expected_storage_identity
            && $claims['item_count'] === count( $items )
            && $claims['items_sha256'] === WorkerProtocol::worker_gallery_items_sha256( $items )
            && hash_equals( WorkerProtocol::worker_gallery_status_request_body_bytes( $decoded['request'], $items ), $arguments['body'] ),
        'Worker gallery status should bind submission, storage identity, count, hash, and exact canonical body bytes.'
    );
    return $claims;
}

function eforms_test_worker_client_gallery_result_token( $request_claims, $statuses, $fixture, $secret, $overrides = array(), $domain = WorkerProtocol::WORKER_GALLERY_STATUS_RESULT_DOMAIN, $version = WorkerProtocol::VERSION, $environment = null ) {
    $claims = array_replace(
        array(
            'request_id' => $request_claims['request_id'],
            'submission_id' => $request_claims['submission_id'],
            'items_sha256' => $request_claims['items_sha256'],
            'statuses_sha256' => WorkerProtocol::worker_gallery_statuses_sha256( $statuses ),
            'item_count' => count( $statuses ),
            'checked_at' => $fixture['verification_now'],
            'expires_at' => $request_claims['expires_at'],
        ),
        $overrides
    );
    return eforms_test_worker_client_signed_result( $domain, $claims, $fixture, $secret, $version, $environment );
}

function eforms_test_worker_client_gallery_response( $request_claims, $statuses, $items, $fixture, $secret, $overrides = array(), $domain = WorkerProtocol::WORKER_GALLERY_STATUS_RESULT_DOMAIN, $version = WorkerProtocol::VERSION, $environment = null ) {
    $token = eforms_test_worker_client_gallery_result_token( $request_claims, $statuses, $fixture, $secret, $overrides, $domain, $version, $environment );
    return WorkerProtocol::worker_gallery_status_result_body_bytes( $token, $statuses, $items );
}

function eforms_test_worker_client_gallery_items( $count ) {
    $items = array();
    $batch_id = str_repeat( 'b', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) );
    for ( $i = 0; $i < $count; $i++ ) {
        $intent_id = substr( str_repeat( 'i', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ) . $i, -Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) );
        $items[] = array(
            'upload_id' => 'upload_' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ),
            'ordinal' => $i,
            'validation_contract_version' => 'managed-image-v1',
            'object_key' => ManagedArtifactKey::create( $batch_id, $i, $intent_id, 'image/png' ),
            'object_version' => 'version-' . $i,
            'etag' => 'etag-' . $i,
            'bytes' => 1234 + $i,
            'policy_fingerprint' => str_repeat( 'd', 64 ),
            'validation_until' => 2000001800 + $i,
        );
    }
    return $items;
}

function eforms_test_worker_client_gallery_statuses( $items ) {
    $statuses = array();
    foreach ( $items as $item ) {
        $statuses[] = array(
            'upload_id' => $item['upload_id'],
            'status' => 'accepted',
            'mime' => 'image/png',
            'width' => 32,
            'height' => 24,
        );
    }
    return $statuses;
}

function eforms_test_worker_client_signed_result( $domain, $claims, $fixture, $secret, $version = null, $environment = null ) {
    $parts = array_merge(
        array(
            $domain,
            $version === null ? $fixture['version'] : $version,
            $fixture['active_key_id'],
            $environment === null ? $fixture['environment'] : $environment,
        ),
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
