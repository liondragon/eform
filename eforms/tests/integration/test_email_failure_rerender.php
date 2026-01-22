<?php
/**
 * Integration test for email-failure rerender contract.
 *
 * Spec: Email-failure recovery (docs/Canonical_Spec.md#sec-email-failure-recovery)
 * Spec: Hidden-mode email-failure recovery (docs/Canonical_Spec.md#sec-hidden-email-failure)
 * Spec: Error handling (docs/Canonical_Spec.md#sec-error-handling)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Errors.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';
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
        return false;
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
// Then it returns the email-failure rerender payload with a fresh token.
eforms_test_assert( $result['ok'] === false, 'Email failure should return ok=false.' );
eforms_test_assert( $result['status'] === 500, 'Email failure should return HTTP 500 status.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_EMAIL_SEND', 'Email failure should return EFORMS_ERR_EMAIL_SEND.' );
eforms_test_assert( $result['security']['token'] !== $mint['token'], 'Email failure should mint a fresh token.' );
eforms_test_assert( $result['email_retry'] === true, 'Email failure rerender should include email_retry marker.' );
eforms_test_assert( $result['values']['name'] === 'Ada', 'Email failure rerender should preserve non-file values.' );
eforms_test_assert(
    strpos( $result['email_failure_summary'], 'name: Ada' ) !== false,
    'Email failure summary should include submitted values.'
);

// Given renderer overrides for an email-failure rerender...
// When FormRenderer renders a JS-minted rerender...
// Then it includes the remint marker and retry input.
$errors = new Errors();
$errors->add_global( 'EFORMS_ERR_EMAIL_SEND', 'We couldn\'t send your message. Please try again.' );

$output = FormRenderer::render(
    'quote-request',
    array(
        'errors' => $errors,
        'security' => array(
            'mode' => 'js',
            'token' => '',
            'instance_id' => '',
            'timestamp' => '',
        ),
        'values' => array(
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'tel_us' => '5551239999',
            'zip_us' => '90210',
            'message' => 'Hello',
        ),
        'email_retry' => true,
        'email_failure_summary' => 'Copy this.',
        'email_failure_remint' => true,
        'force_cache_headers' => true,
    )
);

eforms_test_assert( is_string( $output ), 'Renderer should return HTML.' );
eforms_test_assert( strpos( $output, 'data-eforms-remint="1"' ) !== false, 'Renderer should include remint marker.' );
eforms_test_assert( strpos( $output, 'name="eforms_email_retry"' ) !== false, 'Renderer should include email retry input.' );
eforms_test_assert( strpos( $output, 'value="Ada"' ) !== false, 'Renderer should prefill text values.' );
eforms_test_assert( strpos( $output, 'class="eforms-email-failure-copy"' ) !== false, 'Renderer should include copy textarea.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );
