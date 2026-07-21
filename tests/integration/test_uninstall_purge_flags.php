<?php
/**
 * Integration test for uninstall purge flag behavior.
 *
 * Contract: Architecture and file layout
 * Contract: Configuration
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Admin/AdminSettingsStore.php';
require_once __DIR__ . '/../../src/Gc/GcRunner.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/Throttle.php';
require_once __DIR__ . '/../../src/Submission/Ledger.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';
require_once __DIR__ . '/../../src/Uploads/UploadStore.php';

if ( ! function_exists( 'eforms_test_uninstall_write_file' ) ) {
    function eforms_test_uninstall_write_file( $path, $content = 'x' ) {
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0700, true );
        }

        file_put_contents( $path, $content );
        chmod( $path, 0600 );
    }
}

if ( ! function_exists( 'eforms_test_uninstall_seed_runtime' ) ) {
    function eforms_test_uninstall_seed_runtime( $uploads_dir ) {
        $private = PrivateDir::ensure( $uploads_dir );
        eforms_test_assert( is_array( $private ) && ! empty( $private['ok'] ), 'Test setup should create private directory.' );
        $private_dir = $private['path'];

        $paths = array(
            'token' => $private_dir . '/tokens/aa/token.json',
            'ledger' => $private_dir . '/ledger/contact/aa/submission.used',
            'upload' => $private_dir . '/uploads/aa/submission-id/file.bin',
            'throttle' => $private_dir . '/throttle/aa/ip.tally',
            'staged_manifest' => $private_dir . '/staged/aa/batch-id/manifest.json',
            'staged_lock' => $private_dir . '/staged/aa/batch-id' . UploadBatchStore::LOCK_FILENAME,
            'staged_original' => $private_dir . '/staged/aa/batch-id/files/photo/original.png',
            'staged_preview' => $private_dir . '/staged/aa/batch-id/files/photo/preview.jpg',
            'staged_partial' => $private_dir . '/staged/aa/batch-id/files/photo/original.pending.png',
            'final_manifest' => $private_dir . '/submissions/bb/submission-id/manifest.json',
            'final_lock' => $private_dir . '/submissions/bb/submission-id/' . UploadBatchStore::LOCK_FILENAME,
            'final_original' => $private_dir . '/submissions/bb/submission-id/files/photo/original.png',
            'final_preview' => $private_dir . '/submissions/bb/submission-id/files/photo/preview.jpg',
            'capacity' => $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME,
            'capacity_lock' => $private_dir . '/' . UploadBatchStore::CAPACITY_LOCK_FILENAME,
            'ledger_root_lock' => $private_dir . '/' . Ledger::ROOT_LOCK_FILENAME,
            'purge_marker' => $private_dir . '/' . PrivateDir::PURGE_MARKER_FILENAME,
            'lifecycle_lock' => $private_dir . '/' . PrivateDir::LIFECYCLE_LOCK_FILENAME,
            'log' => $private_dir . '/logs/events-20260101.jsonl',
            'declined' => $private_dir . '/declined/declined-20260101.jsonl',
            'declined_rotated' => $private_dir . '/declined/declined-20260101-1.jsonl',
            'f2b' => rtrim( $uploads_dir, '/\\' ) . '/f2b/eforms.log',
            'f2b_rotated' => rtrim( $uploads_dir, '/\\' ) . '/f2b/eforms.log.1',
            'sentinel' => rtrim( $uploads_dir, '/\\' ) . '/keep-me.txt',
        );

        eforms_test_uninstall_write_file( $paths['token'], '{}' );
        eforms_test_uninstall_write_file( $paths['ledger'], '1' );
        eforms_test_uninstall_write_file( $paths['upload'], 'payload' );
        eforms_test_uninstall_write_file( $paths['throttle'], '1' );
        eforms_test_uninstall_write_file( $paths['staged_manifest'], '{}' );
        eforms_test_uninstall_write_file( $paths['staged_lock'], '' );
        eforms_test_uninstall_write_file( $paths['staged_original'], 'original' );
        eforms_test_uninstall_write_file( $paths['staged_preview'], 'preview' );
        eforms_test_uninstall_write_file( $paths['staged_partial'], 'partial' );
        eforms_test_uninstall_write_file( $paths['final_manifest'], '{}' );
        eforms_test_uninstall_write_file( $paths['final_lock'], '' );
        eforms_test_uninstall_write_file( $paths['final_original'], 'original' );
        eforms_test_uninstall_write_file( $paths['final_preview'], 'preview' );
        eforms_test_uninstall_write_file( $paths['capacity'], '{"version":1,"total_bytes":31,"reservations":[]}' );
        eforms_test_uninstall_write_file( $paths['capacity_lock'], '' );
        eforms_test_uninstall_write_file( $paths['ledger_root_lock'], '' );
        eforms_test_uninstall_write_file( $paths['log'], '{"ok":true}' . "\n" );
        eforms_test_uninstall_write_file( $paths['declined'], '{"review_id":"a"}' . "\n" );
        eforms_test_uninstall_write_file( $paths['declined_rotated'], '{"review_id":"b"}' . "\n" );
        eforms_test_uninstall_write_file( $paths['f2b'], "eforms[f2b]\n" );
        eforms_test_uninstall_write_file( $paths['f2b_rotated'], "eforms[f2b]\n" );
        eforms_test_uninstall_write_file( $paths['sentinel'], 'keep' );

        return $paths;
    }
}

if ( ! function_exists( 'eforms_test_uninstall_run' ) ) {
    function eforms_test_uninstall_run( $uploads_dir, $purge_logs, $purge_uploads ) {
        update_option( AdminSettingsStore::OPTION_NAME, array( 'logging' => array( 'mode' => 'jsonl' ) ), false );

        eforms_test_set_filter(
            'eforms_config',
            function ( $config ) use ( $uploads_dir, $purge_logs, $purge_uploads ) {
                $config['uploads']['dir'] = $uploads_dir;
                $config['install']['uninstall']['purge_logs'] = (bool) $purge_logs;
                $config['install']['uninstall']['purge_uploads'] = (bool) $purge_uploads;
                $config['logging']['fail2ban']['file'] = 'f2b/eforms.log';
                return $config;
            }
        );

        Config::reset_for_tests();
        if ( function_exists( 'eforms_uninstall_run' ) ) {
            return eforms_uninstall_run();
        }

        require __DIR__ . '/../../uninstall.php';
        return isset( $eforms_uninstall_result ) ? $eforms_uninstall_result : array( 'ok' => true, 'reason' => '' );
    }
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    define( 'WP_UNINSTALL_PLUGIN', true );
}

$uploads_dir = eforms_test_tmp_root( 'eforms-uninstall-purge' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

// Case 1: Both flags disabled; nothing should be removed.
$case1 = eforms_test_uninstall_seed_runtime( $uploads_dir );
eforms_test_uninstall_run( $uploads_dir, false, false );
eforms_test_assert( file_exists( $case1['token'] ), 'Token file should remain when purge flags are disabled.' );
eforms_test_assert( file_exists( $case1['staged_original'] ) && file_exists( $case1['final_preview'] ), 'Managed aggregates should remain when purge flags are disabled.' );
eforms_test_assert( file_exists( $case1['capacity'] ) && file_exists( $case1['capacity_lock'] ), 'Managed capacity artifacts should remain when purge flags are disabled.' );
eforms_test_assert( file_exists( $case1['log'] ), 'Log file should remain when purge flags are disabled.' );
eforms_test_assert( file_exists( $case1['declined_rotated'] ), 'Declined review files should remain when purge flags are disabled.' );
eforms_test_assert( file_exists( $case1['f2b_rotated'] ), 'Fail2ban rotated file should remain when purge flags are disabled.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, null ) === null, 'Admin settings option should be deleted even when purge flags are disabled.' );

eforms_test_remove_tree( $uploads_dir . '/eforms-private' );
eforms_test_remove_tree( $uploads_dir . '/f2b' );

// Case 2: string-like purge flags should not broaden strict boolean config.
$case2 = eforms_test_uninstall_seed_runtime( $uploads_dir );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['install']['uninstall']['purge_logs'] = 'yes';
        $config['install']['uninstall']['purge_uploads'] = '1';
        $config['logging']['fail2ban']['file'] = 'f2b/eforms.log';
        return $config;
    }
);
Config::reset_for_tests();
eforms_uninstall_run();
eforms_test_assert( file_exists( $case2['log'] ) && file_exists( $case2['f2b'] ), 'String-like purge flags should not purge log artifacts.' );
eforms_test_assert( file_exists( $case2['token'] ) && file_exists( $case2['upload'] ) && file_exists( $case2['staged_original'] ), 'String-like purge flags should not purge upload artifacts.' );
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );
eforms_test_remove_tree( $uploads_dir . '/f2b' );

// Case 3: purge_logs=true should not traverse a symlinked private root.
if ( function_exists( 'symlink' ) ) {
    $outside_private = eforms_test_tmp_root( 'eforms-uninstall-linked-private' );
    mkdir( $outside_private . '/logs', 0700, true );
    eforms_test_uninstall_write_file( $outside_private . '/logs/events.jsonl', 'outside' );
    symlink( $outside_private, $uploads_dir . '/eforms-private' );
    eforms_test_uninstall_run( $uploads_dir, true, false );
    eforms_test_assert( is_link( $uploads_dir . '/eforms-private' ) && is_file( $outside_private . '/logs/events.jsonl' ), 'Log purge should not traverse a symlinked private root.' );
    @unlink( $uploads_dir . '/eforms-private' );
    eforms_test_remove_tree( $outside_private );
    eforms_test_remove_tree( $uploads_dir . '/f2b' );
}

// Case 4: purge_logs=true should remove logs + fail2ban artifacts only.
$case2 = eforms_test_uninstall_seed_runtime( $uploads_dir );
eforms_test_uninstall_run( $uploads_dir, true, false );
eforms_test_assert( ! file_exists( $case2['log'] ), 'Log file should be removed when purge_logs=true.' );
eforms_test_assert( ! file_exists( dirname( $case2['log'] ) ), 'Logs directory should be removed when purge_logs=true.' );
eforms_test_assert( ! file_exists( $case2['declined'] ), 'Declined review primary file should be removed when purge_logs=true.' );
eforms_test_assert( ! file_exists( $case2['declined_rotated'] ), 'Declined review rotated file should be removed when purge_logs=true.' );
eforms_test_assert( ! file_exists( dirname( $case2['declined'] ) ), 'Declined review directory should be removed when purge_logs=true.' );
eforms_test_assert( ! file_exists( $case2['f2b'] ), 'Fail2ban file should be removed when purge_logs=true.' );
eforms_test_assert( ! file_exists( $case2['f2b_rotated'] ), 'Fail2ban rotated siblings should be removed when purge_logs=true.' );
eforms_test_assert( file_exists( $case2['token'] ), 'Token file should remain when only purge_logs=true.' );
eforms_test_assert( file_exists( $case2['upload'] ), 'Upload file should remain when only purge_logs=true.' );
eforms_test_assert( file_exists( $case2['staged_manifest'] ) && file_exists( $case2['final_manifest'] ), 'Managed aggregate manifests should remain when only purge_logs=true.' );
eforms_test_assert( file_exists( $case2['capacity'] ) && file_exists( $case2['capacity_lock'] ), 'Managed capacity artifacts should remain when only purge_logs=true.' );
eforms_test_assert( file_exists( $case2['sentinel'] ), 'Unrelated files under uploads root must not be removed.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, null ) === null, 'Admin settings option should be deleted when purge_logs=true.' );

eforms_test_remove_tree( $uploads_dir . '/eforms-private' );
eforms_test_remove_tree( $uploads_dir . '/f2b' );

// Case 5: purge_uploads=true should remove non-log runtime artifacts only.
$case3 = eforms_test_uninstall_seed_runtime( $uploads_dir );
eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( ! file_exists( $case3['token'] ), 'Token file should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( dirname( $case3['token'] ) ), 'Tokens subtree should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['ledger'] ), 'Ledger markers should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['upload'] ), 'Upload files should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['throttle'] ), 'Throttle state should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['staged_manifest'] ) && ! file_exists( $case3['staged_original'] ) && ! file_exists( $case3['staged_preview'] ) && ! file_exists( $case3['staged_partial'] ), 'Staged manifests, originals, previews, and partials should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['final_manifest'] ) && ! file_exists( $case3['final_original'] ) && ! file_exists( $case3['final_preview'] ), 'Finalized aggregate files should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['staged_lock'] ) && ! file_exists( $case3['final_lock'] ), 'Purge should close aggregate handles before deleting their lock files and roots.' );
eforms_test_assert( ! file_exists( $case3['capacity'] ), 'Managed capacity accounting should be removed when purge_uploads=true.' );
eforms_test_assert( file_exists( $case3['capacity_lock'] ) && file_exists( $case3['ledger_root_lock'] ) && file_exists( $case3['lifecycle_lock'] ) && file_exists( $case3['purge_marker'] ), 'Successful purge should retain its synchronization inodes and lifecycle barrier for already-open requests.' );
eforms_test_assert( file_exists( $case3['log'] ), 'Logs should remain when purge_logs=false.' );
eforms_test_assert( file_exists( $case3['declined'] ), 'Declined review files should remain when purge_logs=false.' );
eforms_test_assert( file_exists( $case3['f2b'] ), 'Fail2ban file should remain when purge_logs=false.' );
eforms_test_assert( file_exists( $case3['sentinel'] ), 'Unrelated files under uploads root must not be removed.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, null ) === null, 'Admin settings option should be deleted when purge_uploads=true.' );

$blocked_mint = Security::mint_hidden_record( 'contact', $uploads_dir );
$blocked_ledger = Ledger::reserve( 'contact', '123e4567-e89b-12d3-a456-426614174000', $uploads_dir );
$blocked_upload = UploadStore::move_after_ledger(
    array( 'descriptors' => array( array( 'key' => 'attachment', 'type' => 'file' ) ) ),
    array( 'attachment' => array() ),
    '123e4567-e89b-12d3-a456-426614174000',
    $uploads_dir
);
$barrier_config = Config::get();
$barrier_config['throttle']['enable'] = true;
$barrier_config['throttle']['per_ip']['max_per_minute'] = 5;
$blocked_throttle = Throttle::check( array( 'client_ip' => '203.0.113.80' ), $barrier_config, $uploads_dir );
$blocked_gc = GcRunner::run( array( 'dry_run' => false, 'limit' => 1 ) );
eforms_test_assert( empty( $blocked_mint['ok'] ), 'The durable barrier should reject token writes after purge.' );
eforms_test_assert( empty( $blocked_ledger['ok'] ), 'The durable barrier should reject ledger writes after purge.' );
eforms_test_assert( empty( $blocked_upload['ok'] ), 'The durable barrier should reject synchronous upload writes after purge.' );
eforms_test_assert( empty( $blocked_throttle['ok'] ), 'The durable barrier should reject throttle writes after purge.' );
eforms_test_assert( empty( $blocked_gc['ok'] ) && $blocked_gc['reason'] === 'upload_lifecycle_unavailable', 'The durable barrier should reject upload GC mutation after purge.' );
eforms_test_assert( ! is_dir( dirname( dirname( $case3['token'] ) ) ) && ! is_dir( dirname( dirname( dirname( $case3['ledger'] ) ) ) ), 'Rejected post-purge writers must not recreate deleted token or ledger roots.' );

eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 4: an in-flight upload-family writer should block purge until retry.
$case4 = eforms_test_uninstall_seed_runtime( $uploads_dir );
$write_lease = PrivateDir::acquire_write_lease( $uploads_dir );
eforms_test_assert( $write_lease instanceof PrivateDirLease, 'Test setup should hold one shared upload lifecycle lease.' );
$contended_lifecycle = eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( empty( $contended_lifecycle['ok'] ) && $contended_lifecycle['reason'] === 'upload_lifecycle_unavailable', 'Lifecycle contention should make uninstall fail visibly.' );
eforms_test_assert( file_exists( $case4['token'] ) && file_exists( $case4['upload'] ) && file_exists( $case4['staged_original'] ), 'Lifecycle contention should preserve every upload-owned family.' );
eforms_test_assert( ! file_exists( $case4['purge_marker'] ), 'A purge skipped for an in-flight writer must not close the active runtime.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, null ) !== null, 'Failed uninstall should preserve settings for a retry.' );
$write_lease->release();
eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( ! file_exists( $case4['token'] ) && ! file_exists( $case4['upload'] ) && ! file_exists( $case4['staged_original'] ), 'A retry after the in-flight writer exits should complete the purge.' );
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 4b: purge-only managed locks require the exclusive lifecycle lease.
$case4b = eforms_test_uninstall_seed_runtime( $uploads_dir );
$shared_lease = PrivateDir::acquire_write_lease( $uploads_dir );
eforms_test_assert( $shared_lease instanceof PrivateDirLease, 'Test setup should hold a shared lifecycle lease.' );
eforms_test_assert( UploadBatchStore::acquire_purge_capacity_lock( $shared_lease ) === false, 'Managed purge capacity locks should reject shared lifecycle leases.' );
eforms_test_assert( UploadBatchStore::prelock_purge_aggregates( $shared_lease ) === false, 'Managed purge aggregate locks should reject shared lifecycle leases.' );
$shared_lease->release();
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 5: a contended managed-capacity lock should preserve upload state.
$case5 = eforms_test_uninstall_seed_runtime( $uploads_dir );
$capacity_lock_handle = fopen( $case5['capacity_lock'], 'c+b' );
eforms_test_assert( is_resource( $capacity_lock_handle ) && flock( $capacity_lock_handle, LOCK_EX | LOCK_NB ), 'Test setup should hold the managed-capacity lock.' );
$contended_capacity = eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( empty( $contended_capacity['ok'] ) && $contended_capacity['reason'] === 'managed_capacity_lock_unavailable', 'Capacity-lock contention should make uninstall fail visibly.' );
eforms_test_assert( file_exists( $case5['upload'] ) && file_exists( $case5['staged_original'] ) && file_exists( $case5['final_original'] ), 'Lock contention should preserve all upload-owned state.' );
eforms_test_assert( file_exists( $case5['capacity'] ), 'Lock contention should preserve managed capacity accounting.' );
flock( $capacity_lock_handle, LOCK_UN );
fclose( $capacity_lock_handle );
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 6: a symlinked managed-capacity lock should fail closed before purge mutation.
$case6 = eforms_test_uninstall_seed_runtime( $uploads_dir );
$outside_lock = eforms_test_write_file( $uploads_dir, 'outside-capacity.lock', 'outside' );
eforms_test_assert( unlink( $case6['capacity_lock'] ) && symlink( $outside_lock, $case6['capacity_lock'] ), 'Test setup should replace only the managed-capacity lock with a symlink.' );
$linked_capacity = eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( empty( $linked_capacity['ok'] ) && $linked_capacity['reason'] === 'managed_capacity_lock_unavailable', 'A symlinked capacity lock should make uninstall fail visibly.' );
eforms_test_assert( file_exists( $case6['upload'] ) && file_exists( $case6['staged_original'] ) && file_exists( $case6['final_original'] ), 'A symlinked managed-capacity lock should preserve all upload-owned state.' );
eforms_test_assert( is_link( $case6['capacity_lock'] ) && file_get_contents( $outside_lock ) === 'outside', 'Uninstall should not open or chmod through a symlinked managed-capacity lock.' );
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 7: aggregate lock contention should fail closed before any upload purge.
$case6 = eforms_test_uninstall_seed_runtime( $uploads_dir );
$aggregate_lock_handle = fopen( $case6['staged_lock'], 'r+b' );
eforms_test_assert( is_resource( $aggregate_lock_handle ) && flock( $aggregate_lock_handle, LOCK_EX | LOCK_NB ), 'Test setup should hold one managed aggregate lock.' );
$contended_aggregate = eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( empty( $contended_aggregate['ok'] ) && $contended_aggregate['reason'] === 'managed_aggregate_lock_unavailable', 'Aggregate-lock contention should make uninstall fail visibly.' );
eforms_test_assert( file_exists( $case6['token'] ) && file_exists( $case6['upload'] ), 'Aggregate lock contention should preserve non-aggregate upload state.' );
eforms_test_assert( file_exists( $case6['staged_original'] ) && file_exists( $case6['final_original'] ), 'Aggregate lock contention should preserve every managed aggregate.' );
eforms_test_assert( file_exists( $case6['capacity'] ) && file_exists( $case6['capacity_lock'] ), 'Aggregate lock contention should preserve managed capacity accounting and its synchronization inode.' );
eforms_test_assert( ! file_exists( $case6['purge_marker'] ), 'A skipped purge must not block the still-active managed runtime.' );
flock( $aggregate_lock_handle, LOCK_UN );
fclose( $aggregate_lock_handle );
$aggregate_retry = eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( ! empty( $aggregate_retry['ok'] ), 'A retry after aggregate-lock contention should complete uninstall.' );
eforms_test_assert( ! file_exists( $case6['staged_original'] ) && ! file_exists( $case6['final_original'] ), 'A retry after the aggregate writer exits should complete the purge.' );
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 8: incomplete managed-root deletion must retain capacity accounting.
$case7 = eforms_test_uninstall_seed_runtime( $uploads_dir );
$staged_root = dirname( dirname( dirname( $case7['staged_manifest'] ) ) );
chmod( $staged_root, 0500 );
$incomplete_purge = eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( empty( $incomplete_purge['ok'] ) && $incomplete_purge['reason'] === 'upload_purge_incomplete', 'Incomplete managed-root deletion should make uninstall fail visibly.' );
eforms_test_assert( file_exists( $staged_root ), 'A non-writable managed root should remain after an incomplete purge.' );
eforms_test_assert( file_exists( $case7['capacity'] ), 'Incomplete managed-root deletion should retain capacity accounting.' );
eforms_test_assert( file_exists( $case7['purge_marker'] ) && file_exists( $case7['lifecycle_lock'] ) && file_exists( $case7['capacity_lock'] ), 'An incomplete purge should retain the lifecycle barrier and synchronization inodes.' );
chmod( $staged_root, 0700 );
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 9: purge_logs=true should still clean fail2ban family even if private dir is absent.
$f2b_only = rtrim( $uploads_dir, '/\\' ) . '/f2b/eforms.log';
$f2b_only_rotated = $f2b_only . '.1';
eforms_test_uninstall_write_file( $f2b_only, "eforms[f2b]\n" );
eforms_test_uninstall_write_file( $f2b_only_rotated, "eforms[f2b]\n" );
eforms_test_uninstall_run( $uploads_dir, true, false );
eforms_test_assert( ! file_exists( $f2b_only ), 'Fail2ban file should be removed even when private dir is missing.' );
eforms_test_assert( ! file_exists( $f2b_only_rotated ), 'Fail2ban rotated siblings should be removed even when private dir is missing.' );
eforms_test_assert( file_exists( $case3['sentinel'] ), 'Unrelated files under uploads root must not be removed when only fail2ban cleanup runs.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
