<?php
/**
 * Integration proof for the persisted Worker/R2 uninstall drain.
 *
 * Contract: Runtime Storage Uninstall Purge Contract
 */

require_once __DIR__ . '/../bootstrap.php';
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
$batch_secret = rtrim( strtr( base64_encode( str_repeat( 'B', Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
$capacity_only_uploads = eforms_test_setup_uploads( 'eforms-remote-uninstall-capacity-only' );
$capacity_only_created = UploadBatchStore::create_batch(
    $binding,
    $batch_secret,
    $field,
    $capacity_only_uploads,
    1700000000,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    WorkerClient::composition_fingerprint()
);
eforms_test_assert( ! empty( $capacity_only_created['ok'] ), 'Capacity-only uninstall fixture should create one Worker batch.' );
$capacity_only_authorized = UploadBatchStore::authorize_intent(
    $capacity_only_created['batch']['batch_id'],
    $batch_secret,
    'capacity_only_item',
    0,
    'capacity-only.png',
    4096,
    'image/png',
    0,
    $capacity_only_uploads,
    array(
        'now' => 1700000001,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
        'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
    )
);
eforms_test_assert( ! empty( $capacity_only_authorized['ok'] ), 'Capacity-only uninstall fixture should retain a Worker reservation.' );
$capacity_only_path = $capacity_only_uploads . '/eforms-private/staged/'
    . Helpers::h2( $capacity_only_created['batch']['batch_id'] ) . '/'
    . $capacity_only_created['batch']['batch_id'];
eforms_test_remove_tree( $capacity_only_path );
$capacity_only_lease = PrivateDir::acquire_purge_lease( $capacity_only_uploads );
eforms_test_assert( $capacity_only_lease instanceof PrivateDirLease, 'Capacity-only uninstall fixture should acquire its exclusive lifecycle lease.' );
$capacity_only_present = UploadBatchStore::remote_artifacts_present( $capacity_only_lease );
eforms_test_assert(
    ! empty( $capacity_only_present['ok'] ) && ! empty( $capacity_only_present['present'] ),
    'Uninstall detection must retain a capacity-only Worker locator after aggregate loss.'
);
$capacity_only_prepared = UploadBatchStore::prepare_remote_purge(
    $capacity_only_lease,
    WorkerClient::composition_fingerprint(),
    1700000010
);
eforms_test_assert( ! empty( $capacity_only_prepared['ok'] ), 'Capacity-only uninstall should establish its remote drain.' );
$capacity_only_deleted = array();
$capacity_only_delete = function ( $object_key, $version, $artifact_store_identity ) use ( &$capacity_only_deleted, $capacity_only_uploads ) {
    $capacity_lock = fopen( $capacity_only_uploads . '/eforms-private/' . UploadBatchStore::CAPACITY_LOCK_FILENAME, 'c+b' );
    $capacity_lock_free = is_resource( $capacity_lock ) && flock( $capacity_lock, LOCK_EX | LOCK_NB );
    eforms_test_assert( $capacity_lock_free, 'Capacity-only remote deletion must run outside the capacity lock.' );
    if ( $capacity_lock_free ) {
        flock( $capacity_lock, LOCK_UN );
    }
    if ( is_resource( $capacity_lock ) ) {
        fclose( $capacity_lock );
    }
    $capacity_only_deleted[] = array( $object_key, $version, $artifact_store_identity );
    return array( 'ok' => true, 'absent' => true );
};
$capacity_only_staged = UploadBatchStore::resume_remote_purge(
    $capacity_only_lease,
    WorkerClient::composition_fingerprint(),
    $capacity_only_delete,
    $capacity_only_prepared['safe_after']
);
$capacity_only_submissions = UploadBatchStore::resume_remote_purge(
    $capacity_only_lease,
    WorkerClient::composition_fingerprint(),
    $capacity_only_delete,
    $capacity_only_prepared['safe_after'] + 1
);
$capacity_only_done = UploadBatchStore::resume_remote_purge(
    $capacity_only_lease,
    WorkerClient::composition_fingerprint(),
    $capacity_only_delete,
    $capacity_only_prepared['safe_after'] + 2
);
$capacity_only_capacity = eforms_test_managed_capacity_record( $capacity_only_uploads );
eforms_test_assert(
    ! empty( $capacity_only_staged['ok'] )
        && empty( $capacity_only_staged['ready'] )
        && ! empty( $capacity_only_submissions['ok'] )
        && empty( $capacity_only_submissions['ready'] )
        && ! empty( $capacity_only_done['ok'] )
        && ! empty( $capacity_only_done['ready'] ),
    'Capacity-only uninstall should traverse aggregate families before completing its orphan-reservation phase.'
);
eforms_test_assert(
    $capacity_only_deleted === array( array(
        $capacity_only_authorized['intent']['object_key'],
        '',
        WorkerClient::composition_fingerprint(),
    ) )
        && is_array( $capacity_only_capacity )
        && $capacity_only_capacity['total_bytes'] === 0
        && $capacity_only_capacity['reservations'] === array(),
    'Capacity-only uninstall must delete the stored Worker locator before releasing its exact reservation.'
);
$capacity_only_lease->release();
eforms_test_remove_tree( $capacity_only_uploads );

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

$created = UploadBatchStore::create_batch(
    $binding,
    $batch_secret,
    $field,
    $uploads_dir,
    1700000000,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    WorkerClient::composition_fingerprint()
);
eforms_test_assert( ! empty( $created['ok'] ), 'Remote uninstall fixture should create one managed batch.' );
$batch_id = $created['batch']['batch_id'];
$upload_id = 'remote_uninstall_item';
$authorized = UploadBatchStore::authorize_intent(
    $batch_id,
    $batch_secret,
    $upload_id,
    0,
    'phone-photo.png',
    4096,
    'image/png',
    0,
    $uploads_dir,
    array(
        'now' => 1700000001,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
        'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
    )
);
eforms_test_assert( ! empty( $authorized['ok'] ), 'Remote uninstall fixture should persist its pre-transfer intent.' );
$intent = $authorized['intent'];
$object_version = 'remote-uninstall-version-1';
$completed = UploadBatchStore::complete_receipt(
    $batch_id,
    $batch_secret,
    $upload_id,
    array(
        'intent_id' => $intent['intent_id'],
        'batch_id' => $batch_id,
        'upload_id' => $upload_id,
        'ordinal' => 0,
        'object_key' => $intent['object_key'],
        'object_version' => $object_version,
        'etag' => 'remote-uninstall-etag',
        'bytes' => 4096,
        'mime' => 'image/png',
        'width' => 32,
        'height' => 24,
        'policy_fingerprint' => $intent['policy_fingerprint'],
        'expires_at' => 1700000900,
    ),
    $uploads_dir,
    1700000002
);
eforms_test_assert( ! empty( $completed['ok'] ), 'Remote uninstall fixture should commit exact object facts.' );
$local_binding = array(
    'raw_token' => 'local-history-uninstall-token',
    'form_id' => 'virtual-estimate',
    'instance_id' => 'local-history-uninstall-instance',
    'field_key' => 'project_photos',
    'accept_until' => 1700003600,
);
$local_created = UploadBatchStore::create_batch(
    $local_binding,
    rtrim( strtr( base64_encode( str_repeat( 'L', Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' ),
    $field,
    $uploads_dir,
    1700000000,
    FormProtocol::UPLOAD_TRANSPORT_LOCAL
);
eforms_test_assert( ! empty( $local_created['ok'] ), 'Remote uninstall should tolerate one retained local aggregate from an earlier composition.' );
update_option( AdminSettingsStore::OPTION_NAME, array( 'logging' => array( 'mode' => 'jsonl' ) ), false );

try {
    require __DIR__ . '/../../uninstall.php';
    eforms_test_assert( false, 'The first remote uninstall attempt must stop WordPress deletion.' );
} catch ( RuntimeException $exception ) {
    eforms_test_assert( strpos( $exception->getMessage(), 'Retry after' ) !== false, 'The first attempt should provide an actionable retry time.' );
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
$reactivated_secret = rtrim( strtr( base64_encode( str_repeat( 'R', Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
$reactivated_binding = array(
    'raw_token' => 'remote-uninstall-reactivated-token',
    'form_id' => 'virtual-estimate',
    'instance_id' => 'remote-uninstall-reactivated-instance',
    'field_key' => 'project_photos',
    'accept_until' => $reactivated_now + 7200,
);
$reactivated = UploadBatchStore::create_batch(
    $reactivated_binding,
    $reactivated_secret,
    $field,
    $uploads_dir,
    $reactivated_now,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    WorkerClient::composition_fingerprint()
);
eforms_test_assert( ! empty( $reactivated['ok'] ), 'A retained plugin should accept a new Worker batch after reactivation.' );
$reactivated_authorized = UploadBatchStore::authorize_intent(
    $reactivated['batch']['batch_id'],
    $reactivated_secret,
    'remote_reactivated_item',
    0,
    'reactivated-phone-photo.png',
    2048,
    'image/png',
    0,
    $uploads_dir,
    array(
        'now' => $reactivated_now + 1,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
        'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
    )
);
eforms_test_assert( ! empty( $reactivated_authorized['ok'] ), 'Reactivation should permit a new durable remote upload intent.' );
$reactivated_intent = $reactivated_authorized['intent'];
$reactivated_manifest_path = $private_dir . '/staged/' . Helpers::h2( $reactivated['batch']['batch_id'] ) . '/' . $reactivated['batch']['batch_id'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$reactivated_manifest = json_decode( file_get_contents( $reactivated_manifest_path ), true );
unset( $reactivated_manifest['intents']['remote_reactivated_item'] );
file_put_contents( $reactivated_manifest_path, json_encode( $reactivated_manifest, JSON_UNESCAPED_SLASHES ) );
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
$object_batches = array(
    $intent['object_key'] => $batch_id,
    $reactivated_intent['object_key'] => $reactivated['batch']['batch_id'],
);
$remote_delete = function ( $object_key, $version, $artifact_store_identity ) use ( &$locks_free, &$remote_call_uses_fresh_clock, &$deleted_objects, $private_dir, $object_batches ) {
    eforms_test_assert( $artifact_store_identity === WorkerClient::composition_fingerprint(), 'Uninstall deletion must retain each aggregate\'s exact Worker deployment identity.' );
    $remote_call_uses_fresh_clock = func_num_args() === 3;
    $capacity = fopen( $private_dir . '/' . UploadBatchStore::CAPACITY_LOCK_FILENAME, 'c+b' );
    $target_batch_id = isset( $object_batches[ $object_key ] ) ? $object_batches[ $object_key ] : '';
    $aggregate_path = $private_dir . '/staged/' . Helpers::h2( $target_batch_id ) . '/' . $target_batch_id;
    $aggregate = fopen( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $aggregate_path ), 'r+b' );
    $capacity_free = is_resource( $capacity ) && flock( $capacity, LOCK_EX | LOCK_NB );
    $aggregate_free = is_resource( $aggregate ) && flock( $aggregate, LOCK_EX | LOCK_NB );
    $locks_free = $locks_free && $capacity_free && $aggregate_free;
    if ( $capacity_free ) {
        flock( $capacity, LOCK_UN );
    }
    if ( $aggregate_free ) {
        flock( $aggregate, LOCK_UN );
    }
    if ( is_resource( $capacity ) ) {
        fclose( $capacity );
    }
    if ( is_resource( $aggregate ) ) {
        fclose( $aggregate );
    }
    $deleted_objects[] = array( $object_key, $version );
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
        && $progress_capacity['total_bytes'] === 0
        && $progress_capacity['reservations'] === array(),
    'A partial remote uninstall page must durably settle capacity before its manifests disappear.'
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
$reservation_ready = eforms_uninstall_run(
    array(
        'now' => $record['safe_after'] + 3,
        'remote_delete' => $remote_delete,
    )
);
eforms_test_assert( ! empty( $reservation_ready['ok'] ), 'The reservation-phase retry should finish remote and local purge before reporting success.' );
eforms_test_assert( $locks_free, 'Remote deletion must not run under capacity or aggregate locks.' );
eforms_test_assert( $remote_call_uses_fresh_clock, 'Remote uninstall should let the outbound owner mint each operation grant from its current clock.' );
eforms_test_assert(
    count( $deleted_objects ) === 2
        && in_array( array( $intent['object_key'], $object_version ), $deleted_objects, true )
        && in_array( array( $reactivated_intent['object_key'], '' ), $deleted_objects, true ),
    'Uninstall should delete both the exact committed version and the reservation-only object whose post-reactivation manifest write was lost.'
);
eforms_test_assert( ! file_exists( $record_path ) && ! is_dir( $private_dir . '/staged' ) && ! is_dir( $private_dir . '/submissions' ), 'Successful purge should remove its resumable record and managed roots.' );
eforms_test_assert( is_file( $marker_path ), 'The completed purge should retain the authoritative barrier.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, null ) === null, 'Settings should be deleted only after purge completion.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );

$paged_uploads = eforms_test_setup_uploads( 'eforms-remote-uninstall-pages' );
$paged_now = 1800000000;
$paged_count = Anchors::get( 'MANAGED_REMOTE_PURGE_AGGREGATE_PAGE_SIZE' ) + 1;
for ( $index = 0; $index < $paged_count; $index++ ) {
    $paged_binding = array(
        'raw_token' => 'remote-uninstall-page-token-' . $index,
        'form_id' => 'virtual-estimate',
        'instance_id' => 'remote-uninstall-page-' . $index,
        'field_key' => 'project_photos',
        'accept_until' => $paged_now + 3600,
    );
    $paged_created = UploadBatchStore::create_batch(
        $paged_binding,
        rtrim( strtr( base64_encode( str_repeat( chr( 65 + ( $index % 26 ) ), Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' ),
        $field,
        $paged_uploads,
        $paged_now,
        FormProtocol::UPLOAD_TRANSPORT_WORKER,
        WorkerClient::composition_fingerprint()
    );
    eforms_test_assert( ! empty( $paged_created['ok'] ), 'The paged uninstall fixture should create every retained Worker aggregate.' );
}
$paged_lease = PrivateDir::acquire_purge_lease( $paged_uploads );
eforms_test_assert( $paged_lease instanceof PrivateDirLease, 'The paged uninstall fixture should acquire its exclusive lifecycle lease.' );
$paged_prepared = UploadBatchStore::prepare_remote_purge( $paged_lease, WorkerClient::composition_fingerprint(), $paged_now );
eforms_test_assert( ! empty( $paged_prepared['ok'] ) && empty( $paged_prepared['ready'] ), 'The paged uninstall fixture should establish its durable drain.' );
$paged_deletes = 0;
$paged_delete = function () use ( &$paged_deletes ) {
    $paged_deletes++;
    return array( 'ok' => true, 'absent' => true );
};
$paged_first = UploadBatchStore::resume_remote_purge(
    $paged_lease,
    WorkerClient::composition_fingerprint(),
    $paged_delete,
    $paged_prepared['safe_after']
);
$paged_record_path = $paged_lease->private_dir() . '/' . UploadBatchStore::REMOTE_PURGE_FILENAME;
$paged_record = json_decode( file_get_contents( $paged_record_path ), true );
eforms_test_assert(
    ! empty( $paged_first['ok'] )
        && empty( $paged_first['ready'] )
        && is_array( $paged_record )
        && $paged_record['next_family'] === UploadBatchStore::STAGED_DIR
        && $paged_record['cursor'] !== array()
        && $paged_deletes === 0,
    'One uninstall retry must checkpoint after one aggregate page instead of draining an unbounded retained set.'
);
$paged_second = UploadBatchStore::resume_remote_purge(
    $paged_lease,
    WorkerClient::composition_fingerprint(),
    $paged_delete,
    $paged_prepared['safe_after'] + 1
);
$paged_third = UploadBatchStore::resume_remote_purge(
    $paged_lease,
    WorkerClient::composition_fingerprint(),
    $paged_delete,
    $paged_prepared['safe_after'] + 2
);
$paged_done_retry = UploadBatchStore::resume_remote_purge(
    $paged_lease,
    WorkerClient::composition_fingerprint(),
    $paged_delete,
    $paged_prepared['safe_after'] + 3
);
eforms_test_assert(
    ! empty( $paged_second['ok'] )
        && empty( $paged_second['ready'] )
        && ! empty( $paged_third['ok'] )
        && empty( $paged_third['ready'] )
        && ! empty( $paged_done_retry['ok'] )
        && ! empty( $paged_done_retry['ready'] ),
    'Subsequent bounded retries should resume the saved page cursor, finish both aggregate families, and complete the final reservation phase.'
);
$paged_lease->release();
eforms_test_remove_tree( $paged_uploads );
echo "Remote uninstall drain tests passed.\n";
