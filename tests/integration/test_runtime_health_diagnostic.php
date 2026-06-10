<?php
/**
 * Integration tests for the operator runtime health diagnostic.
 *
 * Spec: Runtime Health Diagnostic (docs/Canonical_Spec.md#sec-runtime-health-diagnostic)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Cli/RuntimeHealthCommand.php';
require_once __DIR__ . '/../../src/Diagnostics/RuntimeHealthDiagnostic.php';

$uploads_dir = eforms_test_setup_uploads( 'eforms-runtime-health' );
Config::reset_snapshot();

$result = RuntimeHealthDiagnostic::run();
$command_result = RuntimeHealthCommand::run();
$rows = RuntimeHealthDiagnostic::rows( $result );

eforms_test_assert( $result['ok'] === true, 'Runtime health should pass with warnings in the default test runtime.' );
eforms_test_assert( $result['exit_code'] === 0, 'Warn-only runtime health should expose exit_code=0.' );
eforms_test_assert( count( $result['checks'] ) === 7, 'Runtime health should run the focused readiness check set.' );
eforms_test_assert( $command_result['checks'] === $result['checks'], 'CLI adapter should expose the shared runtime health result without its own check implementation.' );
eforms_test_assert( function_exists( 'eforms_cli_doctor' ), 'Bootstrap should expose the wp eforms doctor handler.' );
eforms_test_assert( RuntimeHealthDiagnostic::summary_line( $result ) === '6 passed, 1 warning, 0 failed', 'Runtime health should summarize pass/warn/fail counts.' );

$checks = array();
foreach ( $result['checks'] as $check ) {
    $checks[ $check['name'] ] = $check;
}

foreach ( array( 'uploads-base', 'private-storage', 'runtime-dirs', 'templates', 'gc-readiness', 'cli-bootstrap', 'config-sources' ) as $name ) {
    eforms_test_assert( isset( $checks[ $name ] ), 'Missing runtime health check: ' . $name );
    eforms_test_assert( isset( $checks[ $name ]['observed'] ) && $checks[ $name ]['observed'] !== '', 'Runtime health should report observed result: ' . $name );
    eforms_test_assert( isset( $checks[ $name ]['expected'] ) && $checks[ $name ]['expected'] !== '', 'Runtime health should report expected result: ' . $name );
}

eforms_test_assert( $checks['cli-bootstrap']['result'] === 'WARN', 'Non-CLI test runtime should produce a CLI bootstrap warning.' );
eforms_test_assert( $checks['gc-readiness']['result'] === 'PASS', 'Fresh runtime storage should pass GC dry-run readiness.' );
eforms_test_assert( $checks['runtime-dirs']['notes'] === 'temporary probes cleaned', 'Runtime dir check should report probe cleanup.' );

foreach ( $rows as $row ) {
    eforms_test_assert( strpos( $row['observed'], $uploads_dir ) === false, 'Runtime health rows should not expose raw upload paths.' );
    eforms_test_assert( strpos( $row['notes'], $uploads_dir ) === false, 'Runtime health notes should not expose raw upload paths.' );
}

$private_dir = $uploads_dir . '/eforms-private';
foreach ( array( 'tokens', 'ledger', 'logs', 'throttle' ) as $dir ) {
    eforms_test_assert( is_dir( $private_dir . '/' . $dir ), 'Runtime health should leave usable runtime dir: ' . $dir );
    eforms_test_assert( ! file_exists( $private_dir . '/' . $dir . '/' . RuntimeHealthDiagnostic::PROBE_FILENAME ), 'Runtime health should remove probe file for: ' . $dir );
}

$config = Config::get();
eforms_test_assert( $config['uploads']['dir'] === $uploads_dir, 'Runtime health should not mutate config state.' );

eforms_test_remove_tree( $uploads_dir );
Config::reset_snapshot();

$missing_uploads = eforms_test_tmp_root( 'eforms-runtime-health-missing' );
$GLOBALS['eforms_test_uploads_dir'] = $missing_uploads;
Config::reset_snapshot();

$failure = RuntimeHealthDiagnostic::run();
$failure_checks = array();
foreach ( $failure['checks'] as $check ) {
    $failure_checks[ $check['name'] ] = $check;
}

eforms_test_assert( $failure['ok'] === false, 'Runtime health should fail when uploads storage is unavailable.' );
eforms_test_assert( $failure['exit_code'] === 1, 'Failed runtime health should expose exit_code=1.' );
eforms_test_assert( $failure_checks['uploads-base']['result'] === 'FAIL', 'Missing uploads base should fail the uploads-base check.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_snapshot();
