<?php
/**
 * Integration tests for content-filter submission wiring.
 *
 * Contract: Spam content filter policy.
 * Contract: Request lifecycle POST.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/DeclinedReviewLog.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';

if ( ! function_exists( 'eforms_content_submission_configure' ) ) {
    function eforms_content_submission_configure( $uploads_dir, $mode, $terms, $threshold = 10, $declined_review = false ) {
        eforms_test_set_filter(
            'eforms_config',
            function ( $config ) use ( $uploads_dir, $mode, $terms, $threshold, $declined_review ) {
                $config['uploads']['dir'] = $uploads_dir;
                $config['security']['honeypot_response'] = 'hard_fail';
                $config['security']['origin_mode'] = 'off';
                $config['spam']['soft_fail_threshold'] = $threshold;
                $config['spam']['content_filter']['mode'] = $mode;
                $config['spam']['content_filter']['blocked_terms'] = $terms;
                $config['declined_review']['enable'] = (bool) $declined_review;
                $config['declined_review']['retention_days'] = 30;
                return $config;
            }
        );

        Config::reset_for_tests();
        StorageHealth::reset_for_tests();
        Logging::reset_for_tests();
        eforms_test_reset_mail();
    }
}

if ( ! function_exists( 'eforms_content_submission_request' ) ) {
    function eforms_content_submission_request( $name, $token = 'tok' ) {
        return array(
            'post' => array(
                'eforms_token' => $token,
                'instance_id' => 'inst',
                'timestamp' => '123',
                'js_ok' => '1',
                'demo' => array(
                    'name' => $name,
                ),
            ),
            'files' => array(),
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
        );
    }
}

if ( ! function_exists( 'eforms_content_submission_security' ) ) {
    function eforms_content_submission_security( $soft_reasons = array(), $require_challenge = false ) {
        return array(
            'token_ok' => true,
            'hard_fail' => false,
            'error_code' => '',
            'submission_id' => 'content-submission',
            'mode' => 'hidden',
            'soft_reasons' => is_array( $soft_reasons ) ? $soft_reasons : array(),
            'require_challenge' => (bool) $require_challenge,
            'challenge_response_present' => (bool) $require_challenge,
        );
    }
}

if ( ! function_exists( 'eforms_content_submission_handle' ) ) {
    function eforms_content_submission_handle( $template_dir, $request, $security, $overrides = array() ) {
        $base_overrides = array(
            'template_base_dir' => $template_dir,
            'trace' => true,
            'security' => function () use ( $security ) {
                return $security;
            },
        );

        return SubmitHandler::handle( 'demo', $request, array_merge( $base_overrides, $overrides ) );
    }
}

$uploads_dir = eforms_test_setup_uploads( 'eforms-content-filter-uploads' );
$template_dir = eforms_test_tmp_root( 'eforms-content-filter-templates' );
mkdir( $template_dir, 0700, true );
eforms_test_write_basic_template( $template_dir, 'demo' );

// Given content suspect mode and an existing below-threshold soft reason...
// Then the content match is attached without increasing the soft-threshold decision.
eforms_content_submission_configure( $uploads_dir, 'suspect', 'casino', 2 );
$captured_security = array();
$commit_calls = 0;
$suspect = eforms_content_submission_handle(
    $template_dir,
    eforms_content_submission_request( 'Casino help' ),
    eforms_content_submission_security( array( 'js_missing' ) ),
    array(
        'ledger_reserve' => function () {
            return array( 'ok' => true );
        },
        'commit' => function ( $context, $coerced, $security ) use ( &$captured_security, &$commit_calls ) {
            $captured_security = $security;
            $commit_calls += 1;
            return array(
                'ok' => true,
                'status' => 200,
                'values' => array( 'name' => 'Casino help' ),
            );
        },
    )
);

eforms_test_assert( $suspect['ok'] === true, 'Content suspect submission should continue.' );
eforms_test_assert( $commit_calls === 1, 'Content suspect should reach the normal commit path.' );
eforms_test_assert(
    $suspect['trace'] === array( 'security', 'normalize', 'validate', 'coerce', 'content_filter', 'commit' ),
    'Content suspect should run after coerce and before commit.'
);
eforms_test_assert( $captured_security['soft_reasons'] === array( 'js_missing' ), 'Content suspect must not add behavior soft reasons.' );
eforms_test_assert( ! empty( $captured_security['content_filter']['matched'] ), 'Content suspect should attach safe content-filter metadata.' );
eforms_test_assert( $captured_security['content_filter']['decision'] === 'suspect', 'Content suspect metadata should keep the suspect decision.' );
eforms_test_assert( $captured_security['content_filter']['reason'] === 'content_blocked_term', 'Content suspect metadata should keep the stable reason.' );
eforms_test_assert( count( Logging::$events ) === 1, 'Content suspect should emit one operator evidence log event.' );
$suspect_event = Logging::$events[0];
eforms_test_assert( $suspect_event['severity'] === 'info', 'Content suspect log should use info severity.' );
eforms_test_assert( $suspect_event['code'] === 'EFORMS_CONTENT_FILTER_SUSPECT', 'Content suspect log should use the content-filter code.' );
eforms_test_assert( $suspect_event['meta']['spam_decision'] === 'suspect', 'Content suspect log should identify the suspect decision.' );
eforms_test_assert( $suspect_event['meta']['content_filter']['match_ids'] === array( sha1( 'casino' ) ), 'Content suspect log should expose stable match IDs.' );
eforms_test_assert( $suspect_event['meta']['content_filter']['field_keys'] === array( 'name' ), 'Content suspect log should expose matched field keys.' );

// Given content reject mode after a successful challenge...
// Then rejection uses the existing spam short-circuit before ledger commit/email.
eforms_content_submission_configure( $uploads_dir, 'reject', 'casino', 10, true );
$burn_calls = 0;
$ledger_calls = 0;
$commit_calls = 0;
$reject = eforms_content_submission_handle(
    $template_dir,
    eforms_content_submission_request( 'Casino help' ),
    eforms_content_submission_security( array(), true ),
    array(
        'challenge' => function () {
            return array(
                'ok' => true,
                'required' => true,
                'error_code' => '',
                'soft_reasons' => array(),
            );
        },
        'honeypot_burn' => function () use ( &$burn_calls ) {
            $burn_calls += 1;
            return array( 'ok' => true );
        },
        'ledger_reserve' => function () use ( &$ledger_calls ) {
            $ledger_calls += 1;
            return array( 'ok' => true );
        },
        'commit' => function () use ( &$commit_calls ) {
            $commit_calls += 1;
            return array( 'ok' => true, 'status' => 200 );
        },
    )
);

eforms_test_assert( $reject['ok'] === false, 'Content reject should return the spam error result.' );
eforms_test_assert( $reject['status'] === 200, 'Content reject should preserve current spam response status.' );
eforms_test_assert( $reject['error_code'] === 'EFORMS_ERR_SPAM', 'Content reject should use the stable spam error code.' );
eforms_test_assert(
    $reject['trace'] === array( 'security', 'normalize', 'validate', 'coerce', 'challenge', 'content_filter', 'content_filter_reject' ),
    'Content reject should run after challenge success and before commit.'
);
eforms_test_assert( $burn_calls === 1, 'Content reject should burn/reserve through the existing spam short-circuit.' );
eforms_test_assert( $ledger_calls === 0, 'Content reject should not reach the normal ledger reservation.' );
eforms_test_assert( $commit_calls === 0, 'Content reject must not run upload moves or email.' );
eforms_test_assert( strpos( json_encode( $reject ), 'casino' ) === false, 'Content reject visitor result must not expose raw matched terms.' );
eforms_test_assert( count( Logging::$events ) === 1, 'Content reject should emit one spam log event.' );
$reject_event = Logging::$events[0];
eforms_test_assert( $reject_event['severity'] === 'warning', 'Content reject log should use warning severity.' );
eforms_test_assert( $reject_event['code'] === 'EFORMS_ERR_SPAM', 'Content reject log should use the spam code.' );
eforms_test_assert( $reject_event['meta']['content_filter']['decision'] === 'reject', 'Content reject log should include reject decision metadata.' );
eforms_test_assert( $reject_event['meta']['content_filter']['match_ids'] === array( sha1( 'casino' ) ), 'Content reject log should expose stable match IDs.' );
eforms_test_assert( $reject_event['meta']['content_filter']['field_keys'] === array( 'name' ), 'Content reject log should expose matched field keys.' );
eforms_test_assert( strpos( json_encode( $reject_event ), 'casino' ) === false, 'Content reject log must not expose raw matched terms.' );
$declined = DeclinedReviewLog::query( array( 'decision_code' => 'EFORMS_ERR_SPAM' ), Config::get() );
eforms_test_assert( $declined['total'] === 1, 'Content reject should create one declined-review record when review capture is enabled.' );
$declined_record = $declined['records'][0];
eforms_test_assert( $declined_record['decision_phase'] === 'content_filter', 'Content declined record should identify the content-filter phase.' );
eforms_test_assert( $declined_record['value_stage'] === 'canonical', 'Content declined record should capture canonical values.' );
eforms_test_assert( $declined_record['content_filter']['decision'] === 'reject', 'Content declined record should include content-filter decision.' );
eforms_test_assert( $declined_record['content_filter']['match_ids'] === array( sha1( 'casino' ) ), 'Content declined record should include stable match IDs.' );
eforms_test_assert( $declined_record['content_filter']['field_keys'] === array( 'name' ), 'Content declined record should include matched field keys.' );
eforms_test_assert( strpos( json_encode( $declined_record['content_filter'] ), 'casino' ) === false, 'Content declined metadata must not expose raw matched terms.' );

// Given a blocked term appears only in protocol fields...
// Then the content filter does not reject the submission.
eforms_content_submission_configure( $uploads_dir, 'reject', 'casino', 10 );
$commit_calls = 0;
$protocol_only = eforms_content_submission_handle(
    $template_dir,
    eforms_content_submission_request( 'Ada Lovelace', 'casino-token' ),
    eforms_content_submission_security(),
    array(
        'ledger_reserve' => function () {
            return array( 'ok' => true );
        },
        'commit' => function () use ( &$commit_calls ) {
            $commit_calls += 1;
            return array(
                'ok' => true,
                'status' => 200,
                'values' => array( 'name' => 'Ada Lovelace' ),
            );
        },
    )
);

eforms_test_assert( $protocol_only['ok'] === true, 'Protocol-only term matches should not reject.' );
eforms_test_assert( $commit_calls === 1, 'Protocol-only term matches should continue to commit.' );
eforms_test_assert(
    $protocol_only['trace'] === array( 'security', 'normalize', 'validate', 'coerce', 'content_filter', 'commit' ),
    'Protocol-only no-match should keep the normal enabled content-filter order.'
);

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
StorageHealth::reset_for_tests();
Logging::reset_for_tests();
