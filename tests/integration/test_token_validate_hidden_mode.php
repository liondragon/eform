<?php
/**
 * Integration tests for hidden-mode token validation.
 *
 * Contract: Lifecycle quickstart
 * Contract: Security
 * Contract: Origin policy
 * Contract: Timing checks
 * Contract: Spam decision
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Helpers.php';
require_once __DIR__ . '/../../src/Security/Security.php';

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return isset( $GLOBALS['eforms_test_home_url'] ) && is_string( $GLOBALS['eforms_test_home_url'] )
            ? $GLOBALS['eforms_test_home_url']
            : 'https://example.com';
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
    'eforms_hp' => '',
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

// Given a forged request Host that matches Origin but not the site origin...
// When token_validate runs in soft origin mode...
// Then the token stays structurally valid but the canonical origin owner emits origin_soft.
$uploads_dir = eforms_test_setup_uploads( 'eforms-token-validate' );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
$_SERVER['HTTP_HOST'] = 'evil.test';
$GLOBALS['eforms_test_home_url'] = 'https://example.com';

$mint = Security::mint_hidden_record( 'contact' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'js_ok' => '1',
    'eforms_hp' => '',
);
$request = array(
    'headers' => array( 'Origin' => 'https://evil.test' ),
);

$result = Security::token_validate( $post, 'contact', $request );
eforms_test_assert( $result['token_ok'] === true, 'Forged Host should not invalidate the token record.' );
eforms_test_assert( $result['hard_fail'] === false, 'Forged Host should remain a soft origin signal by default.' );
eforms_test_assert( $result['soft_reasons'] === array( 'origin_soft' ), 'Forged Host should emit only origin_soft.' );
$_SERVER['HTTP_HOST'] = 'example.com';
unset( $GLOBALS['eforms_test_home_url'] );
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
    $result['soft_reasons'] === array( 'min_fill_time', 'age_advisory', 'honeypot_missing', 'js_missing', 'origin_soft' ),
    'Soft reasons should be ordered and deduplicated.'
);
eforms_test_assert( $result['require_challenge'] === true, 'Challenge should be required for auto mode with soft reasons.' );

// Given a stale email retry marker...
// When token_validate runs...
// Then min_fill_time is not bypassed because email failures now use result-page PRG.
$post['eforms_email_retry'] = '1';
$result = Security::token_validate( $post, 'contact', array() );
eforms_test_assert(
    $result['soft_reasons'] === array( 'min_fill_time', 'age_advisory', 'honeypot_missing', 'js_missing', 'origin_soft' ),
    'Stale email retry marker should not bypass min_fill_time.'
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
    'eforms_hp' => '',
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
    'eforms_hp' => '',
);
$result = Security::token_validate( $post, 'contact', array() );
eforms_test_assert( $result['hard_fail'] === true, 'Mismatched instance id should hard-fail.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_TOKEN', 'Mismatched instance id should return token error.' );

eforms_test_remove_tree( $uploads_dir );
