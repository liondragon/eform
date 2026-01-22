<?php
/**
 * Integration test for POST size cap enforcement.
 *
 * Spec: POST size cap (docs/Canonical_Spec.md#sec-post-size-cap)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';

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

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['max_post_bytes'] = 10;
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();

$trace = array();
$overrides = array(
    'template_base_dir' => $template_dir,
    'security' => function () use ( &$trace ) {
        $trace[] = 'security';
        return array( 'token_ok' => true, 'hard_fail' => false );
    },
);

$request = array(
    'post' => array(),
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
    'content_length' => 2048,
);

$result = SubmitHandler::handle( 'demo', $request, $overrides );

// Given a request with Content-Length exceeding the cap...
// When SubmitHandler runs...
// Then it fails before running the Security gate.
eforms_test_assert( $result['ok'] === false, 'Oversized requests should fail early.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_TYPE', 'Oversized requests should use the generic type error.' );
eforms_test_assert( empty( $trace ), 'Security gate should not run when Content-Length exceeds the cap.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
