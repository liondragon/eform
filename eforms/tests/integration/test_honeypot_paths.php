<?php
/**
 * Integration tests for honeypot behavior.
 *
 * Spec: Honeypot (docs/Canonical_Spec.md#sec-honeypot)
 * Spec: Security (docs/Canonical_Spec.md#sec-security)
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

// Given a honeypot hit in stealth mode...
// When SubmitHandler runs...
// Then it short-circuits and mimics success, burning only with token_ok.
$uploads_dir = eforms_test_setup_uploads( 'eforms-honeypot' );
$template_dir = eforms_test_tmp_root( 'eforms-honeypot-template' );
mkdir( $template_dir, 0700, true );
eforms_test_write_template( $template_dir, 'demo' );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['honeypot_response'] = 'stealth_success';
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
    'eforms_hp' => 'bot',
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

$burn_calls = 0;
$overrides = array(
    'template_base_dir' => $template_dir,
    'trace' => true,
    'honeypot_burn' => function ( $form_id, $submission_id, $uploads_dir, $request_data, $config ) use ( &$burn_calls ) {
        $burn_calls += 1;
        return array( 'ok' => true, 'attempted' => true );
    },
);

$result = SubmitHandler::handle( 'demo', $request, $overrides );

eforms_test_assert( $result['ok'] === true, 'Stealth honeypot should return ok result.' );
eforms_test_assert( $result['errors'] === null, 'Stealth honeypot should not include errors.' );
eforms_test_assert(
    isset( $result['success'] ) && is_array( $result['success'] ),
    'Stealth honeypot should include success metadata.'
);
eforms_test_assert(
    isset( $result['success']['mode'] ) && $result['success']['mode'] === 'inline',
    'Stealth honeypot should mirror the template success mode.'
);
eforms_test_assert(
    isset( $result['form_id'] ) && $result['form_id'] === 'demo',
    'Stealth honeypot should include the form_id for success redirects.'
);
eforms_test_assert( $burn_calls === 1, 'Stealth honeypot should attempt ledger burn when token_ok.' );
eforms_test_assert(
    $result['trace'] === array( 'security', 'honeypot' ),
    'Honeypot should short-circuit before validation stages.'
);
if ( class_exists( 'Logging' ) ) {
    eforms_test_assert( count( Logging::$events ) === 1, 'Stealth honeypot should emit a log event.' );
    $event = Logging::$events[0];
    eforms_test_assert( $event['code'] === 'EFORMS_ERR_HONEYPOT', 'Honeypot log should use honeypot code.' );
    eforms_test_assert( ! empty( $event['meta']['honeypot'] ), 'Honeypot log should include honeypot flag.' );
    eforms_test_assert( ! empty( $event['meta']['stealth'] ), 'Stealth honeypot log should include stealth flag.' );
}

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );

// Given a honeypot hit in hard-fail mode...
// When SubmitHandler runs...
// Then it returns the honeypot error and skips burn when token is invalid.
$uploads_dir = eforms_test_setup_uploads( 'eforms-honeypot' );
$template_dir = eforms_test_tmp_root( 'eforms-honeypot-template' );
mkdir( $template_dir, 0700, true );
eforms_test_write_template( $template_dir, 'demo' );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['honeypot_response'] = 'hard_fail';
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
    'eforms_hp' => 'bot',
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

$burn_calls = 0;
$overrides = array(
    'template_base_dir' => $template_dir,
    'trace' => true,
    'honeypot_burn' => function ( $form_id, $submission_id, $uploads_dir, $request_data, $config ) use ( &$burn_calls ) {
        $burn_calls += 1;
        return array( 'ok' => true, 'attempted' => true );
    },
);

$result = SubmitHandler::handle( 'demo', $request, $overrides );
eforms_test_assert( $result['ok'] === false, 'Hard-fail honeypot should return error result.' );
eforms_test_assert( $result['status'] === 200, 'Hard-fail honeypot should use HTTP 200.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_HONEYPOT', 'Hard-fail honeypot should use honeypot error code.' );
eforms_test_assert( $burn_calls === 1, 'Hard-fail honeypot should attempt ledger burn when token_ok.' );

// Given an invalid token with honeypot hit...
// When SubmitHandler runs...
// Then it still responds with honeypot error and skips ledger burn.
$post['eforms_token'] = 'not-a-token';
$post['instance_id'] = 'not-an-instance';
$request['post'] = $post;

$burn_calls = 0;
$result = SubmitHandler::handle( 'demo', $request, $overrides );
eforms_test_assert( $result['ok'] === false, 'Invalid token honeypot should return error result.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_HONEYPOT', 'Invalid token honeypot should still return honeypot error.' );
eforms_test_assert( $burn_calls === 0, 'Invalid token honeypot must not attempt ledger burn.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );
