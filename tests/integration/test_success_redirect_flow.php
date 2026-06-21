<?php
/**
 * Integration test for success PRG through plugin-owned result pages.
 *
 * Contract: Success behavior
 * Contract: Result page flow
 * Contract: Cache-safety
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Submission/Success.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';

if ( ! function_exists( 'eforms_success_test_write_template' ) ) {
    function eforms_success_test_write_template( $dir, $form_id ) {
        return eforms_test_write_form_template(
            $dir,
            $form_id,
            'Test Form',
            array(
                array(
                    'key' => 'name',
                    'type' => 'text',
                    'label' => 'Name',
                    'required' => true,
                ),
            ),
            array( 'name' ),
            array( 'to' => 'test@example.com', 'subject' => 'Test' ),
            array(
                'success' => array(
                    'title' => 'Custom Result',
                    'message' => 'Custom result copy.',
                ),
            )
        );
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
eforms_success_test_write_template( $template_dir, 'result-form' );

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
