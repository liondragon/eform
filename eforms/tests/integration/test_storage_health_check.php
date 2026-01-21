<?php
/**
 * Integration tests for storage health check and private-dir hardening.
 *
 * Spec: Shared lifecycle and storage contract (docs/Canonical_Spec.md#sec-shared-lifecycle)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 * Spec: Security invariants (docs/Canonical_Spec.md#sec-security-invariants)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';

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
// When the storage health check runs...
// Then it succeeds and hardens the private directory.
$uploads_dir = eforms_test_tmp_root( 'eforms-storage-health' );
mkdir( $uploads_dir, 0700, true );

StorageHealth::reset_for_tests();
Logging::reset();

$result = StorageHealth::check( $uploads_dir );
eforms_test_assert( $result['ok'] === true, 'Storage health check should succeed for writable uploads.' );

$private_dir = $uploads_dir . '/eforms-private';
eforms_test_assert( is_dir( $private_dir ), 'Private directory should be created.' );
eforms_test_assert( is_file( $private_dir . '/index.html' ), 'Private index.html should exist.' );
eforms_test_assert( is_file( $private_dir . '/.htaccess' ), 'Private .htaccess should exist.' );
eforms_test_assert( is_file( $private_dir . '/web.config' ), 'Private web.config should exist.' );

$htaccess = file_get_contents( $private_dir . '/.htaccess' );
eforms_test_assert( is_string( $htaccess ) && strpos( $htaccess, 'Deny from all' ) !== false, 'Private .htaccess should deny access.' );

$webconfig = file_get_contents( $private_dir . '/web.config' );
eforms_test_assert( is_string( $webconfig ) && strpos( $webconfig, '<deny users="*"' ) !== false, 'Private web.config should deny access.' );

eforms_test_remove_tree( $uploads_dir );

// Given an uploads dir without write permissions...
// When the storage health check runs...
// Then it fails and logs only once per request.
$uploads_dir = eforms_test_tmp_root( 'eforms-storage-health' );
mkdir( $uploads_dir, 0700, true );
chmod( $uploads_dir, 0500 );

StorageHealth::reset_for_tests();
Logging::reset();

$result = StorageHealth::check( $uploads_dir );
eforms_test_assert( $result['ok'] === false, 'Storage health check should fail for non-writable uploads.' );
eforms_test_assert( $result['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'Storage health should surface storage unavailable code.' );

StorageHealth::check( $uploads_dir );
eforms_test_assert( count( Logging::$events ) === 1, 'Storage health should log at most one warning per request.' );

chmod( $uploads_dir, 0700 );
eforms_test_remove_tree( $uploads_dir );
