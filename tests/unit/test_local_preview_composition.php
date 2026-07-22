<?php
/**
 * Deployment wiring coverage for the optional local preview composition.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/WorkerClient.php';

define( 'EFORMS_UPLOAD_COMPOSITION', WorkerClient::COMPOSITION_LOCAL_PREVIEW );
define( 'EFORMS_LOCAL_PREVIEW_CONCURRENCY', Anchors::get( 'LOCAL_PREVIEW_CONCURRENCY_MAX' ) + 1 );

eforms_test_assert( WorkerClient::composition() === FormProtocol::UPLOAD_TRANSPORT_LOCAL, 'Invalid optional preview tuning must not disable authoritative local upload.' );
eforms_test_assert( WorkerClient::local_preview_concurrency() === null, 'Out-of-range preview concurrency should be rejected rather than clamped.' );
eforms_test_assert( WorkerClient::review_provider() === 'unavailable', 'Invalid optional preview tuning should disable only the review provider.' );

echo "Local preview composition tests passed.\n";
