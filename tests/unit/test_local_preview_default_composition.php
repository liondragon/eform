<?php
/**
 * Default tuning coverage for the optional local preview composition.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/WorkerClient.php';
require_once __DIR__ . '/../../src/Diagnostics/RuntimeHealthDiagnostic.php';

define( 'EFORMS_UPLOAD_COMPOSITION', WorkerClient::COMPOSITION_LOCAL_PREVIEW );

eforms_test_assert( WorkerClient::composition() === FormProtocol::UPLOAD_TRANSPORT_LOCAL, 'The optional preview composition should preserve the canonical local artifact transport.' );
eforms_test_assert( WorkerClient::local_preview_concurrency() === 1, 'Local lazy previews should serialize by default.' );
eforms_test_assert( WorkerClient::review_provider() === 'local', 'Valid local preview wiring should enable only the optional local review provider.' );

$preview_readiness = new ReflectionMethod( RuntimeHealthDiagnostic::class, 'check_review_preview_readiness' );
$preview_readiness->setAccessible( true );
$low_memory = $preview_readiness->invoke(
    null,
    array(
        'local_preview_imagick' => true,
        'memory_limit' => '512M',
        'execution_limit' => 360,
    )
);
eforms_test_assert( $low_memory['result'] === 'WARN' && $low_memory['observed'] === 'memory_limit', 'Runtime preview readiness should evaluate the observed web memory limit instead of the diagnostic CLI process.' );
$ready = $preview_readiness->invoke(
    null,
    array(
        'local_preview_imagick' => true,
        'memory_limit' => '768M',
        'execution_limit' => 60,
    )
);
eforms_test_assert( $ready['result'] === 'PASS', 'Runtime preview readiness should pass when the observed web limits and Imagick capability meet the fixed bounds.' );

echo "Local preview default composition tests passed.\n";
