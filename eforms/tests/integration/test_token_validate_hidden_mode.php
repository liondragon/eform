<?php
/**
 * Integration tests for hidden-mode token validation.
 *
 * Spec: Lifecycle quickstart (docs/Canonical_Spec.md#sec-lifecycle-quickstart)
 * Spec: Security (docs/Canonical_Spec.md#sec-security)
 * Spec: Origin policy (docs/Canonical_Spec.md#sec-origin-policy)
 * Spec: Timing checks (docs/Canonical_Spec.md#sec-timing-checks)
 * Spec: Spam decision (docs/Canonical_Spec.md#sec-spam-decision)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Helpers.php';
require_once __DIR__ . '/../../src/Security/Security.php';

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

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

// Given a valid hidden token record...
// When token_validate runs with matching metadata...
// Then it succeeds without soft reasons.
$uploads_dir = eforms_test_setup_uploads( 'eforms-token-validate' );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();

$mint = Security::mint_hidden_record( 'contact' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'js_ok' => '1',
);
$request = array(
    'headers' => array( 'Origin' => 'https://example.com' ),
);

$result = Security::token_validate( $post, 'contact', $request );
eforms_test_assert( $result['token_ok'] === true, 'Token should validate with matching record.' );
eforms_test_assert( $result['hard_fail'] === false, 'Token validation should not hard-fail.' );
eforms_test_assert( $result['mode'] === 'hidden', 'Token validation should return hidden mode.' );
eforms_test_assert( $result['submission_id'] === $mint['token'], 'Submission id should equal token.' );
eforms_test_assert( $result['soft_reasons'] === array(), 'Soft reasons should be empty for clean request.' );
eforms_test_assert( $result['require_challenge'] === false, 'Challenge should not be required by default.' );

eforms_test_remove_tree( $uploads_dir );

// Given a fast, aged, non-JS submission with missing Origin...
// When token_validate runs in auto challenge mode...
// Then it emits the full soft-reason set and requires a challenge.
$uploads_dir = eforms_test_setup_uploads( 'eforms-token-validate' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['min_fill_seconds'] = 10;
        $config['security']['max_form_age_seconds'] = 1;
        $config['security']['origin_mode'] = 'soft';
        $config['security']['origin_missing_hard'] = false;
        $config['security']['js_hard_mode'] = false;
        $config['challenge']['mode'] = 'auto';
        return $config;
    }
);
Config::reset_for_tests();

$mint = Security::mint_hidden_record( 'contact' );
$config = Config::get();
$record_path = $uploads_dir . '/eforms-private/tokens/' . Helpers::h2( $mint['token'] ) . '/' . hash( 'sha256', $mint['token'] ) . '.json';
$raw = file_get_contents( $record_path );
$decoded = json_decode( $raw, true );
$issued_at = time() - 5;
$decoded['issued_at'] = $issued_at;
$decoded['expires'] = $issued_at + $config['security']['token_ttl_seconds'];
file_put_contents( $record_path, json_encode( $decoded ) );
chmod( $record_path, 0600 );

$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
);
$result = Security::token_validate( $post, 'contact', array() );
eforms_test_assert( $result['token_ok'] === true, 'Token should validate for soft-fail scenario.' );
eforms_test_assert(
    $result['soft_reasons'] === array( 'min_fill_time', 'age_advisory', 'js_missing', 'origin_soft' ),
    'Soft reasons should be ordered and deduplicated.'
);
eforms_test_assert( $result['require_challenge'] === true, 'Challenge should be required for auto mode with soft reasons.' );

// Given an email retry marker...
// When token_validate runs...
// Then min_fill_time is bypassed.
$post['eforms_email_retry'] = '1';
$result = Security::token_validate( $post, 'contact', array() );
eforms_test_assert(
    $result['soft_reasons'] === array( 'age_advisory', 'js_missing', 'origin_soft' ),
    'Email retry should bypass min_fill_time.'
);

eforms_test_remove_tree( $uploads_dir );

// Given js_hard_mode with missing js_ok...
// When token_validate runs...
// Then it hard-fails.
$uploads_dir = eforms_test_setup_uploads( 'eforms-token-validate' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['js_hard_mode'] = true;
        $config['security']['origin_mode'] = 'off';
        return $config;
    }
);
Config::reset_for_tests();

$mint = Security::mint_hidden_record( 'contact' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
);
$result = Security::token_validate( $post, 'contact', array() );
eforms_test_assert( $result['hard_fail'] === true, 'js_hard_mode should hard-fail without js_ok.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_TOKEN', 'js_hard_mode should return token error.' );

eforms_test_remove_tree( $uploads_dir );

// Given hard origin mode with missing Origin...
// When token_validate runs...
// Then it hard-fails with origin forbidden.
$uploads_dir = eforms_test_setup_uploads( 'eforms-token-validate' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) {
        $config['security']['origin_mode'] = 'hard';
        $config['security']['origin_missing_hard'] = true;
        return $config;
    }
);
Config::reset_for_tests();

$mint = Security::mint_hidden_record( 'contact' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'js_ok' => '1',
);
$result = Security::token_validate( $post, 'contact', array() );
eforms_test_assert( $result['hard_fail'] === true, 'Missing Origin should hard-fail in hard mode.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_ORIGIN_FORBIDDEN', 'Origin hard-fail should use origin forbidden code.' );

eforms_test_remove_tree( $uploads_dir );

// Given a mismatched instance_id...
// When token_validate runs...
// Then it hard-fails.
$uploads_dir = eforms_test_setup_uploads( 'eforms-token-validate' );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();

$mint = Security::mint_hidden_record( 'contact' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => 'bad-instance',
    'js_ok' => '1',
);
$result = Security::token_validate( $post, 'contact', array() );
eforms_test_assert( $result['hard_fail'] === true, 'Mismatched instance id should hard-fail.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_TOKEN', 'Mismatched instance id should return token error.' );

eforms_test_remove_tree( $uploads_dir );
