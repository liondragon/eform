<?php
/**
 * Integration test for email header sanitization and reply-to precedence.
 *
 * Spec: Email delivery (docs/Canonical_Spec.md#sec-email)
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

if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers, $attachments = array() ) {
        $GLOBALS['eforms_mail_calls'][] = array(
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'attachments' => $attachments,
        );
        return true;
    }
}

$GLOBALS['eforms_mail_calls'] = array();

$template_dir = eforms_test_tmp_root( 'eforms-email-template' );
mkdir( $template_dir, 0700, true );
$template = array(
    'id' => 'demo',
    'version' => '1',
    'title' => 'Demo',
    'success' => array(
        'mode' => 'inline',
        'message' => 'Thanks.',
    ),
    'email' => array(
        'to' => 'dest@example.com',
        'subject' => "Hello {{field.name}}\r\nBcc: test",
        'email_template' => 'default',
        'include_fields' => array( 'name' ),
    ),
    'fields' => array(
        array(
            'key' => 'name',
            'type' => 'text',
            'label' => 'Name',
        ),
    ),
    'submit_button_text' => 'Send',
);
file_put_contents( $template_dir . '/demo.json', json_encode( $template ) );

Config::reset_for_tests();
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['email']['from_address'] = 'noreply@evil.example';
        $config['email']['reply_to_address'] = 'reply@example.com';
        return $config;
    }
);

$loaded = TemplateLoader::load( 'demo', $template_dir );
$context_result = TemplateContext::build( $loaded['template'], $loaded['version'] );
$context = $context_result['context'];

$values = array(
    'name' => "Ada\r\nInjected",
);
$security = array(
    'submission_id' => 'submission-1',
    'mode' => 'hidden',
);

$result = Emailer::send( $context, $values, $security, array(), Config::get() );

// Given sanitized headers...
// When Emailer builds the outbound email...
// Then subject/header values contain no CR/LF and reply-to precedence holds.
eforms_test_assert( $result['ok'] === true, 'Emailer should send successfully.' );
eforms_test_assert( ! empty( $GLOBALS['eforms_mail_calls'] ), 'wp_mail should be called.' );

$call = $GLOBALS['eforms_mail_calls'][0];
$subject = $call['subject'];
$headers = $call['headers'];

$from = '';
$reply_to = '';
foreach ( $headers as $header ) {
    if ( strpos( $header, 'From:' ) === 0 ) {
        $from = $header;
    }
    if ( strpos( $header, 'Reply-To:' ) === 0 ) {
        $reply_to = $header;
    }
}

eforms_test_assert( strpos( $subject, "\n" ) === false, 'Subject should not contain LF.' );
eforms_test_assert( strpos( $subject, "\r" ) === false, 'Subject should not contain CR.' );
eforms_test_assert( strpos( $subject, 'Ada Injected' ) !== false, 'Subject should include sanitized token expansion.' );
eforms_test_assert( strpos( $from, 'no-reply@example.com' ) !== false, 'From should fall back to the site domain.' );
eforms_test_assert( $reply_to === 'Reply-To: reply@example.com', 'Reply-To should use the configured address.' );

if ( is_dir( $template_dir ) ) {
    $items = array_diff( scandir( $template_dir ), array( '.', '..' ) );
    foreach ( $items as $item ) {
        @unlink( $template_dir . '/' . $item );
    }
    @rmdir( $template_dir );
}

$eforms_test_filter_cleanup = eforms_test_set_filter( 'eforms_config', null );
