<?php
/**
 * Integration tests for the operator spam smoke command.
 *
 * Contract: Spam smoke command
 * Contract: Honeypot
 * Contract: Spam decision
 * Contract: Throttling
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Diagnostics/SpamSmokeDiagnostic.php';
require_once __DIR__ . '/../../src/Cli/SpamSmokeCommand.php';

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;
$_SERVER['HTTP_ORIGIN'] = 'https://example.com';
unset( $_SERVER['CONTENT_LENGTH'] );

$uploads_dir = eforms_test_setup_uploads( 'eforms-spam-smoke' );
Config::reset_snapshot();

$result = SpamSmokeDiagnostic::run();
$command_result = SpamSmokeCommand::run();

eforms_test_assert( $result['ok'] === true, 'Spam smoke diagnostic should pass in the default test runtime.' );
eforms_test_assert( $result['exit_code'] === 0, 'All-pass smoke run should expose exit_code=0.' );
eforms_test_assert( count( $result['checks'] ) === 10, 'Spam smoke should run the focused behavior-class check set.' );
eforms_test_assert( $result['summary']['passed'] === 10, 'All smoke checks should pass.' );
eforms_test_assert( $result['summary']['failed'] === 0, 'No smoke checks should fail.' );
eforms_test_assert( $command_result['checks'] === $result['checks'], 'CLI adapter should expose the shared diagnostic result without its own check implementation.' );
eforms_test_assert( SpamSmokeDiagnostic::summary_line( $result ) === '10 passed, 0 failed', 'Diagnostic owner should derive the shared summary line.' );
eforms_test_assert( count( SpamSmokeDiagnostic::rows( $result ) ) === 10, 'Diagnostic owner should derive shared presentation rows.' );

$checks = array();
foreach ( $result['checks'] as $check ) {
    $checks[ $check['name'] ] = $check;
}

foreach ( array( 'baseline', 'honeypot', 'missing-js', 'missing-honeypot', 'too-fast', 'combined-soft', 'challenge-auto', 'throttle', 'mint-oversized', 'mint-no-origin' ) as $name ) {
    eforms_test_assert( isset( $checks[ $name ] ), 'Missing smoke check: ' . $name );
    eforms_test_assert( $checks[ $name ]['ok'] === true, 'Smoke check should pass: ' . $name );
    eforms_test_assert( isset( $checks[ $name ]['expected'] ) && $checks[ $name ]['expected'] !== '', 'Smoke check should report expected result: ' . $name );
    eforms_test_assert( isset( $checks[ $name ]['config_scope'] ) && $checks[ $name ]['config_scope'] !== '', 'Smoke check should report config scope: ' . $name );
}

eforms_test_assert(
    strpos( $checks['baseline']['observed'], 'commit override' ) !== false,
    'Baseline should report that real email was suppressed at the commit boundary.'
);
eforms_test_assert(
    strpos( $checks['missing-js']['observed'], 'js_missing' ) !== false,
    'Missing-JS check should prove the js_missing soft reason.'
);
eforms_test_assert(
    strpos( $checks['missing-honeypot']['observed'], 'honeypot_missing' ) !== false,
    'Missing-honeypot check should prove omitted honeypot is a soft reason.'
);
eforms_test_assert(
    strpos( $checks['too-fast']['observed'], 'min_fill_time' ) !== false,
    'Too-fast check should prove the min_fill_time soft reason.'
);
eforms_test_assert(
    strpos( $checks['combined-soft']['observed'], 'min_fill_time' ) !== false && strpos( $checks['combined-soft']['observed'], 'js_missing' ) !== false,
    'Combined-soft check should prove multiple timing soft reasons can be reported together.'
);
eforms_test_assert(
    strpos( $checks['combined-soft']['config_scope'], 'missing JS plus positive min fill' ) !== false,
    'Combined-soft check should explain its temporary config assumptions.'
);
eforms_test_assert(
    strpos( $checks['challenge-auto']['observed'], 'required' ) !== false && strpos( $checks['challenge-auto']['observed'], 'js_missing' ) !== false && strpos( $checks['challenge-auto']['observed'], 'honeypot_missing' ) !== false,
    'Challenge-auto check should prove auto mode requires challenge for a synthetic soft signal.'
);
eforms_test_assert(
    strpos( $checks['challenge-auto']['notes'], 'provider not contacted' ) !== false,
    'Challenge-auto check should not depend on a remote provider call.'
);
eforms_test_assert(
    strpos( $checks['throttle']['notes'], '198.51.100.44' ) !== false,
    'Throttle check should use its own synthetic IP.'
);
eforms_test_assert(
    $checks['mint-no-origin']['observed'] === '403',
    'No-origin mint check must ignore the ambient admin request Origin header.'
);

$config = Config::get();
eforms_test_assert( $config['throttle']['enable'] === false, 'Throttle override must not leak after smoke run.' );
eforms_test_assert( $config['spam']['soft_fail_threshold'] === Config::DEFAULT_SPAM_SOFT_FAIL_THRESHOLD, 'Strict spam threshold must not leak after smoke run.' );
eforms_test_assert( $config['security']['max_post_bytes'] === PHP_INT_MAX, 'Oversized mint cap must not leak after smoke run.' );
eforms_test_assert( ! isset( $_SERVER['CONTENT_LENGTH'] ), 'Oversized mint CONTENT_LENGTH must be restored.' );
eforms_test_assert( $_SERVER['HTTP_ORIGIN'] === 'https://example.com', 'Smoke run should not mutate the ambient Origin header.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_snapshot();
