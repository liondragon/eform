<?php
/**
 * Integration tests for honeypot behavior.
 *
 * Spec: Honeypot (docs/Canonical_Spec.md#sec-honeypot)
 * Spec: Security (docs/Canonical_Spec.md#sec-security)
 * Spec: Spam decision (docs/Canonical_Spec.md#sec-spam-decision)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';

// Given a honeypot hit in stealth mode...
// When SubmitHandler runs...
// Then it short-circuits and mimics success, burning only with token_ok.
$uploads_dir = eforms_test_setup_uploads( 'eforms-honeypot' );
$template_dir = eforms_test_tmp_root( 'eforms-honeypot-template' );
mkdir( $template_dir, 0700, true );
eforms_test_write_basic_template( $template_dir, 'demo' );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['honeypot_response'] = 'stealth_success';
        $config['security']['origin_mode'] = 'off';
        $config['declined_review']['enable'] = true;
        $config['declined_review']['retention_days'] = 30;
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();
Logging::reset_for_tests();

$mint = Security::mint_hidden_record( 'demo' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
    'eforms_hp' => 'bot',
    'demo' => array(
        'name' => 'Ada',
    ),
);

$request = array(
    'post' => $post,
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
);

$burn_calls = 0;
$overrides = array(
    'template_base_dir' => $template_dir,
    'trace' => true,
    'honeypot_burn' => function ( $form_id, $submission_id, $uploads_dir, $request_data, $config ) use ( &$burn_calls ) {
        $burn_calls += 1;
        return array( 'ok' => true, 'attempted' => true );
    },
);

$result = SubmitHandler::handle( 'demo', $request, $overrides );

eforms_test_assert( $result['ok'] === true, 'Stealth honeypot should return ok result.' );
eforms_test_assert( $result['errors'] === null, 'Stealth honeypot should not include errors.' );
eforms_test_assert( ! isset( $result['success'] ), 'Stealth honeypot should not carry result-page copy metadata.' );
eforms_test_assert(
    isset( $result['form_id'] ) && $result['form_id'] === 'demo',
    'Stealth honeypot should include the form_id for success redirects.'
);
eforms_test_assert( $burn_calls === 1, 'Stealth honeypot should attempt ledger burn when token_ok.' );
eforms_test_assert(
    $result['trace'] === array( 'security', 'honeypot' ),
    'Honeypot should short-circuit before validation stages.'
);
if ( class_exists( 'Logging' ) ) {
    eforms_test_assert( count( Logging::$events ) === 1, 'Stealth honeypot should emit a log event.' );
    $event = Logging::$events[0];
    eforms_test_assert( $event['code'] === 'EFORMS_ERR_HONEYPOT', 'Honeypot log should use honeypot code.' );
    eforms_test_assert( ! empty( $event['meta']['honeypot'] ), 'Honeypot log should include honeypot flag.' );
    eforms_test_assert( ! empty( $event['meta']['stealth'] ), 'Stealth honeypot log should include stealth flag.' );
}
$declined = DeclinedReviewLog::query( array(), Config::get() );
eforms_test_assert( $declined['total'] === 1, 'Token-valid stealth honeypot should create one declined-review record.' );
$declined_record = $declined['records'][0];
eforms_test_assert( $declined_record['decision_phase'] === 'honeypot', 'Honeypot declined record should identify the honeypot phase.' );
eforms_test_assert( $declined_record['value_stage'] === 'metadata_only', 'Honeypot declined record should be metadata-only.' );
eforms_test_assert( $declined_record['fields'] === array(), 'Honeypot declined record should not capture raw submitted field values.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );

// Given a honeypot hit in hard-fail mode...
// When SubmitHandler runs...
// Then it returns the honeypot error and skips burn when token is invalid.
$uploads_dir = eforms_test_setup_uploads( 'eforms-honeypot' );
$template_dir = eforms_test_tmp_root( 'eforms-honeypot-template' );
mkdir( $template_dir, 0700, true );
eforms_test_write_basic_template( $template_dir, 'demo' );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['honeypot_response'] = 'hard_fail';
        $config['security']['origin_mode'] = 'off';
        $config['declined_review']['enable'] = true;
        $config['declined_review']['retention_days'] = 30;
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();
Logging::reset_for_tests();

$mint = Security::mint_hidden_record( 'demo' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
    'eforms_hp' => 'bot',
    'demo' => array(
        'name' => 'Ada',
    ),
);
$request = array(
    'post' => $post,
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
);

$burn_calls = 0;
$overrides = array(
    'template_base_dir' => $template_dir,
    'trace' => true,
    'honeypot_burn' => function ( $form_id, $submission_id, $uploads_dir, $request_data, $config ) use ( &$burn_calls ) {
        $burn_calls += 1;
        return array( 'ok' => true, 'attempted' => true );
    },
);

$result = SubmitHandler::handle( 'demo', $request, $overrides );
eforms_test_assert( $result['ok'] === false, 'Hard-fail honeypot should return error result.' );
eforms_test_assert( $result['status'] === 200, 'Hard-fail honeypot should use HTTP 200.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_HONEYPOT', 'Hard-fail honeypot should use honeypot error code.' );
eforms_test_assert( $burn_calls === 1, 'Hard-fail honeypot should attempt ledger burn when token_ok.' );
$declined = DeclinedReviewLog::query( array(), Config::get() );
eforms_test_assert( $declined['total'] === 1, 'Token-valid hard-fail honeypot should create one declined-review record.' );
$declined_before_invalid = $declined['total'];

// Given an invalid token with honeypot hit...
// When SubmitHandler runs...
// Then it still responds with honeypot error and skips ledger burn.
$post['eforms_token'] = 'not-a-token';
$post['instance_id'] = 'not-an-instance';
$request['post'] = $post;

$burn_calls = 0;
$result = SubmitHandler::handle( 'demo', $request, $overrides );
eforms_test_assert( $result['ok'] === false, 'Invalid token honeypot should return error result.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_HONEYPOT', 'Invalid token honeypot should still return honeypot error.' );
eforms_test_assert( $burn_calls === 0, 'Invalid token honeypot must not attempt ledger burn.' );
$declined = DeclinedReviewLog::query( array(), Config::get() );
eforms_test_assert( $declined['total'] === $declined_before_invalid, 'Invalid-token honeypot must not create another declined-review record.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );
