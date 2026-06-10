<?php
/**
 * Integration tests for threshold spam-fail handling.
 *
 * Spec: Spam decision (docs/Canonical_Spec.md#sec-spam-decision)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';

if ( ! function_exists( 'eforms_spam_test_request' ) ) {
    function eforms_spam_test_request( $tmp_upload = '' ) {
        $files = array();
        if ( is_string( $tmp_upload ) && $tmp_upload !== '' ) {
            $files = array(
                'demo' => array(
                    'name' => array( 'upload' => 'bot.pdf' ),
                    'tmp_name' => array( 'upload' => $tmp_upload ),
                    'error' => array( 'upload' => 0 ),
                    'size' => array( 'upload' => is_file( $tmp_upload ) ? filesize( $tmp_upload ) : 0 ),
                ),
            );
        }

        return array(
            'post' => array(
                'eforms_token' => 'tok',
                'instance_id' => 'inst',
                'timestamp' => '123',
                'js_ok' => '',
                'demo' => array(
                    'name' => 'Ada',
                ),
            ),
            'files' => $files,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
        );
    }
}

if ( ! function_exists( 'eforms_spam_test_security' ) ) {
    function eforms_spam_test_security( $ok, $soft_reasons ) {
        return array(
            'token_ok' => (bool) $ok,
            'hard_fail' => ! $ok,
            'error_code' => $ok ? '' : 'EFORMS_ERR_TOKEN',
            'submission_id' => $ok ? 'spam-submission' : '',
            'mode' => 'hidden',
            'soft_reasons' => is_array( $soft_reasons ) ? $soft_reasons : array(),
            'require_challenge' => false,
        );
    }
}

if ( ! function_exists( 'eforms_spam_test_configure' ) ) {
    function eforms_spam_test_configure( $threshold, $response ) {
        eforms_test_set_filter(
            'eforms_config',
            function ( $config ) use ( $threshold, $response ) {
                $config['security']['honeypot_response'] = $response;
                $config['security']['origin_mode'] = 'off';
                $config['spam']['soft_fail_threshold'] = $threshold;
                $config['declined_review']['enable'] = true;
                $config['declined_review']['retention_days'] = 30;
                return $config;
            }
        );

        Config::reset_for_tests();
        StorageHealth::reset_for_tests();
        Logging::reset_for_tests();
    }
}

if ( ! function_exists( 'eforms_spam_test_handle' ) ) {
    function eforms_spam_test_handle( $template_dir, $request, $security, $overrides = array() ) {
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

$uploads_dir = eforms_test_setup_uploads( 'eforms-spam-fail-uploads' );

$template_dir = eforms_test_tmp_root( 'eforms-spam-fail-templates' );
mkdir( $template_dir, 0700, true );
eforms_test_write_basic_template( $template_dir, 'demo' );

// Given threshold=1 and one soft reason in hard-fail mode...
// When SubmitHandler runs...
// Then it short-circuits before validation, email, and upload moves.
eforms_spam_test_configure( 1, 'hard_fail' );
$tmp_dir = eforms_test_tmp_root( 'eforms-spam-fail-tmp' );
mkdir( $tmp_dir, 0700, true );
$tmp_upload = $tmp_dir . '/bot.pdf';
file_put_contents( $tmp_upload, '%PDF-1.4' );
$burn_calls = 0;
$commit_calls = 0;
$request = eforms_spam_test_request( $tmp_upload );
$result = eforms_spam_test_handle(
    $template_dir,
    $request,
    eforms_spam_test_security( true, array( 'js_missing' ) ),
    array(
        'honeypot_burn' => function () use ( &$burn_calls ) {
            $burn_calls += 1;
            return array( 'ok' => true );
        },
        'normalize' => function () {
            throw new RuntimeException( 'normalize should not run on spam-fail.' );
        },
        'commit' => function () use ( &$commit_calls ) {
            $commit_calls += 1;
            return array( 'ok' => true, 'status' => 200 );
        },
    )
);

eforms_test_assert( $result['ok'] === false, 'Threshold spam hard-fail should return an error result.' );
eforms_test_assert( $result['status'] === 200, 'Threshold spam hard-fail should use HTTP 200.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_SPAM', 'Threshold spam hard-fail should use the spam error code.' );
eforms_test_assert( $result['trace'] === array( 'security', 'spam' ), 'Threshold spam should stop before normalize/validate/coerce.' );
eforms_test_assert( $burn_calls === 1, 'Threshold spam should burn the ledger when token_ok=true.' );
eforms_test_assert( $commit_calls === 0, 'Threshold spam must not run commit/email.' );
eforms_test_assert( ! file_exists( $tmp_upload ), 'Threshold spam should clean request temp uploads.' );

if ( class_exists( 'Logging' ) ) {
    eforms_test_assert( count( Logging::$events ) === 1, 'Threshold spam should emit one warning event.' );
    $event = Logging::$events[0];
    eforms_test_assert( $event['severity'] === 'warning', 'Threshold spam log should be warning severity.' );
    eforms_test_assert( $event['code'] === 'EFORMS_ERR_SPAM', 'Threshold spam log should use spam code.' );
    eforms_test_assert( $event['meta']['spam_decision'] === 'fail', 'Threshold spam log should include spam_decision=fail.' );
    eforms_test_assert( $event['meta']['soft_fail_count'] === 1, 'Threshold spam log should include soft_fail_count.' );
    eforms_test_assert( $event['meta']['threshold'] === 1, 'Threshold spam log should include threshold.' );
}
$declined = DeclinedReviewLog::query( array( 'decision_code' => 'EFORMS_ERR_SPAM' ), Config::get() );
eforms_test_assert( $declined['total'] === 1, 'Threshold spam hard-fail should create one declined-review record.' );
$declined_record = $declined['records'][0];
eforms_test_assert( $declined_record['decision_phase'] === 'spam_threshold', 'Spam declined record should identify the threshold phase.' );
eforms_test_assert( $declined_record['value_stage'] === 'raw_declared', 'Spam declined record should capture raw declared values.' );
eforms_test_assert( $declined_record['fields']['name'] === 'Ada', 'Spam declined record should include declared field content.' );

// Given threshold=1 and one soft reason in stealth mode...
// Then spam-fail returns success-shaped metadata without commit/email.
eforms_spam_test_configure( 1, 'stealth_success' );
$burn_calls = 0;
$commit_calls = 0;
$result = eforms_spam_test_handle(
    $template_dir,
    eforms_spam_test_request(),
    eforms_spam_test_security( true, array( 'js_missing' ) ),
    array(
        'honeypot_burn' => function () use ( &$burn_calls ) {
            $burn_calls += 1;
            return array( 'ok' => true );
        },
        'commit' => function () use ( &$commit_calls ) {
            $commit_calls += 1;
            return array( 'ok' => true, 'status' => 200 );
        },
    )
);
eforms_test_assert( $result['ok'] === true, 'Stealth spam-fail should mimic success.' );
eforms_test_assert( $result['commit']['committed'] === false, 'Stealth spam-fail should not commit side effects.' );
eforms_test_assert( $burn_calls === 1, 'Stealth spam-fail should burn the ledger when token_ok=true.' );
eforms_test_assert( $commit_calls === 0, 'Stealth spam-fail must not run commit/email.' );

// Given threshold=2 and one soft reason...
// Then the submission remains suspect and proceeds through validation/commit.
eforms_spam_test_configure( 2, 'hard_fail' );
$declined_before_suspect = DeclinedReviewLog::query( array( 'decision_code' => 'EFORMS_ERR_SPAM' ), Config::get() )['total'];
$commit_calls = 0;
$result = eforms_spam_test_handle(
    $template_dir,
    eforms_spam_test_request(),
    eforms_spam_test_security( true, array( 'js_missing' ) ),
    array(
        'ledger_reserve' => function () {
            return array( 'ok' => true );
        },
        'commit' => function () use ( &$commit_calls ) {
            $commit_calls += 1;
            return array(
                'ok' => true,
                'status' => 200,
                'values' => array( 'name' => 'Ada' ),
            );
        },
    )
);
eforms_test_assert( $result['ok'] === true, 'Below-threshold suspect submission should still succeed.' );
eforms_test_assert( $commit_calls === 1, 'Below-threshold suspect submission should run commit/email path.' );
eforms_test_assert(
    $result['trace'] === array( 'security', 'normalize', 'validate', 'coerce', 'commit' ),
    'Below-threshold suspect submission should continue through the pipeline.'
);
$declined_after_suspect = DeclinedReviewLog::query( array( 'decision_code' => 'EFORMS_ERR_SPAM' ), Config::get() )['total'];
eforms_test_assert( $declined_after_suspect === $declined_before_suspect, 'Below-threshold suspect submission should not create a declined-review record.' );

// Given token validation fails even with soft reasons...
// Then SubmitHandler stops at token failure and does not burn the ledger.
eforms_spam_test_configure( 1, 'hard_fail' );
$declined_before_token = DeclinedReviewLog::query( array( 'decision_code' => 'EFORMS_ERR_SPAM' ), Config::get() )['total'];
$burn_calls = 0;
$result = eforms_spam_test_handle(
    $template_dir,
    eforms_spam_test_request(),
    eforms_spam_test_security( false, array( 'js_missing' ) ),
    array(
        'honeypot_burn' => function () use ( &$burn_calls ) {
            $burn_calls += 1;
            return array( 'ok' => true );
        },
    )
);
eforms_test_assert( $result['ok'] === false, 'Invalid token should hard-fail before spam decision.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_TOKEN', 'Invalid token should keep token error.' );
eforms_test_assert( $burn_calls === 0, 'Invalid token must not burn the ledger.' );
$declined_after_token = DeclinedReviewLog::query( array( 'decision_code' => 'EFORMS_ERR_SPAM' ), Config::get() )['total'];
eforms_test_assert( $declined_after_token === $declined_before_token, 'Invalid token should not create a declined-review record.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_remove_tree( $tmp_dir );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
StorageHealth::reset_for_tests();
