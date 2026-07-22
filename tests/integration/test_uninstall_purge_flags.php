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
        $now = 1700000000;
        $secret = rtrim( strtr( base64_encode( str_repeat( 'P', Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
        $field = array(
            'type' => 'files',
            'upload_mode' => 'staged',
            'accept' => array( 'image' ),
            'max_file_bytes' => 1048576,
            'max_files' => 1,
            'max_total_bytes' => 1048576,
        );
        $staged_binding = array(
            'raw_token' => 'uninstall-purge-staged-token',
            'form_id' => 'uninstall-purge-form',
            'instance_id' => 'uninstall-purge-staged-instance',
            'field_key' => 'photos',
            'accept_until' => $now + 3600,
        );
        $staged = UploadBatchStore::create_batch( $staged_binding, $secret, $field, $uploads_dir, $now );
        eforms_test_assert( ! empty( $staged['ok'] ), 'Test setup should create one valid retained staged aggregate.' );
        $final_binding = $staged_binding;
        $final_binding['raw_token'] = 'uninstall-purge-final-token';
        $final_binding['instance_id'] = 'uninstall-purge-final-instance';
        $final = UploadBatchStore::create_batch( $final_binding, $secret, $field, $uploads_dir, $now );
        eforms_test_assert( ! empty( $final['ok'] ), 'Test setup should create the aggregate that will become a valid retained submission.' );
        $submission_id = '123e4567-e89b-12d3-a456-426614174111';
        $claimed = UploadBatchStore::claim_finalization(
            $final['batch']['batch_id'],
            $secret,
            $final_binding,
            $field,
            array(),
            $submission_id,
            $uploads_dir,
            $now + 1
        );
        eforms_test_assert( ! empty( $claimed['ok'] ), 'Test setup should claim the retained submission aggregate.' );
        $finalized = UploadBatchStore::finalize( $final['batch']['batch_id'], $submission_id, $uploads_dir, $now + 2 );
        eforms_test_assert( ! empty( $finalized['ok'] ), 'Test setup should finalize one valid retained submission aggregate.' );
        $staged_id = $staged['batch']['batch_id'];

        $paths = array(
            'token' => $private_dir . '/tokens/aa/token.json',
            'ledger' => $private_dir . '/ledger/contact/aa/submission.used',
            'upload' => $private_dir . '/uploads/aa/submission-id/file.bin',
            'throttle' => $private_dir . '/throttle/aa/ip.tally',
            'staged_manifest' => $private_dir . '/staged/' . Helpers::h2( $staged_id ) . '/' . $staged_id . '/manifest.json',
            'staged_lock' => UploadBatchStore::aggregate_lock_path(
                UploadBatchStore::STAGED_DIR,
                $private_dir . '/staged/' . Helpers::h2( $staged_id ) . '/' . $staged_id
            ),
            'final_manifest' => $private_dir . '/submissions/' . Helpers::h2( $submission_id ) . '/' . $submission_id . '/manifest.json',
            'final_lock' => $private_dir . '/submissions/' . Helpers::h2( $submission_id ) . '/' . $submission_id . '/' . UploadBatchStore::LOCK_FILENAME,
            'artifact' => $private_dir . '/' . UploadBatchStore::ARTIFACTS_DIR . '/cc/object-id/version.artifact',
            'artifact_temp' => $private_dir . '/' . UploadBatchStore::ARTIFACTS_DIR . '/cc/object-id/.version.tmp',
            'preview_cache' => $private_dir . '/' . UploadBatchStore::PREVIEW_CACHE_DIR . '/cc/cache-id/preview.jpg',
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
        eforms_test_uninstall_write_file( $paths['artifact'], 'authoritative-artifact' );
        eforms_test_uninstall_write_file( $paths['artifact_temp'], 'incomplete-artifact' );
        eforms_test_uninstall_write_file( $paths['preview_cache'], "\xff\xd8\xff\xd9" );
        eforms_test_uninstall_write_file(
            $paths['capacity'],
            json_encode(
                array(
                    'version' => UploadBatchStore::CAPACITY_VERSION,
                    'total_bytes' => 0,
                    'reservations' => array(),
                    'releases' => array(),
                    'updated_at' => $now + 2,
                )
            )
        );
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
    function eforms_test_uninstall_run( $uploads_dir, $purge_logs, $purge_uploads, $options = array() ) {
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
            return eforms_uninstall_run( $options );
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
eforms_test_assert( file_exists( $case1['staged_manifest'] ) && file_exists( $case1['final_manifest'] ) && file_exists( $case1['artifact'] ) && file_exists( $case1['preview_cache'] ), 'Managed aggregates, artifacts, and optional previews should remain when purge flags are disabled.' );
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
eforms_test_assert( file_exists( $case2['token'] ) && file_exists( $case2['upload'] ) && file_exists( $case2['artifact'] ), 'String-like purge flags should not purge upload artifacts.' );
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
eforms_test_assert( ! file_exists( $case3['staged_manifest'] ) && ! file_exists( $case3['final_manifest'] ), 'Staged and finalized aggregate files should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['artifact'] ) && ! file_exists( $case3['artifact_temp'] ), 'Committed and incomplete authoritative artifacts should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['preview_cache'] ), 'Optional local preview caches should be removed when purge_uploads=true.' );
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
eforms_test_assert( file_exists( $case4['token'] ) && file_exists( $case4['upload'] ) && file_exists( $case4['artifact'] ), 'Lifecycle contention should preserve every upload-owned family.' );
eforms_test_assert( ! file_exists( $case4['purge_marker'] ), 'A purge skipped for an in-flight writer must not close the active runtime.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, null ) !== null, 'Failed uninstall should preserve settings for a retry.' );
$write_lease->release();
eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( ! file_exists( $case4['token'] ) && ! file_exists( $case4['upload'] ) && ! file_exists( $case4['artifact'] ), 'A retry after the in-flight writer exits should complete the purge.' );
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 4b: purge-only managed locks require the exclusive lifecycle lease.
$case4b = eforms_test_uninstall_seed_runtime( $uploads_dir );
$shared_lease = PrivateDir::acquire_write_lease( $uploads_dir );
eforms_test_assert( $shared_lease instanceof PrivateDirLease, 'Test setup should hold a shared lifecycle lease.' );
eforms_test_assert( UploadBatchStore::acquire_purge_capacity_lock( $shared_lease ) === false, 'Managed purge capacity locks should reject shared lifecycle leases.' );
eforms_test_assert( UploadBatchStore::prelock_purge_aggregates( $shared_lease ) === false, 'Managed purge aggregate locks should reject shared lifecycle leases.' );
$shared_lease->release();
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 4c: purge prelock should fence a lockless staged partial under the exclusive lease.
$partial_uploads_dir = eforms_test_tmp_root( 'eforms-uninstall-lockless-partial' );
mkdir( $partial_uploads_dir, 0700, true );
$partial_private = PrivateDir::ensure( $partial_uploads_dir );
eforms_test_assert( is_array( $partial_private ) && ! empty( $partial_private['ok'] ), 'Lockless partial setup should create private storage.' );
$partial_batch_id = str_repeat( 'A', Anchors::get( 'MANAGED_BATCH_ID_CHARS' ) );
$partial_path = $partial_private['path'] . '/staged/' . Helpers::h2( $partial_batch_id ) . '/' . $partial_batch_id;
mkdir( $partial_path, 0700, true );
$partial_lease = PrivateDir::acquire_purge_lease( $partial_uploads_dir );
eforms_test_assert( $partial_lease instanceof PrivateDirLease, 'Lockless partial setup should acquire the exclusive lifecycle lease.' );
$partial_locks = UploadBatchStore::prelock_purge_aggregates( $partial_lease );
eforms_test_assert( is_array( $partial_locks ) && count( $partial_locks ) === 1, 'Purge prelock should create and hold the missing staged sibling lock for a lockless partial.' );
UploadBatchStore::release_purge_locks( $partial_locks );
$partial_lease->release();
eforms_test_assert( is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $partial_path ) ), 'Lockless partial prelock should leave the owner-owned staged sibling lock inode.' );
eforms_test_remove_tree( $partial_uploads_dir );

// Case 5: a contended managed-capacity lock should preserve upload state.
$case5 = eforms_test_uninstall_seed_runtime( $uploads_dir );
$capacity_lock_handle = fopen( $case5['capacity_lock'], 'c+b' );
eforms_test_assert( is_resource( $capacity_lock_handle ) && flock( $capacity_lock_handle, LOCK_EX | LOCK_NB ), 'Test setup should hold the managed-capacity lock.' );
$contended_capacity = eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( empty( $contended_capacity['ok'] ) && $contended_capacity['reason'] === 'managed_capacity_lock_unavailable', 'Capacity-lock contention should make uninstall fail visibly.' );
eforms_test_assert( file_exists( $case5['upload'] ) && file_exists( $case5['staged_manifest'] ) && file_exists( $case5['artifact'] ), 'Lock contention should preserve all upload-owned state.' );
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
eforms_test_assert( file_exists( $case6['upload'] ) && file_exists( $case6['staged_manifest'] ) && file_exists( $case6['artifact'] ), 'A symlinked managed-capacity lock should preserve all upload-owned state.' );
eforms_test_assert( is_link( $case6['capacity_lock'] ) && file_get_contents( $outside_lock ) === 'outside', 'Uninstall should not open or chmod through a symlinked managed-capacity lock.' );
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 7: aggregate lock contention should fail closed before any upload purge.
$case6 = eforms_test_uninstall_seed_runtime( $uploads_dir );
$aggregate_lock_handle = fopen( $case6['staged_lock'], 'r+b' );
eforms_test_assert( is_resource( $aggregate_lock_handle ) && flock( $aggregate_lock_handle, LOCK_EX | LOCK_NB ), 'Test setup should hold one managed aggregate lock.' );
$contended_aggregate = eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( empty( $contended_aggregate['ok'] ) && $contended_aggregate['reason'] === 'managed_aggregate_lock_unavailable', 'Aggregate-lock contention should make uninstall fail visibly.' );
eforms_test_assert( file_exists( $case6['token'] ) && file_exists( $case6['upload'] ), 'Aggregate lock contention should preserve non-aggregate upload state.' );
eforms_test_assert( file_exists( $case6['staged_manifest'] ) && file_exists( $case6['final_manifest'] ) && file_exists( $case6['artifact'] ), 'Aggregate lock contention should preserve every managed aggregate and artifact.' );
eforms_test_assert( file_exists( $case6['capacity'] ) && file_exists( $case6['capacity_lock'] ), 'Aggregate lock contention should preserve managed capacity accounting and its synchronization inode.' );
eforms_test_assert( ! file_exists( $case6['purge_marker'] ), 'A skipped purge must not block the still-active managed runtime.' );
flock( $aggregate_lock_handle, LOCK_UN );
fclose( $aggregate_lock_handle );
$aggregate_retry = eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( ! empty( $aggregate_retry['ok'] ), 'A retry after aggregate-lock contention should complete uninstall.' );
eforms_test_assert( ! file_exists( $case6['staged_manifest'] ) && ! file_exists( $case6['final_manifest'] ) && ! file_exists( $case6['artifact'] ), 'A retry after the aggregate writer exits should complete the purge.' );
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

// Case 9: failure to remove capacity accounting must stop uninstall.
$case9 = eforms_test_uninstall_seed_runtime( $uploads_dir );
$capacity_remove_attempted = false;
$capacity_failure = eforms_test_uninstall_run(
    $uploads_dir,
    false,
    true,
    array(
        'remove_tree' => function ( $path ) use ( $case9, &$capacity_remove_attempted ) {
            if ( $path === $case9['capacity'] ) {
                $capacity_remove_attempted = true;
                return;
            }
            eforms_uninstall_remove_tree( $path );
        },
    )
);
eforms_test_assert( $capacity_remove_attempted, 'The capacity-ledger failure fixture should reach the ledger-removal window.' );
eforms_test_assert( empty( $capacity_failure['ok'] ) && $capacity_failure['reason'] === 'upload_purge_incomplete', 'A retained managed-capacity ledger should make uninstall fail visibly.' );
eforms_test_assert( file_exists( $case9['capacity'] ), 'Failed managed-capacity deletion should retain the ledger for diagnosis and retry.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, null ) !== null, 'Failed capacity-ledger deletion should preserve settings for a retry.' );
eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 10: a broken upload-root symlink must remain visible when unlink fails.
if ( function_exists( 'symlink' ) ) {
    $case10 = eforms_test_uninstall_seed_runtime( $uploads_dir );
    $tokens_root = dirname( dirname( $case10['token'] ) );
    eforms_test_remove_tree( $tokens_root );
    eforms_test_assert( symlink( $uploads_dir . '/missing-token-root', $tokens_root ), 'The broken-root failure fixture should create a broken tokens symlink.' );
    $linked_remove_attempted = false;
    $linked_root_failure = eforms_test_uninstall_run(
        $uploads_dir,
        false,
        true,
        array(
            'remove_tree' => function ( $path ) use ( $tokens_root, &$linked_remove_attempted ) {
                if ( $path === $tokens_root ) {
                    $linked_remove_attempted = true;
                    return;
                }
                eforms_uninstall_remove_tree( $path );
            },
        )
    );
    eforms_test_assert( $linked_remove_attempted, 'The broken-root failure fixture should reach tokens-root removal.' );
    eforms_test_assert( empty( $linked_root_failure['ok'] ) && $linked_root_failure['reason'] === 'upload_purge_incomplete', 'A retained broken upload-root symlink should make uninstall fail visibly.' );
    eforms_test_assert( is_link( $tokens_root ), 'A failed broken-root unlink should remain available for diagnosis and retry.' );
    eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, null ) !== null, 'Failed broken-root deletion should preserve settings for a retry.' );
    eforms_test_remove_tree( $uploads_dir . '/eforms-private' );
}

// Case 11: purge_logs=true should still clean fail2ban family even if private dir is absent.
$f2b_only = rtrim( $uploads_dir, '/\\' ) . '/f2b/eforms.log';
$f2b_only_rotated = $f2b_only . '.1';
$f2b_only_backup = $f2b_only . '.bak';
eforms_test_uninstall_write_file( $f2b_only, "eforms[f2b]\n" );
eforms_test_uninstall_write_file( $f2b_only_rotated, "eforms[f2b]\n" );
eforms_test_uninstall_write_file( $f2b_only_backup, "operator backup\n" );
eforms_test_uninstall_run( $uploads_dir, true, false );
eforms_test_assert( ! file_exists( $f2b_only ), 'Fail2ban file should be removed even when private dir is missing.' );
eforms_test_assert( ! file_exists( $f2b_only_rotated ), 'Fail2ban rotated siblings should be removed even when private dir is missing.' );
eforms_test_assert( file_exists( $f2b_only_backup ), 'Fail2ban uninstall cleanup should preserve non-owned dot-suffix files.' );
eforms_test_assert( file_exists( $case3['sentinel'] ), 'Unrelated files under uploads root must not be removed when only fail2ban cleanup runs.' );
eforms_test_remove_tree( $uploads_dir . '/f2b' );

// Case 12: relative fail2ban paths must not escape uploads during uninstall cleanup.
$outside_f2b_dir = eforms_test_tmp_root( 'eforms-uninstall-outside-f2b' );
$outside_f2b = $outside_f2b_dir . '/eforms.log';
$outside_f2b_rotated = $outside_f2b . '.1';
eforms_test_uninstall_write_file( $outside_f2b, "outside\n" );
eforms_test_uninstall_write_file( $outside_f2b_rotated, "outside rotated\n" );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir, $outside_f2b_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['install']['uninstall']['purge_logs'] = true;
        $config['install']['uninstall']['purge_uploads'] = false;
        $config['logging']['fail2ban']['file'] = '../' . basename( $outside_f2b_dir ) . '/eforms.log';
        return $config;
    }
);
Config::reset_for_tests();
eforms_uninstall_run();
eforms_test_assert( file_exists( $outside_f2b ) && file_exists( $outside_f2b_rotated ), 'Uninstall should not follow relative Fail2ban paths outside uploads.dir.' );
eforms_test_remove_tree( $outside_f2b_dir );

// Case 13: absolute fail2ban paths are operator-managed directories during cleanup.
$absolute_f2b_dir = eforms_test_tmp_root( 'eforms-uninstall-absolute-f2b' );
$absolute_f2b = $absolute_f2b_dir . '/eforms.log';
$absolute_f2b_rotated = $absolute_f2b . '.1';
mkdir( $absolute_f2b_dir, 0755, true );
eforms_test_uninstall_write_file( $absolute_f2b, "outside\n" );
eforms_test_uninstall_write_file( $absolute_f2b_rotated, "outside rotated\n" );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir, $absolute_f2b ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['install']['uninstall']['purge_logs'] = true;
        $config['install']['uninstall']['purge_uploads'] = false;
        $config['logging']['fail2ban']['file'] = $absolute_f2b;
        return $config;
    }
);
Config::reset_for_tests();
eforms_uninstall_run();
eforms_test_assert( is_dir( $absolute_f2b_dir ), 'Uninstall should preserve absolute Fail2ban parent directories.' );
eforms_test_assert( ! file_exists( $absolute_f2b ) && ! file_exists( $absolute_f2b_rotated ), 'Uninstall should remove only the absolute Fail2ban file family.' );
eforms_test_remove_tree( $absolute_f2b_dir );

// Case 14: absolute fail2ban paths remain operator-managed even inside uploads.dir.
$absolute_inside_f2b_dir = rtrim( $uploads_dir, '/\\' ) . '/absolute-f2b';
$absolute_inside_f2b = $absolute_inside_f2b_dir . '/eforms.log';
$absolute_inside_f2b_rotated = $absolute_inside_f2b . '.1';
mkdir( $absolute_inside_f2b_dir, 0755, true );
eforms_test_uninstall_write_file( $absolute_inside_f2b, "inside absolute\n" );
eforms_test_uninstall_write_file( $absolute_inside_f2b_rotated, "inside absolute rotated\n" );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir, $absolute_inside_f2b ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['install']['uninstall']['purge_logs'] = true;
        $config['install']['uninstall']['purge_uploads'] = false;
        $config['logging']['fail2ban']['file'] = $absolute_inside_f2b;
        return $config;
    }
);
Config::reset_for_tests();
eforms_uninstall_run();
eforms_test_assert( is_dir( $absolute_inside_f2b_dir ), 'Uninstall should preserve absolute Fail2ban parent directories even under uploads.dir.' );
eforms_test_assert( ! file_exists( $absolute_inside_f2b ) && ! file_exists( $absolute_inside_f2b_rotated ), 'Uninstall should still remove the absolute Fail2ban file family under uploads.dir.' );
eforms_test_remove_tree( $absolute_inside_f2b_dir );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
