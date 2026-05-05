<?php
/**
 * Integration test for email-failure result-page contract.
 *
 * Spec: Email-failure recovery (docs/Canonical_Spec.md#sec-email-failure-recovery)
 * Spec: Hidden-mode email-failure recovery (docs/Canonical_Spec.md#sec-hidden-email-failure)
 * Spec: Error handling (docs/Canonical_Spec.md#sec-error-handling)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Errors.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => isset( $GLOBALS['eforms_test_uploads_dir'] ) ? $GLOBALS['eforms_test_uploads_dir'] : '',
        );
    }
}

if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
        $GLOBALS['eforms_test_mail_calls'][] = array(
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'attachments' => $attachments,
        );
        return false;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) {
        if ( $name === 'admin_email' ) {
            return 'admin@example.com';
        }

        return $default;
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return 'https://example.com';
    }
}

if ( ! function_exists( 'eforms_test_remove_tree' ) ) {
    function eforms_test_remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
            return;
        }

        $items = array_diff( scandir( $path ), array( '.', '..' ) );
        foreach ( $items as $item ) {
            eforms_test_remove_tree( $path . '/' . $item );
        }
        @rmdir( $path );
    }
}

if ( ! function_exists( 'eforms_test_setup_uploads' ) ) {
    function eforms_test_setup_uploads( $prefix ) {
        $uploads_dir = eforms_test_tmp_root( $prefix );
        mkdir( $uploads_dir, 0700, true );
        $GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
        return $uploads_dir;
    }
}

if ( ! function_exists( 'eforms_test_write_template' ) ) {
    function eforms_test_write_template( $dir, $form_id ) {
        $template = array(
            'id' => $form_id,
            'version' => '1',
            'title' => 'Demo',
            'success' => array(
                'mode' => 'inline',
                'message' => 'Thanks.',
            ),
            'email' => array(
                'to' => 'demo@example.com',
                'subject' => 'Demo',
                'email_template' => 'default',
                'include_fields' => array( 'name', 'email' ),
            ),
            'fields' => array(
                array(
                    'key' => 'name',
                    'type' => 'text',
                    'label' => 'Name',
                    'required' => true,
                ),
                array(
                    'key' => 'email',
                    'type' => 'email',
                    'label' => 'Email',
                    'required' => true,
                ),
            ),
            'submit_button_text' => 'Send',
        );

        $path = rtrim( $dir, '/\\' ) . '/' . $form_id . '.json';
        file_put_contents( $path, json_encode( $template ) );
        return $path;
    }
}

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

// Given a hidden-mode submission where wp_mail fails...
$uploads_dir = eforms_test_setup_uploads( 'eforms-email-failure' );
$template_dir = eforms_test_tmp_root( 'eforms-email-template' );
mkdir( $template_dir, 0700, true );
eforms_test_write_template( $template_dir, 'demo' );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['origin_mode'] = 'off';
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();
if ( class_exists( 'Logging' ) && method_exists( 'Logging', 'reset' ) ) {
    Logging::reset();
}
$GLOBALS['eforms_test_mail_calls'] = array();

$mint = Security::mint_hidden_record( 'demo' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
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

// When SubmitHandler processes the submission...
// Then it returns the email-failure result payload without exposing submitted values.
eforms_test_assert( $result['ok'] === false, 'Email failure should return ok=false.' );
eforms_test_assert( $result['status'] === 500, 'Email failure should return HTTP 500 status.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_EMAIL_SEND', 'Email failure should return EFORMS_ERR_EMAIL_SEND.' );
eforms_test_assert( ! isset( $result['success'] ), 'Email failure should not produce success redirect metadata.' );
eforms_test_assert( ! empty( $result['email_failed'] ), 'Email failure should include an email_failed marker.' );
eforms_test_assert( ! isset( $result['security'] ), 'Email failure result should not expose retry security metadata.' );
eforms_test_assert( ! isset( $result['email_retry'] ), 'Email failure result should not expose retry markers.' );
eforms_test_assert( ! isset( $result['values'] ), 'Email failure result should not expose submitted values.' );
eforms_test_assert( ! isset( $result['email_failure_summary'] ), 'Email failure result should not expose a submission copy summary.' );
eforms_test_assert(
    count( $GLOBALS['eforms_test_mail_calls'] ) === 2,
    'Email failure should attempt the original send and one admin notification.'
);
eforms_test_assert(
    $GLOBALS['eforms_test_mail_calls'][1]['to'] === 'admin@example.com',
    'Admin notification should be sent to the WordPress admin email.'
);
eforms_test_assert(
    strpos( $GLOBALS['eforms_test_mail_calls'][1]['message'], 'Ada' ) === false,
    'Admin notification should not include submitted field values.'
);

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );
