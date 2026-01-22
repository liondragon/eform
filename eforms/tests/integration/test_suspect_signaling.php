<?php
/**
 * Integration test for suspect signaling (headers + subject tag).
 *
 * Spec: Suspect handling (docs/Canonical_Spec.md#sec-suspect-handling)
 * Spec: Spam decision (docs/Canonical_Spec.md#sec-spam-decision)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
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
    function wp_mail( $to, $subject, $message, $headers, $attachments = array() ) {
        if ( ! isset( $GLOBALS['eforms_test_mail_calls'] ) || ! is_array( $GLOBALS['eforms_test_mail_calls'] ) ) {
            $GLOBALS['eforms_test_mail_calls'] = array();
        }

        $GLOBALS['eforms_test_mail_calls'][] = array(
            'subject' => $subject,
            'headers' => $headers,
        );

        return true;
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

$uploads_dir = eforms_test_tmp_root( 'eforms-suspect-uploads' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$template_dir = eforms_test_tmp_root( 'eforms-suspect-templates' );
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

// Given a valid submission with a soft reason (js_missing)...
$GLOBALS['eforms_test_mail_calls'] = array();
$mint = Security::mint_hidden_record( 'demo' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
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
