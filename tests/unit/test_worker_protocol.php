<?php
/**
 * Cross-language fixtures for the WordPress side of the Worker trust boundary.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/WorkerProtocol.php';

$fixture = json_decode( file_get_contents( __DIR__ . '/../fixtures/worker_protocol.json' ), true );
eforms_test_assert( is_array( $fixture ), 'Worker protocol fixture should decode.' );

$secret = WorkerProtocol::decode_integration_key( $fixture['active_key_b64'] );
$keys = array( $fixture['active_key_id'] => $secret );
eforms_test_assert( strlen( $secret ) === Anchors::get( 'WORKER_INTEGRATION_KEY_BYTES' ), 'Worker integration fixture should decode to one exact integration key.' );
eforms_test_assert( WorkerProtocol::decode_integration_key( $fixture['active_key_b64'] . '=' ) === '', 'Padded integration keys should fail canonical decoding.' );

$upload_grant = WorkerProtocol::sign_upload_grant( $fixture['claims']['upload_grant'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$review_grant = WorkerProtocol::sign_review_grant( $fixture['claims']['review_grant'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$health_request = WorkerProtocol::sign_health_request( $fixture['claims']['health_request'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$object_request = WorkerProtocol::sign_object_request( $fixture['claims']['object_request'], $fixture['active_key_id'], $secret, $fixture['environment'] );
eforms_test_assert( eforms_worker_vector_matches( $upload_grant, $fixture['vectors']['upload_grant'] ), 'PHP should produce the canonical Worker upload grant fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $review_grant, $fixture['vectors']['review_grant'] ), 'PHP should produce the canonical Worker review grant fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $health_request, $fixture['vectors']['health_request'] ), 'PHP should produce the canonical Worker health request fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $object_request, $fixture['vectors']['object_request'] ), 'PHP should produce the canonical Worker object request fixture.' );

$receipt_token = eforms_worker_fixture_token( WorkerProtocol::UPLOAD_RECEIPT_DOMAIN, $fixture, $fixture['claims']['upload_receipt'], $fixture['vectors']['upload_receipt']['signature_b64'] );
$health_token = eforms_worker_fixture_token( WorkerProtocol::HEALTH_RESULT_DOMAIN, $fixture, $fixture['claims']['health_result'], $fixture['vectors']['health_result']['signature_b64'] );
$object_token = eforms_worker_fixture_token( WorkerProtocol::OBJECT_RESULT_DOMAIN, $fixture, $fixture['claims']['object_result'], $fixture['vectors']['object_result']['signature_b64'] );
$receipt = WorkerProtocol::verify_upload_receipt( $receipt_token, $keys, $fixture['environment'], $fixture['verification_now'] );
eforms_test_assert( ! empty( $receipt['ok'] ) && $receipt['claims'] === $fixture['claims']['upload_receipt'], 'PHP should verify and type the canonical Worker upload receipt fixture.' );
$health = WorkerProtocol::verify_health_result( $health_token, $keys, $fixture['environment'], $fixture['verification_now'] );
$expected_health = $fixture['claims']['health_result'];
$expected_health['storage_ready'] = true;
$expected_health['inspection_ready'] = true;
eforms_test_assert( ! empty( $health['ok'] ) && $health['claims'] === $expected_health, 'PHP should verify and type the canonical Worker health result fixture.' );
$object = WorkerProtocol::verify_object_result( $object_token, $keys, $fixture['environment'], $fixture['verification_now'] );
eforms_test_assert( ! empty( $object['ok'] ) && $object['claims'] === $fixture['claims']['object_result'], 'PHP should verify and type the canonical Worker object result fixture.' );

$receipt_parts = array_merge(
    array( WorkerProtocol::UPLOAD_RECEIPT_DOMAIN, WorkerProtocol::VERSION, $fixture['active_key_id'], $fixture['environment'] ),
    array_map( 'strval', array_values( $fixture['claims']['upload_receipt'] ) )
);
$invalid_receipts = array(
    'unknown_version' => array_replace( $receipt_parts, array( 1 => '2' ) ),
    'wrong_domain' => array_replace( $receipt_parts, array( 0 => WorkerProtocol::UPLOAD_GRANT_DOMAIN ) ),
    'unknown_key' => array_replace( $receipt_parts, array( 2 => 'unknown-key' ) ),
    'reordered_fields' => array_merge( array_slice( $receipt_parts, 0, -2 ), array( $receipt_parts[ count( $receipt_parts ) - 1 ], $receipt_parts[ count( $receipt_parts ) - 2 ] ) ),
    'missing_field' => array_slice( $receipt_parts, 0, -1 ),
);
foreach ( $invalid_receipts as $name => $parts ) {
    $token = eforms_worker_signed_parts( $parts, $secret );
    $invalid = WorkerProtocol::verify_upload_receipt( $token, $keys, $fixture['environment'], $fixture['verification_now'] );
    eforms_test_assert( empty( $invalid['ok'] ), 'PHP should reject the signed invalid receipt vector: ' . $name );
}
eforms_test_assert( empty( WorkerProtocol::verify_upload_receipt( $receipt_token, $keys, 'wrong-environment', $fixture['verification_now'] )['ok'] ), 'A cross-environment receipt replay should fail.' );
eforms_test_assert( ! empty( WorkerProtocol::verify_upload_receipt( $receipt_token, $keys, $fixture['environment'], $fixture['claims']['upload_receipt']['expires_at'] + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) )['ok'] ), 'The one explicit clock-skew boundary should remain verifiable.' );
eforms_test_assert( empty( WorkerProtocol::verify_upload_receipt( $receipt_token, $keys, $fixture['environment'], $fixture['claims']['upload_receipt']['expires_at'] + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) + 1 )['ok'] ), 'A receipt beyond the explicit clock-skew allowance should fail.' );
eforms_test_assert( empty( WorkerProtocol::verify_upload_receipt( $receipt_token . '=', $keys, $fixture['environment'], $fixture['verification_now'] )['ok'] ), 'A non-canonical receipt encoding should fail.' );

$configuration = WorkerProtocol::key_configuration( $fixture['environment'], $fixture['active_key_id'], $fixture['active_key_b64'] );
eforms_test_assert( is_array( $configuration ) && $configuration['keys'][ $fixture['active_key_id'] ] === $secret, 'WordPress deployment wiring should build one validated active verification keyring.' );
eforms_test_assert( WorkerProtocol::key_configuration( $fixture['environment'], $fixture['active_key_id'], $fixture['active_key_b64'], $fixture['active_key_id'], $fixture['active_key_b64'] ) === null, 'Active and secondary key identifiers must not alias.' );

$extra_claim = $fixture['claims']['upload_grant'];
$extra_claim['untrusted'] = 'value';
eforms_test_assert( WorkerProtocol::sign_upload_grant( $extra_claim, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'WordPress should not sign extra grant fields.' );
$missing_claim = $fixture['claims']['upload_grant'];
unset( $missing_claim['object_key'] );
eforms_test_assert( WorkerProtocol::sign_upload_grant( $missing_claim, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'WordPress should not sign an incomplete grant.' );
eforms_test_assert( WorkerProtocol::sign_upload_grant( $fixture['claims']['upload_grant'], 'unknown key', $secret, $fixture['environment'] ) === '', 'Invalid key identifiers should not produce a grant.' );

echo "Worker protocol PHP tests passed.\n";

function eforms_worker_vector_matches( $token, $vector ) {
    $segments = explode( '.', $token );
    if ( count( $segments ) !== 2 ) {
        return false;
    }
    $payload = eforms_worker_base64url_decode( $segments[0] );
    return is_string( $payload )
        && hash( 'sha256', $payload ) === $vector['payload_sha256']
        && $segments[1] === $vector['signature_b64'];
}

function eforms_worker_fixture_token( $domain, $fixture, $claims, $signature ) {
    $parts = array_merge(
        array( $domain, $fixture['version'], $fixture['active_key_id'], $fixture['environment'] ),
        array_map( 'strval', array_values( $claims ) )
    );
    return eforms_worker_base64url_encode( eforms_worker_encode_parts( $parts ) ) . '.' . $signature;
}

function eforms_worker_signed_parts( $parts, $secret ) {
    $payload = eforms_worker_encode_parts( $parts );
    return eforms_worker_base64url_encode( $payload ) . '.' . eforms_worker_base64url_encode( hash_hmac( 'sha256', $payload, $secret, true ) );
}

function eforms_worker_encode_parts( $parts ) {
    $encoded = '';
    foreach ( $parts as $part ) {
        $encoded .= pack( 'N', strlen( $part ) ) . $part;
    }
    return $encoded;
}

function eforms_worker_base64url_encode( $bytes ) {
    return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
}

function eforms_worker_base64url_decode( $encoded ) {
    $padding = ( 4 - strlen( $encoded ) % 4 ) % 4;
    return base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', $padding ), true );
}
