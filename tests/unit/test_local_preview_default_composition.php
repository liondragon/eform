<?php
/**
 * Default tuning coverage for the optional local preview composition.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/WorkerClient.php';

define( 'EFORMS_UPLOAD_COMPOSITION', WorkerClient::COMPOSITION_LOCAL_PREVIEW );

eforms_test_assert( WorkerClient::composition() === FormProtocol::UPLOAD_TRANSPORT_LOCAL, 'The optional preview composition should preserve the canonical local artifact transport.' );
eforms_test_assert( WorkerClient::local_preview_concurrency() === 1, 'Local lazy previews should serialize by default.' );
eforms_test_assert( WorkerClient::review_provider() === 'local', 'Valid local preview wiring should enable only the optional local review provider.' );

echo "Local preview default composition tests passed.\n";
