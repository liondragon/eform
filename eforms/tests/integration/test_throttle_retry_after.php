<?php
/**
 * Integration test for throttle Retry-After and entrypoint semantics.
 *
 * Spec: Throttling (docs/Canonical_Spec.md#sec-throttling)
 * Spec: Security (docs/Canonical_Spec.md#sec-security)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';
require_once __DIR__ . '/../../src/Security/MintEndpoint.php';
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
            'title' => 'Throttle Demo',
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

$uploads_dir = eforms_test_tmp_root( 'eforms-throttle-uploads' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$template_dir = eforms_test_tmp_root( 'eforms-throttle-templates' );
mkdir( $template_dir, 0700, true );
eforms_test_write_template( $template_dir, 'demo' );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['throttle']['enable'] = true;
        $config['throttle']['per_ip']['max_per_minute'] = 1;
        $config['throttle']['per_ip']['cooldown_seconds'] = 120;
        return $config;
    }
);

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

// Given hidden-mode render throttling...
// When the same IP mints twice in one window...
// Then the second render returns the generic inline throttled error.
Config::reset_for_tests();
StorageHealth::reset_for_tests();
FormRenderer::reset_for_tests();
$_SERVER['REMOTE_ADDR'] = '203.0.113.41';

$first_render = FormRenderer::render( 'contact' );
eforms_test_assert( strpos( $first_render, 'name="eforms_token"' ) !== false, 'Hidden-mode GET should render token fields before throttle limit.' );

FormRenderer::reset_for_tests();
$second_render = FormRenderer::render( 'contact' );
eforms_test_assert( strpos( $second_render, 'data-eforms-error="EFORMS_ERR_THROTTLED"' ) !== false, 'Hidden-mode throttle hard-fail should render throttled code.' );
eforms_test_assert( strpos( $second_render, 'Please wait a moment and try again.' ) !== false, 'Hidden-mode throttle hard-fail should use generic retry messaging.' );

// Given /eforms/mint throttling...
// When requests exceed the fixed window...
// Then the endpoint responds 429 with Retry-After.
Config::reset_for_tests();
$mint_request = array(
    'method' => 'POST',
    'headers' => array(
        'Origin' => 'https://example.com',
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
    'params' => array( 'f' => 'contact' ),
    'client_ip' => '203.0.113.42',
);

$mint_first = MintEndpoint::handle( $mint_request );
eforms_test_assert( $mint_first['status'] === 200, '/eforms/mint first request should succeed.' );

$mint_second = MintEndpoint::handle( $mint_request );
eforms_test_assert( $mint_second['status'] === 429, '/eforms/mint should return 429 after limit.' );
eforms_test_assert( $mint_second['body']['error'] === 'EFORMS_ERR_THROTTLED', '/eforms/mint should return throttled error code.' );
eforms_test_assert( isset( $mint_second['headers']['Retry-After'] ), '/eforms/mint throttle response should include Retry-After header.' );
eforms_test_assert( (int) $mint_second['headers']['Retry-After'] >= 1, '/eforms/mint Retry-After should be a positive integer.' );

// Given POST submit throttling...
// When Security gate is over the limit...
// Then SubmitHandler fails with 429 before normalize/validate.
Config::reset_for_tests();
$submit_ip = '203.0.113.43';
$mint = Security::mint_hidden_record( 'demo', $uploads_dir, array( 'client_ip' => $submit_ip ) );
eforms_test_assert( is_array( $mint ) && ! empty( $mint['ok'] ), 'Hidden mint should succeed before throttled POST check.' );

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
        'Origin' => 'https://example.com',
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
    'client_ip' => $submit_ip,
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

$submit_result = SubmitHandler::handle( 'demo', $request, $overrides );
eforms_test_assert( $submit_result['ok'] === false, 'POST throttle hard-fail should not succeed.' );
eforms_test_assert( $submit_result['status'] === 429, 'POST throttle hard-fail should return 429.' );
eforms_test_assert( $submit_result['error_code'] === 'EFORMS_ERR_THROTTLED', 'POST throttle hard-fail should return throttled error code.' );
eforms_test_assert( isset( $submit_result['headers']['Retry-After'] ), 'POST throttle hard-fail should include Retry-After header in result.' );
eforms_test_assert( (int) $submit_result['headers']['Retry-After'] >= 1, 'POST Retry-After should be a positive integer.' );
eforms_test_assert( $trace === array( 'security' ), 'POST throttle hard-fail should stop before normalize/validate/coerce.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
StorageHealth::reset_for_tests();
FormRenderer::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
