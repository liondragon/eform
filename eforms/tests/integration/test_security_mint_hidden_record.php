<?php
/**
 * Integration tests for hidden-mode token minting.
 *
 * Spec: Hidden-mode contract (docs/Canonical_Spec.md#sec-hidden-mode)
 * Spec: Security invariants (docs/Canonical_Spec.md#sec-security-invariants)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Helpers.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
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

// Given a writable uploads dir...
// When minting a hidden-mode record...
// Then the token record is written with the canonical fields.
$uploads_dir = eforms_test_tmp_root( 'eforms-mint-hidden' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

Config::reset_for_tests();

$result = Security::mint_hidden_record( 'contact' );
eforms_test_assert( is_array( $result ) && isset( $result['ok'] ) && $result['ok'] === true, 'Mint should succeed for writable uploads.' );

$token = $result['token'];
$instance_id = $result['instance_id'];
$issued_at = $result['issued_at'];
$expires = $result['expires'];

eforms_test_assert( preg_match( '/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $token ) === 1, 'Token should be a UUIDv4.' );
eforms_test_assert( preg_match( '/^[A-Za-z0-9_-]{22,32}$/', $instance_id ) === 1, 'Instance id should be base64url (16-24 bytes).' );

$config = Config::get();
$ttl = $config['security']['token_ttl_seconds'];
eforms_test_assert( $expires === $issued_at + $ttl, 'Expires should equal issued_at + token_ttl_seconds.' );

$shard = Helpers::h2( $token );
$record_path = $uploads_dir . '/eforms-private/tokens/' . $shard . '/' . hash( 'sha256', $token ) . '.json';
eforms_test_assert( is_file( $record_path ), 'Token record should exist on disk.' );

$raw = file_get_contents( $record_path );
$decoded = json_decode( $raw, true );
eforms_test_assert( is_array( $decoded ), 'Token record JSON should decode.' );
eforms_test_assert( $decoded['mode'] === 'hidden', 'Token record mode should be hidden.' );
eforms_test_assert( $decoded['form_id'] === 'contact', 'Token record form_id should match.' );
eforms_test_assert( $decoded['instance_id'] === $instance_id, 'Token record instance_id should match.' );
eforms_test_assert( $decoded['issued_at'] === $issued_at, 'Token record issued_at should match.' );
eforms_test_assert( $decoded['expires'] === $expires, 'Token record expires should match.' );

$record_perms = fileperms( $record_path ) & 0777;
eforms_test_assert( $record_perms === 0600, 'Token record should be 0600.' );

$shard_perms = fileperms( dirname( $record_path ) ) & 0777;
eforms_test_assert( $shard_perms === 0700, 'Token shard dir should be 0700.' );

eforms_test_remove_tree( $uploads_dir );

// Given an uploads dir without write permissions...
// When minting a hidden-mode record...
// Then minting fails with a storage error.
$uploads_dir = eforms_test_tmp_root( 'eforms-mint-hidden' );
mkdir( $uploads_dir, 0700, true );
chmod( $uploads_dir, 0500 );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

Config::reset_for_tests();

$result = Security::mint_hidden_record( 'contact' );
eforms_test_assert( is_array( $result ) && isset( $result['ok'] ) && $result['ok'] === false, 'Mint should fail for unwritable uploads.' );
eforms_test_assert( isset( $result['code'] ) && $result['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'Mint failure should surface storage unavailable.' );

chmod( $uploads_dir, 0700 );
eforms_test_remove_tree( $uploads_dir );
