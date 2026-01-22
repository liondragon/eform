<?php
/**
 * Integration tests for ledger reservation semantics.
 *
 * Spec: Ledger reservation contract (docs/Canonical_Spec.md#sec-ledger-contract)
 * Spec: Request lifecycle POST (docs/Canonical_Spec.md#sec-request-lifecycle-post)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Helpers.php';
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

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

// Given a valid submission...
// When SubmitHandler reserves the ledger marker...
// Then the marker exists before side effects and duplicates return token errors.
$uploads_dir = eforms_test_setup_uploads( 'eforms-ledger' );
$template_dir = eforms_test_tmp_root( 'eforms-ledger-template' );
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
    ),
);

$request = array(
    'post' => $post,
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
);

$ledger_path = $uploads_dir . '/eforms-private/ledger/demo/' . Helpers::h2( $mint['token'] ) . '/' . $mint['token'] . '.used';
$commit_saw_ledger = false;
$overrides = array(
    'template_base_dir' => $template_dir,
    'commit' => function () use ( &$commit_saw_ledger, $ledger_path ) {
        $commit_saw_ledger = is_file( $ledger_path );
        return array( 'ok' => true, 'status' => 200, 'committed' => true );
    },
);

$result = SubmitHandler::handle( 'demo', $request, $overrides );
eforms_test_assert( $result['ok'] === true, 'Ledger reservation should allow the first submission.' );
eforms_test_assert( $commit_saw_ledger === true, 'Ledger marker should exist before commit side effects.' );
eforms_test_assert( is_file( $ledger_path ), 'Ledger marker should be created on success.' );

$dup = SubmitHandler::handle( 'demo', $request, $overrides );
eforms_test_assert( $dup['ok'] === false, 'Duplicate submissions should be rejected.' );
eforms_test_assert( $dup['error_code'] === 'EFORMS_ERR_TOKEN', 'Duplicate submissions should return token error.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );

// Given a ledger IO failure...
// When SubmitHandler attempts reservation...
// Then it hard-fails with EFORMS_ERR_LEDGER_IO and logs EFORMS_LEDGER_IO.
$uploads_dir = eforms_test_setup_uploads( 'eforms-ledger' );
$template_dir = eforms_test_tmp_root( 'eforms-ledger-template' );
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

$private_dir = $uploads_dir . '/eforms-private';
mkdir( $private_dir, 0700, true );
file_put_contents( $private_dir . '/ledger', 'block' );
chmod( $private_dir . '/ledger', 0600 );

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

$commit_called = false;
$overrides = array(
    'template_base_dir' => $template_dir,
    'commit' => function () use ( &$commit_called ) {
        $commit_called = true;
        return array( 'ok' => true, 'status' => 200, 'committed' => true );
    },
);

$result = SubmitHandler::handle( 'demo', $request, $overrides );
eforms_test_assert( $result['ok'] === false, 'Ledger IO failures should hard-fail submissions.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_LEDGER_IO', 'Ledger IO failures should map to EFORMS_ERR_LEDGER_IO.' );
eforms_test_assert( $commit_called === false, 'Commit should not run after ledger failure.' );
if ( class_exists( 'Logging' ) ) {
    $codes = array();
    foreach ( Logging::$events as $event ) {
        if ( isset( $event['code'] ) ) {
            $codes[] = $event['code'];
        }
    }
    eforms_test_assert(
        in_array( 'EFORMS_LEDGER_IO', $codes, true ),
        'Ledger IO failures should log EFORMS_LEDGER_IO.'
    );
}

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );
