<?php
/**
 * Integration test for strict email upload item handling.
 *
 * Spec: Email delivery (docs/Canonical_Spec.md#sec-email)
 * Spec: Uploads (docs/Canonical_Spec.md#sec-uploads)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
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
        $GLOBALS['eforms_upload_strict_mail_calls'][] = array(
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'attachments' => $attachments,
        );
        return true;
    }
}

if ( ! function_exists( 'eforms_upload_strict_remove_tree' ) ) {
    function eforms_upload_strict_remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
            return;
        }

        $items = array_diff( scandir( $path ), array( '.', '..' ) );
        foreach ( $items as $item ) {
            eforms_upload_strict_remove_tree( $path . '/' . $item );
        }
        @rmdir( $path );
    }
}

$dir = eforms_test_tmp_root( 'eforms-email-upload-strict' );
mkdir( $dir, 0700, true );
$missing_safe_path = $dir . '/missing-safe.pdf';
$empty_safe_path = $dir . '/empty-safe.pdf';
file_put_contents( $missing_safe_path, 'one' );
file_put_contents( $empty_safe_path, 'two' );

$GLOBALS['eforms_upload_strict_mail_calls'] = array();
$_SERVER['HTTP_HOST'] = 'example.com';

$context = array(
    'id' => 'upload-strict',
    'email' => array(
        'to' => 'dest@example.com',
        'subject' => 'Upload strictness',
        'email_template' => 'default',
        'include_fields' => array( 'missing_safe', 'empty_safe' ),
    ),
    'fields' => array(
        array(
            'key' => 'missing_safe',
            'type' => 'file',
            'label' => 'Missing Safe',
            'email_attach' => true,
        ),
        array(
            'key' => 'empty_safe',
            'type' => 'file',
            'label' => 'Empty Safe',
            'email_attach' => true,
        ),
        array(
            'key' => 'missing_path',
            'type' => 'file',
            'label' => 'Missing Path',
            'email_attach' => true,
        ),
    ),
    'descriptors' => array(
        array(
            'key' => 'missing_safe',
            'type' => 'file',
        ),
        array(
            'key' => 'empty_safe',
            'type' => 'file',
        ),
        array(
            'key' => 'missing_path',
            'type' => 'file',
        ),
    ),
);

$values = array(
    'missing_safe' => array(
        'tmp_name' => '',
        'original_name' => 'missing-safe.pdf',
        'size' => 3,
        'error' => UPLOAD_ERR_OK,
        'stored' => array(
            'path' => $missing_safe_path,
            'bytes' => 3,
        ),
    ),
    'empty_safe' => array(
        'tmp_name' => '',
        'original_name' => 'fallback-name.pdf',
        'original_name_safe' => '',
        'size' => 3,
        'error' => UPLOAD_ERR_OK,
        'stored' => array(
            'path' => $empty_safe_path,
            'bytes' => 3,
        ),
    ),
    'missing_path' => array(
        'tmp_name' => '',
        'original_name' => 'lost.pdf',
        'original_name_safe' => 'lost-safe.pdf',
        'size' => 3,
        'error' => UPLOAD_ERR_OK,
        'stored' => array(
            'path' => $dir . '/does-not-exist.pdf',
            'bytes' => 3,
        ),
    ),
);

$config = Config::defaults();
$config['email']['html'] = false;
$config['email']['upload_max_attachments'] = 0;
$config['uploads']['max_email_bytes'] = 1024 * 1024;
$config['privacy']['ip_mode'] = 'none';

$result = Emailer::send(
    $context,
    $values,
    array(
        'submission_id' => 'strict-1',
        'mode' => 'hidden',
    ),
    array(),
    $config
);

eforms_test_assert( $result['ok'] === true, 'Emailer should send strict upload scenario.' );
eforms_test_assert( count( $GLOBALS['eforms_upload_strict_mail_calls'] ) === 1, 'wp_mail should be called once.' );

$mail = $GLOBALS['eforms_upload_strict_mail_calls'][0];
eforms_test_assert( $mail['attachments'] === array(), 'Zero attachment cap should prevent all attachments.' );
eforms_test_assert( strpos( $mail['message'], 'Attachments omitted due to limits: fallback-name.pdf' ) !== false, 'Empty safe name should fall back to original name for overflow display.' );
eforms_test_assert( strpos( $mail['message'], 'missing-safe.pdf' ) === false, 'Items missing original_name_safe must not become displayable or attachable.' );
eforms_test_assert( strpos( $mail['message'], 'lost-safe.pdf' ) === false, 'Items with invalid stored paths must not appear in attachment overflow.' );

eforms_upload_strict_remove_tree( $dir );
