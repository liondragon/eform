<?php
/**
 * Integration test for GC dry-run, lock, eligibility, and idempotency.
 *
 * Contract: Uploads
 * Contract: Throttling
 * Contract: Anchors
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Anchors.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
require_once __DIR__ . '/../../src/Gc/GcRunner.php';

if ( ! function_exists( 'eforms_test_gc_write_file' ) ) {
    function eforms_test_gc_write_file( $path, $content, $mtime ) {
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0700, true );
        }

        file_put_contents( $path, $content );
        chmod( $path, 0600 );
        touch( $path, (int) $mtime );
    }
}

if ( ! function_exists( 'eforms_test_gc_finalize_submission' ) ) {
    function eforms_test_gc_finalize_submission( $uploads_dir, $submission_id, $suffix, $now ) {
        $secret = rtrim( strtr( base64_encode( str_repeat( "\x61", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
        $field = array(
            'type' => 'files',
            'upload_mode' => 'staged',
            'accept' => array( 'image' ),
            'max_file_bytes' => 1048576,
            'max_files' => 3,
            'max_total_bytes' => 3145728,
        );
        $binding = array(
            'raw_token' => 'gc-recovery-token-' . $suffix,
            'form_id' => 'gc-recovery-form',
            'instance_id' => 'gc-recovery-instance-' . $suffix,
            'field_key' => 'photos',
            'accept_until' => $now + 3600,
        );
        $created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
        eforms_test_assert( ! empty( $created['ok'] ), 'The GC recovery fixture should create a managed batch.' );
        $batch_id = $created['batch']['batch_id'];
        $claimed = UploadBatchStore::claim_finalization( $batch_id, $secret, $binding, $field, array(), $submission_id, $uploads_dir, $now );
        eforms_test_assert( ! empty( $claimed['ok'] ), 'The GC recovery fixture should claim its managed batch.' );
        $finalized = UploadBatchStore::finalize( $batch_id, $submission_id, $uploads_dir, $now );
        eforms_test_assert( ! empty( $finalized['ok'] ), 'The GC recovery fixture should finalize its managed batch.' );
    }
}

$uploads_dir = eforms_test_tmp_root( 'eforms-gc-dry-run-uploads' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['uploads']['retention_seconds'] = 120;
        $config['declined_review']['retention_days'] = 1;
        return $config;
    }
);

Config::reset_for_tests();
Logging::reset_for_tests();

$private = PrivateDir::ensure( $uploads_dir );
eforms_test_assert( is_array( $private ) && ! empty( $private['ok'] ), 'Private directory should be available for GC tests.' );
$private_dir = $private['path'];

$now = time();
$token_ttl_max = (int) Anchors::get( 'TOKEN_TTL_MAX' );
$ledger_grace = (int) Anchors::get( 'LEDGER_GC_GRACE_SECONDS' );
$throttle_stale_seconds = (int) Anchors::get( 'THROTTLE_STALE_SECONDS' );

$token_expired_path = $private_dir . '/tokens/aa/expired.json';
$token_fresh_path = $private_dir . '/tokens/aa/fresh.json';
$token_expired_payload = json_encode( array( 'expires' => $now - 60 ) );
$token_fresh_payload = json_encode( array( 'expires' => $now + 600 ) );
eforms_test_gc_write_file( $token_expired_path, $token_expired_payload, $now - 100 );
eforms_test_gc_write_file( $token_fresh_path, $token_fresh_payload, $now - 100 );

$ledger_expired_path = $private_dir . '/ledger/demo/aa/expired.used';
$ledger_fresh_path = $private_dir . '/ledger/demo/aa/fresh.used';
$ledger_root_lock_path = $private_dir . '/' . Ledger::ROOT_LOCK_FILENAME;
$ledger_protected_lock_path = $private_dir . '/ledger/demo/aa/' . Ledger::SHARD_LOCK_FILENAME;
$ledger_orphan_lock_path = $private_dir . '/ledger/abandoned/aa/' . Ledger::SHARD_LOCK_FILENAME;
eforms_test_gc_write_file( $ledger_expired_path, '1', $now - $token_ttl_max - $ledger_grace - 5 );
eforms_test_gc_write_file( $ledger_fresh_path, '1', $now - $token_ttl_max - $ledger_grace + 5 );
eforms_test_gc_write_file( $ledger_root_lock_path, '', $now - $token_ttl_max - $ledger_grace - 5 );
eforms_test_gc_write_file( $ledger_protected_lock_path, '', $now - $token_ttl_max - $ledger_grace - 5 );
eforms_test_gc_write_file( $ledger_orphan_lock_path, '', $now - $token_ttl_max - $ledger_grace - 5 );

$upload_expired_path = $private_dir . '/uploads/20260101/expired.bin';
$upload_fresh_path = $private_dir . '/uploads/20260101/fresh.bin';
$retention_recovery_submission = '123e4567-e89b-42d3-a456-426614174097';
$retention_recovery_path = $private_dir . '/uploads/' . Helpers::h2( $retention_recovery_submission ) . '/' . $retention_recovery_submission . '/' . $retention_recovery_submission . '-1-0-0123456789abcdef.pdf';
$upload_control_path = $private_dir . '/uploads/index.html';
eforms_test_gc_write_file( $upload_expired_path, str_repeat( 'a', 11 ), $now - 121 );
eforms_test_gc_write_file( $upload_fresh_path, str_repeat( 'b', 12 ), $now - 30 );
eforms_test_gc_write_file( $retention_recovery_path, 'recovery-within-finalized-ttl', $now - 121 );
eforms_test_gc_write_file( $upload_control_path, '<!doctype html><title></title>', $now - 86400 );

$throttle_old_tally_path = $private_dir . '/throttle/aa/old.tally';
$throttle_fresh_tally_path = $private_dir . '/throttle/aa/fresh.tally';
$throttle_old_cooldown_path = $private_dir . '/throttle/aa/old.cooldown';
eforms_test_gc_write_file( $throttle_old_tally_path, '111', $now - $throttle_stale_seconds - 10 );
eforms_test_gc_write_file( $throttle_fresh_tally_path, '1', $now - 100 );
eforms_test_gc_write_file( $throttle_old_cooldown_path, '', $now - $throttle_stale_seconds - 10 );

$declined_expired_path = $private_dir . '/declined/declined-20260101.jsonl';
$declined_fresh_path = $private_dir . '/declined/declined-20260102-1.jsonl';
eforms_test_gc_write_file( $declined_expired_path, '{"review_id":"old"}' . "\n", $now - 86500 );
eforms_test_gc_write_file( $declined_fresh_path, '{"review_id":"fresh"}' . "\n", $now - 100 );

$expected_candidates = 7;
$expected_candidate_bytes =
    filesize( $token_expired_path ) +
    filesize( $ledger_expired_path ) +
    filesize( $ledger_orphan_lock_path ) +
    filesize( $upload_expired_path ) +
    filesize( $throttle_old_tally_path ) +
    filesize( $throttle_old_cooldown_path ) +
    filesize( $declined_expired_path );

$dry_run = GcRunner::run(
    array(
        'dry_run' => true,
        'now' => $now,
    )
);

eforms_test_assert( $dry_run['ok'] === true, 'Dry-run should succeed.' );
eforms_test_assert( $dry_run['dry_run'] === true, 'Dry-run result should be marked as dry_run.' );
eforms_test_assert( $dry_run['candidates'] === $expected_candidates, 'Dry-run should report the expected candidate count.' );
eforms_test_assert( $dry_run['candidate_bytes'] === $expected_candidate_bytes, 'Dry-run should report expected candidate bytes.' );
eforms_test_assert( $dry_run['deleted'] === 0, 'Dry-run must not delete files.' );
eforms_test_assert( $dry_run['by_type']['tokens']['candidates'] === 1, 'Dry-run should include one expired token.' );
eforms_test_assert( $dry_run['by_type']['ledger']['candidates'] === 2, 'Dry-run should include one expired ledger marker and one orphan shard lock.' );
eforms_test_assert( $dry_run['by_type']['uploads']['candidates'] === 1, 'Dry-run should include one expired upload.' );
eforms_test_assert( $dry_run['by_type']['throttle']['candidates'] === 2, 'Dry-run should include stale throttle tally and cooldown files.' );
eforms_test_assert( $dry_run['by_type']['declined']['candidates'] === 1, 'Dry-run should include one expired declined-review file.' );

eforms_test_assert( file_exists( $token_expired_path ), 'Dry-run must keep expired token file.' );
eforms_test_assert( file_exists( $ledger_expired_path ), 'Dry-run must keep expired ledger marker.' );
eforms_test_assert( file_exists( $ledger_orphan_lock_path ), 'Dry-run must keep orphan ledger shard lock.' );
eforms_test_assert( file_exists( $upload_expired_path ), 'Dry-run must keep expired upload file.' );
eforms_test_assert( file_exists( $throttle_old_tally_path ), 'Dry-run must keep stale throttle tally.' );
eforms_test_assert( file_exists( $throttle_old_cooldown_path ), 'Dry-run must keep stale cooldown sentinel.' );
eforms_test_assert( file_exists( $declined_expired_path ), 'Dry-run must keep expired declined-review file.' );

$apply = GcRunner::run(
    array(
        'now' => $now,
    )
);

eforms_test_assert( $apply['ok'] === true, 'GC apply run should succeed.' );
eforms_test_assert( $apply['dry_run'] === false, 'Apply run should not be dry-run.' );
eforms_test_assert( $apply['deleted'] === $expected_candidates, 'Apply run should delete every eligible artifact.' );
eforms_test_assert( $apply['deleted_bytes'] === $expected_candidate_bytes, 'Apply run should report expected deleted bytes.' );

eforms_test_assert( ! file_exists( $token_expired_path ), 'Expired token should be deleted.' );
eforms_test_assert( file_exists( $token_fresh_path ), 'Unexpired token should be preserved.' );
eforms_test_assert( ! file_exists( $ledger_expired_path ), 'Expired ledger marker should be deleted.' );
eforms_test_assert( file_exists( $ledger_fresh_path ), 'Ledger marker within grace window should be preserved.' );
eforms_test_assert( ! file_exists( $ledger_orphan_lock_path ), 'Orphan ledger shard lock should be deleted.' );
eforms_test_assert( file_exists( $ledger_protected_lock_path ), 'Ledger shard lock with a live marker should be preserved.' );
eforms_test_assert( file_exists( $ledger_root_lock_path ), 'GC should retain the stable ledger-root guard.' );
eforms_test_assert( ! file_exists( $upload_expired_path ), 'Expired upload should be deleted.' );
eforms_test_assert( file_exists( $upload_fresh_path ), 'Fresh upload should be preserved.' );
eforms_test_assert( file_exists( $retention_recovery_path ), 'Recovery uploads should use finalized TTL instead of shorter ordinary retention.' );
eforms_test_assert( file_exists( $upload_control_path ), 'Uploads control file should be preserved.' );
eforms_test_assert( ! file_exists( $throttle_old_tally_path ), 'Stale throttle tally should be deleted.' );
eforms_test_assert( file_exists( $throttle_fresh_tally_path ), 'Fresh throttle tally should be preserved.' );
eforms_test_assert( ! file_exists( $throttle_old_cooldown_path ), 'Stale cooldown sentinel should be deleted.' );
eforms_test_assert( ! file_exists( $declined_expired_path ), 'Expired declined-review file should be deleted.' );
eforms_test_assert( file_exists( $declined_fresh_path ), 'Fresh declined-review rotated file should be preserved.' );

$idempotent = GcRunner::run(
    array(
        'now' => $now,
    )
);

eforms_test_assert( $idempotent['ok'] === true, 'Idempotency run should succeed.' );
eforms_test_assert( $idempotent['candidates'] === 0, 'Second apply run should find no eligible files.' );
eforms_test_assert( $idempotent['deleted'] === 0, 'Second apply run should not delete files.' );

$guarded_orphan_lock_path = $private_dir . '/ledger/guarded/aa/' . Ledger::SHARD_LOCK_FILENAME;
eforms_test_gc_write_file( $guarded_orphan_lock_path, '', $now - $token_ttl_max - $ledger_grace - 5 );
$ledger_root_handle = fopen( $ledger_root_lock_path, 'r+b' );
eforms_test_assert( is_resource( $ledger_root_handle ) && flock( $ledger_root_handle, LOCK_SH | LOCK_NB ), 'Fixture should hold a shared ledger-root guard.' );
eforms_test_assert( Ledger::delete_orphan_shard_lock( $guarded_orphan_lock_path, $private_dir ) === false, 'Orphan cleanup must not unlink a shard lock while a ledger operation holds the stable guard.' );
eforms_test_assert( file_exists( $guarded_orphan_lock_path ), 'Guarded orphan cleanup must preserve the existing lock inode.' );
flock( $ledger_root_handle, LOCK_UN );
fclose( $ledger_root_handle );
eforms_test_assert( Ledger::delete_orphan_shard_lock( $guarded_orphan_lock_path, $private_dir ) === true, 'Orphan cleanup may delete the shard lock after the stable guard is released.' );

$lock_path = $private_dir . '/gc.lock';
$lock_handle = fopen( $lock_path, 'c+' );
eforms_test_assert( $lock_handle !== false, 'Test setup should open gc.lock.' );
$lock_ok = flock( $lock_handle, LOCK_EX | LOCK_NB );
eforms_test_assert( $lock_ok === true, 'Test setup should lock gc.lock.' );

$locked = GcRunner::run(
    array(
        'dry_run' => true,
        'now' => $now,
    )
);

eforms_test_assert( $locked['ok'] === false, 'Concurrent lock run should fail closed.' );
eforms_test_assert( $locked['locked'] === true, 'Concurrent lock run should report lock contention.' );

flock( $lock_handle, LOCK_UN );
fclose( $lock_handle );
unlink( $lock_path );
if ( function_exists( 'symlink' ) ) {
    $outside_lock = eforms_test_write_file( $uploads_dir, 'outside-gc.lock', 'outside' );
    eforms_test_assert( symlink( $outside_lock, $lock_path ), 'Test setup should replace gc.lock with a symlink.' );
    $linked_lock = GcRunner::run(
        array(
            'dry_run' => true,
            'now' => $now,
        )
    );
    eforms_test_assert( $linked_lock['ok'] === false && $linked_lock['reason'] === 'gc_lock_open_failed', 'GC should fail closed on a symlinked lock file.' );
    eforms_test_assert( file_get_contents( $outside_lock ) === 'outside', 'GC should not open or chmod through a symlinked lock.' );
}

Logging::reset_for_tests();
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );

$zero_retention_uploads = eforms_test_setup_uploads( 'eforms-gc-recovery-zero-retention' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $zero_retention_uploads ) {
        $config['uploads']['dir'] = $zero_retention_uploads;
        $config['uploads']['retention_seconds'] = 0;
        return $config;
    }
);
Config::reset_for_tests();
$zero_private = PrivateDir::ensure( $zero_retention_uploads );
$recovery_submission = '123e4567-e89b-42d3-a456-426614174099';
$protected_submission = '123e4567-e89b-42d3-a456-426614174098';
$recovery_root = $zero_private['path'] . '/uploads/' . Helpers::h2( $recovery_submission ) . '/' . $recovery_submission;
$protected_root = $zero_private['path'] . '/uploads/' . Helpers::h2( $protected_submission ) . '/' . $protected_submission;
$recovery_old = $recovery_root . '/' . $recovery_submission . '-1-0-0123456789abcdef.pdf';
$recovery_fresh = $recovery_root . '/' . $recovery_submission . '-1-1-fedcba9876543210.pdf';
$recovery_protected = $protected_root . '/' . $protected_submission . '-1-0-0123456789abcdef.pdf';
$ordinary_zero_retention = $zero_private['path'] . '/uploads/20260101/ordinary.bin';
$recovery_ttl = Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' );
eforms_test_gc_write_file( $recovery_old, 'old-recovery', $now - $recovery_ttl - 1 );
eforms_test_gc_write_file( $recovery_fresh, 'fresh-recovery', $now - $recovery_ttl + 1 );
eforms_test_gc_finalize_submission( $zero_retention_uploads, $protected_submission, 'protected', $now );
eforms_test_gc_write_file( $recovery_protected, 'protected-recovery', $now - $recovery_ttl - 1 );
eforms_test_gc_write_file( $ordinary_zero_retention, 'ordinary', $now - $recovery_ttl - 1 );
$zero_dry = GcRunner::run( array( 'dry_run' => true, 'now' => $now ) );
eforms_test_assert( ! empty( $zero_dry['ok'] ) && $zero_dry['by_type']['uploads']['candidates'] === 1, 'Zero-retention GC should still discover one abandoned staged recovery file.' );
$zero_apply = GcRunner::run( array( 'now' => $now ) );
eforms_test_assert( ! empty( $zero_apply['ok'] ) && ! is_file( $recovery_old ), 'Zero-retention GC should reclaim an abandoned staged recovery file after the finalized TTL.' );
eforms_test_assert( is_file( $recovery_fresh ) && is_file( $ordinary_zero_retention ), 'Zero-retention GC should preserve fresh recovery files and ordinary immediate-retention files.' );
eforms_test_assert( is_file( $recovery_protected ), 'GC should preserve recovery files while their finalized submission aggregate exists.' );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $zero_retention_uploads );

if ( function_exists( 'symlink' ) ) {
    $scan_uploads = eforms_test_setup_uploads( 'eforms-gc-enumeration-failure' );
    eforms_test_set_filter(
        'eforms_config',
        function ( $config ) use ( $scan_uploads ) {
            $config['uploads']['dir'] = $scan_uploads;
            return $config;
        }
    );
    Config::reset_for_tests();
    $scan_private = PrivateDir::ensure( $scan_uploads );
    $outside = eforms_test_tmp_root( 'eforms-gc-enumeration-outside' );
    mkdir( $outside, 0700, true );
    eforms_test_assert( symlink( $outside, $scan_private['path'] . '/tokens' ), 'Enumeration failure fixture should link the token root outside managed storage.' );
    $scan_failure = GcRunner::run( array( 'now' => $now, 'limit' => 7 ) );
    eforms_test_assert( empty( $scan_failure['ok'] ) && $scan_failure['reason'] === 'tokens_directory_enumeration_failed', 'GC should report a file-family enumeration failure instead of treating it as an empty directory.' );

    eforms_test_assert( symlink( $outside, $scan_private['path'] . '/' . UploadBatchStore::STAGED_DIR ), 'Enumeration failure fixture should link the staged root outside managed storage.' );
    $aggregate_failure = UploadBatchStore::gc_aggregates( 'staged', $scan_uploads, $now, 1 );
    eforms_test_assert( empty( $aggregate_failure['ok'] ) && $aggregate_failure['reason'] === 'aggregate_enumeration_failed', 'Managed aggregate GC should report an invalid enumeration root.' );
    eforms_test_assert( count( scandir( $outside ) ) === 2, 'Enumeration failures must not traverse or mutate an external directory.' );

    eforms_test_set_filter( 'eforms_config', null );
    Config::reset_for_tests();
    eforms_test_remove_tree( $scan_uploads );
    eforms_test_remove_tree( $outside );
}
