<?php
/**
 * Integration test for POST pipeline ordering.
 *
 * Spec: Request lifecycle POST (docs/Canonical_Spec.md#sec-request-lifecycle-post)
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';
require_once __DIR__ . '/../../src/Validation/Coercer.php';
require_once __DIR__ . '/../../src/Validation/Normalizer.php';
require_once __DIR__ . '/../../src/Validation/Validator.php';

$uploads_dir = eforms_test_setup_uploads( 'eforms-submit-uploads' );

$template_dir = eforms_test_tmp_root( 'eforms-submit-templates' );
mkdir( $template_dir, 0700, true );
eforms_test_write_basic_template( $template_dir, 'demo' );

Config::reset_for_tests();
StorageHealth::reset_for_tests();

$mint = Security::mint_hidden_record( 'demo' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
    'demo' => array(
        'name' => 'Ada',
    ),
);

$request = array(
    'post' => $post,
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
);

$trace = array();
$overrides = array(
    'template_base_dir' => $template_dir,
    'security' => function ( $post_data, $form_id, $request_data, $uploads_override ) use ( &$trace ) {
        $trace[] = 'security';
        return Security::token_validate( $post_data, $form_id, $request_data, $uploads_override );
    },
    'normalize' => function ( $context, $post_data, $files_data ) use ( &$trace ) {
        $trace[] = 'normalize';
        return NormalizerStage::normalize( $context, $post_data, $files_data );
    },
    'validate' => function ( $context, $normalized ) use ( &$trace ) {
        $trace[] = 'validate';
        return Validator::validate( $context, $normalized );
    },
    'coerce' => function ( $context, $validated ) use ( &$trace ) {
        $trace[] = 'coerce';
        return Coercer::coerce( $context, $validated );
    },
    'commit' => function () use ( &$trace ) {
        $trace[] = 'commit';
        return array( 'ok' => true, 'status' => 200, 'committed' => true );
    },
);

$result = SubmitHandler::handle( 'demo', $request, $overrides );

// Given a valid submission...
// When SubmitHandler runs...
// Then the pipeline order is security → normalize → validate → coerce → commit.
eforms_test_assert( $result['ok'] === true, 'SubmitHandler should return ok for the happy path.' );
eforms_test_assert(
    $trace === array( 'security', 'normalize', 'validate', 'coerce', 'commit' ),
    'Pipeline stages should execute in deterministic order.'
);

// Given a template whose id differs from its filename stem...
// When SubmitHandler loads the selected form...
// Then submission fails before security or side effects can run.
$mismatch_dir = eforms_test_tmp_root( 'eforms-submit-mismatch' );
mkdir( $mismatch_dir, 0700, true );
file_put_contents(
    $mismatch_dir . '/mismatch.json',
    json_encode(
        array(
            'id' => 'other',
            'version' => '1',
            'title' => 'Mismatch',
            'result_pages' => array(
                'success' => array(
                    'message' => 'Thanks.',
                ),
            ),
            'email' => array(
                'to' => 'demo@example.com',
                'subject' => 'Demo',
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
        )
    )
);
$mismatch_trace = array();
$mismatch_result = SubmitHandler::handle(
    'mismatch',
    array(
        'post' => array(
            'mismatch' => array( 'name' => 'Ada' ),
        ),
        'files' => array(),
    ),
    array(
        'template_base_dir' => $mismatch_dir,
        'security' => function () use ( &$mismatch_trace ) {
            $mismatch_trace[] = 'security';
            return array( 'token_ok' => true );
        },
    )
);
eforms_test_assert( $mismatch_result['ok'] === false, 'SubmitHandler should reject template id mismatches.' );
eforms_test_assert( $mismatch_result['error_code'] === 'EFORMS_ERR_SCHEMA_KEY', 'Submit mismatch should surface a deterministic config error.' );
eforms_test_assert( $mismatch_trace === array(), 'Submit mismatch should fail before security or side effects.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_remove_tree( $mismatch_dir );
