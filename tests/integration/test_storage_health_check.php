<?php
/**
 * Integration tests for storage health check and private-dir hardening.
 *
 * Contract: Shared lifecycle and storage contract
 * Contract: Cache-safety
 * Contract: Security invariants
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';

// Given a writable uploads dir...
// When the storage health check runs...
// Then it succeeds and hardens the private directory.
$uploads_dir = eforms_test_tmp_root( 'eforms-storage-health' );
mkdir( $uploads_dir, 0700, true );

StorageHealth::reset_for_tests();
Logging::reset_for_tests();

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

// Given a retained purge barrier...
// Then live storage health probes fail closed without mutating private storage.
$uploads_dir = eforms_test_tmp_root( 'eforms-storage-health-purged' );
mkdir( $uploads_dir, 0700, true );
$private = PrivateDir::ensure( $uploads_dir );
file_put_contents( $private['path'] . '/' . PrivateDir::PURGE_MARKER_FILENAME, "purged\n" );

StorageHealth::reset_for_tests();
Logging::reset_for_tests();

$result = StorageHealth::check( $uploads_dir );
eforms_test_assert( $result['ok'] === false && $result['reason'] === 'managed_purged', 'Storage health should respect the retained purge barrier.' );
eforms_test_assert( count( glob( $private['path'] . '/.eforms-health-*' ) ) === 0, 'Storage health should not create probe directories behind a purge barrier.' );
eforms_test_remove_tree( $uploads_dir );

// Given symlinked private storage paths...
// Then PrivateDir fails closed instead of hardening or materializing through them.
if ( function_exists( 'symlink' ) ) {
    $uploads_dir = eforms_test_tmp_root( 'eforms-storage-health-symlink' );
    $target_dir = eforms_test_tmp_root( 'eforms-storage-health-symlink-target' );
    mkdir( $uploads_dir, 0700, true );
    mkdir( $target_dir, 0700, true );

    symlink( $target_dir, $uploads_dir . '/eforms-private' );
    $private = PrivateDir::ensure( $uploads_dir );
    eforms_test_assert( empty( $private['ok'] ), 'PrivateDir should reject a symlinked private root.' );
    eforms_test_assert( ! is_file( $target_dir . '/' . PrivateDir::INDEX_FILENAME ), 'PrivateDir should not write protection files through a symlinked private root.' );
    @unlink( $uploads_dir . '/eforms-private' );

    $private = PrivateDir::ensure( $uploads_dir );
    eforms_test_assert( ! empty( $private['ok'] ), 'PrivateDir should recover after the symlinked private root is removed.' );
    $file_target = $target_dir . '/linked-index.html';
    file_put_contents( $file_target, 'external' );
    @unlink( $private['path'] . '/' . PrivateDir::INDEX_FILENAME );
    symlink( $file_target, $private['path'] . '/' . PrivateDir::INDEX_FILENAME );
    $file_symlink_result = PrivateDir::ensure( $uploads_dir );
    eforms_test_assert( empty( $file_symlink_result['ok'] ) && $file_symlink_result['error'] === 'private_dir_index_failed', 'PrivateDir should reject symlinked deny-rule files.' );
    eforms_test_assert( file_get_contents( $file_target ) === 'external', 'PrivateDir should not chmod or rewrite a symlinked deny-rule target.' );
    @unlink( $private['path'] . '/' . PrivateDir::INDEX_FILENAME );
    PrivateDir::ensure( $uploads_dir );

    symlink( $target_dir, $private['path'] . '/staged' );
    eforms_test_assert( PrivateDir::protected_subdir( $uploads_dir, 'staged', true ) === '', 'PrivateDir should reject symlinked protected child dirs.' );
    $lease = PrivateDir::acquire_write_lease( $uploads_dir );
    eforms_test_assert( $lease instanceof PrivateDirLease, 'Symlink child-dir fixture should still acquire the private lifecycle lease.' );
    eforms_test_assert( PrivateDir::leased_subdir( $lease, 'staged', true, true ) === '', 'Lease-scoped child dirs should also reject symlinks.' );
    $lease->release();

    eforms_test_remove_tree( $uploads_dir );
    eforms_test_remove_tree( $target_dir );
}

// Given an uploads dir without write permissions...
// When the storage health check runs...
// Then it fails and logs only once per request.
$uploads_dir = eforms_test_tmp_root( 'eforms-storage-health' );
mkdir( $uploads_dir, 0700, true );
chmod( $uploads_dir, 0500 );

StorageHealth::reset_for_tests();
Logging::reset_for_tests();

$result = StorageHealth::check( $uploads_dir );
eforms_test_assert( $result['ok'] === false, 'Storage health check should fail for non-writable uploads.' );
eforms_test_assert( $result['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'Storage health should surface storage unavailable code.' );

StorageHealth::check( $uploads_dir );
eforms_test_assert( count( Logging::$events ) === 1, 'Storage health should log at most one warning per request.' );

chmod( $uploads_dir, 0700 );
eforms_test_remove_tree( $uploads_dir );
