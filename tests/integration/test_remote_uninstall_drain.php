<?php
/**
 * Integration proof for the persisted Worker/R2 uninstall drain.
 *
 * Contract: Runtime Storage Uninstall Purge Contract
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/managed_upload_fixtures.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Admin/AdminSettingsStore.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';
require_once __DIR__ . '/../../src/Uploads/WorkerClient.php';

define( 'EFORMS_UPLOAD_COMPOSITION', 'worker_r2_cloudflare' );
define( 'EFORMS_WORKER_URL', 'https://media.example.test' );
define( 'EFORMS_WORKER_ENVIRONMENT_ID', 'remote-uninstall-test' );
define( 'EFORMS_WORKER_ACTIVE_KEY_ID', 'key-current' );
define( 'EFORMS_WORKER_ACTIVE_KEY_B64', rtrim( strtr( base64_encode( str_repeat( 'U', Anchors::get( 'WORKER_INTEGRATION_KEY_BYTES' ) ) ), '+/', '-_' ), '=' ) );
define( 'WP_UNINSTALL_PLUGIN', true );

$uploads_dir = eforms_test_setup_uploads( 'eforms-remote-uninstall' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['install']['uninstall']['purge_uploads'] = true;
        $config['install']['uninstall']['purge_logs'] = false;
        return $config;
    }
);
Config::reset_for_tests();

$field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 1048576,
    'max_files' => 2,
    'max_total_bytes' => 2097152,
);
$binding = array(
    'raw_token' => 'remote-uninstall-token',
    'form_id' => 'virtual-estimate',
    'instance_id' => 'remote-uninstall-instance',
    'field_key' => 'project_photos',
    'accept_until' => 1700003600,
);
$batch_secret = eforms_test_managed_batch_secret( 'B' );
$worker_uploads = eforms_test_setup_uploads( 'eforms-remote-uninstall-candidate' );
$worker_now = 1710000000;
$worker_staged = eforms_test_uninstall_worker_item( $worker_uploads, 'staged', $field, $worker_now, false );
$worker_finalized = eforms_test_uninstall_worker_item( $worker_uploads, 'finalized', $field, $worker_now + 100, true );
$worker_capacity_only = eforms_test_uninstall_worker_capacity_only( $worker_uploads, 'capacity-only', $field, $worker_now + 200 );
$worker_lease = PrivateDir::acquire_purge_lease( $worker_uploads );
eforms_test_assert( $worker_lease instanceof PrivateDirLease, 'Worker uninstall fixture should acquire its exclusive lifecycle lease.' );
$worker_prepared = UploadBatchStore::prepare_worker_remote_purge(
    $worker_lease,
    WorkerClient::composition_fingerprint(),
    $worker_now + 300
);
$worker_drain = Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' )
    + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' )
    + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' )
    + 1;
$worker_expected_safe_after = max(
    $worker_now + 300,
    $worker_staged['validation_until'],
    $worker_finalized['validation_until'],
    $worker_capacity_only['validation_until']
) + $worker_drain;
eforms_test_assert(
    ! empty( $worker_prepared['ok'] )
        && empty( $worker_prepared['ready'] )
        && $worker_prepared['safe_after'] === $worker_expected_safe_after,
    'Worker uninstall preparation should wait through every retained validation_until plus Worker grant/upload/skew drain.'
);
$worker_capacity_path = $worker_lease->private_dir() . '/' . UploadBatchStore::CAPACITY_FILENAME;
$worker_capacity_before_mismatch = eforms_test_managed_capacity_record( $worker_uploads );
$worker_capacity_mismatch = $worker_capacity_before_mismatch;
$worker_capacity_mismatch['total_bytes'] += 1;
$worker_staged_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $worker_staged['path'] );
$worker_preproof_dir_mode = 0700;
$worker_preproof_lock_mode = 0600;
eforms_test_assert( @chmod( $worker_staged['path'], $worker_preproof_dir_mode ), 'The remote purge mismatch fixture should lower aggregate permissions.' );
eforms_test_assert( @chmod( $worker_staged_lock_path, $worker_preproof_lock_mode ), 'The remote purge mismatch fixture should lower aggregate-lock permissions.' );
eforms_test_assert( ManagedCapacityStore::write( $worker_capacity_path, $worker_capacity_mismatch ), 'The remote purge mismatch fixture should persist mismatched capacity.' );
$worker_mismatch_calls = 0;
$worker_mismatch = UploadBatchStore::resume_worker_remote_purge(
    $worker_lease,
    WorkerClient::composition_fingerprint(),
    function () use ( &$worker_mismatch_calls ) {
        $worker_mismatch_calls++;
        return array( 'ok' => true, 'absent' => true );
    },
    $worker_prepared['safe_after']
);
eforms_test_assert(
    empty( $worker_mismatch['ok'] )
        && $worker_mismatch['reason'] === 'remote_state_changed'
        && $worker_mismatch_calls === 0
        && ( @fileperms( $worker_staged['path'] ) & 0777 ) === $worker_preproof_dir_mode
        && ( @fileperms( $worker_staged_lock_path ) & 0777 ) === $worker_preproof_lock_mode
        && eforms_test_managed_capacity_record( $worker_uploads ) === $worker_capacity_mismatch,
    'Worker remote purge must fail before provider deletion or permission repair when capacity exceeds exact aggregate authority.'
);
eforms_test_assert( @chmod( $worker_staged['path'], PrivateDir::DIRECTORY_MODE ), 'The remote purge mismatch fixture should restore aggregate permissions.' );
eforms_test_assert( @chmod( $worker_staged_lock_path, PrivateDir::FILE_MODE ), 'The remote purge mismatch fixture should restore aggregate-lock permissions.' );
eforms_test_assert( ManagedCapacityStore::write( $worker_capacity_path, $worker_capacity_before_mismatch ), 'The remote purge mismatch fixture should restore exact capacity.' );
$worker_failed_calls = array();
$worker_failure = UploadBatchStore::resume_worker_remote_purge(
    $worker_lease,
    WorkerClient::composition_fingerprint(),
    function ( $authority ) use ( &$worker_failed_calls ) {
        $worker_failed_calls[] = $authority;
        return array( 'ok' => false, 'reason' => 'provider_unavailable' );
    },
    $worker_prepared['safe_after']
);
$worker_record_path = $worker_lease->private_dir() . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME;
$worker_record_after_failure = json_decode( file_get_contents( $worker_record_path ), true );
eforms_test_assert(
    empty( $worker_failure['ok'] )
        && $worker_failure['reason'] === 'remote_delete_failed'
        && $worker_failed_calls === array( $worker_staged['authority'] )
        && is_array( $worker_record_after_failure )
        && $worker_record_after_failure['next_family'] === UploadBatchStore::STAGED_DIR
        && $worker_record_after_failure['last_failure_class'] === 'provider_failure'
        && is_dir( $worker_staged['path'] )
        && eforms_test_managed_capacity_record( $worker_uploads )['total_bytes'] === $worker_staged['authority']['bytes'] + $worker_finalized['authority']['bytes'] + $worker_capacity_only['authority']['bytes'],
    'Worker uninstall should stop at the first provider failure without releasing exact capacity or advancing its cursor.'
);
$worker_deleted = array();
$worker_locks_free = true;
$worker_paths = array(
    $worker_staged['authority']['object_key'] => array( UploadBatchStore::STAGED_DIR, $worker_staged['path'] ),
    $worker_finalized['authority']['object_key'] => array( UploadBatchStore::SUBMISSIONS_DIR, $worker_finalized['path'] ),
);
$worker_delete = function ( $authority ) use ( &$worker_deleted, &$worker_locks_free, $worker_uploads, $worker_paths ) {
    $expected_keys = array(
        'upload_id', 'storage_identity', 'expected_composition_fingerprint', 'validation_contract_version',
        'object_key', 'object_version', 'etag', 'bytes', 'policy_fingerprint',
    );
    if ( isset( $authority['validation_until'] ) ) {
        $expected_keys[] = 'validation_until';
    }
    eforms_test_assert( array_keys( $authority ) === $expected_keys, 'Worker uninstall should pass exact v3 delete authority only.' );
    $capacity_lock = ManagedCapacityStore::acquire_lock( $worker_uploads . '/eforms-private/' . UploadBatchStore::CAPACITY_LOCK_FILENAME, true, true, true );
    $aggregate_free = true;
    if ( isset( $worker_paths[ $authority['object_key'] ] ) ) {
        $path = $worker_paths[ $authority['object_key'] ];
        $aggregate_lock = ManagedCapacityStore::acquire_lock( UploadBatchStore::aggregate_lock_path( $path[0], $path[1] ), true, true, true );
        $aggregate_free = is_resource( $aggregate_lock );
        if ( is_resource( $aggregate_lock ) ) {
            flock( $aggregate_lock, LOCK_UN );
            fclose( $aggregate_lock );
        }
    }
    $worker_locks_free = $worker_locks_free && is_resource( $capacity_lock ) && $aggregate_free;
    if ( is_resource( $capacity_lock ) ) {
        flock( $capacity_lock, LOCK_UN );
        fclose( $capacity_lock );
    }
    $worker_deleted[] = $authority;
    return array( 'ok' => true, 'absent' => true );
};
$worker_resume = array( 'ok' => true, 'ready' => false );
for ( $attempt = 0; $attempt < 5 && empty( $worker_resume['ready'] ); $attempt++ ) {
    $worker_resume = UploadBatchStore::resume_worker_remote_purge(
        $worker_lease,
        WorkerClient::composition_fingerprint(),
        $worker_delete,
        $worker_prepared['safe_after'] + 1 + $attempt
    );
    eforms_test_assert( ! empty( $worker_resume['ok'] ), 'Worker uninstall resume should retain the existing purge cursor and barrier: ' . json_encode( $worker_resume ) );
}
$worker_final_record = json_decode( file_get_contents( $worker_record_path ), true );
$worker_final_capacity = eforms_test_managed_capacity_record( $worker_uploads );
eforms_test_assert(
    ! empty( $worker_resume['ready'] )
        && $worker_deleted === array( $worker_staged['authority'], $worker_finalized['authority'], $worker_capacity_only['authority'] )
        && $worker_locks_free
        && is_array( $worker_final_record )
        && $worker_final_record['next_family'] === 'done'
        && is_array( $worker_final_capacity )
        && $worker_final_capacity['total_bytes'] === 0
        && $worker_final_capacity['reservations'] === array()
        && ! is_dir( $worker_staged['path'] )
        && ! is_dir( $worker_finalized['path'] )
        && ! is_file( $worker_finalized['review_snapshot_path'] )
        && ! is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $worker_finalized['path'] ) ),
    'Worker uninstall should delete staged, finalized, and capacity-only authorities exactly once outside locks before releasing local evidence.'
);
$worker_lease->release();
eforms_test_remove_tree( $worker_uploads );

$corrupt_uploads_dir = eforms_test_setup_uploads( 'eforms-remote-uninstall-corrupt-local' );
$corrupt_created = UploadBatchStore::create_batch(
    $binding,
    $batch_secret,
    $field,
    $corrupt_uploads_dir,
    1700000000,
    FormProtocol::UPLOAD_TRANSPORT_LOCAL
);
eforms_test_assert( ! empty( $corrupt_created['ok'] ), 'Corrupt-manifest uninstall fixture should begin with one valid local aggregate.' );
$corrupt_manifest_path = $corrupt_uploads_dir . '/eforms-private/staged/'
    . Helpers::h2( $corrupt_created['batch']['batch_id'] ) . '/'
    . $corrupt_created['batch']['batch_id'] . '/manifest.json';
$corrupt_manifest = json_decode( file_get_contents( $corrupt_manifest_path ), true );
unset( $corrupt_manifest['binding'] );
file_put_contents( $corrupt_manifest_path, json_encode( $corrupt_manifest, JSON_UNESCAPED_SLASHES ) );
$corrupt_lease = PrivateDir::acquire_purge_lease( $corrupt_uploads_dir );
eforms_test_assert( $corrupt_lease instanceof PrivateDirLease, 'Corrupt-manifest uninstall fixture should acquire its exclusive lifecycle lease.' );
$corrupt_present = UploadBatchStore::remote_artifacts_present( $corrupt_lease );
eforms_test_assert(
    empty( $corrupt_present['ok'] ) && $corrupt_present['reason'] === 'manifest_invalid',
    'Uninstall must validate the complete manifest before deciding that no remote drain is required.'
);
$corrupt_lease->release();
eforms_test_remove_tree( $corrupt_uploads_dir );

$worker_v6_uploads_dir = eforms_test_setup_uploads( 'eforms-remote-uninstall-worker-v6' );
$worker_v6_created = UploadBatchStore::create_batch(
    $binding,
    $batch_secret,
    $field,
    $worker_v6_uploads_dir,
    1700000000,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    WorkerClient::composition_fingerprint()
);
eforms_test_assert( ! empty( $worker_v6_created['ok'] ), 'Worker-v6 uninstall fixture should begin with one valid Worker aggregate.' );
$worker_v6_manifest_path = $worker_v6_uploads_dir . '/eforms-private/staged/'
    . Helpers::h2( $worker_v6_created['batch']['batch_id'] ) . '/'
    . $worker_v6_created['batch']['batch_id'] . '/manifest.json';
$worker_v6_manifest = json_decode( file_get_contents( $worker_v6_manifest_path ), true );
$worker_v6_manifest['version'] = UploadBatchStore::MANIFEST_VERSION;
file_put_contents( $worker_v6_manifest_path, json_encode( $worker_v6_manifest, JSON_UNESCAPED_SLASHES ) );
$worker_v6_lease = PrivateDir::acquire_purge_lease( $worker_v6_uploads_dir );
eforms_test_assert( $worker_v6_lease instanceof PrivateDirLease, 'Worker-v6 uninstall fixture should acquire its exclusive lifecycle lease.' );
$worker_v6_present = UploadBatchStore::remote_artifacts_present( $worker_v6_lease );
eforms_test_assert(
    empty( $worker_v6_present['ok'] ) && $worker_v6_present['reason'] === 'manifest_invalid',
    'Uninstall remote-artifact detection must fail closed on schema-6 Worker manifests instead of treating them as remote authority.'
	);
	$worker_v6_lease->release();
	eforms_test_remove_tree( $worker_v6_uploads_dir );

	$worker_prepare_bad_uploads_dir = eforms_test_setup_uploads( 'eforms-remote-uninstall-prepare-bad-capacity' );
	$worker_prepare_bad = eforms_test_uninstall_worker_item( $worker_prepare_bad_uploads_dir, 'prepare-bad', $field, 1700000000, false );
	$worker_prepare_bad_private = $worker_prepare_bad_uploads_dir . '/eforms-private';
	$worker_prepare_bad_capacity_path = $worker_prepare_bad_private . '/' . UploadBatchStore::CAPACITY_FILENAME;
	$worker_prepare_bad_capacity = eforms_test_managed_capacity_record( $worker_prepare_bad_uploads_dir );
	$worker_prepare_bad_overcount = $worker_prepare_bad_capacity;
	$worker_prepare_bad_overcount['total_bytes'] += 1;
	$worker_prepare_bad_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $worker_prepare_bad['path'] );
	$worker_prepare_bad_dir_mode = 0755;
	$worker_prepare_bad_lock_mode = 0644;
	eforms_test_assert( ManagedCapacityStore::write( $worker_prepare_bad_capacity_path, $worker_prepare_bad_overcount ), 'The Worker uninstall prepare overcount fixture should persist mismatched capacity.' );
	eforms_test_assert( @chmod( $worker_prepare_bad['path'], $worker_prepare_bad_dir_mode ), 'The Worker uninstall prepare fixture should lower aggregate permissions.' );
	eforms_test_assert( @chmod( $worker_prepare_bad_lock_path, $worker_prepare_bad_lock_mode ), 'The Worker uninstall prepare fixture should lower lock permissions.' );
	$worker_prepare_bad_lease = PrivateDir::acquire_purge_lease( $worker_prepare_bad_uploads_dir );
	eforms_test_assert( $worker_prepare_bad_lease instanceof PrivateDirLease, 'Worker uninstall prepare overcount fixture should acquire its exclusive lifecycle lease.' );
	$worker_prepare_bad_present = UploadBatchStore::remote_artifacts_present( $worker_prepare_bad_lease );
	$worker_prepare_bad_prepared = UploadBatchStore::prepare_worker_remote_purge(
	    $worker_prepare_bad_lease,
	    WorkerClient::composition_fingerprint(),
	    1700000010
	);
	$worker_prepare_bad_record_path = $worker_prepare_bad_private . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME;
	$worker_prepare_bad_marker_path = $worker_prepare_bad_private . '/' . PrivateDir::PURGE_MARKER_FILENAME;
	eforms_test_assert(
	    empty( $worker_prepare_bad_present['ok'] )
	        && $worker_prepare_bad_present['reason'] === 'capacity_invalid'
	        && empty( $worker_prepare_bad_prepared['ok'] )
	        && $worker_prepare_bad_prepared['reason'] === 'worker_remote_purge_authority_invalid'
	        && ! file_exists( $worker_prepare_bad_record_path )
	        && ! file_exists( $worker_prepare_bad_marker_path )
	        && ( @fileperms( $worker_prepare_bad['path'] ) & 0777 ) === $worker_prepare_bad_dir_mode
	        && ( @fileperms( $worker_prepare_bad_lock_path ) & 0777 ) === $worker_prepare_bad_lock_mode
	        && eforms_test_managed_capacity_record( $worker_prepare_bad_uploads_dir ) === $worker_prepare_bad_overcount,
	    'Initial Worker uninstall preflight and drain preparation must fail on bad exact capacity before permission repair, purge barrier writes, or drain-record writes.'
	);
	$worker_prepare_bad_lease->release();
	eforms_test_remove_tree( $worker_prepare_bad_uploads_dir );

	$worker_recover_uploads_dir = eforms_test_setup_uploads( 'eforms-remote-uninstall-recover-invalid-record' );
	$worker_recover = eforms_test_uninstall_worker_item( $worker_recover_uploads_dir, 'recover-invalid-record', $field, 1700000000, false );
	$worker_recover_private = $worker_recover_uploads_dir . '/eforms-private';
	$worker_recover_record_path = $worker_recover_private . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME;
	$worker_recover_marker_path = $worker_recover_private . '/' . PrivateDir::PURGE_MARKER_FILENAME;
	$worker_recover_lease = PrivateDir::acquire_purge_lease( $worker_recover_uploads_dir );
	eforms_test_assert( $worker_recover_lease instanceof PrivateDirLease, 'Worker uninstall recovery fixture should acquire its exclusive lifecycle lease.' );
	eforms_test_assert( PrivateDir::mark_purged( $worker_recover_lease ), 'Worker uninstall recovery fixture should create the purge barrier.' );
	file_put_contents( $worker_recover_record_path, '{"bad":true}' );
	chmod( $worker_recover_record_path, PrivateDir::FILE_MODE );
	$worker_recover_prepared = UploadBatchStore::prepare_worker_remote_purge(
	    $worker_recover_lease,
	    WorkerClient::composition_fingerprint(),
	    1700000020
	);
	$worker_recover_record = json_decode( file_get_contents( $worker_recover_record_path ), true );
	eforms_test_assert(
	    ! empty( $worker_recover_prepared['ok'] )
	        && empty( $worker_recover_prepared['ready'] )
	        && is_file( $worker_recover_marker_path )
	        && is_array( $worker_recover_record )
	        && $worker_recover_record['phase'] === 'draining'
	        && $worker_recover_record['started_at'] === 1700000020
	        && $worker_recover_record['composition_fingerprint'] === WorkerClient::composition_fingerprint(),
	    'Remote purge preparation should recover a barrier that lacks a valid drain record by writing a fresh safe drain.'
	);
	$worker_recover_lease->release();
	eforms_test_remove_tree( $worker_recover_uploads_dir );

	$primary = eforms_test_uninstall_worker_item( $uploads_dir, 'primary', $field, 1700000000, false );
$intent = array( 'object_key' => $primary['authority']['object_key'] );
$object_version = $primary['authority']['object_version'];
update_option( AdminSettingsStore::OPTION_NAME, array( 'logging' => array( 'mode' => 'jsonl' ) ), false );

try {
    require __DIR__ . '/../../uninstall.php';
    eforms_test_assert( false, 'The first remote uninstall attempt must stop WordPress deletion.' );
} catch ( RuntimeException $exception ) {
    eforms_test_assert( strpos( $exception->getMessage(), 'Retry after' ) !== false, 'The first attempt should provide an actionable retry time: ' . $exception->getMessage() );
}

$private_dir = $uploads_dir . '/eforms-private';
$record_path = $private_dir . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME;
$marker_path = $private_dir . '/' . PrivateDir::PURGE_MARKER_FILENAME;
eforms_test_assert( is_file( $record_path ) && is_file( $marker_path ), 'The initial attempt should persist one drain record and the authoritative barrier.' );
eforms_test_assert( ( fileperms( $record_path ) & 0777 ) === PrivateDir::FILE_MODE, 'The remote drain control record should remain owner-private.' );
$record = json_decode( file_get_contents( $record_path ), true );
eforms_test_assert( is_array( $record ) && $record['phase'] === 'draining', 'The persisted record should begin in draining phase.' );
eforms_test_assert( strpos( file_get_contents( $record_path ), $intent['object_key'] ) === false, 'The drain record must not persist object keys.' );
$previous_safe_after = $record['safe_after'];
eforms_test_assert(
    $previous_safe_after === $record['started_at']
        + Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' )
        + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' )
        + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' )
        + 1,
    'The purge clock must advance past the final inclusive Worker acceptance boundary.'
);

eforms_test_assert( PrivateDir::resume_after_install( $uploads_dir ) === true, 'Reactivation should reopen managed uploads without discarding the prior drain evidence.' );
eforms_test_assert( ! file_exists( $marker_path ) && is_file( $record_path ), 'Reactivation should remove only the barrier and leave the stale drain record observable.' );
$reactivated_now = $previous_safe_after + 100;
$reactivated_capacity = eforms_test_uninstall_worker_capacity_only( $uploads_dir, 'reactivated', $field, $reactivated_now );
$reactivated_intent = array( 'object_key' => $reactivated_capacity['authority']['object_key'] );
$restart_remote_calls = 0;
$restarted = eforms_uninstall_run(
    array(
        'now' => $reactivated_now + 10,
        'remote_delete' => function () use ( &$restart_remote_calls ) {
            $restart_remote_calls++;
            return array( 'ok' => true, 'absent' => true );
        },
    )
);
$record = json_decode( file_get_contents( $record_path ), true );
eforms_test_assert(
    empty( $restarted['ok'] )
        && $restarted['reason'] === 'remote_purge_draining'
        && $restart_remote_calls === 0
        && is_array( $record )
        && $record['started_at'] === $reactivated_now + 10
        && $record['safe_after'] > $previous_safe_after
        && is_file( $marker_path ),
    'A later uninstall must replace stale drain timing and establish a fresh barrier after reactivation.'
);

$remote_calls = 0;
$early = eforms_uninstall_run(
    array(
        'now' => $record['safe_after'] - 1,
        'remote_delete' => function () use ( &$remote_calls ) {
            $remote_calls++;
            return array( 'ok' => true, 'absent' => true );
        },
    )
);
eforms_test_assert( empty( $early['ok'] ) && $early['reason'] === 'remote_purge_draining' && $remote_calls === 0, 'An early retry must return immediately without remote mutation.' );

$failed = eforms_uninstall_run(
    array(
        'now' => $record['safe_after'],
        'remote_delete' => function () use ( &$remote_calls ) {
            $remote_calls++;
            return array( 'ok' => false, 'reason' => 'provider_unavailable' );
        },
    )
);
eforms_test_assert( empty( $failed['ok'] ) && $failed['reason'] === 'remote_delete_failed', 'Provider failure should keep uninstall retryable.' );
eforms_test_assert( is_file( $record_path ) && is_file( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME ), 'Provider failure should retain drain and capacity authority.' );

$locks_free = true;
$remote_call_uses_fresh_clock = false;
$deleted_objects = array();
$object_paths = array(
    $primary['authority']['object_key'] => $primary['path'],
);
$remote_delete = function ( $authority ) use ( &$locks_free, &$remote_call_uses_fresh_clock, &$deleted_objects, $private_dir, $object_paths ) {
    $expected_keys = array(
        'upload_id', 'storage_identity', 'expected_composition_fingerprint', 'validation_contract_version',
        'object_key', 'object_version', 'etag', 'bytes', 'policy_fingerprint',
    );
    if ( isset( $authority['validation_until'] ) ) {
        $expected_keys[] = 'validation_until';
    }
    eforms_test_assert( array_keys( $authority ) === $expected_keys, 'Remote uninstall should pass exact v3 delete authority only.' );
    eforms_test_assert( $authority['expected_composition_fingerprint'] === WorkerClient::composition_fingerprint(), 'Uninstall deletion must retain each aggregate\'s exact Worker deployment identity.' );
    $remote_call_uses_fresh_clock = func_num_args() === 1;
    $capacity = fopen( $private_dir . '/' . UploadBatchStore::CAPACITY_LOCK_FILENAME, 'c+b' );
    $capacity_free = is_resource( $capacity ) && flock( $capacity, LOCK_EX | LOCK_NB );
    $aggregate_free = true;
    if ( isset( $object_paths[ $authority['object_key'] ] ) ) {
        $aggregate = fopen( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $object_paths[ $authority['object_key'] ] ), 'r+b' );
        $aggregate_free = is_resource( $aggregate ) && flock( $aggregate, LOCK_EX | LOCK_NB );
    } else {
        $aggregate = false;
    }
    $locks_free = $locks_free && $capacity_free && $aggregate_free;
    if ( $capacity_free ) {
        flock( $capacity, LOCK_UN );
    }
    if ( $aggregate_free && is_resource( $aggregate ) ) {
        flock( $aggregate, LOCK_UN );
    }
    if ( is_resource( $capacity ) ) {
        fclose( $capacity );
    }
    if ( is_resource( $aggregate ) ) {
        fclose( $aggregate );
    }
    $deleted_objects[] = array( $authority['object_key'], $authority['object_version'] );
    return array( 'ok' => true, 'absent' => true );
};
$progress = eforms_uninstall_run(
    array(
        'now' => $record['safe_after'] + 1,
        'remote_delete' => $remote_delete,
    )
);
$progress_record = json_decode( file_get_contents( $record_path ), true );
$progress_capacity = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert(
    empty( $progress['ok'] )
        && $progress['reason'] === 'remote_purge_draining'
        && is_array( $progress_record )
        && $progress_record['next_family'] === UploadBatchStore::SUBMISSIONS_DIR,
    'A ready uninstall retry should checkpoint one bounded family page before asking for another supported retry.'
);
eforms_test_assert(
    is_array( $progress_capacity )
        && $progress_capacity['total_bytes'] === $reactivated_capacity['authority']['bytes']
        && count( $progress_capacity['reservations'] ) === 1,
    'A partial remote uninstall page must durably settle aggregate capacity while retaining reservation-only authority for its bounded phase.'
);
$ready = eforms_uninstall_run(
    array(
        'now' => $record['safe_after'] + 2,
        'remote_delete' => $remote_delete,
    )
);
eforms_test_assert(
    empty( $ready['ok'] ) && $ready['reason'] === 'remote_purge_draining',
    'A ready retry should checkpoint the empty submissions family before the bounded reservation phase.'
);
$reservation_phase_capacity = eforms_test_managed_capacity_record( $uploads_dir );
$reservation_phase_corrupt = $reservation_phase_capacity;
$reservation_phase_corrupt['total_bytes'] += 1;
$reservation_failure_calls = count( $deleted_objects );
eforms_test_assert( ManagedCapacityStore::write( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME, $reservation_phase_corrupt ), 'The reservation-phase fixture should persist mismatched capacity.' );
$reservation_failure = eforms_uninstall_run(
    array(
        'now' => $record['safe_after'] + 3,
        'remote_delete' => $remote_delete,
    )
);
$reservation_failure_record = json_decode( file_get_contents( $record_path ), true );
eforms_test_assert(
    empty( $reservation_failure['ok'] )
        && $reservation_failure['reason'] === 'capacity_invalid'
        && count( $deleted_objects ) === $reservation_failure_calls
        && eforms_test_managed_capacity_record( $uploads_dir ) === $reservation_phase_corrupt
        && is_file( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME )
        && is_array( $reservation_failure_record )
        && $reservation_failure_record['next_family'] === 'reservations',
    'Reservation-only remote purge must fail closed before remote deletion or capacity-record removal when total bytes exceed exact Worker authority.'
);
eforms_test_assert( ManagedCapacityStore::write( $private_dir . '/' . UploadBatchStore::CAPACITY_FILENAME, $reservation_phase_capacity ), 'The reservation-phase fixture should restore exact capacity for the successful retry.' );
$reservation_ready = eforms_uninstall_run(
    array(
        'now' => $record['safe_after'] + 4,
        'remote_delete' => $remote_delete,
    )
);
eforms_test_assert( ! empty( $reservation_ready['ok'] ), 'The reservation-phase retry should finish remote and local purge before reporting success.' );
eforms_test_assert( $locks_free, 'Remote deletion must not run under capacity or aggregate locks.' );
eforms_test_assert( $remote_call_uses_fresh_clock, 'Remote uninstall should let the outbound owner mint each operation grant from its current clock.' );
eforms_test_assert(
    count( $deleted_objects ) === 2
        && in_array( array( $intent['object_key'], $object_version ), $deleted_objects, true )
        && in_array( array( $reactivated_intent['object_key'], '-' ), $deleted_objects, true ),
    'Uninstall should delete both the exact committed version and the reservation-only object whose post-reactivation manifest write was lost.'
);
eforms_test_assert( ! file_exists( $record_path ) && ! is_dir( $private_dir . '/staged' ) && ! is_dir( $private_dir . '/submissions' ), 'Successful purge should remove its resumable record and managed roots.' );
eforms_test_assert( is_file( $marker_path ), 'The completed purge should retain the authoritative barrier.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, null ) === null, 'Settings should be deleted only after purge completion.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );

function eforms_test_uninstall_worker_item( $uploads_dir, $label, $field, $now, $finalize ) {
    $binding = array(
        'raw_token' => 'remote-uninstall-candidate-' . $label,
        'form_id' => 'virtual-estimate',
        'instance_id' => 'remote-uninstall-candidate-' . $label,
        'field_key' => 'project_photos',
        'accept_until' => $now + 3600,
    );
    $secret = eforms_test_managed_batch_secret( substr( hash( 'sha256', $label, true ), 0, 1 ) );
    $created = UploadBatchStore::create_batch(
        $binding,
        $secret,
        $field,
        $uploads_dir,
        $now,
        FormProtocol::UPLOAD_TRANSPORT_WORKER,
        WorkerClient::composition_fingerprint()
    );
    eforms_test_assert( ! empty( $created['ok'] ), 'Worker uninstall fixture should create ' . $label . ' batch.' );
    $batch_id = $created['batch']['batch_id'];
    $upload_id = 'worker_' . str_replace( '-', '_', $label );
    $validation_until = $binding['accept_until'] + 100 + ( $finalize ? 50 : 0 );
    $authorized = UploadBatchStore::worker_authorize_intent(
        $batch_id,
        $secret,
        $upload_id,
        0,
        $label . '.png',
        $finalize ? 3072 : 2048,
        'image/png',
        $uploads_dir,
        array(
            'now' => $now + 1,
            'storage_identity' => WorkerClient::composition_fingerprint(),
            'validation_contract_version' => 'validation-v1',
            'upload_until' => $now + 120,
            'accept_until' => $binding['accept_until'],
            'validation_until' => $validation_until,
            'staged_delete_after' => $created['batch']['delete_after'],
        )
    );
    eforms_test_assert( ! empty( $authorized['ok'] ), 'Worker uninstall fixture should authorize ' . $label . ' intent: ' . json_encode( $authorized ) );
    $path = $uploads_dir . '/eforms-private/' . UploadBatchStore::STAGED_DIR . '/' . Helpers::h2( $batch_id ) . '/' . $batch_id;
    $manifest_path = $path . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $manifest = json_decode( file_get_contents( $manifest_path ), true );
    $receipt = eforms_test_worker_stored_receipt( $manifest, $upload_id, 'candidate-' . $label . '-version', 'candidate-' . $label . '-etag' );
    $completed = UploadBatchStore::worker_complete_stored_receipt(
        $batch_id,
        $secret,
        $upload_id,
        $receipt,
        $uploads_dir,
        $now + 2
    );
    eforms_test_assert( ! empty( $completed['ok'] ), 'Worker uninstall fixture should register ' . $label . ' item: ' . json_encode( $completed ) );
    $review_path = '';
    if ( $finalize ) {
        $submission_id = 'submission-' . substr( hash( 'sha256', 'candidate-uninstall-' . $label ), 0, 16 );
        $claimed = UploadBatchStore::worker_claim_finalization(
            $batch_id,
            $secret,
            $binding,
            $field,
            array( UploadValue::review_staged_item( $completed['item'] ) ),
            $submission_id,
            $uploads_dir,
            $now + 3
        );
        eforms_test_assert( ! empty( $claimed['ok'] ), 'Worker uninstall finalized fixture should claim finalization: ' . json_encode( $claimed ) );
        $finalized = UploadBatchStore::worker_finalize( $batch_id, $submission_id, $uploads_dir, $now + 4 );
        eforms_test_assert( ! empty( $finalized['ok'] ), 'Worker uninstall finalized fixture should finalize: ' . json_encode( $finalized ) );
        $path = $uploads_dir . '/eforms-private/' . UploadBatchStore::SUBMISSIONS_DIR . '/' . Helpers::h2( $submission_id ) . '/' . $submission_id;
        $manifest_path = $path . '/' . UploadBatchStore::MANIFEST_FILENAME;
        $review_path = $path . '/' . UploadBatchStore::REVIEW_SNAPSHOT_FILENAME;
        file_put_contents( $review_path, '{}' );
        chmod( $review_path, PrivateDir::REVIEW_FILE_MODE );
    }
    $manifest = json_decode( file_get_contents( $manifest_path ), true );
    $item = $manifest['items'][ $upload_id ];
    return array(
        'path' => $path,
        'manifest_path' => $manifest_path,
        'review_snapshot_path' => $review_path,
        'validation_until' => $validation_until,
        'authority' => array(
            'upload_id' => $upload_id,
            'storage_identity' => $item['storage_identity'],
            'expected_composition_fingerprint' => $item['storage_identity'],
            'validation_contract_version' => $item['validation_contract_version'],
            'object_key' => $item['object_key'],
            'object_version' => $item['object_version'],
            'etag' => $item['etag'],
            'bytes' => $item['bytes'],
            'policy_fingerprint' => $item['policy_fingerprint'],
        ),
    );
}

function eforms_test_uninstall_worker_capacity_only( $uploads_dir, $label, $field, $now ) {
    $binding = array(
        'raw_token' => 'remote-uninstall-candidate-' . $label,
        'form_id' => 'virtual-estimate',
        'instance_id' => 'remote-uninstall-candidate-' . $label,
        'field_key' => 'project_photos',
        'accept_until' => $now + 3600,
    );
    $secret = eforms_test_managed_batch_secret( 'K' );
    $created = UploadBatchStore::create_batch(
        $binding,
        $secret,
        $field,
        $uploads_dir,
        $now,
        FormProtocol::UPLOAD_TRANSPORT_WORKER,
        WorkerClient::composition_fingerprint()
    );
    eforms_test_assert( ! empty( $created['ok'] ), 'Worker capacity-only uninstall fixture should create one batch.' );
    $validation_until = $binding['accept_until'] + 500;
    $authorized = UploadBatchStore::worker_authorize_intent(
        $created['batch']['batch_id'],
        $secret,
        'worker_capacity_only',
        0,
        'candidate-capacity-only.png',
        4096,
        'image/png',
        $uploads_dir,
        array(
            'now' => $now + 1,
            'storage_identity' => WorkerClient::composition_fingerprint(),
            'validation_contract_version' => 'validation-v1',
            'upload_until' => $now + 120,
            'accept_until' => $binding['accept_until'],
            'validation_until' => $validation_until,
            'staged_delete_after' => $created['batch']['delete_after'],
        )
    );
    eforms_test_assert( ! empty( $authorized['ok'] ), 'Worker capacity-only uninstall fixture should retain one exact candidate reservation: ' . json_encode( $authorized ) );
    $path = $uploads_dir . '/eforms-private/' . UploadBatchStore::STAGED_DIR . '/' . Helpers::h2( $created['batch']['batch_id'] ) . '/' . $created['batch']['batch_id'];
    eforms_test_remove_tree( $path );
    return array(
        'validation_until' => $validation_until,
        'authority' => array(
            'upload_id' => 'worker_capacity_only',
            'storage_identity' => WorkerClient::composition_fingerprint(),
            'expected_composition_fingerprint' => WorkerClient::composition_fingerprint(),
            'validation_contract_version' => 'validation-v1',
            'object_key' => $authorized['intent']['object_key'],
            'object_version' => '-',
            'etag' => '-',
            'bytes' => 4096,
            'policy_fingerprint' => $authorized['intent']['policy_fingerprint'],
            'validation_until' => $validation_until,
        ),
    );
}

echo "Remote uninstall drain tests passed.\n";
