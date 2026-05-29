<?php
/**
 * Integration test for success PRG through plugin-owned result pages.
 *
 * Spec: Success behavior (docs/Canonical_Spec.md#sec-success)
 * Spec: Result page flow (docs/Canonical_Spec.md#sec-success-flow)
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
    function eforms_test_write_template( $dir, $form_id ) {
        $template = array(
            'id' => $form_id,
            'version' => '1',
            'title' => 'Test Form',
            'result_pages' => array(
                'success' => array(
                    'title' => 'Custom Result',
                    'message' => 'Custom result copy.',
                ),
            ),
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

$context_redirect = array(
    'id' => 'test_form',
);

$options = array(
    'current_url' => 'https://example.com/contact/',
    'dry_run' => true,
);

$result = Success::redirect( $context_redirect, $options );

eforms_test_assert( $result['ok'] === true, 'Success redirect should return ok=true.' );
eforms_test_assert( $result['status'] === 303, 'Success redirect should use status 303.' );
eforms_test_assert( strpos( $result['location'], 'eforms_result=success' ) !== false, 'Success redirect should target a result page.' );
eforms_test_assert( strpos( $result['location'], 'eforms_form=test_form' ) !== false, 'Success redirect should identify the form.' );
eforms_test_assert(
    Success::get_result_title(
        'success',
        array(
            'result_pages' => array(
                'success' => array(
                    'title' => 'Custom Result',
                    'message' => 'Custom result copy.',
                ),
            ),
        )
    ) === 'Custom Result',
    'Success result title should come from result_pages.success.title.'
);
eforms_test_assert(
    Success::get_result_message(
        'success',
        array(
            'result_pages' => array(
                'success' => array(
                    'title' => 'Custom Result',
                    'message' => 'Custom result copy.',
                ),
            ),
        )
    ) === 'Custom result copy.',
    'Success result message should come from result_pages.success.message.'
);

$handler_result_redirect = array(
    'ok' => true,
    'form_id' => 'test_form',
);

$redirect_result_ext = SubmitHandler::do_success_redirect( $handler_result_redirect, $options );

eforms_test_assert( $redirect_result_ext['ok'] === true, 'do_success_redirect should succeed for success results.' );
eforms_test_assert( strpos( $redirect_result_ext['location'], 'eforms_result=success' ) !== false, 'do_success_redirect should use result-page success.' );
eforms_test_assert( strpos( $redirect_result_ext['location'], 'eforms_form=test_form' ) !== false, 'do_success_redirect should include form id.' );

$handler_result_fail = array(
    'ok' => false,
    'form_id' => 'test_form',
);

$redirect_result_fail = SubmitHandler::do_success_redirect( $handler_result_fail, $options );

eforms_test_assert( $redirect_result_fail['ok'] === false, 'do_success_redirect should fail for non-success results.' );
eforms_test_assert( $redirect_result_fail['reason'] === 'not_success', 'Non-success result should fail with reason "not_success".' );

$uploads_dir = eforms_test_tmp_root( 'eforms-redirect-uploads' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$template_dir = eforms_test_tmp_root( 'eforms-redirect-templates' );
mkdir( $template_dir, 0700, true );
eforms_test_write_template( $template_dir, 'result-form' );

Config::reset_for_tests();
StorageHealth::reset_for_tests();

$mint = Security::mint_hidden_record( 'result-form' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
    'result-form' => array(
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

$pipeline_result = SubmitHandler::handle( 'result-form', $request, $overrides );

eforms_test_assert( $pipeline_result['ok'] === true, 'Pipeline should succeed.' );
eforms_test_assert( ! isset( $pipeline_result['success'] ), 'Pipeline result should not carry result-page copy metadata.' );
eforms_test_assert( $pipeline_result['form_id'] === 'result-form', 'Pipeline result should include form_id.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

echo "All result-page success flow tests passed.\n";
