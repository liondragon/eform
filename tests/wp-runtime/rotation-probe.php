<?php
/**
 * Read-only paired-runtime probe for the controlled Worker key-rotation drill.
 * Run through `wp eval-file` on the disposable WordPress deployment.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WorkerClient' ) ) {
    fwrite( STDERR, "eForms rotation probe requires a bootstrapped WordPress runtime.\n" );
    exit( 1 );
}

$configuration = WorkerClient::configuration();
$health = is_array( $configuration )
    ? WorkerClient::health( time(), null, 'rotation_probe' )
    : array( 'ok' => false );
$secondary_ids = array();
$wordpress_origin = function_exists( 'home_url' ) ? eforms_rotation_probe_origin( home_url( '/' ) ) : '';
$pair_fingerprint = '';
if ( is_array( $configuration ) ) {
    $secondary_ids = array_values( array_diff( array_keys( $configuration['keys'] ), array( $configuration['active_id'] ) ) );
    sort( $secondary_ids, SORT_STRING );
    if ( $wordpress_origin !== '' ) {
        $encoded_pair = json_encode(
            array( 'wordpress_worker_pair', $wordpress_origin, $configuration['origin'], $configuration['environment'] ),
            JSON_UNESCAPED_SLASHES
        );
        $pair_fingerprint = is_string( $encoded_pair ) ? hash( 'sha256', $encoded_pair ) : '';
    }
}
$record = array(
    'environment_id' => is_array( $configuration ) ? $configuration['environment'] : '',
    'pair_fingerprint' => $pair_fingerprint,
    'active_key_id' => is_array( $configuration ) ? $configuration['active_id'] : '',
    'secondary_key_ids' => $secondary_ids,
    'ready' => $pair_fingerprint !== '' && ! empty( $health['ok'] ) && ! empty( $health['storage_ready'] ) && ! empty( $health['inspection_ready'] ),
);
echo json_encode( $record, JSON_UNESCAPED_SLASHES ) . "\n";
exit( $record['ready'] ? 0 : 1 );

function eforms_rotation_probe_origin( $url ) {
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
