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
$throttle_stale_seconds = 2 * 24 * 60 * 60;

$token_expired_path = $private_dir . '/tokens/aa/expired.json';
$token_fresh_path = $private_dir . '/tokens/aa/fresh.json';
$token_expired_payload = json_encode( array( 'expires' => $now - 60 ) );
$token_fresh_payload = json_encode( array( 'expires' => $now + 600 ) );
eforms_test_gc_write_file( $token_expired_path, $token_expired_payload, $now - 100 );
eforms_test_gc_write_file( $token_fresh_path, $token_fresh_payload, $now - 100 );

$ledger_expired_path = $private_dir . '/ledger/demo/aa/expired.used';
$ledger_fresh_path = $private_dir . '/ledger/demo/aa/fresh.used';
eforms_test_gc_write_file( $ledger_expired_path, '1', $now - $token_ttl_max - $ledger_grace - 5 );
eforms_test_gc_write_file( $ledger_fresh_path, '1', $now - $token_ttl_max - $ledger_grace + 5 );

$upload_expired_path = $private_dir . '/uploads/20260101/expired.bin';
$upload_fresh_path = $private_dir . '/uploads/20260101/fresh.bin';
$upload_control_path = $private_dir . '/uploads/index.html';
eforms_test_gc_write_file( $upload_expired_path, str_repeat( 'a', 11 ), $now - 121 );
eforms_test_gc_write_file( $upload_fresh_path, str_repeat( 'b', 12 ), $now - 30 );
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

$expected_candidates = 6;
$expected_candidate_bytes =
    filesize( $token_expired_path ) +
    filesize( $ledger_expired_path ) +
    filesize( $upload_expired_path ) +
    filesize( $throttle_old_tally_path ) +
    filesize( $throttle_old_cooldown_path ) +
    filesize( $declined_expired_path );

$dry_run = GcRunner::run(
    array(
        'dry_run' => true,
        'now' => $now,
        'limit' => 500,
    )
);

eforms_test_assert( $dry_run['ok'] === true, 'Dry-run should succeed.' );
eforms_test_assert( $dry_run['dry_run'] === true, 'Dry-run result should be marked as dry_run.' );
eforms_test_assert( $dry_run['candidates'] === $expected_candidates, 'Dry-run should report the expected candidate count.' );
eforms_test_assert( $dry_run['candidate_bytes'] === $expected_candidate_bytes, 'Dry-run should report expected candidate bytes.' );
eforms_test_assert( $dry_run['deleted'] === 0, 'Dry-run must not delete files.' );
eforms_test_assert( $dry_run['by_type']['tokens']['candidates'] === 1, 'Dry-run should include one expired token.' );
eforms_test_assert( $dry_run['by_type']['ledger']['candidates'] === 1, 'Dry-run should include one expired ledger marker.' );
eforms_test_assert( $dry_run['by_type']['uploads']['candidates'] === 1, 'Dry-run should include one expired upload.' );
eforms_test_assert( $dry_run['by_type']['throttle']['candidates'] === 2, 'Dry-run should include stale throttle tally and cooldown files.' );
eforms_test_assert( $dry_run['by_type']['declined']['candidates'] === 1, 'Dry-run should include one expired declined-review file.' );

eforms_test_assert( file_exists( $token_expired_path ), 'Dry-run must keep expired token file.' );
eforms_test_assert( file_exists( $ledger_expired_path ), 'Dry-run must keep expired ledger marker.' );
eforms_test_assert( file_exists( $upload_expired_path ), 'Dry-run must keep expired upload file.' );
eforms_test_assert( file_exists( $throttle_old_tally_path ), 'Dry-run must keep stale throttle tally.' );
eforms_test_assert( file_exists( $throttle_old_cooldown_path ), 'Dry-run must keep stale cooldown sentinel.' );
eforms_test_assert( file_exists( $declined_expired_path ), 'Dry-run must keep expired declined-review file.' );

$apply = GcRunner::run(
    array(
        'now' => $now,
        'limit' => 500,
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
eforms_test_assert( ! file_exists( $upload_expired_path ), 'Expired upload should be deleted.' );
eforms_test_assert( file_exists( $upload_fresh_path ), 'Fresh upload should be preserved.' );
eforms_test_assert( file_exists( $upload_control_path ), 'Uploads control file should be preserved.' );
eforms_test_assert( ! file_exists( $throttle_old_tally_path ), 'Stale throttle tally should be deleted.' );
eforms_test_assert( file_exists( $throttle_fresh_tally_path ), 'Fresh throttle tally should be preserved.' );
eforms_test_assert( ! file_exists( $throttle_old_cooldown_path ), 'Stale cooldown sentinel should be deleted.' );
eforms_test_assert( ! file_exists( $declined_expired_path ), 'Expired declined-review file should be deleted.' );
eforms_test_assert( file_exists( $declined_fresh_path ), 'Fresh declined-review rotated file should be preserved.' );

$idempotent = GcRunner::run(
    array(
        'now' => $now,
        'limit' => 500,
    )
);

eforms_test_assert( $idempotent['ok'] === true, 'Idempotency run should succeed.' );
eforms_test_assert( $idempotent['candidates'] === 0, 'Second apply run should find no eligible files.' );
eforms_test_assert( $idempotent['deleted'] === 0, 'Second apply run should not delete files.' );

$lock_path = $private_dir . '/gc.lock';
$lock_handle = fopen( $lock_path, 'c+' );
eforms_test_assert( $lock_handle !== false, 'Test setup should open gc.lock.' );
$lock_ok = flock( $lock_handle, LOCK_EX | LOCK_NB );
eforms_test_assert( $lock_ok === true, 'Test setup should lock gc.lock.' );

$locked = GcRunner::run(
    array(
        'dry_run' => true,
        'now' => $now,
        'limit' => 500,
    )
);

eforms_test_assert( $locked['ok'] === false, 'Concurrent lock run should fail closed.' );
eforms_test_assert( $locked['locked'] === true, 'Concurrent lock run should report lock contention.' );

flock( $lock_handle, LOCK_UN );
fclose( $lock_handle );

Logging::reset_for_tests();
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
