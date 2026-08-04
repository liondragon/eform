<?php
/**
 * Focused read-only Worker deployment preflight tests.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../worker/scripts/deployment-preflight.php';

$result = eforms_worker_deployment_preflight( __DIR__ . '/../../worker/wrangler.jsonc' );
eforms_test_assert( ! empty( $result['ok'] ) && $result['reason'] === 'ready', 'Worker deployment preflight should pass for the checked-in Queue/DLQ wiring.' );
eforms_test_assert( $result['facts']['producer_queue'] === $result['facts']['consumer_queue'], 'The Queue producer and consumer should target the same validation Queue.' );
eforms_test_assert( $result['facts']['dead_letter_queue'] !== $result['facts']['consumer_queue'], 'The deployment should route exhausted validation jobs into a distinct DLQ.' );
eforms_test_assert( $result['facts']['max_batch_size'] === Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_BATCH_SIZE' ), 'Queue batch size should remain Anchor-owned.' );
eforms_test_assert( $result['facts']['max_batch_timeout'] === Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_BATCH_TIMEOUT_SECONDS' ), 'Queue batch timeout should remain Anchor-owned.' );
eforms_test_assert( $result['facts']['max_retries'] === Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_RETRIES' ), 'Queue retry count should remain Anchor-owned.' );
eforms_test_assert( $result['facts']['max_concurrency'] === Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_CONCURRENCY' ), 'Queue concurrency should remain Anchor-owned.' );
eforms_test_assert( $result['facts']['validation_contract_version'] === WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION, 'Worker validation contract should match the PHP grant owner.' );

$base = json_decode( file_get_contents( __DIR__ . '/../../worker/wrangler.jsonc' ), true );
eforms_test_assert( is_array( $base ), 'The checked-in wrangler config should decode as JSON.' );

$missing_dlq = $base;
$missing_dlq['queues']['consumers'][0]['dead_letter_queue'] = $missing_dlq['queues']['consumers'][0]['queue'];
eforms_test_assert( eforms_worker_deployment_preflight_evaluate( $missing_dlq )['reason'] === 'dlq_unavailable', 'The preflight should fail closed when the DLQ is missing or aliases the consumer Queue.' );

$mismatched_queue = $base;
$mismatched_queue['queues']['consumers'][0]['queue'] = 'alternate-validation';
eforms_test_assert( eforms_worker_deployment_preflight_evaluate( $mismatched_queue )['reason'] === 'producer_consumer_mismatch', 'The preflight should fail when producer and consumer queue names diverge.' );

$empty_queue = $base;
$empty_queue['queues']['producers'][0]['queue'] = '';
$empty_queue['queues']['consumers'][0]['queue'] = '';
eforms_test_assert( eforms_worker_deployment_preflight_evaluate( $empty_queue )['reason'] === 'queue_binding_shape', 'The preflight should reject empty Queue names even when producer and consumer match.' );

$array_queue = $base;
$array_queue['queues']['producers'][0]['queue'] = array( 'validation' );
$array_queue['queues']['consumers'][0]['queue'] = array( 'validation' );
eforms_test_assert( eforms_worker_deployment_preflight_evaluate( $array_queue )['reason'] === 'queue_binding_shape', 'The preflight should reject non-string Queue names even when producer and consumer match.' );

$stale_anchor = $base;
$stale_anchor['queues']['consumers'][0]['max_retries'] = Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_RETRIES' ) + 1;
eforms_test_assert( eforms_worker_deployment_preflight_evaluate( $stale_anchor )['reason'] === 'queue_anchor_mismatch', 'The preflight should fail when Queue retry policy drifts from Anchors.' );

$stale_contract = $base;
$stale_contract['vars']['EFORMS_VALIDATION_CONTRACT_VERSION'] = 'managed-image-v2';
eforms_test_assert( eforms_worker_deployment_preflight_evaluate( $stale_contract )['reason'] === 'validation_contract_mismatch', 'The preflight should fail when Worker validation contract drifts from the PHP grant owner.' );

echo "Worker deployment preflight tests passed.\n";
