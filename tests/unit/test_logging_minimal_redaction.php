<?php
/**
 * Unit test for minimal logging privacy.
 *
 * Contract: Logging
 */

require_once __DIR__ . '/../../src/Logging.php';
require_once __DIR__ . '/../bootstrap.php';

$uploads_dir = eforms_test_tmp_root( 'eforms-logging-minimal' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$old_log_errors = ini_get( 'log_errors' );
$old_error_log = ini_get( 'error_log' );
$log_file = $uploads_dir . '/minimal.log';
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $log_file );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['logging']['mode'] = 'minimal';
        $config['logging']['level'] = 2;
        $config['logging']['pii'] = true;
        $config['privacy']['ip_mode'] = 'full';
        return $config;
    }
);

Config::reset_for_tests();
Logging::reset_for_tests();

Logging::event(
    'warning',
    'EFORMS_ERR_TOKEN',
    array(
        'request_id' => 'req-minimal',
        'form_id' => 'contact',
        'submission_id' => 'subm-1',
        'reason' => 'origin_soft',
        'origin_state' => 'cross',
        'soft_reasons' => array( 'origin_soft' ),
        'message' => 'Contact person@example.test from 198.51.100.12',
        'email' => 'person@example.test',
        'ip_raw' => '198.51.100.12',
        'client_ip' => '198.51.100.12',
        'unknown_meta' => 'secret-value',
    )
);

$line = is_file( $log_file ) ? file_get_contents( $log_file ) : '';
eforms_test_assert( is_string( $line ) && $line !== '', 'Minimal logging should write a line.' );
eforms_test_assert( strpos( $line, '"request_id":"req-minimal"' ) !== false, 'Minimal logging should retain request_id.' );
eforms_test_assert( strpos( $line, '"reason":"origin_soft"' ) !== false, 'Minimal logging should retain safe operational reason.' );
eforms_test_assert( strpos( $line, '"origin_state":"cross"' ) !== false, 'Minimal logging should retain origin_state.' );
eforms_test_assert( strpos( $line, 'person@example.test' ) === false, 'Minimal logging must not emit email metadata.' );
eforms_test_assert( strpos( $line, '198.51.100.12' ) === false, 'Minimal logging must not emit raw IP metadata.' );
eforms_test_assert( strpos( $line, 'secret-value' ) === false, 'Minimal logging must not emit arbitrary unknown metadata.' );
eforms_test_assert( strpos( $line, 'ip=198.51.100.0' ) !== false, 'Minimal logging should retain privacy-processed IP.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
Logging::reset_for_tests();
ini_set( 'log_errors', is_string( $old_log_errors ) ? $old_log_errors : '1' );
ini_set( 'error_log', is_string( $old_error_log ) ? $old_error_log : '' );

if ( is_file( $log_file ) ) {
    unlink( $log_file );
}
rmdir( $uploads_dir );
