<?php
/**
 * Integration test for suspect signaling (headers + subject tag).
 *
 * Contract: Suspect handling
 * Contract: Spam decision
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

$uploads_dir = eforms_test_setup_uploads( 'eforms-suspect-uploads' );

$template_dir = eforms_test_tmp_root( 'eforms-suspect-templates' );
mkdir( $template_dir, 0700, true );
eforms_test_write_contact_template( $template_dir, 'demo' );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['origin_mode'] = 'off';
        return $config;
    }
);

Config::reset_for_tests();
StorageHealth::reset_for_tests();

// Given a valid submission with a soft reason (js_missing)...
eforms_test_reset_mail();
$mint = Security::mint_hidden_record( 'demo' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'eforms_hp' => '',
    'demo' => array(
        'name' => 'Ada',
        'email' => 'ada@example.com',
    ),
);

$request = array(
    'post' => $post,
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
);

$overrides = array(
    'template_base_dir' => $template_dir,
);

$result = SubmitHandler::handle( 'demo', $request, $overrides );

// When SubmitHandler succeeds...
// Then it tags the subject and emits suspect headers.
eforms_test_assert( $result['ok'] === true, 'Suspect submission should still succeed.' );
eforms_test_assert( ! empty( $GLOBALS['eforms_test_mail_calls'] ), 'Email should be sent for suspect submissions.' );

$subject = $GLOBALS['eforms_test_mail_calls'][0]['subject'];
eforms_test_assert(
    is_string( $subject ) && strpos( $subject, '[Suspect] ' ) === 0,
    'Suspect submissions should receive a subject tag.'
);
$mail_headers = $GLOBALS['eforms_test_mail_calls'][0]['headers'];
$has_reason_header = false;
foreach ( $mail_headers as $header ) {
    if ( $header === 'X-EForms-Soft-Reasons: js_missing' ) {
        $has_reason_header = true;
    }
}
eforms_test_assert( $has_reason_header, 'Suspect emails should include soft reason headers.' );

$headers = function_exists( 'headers_list' ) ? headers_list() : array();
if ( ! empty( $headers ) ) {
    $has_soft_fails = false;
    $has_suspect = false;

    foreach ( $headers as $header ) {
        if ( stripos( $header, 'X-EForms-Soft-Fails:' ) === 0 ) {
            $value = trim( substr( $header, strlen( 'X-EForms-Soft-Fails:' ) ) );
            if ( $value === '1' ) {
                $has_soft_fails = true;
            }
        }
        if ( stripos( $header, 'X-EForms-Suspect:' ) === 0 ) {
            $has_suspect = true;
        }
    }

    eforms_test_assert( $has_soft_fails, 'Response should include X-EForms-Soft-Fails for suspects.' );
    eforms_test_assert( $has_suspect, 'Response should include X-EForms-Suspect for suspects.' );
}

if ( function_exists( 'header_remove' ) ) {
    header_remove();
}

// Given an invalid submission with a soft reason...
$mint = Security::mint_hidden_record( 'demo' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'eforms_hp' => '',
    'demo' => array(
        'email' => 'ada@example.com',
    ),
);

$request['post'] = $post;
$result = SubmitHandler::handle( 'demo', $request, $overrides );

// When SubmitHandler rerenders with errors...
// Then it still emits the suspect headers.
eforms_test_assert( $result['ok'] === false, 'Invalid submissions should rerender with errors.' );

$headers = function_exists( 'headers_list' ) ? headers_list() : array();
if ( ! empty( $headers ) ) {
    $has_soft_fails = false;
    $has_suspect = false;

    foreach ( $headers as $header ) {
        if ( stripos( $header, 'X-EForms-Soft-Fails:' ) === 0 ) {
            $value = trim( substr( $header, strlen( 'X-EForms-Soft-Fails:' ) ) );
            if ( $value === '1' ) {
                $has_soft_fails = true;
            }
        }
        if ( stripos( $header, 'X-EForms-Suspect:' ) === 0 ) {
            $has_suspect = true;
        }
    }

    eforms_test_assert( $has_soft_fails, 'Rerender responses should include X-EForms-Soft-Fails.' );
    eforms_test_assert( $has_suspect, 'Rerender responses should include X-EForms-Suspect.' );
}

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );
