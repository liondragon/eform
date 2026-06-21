<?php
/**
 * Integration test for email header sanitization and reply-to precedence.
 *
 * Contract: Email delivery
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Rendering/TemplateLoader.php';
require_once __DIR__ . '/../../src/Rendering/TemplateContext.php';
require_once __DIR__ . '/../../src/Email/Emailer.php';

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return 'https://example.com';
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $key = '' ) {
        return 'Example Site';
    }
}

eforms_test_reset_mail();

$template_dir = eforms_test_tmp_root( 'eforms-email-template' );
mkdir( $template_dir, 0700, true );
$template = array(
    'id' => 'demo',
    'version' => '1',
    'title' => 'Demo',
    'result_pages' => array(
        'success' => array(
            'message' => 'Thanks.',
        ),
    ),
    'email' => array(
        'to' => 'dest@example.com',
        'subject' => "Hello {{field.name}}\r\nBcc: test",
        'email_template' => 'default',
        'include_fields' => array( 'name', 'email', 'ip' ),
    ),
    'fields' => array(
        array(
            'key' => 'name',
            'type' => 'text',
            'label' => 'Name',
        ),
        array(
            'key' => 'email',
            'type' => 'email',
            'label' => 'Email',
        ),
    ),
    'submit_button_text' => 'Send',
);
file_put_contents( $template_dir . '/demo.json', json_encode( $template ) );

Config::reset_for_tests();
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['email']['from_address'] = 'contact@example.com';
        $config['email']['html'] = true;
        $config['spam']['soft_fail_threshold'] = 10;
        $config['privacy']['ip_mode'] = 'full';
        return $config;
    }
);

$loaded = TemplateLoader::load( 'demo', $template_dir );
$context_result = TemplateContext::build( $loaded['template'], $loaded['version'] );
$context = $context_result['context'];

$values = array(
    'name' => "Ada\r\nInjected",
    'email' => 'ada@example.com',
);
$security = array(
    'submission_id' => 'submission-1',
    'mode' => 'hidden',
    'soft_reasons' => array( 'origin_soft', 'origin_soft', "js_missing\r\nBcc: attacker@example.com", 'bad value' ),
);

$result = Emailer::send( $context, $values, $security, array( 'client_ip' => '203.0.113.42' ), Config::get() );

// Given sanitized headers...
// When Emailer builds the outbound email...
// Then subject/header values contain no CR/LF and reply-to precedence holds.
eforms_test_assert( $result['ok'] === true, 'Emailer should send successfully.' );
eforms_test_assert( ! empty( $GLOBALS['eforms_test_mail_calls'] ), 'wp_mail should be called.' );

$call = $GLOBALS['eforms_test_mail_calls'][0];
$subject = $call['subject'];
$headers = $call['headers'];
$body = $call['message'];

$from = '';
$reply_to = '';
$soft_reasons = '';
foreach ( $headers as $header ) {
    if ( strpos( $header, 'From:' ) === 0 ) {
        $from = $header;
    }
    if ( strpos( $header, 'Reply-To:' ) === 0 ) {
        $reply_to = $header;
    }
    if ( strpos( $header, 'X-EForms-Soft-Reasons:' ) === 0 ) {
        $soft_reasons = $header;
    }
}

eforms_test_assert( strpos( $subject, "\n" ) === false, 'Subject should not contain LF.' );
eforms_test_assert( strpos( $subject, "\r" ) === false, 'Subject should not contain CR.' );
eforms_test_assert( strpos( $subject, 'Ada Injected' ) !== false, 'Subject should include sanitized token expansion.' );
eforms_test_assert( $from === 'From: Ada Injected <contact@example.com>', 'From should keep configured same-domain address.' );
eforms_test_assert( $reply_to === 'Reply-To: ada@example.com', 'Reply-To should default to the canonical email field when no fixed reply_to_address is configured.' );
eforms_test_assert( $soft_reasons === 'X-EForms-Soft-Reasons: origin_soft', 'Soft reason header should keep only safe deduplicated reason tokens.' );
eforms_test_assert( strpos( implode( "\n", $headers ), 'Bcc:' ) === false, 'Soft reason header should not allow injected headers.' );
eforms_test_assert( strpos( $body, '<table role="presentation"' ) !== false, 'HTML body should render included fields as a table.' );
eforms_test_assert( strpos( $body, 'Form:' ) === false, 'Default email body should not include automatic form metadata.' );
eforms_test_assert( strpos( $body, 'Submission:' ) === false, 'Default email body should not include automatic submission metadata.' );
eforms_test_assert( strpos( $body, 'Submitted:' ) === false, 'Default email body should not include automatic submitted metadata.' );
eforms_test_assert( strpos( $body, '<th scope="row" style="font-weight:bold;text-align:left;vertical-align:top;padding:0 28px 4px 0;">Name:</th>' ) !== false, 'HTML body should bold friendly field labels.' );
eforms_test_assert( strpos( $body, '<a href="mailto:ada@example.com">ada@example.com</a>' ) !== false, 'HTML body should render email values as mailto links.' );
eforms_test_assert( strpos( $body, 'Sent from:' ) !== false, 'HTML body should render ip with a friendly label.' );
eforms_test_assert( strpos( $body, 'name:' ) === false, 'HTML body should not render backend field keys as labels.' );

eforms_test_remove_tree( $template_dir );

eforms_test_set_filter( 'eforms_config', null );
