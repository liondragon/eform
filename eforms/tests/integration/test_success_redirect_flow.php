<?php
/**
 * Integration test for redirect success flow.
 *
 * Spec: Success behavior (docs/Canonical_Spec.md#sec-success)
 * Spec: Success modes (docs/Canonical_Spec.md#sec-success-modes)
 * Spec: Redirect safety (docs/Canonical_Spec.md#sec-redirect-safety)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Submission/Success.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => isset( $GLOBALS['eforms_test_uploads_dir'] ) ? $GLOBALS['eforms_test_uploads_dir'] : '',
        );
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
    function eforms_test_write_template( $dir, $form_id, $success_mode = 'inline', $redirect_url = '' ) {
        $success = array(
            'mode' => $success_mode,
            'message' => 'Thanks.',
        );
        if ( $redirect_url !== '' ) {
            $success['redirect_url'] = $redirect_url;
        }

        $template = array(
            'id' => $form_id,
            'version' => '1',
            'title' => 'Test Form',
            'success' => $success,
            'email' => array(
                'to' => 'test@example.com',
                'subject' => 'Test',
                'email_template' => 'default',
                'include_fields' => array( 'name' ),
            ),
            'fields' => array(
                array(
                    'key' => 'name',
                    'type' => 'text',
                    'label' => 'Name',
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

// ---- Test 1: Success::redirect returns redirect URL for redirect mode ----

$context_redirect = array(
    'id' => 'test_form',
    'success' => array(
        'mode' => 'redirect',
        'redirect_url' => 'https://example.com/thank-you/',
    ),
);

$options = array(
    'current_url' => 'https://example.com/contact/',
    'host' => 'example.com',
    'scheme' => 'https',
    'dry_run' => true,
);

$result = Success::redirect( $context_redirect, $options );

eforms_test_assert( $result['ok'] === true, 'Redirect success should return ok=true.' );
eforms_test_assert( $result['status'] === 303, 'Redirect success should use status 303.' );
eforms_test_assert(
    $result['location'] === 'https://example.com/thank-you/',
    'Redirect success should use the configured redirect_url.'
);

// ---- Test 2: Success::redirect rejects cross-origin redirects ----

$context_cross_origin = array(
    'id' => 'test_form',
    'success' => array(
        'mode' => 'redirect',
        'redirect_url' => 'https://evil.com/steal-data/',
    ),
);

$result_cross = Success::redirect( $context_cross_origin, $options );

eforms_test_assert( $result_cross['ok'] === false, 'Cross-origin redirect should fail.' );
eforms_test_assert(
    $result_cross['reason'] === 'cross_origin',
    'Cross-origin redirect should fail with reason "cross_origin".'
);

// ---- Test 3: Success::redirect_external validates same-origin ----

$result_external = Success::redirect_external( 'https://example.com/success/', $options );

eforms_test_assert( $result_external['ok'] === true, 'Same-origin external redirect should succeed.' );
eforms_test_assert( $result_external['status'] === 303, 'External redirect should use status 303.' );

// ---- Test 4: Success::redirect_external rejects different scheme ----

$options_http = array(
    'host' => 'example.com',
    'scheme' => 'http',
    'dry_run' => true,
);

$result_scheme_mismatch = Success::redirect_external( 'https://example.com/success/', $options_http );

eforms_test_assert( $result_scheme_mismatch['ok'] === false, 'Scheme mismatch redirect should fail.' );
eforms_test_assert(
    $result_scheme_mismatch['reason'] === 'cross_origin',
    'Scheme mismatch should fail with reason "cross_origin".'
);

// ---- Test 5: Success::redirect_external rejects different port ----

$options_port = array(
    'host' => 'example.com',
    'scheme' => 'https',
    'port' => 443,
    'dry_run' => true,
);

$result_port_mismatch = Success::redirect_external( 'https://example.com:8443/success/', $options_port );

eforms_test_assert( $result_port_mismatch['ok'] === false, 'Port mismatch redirect should fail.' );
eforms_test_assert(
    $result_port_mismatch['reason'] === 'cross_origin',
    'Port mismatch should fail with reason "cross_origin".'
);

// ---- Test 6: Success::redirect fails when redirect_url is empty ----

$context_no_url = array(
    'id' => 'test_form',
    'success' => array(
        'mode' => 'redirect',
        'redirect_url' => '',
    ),
);

$result_no_url = Success::redirect( $context_no_url, $options );

eforms_test_assert( $result_no_url['ok'] === false, 'Redirect with empty URL should fail.' );
eforms_test_assert(
    $result_no_url['reason'] === 'no_redirect_url',
    'Empty redirect URL should fail with reason "no_redirect_url".'
);

// ---- Test 7: SubmitHandler::do_success_redirect handles inline mode ----

$handler_result_inline = array(
    'ok' => true,
    'form_id' => 'test_form',
    'success' => array(
        'mode' => 'inline',
        'message' => 'Thanks!',
        'redirect_url' => '',
    ),
);

$redirect_options = array(
    'current_url' => 'https://example.com/form/',
    'dry_run' => true,
);

$redirect_result = SubmitHandler::do_success_redirect( $handler_result_inline, $redirect_options );

eforms_test_assert( $redirect_result['ok'] === true, 'do_success_redirect should succeed for inline mode.' );
eforms_test_assert(
    strpos( $redirect_result['location'], 'eforms_success=test_form' ) !== false,
    'Inline mode should redirect with eforms_success query param.'
);

// ---- Test 8: SubmitHandler::do_success_redirect handles redirect mode ----

$handler_result_redirect = array(
    'ok' => true,
    'form_id' => 'test_form',
    'success' => array(
        'mode' => 'redirect',
        'message' => '',
        'redirect_url' => 'https://example.com/thank-you/',
    ),
);

$redirect_options_ext = array(
    'host' => 'example.com',
    'scheme' => 'https',
    'dry_run' => true,
);

$redirect_result_ext = SubmitHandler::do_success_redirect( $handler_result_redirect, $redirect_options_ext );

eforms_test_assert( $redirect_result_ext['ok'] === true, 'do_success_redirect should succeed for redirect mode.' );
eforms_test_assert(
    $redirect_result_ext['location'] === 'https://example.com/thank-you/',
    'Redirect mode should use the configured redirect_url.'
);

// ---- Test 9: SubmitHandler::do_success_redirect rejects non-success results ----

$handler_result_fail = array(
    'ok' => false,
    'form_id' => 'test_form',
);

$redirect_result_fail = SubmitHandler::do_success_redirect( $handler_result_fail, $redirect_options );

eforms_test_assert( $redirect_result_fail['ok'] === false, 'do_success_redirect should fail for non-success results.' );
eforms_test_assert(
    $redirect_result_fail['reason'] === 'not_success',
    'Non-success result should fail with reason "not_success".'
);

// ---- Test 10: Full pipeline returns success config in result ----

$uploads_dir = eforms_test_tmp_root( 'eforms-redirect-uploads' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$template_dir = eforms_test_tmp_root( 'eforms-redirect-templates' );
mkdir( $template_dir, 0700, true );
eforms_test_write_template( $template_dir, 'redirect-form', 'redirect', 'https://example.com/thanks/' );

Config::reset_for_tests();
StorageHealth::reset_for_tests();

$mint = Security::mint_hidden_record( 'redirect-form' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
    'redirect-form' => array(
        'name' => 'Test User',
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
    'commit' => function () {
        return array( 'ok' => true, 'status' => 200, 'committed' => true );
    },
);

$pipeline_result = SubmitHandler::handle( 'redirect-form', $request, $overrides );

eforms_test_assert( $pipeline_result['ok'] === true, 'Pipeline should succeed.' );
eforms_test_assert(
    isset( $pipeline_result['success'] ) && is_array( $pipeline_result['success'] ),
    'Pipeline result should include success configuration.'
);
eforms_test_assert(
    $pipeline_result['success']['mode'] === 'redirect',
    'Success mode should be "redirect".'
);
eforms_test_assert(
    $pipeline_result['success']['redirect_url'] === 'https://example.com/thanks/',
    'Success redirect_url should match template configuration.'
);
eforms_test_assert(
    $pipeline_result['form_id'] === 'redirect-form',
    'Pipeline result should include form_id.'
);

// ---- Cleanup ----

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

echo "All redirect success flow tests passed.\n";
