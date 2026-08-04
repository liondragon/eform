<?php
/**
 * Read-only deployment-source preflight for Worker bindings that signed health cannot
 * prove without publishing synthetic work.
 */

require_once __DIR__ . '/../../src/Anchors.php';
require_once __DIR__ . '/../../src/Uploads/WorkerProtocol.php';

function eforms_worker_deployment_preflight( $config_path = null ) {
    $config_path = $config_path === null ? __DIR__ . '/../wrangler.jsonc' : $config_path;
    $config = is_file( $config_path ) ? json_decode( file_get_contents( $config_path ), true ) : null;
    if ( ! is_array( $config ) ) {
        return eforms_worker_deployment_preflight_result( false, 'wrangler_invalid' );
    }
    return eforms_worker_deployment_preflight_evaluate( $config );
}

function eforms_worker_deployment_preflight_evaluate( $config ) {
    $queue = eforms_worker_deployment_preflight_evaluate_queue( $config );
    if ( empty( $queue['ok'] ) ) {
        return eforms_worker_deployment_preflight_result( false, $queue['reason'] );
    }
    $facts = $queue['facts'];
    $vars = isset( $config['vars'] ) && is_array( $config['vars'] ) ? $config['vars'] : array();
    if ( ! isset( $vars['EFORMS_VALIDATION_CONTRACT_VERSION'] )
        || $vars['EFORMS_VALIDATION_CONTRACT_VERSION'] !== WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION
    ) {
        return eforms_worker_deployment_preflight_result( false, 'validation_contract_mismatch' );
    }
    $facts['validation_contract_version'] = $vars['EFORMS_VALIDATION_CONTRACT_VERSION'];
    return eforms_worker_deployment_preflight_result( true, 'ready', $facts );
}

function eforms_worker_deployment_preflight_evaluate_queue( $config ) {
    $queues = isset( $config['queues'] ) && is_array( $config['queues'] ) ? $config['queues'] : array();
    $producers = isset( $queues['producers'] ) && is_array( $queues['producers'] ) ? $queues['producers'] : array();
    $consumers = isset( $queues['consumers'] ) && is_array( $queues['consumers'] ) ? $queues['consumers'] : array();
    if ( count( $producers ) !== 1 || count( $consumers ) !== 1 ) {
        return eforms_worker_deployment_preflight_result( false, 'queue_binding_count' );
    }
    $producer = $producers[0];
    $consumer = $consumers[0];
    if ( ! is_array( $producer )
        || ! is_array( $consumer )
        || ! isset( $producer['binding'], $producer['queue'], $consumer['queue'], $consumer['dead_letter_queue'] )
        || $producer['binding'] !== 'VALIDATION_QUEUE'
        || ! is_string( $producer['queue'] )
        || $producer['queue'] === ''
        || ! is_string( $consumer['queue'] )
        || $consumer['queue'] === ''
    ) {
        return eforms_worker_deployment_preflight_result( false, 'queue_binding_shape' );
    }
    if ( $producer['queue'] !== $consumer['queue'] ) {
        return eforms_worker_deployment_preflight_result( false, 'producer_consumer_mismatch' );
    }
    if ( ! is_string( $consumer['dead_letter_queue'] ) || $consumer['dead_letter_queue'] === '' || $consumer['dead_letter_queue'] === $consumer['queue'] ) {
        return eforms_worker_deployment_preflight_result( false, 'dlq_unavailable' );
    }

    $expected = array(
        'max_batch_size' => Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_BATCH_SIZE' ),
        'max_batch_timeout' => Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_BATCH_TIMEOUT_SECONDS' ),
        'max_retries' => Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_RETRIES' ),
        'max_concurrency' => Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_CONCURRENCY' ),
    );
    foreach ( $expected as $field => $value ) {
        if ( ! isset( $consumer[ $field ] ) || $consumer[ $field ] !== $value ) {
            return eforms_worker_deployment_preflight_result( false, 'queue_anchor_mismatch' );
        }
    }

    return eforms_worker_deployment_preflight_result(
        true,
        'ready',
        array(
            'producer_queue' => $producer['queue'],
            'consumer_queue' => $consumer['queue'],
            'dead_letter_queue' => $consumer['dead_letter_queue'],
            'max_batch_size' => $consumer['max_batch_size'],
            'max_batch_timeout' => $consumer['max_batch_timeout'],
            'max_retries' => $consumer['max_retries'],
            'max_concurrency' => $consumer['max_concurrency'],
        )
    );
}

function eforms_worker_deployment_preflight_result( $ok, $reason, $facts = array() ) {
    return array(
        'ok' => (bool) $ok,
        'reason' => $reason,
        'facts' => is_array( $facts ) ? $facts : array(),
    );
}

if ( realpath( isset( $_SERVER['SCRIPT_FILENAME'] ) ? $_SERVER['SCRIPT_FILENAME'] : '' ) === __FILE__ ) {
    $result = eforms_worker_deployment_preflight();
    if ( ! empty( $result['ok'] ) ) {
        fwrite( STDOUT, "Worker deployment-source preflight passed.\n" );
        exit( 0 );
    }
    fwrite( STDERR, "Worker deployment-source preflight failed: " . $result['reason'] . "\n" );
    exit( 1 );
}
