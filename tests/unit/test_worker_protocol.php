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
eforms_test_assert(
    WorkerProtocol::valid_validation_contract_version( WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION )
        && ! WorkerProtocol::valid_validation_contract_version( 'bad version!' ),
    'Worker protocol should expose the validation-contract version grammar used by CLI retirement preflight.'
);

$health_request = WorkerProtocol::sign_health_request( $fixture['claims']['health_request'], $fixture['active_key_id'], $secret, $fixture['environment'] );
eforms_test_assert( eforms_worker_vector_matches( $health_request, $fixture['vectors']['health_request'] ), 'PHP should produce the canonical Worker health request fixture.' );

$health_token = eforms_worker_fixture_token( WorkerProtocol::HEALTH_RESULT_DOMAIN, $fixture, $fixture['claims']['health_result'], $fixture['vectors']['health_result']['signature_b64'] );
$health = WorkerProtocol::verify_health_result( $health_token, $keys, $fixture['environment'], $fixture['verification_now'] );
$expected_health = $fixture['claims']['health_result'];
foreach ( array( 'storage_ready', 'inspection_ready', 'queue_producer_ready', 'limiter_ready', 'keys_ready', 'storage_identity_ready', 'validation_contract_ready' ) as $field ) {
    $expected_health[ $field ] = true;
}
eforms_test_assert( ! empty( $health['ok'] ) && $health['claims'] === $expected_health, 'PHP should verify and type the canonical Worker health result fixture.' );

$worker_upload_grant = WorkerProtocol::sign_worker_upload_grant( $fixture['worker_claims']['upload_grant'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$worker_stored_receipt = WorkerProtocol::sign_worker_stored_receipt( $fixture['worker_claims']['stored_receipt'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$worker_gallery_request = WorkerProtocol::sign_worker_gallery_status_request( $fixture['worker_claims']['gallery_status_request'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$worker_gallery_result = WorkerProtocol::sign_worker_gallery_status_result( $fixture['worker_claims']['gallery_status_result'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$worker_review_grant = WorkerProtocol::sign_worker_review_grant( $fixture['worker_claims']['review_grant'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$worker_object_known_delete = WorkerProtocol::sign_worker_object_request( $fixture['worker_claims']['object_request_known_delete'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$worker_object_unknown_delete = WorkerProtocol::sign_worker_object_request( $fixture['worker_claims']['object_request_unknown_delete'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$worker_object_known_inspect = WorkerProtocol::sign_worker_object_request( $fixture['worker_claims']['object_request_known_inspect'], $fixture['active_key_id'], $secret, $fixture['environment'] );
$worker_object_result = WorkerProtocol::sign_worker_object_result( $fixture['worker_claims']['object_result'], $fixture['active_key_id'], $secret, $fixture['environment'] );
eforms_test_assert( eforms_worker_vector_matches( $worker_upload_grant, $fixture['worker_vectors']['upload_grant'] ), 'PHP should produce the v3 Worker upload grant fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $worker_stored_receipt, $fixture['worker_vectors']['stored_receipt'] ), 'PHP should produce the v3 Worker Stored receipt fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $worker_gallery_request, $fixture['worker_vectors']['gallery_status_request'] ), 'PHP should produce the v3 gallery-status request fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $worker_gallery_result, $fixture['worker_vectors']['gallery_status_result'] ), 'PHP should produce the v3 gallery-status result fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $worker_review_grant, $fixture['worker_vectors']['review_grant'] ), 'PHP should produce the v3 exact-result review grant fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $worker_object_known_delete, $fixture['worker_vectors']['object_request_known_delete'] ), 'PHP should produce the v3 known-object delete request fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $worker_object_unknown_delete, $fixture['worker_vectors']['object_request_unknown_delete'] ), 'PHP should produce the v3 unknown-object delete request fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $worker_object_known_inspect, $fixture['worker_vectors']['object_request_known_inspect'] ), 'PHP should produce the v3 known-object inspect request fixture.' );
eforms_test_assert( eforms_worker_vector_matches( $worker_object_result, $fixture['worker_vectors']['object_result'] ), 'PHP should produce the v3 object result fixture.' );
$worker_grant_verified = WorkerProtocol::verify_worker_upload_grant( $worker_upload_grant, $keys, $fixture['environment'], $fixture['verification_now'] );
eforms_test_assert( ! empty( $worker_grant_verified['ok'] ) && $worker_grant_verified['claims'] === $fixture['worker_claims']['upload_grant'], 'PHP should verify and type the v3 Worker upload grant.' );
$worker_receipt_token = eforms_worker_fixture_token( WorkerProtocol::WORKER_STORED_RECEIPT_DOMAIN, $fixture, $fixture['worker_claims']['stored_receipt'], $fixture['worker_vectors']['stored_receipt']['signature_b64'] );
$worker_receipt_verified = WorkerProtocol::verify_worker_stored_receipt( $worker_receipt_token, $keys, $fixture['environment'], $fixture['verification_now'] );
eforms_test_assert( ! empty( $worker_receipt_verified['ok'] ) && $worker_receipt_verified['claims'] === $fixture['worker_claims']['stored_receipt'], 'PHP should verify and type the v3 Worker Stored receipt.' );
eforms_test_assert(
    ! isset( $worker_receipt_verified['claims']['mime'], $worker_receipt_verified['claims']['width'], $worker_receipt_verified['claims']['height'], $worker_receipt_verified['claims']['outcome'] ),
    'v3 Worker Stored receipts should contain storage facts only.'
);
eforms_test_assert( WorkerProtocol::verify_worker_gallery_status_request( $worker_gallery_request, $keys, $fixture['environment'], $fixture['verification_now'] )['claims'] === $fixture['worker_claims']['gallery_status_request'], 'PHP should verify and type the v3 gallery-status request.' );
eforms_test_assert( WorkerProtocol::verify_worker_gallery_status_result( $worker_gallery_result, $keys, $fixture['environment'], $fixture['verification_now'] )['claims'] === $fixture['worker_claims']['gallery_status_result'], 'PHP should verify and type the v3 gallery-status result.' );
eforms_test_assert( ! empty( WorkerProtocol::verify_worker_gallery_status_request( $worker_gallery_request, $keys, $fixture['environment'], $fixture['worker_claims']['gallery_status_request']['expires_at'] - 1 )['ok'] ), 'Gallery-status requests should remain valid before the closed-at timestamp.' );
eforms_test_assert( empty( WorkerProtocol::verify_worker_gallery_status_request( $worker_gallery_request, $keys, $fixture['environment'], $fixture['worker_claims']['gallery_status_request']['expires_at'] )['ok'] ), 'Gallery-status requests should close exactly at expires_at.' );
eforms_test_assert( ! empty( WorkerProtocol::verify_worker_gallery_status_result( $worker_gallery_result, $keys, $fixture['environment'], $fixture['worker_claims']['gallery_status_result']['expires_at'] - 1 )['ok'] ), 'Gallery-status results should remain valid before the closed-at timestamp.' );
eforms_test_assert( empty( WorkerProtocol::verify_worker_gallery_status_result( $worker_gallery_result, $keys, $fixture['environment'], $fixture['worker_claims']['gallery_status_result']['expires_at'] )['ok'] ), 'Gallery-status results should close exactly at expires_at.' );
eforms_test_assert( WorkerProtocol::verify_worker_review_grant( $worker_review_grant, $keys, $fixture['environment'], $fixture['verification_now'] )['claims'] === $fixture['worker_claims']['review_grant'], 'PHP should verify and type the v3 exact-result review grant.' );
eforms_test_assert( ! empty( WorkerProtocol::verify_worker_review_grant( $worker_review_grant, $keys, $fixture['environment'], $fixture['worker_claims']['review_grant']['expires_at'] - 1 )['ok'] ), 'Worker exact-result review grants should remain valid before the closed-at timestamp.' );
eforms_test_assert( empty( WorkerProtocol::verify_worker_review_grant( $worker_review_grant, $keys, $fixture['environment'], $fixture['worker_claims']['review_grant']['expires_at'] )['ok'] ), 'Worker exact-result review grants should close exactly at expires_at.' );
eforms_test_assert( WorkerProtocol::verify_worker_object_request( $worker_object_known_delete, $keys, $fixture['environment'], $fixture['verification_now'] )['claims'] === $fixture['worker_claims']['object_request_known_delete'], 'PHP should verify and type the v3 known-object delete request.' );
eforms_test_assert( WorkerProtocol::verify_worker_object_request( $worker_object_unknown_delete, $keys, $fixture['environment'], $fixture['verification_now'] )['claims'] === $fixture['worker_claims']['object_request_unknown_delete'], 'PHP should verify and type the v3 unknown-object delete request.' );
eforms_test_assert( WorkerProtocol::verify_worker_object_request( $worker_object_known_inspect, $keys, $fixture['environment'], $fixture['verification_now'] )['claims'] === $fixture['worker_claims']['object_request_known_inspect'], 'PHP should verify and type the v3 known-object inspect request.' );
eforms_test_assert( WorkerProtocol::verify_worker_object_result( $worker_object_result, $keys, $fixture['environment'], $fixture['verification_now'] )['claims'] === $fixture['worker_claims']['object_result'], 'PHP should verify and type the v3 object result.' );
eforms_test_assert( ! empty( WorkerProtocol::verify_worker_object_request( $worker_object_known_delete, $keys, $fixture['environment'], $fixture['worker_claims']['object_request_known_delete']['expires_at'] - 1 )['ok'] ), 'Worker object requests should remain valid before the closed-at timestamp.' );
eforms_test_assert( empty( WorkerProtocol::verify_worker_object_request( $worker_object_known_delete, $keys, $fixture['environment'], $fixture['worker_claims']['object_request_known_delete']['expires_at'] )['ok'] ), 'Worker object requests should close exactly at expires_at.' );
eforms_test_assert( ! empty( WorkerProtocol::verify_worker_object_result( $worker_object_result, $keys, $fixture['environment'], $fixture['worker_claims']['object_result']['expires_at'] - 1 )['ok'] ), 'Worker object results should remain valid before the closed-at timestamp.' );
eforms_test_assert( empty( WorkerProtocol::verify_worker_object_result( $worker_object_result, $keys, $fixture['environment'], $fixture['worker_claims']['object_result']['expires_at'] )['ok'] ), 'Worker object results should close exactly at expires_at.' );
eforms_test_assert( empty( WorkerProtocol::verify_worker_gallery_status_request( $worker_gallery_request, $keys, 'wrong-environment', $fixture['verification_now'] )['ok'] ), 'Gallery-status requests should reject cross-environment replays.' );
eforms_test_assert( empty( WorkerProtocol::verify_worker_gallery_status_result( $worker_gallery_result, $keys, $fixture['environment'], $fixture['worker_claims']['gallery_status_result']['expires_at'] + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) + 1 )['ok'] ), 'Gallery-status results should reject expired envelopes.' );
eforms_test_assert( empty( WorkerProtocol::verify_worker_review_grant( $worker_review_grant, $keys, $fixture['environment'], $fixture['worker_claims']['review_grant']['expires_at'] + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' ) + 1 )['ok'] ), 'Worker exact-result review grants should reject expired envelopes.' );
eforms_test_assert( empty( WorkerProtocol::verify_worker_gallery_status_result( $worker_gallery_request, $keys, $fixture['environment'], $fixture['verification_now'] )['ok'] ), 'Gallery-status result verification should reject a request envelope.' );
$wrong_domain_gallery_request = eforms_worker_signed_parts(
    array_merge(
        array( WorkerProtocol::WORKER_GALLERY_STATUS_RESULT_DOMAIN, WorkerProtocol::VERSION, $fixture['active_key_id'], $fixture['environment'] ),
        array_map( 'strval', array_values( $fixture['worker_claims']['gallery_status_request'] ) )
    ),
    $secret
);
eforms_test_assert( empty( WorkerProtocol::verify_worker_gallery_status_request( $wrong_domain_gallery_request, $keys, $fixture['environment'], $fixture['verification_now'] )['ok'] ), 'Gallery-status requests should reject signed wrong-domain envelopes.' );
$mixed_version_gallery_request = eforms_worker_signed_parts(
    array_merge(
        array( WorkerProtocol::WORKER_GALLERY_STATUS_REQUEST_DOMAIN, '2', $fixture['active_key_id'], $fixture['environment'] ),
        array_map( 'strval', array_values( $fixture['worker_claims']['gallery_status_request'] ) )
    ),
    $secret
);
eforms_test_assert( empty( WorkerProtocol::verify_worker_gallery_status_request( $mixed_version_gallery_request, $keys, $fixture['environment'], $fixture['verification_now'] )['ok'] ), 'Gallery-status requests should reject signed version-2 envelopes.' );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( array() ) === array(), 'gallery item arrays may be empty.' );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_statuses( array() ) === array(), 'gallery status arrays may be empty.' );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( $fixture['worker_claims']['gallery_items'] ) === $fixture['worker_claims']['gallery_items'], 'PHP should normalize one exact gallery item.' );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_statuses( $fixture['worker_claims']['gallery_statuses'], $fixture['worker_claims']['gallery_items'] ) === $fixture['worker_claims']['gallery_statuses'], 'PHP should normalize one exact gallery status in item order.' );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_statuses( array( $fixture['worker_claims']['gallery_status_pending'], $fixture['worker_claims']['gallery_status_unavailable'] ) ) !== null, 'Pending and unavailable statuses should use the compact exact shape.' );
eforms_test_assert( WorkerProtocol::worker_gallery_items_sha256( $fixture['worker_claims']['gallery_items'] ) === $fixture['worker_claims']['gallery_hashes']['items_sha256'], 'PHP should hash canonical gallery items with sorted object keys and stable array order.' );
eforms_test_assert( WorkerProtocol::worker_gallery_statuses_sha256( $fixture['worker_claims']['gallery_statuses'], $fixture['worker_claims']['gallery_items'] ) === $fixture['worker_claims']['gallery_hashes']['statuses_sha256'], 'PHP should hash canonical gallery statuses with sorted object keys and stable array order.' );
eforms_test_assert( WorkerProtocol::worker_gallery_status_request_claims_match_items( $fixture['worker_claims']['gallery_status_request'], $fixture['worker_claims']['gallery_items'] ), 'Gallery request claims should bind item_count and items_sha256 to the exact item array.' );
eforms_test_assert( WorkerProtocol::worker_gallery_status_result_claims_match_statuses( $fixture['worker_claims']['gallery_status_result'], $fixture['worker_claims']['gallery_statuses'], $fixture['worker_claims']['gallery_items'] ), 'Gallery result claims should bind item_count, items_sha256, and statuses_sha256 to exact arrays.' );
eforms_test_assert( WorkerProtocol::worker_gallery_status_request_body_bytes( $worker_gallery_request, $fixture['worker_claims']['gallery_items'] ) !== '', 'Gallery request wrappers should serialize under the Anchor-owned byte cap.' );
eforms_test_assert( WorkerProtocol::worker_gallery_status_result_body_bytes( $worker_gallery_result, $fixture['worker_claims']['gallery_statuses'], $fixture['worker_claims']['gallery_items'] ) !== '', 'Gallery result wrappers should serialize under the Anchor-owned byte cap.' );
$max_gallery_items = eforms_worker_worker_gallery_items( Anchors::get( 'MANAGED_STAGED_MAX_FILES' ) );
$max_gallery_statuses = eforms_worker_worker_gallery_statuses( $max_gallery_items );
eforms_test_assert( count( WorkerProtocol::normalize_worker_gallery_items( $max_gallery_items ) ) === Anchors::get( 'MANAGED_STAGED_MAX_FILES' ), 'gallery item arrays should accept the Anchor-owned max item count.' );
eforms_test_assert( count( WorkerProtocol::normalize_worker_gallery_statuses( $max_gallery_statuses, $max_gallery_items ) ) === Anchors::get( 'MANAGED_STAGED_MAX_FILES' ), 'gallery status arrays should accept the Anchor-owned max item count.' );
$max_gallery_request_claims = array_replace(
    $fixture['worker_claims']['gallery_status_request'],
    array(
        'items_sha256' => WorkerProtocol::worker_gallery_items_sha256( $max_gallery_items ),
        'item_count' => Anchors::get( 'MANAGED_STAGED_MAX_FILES' ),
    )
);
$max_gallery_result_claims = array_replace(
    $fixture['worker_claims']['gallery_status_result'],
    array(
        'items_sha256' => $max_gallery_request_claims['items_sha256'],
        'statuses_sha256' => WorkerProtocol::worker_gallery_statuses_sha256( $max_gallery_statuses, $max_gallery_items ),
        'item_count' => Anchors::get( 'MANAGED_STAGED_MAX_FILES' ),
    )
);
$max_gallery_request = WorkerProtocol::sign_worker_gallery_status_request( $max_gallery_request_claims, $fixture['active_key_id'], $secret, $fixture['environment'] );
$max_gallery_result = WorkerProtocol::sign_worker_gallery_status_result( $max_gallery_result_claims, $fixture['active_key_id'], $secret, $fixture['environment'] );
$max_request_bytes = strlen( WorkerProtocol::worker_gallery_status_request_body_bytes( $max_gallery_request, $max_gallery_items ) );
$max_result_bytes = strlen( WorkerProtocol::worker_gallery_status_result_body_bytes( $max_gallery_result, $max_gallery_statuses, $max_gallery_items ) );
eforms_test_assert( $max_request_bytes > 0 && $max_request_bytes <= Anchors::get( 'WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES' ), 'The 24-item worst-case gallery request wrapper should fit under the 32KiB cap; observed ' . $max_request_bytes . ' bytes.' );
eforms_test_assert( $max_result_bytes > 0 && $max_result_bytes <= Anchors::get( 'WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES' ), 'The 24-item worst-case gallery result wrapper should fit under the 16KiB cap; observed ' . $max_result_bytes . ' bytes.' );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( eforms_worker_worker_gallery_items( Anchors::get( 'MANAGED_STAGED_MAX_FILES' ) + 1 ) ) === null, 'gallery items should reject max+1 arrays.' );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_statuses( eforms_worker_worker_gallery_statuses( eforms_worker_worker_gallery_items( Anchors::get( 'MANAGED_STAGED_MAX_FILES' ) + 1 ) ) ) === null, 'gallery statuses should reject max+1 arrays.' );

$worker_invalid_grant = $fixture['worker_claims']['upload_grant'];
$worker_invalid_grant['grant_expires_at'] = $worker_invalid_grant['upload_until'] + 1;
eforms_test_assert( WorkerProtocol::sign_worker_upload_grant( $worker_invalid_grant, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker grants should reject non-exact deadline bindings.' );
$worker_extra_receipt = $fixture['worker_claims']['stored_receipt'];
$worker_extra_receipt['mime'] = 'image/png';
eforms_test_assert( WorkerProtocol::sign_worker_stored_receipt( $worker_extra_receipt, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker Stored receipts should reject old accepted-media fields.' );
$old_receipt_claims = array(
    'intent_id' => $fixture['worker_claims']['stored_receipt']['intent_id'],
    'batch_id' => $fixture['worker_claims']['stored_receipt']['batch_id'],
    'upload_id' => $fixture['worker_claims']['stored_receipt']['upload_id'],
    'ordinal' => $fixture['worker_claims']['stored_receipt']['ordinal'],
    'object_key' => $fixture['worker_claims']['stored_receipt']['object_key'],
    'object_version' => $fixture['worker_claims']['stored_receipt']['object_version'],
    'etag' => $fixture['worker_claims']['stored_receipt']['etag'],
    'bytes' => $fixture['worker_claims']['stored_receipt']['bytes'],
    'mime' => 'image/png',
    'width' => 32,
    'height' => 24,
    'policy_fingerprint' => $fixture['worker_claims']['stored_receipt']['policy_fingerprint'],
    'expires_at' => $fixture['worker_claims']['stored_receipt']['expires_at'],
);
$old_receipt_as_worker = eforms_worker_signed_parts(
    array_merge(
        array( WorkerProtocol::WORKER_STORED_RECEIPT_DOMAIN, WorkerProtocol::VERSION, $fixture['active_key_id'], $fixture['environment'] ),
        array_map( 'strval', array_values( $old_receipt_claims ) )
    ),
    $secret
);
eforms_test_assert( empty( WorkerProtocol::verify_worker_stored_receipt( $old_receipt_as_worker, $keys, $fixture['environment'], $fixture['verification_now'] )['ok'] ), 'The old accepted-media receipt shape must not satisfy the v3 Stored receipt schema.' );
$mixed_version_receipt = eforms_worker_signed_parts(
    array_merge(
        array( WorkerProtocol::WORKER_STORED_RECEIPT_DOMAIN, '2', $fixture['active_key_id'], $fixture['environment'] ),
        array_map( 'strval', array_values( $fixture['worker_claims']['stored_receipt'] ) )
    ),
    $secret
);
eforms_test_assert( empty( WorkerProtocol::verify_worker_stored_receipt( $mixed_version_receipt, $keys, $fixture['environment'], $fixture['verification_now'] )['ok'] ), 'Worker receipt verification should reject a signed version-2 envelope.' );
$bad_gallery_item = $fixture['worker_claims']['gallery_items'][0];
$bad_gallery_item['bytes'] = (string) $bad_gallery_item['bytes'];
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( array( $bad_gallery_item ) ) === null, 'gallery items should reject JSON string numbers.' );
$bad_gallery_item = $fixture['worker_claims']['gallery_items'][0];
$bad_gallery_item['filename'] = 'customer.png';
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( array( $bad_gallery_item ) ) === null, 'gallery items should reject unknown fields.' );
$bad_gallery_item = $fixture['worker_claims']['gallery_items'][0];
$bad_gallery_item['upload_id'] = str_repeat( 'u', Anchors::get( 'MANAGED_ID_MAX_CHARS' ) + 1 );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( array( $bad_gallery_item ) ) === null, 'gallery items should reject oversized scalar fields.' );
$reordered_gallery_items = array_reverse( $max_gallery_items );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( $reordered_gallery_items ) === null, 'gallery items should require exact ordinal then upload_id order.' );
$duplicate_gallery_items = $fixture['worker_claims']['gallery_items'];
$duplicate_gallery_items[] = $fixture['worker_claims']['gallery_items'][0];
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( $duplicate_gallery_items ) === null, 'gallery items should reject duplicate upload and ordinal tuples.' );
$duplicate_upload_gallery_items = array_slice( $max_gallery_items, 0, 2 );
$duplicate_upload_gallery_items[1]['upload_id'] = $duplicate_upload_gallery_items[0]['upload_id'];
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( $duplicate_upload_gallery_items ) === null, 'gallery items should reject duplicate upload IDs even when ordinals differ.' );
$duplicate_ordinal_gallery_items = array_slice( $max_gallery_items, 0, 2 );
$duplicate_ordinal_gallery_items[1]['ordinal'] = $duplicate_ordinal_gallery_items[0]['ordinal'];
$duplicate_ordinal_gallery_items[1]['object_key'] = $duplicate_ordinal_gallery_items[0]['object_key'];
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( $duplicate_ordinal_gallery_items ) === null, 'gallery items should reject duplicate ordinals even when upload IDs differ.' );
$bad_gallery_item = $fixture['worker_claims']['gallery_items'][0];
$bad_gallery_item['ordinal'] = $bad_gallery_item['ordinal'] + 1;
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( array( $bad_gallery_item ) ) === null, 'gallery items should reject ordinals that do not match the canonical object key.' );
$mixed_namespace_gallery_items = array_slice( $max_gallery_items, 0, 2 );
$mixed_namespace_gallery_items[1]['object_key'] = ManagedArtifactKey::create( eforms_test_digest( 'mixed-gallery-namespace' ), 1, eforms_test_digest( 'mixed-gallery-intent' ), 'image/png' );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_items( $mixed_namespace_gallery_items ) === null, 'gallery items should reject mixed batch namespaces.' );
$bad_gallery_status = $fixture['worker_claims']['gallery_statuses'][0];
$bad_gallery_status['width'] = (string) $bad_gallery_status['width'];
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_statuses( array( $bad_gallery_status ) ) === null, 'gallery statuses should reject JSON string media dimensions.' );
$bad_gallery_status = $fixture['worker_claims']['gallery_status_pending'];
$bad_gallery_status['mime'] = 'image/png';
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_statuses( array( $bad_gallery_status ) ) === null, 'Pending statuses should reject accepted-only media facts.' );
$bad_gallery_status = $fixture['worker_claims']['gallery_statuses'][0];
unset( $bad_gallery_status['height'] );
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_statuses( array( $bad_gallery_status ) ) === null, 'Accepted statuses should require exact media facts.' );
$bad_gallery_statuses = $fixture['worker_claims']['gallery_statuses'];
$bad_gallery_statuses[0]['upload_id'] = 'different_upload';
eforms_test_assert( WorkerProtocol::normalize_worker_gallery_statuses( $bad_gallery_statuses, $fixture['worker_claims']['gallery_items'] ) === null, 'gallery statuses should preserve the exact item order at the consumer boundary.' );
$bad_gallery_request_claims = array_replace( $fixture['worker_claims']['gallery_status_request'], array( 'item_count' => 2 ) );
eforms_test_assert( ! WorkerProtocol::worker_gallery_status_request_claims_match_items( $bad_gallery_request_claims, $fixture['worker_claims']['gallery_items'] ), 'Gallery request bindings should reject mismatched item_count values.' );
$bad_gallery_result_claims = array_replace( $fixture['worker_claims']['gallery_status_result'], array( 'statuses_sha256' => str_repeat( '0', 64 ) ) );
eforms_test_assert( ! WorkerProtocol::worker_gallery_status_result_claims_match_statuses( $bad_gallery_result_claims, $fixture['worker_claims']['gallery_statuses'], $fixture['worker_claims']['gallery_items'] ), 'Gallery result bindings should reject mismatched status digests.' );
$bad_gallery_result_claims = array_replace( $fixture['worker_claims']['gallery_status_result'], array( 'item_count' => 2 ) );
eforms_test_assert( ! WorkerProtocol::worker_gallery_status_result_claims_match_statuses( $bad_gallery_result_claims, $fixture['worker_claims']['gallery_statuses'], $fixture['worker_claims']['gallery_items'] ), 'Gallery result bindings should reject mismatched item_count values.' );
$bad_gallery_result_claims = array_replace( $fixture['worker_claims']['gallery_status_result'], array( 'items_sha256' => str_repeat( '0', 64 ) ) );
eforms_test_assert( ! WorkerProtocol::worker_gallery_status_result_claims_match_statuses( $bad_gallery_result_claims, $fixture['worker_claims']['gallery_statuses'], $fixture['worker_claims']['gallery_items'] ), 'Gallery result bindings should reject mismatched item digests.' );
eforms_test_assert( WorkerProtocol::worker_gallery_status_request_body_bytes( str_repeat( 'x', Anchors::get( 'WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES' ) ), $fixture['worker_claims']['gallery_items'] ) === '', 'Oversized gallery request wrappers should fail closed under the Anchor-owned cap.' );
eforms_test_assert( WorkerProtocol::worker_gallery_status_result_body_bytes( str_repeat( 'x', Anchors::get( 'WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES' ) ), $fixture['worker_claims']['gallery_statuses'], $fixture['worker_claims']['gallery_items'] ) === '', 'Oversized gallery result wrappers should fail closed under the Anchor-owned cap.' );
$worker_review_extra = $fixture['worker_claims']['review_grant'];
$worker_review_extra['mime'] = 'image/png';
eforms_test_assert( WorkerProtocol::sign_worker_review_grant( $worker_review_extra, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker exact-result review grants should reject unknown fields.' );
$worker_review_mixed = array(
    'submission_id' => $fixture['worker_claims']['review_grant']['submission_id'],
    'upload_id' => $fixture['worker_claims']['review_grant']['upload_id'],
    'object_key' => $fixture['worker_claims']['review_grant']['object_key'],
    'object_version' => $fixture['worker_claims']['review_grant']['object_version'],
    'action' => $fixture['worker_claims']['review_grant']['action'],
    'recipe_version' => $fixture['worker_claims']['review_grant']['recipe_version'],
    'expires_at' => $fixture['worker_claims']['review_grant']['expires_at'],
    'storage_identity' => $fixture['worker_claims']['review_grant']['storage_identity'],
);
eforms_test_assert( WorkerProtocol::sign_worker_review_grant( $worker_review_mixed, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker exact-result review grants should reject old v2 review shapes.' );
$worker_object_one_sided = $fixture['worker_claims']['object_request_unknown_delete'];
$worker_object_one_sided['etag'] = 'worker-etag-v1';
eforms_test_assert( WorkerProtocol::sign_worker_object_request( $worker_object_one_sided, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker object requests should reject one-sided unknown version/ETag sentinels.' );
$worker_object_unknown_inspect = array_replace( $fixture['worker_claims']['object_request_unknown_delete'], array( 'action' => 'inspect' ) );
eforms_test_assert( WorkerProtocol::sign_worker_object_request( $worker_object_unknown_inspect, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker object inspect requests should reject unknown version/ETag sentinels.' );
$worker_object_bad_key = array_replace( $fixture['worker_claims']['object_request_known_delete'], array( 'ordinal' => 3 ) );
eforms_test_assert( WorkerProtocol::sign_worker_object_request( $worker_object_bad_key, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker object requests should bind batch, intent, and ordinal to the managed object key.' );
$worker_object_extra = $fixture['worker_claims']['object_request_known_delete'];
$worker_object_extra['validation_until'] = 2000001800;
eforms_test_assert( WorkerProtocol::sign_worker_object_request( $worker_object_extra, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker object requests should reject unknown fields.' );
$worker_object_mixed = eforms_worker_signed_parts(
    array_merge(
        array( WorkerProtocol::OBJECT_REQUEST_DOMAIN, '2', $fixture['active_key_id'], $fixture['environment'] ),
        array_map( 'strval', array_values( $fixture['worker_claims']['object_request_known_delete'] ) )
    ),
    $secret
);
eforms_test_assert( empty( WorkerProtocol::verify_worker_object_request( $worker_object_mixed, $keys, $fixture['environment'], $fixture['verification_now'] )['ok'] ), 'Worker object requests should reject signed version-2 envelopes.' );
$configuration = WorkerProtocol::key_configuration( $fixture['environment'], $fixture['active_key_id'], $fixture['active_key_b64'] );
eforms_test_assert( is_array( $configuration ) && $configuration['keys'][ $fixture['active_key_id'] ] === $secret, 'WordPress deployment wiring should build one validated active verification keyring.' );
eforms_test_assert( WorkerProtocol::key_configuration( $fixture['environment'], $fixture['active_key_id'], $fixture['active_key_b64'], $fixture['active_key_id'], $fixture['active_key_b64'] ) === null, 'Active and secondary key identifiers must not alias.' );

$extra_claim = $fixture['worker_claims']['upload_grant'];
$extra_claim['untrusted'] = 'value';
eforms_test_assert( WorkerProtocol::sign_worker_upload_grant( $extra_claim, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'WordPress should not sign extra Worker grant fields.' );
$missing_claim = $fixture['worker_claims']['upload_grant'];
unset( $missing_claim['object_key'] );
eforms_test_assert( WorkerProtocol::sign_worker_upload_grant( $missing_claim, $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'WordPress should not sign an incomplete Worker grant.' );
eforms_test_assert( WorkerProtocol::sign_worker_upload_grant( $fixture['worker_claims']['upload_grant'], 'unknown key', $secret, $fixture['environment'] ) === '', 'Invalid key identifiers should not produce a Worker grant.' );

$old_opaque_identity = hash( 'sha256', $fixture['worker_claims']['upload_grant']['batch_id'] . "\0" . $fixture['worker_claims']['upload_grant']['intent_id'] );
$old_opaque_key = 'artifacts/' . Helpers::h2( $old_opaque_identity ) . '/' . $old_opaque_identity;
eforms_test_assert( WorkerProtocol::sign_worker_upload_grant( array_replace( $fixture['worker_claims']['upload_grant'], array( 'object_key' => $old_opaque_key ) ), $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker upload grants must reject old opaque object keys.' );
eforms_test_assert( WorkerProtocol::sign_worker_review_grant( array_replace( $fixture['worker_claims']['review_grant'], array( 'object_key' => $old_opaque_key ) ), $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker review grants must reject old opaque object keys.' );
eforms_test_assert( WorkerProtocol::sign_worker_object_request( array_replace( $fixture['worker_claims']['object_request_known_delete'], array( 'object_key' => $old_opaque_key ) ), $fixture['active_key_id'], $secret, $fixture['environment'] ) === '', 'Worker object cleanup requests must reject old opaque object keys.' );

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

function eforms_worker_fixture_token( $domain, $fixture, $claims, $signature, $version = null ) {
    $parts = array_merge(
        array( $domain, $version === null ? $fixture['version'] : $version, $fixture['active_key_id'], $fixture['environment'] ),
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

function eforms_worker_worker_gallery_items( $count ) {
    $items = array();
    $batch_id = str_repeat( 'b', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) );
    for ( $i = 0; $i < $count; $i++ ) {
        $intent_id = substr( str_repeat( 'i', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) ) . $i, -Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) );
        $items[] = array(
            'upload_id' => 'upload_' . str_pad( (string) $i, 120, 'u', STR_PAD_LEFT ),
            'ordinal' => $i,
            'validation_contract_version' => str_repeat( 'v', Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) ),
            'object_key' => ManagedArtifactKey::create( $batch_id, $i, $intent_id, 'image/png' ),
            'object_version' => str_repeat( 'o', Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) ),
            'etag' => str_repeat( 'e', Anchors::get( 'WORKER_OPAQUE_MAX_CHARS' ) ),
            'bytes' => 9007199254740991,
            'policy_fingerprint' => str_repeat( 'd', 64 ),
            'validation_until' => 9007199254740991,
        );
    }
    return $items;
}

function eforms_worker_worker_gallery_statuses( $items ) {
    $statuses = array();
    foreach ( $items as $item ) {
        $statuses[] = array(
            'upload_id' => $item['upload_id'],
            'status' => 'accepted',
            'mime' => 'image/png',
            'width' => 9007199254740991,
            'height' => 9007199254740991,
        );
    }
    return $statuses;
}
