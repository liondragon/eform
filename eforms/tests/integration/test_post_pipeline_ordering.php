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
            'title' => 'Demo',
            'success' => array(
                'mode' => 'inline',
                'message' => 'Thanks.',
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

$uploads_dir = eforms_test_tmp_root( 'eforms-submit-uploads' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$template_dir = eforms_test_tmp_root( 'eforms-submit-templates' );
mkdir( $template_dir, 0700, true );
eforms_test_write_template( $template_dir, 'demo' );

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

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
