<?php
/**
 * Integration tests for manifest-driven managed upload garbage collection.
 *
 * Contract: Managed Aggregate Contract
 * Contract: Runtime Storage GC Contract
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/managed_upload_fixtures.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Gc/GcRunner.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';

function eforms_test_gc_managed_configure( $uploads_dir ) {
    $GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
    eforms_test_set_filter(
        'eforms_config',
        function ( $config ) use ( $uploads_dir ) {
            $config['uploads']['dir'] = $uploads_dir;
            return $config;
        }
    );
    Config::reset_for_tests();
    Logging::reset_for_tests();
}

function eforms_test_gc_review_snapshot( $submission_id, $submitted_at ) {
    return array(
        'schema_version' => SubmissionReviewSnapshot::SCHEMA_VERSION,
        'form_id' => 'virtual-quote',
        'template_version' => 'gc-test',
        'submission_id' => $submission_id,
        'submitted_at' => gmdate( 'c', $submitted_at ),
        'title' => 'Submission Request',
        'header' => array(
            array(
                'key' => 'name',
                'label' => 'Name',
                'value' => 'GC Fixture',
                'type' => 'text',
            ),
            array(
                'key' => 'zip_us',
                'label' => 'Zip Code',
                'value' => '80202',
                'type' => 'text',
            ),
        ),
        'operator_rows' => array(
            array(
                'key' => 'project_description',
                'label' => 'Project Description',
                'value' => 'Managed GC fixture',
                'type' => 'text',
            ),
        ),
    );
}

function eforms_test_gc_managed_fixture( $uploads_dir, $name, $created_at, $accept_until, $finalized_at = null ) {
    $field = array(
        'type' => 'files',
        'upload_mode' => 'staged',
        'accept' => array( 'image' ),
        'max_file_bytes' => 1048576,
        'max_files' => 3,
        'max_total_bytes' => 3145728,
    );
    $binding = array(
        'raw_token' => 'gc-token-' . $name,
        'form_id' => 'virtual-quote',
        'instance_id' => 'gc-instance-' . $name,
        'field_key' => 'project_photos',
        'accept_until' => $accept_until,
    );
    $secret = eforms_test_managed_batch_secret( substr( hash( 'sha256', $name, true ), 0, 1 ) );
    $created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $created_at );
    eforms_test_assert( ! empty( $created['ok'] ), 'Managed GC fixture batch should be created.' );
    $staged_delete_after = $created['batch']['delete_after'];

    $batch_id = $created['batch']['batch_id'];
    $path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $batch_id ) . '/' . $batch_id;
    $upload_id = 'photo_' . substr( hash( 'sha256', $name ), 0, 12 );
    $artifact = eforms_test_fixture_bytes( 'staged-landscape.png' );
    $source = eforms_test_write_file( $uploads_dir, $upload_id . '.png', $artifact );
    $put = UploadBatchStore::put_item(
        $batch_id,
        $secret,
        $upload_id,
        0,
        array( 'tmp_name' => $source, 'original_name' => $name . '.png', 'size' => strlen( $artifact ), 'error' => UPLOAD_ERR_OK ),
        $uploads_dir,
        array(
            'now' => $created_at,
            'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
        )
    );
    eforms_test_assert( ! empty( $put['ok'] ), 'Managed GC fixture should commit one authoritative artifact.' );
    $artifact_bytes = strlen( $artifact );

    $submission_id = null;
    if ( $finalized_at !== null ) {
        $submission_id = 'submission-' . substr( hash( 'sha256', $name ), 0, 16 );
        $resolved = UploadBatchStore::resolve_open( $batch_id, $secret, $binding, $field, $uploads_dir, $finalized_at - 1 );
        $claimed = UploadBatchStore::claim_finalization( $batch_id, $secret, $binding, $field, $resolved['items'], $submission_id, $uploads_dir, $finalized_at - 1 );
        eforms_test_assert( ! empty( $claimed['ok'] ), 'Managed GC fixture should claim finalization.' );
        $finalized = UploadBatchStore::finalize( $batch_id, $submission_id, $uploads_dir, $finalized_at, eforms_test_gc_review_snapshot( $submission_id, $finalized_at ) );
        eforms_test_assert( ! empty( $finalized['ok'] ), 'Managed GC fixture should finalize.' );
        $path = $uploads_dir . '/eforms-private/submissions/' . Helpers::h2( $submission_id ) . '/' . $submission_id;
    }

    return array(
        'path' => $path,
        'batch_id' => $batch_id,
        'upload_id' => $upload_id,
        'submission_id' => $submission_id,
        'review_snapshot_path' => $submission_id === null ? '' : $path . '/' . UploadBatchStore::REVIEW_SNAPSHOT_FILENAME,
        'artifact_bytes' => $artifact_bytes,
        'managed_bytes' => $artifact_bytes,
        'staged_delete_after' => $staged_delete_after,
        'delete_after' => $finalized_at === null
            ? $created['batch']['delete_after']
            : $finalized['submission']['delete_after'],
    );
}

function eforms_test_retained_submission_by_id( $page, $submission_id ) {
    if ( ! is_array( $page ) || ! isset( $page['submissions'] ) || ! is_array( $page['submissions'] ) ) {
        return null;
    }
    foreach ( $page['submissions'] as $submission ) {
        if ( is_array( $submission ) && isset( $submission['submission_id'] ) && $submission['submission_id'] === $submission_id ) {
            return $submission;
        }
    }
    return null;
}

function eforms_test_retained_submission_ids( $page ) {
    if ( ! is_array( $page ) || ! isset( $page['submissions'] ) || ! is_array( $page['submissions'] ) ) {
        return array();
    }
    return array_values( array_filter( array_column( $page['submissions'], 'submission_id' ), 'is_string' ) );
}

function eforms_test_gc_batch_id_for_name( $name ) {
    $field = array(
        'type' => 'files',
        'upload_mode' => 'staged',
        'accept' => array( 'image' ),
        'max_file_bytes' => 1048576,
        'max_files' => 3,
        'max_total_bytes' => 3145728,
    );
    return UploadBatchStore::derive_batch_id(
        'gc-token-' . $name,
        'virtual-quote',
        'gc-instance-' . $name,
        'project_photos',
        UploadBatchStore::policy_fingerprint( $field )
    );
}

function eforms_test_gc_worker_fixture( $uploads_dir, $name, $created_at, $accept_until, $validation_until, $delete_after, $deleted_at, $kinds ) {
    $field = array(
        'type' => 'files',
        'upload_mode' => 'staged',
        'accept' => array( 'image' ),
        'max_file_bytes' => 1048576,
        'max_files' => 3,
        'max_total_bytes' => 3145728,
    );
    $binding = array(
        'raw_token' => 'worker-gc-token-' . $name,
        'form_id' => 'virtual-quote',
        'instance_id' => 'worker-gc-' . $name,
        'field_key' => 'project_photos',
        'accept_until' => $accept_until,
    );
    $secret = eforms_test_managed_batch_secret( substr( hash( 'sha256', 'worker-' . $name, true ), 0, 1 ) );
    $identity = hash( 'sha256', 'worker-gc-identity-' . $name );
    $created = UploadBatchStore::create_batch(
        $binding,
        $secret,
        $field,
        $uploads_dir,
        $created_at,
        FormProtocol::UPLOAD_TRANSPORT_WORKER,
        $identity
    );
    eforms_test_assert( ! empty( $created['ok'] ), 'Worker GC fixture should create its Worker-owned batch: ' . $name );
    $batch_id = $created['batch']['batch_id'];
    $path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $batch_id ) . '/' . $batch_id;
    $manifest_path = $path . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $uploads = array();
    foreach ( array_values( $kinds ) as $index => $kind ) {
        $upload_id = 'cg_' . substr( hash( 'sha256', $name . ':' . $index ), 0, 16 );
        $bytes = 4096 + $index;
        $authorized = UploadBatchStore::worker_authorize_intent(
            $batch_id,
            $secret,
            $upload_id,
            $index,
            $name . '-' . $index . '.png',
            $bytes,
            'image/png',
            $uploads_dir,
            array(
                'now' => $created_at + 1 + $index,
                'storage_identity' => $identity,
                'validation_contract_version' => 'validation-v1',
                'upload_until' => $created_at + 120 + $index,
                'accept_until' => $accept_until,
                'validation_until' => $validation_until,
                'staged_delete_after' => $delete_after,
            )
        );
        eforms_test_assert( ! empty( $authorized['ok'] ), 'Worker GC fixture should authorize item ' . $index . ': ' . json_encode( $authorized ) );
        $manifest = json_decode( file_get_contents( $manifest_path ), true );
        $version = '-';
        $etag = '-';
        $item_summary = null;
        if ( $kind === 'live_intent' ) {
            $uploads[] = array(
                'upload_id' => $upload_id,
                'kind' => $kind,
                'bytes' => $bytes,
                'object_key' => $authorized['intent']['object_key'],
                'object_version' => $version,
                'etag' => $etag,
            );
            continue;
        }
        if ( $kind === 'item' || $kind === 'active_item' ) {
            $version = 'worker-version-' . substr( hash( 'sha256', $name . ':' . $index ), 0, 16 );
            $etag = 'worker-etag-' . substr( hash( 'sha256', 'etag:' . $name . ':' . $index ), 0, 16 );
            $completed = UploadBatchStore::worker_complete_stored_receipt(
                $batch_id,
                $secret,
                $upload_id,
                eforms_test_worker_stored_receipt( $manifest, $upload_id, $version, $etag ),
                $uploads_dir,
                $created_at + 30 + $index
            );
            eforms_test_assert( ! empty( $completed['ok'] ), 'Worker GC fixture should complete item ' . $index . ': ' . json_encode( $completed ) );
            $item_summary = $completed['item'];
        }
        if ( $kind === 'active_item' ) {
            $uploads[] = array(
                'upload_id' => $upload_id,
                'kind' => $kind,
                'bytes' => $bytes,
                'object_key' => $authorized['intent']['object_key'],
                'object_version' => $version,
                'etag' => $etag,
                'item_summary' => $item_summary,
            );
            continue;
        }
        $deleted = UploadBatchStore::worker_delete_item( $batch_id, $secret, $upload_id, $uploads_dir, $deleted_at );
        eforms_test_assert( ! empty( $deleted['ok'] ), 'Worker GC fixture should tombstone item ' . $index . ': ' . json_encode( $deleted ) );
        $uploads[] = array(
            'upload_id' => $upload_id,
            'kind' => $kind,
            'bytes' => $bytes,
            'object_key' => $authorized['intent']['object_key'],
            'object_version' => $version,
            'etag' => $etag,
        );
    }
    return array(
        'batch_id' => $batch_id,
        'secret' => $secret,
        'identity' => $identity,
        'binding' => $binding,
        'field' => $field,
        'path' => $path,
        'manifest_path' => $manifest_path,
        'uploads' => $uploads,
        'bytes' => array_sum( array_column( $uploads, 'bytes' ) ),
        'deleted_at' => $deleted_at,
        'validation_until' => $validation_until,
        'delete_after' => $delete_after,
    );
}

function eforms_test_gc_worker_finalized_fixture( $uploads_dir, $name, $created_at, $accept_until, $validation_until, $staged_delete_after, $finalized_at ) {
    $fixture = eforms_test_gc_worker_fixture(
        $uploads_dir,
        $name,
        $created_at,
        $accept_until,
        $validation_until,
        $staged_delete_after,
        $staged_delete_after - 10,
        array( 'active_item' )
    );
    $item = $fixture['uploads'][0];
    $submission_id = 'submission-' . substr( hash( 'sha256', 'worker-finalized-' . $name ), 0, 16 );
    $claimed = UploadBatchStore::worker_claim_finalization(
        $fixture['batch_id'],
        $fixture['secret'],
        $fixture['binding'],
        $fixture['field'],
        array( UploadValue::review_staged_item( $item['item_summary'] ) ),
        $submission_id,
        $uploads_dir,
        $finalized_at - 1
    );
    eforms_test_assert( ! empty( $claimed['ok'] ), 'Worker finalized fixture should claim finalization: ' . json_encode( $claimed ) );
    $finalized = UploadBatchStore::worker_finalize( $fixture['batch_id'], $submission_id, $uploads_dir, $finalized_at );
    eforms_test_assert( ! empty( $finalized['ok'] ), 'Worker finalized fixture should finalize through the real submissions path: ' . json_encode( $finalized ) );

    $path = $uploads_dir . '/eforms-private/' . UploadBatchStore::SUBMISSIONS_DIR . '/' . Helpers::h2( $submission_id ) . '/' . $submission_id;
    $manifest_path = $path . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $review_path = $path . '/' . UploadBatchStore::REVIEW_SNAPSHOT_FILENAME;
    $written = file_put_contents( $review_path, json_encode( eforms_test_gc_review_snapshot( $submission_id, $finalized_at ), JSON_UNESCAPED_SLASHES ) );
    if ( $written !== false ) {
        chmod( $review_path, PrivateDir::REVIEW_FILE_MODE );
    }
    eforms_test_assert( $written !== false && is_file( $review_path ), 'Worker finalized fixture should store a valid review snapshot sidecar.' );

    $manifest = json_decode( file_get_contents( $manifest_path ), true );
    eforms_test_assert(
        is_array( $manifest )
            && $manifest['state'] === 'finalized'
            && $manifest['claim']['submission_id'] === $submission_id
            && $manifest['finalized_at'] === $finalized_at
            && $manifest['intents'] === array()
            && isset( $manifest['items'][ $item['upload_id'] ] )
            && is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $path ) ),
        'Worker finalized fixture should retain an active item, claim, manifest, review sidecar, and internal lock.'
    );

    $fixture['submission_id'] = $submission_id;
    $fixture['path'] = $path;
    $fixture['manifest_path'] = $manifest_path;
    $fixture['review_snapshot_path'] = $review_path;
    $fixture['delete_after'] = $manifest['delete_after'];
    $fixture['finalized_at'] = $manifest['finalized_at'];
    $fixture['item'] = $item;
    $fixture['finalized'] = $finalized['submission'];
    return $fixture;
}

function eforms_test_gc_worker_release_once( $record, $manifest, $now ) {
    $tombstone_bytes = 0;
    $attributed = array();
    $already_released = array();
    foreach ( $manifest['tombstones'] as $upload_id => $tombstone ) {
        $bytes = (int) $tombstone['bytes'];
        $tombstone_bytes += $bytes;
        $attributed[ (string) $upload_id ] = $bytes;
        if ( ! empty( $tombstone['capacity_released'] ) ) {
            $already_released[ (string) $upload_id ] = $bytes;
        }
    }
    return ManagedCapacityStore::release_remote_aggregate_once(
        $record,
        $manifest['batch_id'],
        $tombstone_bytes,
        $attributed,
        $already_released,
        $manifest['artifact_store_identity'],
        $now
    );
}

function eforms_test_gc_worker_ready_fixture( $uploads_dir, $name, $created_at, $accept_until, $validation_until, $delete_after, $now ) {
    $fixture = eforms_test_gc_worker_fixture(
        $uploads_dir,
        $name,
        $created_at,
        $accept_until,
        $validation_until,
        $delete_after,
        $delete_after - 10,
        array( 'active_item', 'live_intent' )
    );
    $calls = 0;
    $first = UploadBatchStore::gc_aggregates(
        'staged',
        $uploads_dir,
        $now,
        20,
        false,
        array(),
        function () use ( &$calls ) {
            $calls++;
            return array( 'ok' => true, 'absent' => true );
        }
    );
    $manifest = json_decode( file_get_contents( $fixture['manifest_path'] ), true );
    $capacity = eforms_test_managed_capacity_record( $uploads_dir );
    eforms_test_assert(
        $first['ok'] === true
            && $first['candidates'] === 1
            && $first['deleted'] === 0
            && $first['released_bytes'] === $fixture['bytes']
            && $calls === 2
            && $manifest['items'] === array()
            && $manifest['intents'] === array()
            && ! empty( $manifest['tombstones'][ $fixture['uploads'][0]['upload_id'] ]['capacity_released'] )
            && ! empty( $manifest['tombstones'][ $fixture['uploads'][1]['upload_id'] ]['capacity_released'] )
            && $capacity['total_bytes'] === 0,
        'Worker crash-window fixture should reach ready state through existing E1 cleanup.'
    );
    $fixture['ready_manifest'] = $manifest;
    $fixture['ready_capacity'] = $capacity;
    $fixture['ready_now'] = $now;
    return $fixture;
}

$uploads_dir = eforms_test_setup_uploads( 'eforms-gc-managed' );
eforms_test_gc_managed_configure( $uploads_dir );
$base = 1700000000;
$run_now = $base + Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' );

$expired_staged = eforms_test_gc_managed_fixture( $uploads_dir, 'expired-staged', $base, $base + 100 );
$fresh_staged = eforms_test_gc_managed_fixture( $uploads_dir, 'fresh-staged', $run_now, $run_now + 3600 );
$expired_final = eforms_test_gc_managed_fixture( $uploads_dir, 'expired-final', $base - 2, $base + 3600, $base );
$fresh_final = eforms_test_gc_managed_fixture( $uploads_dir, 'fresh-final', $base, $run_now + 3600, $run_now - 100 );
$manual_final = eforms_test_gc_managed_fixture( $uploads_dir, 'manual-final', $base, $run_now + 3600, $run_now - 90 );
eforms_test_assert( ! empty( UploadBatchStore::update_finalized_availability( $manual_final['submission_id'], $uploads_dir, null, $run_now - 80 )['ok'] ), 'The manual-retention fixture should set delete_after to null.' );
eforms_test_assert( $expired_staged['delete_after'] <= $run_now, 'The staged fixture should be expired at injected GC time.' );
eforms_test_assert( $expired_final['delete_after'] === $run_now, 'The finalized fixture should become eligible exactly at delete_after.' );
eforms_test_assert( is_file( $expired_final['review_snapshot_path'] ) && is_file( $fresh_final['review_snapshot_path'] ) && is_file( $manual_final['review_snapshot_path'] ), 'Finalized GC fixtures should carry review snapshot sidecars.' );

$limited_retained = UploadBatchStore::retained_photo_submissions( $uploads_dir, $run_now, 1 );
eforms_test_assert(
    $limited_retained['ok'] === true
        && $limited_retained['scanned'] > 1
        && $limited_retained['scanned'] <= Anchors::get( 'RETAINED_SUBMISSIONS_SCAN_PAGE_SIZE' )
        && $limited_retained['reached_limit'] === true
        && isset( $limited_retained['cursor']['shard'], $limited_retained['cursor']['aggregate'] ),
    'Retained submission listing should confirm another retained row before exposing a bounded deterministic cursor.'
);

$corrupt_final = eforms_test_gc_managed_fixture( $uploads_dir, 'corrupt-list-final', $base, $run_now + 3600, $run_now - 70 );
file_put_contents( $corrupt_final['review_snapshot_path'], '{"schema_version":999}' );
chmod( $corrupt_final['review_snapshot_path'], 0600 );
$retained = UploadBatchStore::retained_photo_submissions( $uploads_dir, $run_now, Anchors::get( 'RETAINED_SUBMISSIONS_PAGE_SIZE' ) );
eforms_test_assert( $retained['ok'] === true, 'Retained submission listing should succeed before GC: ' . json_encode( $retained ) );
$retained_ids = eforms_test_retained_submission_ids( $retained );
eforms_test_assert( in_array( $expired_final['submission_id'], $retained_ids, true ), 'Retained listing should include expired-but-not-yet-GCed finalized submissions.' );
eforms_test_assert( in_array( $fresh_final['submission_id'], $retained_ids, true ), 'Retained listing should include pre-expiry finalized submissions.' );
eforms_test_assert( in_array( $manual_final['submission_id'], $retained_ids, true ), 'Retained listing should include manual-retention finalized submissions.' );
eforms_test_assert( ! in_array( $corrupt_final['submission_id'], $retained_ids, true ), 'Retained listing should fail closed around corrupt review sidecars.' );
eforms_test_assert( ! in_array( $expired_staged['batch_id'], $retained_ids, true ), 'Retained listing should not include staged or listing-only identities.' );
$manual_row = eforms_test_retained_submission_by_id( $retained, $manual_final['submission_id'] );
eforms_test_assert(
    is_array( $manual_row )
        && $manual_row['submitted_at'] === $run_now - 90
        && is_string( $manual_row['submitted_label'] )
        && $manual_row['photo_count'] === 1
        && $manual_row['availability']['delete_after'] === null
        && $manual_row['availability']['expired'] === false
        && $manual_row['view'] === array( 'submission_id' => $manual_final['submission_id'] )
        && $manual_row['summary']['name'] === 'GC Fixture'
        && $manual_row['summary']['zip_us'] === '80202'
        && $manual_row['summary']['project_summary'] === 'Managed GC fixture',
    'Retained listing row should expose compact admin facts and snapshot summary fields.'
);
$expired_row = eforms_test_retained_submission_by_id( $retained, $expired_final['submission_id'] );
eforms_test_assert( is_array( $expired_row ) && $expired_row['availability']['expired'] === true, 'Retained listing should mark expired finalized submissions without hiding retained rows.' );
$retained_json = json_encode( $retained );
eforms_test_assert(
    is_string( $retained_json )
        && strpos( $retained_json, 'artifact_store' ) === false
        && strpos( $retained_json, 'artifact_store_identity' ) === false
        && strpos( $retained_json, 'object_key' ) === false
        && strpos( $retained_json, 'eforms-private' ) === false
        && strpos( $retained_json, 'email_attempted' ) === false
        && strpos( $retained_json, 'tel_us' ) === false
        && strpos( $retained_json, 'listing_url' ) === false,
    'Retained listing should not leak storage internals or detail-only contact fields.'
);

$preview_fence_lease = PrivateDir::acquire_write_lease( $uploads_dir );
eforms_test_assert( $preview_fence_lease instanceof PrivateDirLease, 'The preview-fence GC fixture should acquire the lifecycle lease.' );
$preview_fence_key = ManagedArtifactKey::create( eforms_test_digest( 'preview-fence-gc-batch' ), 0, eforms_test_digest( 'preview-fence-gc-item' ), 'image/png' );
$preview_fence_version = '11111111-1111-4111-8111-111111111111';
eforms_test_assert(
    LocalPreviewProvider::delete_cache( $preview_fence_lease, $preview_fence_key, $preview_fence_version ),
    'The preview-fence GC fixture should persist one absent-cache deletion fence.'
);
$preview_fence_root = PrivateDir::leased_subdir( $preview_fence_lease, LocalPreviewProvider::ROOT_DIR, false, true );
$preview_fence_paths = glob( $preview_fence_root . '/*/*/' . LocalPreviewProvider::DELETED_FILENAME );
eforms_test_assert( is_array( $preview_fence_paths ) && count( $preview_fence_paths ) === 1, 'The preview-fence GC fixture should expose one deterministic fence.' );
eforms_test_assert(
    touch( $preview_fence_paths[0], $run_now - Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' ) ),
    'The preview-fence GC fixture should set its exact eligibility boundary.'
);
$preview_fence_lease->release();

$capacity_before = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert( is_array( $capacity_before ), 'Managed capacity should be readable before GC.' );
	$capacity_path = $uploads_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
	$capacity_with_stale = $capacity_before;
	$stale_gc_intent_id = eforms_test_digest( 'stale-gc-reservation' );
	$stale_gc_reservation_id = hash( 'sha256', $expired_staged['batch_id'] . "\0interrupted_upload" );
	$capacity_with_stale['total_bytes'] += 999;
	$capacity_with_stale['store_bytes']['local'] += 999;
	$capacity_with_stale['reservations'][ $stale_gc_reservation_id ] = array(
	    'batch_id' => $expired_staged['batch_id'],
	    'upload_id' => 'interrupted_upload',
	    'bytes' => 999,
    'transient_bytes' => 0,
	    'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_LOCAL,
	    'artifact_store_identity' => UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY,
	    'cleanup_started' => false,
	    'intent_id' => $stale_gc_intent_id,
	    'object_key' => ManagedArtifactKey::create( $expired_staged['batch_id'], 0, $stale_gc_intent_id, 'image/png' ),
	    'created_at' => $run_now - Anchors::get( 'MANAGED_RESERVATION_STALE_SECONDS' ),
	);
file_put_contents( $capacity_path, json_encode( $capacity_with_stale ) );
chmod( $capacity_path, 0600 );

$dry_run = GcRunner::run( array( 'dry_run' => true, 'now' => $run_now ) );
eforms_test_assert( $dry_run['ok'] === true, 'Managed aggregate dry-run should succeed: ' . json_encode( $dry_run ) );
eforms_test_assert( $dry_run['by_type']['staged_batches']['candidates'] === 1, 'Dry-run should find one expired staged aggregate.' );
eforms_test_assert( $dry_run['by_type']['finalized_submissions']['candidates'] === 1, 'Dry-run should find one expired finalized aggregate.' );
eforms_test_assert( $dry_run['by_type']['preview_fences']['candidates'] === 1, 'Dry-run should find one expired local preview deletion fence.' );
eforms_test_assert( $dry_run['by_type']['staged_batches']['candidate_artifact_bytes'] === $expired_staged['artifact_bytes'], 'Staged dry-run should report authoritative artifact bytes.' );
eforms_test_assert( $dry_run['by_type']['finalized_submissions']['candidate_artifact_bytes'] === $expired_final['artifact_bytes'], 'Finalized dry-run should report authoritative artifact bytes.' );
eforms_test_assert( $dry_run['deleted'] === 0 && $dry_run['by_type']['staged_batches']['released_bytes'] === 0, 'Dry-run should delete nothing and release no capacity.' );
eforms_test_assert( is_dir( $expired_staged['path'] ) && is_dir( $expired_final['path'] ) && is_dir( $manual_final['path'] ), 'Dry-run should preserve eligible aggregates and manual-retention finalized submissions.' );
$capacity_after_dry_run = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert( $capacity_after_dry_run === $capacity_with_stale, 'Dry-run should preserve capacity accounting and stale reservations.' );

$apply = GcRunner::run( array( 'now' => $run_now, 'reconcile_capacity' => true ) );
$expected_release = $expired_staged['managed_bytes'] + $expired_final['managed_bytes'];
eforms_test_assert( $apply['ok'] === true && $apply['deleted'] === 3, 'Managed GC should delete both eligible aggregate families and the expired preview fence.' );
eforms_test_assert( $apply['capacity_reconciled'] === true && $apply['stale_reservations_removed'] === 1, 'Applying GC should reconcile one stale reservation before aggregate deletion.' );
eforms_test_assert( $apply['by_type']['staged_batches']['released_bytes'] === $expired_staged['managed_bytes'], 'Staged deletion should release its exact managed bytes.' );
eforms_test_assert( $apply['by_type']['finalized_submissions']['released_bytes'] === $expired_final['managed_bytes'], 'Finalized deletion should release its exact managed bytes.' );
eforms_test_assert( ! is_dir( $expired_staged['path'] ) && ! is_dir( $expired_final['path'] ) && is_dir( $manual_final['path'] ), 'Apply should remove expired aggregate directories while preserving manual-retention finalized submissions.' );
eforms_test_assert( ! file_exists( $expired_final['review_snapshot_path'] ) && is_file( $manual_final['review_snapshot_path'] ), 'Finalized aggregate GC should remove review snapshots with deleted aggregates and preserve retained sidecars.' );
eforms_test_assert( $apply['by_type']['preview_fences']['deleted'] === 1 && ! file_exists( dirname( $preview_fence_paths[0] ) ), 'Apply should reclaim the complete expired preview fence directory.' );
eforms_test_assert( is_dir( $fresh_staged['path'] ) && is_dir( $fresh_final['path'] ), 'Apply should preserve pre-expiry aggregates.' );
$post_gc_retained = UploadBatchStore::retained_photo_submissions( $uploads_dir, $run_now, Anchors::get( 'RETAINED_SUBMISSIONS_PAGE_SIZE' ) );
$post_gc_ids = eforms_test_retained_submission_ids( $post_gc_retained );
eforms_test_assert(
    $post_gc_retained['ok'] === true
        && ! in_array( $expired_final['submission_id'], $post_gc_ids, true )
        && in_array( $fresh_final['submission_id'], $post_gc_ids, true )
        && in_array( $manual_final['submission_id'], $post_gc_ids, true )
        && ! in_array( $corrupt_final['submission_id'], $post_gc_ids, true ),
    'Retained listing should track aggregate retention after GC and keep corrupt sidecars absent.'
);
$capacity_after_apply = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert( $capacity_after_apply['total_bytes'] === $capacity_before['total_bytes'] - $expected_release, 'Apply should release aggregate capacity exactly once.' );

$idempotent = GcRunner::run( array( 'now' => $run_now ) );
eforms_test_assert( $idempotent['ok'] === true && $idempotent['candidates'] === 0 && $idempotent['deleted'] === 0, 'A repeated managed GC run should be idempotent.' );
$capacity_after_retry = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert( $capacity_after_retry['total_bytes'] === $capacity_after_apply['total_bytes'], 'An idempotent GC retry should not release capacity twice.' );

$absent_intent_dir = eforms_test_setup_uploads( 'eforms-gc-absent-intent' );
$absent_field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 1048576,
    'max_files' => 3,
    'max_total_bytes' => 3145728,
);
$absent_binding = array(
    'raw_token' => 'gc-token-absent-intent',
    'form_id' => 'virtual-quote',
    'instance_id' => 'gc-instance-absent-intent',
    'field_key' => 'project_photos',
    'accept_until' => $base + 100,
);
$absent_secret = eforms_test_managed_batch_secret( "\x39" );
$absent_created = UploadBatchStore::create_batch( $absent_binding, $absent_secret, $absent_field, $absent_intent_dir, $base );
$absent_batch_id = $absent_created['batch']['batch_id'];
$absent_intent = UploadBatchStore::authorize_intent(
    $absent_batch_id,
    $absent_secret,
    'absent_photo',
    0,
    'absent.png',
    4096,
    'image/png',
    0,
    $absent_intent_dir,
    array(
        'now' => $base + 1,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
    )
);
eforms_test_assert( ! empty( $absent_intent['ok'] ), 'The absent-intent GC fixture should reserve one unresolved intent.' );
$absent_delete_after = $absent_created['batch']['delete_after'];
$absent_reconciled = UploadBatchStore::reconcile_capacity( $absent_intent_dir, $absent_delete_after, $absent_delete_after );
$absent_capacity = eforms_test_managed_capacity_record( $absent_intent_dir );
eforms_test_assert(
    ! empty( $absent_reconciled['ok'] )
        && $absent_reconciled['stale_reservations_removed'] === 1
        && $absent_capacity['total_bytes'] === 0
        && $absent_capacity['reservations'] === array(),
    'Reconciliation should release a stale unresolved intent when no artifact was materialized.'
);
$post_reconcile_source = eforms_test_write_file( $absent_intent_dir, 'post-reconcile-delayed-upload.bin', str_repeat( 'x', 4096 ) );
$post_reconcile_lease = PrivateDir::acquire_write_lease( $absent_intent_dir );
$post_reconcile_write = LocalArtifactStore::write(
    $post_reconcile_lease,
    $absent_intent['intent']['object_key'],
    $post_reconcile_source,
    4096
);
$post_reconcile_lease->release();
eforms_test_assert(
    empty( $post_reconcile_write['ok'] )
        && $post_reconcile_write['reason'] === 'artifact_deleted'
        && LocalArtifactStore::bytes_for_key( $absent_intent_dir, $absent_intent['intent']['object_key'] ) === 0,
    'Reconciliation must fence confirmed absence before releasing a stale reservation so a delayed authorized writer cannot create unaccounted bytes.'
);
$absent_path = $absent_intent_dir . '/eforms-private/staged/' . Helpers::h2( $absent_batch_id ) . '/' . $absent_batch_id;
$absent_dry_run = UploadBatchStore::gc_aggregates( 'staged', $absent_intent_dir, $absent_delete_after, 500, true );
eforms_test_assert(
    $absent_dry_run['ok'] === true
        && $absent_dry_run['candidates'] === 1
        && $absent_dry_run['deleted'] === 0
        && is_dir( $absent_path ),
    'Dry-run should recognize the reconciled absent-intent aggregate without mutating it or capacity.'
);
$absent_gc = UploadBatchStore::gc_aggregates( 'staged', $absent_intent_dir, $absent_delete_after, 500 );
eforms_test_assert(
    $absent_gc['ok'] === true
        && $absent_gc['deleted'] === 1
        && $absent_gc['released_bytes'] === 0
        && ! is_dir( $absent_path ),
    'GC should delete an expired aggregate after its absent intent reservation was already reconciled.'
);
$post_gc_source = eforms_test_write_file( $absent_intent_dir, 'post-gc-delayed-upload.bin', str_repeat( 'x', 4096 ) );
$post_gc_lease = PrivateDir::acquire_write_lease( $absent_intent_dir );
$post_gc_write = LocalArtifactStore::write(
    $post_gc_lease,
    $absent_intent['intent']['object_key'],
    $post_gc_source,
    4096
);
$post_gc_lease->release();
eforms_test_assert(
    empty( $post_gc_write['ok'] )
        && $post_gc_write['reason'] === 'artifact_deleted'
        && LocalArtifactStore::bytes_for_key( $absent_intent_dir, $absent_intent['intent']['object_key'] ) === 0,
    'Aggregate GC must retain the object fence so a previously authorized delayed writer cannot recreate released bytes.'
);
eforms_test_remove_tree( $absent_intent_dir );

eforms_test_remove_tree( $uploads_dir );

$renamed_finalizing_dir = eforms_test_setup_uploads( 'eforms-gc-renamed-finalizing' );
eforms_test_gc_managed_configure( $renamed_finalizing_dir );
$renamed_finalizing = eforms_test_gc_managed_fixture( $renamed_finalizing_dir, 'renamed-finalizing', $base, $base + 100, $base + 1 );
$renamed_manifest_path = $renamed_finalizing['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$renamed_manifest = json_decode( file_get_contents( $renamed_manifest_path ), true );
$renamed_manifest['state'] = 'finalizing';
$renamed_manifest['delete_after'] = $renamed_finalizing['staged_delete_after'];
unset( $renamed_manifest['finalized_at'], $renamed_manifest['email_attempted_at'] );
file_put_contents( $renamed_manifest_path, json_encode( $renamed_manifest ) );
chmod( $renamed_manifest_path, 0600 );
$renamed_capacity_before = eforms_test_managed_capacity_record( $renamed_finalizing_dir );
$renamed_dry_run = GcRunner::run( array( 'dry_run' => true, 'now' => $renamed_finalizing['staged_delete_after'] ) );
eforms_test_assert( $renamed_dry_run['by_type']['finalized_submissions']['candidates'] === 1, 'Dry-run should find a post-rename finalizing aggregate at its staged deadline.' );
eforms_test_assert( is_dir( $renamed_finalizing['path'] ), 'Dry-run should preserve the post-rename finalizing aggregate.' );
$renamed_apply = GcRunner::run( array( 'now' => $renamed_finalizing['staged_delete_after'] ) );
eforms_test_assert( $renamed_apply['ok'] === true && $renamed_apply['by_type']['finalized_submissions']['deleted'] === 1, 'GC should delete an expired post-rename finalizing aggregate.' );
eforms_test_assert( $renamed_apply['by_type']['finalized_submissions']['released_bytes'] === $renamed_finalizing['managed_bytes'], 'Post-rename finalizing GC should release exact managed capacity.' );
eforms_test_assert( ! is_dir( $renamed_finalizing['path'] ), 'GC should remove the expired post-rename finalizing directory.' );
$renamed_capacity_after = eforms_test_managed_capacity_record( $renamed_finalizing_dir );
eforms_test_assert( $renamed_capacity_after['total_bytes'] === $renamed_capacity_before['total_bytes'] - $renamed_finalizing['managed_bytes'], 'Post-rename finalizing GC should release capacity exactly once.' );
eforms_test_remove_tree( $renamed_finalizing_dir );

$fair_dir = eforms_test_setup_uploads( 'eforms-gc-family-fairness' );
eforms_test_gc_managed_configure( $fair_dir );
$expired_fair = eforms_test_gc_managed_fixture( $fair_dir, 'expired-fair', $base - 2, $base + 3600, $base );
$tokens_dir = $fair_dir . '/eforms-private/tokens/fairness';
mkdir( $tokens_dir, 0700, true );
for ( $index = 0; $index < 10; $index++ ) {
    file_put_contents( $tokens_dir . '/fresh-' . $index . '.json', json_encode( array( 'expires' => $run_now + 3600 ) ) );
}
$fair = GcRunner::run( array( 'now' => $run_now, 'limit' => 7 ) );
eforms_test_assert( $fair['ok'] === true && ! is_dir( $expired_fair['path'] ), 'A full family-sized batch should reach an expired finalized aggregate despite a larger fresh-token prefix.' );
eforms_test_assert( count( glob( $tokens_dir . '/*.json' ) ) === 10, 'Family fairness must preserve fresh tokens.' );
eforms_test_remove_tree( $fair_dir );

$file_cursor_dir = eforms_test_setup_uploads( 'eforms-gc-file-family-cursors' );
eforms_test_gc_managed_configure( $file_cursor_dir );
$file_cursor_now = $run_now - ( ( (int) floor( $run_now / 60 ) % 7 ) * 60 );
$file_tokens_dir = $file_cursor_dir . '/eforms-private/tokens/cursor';
$file_declined_dir = $file_cursor_dir . '/eforms-private/' . DeclinedReviewLog::DIR;
mkdir( $file_tokens_dir, 0700, true );
mkdir( $file_declined_dir, 0700, true );
for ( $index = 0; $index < 7; $index++ ) {
    if ( $index < 3 ) {
        file_put_contents( $file_tokens_dir . '/a-fresh-' . $index . '.json', json_encode( array( 'expires' => $file_cursor_now + 3600 ) ) );
    }
    file_put_contents( $file_declined_dir . '/aaa-control-' . $index . '.txt', 'control' );
}
$expired_token_path = $file_tokens_dir . '/z-expired.json';
$expired_declined_path = $file_declined_dir . '/declined-20000101.jsonl';
file_put_contents( $expired_token_path, json_encode( array( 'expires' => $file_cursor_now - 1 ) ) );
file_put_contents( $expired_declined_path, "{\"review_id\":\"expired\"}\n" );
touch( $expired_declined_path, $base - 86400 );

$file_cursor_dry_one = GcRunner::run( array( 'dry_run' => true, 'now' => $file_cursor_now, 'limit' => 7 ) );
$file_cursor_dry_two = GcRunner::run( array( 'dry_run' => true, 'now' => $file_cursor_now, 'limit' => 7 ) );
eforms_test_assert( $file_cursor_dry_one['by_type']['tokens']['scanned'] === 1 && $file_cursor_dry_two['by_type']['tokens']['scanned'] === 1, 'Repeated dry-runs should read but not advance file-family cursors.' );

$file_cursor_first = GcRunner::run( array( 'now' => $file_cursor_now, 'limit' => 7 ) );
$file_progress = json_decode( file_get_contents( $file_cursor_dir . '/eforms-private/' . GcRunner::LOCK_FILENAME ), true );
eforms_test_assert( $file_cursor_first['ok'] === true && is_array( $file_progress ), 'Applying bounded GC should persist readable progress.' );
eforms_test_assert( $file_progress['version'] === GcRunner::PROGRESS_VERSION, 'GC progress should use the current all-family cursor shape.' );
eforms_test_assert( $file_progress['families']['tokens'] === array( 'path' => 'cursor/a-fresh-0.json' ), 'Token GC should persist its last bounded traversal path.' );
eforms_test_assert( ! empty( $file_progress['families']['declined']['entry'] ), 'Declined-log GC should persist its last bounded traversal entry.' );
eforms_test_assert( file_exists( $expired_token_path ) && file_exists( $expired_declined_path ), 'One bounded apply should not jump past fresh prefixes.' );

for ( $index = 1; $index <= 3; $index++ ) {
    GcRunner::run( array( 'now' => $file_cursor_now + ( $index * 60 ), 'limit' => 7 ) );
}
eforms_test_assert( ! file_exists( $expired_token_path ), 'Repeated bounded GC should advance past a fresh token prefix and delete an expired token.' );
eforms_test_assert( ! file_exists( $expired_declined_path ), 'Repeated bounded GC should advance past control files and delete an expired declined-review log.' );
eforms_test_assert( count( glob( $file_tokens_dir . '/a-fresh-*.json' ) ) === 3, 'Cursor progress must preserve fresh token files.' );
eforms_test_remove_tree( $file_cursor_dir );

$family_rotation_dir = eforms_test_setup_uploads( 'eforms-gc-persisted-family-rotation' );
eforms_test_gc_managed_configure( $family_rotation_dir );
$rotation_tokens_dir = $family_rotation_dir . '/eforms-private/tokens';
$rotation_ledger_dir = $family_rotation_dir . '/eforms-private/ledger';
mkdir( $rotation_tokens_dir, 0700, true );
mkdir( $rotation_ledger_dir, 0700, true );
file_put_contents( $rotation_tokens_dir . '/fresh.json', json_encode( array( 'expires' => $run_now + 3600 ) ) );
$rotation_ledger_path = $rotation_ledger_dir . '/expired.used';
file_put_contents( $rotation_ledger_path, 'used' );
touch( $rotation_ledger_path, $run_now - Anchors::get( 'TOKEN_TTL_MAX' ) - Anchors::get( 'LEDGER_GC_GRACE_SECONDS' ) - 1 );

$rotation_first = GcRunner::run( array( 'now' => $run_now, 'limit' => 1 ) );
$rotation_progress = json_decode( file_get_contents( $family_rotation_dir . '/eforms-private/' . GcRunner::LOCK_FILENAME ), true );
eforms_test_assert( $rotation_first['by_type']['tokens']['scanned'] === 1 && $rotation_first['by_type']['ledger']['scanned'] === 0, 'A one-item batch should begin at the persisted initial family.' );
eforms_test_assert( $rotation_progress['next_family'] === 'ledger', 'Applying GC should persist the next family after exhausting a tiny global limit.' );
eforms_test_assert( file_exists( $rotation_ledger_path ), 'The first tiny batch should leave the later expired family for its persisted turn.' );

$rotation_second = GcRunner::run( array( 'now' => $run_now + 420, 'limit' => 1 ) );
eforms_test_assert( $rotation_second['by_type']['ledger']['scanned'] === 1, 'A fixed seven-minute schedule should resume at the persisted next family.' );
eforms_test_assert( ! file_exists( $rotation_ledger_path ), 'Persisted family rotation should prevent an expired ledger marker from starving.' );
eforms_test_remove_tree( $family_rotation_dir );

$cursor_dir = eforms_test_setup_uploads( 'eforms-gc-family-cursor' );
eforms_test_gc_managed_configure( $cursor_dir );
$cursor_names = array();
$cursor_shard = '';
for ( $index = 0; $index < 4096; $index++ ) {
    $candidate = 'cursor-expired-' . $index;
    $worker_submission_id = 'submission-' . substr( hash( 'sha256', $candidate ), 0, 16 );
    $worker_shard = Helpers::h2( $worker_submission_id );
    if ( ! isset( $cursor_names[ $worker_shard ] ) ) {
        $cursor_names[ $worker_shard ] = array();
    }
    $cursor_names[ $worker_shard ][] = $candidate;
    if ( count( $cursor_names[ $worker_shard ] ) === 2 ) {
        $cursor_shard = $worker_shard;
        break;
    }
}
eforms_test_assert( $cursor_shard !== '', 'The cursor fixture should find two deterministic IDs in one shard.' );
$ordered_names = $cursor_names[ $cursor_shard ];
usort(
    $ordered_names,
    function ( $left, $right ) {
        $left_id = 'submission-' . substr( hash( 'sha256', $left ), 0, 16 );
        $right_id = 'submission-' . substr( hash( 'sha256', $right ), 0, 16 );
        return strcmp( $left_id, $right_id );
    }
);
$cursor_first_fixture = eforms_test_gc_managed_fixture( $cursor_dir, $ordered_names[0], $base - 2, $base + 3600, $base );
$cursor_second_fixture = eforms_test_gc_managed_fixture( $cursor_dir, $ordered_names[1], $base - 2, $base + 3600, $base );
$cursor_first_run = UploadBatchStore::gc_aggregates( 'finalized', $cursor_dir, $run_now, 1 );
eforms_test_assert( ! is_dir( $cursor_first_fixture['path'] ) && is_dir( $cursor_second_fixture['path'] ), 'The first bounded run should delete only the first sorted aggregate.' );
eforms_test_assert(
    $cursor_first_run['cursor'] === array( 'shard' => $cursor_shard, 'aggregate' => basename( $cursor_first_fixture['path'] ) ),
    'Managed GC should persist the last stable aggregate identifier instead of a mutable directory offset.'
);
$cursor_second_run = UploadBatchStore::gc_aggregates( 'finalized', $cursor_dir, $run_now, 1, false, $cursor_first_run['cursor'] );
eforms_test_assert( ! is_dir( $cursor_second_fixture['path'] ) && $cursor_second_run['deleted'] === 1, 'Deleting the cursor entry must not skip the next sorted aggregate.' );
eforms_test_remove_tree( $cursor_dir );

$fatal_cursor_dir = eforms_test_setup_uploads( 'eforms-gc-fatal-cursor' );
eforms_test_gc_managed_configure( $fatal_cursor_dir );
$fatal_names = array();
$fatal_shard = '';
for ( $index = 0; $index < 8192; $index++ ) {
    $candidate = 'fatal-cursor-' . $index;
    $submission_id = 'submission-' . substr( hash( 'sha256', $candidate ), 0, 16 );
    $shard = Helpers::h2( $submission_id );
    if ( ! isset( $fatal_names[ $shard ] ) ) {
        $fatal_names[ $shard ] = array();
    }
    $fatal_names[ $shard ][] = $candidate;
    if ( count( $fatal_names[ $shard ] ) === 3 ) {
        $fatal_shard = $shard;
        break;
    }
}
eforms_test_assert( $fatal_shard !== '', 'The fatal cursor fixture should find three deterministic IDs in one shard.' );
$fatal_ordered_names = $fatal_names[ $fatal_shard ];
usort(
    $fatal_ordered_names,
    function ( $left, $right ) {
        $left_id = 'submission-' . substr( hash( 'sha256', $left ), 0, 16 );
        $right_id = 'submission-' . substr( hash( 'sha256', $right ), 0, 16 );
        return strcmp( $left_id, $right_id );
    }
);
$fatal_first_fixture = eforms_test_gc_managed_fixture( $fatal_cursor_dir, $fatal_ordered_names[0], $base - 2, $base + 3600, $base );
$fatal_second_fixture = eforms_test_gc_managed_fixture( $fatal_cursor_dir, $fatal_ordered_names[1], $base - 2, $base + 3600, $base );
$fatal_third_fixture = eforms_test_gc_managed_fixture( $fatal_cursor_dir, $fatal_ordered_names[2], $base - 2, $base + 3600, $base );
$fatal_capacity_path = $fatal_cursor_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
$fatal_capacity = eforms_test_managed_capacity_record( $fatal_cursor_dir );
$fatal_capacity['total_bytes'] = $fatal_first_fixture['managed_bytes'];
$fatal_capacity['store_bytes']['local'] = $fatal_first_fixture['managed_bytes'];
$fatal_capacity['store_bytes']['worker'] = 0;
eforms_test_assert( ManagedCapacityStore::write( $fatal_capacity_path, $fatal_capacity ), 'The fatal cursor fixture should persist a capacity record that lets only the first aggregate release.' );
$fatal_cursor_run = UploadBatchStore::gc_aggregates( 'finalized', $fatal_cursor_dir, $run_now, 20 );
eforms_test_assert(
    empty( $fatal_cursor_run['ok'] )
        && $fatal_cursor_run['reason'] === 'capacity_inconsistent'
        && $fatal_cursor_run['cursor'] === array( 'shard' => $fatal_shard, 'aggregate' => basename( $fatal_first_fixture['path'] ) )
        && ! is_dir( $fatal_first_fixture['path'] )
        && is_dir( $fatal_second_fixture['path'] )
        && is_dir( $fatal_third_fixture['path'] ),
    'Fatal aggregate GC should persist the last completed cursor and leave unprocessed later aggregates resumable.'
);
eforms_test_remove_tree( $fatal_cursor_dir );

$error_cursor_dir = eforms_test_setup_uploads( 'eforms-gc-nonfatal-cursor' );
eforms_test_gc_managed_configure( $error_cursor_dir );
$error_names = array();
$error_shard = '';
for ( $index = 0; $index < 8192; $index++ ) {
    $candidate = 'nonfatal-cursor-' . $index;
    $submission_id = 'submission-' . substr( hash( 'sha256', $candidate ), 0, 16 );
    $shard = Helpers::h2( $submission_id );
    if ( ! isset( $error_names[ $shard ] ) ) {
        $error_names[ $shard ] = array();
    }
    $error_names[ $shard ][] = $candidate;
    if ( count( $error_names[ $shard ] ) === 3 ) {
        $error_shard = $shard;
        break;
    }
}
eforms_test_assert( $error_shard !== '', 'The nonfatal cursor fixture should find three deterministic IDs in one shard.' );
$error_ordered_names = $error_names[ $error_shard ];
usort(
    $error_ordered_names,
    function ( $left, $right ) {
        $left_id = 'submission-' . substr( hash( 'sha256', $left ), 0, 16 );
        $right_id = 'submission-' . substr( hash( 'sha256', $right ), 0, 16 );
        return strcmp( $left_id, $right_id );
    }
);
$error_first_fixture = eforms_test_gc_managed_fixture( $error_cursor_dir, $error_ordered_names[0], $base - 2, $base + 3600, $base );
$error_second_fixture = eforms_test_gc_managed_fixture( $error_cursor_dir, $error_ordered_names[1], $base - 2, $base + 3600, $base );
$error_third_fixture = eforms_test_gc_managed_fixture( $error_cursor_dir, $error_ordered_names[2], $base - 2, $base + 3600, $base );
file_put_contents( $error_second_fixture['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME, '{"version":6,' );
$error_cursor_run = UploadBatchStore::gc_aggregates( 'finalized', $error_cursor_dir, $run_now, 20 );
eforms_test_assert(
    $error_cursor_run['ok'] === true
        && $error_cursor_run['errors'] === 1
        && $error_cursor_run['reason'] === 'manifest_invalid'
        && $error_cursor_run['cursor'] === array( 'shard' => $error_shard, 'aggregate' => basename( $error_first_fixture['path'] ) )
        && ! is_dir( $error_first_fixture['path'] )
        && is_dir( $error_second_fixture['path'] )
        && ! is_dir( $error_third_fixture['path'] ),
    'Nonfatal aggregate GC errors should return the cursor before the first failed aggregate even after later aggregates succeed.'
);
eforms_test_remove_tree( $error_cursor_dir );

$partial_dir = eforms_test_setup_uploads( 'eforms-gc-partial-batches' );
eforms_test_gc_managed_configure( $partial_dir );
$partial_now = $run_now + Anchors::get( 'TOKEN_TTL_MAX' ) + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$stale_partial_id = eforms_test_gc_batch_id_for_name( 'stale-partial' );
$stale_partial_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $stale_partial_id ) . '/' . $stale_partial_id;
mkdir( $stale_partial_path, 0700, true );
file_put_contents( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $stale_partial_path ), '' );
touch( $stale_partial_path, $run_now );
$temp_partial_id = eforms_test_gc_batch_id_for_name( 'stale-partial-with-manifest-temp' );
$temp_partial_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $temp_partial_id ) . '/' . $temp_partial_id;
mkdir( $temp_partial_path, 0700, true );
file_put_contents( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $temp_partial_path ), '' );
file_put_contents( $temp_partial_path . '/.manifest.json.fedcba9876543210.tmp', '{"version":' );
touch( $temp_partial_path, $run_now );
$fresh_partial_id = eforms_test_gc_batch_id_for_name( 'fresh-partial' );
$fresh_partial_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $fresh_partial_id ) . '/' . $fresh_partial_id;
mkdir( $fresh_partial_path, 0700, true );
file_put_contents( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $fresh_partial_path ), '' );
touch( $fresh_partial_path, $run_now + 1 );
$residue_partial_id = eforms_test_gc_batch_id_for_name( 'partial-with-residue' );
$residue_partial_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $residue_partial_id ) . '/' . $residue_partial_id;
mkdir( $residue_partial_path, 0700, true );
file_put_contents( $residue_partial_path . '/unexpected.pending', 'residue' );
touch( $residue_partial_path, $run_now );
$linked_partial_id = eforms_test_gc_batch_id_for_name( 'linked-partial' );
$linked_partial_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $linked_partial_id ) . '/' . $linked_partial_id;
$linked_target_path = $partial_dir . '/outside-managed-partial';
mkdir( dirname( $linked_partial_path ), 0700, true );
mkdir( $linked_target_path, 0700, true );
file_put_contents( $linked_target_path . '/do-not-delete', 'outside' );
symlink( $linked_target_path, $linked_partial_path );
$linked_files_id = eforms_test_gc_batch_id_for_name( 'linked-files-partial' );
$linked_files_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $linked_files_id ) . '/' . $linked_files_id;
$linked_files_target = $partial_dir . '/outside-managed-files';
mkdir( $linked_files_path, 0700, true );
mkdir( $linked_files_target, 0700, true );
file_put_contents( $linked_files_target . '/do-not-delete', 'outside' );
symlink( $linked_files_target, $linked_files_path . '/unexpected' );
clearstatcache();

$partial_dry_run = UploadBatchStore::gc_aggregates( 'staged', $partial_dir, $partial_now, 500, true );
eforms_test_assert( $partial_dry_run['ok'] === true && $partial_dry_run['candidates'] === 2 && $partial_dry_run['errors'] === 2, 'Dry-run should recognize stale safe partial batches with or without an owner-created manifest temp.' );
eforms_test_assert( is_dir( $stale_partial_path ) && is_dir( $temp_partial_path ) && is_dir( $fresh_partial_path ) && is_dir( $residue_partial_path ) && is_link( $linked_partial_path ), 'Partial-batch dry-run should preserve stale, fresh, recoverable, unrecognized, and symlinked directories.' );
$partial_apply = UploadBatchStore::gc_aggregates( 'staged', $partial_dir, $partial_now, 500 );
eforms_test_assert( $partial_apply['ok'] === true && $partial_apply['deleted'] === 2 && $partial_apply['errors'] === 2, 'Applying managed GC should collect stale manifest-less partial batches, including an abandoned owner temp, and reject unfamiliar or symlinked residue.' );
eforms_test_assert( ! is_dir( $stale_partial_path ) && ! is_dir( $temp_partial_path ) && is_dir( $fresh_partial_path ) && is_file( $residue_partial_path . '/unexpected.pending' ) && is_file( $linked_target_path . '/do-not-delete' ) && is_file( $linked_files_target . '/do-not-delete' ), 'Partial cleanup should delete only stale recognizable aggregates and never traverse symlinked aggregate or residue paths.' );
eforms_test_remove_tree( $partial_dir );

$corrupt_manifest_dir = eforms_test_setup_uploads( 'eforms-gc-corrupt-manifest' );
eforms_test_gc_managed_configure( $corrupt_manifest_dir );
$corrupt_manifest = eforms_test_gc_managed_fixture( $corrupt_manifest_dir, 'corrupt-manifest', $base, $base + 10 );
file_put_contents( $corrupt_manifest['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME, '{invalid' );
$manifest_failure = GcRunner::run( array( 'now' => $run_now ) );
eforms_test_assert( $manifest_failure['ok'] === false && strpos( $manifest_failure['reason'], 'manifest_invalid' ) !== false, 'A corrupt managed manifest should fail closed with an observable reason.' );
eforms_test_assert( is_dir( $corrupt_manifest['path'] ), 'A corrupt managed manifest should preserve its aggregate.' );
eforms_test_remove_tree( $corrupt_manifest_dir );

$unknown_schema_dir = eforms_test_setup_uploads( 'eforms-gc-unknown-manifest-field' );
eforms_test_gc_managed_configure( $unknown_schema_dir );
$unknown_schema = eforms_test_gc_managed_fixture( $unknown_schema_dir, 'unknown-manifest-field', $base, $base + 10 );
$unknown_schema_path = $unknown_schema['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$unknown_schema_manifest = json_decode( file_get_contents( $unknown_schema_path ), true );
$unknown_schema_manifest['processing_state'] = 'pending';
file_put_contents( $unknown_schema_path, json_encode( $unknown_schema_manifest ) );
$unknown_schema_failure = GcRunner::run( array( 'now' => $run_now ) );
eforms_test_assert( $unknown_schema_failure['ok'] === false && strpos( $unknown_schema_failure['reason'], 'manifest_invalid' ) !== false, 'GC should reject a version-4 manifest with an unknown lifecycle field.' );
eforms_test_assert( is_dir( $unknown_schema['path'] ), 'GC should preserve an aggregate whose manifest schema is not exact.' );
eforms_test_remove_tree( $unknown_schema_dir );

$corrupt_capacity_dir = eforms_test_setup_uploads( 'eforms-gc-corrupt-capacity' );
eforms_test_gc_managed_configure( $corrupt_capacity_dir );
$corrupt_capacity = eforms_test_gc_managed_fixture( $corrupt_capacity_dir, 'corrupt-capacity', $base, $base + 10 );
file_put_contents( $corrupt_capacity_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME, '{invalid' );
$capacity_failure = GcRunner::run( array( 'now' => $run_now ) );
eforms_test_assert( $capacity_failure['ok'] === false && strpos( $capacity_failure['reason'], 'capacity_invalid' ) !== false, 'A corrupt managed capacity record should fail closed with an observable reason.' );
eforms_test_assert( is_dir( $corrupt_capacity['path'] ), 'Corrupt capacity accounting should preserve the aggregate.' );

$worker_dir = eforms_test_setup_uploads( 'eforms-gc-worker-tombstones' );
eforms_test_gc_managed_configure( $worker_dir );
$worker_base = 1900000000;
$worker_drain = Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' )
    + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' )
    + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
$worker_validation_drain = Anchors::get( 'WORKER_QUEUE_CONSUMER_MAX_WALL_SECONDS' )
    + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
$worker_accept = $worker_base + $worker_drain + 2000;
$worker_delete_after = $worker_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$worker_fixture = eforms_test_gc_worker_fixture(
    $worker_dir,
    'single-open-batch',
    $worker_base,
    $worker_accept,
    $worker_base + 700,
    $worker_delete_after,
    $worker_base + 100,
    array( 'item', 'intent' )
);
$worker_safe_after = max( $worker_fixture['validation_until'] + $worker_validation_drain, $worker_fixture['deleted_at'] + $worker_drain );
$worker_manifest_before = file_get_contents( $worker_fixture['manifest_path'] );
$worker_capacity_before = eforms_test_managed_capacity_record( $worker_dir );
$worker_equal_calls = 0;
$worker_equal = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    $worker_safe_after,
    20,
    false,
    array(),
    function () use ( &$worker_equal_calls ) {
        $worker_equal_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_equal['ok'] === true
        && $worker_equal['candidates'] === 0
        && $worker_equal['released_bytes'] === 0
        && $worker_equal_calls === 0
        && file_get_contents( $worker_fixture['manifest_path'] ) === $worker_manifest_before
        && eforms_test_managed_capacity_record( $worker_dir ) === $worker_capacity_before,
    'Dormant candidate GC should make no remote call or mutation at the strict safe_after equality boundary.'
);

$worker_dry_calls = 0;
$worker_dry = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    $worker_safe_after + 1,
    20,
    true,
    array(),
    function () use ( &$worker_dry_calls ) {
        $worker_dry_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_dry['ok'] === true
        && $worker_dry['candidates'] === 1
        && $worker_dry['candidate_bytes'] === $worker_fixture['bytes']
        && $worker_dry['released_bytes'] === 0
        && $worker_dry_calls === 0
        && file_get_contents( $worker_fixture['manifest_path'] ) === $worker_manifest_before
        && eforms_test_managed_capacity_record( $worker_dir ) === $worker_capacity_before,
    'Dormant candidate GC dry-run should report the open-batch tombstones without manifest, capacity, or remote mutation.'
);

$worker_success_calls = array();
$worker_lock_checks = array();
$worker_success_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    $worker_safe_after + 1,
    20,
    false,
    array(),
    function ( $authority ) use ( &$worker_success_calls, &$worker_lock_checks, $worker_dir, $worker_fixture ) {
        $worker_success_calls[] = $authority;
        $capacity_lock = ManagedCapacityStore::acquire_lock( $worker_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_LOCK_FILENAME, true, true );
        $aggregate_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $worker_fixture['path'] );
        $aggregate_lock = fopen( $aggregate_lock_path, 'r+b' );
        $aggregate_free = is_resource( $aggregate_lock ) && flock( $aggregate_lock, LOCK_EX | LOCK_NB );
        $worker_lock_checks[] = array(
            'capacity' => is_resource( $capacity_lock ),
            'aggregate' => $aggregate_free,
        );
        if ( $aggregate_free ) {
            flock( $aggregate_lock, LOCK_UN );
        }
        if ( is_resource( $aggregate_lock ) ) {
            fclose( $aggregate_lock );
        }
        if ( is_resource( $capacity_lock ) ) {
            flock( $capacity_lock, LOCK_UN );
            fclose( $capacity_lock );
        }
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_success_manifest = json_decode( file_get_contents( $worker_fixture['manifest_path'] ), true );
$worker_success_capacity = eforms_test_managed_capacity_record( $worker_dir );
$expected_success_authority = array();
foreach ( $worker_fixture['uploads'] as $upload ) {
    $tombstone = $worker_success_manifest['tombstones'][ $upload['upload_id'] ];
    $expected_success_authority[] = array(
        'upload_id' => $upload['upload_id'],
        'storage_identity' => $worker_fixture['identity'],
        'expected_composition_fingerprint' => $worker_fixture['identity'],
        'validation_contract_version' => 'validation-v1',
        'object_key' => $tombstone['object_key'],
        'object_version' => $upload['object_version'],
        'etag' => $upload['etag'],
        'bytes' => $upload['bytes'],
        'policy_fingerprint' => $tombstone['policy_fingerprint'],
    );
}
eforms_test_assert(
    $worker_success_result['ok'] === true
        && $worker_success_result['candidates'] === 1
        && $worker_success_result['released_bytes'] === $worker_fixture['bytes']
        && $worker_success_capacity['total_bytes'] === 0
        && count( $worker_success_calls ) === 2
        && array_keys( $worker_success_calls[0] ) === array_keys( $expected_success_authority[0] )
        && $worker_success_calls === $expected_success_authority
        && $worker_lock_checks === array(
            array( 'capacity' => true, 'aggregate' => true ),
            array( 'capacity' => true, 'aggregate' => true ),
        )
        && ! empty( $worker_success_manifest['tombstones'][ $worker_fixture['uploads'][0]['upload_id'] ]['capacity_release_started'] )
        && ! empty( $worker_success_manifest['tombstones'][ $worker_fixture['uploads'][1]['upload_id'] ]['capacity_release_started'] )
        && ! empty( $worker_success_manifest['tombstones'][ $worker_fixture['uploads'][0]['upload_id'] ]['capacity_released'] )
        && ! empty( $worker_success_manifest['tombstones'][ $worker_fixture['uploads'][1]['upload_id'] ]['capacity_released'] ),
    'Dormant candidate GC should delete known-version and dash-version tombstones with exact authority outside both locks and release exact capacity once.'
);
$worker_success_retry_calls = 0;
$worker_success_retry = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    $worker_safe_after + 2,
    20,
    false,
    array(),
    function () use ( &$worker_success_retry_calls ) {
        $worker_success_retry_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_success_retry['ok'] === true
        && $worker_success_retry['candidates'] === 0
        && $worker_success_retry['released_bytes'] === 0
        && $worker_success_retry_calls === 0
        && eforms_test_managed_capacity_record( $worker_dir ) === $worker_success_capacity,
    'Dormant candidate GC retry should be idempotent after confirmed absence.'
);

$worker_validation = eforms_test_gc_worker_fixture(
    $worker_dir,
    'validation-dominates',
    $worker_base + 3000,
    $worker_base + 3000 + $worker_drain + 4000,
    $worker_base + 3100 + $worker_drain + 100,
    $worker_base + 3000 + $worker_drain + 4000 + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 3100,
    array( 'item' )
);
$worker_validation_calls = 0;
$worker_validation_safe_after = $worker_validation['validation_until'] + $worker_validation_drain;
$worker_validation_equal = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    $worker_validation_safe_after,
    20,
    false,
    array(),
    function () use ( &$worker_validation_calls ) {
        $worker_validation_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_validation_next = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    $worker_validation_safe_after + 1,
    20,
    false,
    array(),
    function () use ( &$worker_validation_calls ) {
        $worker_validation_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_validation_safe_after > $worker_validation['deleted_at'] + $worker_drain
        && $worker_validation_equal['ok'] === true
        && $worker_validation_equal['candidates'] === 0
        && $worker_validation_next['released_bytes'] === $worker_validation['bytes']
        && $worker_validation_calls === 1,
    'Worker validation drain should dominate deleted_at plus upload drain, with equality retained and next-second cleanup.'
);

$worker_failure = eforms_test_gc_worker_fixture(
    $worker_dir,
    'partial-failure',
    $worker_base + 6000,
    $worker_base + 6000 + $worker_drain + 4000,
    $worker_base + 6500,
    $worker_base + 6000 + $worker_drain + 4000 + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 6100,
    array( 'item', 'intent', 'item' )
);
$worker_failure_safe_after = max( $worker_failure['validation_until'] + $worker_validation_drain, $worker_failure['deleted_at'] + $worker_drain );
$worker_failure_capacity_before = eforms_test_managed_capacity_record( $worker_dir );
$worker_failure_calls = array();
$worker_failure_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    $worker_failure_safe_after + 1,
    20,
    false,
    array(),
    function ( $authority ) use ( &$worker_failure_calls ) {
        $worker_failure_calls[] = $authority;
        return count( $worker_failure_calls ) === 2
            ? array( 'ok' => false, 'reason' => 'provider_unavailable' )
            : array( 'ok' => true, 'absent' => true );
    }
);
$worker_failure_manifest = json_decode( file_get_contents( $worker_failure['manifest_path'] ), true );
$worker_failure_capacity_after = eforms_test_managed_capacity_record( $worker_dir );
$worker_failure_cursor = array(
    'shard' => basename( dirname( $worker_failure['path'] ) ),
    'aggregate' => basename( $worker_failure['path'] ),
);
$worker_failure_phases = array();
foreach ( $worker_failure['uploads'] as $upload ) {
    $tombstone = $worker_failure_manifest['tombstones'][ $upload['upload_id'] ];
    $worker_failure_phases[] = array(
        'started' => $tombstone['capacity_release_started'],
        'released' => $tombstone['capacity_released'],
    );
}
eforms_test_assert(
    empty( $worker_failure_result['ok'] )
        && $worker_failure_result['reason'] === 'remote_delete_failed'
        && is_array( $worker_failure_result['cursor'] )
        && $worker_failure_result['cursor'] !== $worker_failure_cursor
        && count( $worker_failure_calls ) === 2
        && $worker_failure_capacity_after['total_bytes'] === $worker_failure_capacity_before['total_bytes']
        && $worker_failure_phases === array(
            array( 'started' => true, 'released' => false ),
            array( 'started' => true, 'released' => false ),
            array( 'started' => true, 'released' => false ),
        ),
    'Worker partial provider failure should stop at the first failed target, retain charge, keep the cursor before the failed aggregate, and leave all selected tombstones started but unreleased.'
);
$worker_failure_retry_calls = 0;
$worker_failure_retry = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    $worker_failure_safe_after + 2,
    20,
    false,
    array(),
    function () use ( &$worker_failure_retry_calls ) {
        $worker_failure_retry_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_failure_retry_manifest = json_decode( file_get_contents( $worker_failure['manifest_path'] ), true );
$worker_failure_retry_capacity = eforms_test_managed_capacity_record( $worker_dir );
$worker_failure_retry_released = true;
foreach ( $worker_failure['uploads'] as $upload ) {
    $worker_failure_retry_released = $worker_failure_retry_released
        && ! empty( $worker_failure_retry_manifest['tombstones'][ $upload['upload_id'] ]['capacity_released'] );
}
eforms_test_assert(
    $worker_failure_retry['ok'] === true
        && $worker_failure_retry_calls === 3
        && $worker_failure_retry['released_bytes'] === $worker_failure['bytes']
        && $worker_failure_retry_capacity['total_bytes'] === $worker_failure_capacity_before['total_bytes'] - $worker_failure['bytes']
        && $worker_failure_retry_released,
    'Worker partial-failure retry should treat prior successful remote deletes as idempotent and release all selected tombstones once all report absent.'
);

$worker_repair = eforms_test_gc_worker_fixture(
    $worker_dir,
    'repair-only',
    $worker_base + 9000,
    $worker_base + 9000 + $worker_drain + 4000,
    $worker_base + 9500,
    $worker_base + 9000 + $worker_drain + 4000 + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 9100,
    array( 'intent' )
);
$worker_repair_manifest = json_decode( file_get_contents( $worker_repair['manifest_path'] ), true );
$worker_repair_upload_id = $worker_repair['uploads'][0]['upload_id'];
	$worker_repair_manifest['tombstones'][ $worker_repair_upload_id ]['capacity_release_started'] = true;
	$worker_repair_manifest['tombstones'][ $worker_repair_upload_id ]['capacity_released'] = true;
	file_put_contents( $worker_repair['manifest_path'], json_encode( $worker_repair_manifest, JSON_UNESCAPED_SLASHES ) );
	$worker_capacity_path = $worker_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
	$worker_repair_capacity_before = eforms_test_managed_capacity_record( $worker_dir );
	$worker_repair_reservation_id = hash( 'sha256', $worker_repair_manifest['batch_id'] . "\0" . $worker_repair_upload_id );
	$worker_repair_bad_capacity = $worker_repair_capacity_before;
	$worker_repair_bad_capacity['reservations'][ $worker_repair_reservation_id ]['validation_contract_version'] = 'validation-v2';
	eforms_test_assert( ManagedCapacityStore::write( $worker_capacity_path, $worker_repair_bad_capacity ), 'The Worker repair-only fixture should persist mismatched retained reservation authority.' );
	$worker_repair_mismatch_calls = 0;
	$worker_repair_mismatch = UploadBatchStore::gc_aggregates(
	    'staged',
	    $worker_dir,
	    max( $worker_repair['validation_until'] + $worker_validation_drain, $worker_repair['deleted_at'] + $worker_drain ) + 1,
	    20,
	    false,
	    array(),
	    function () use ( &$worker_repair_mismatch_calls ) {
	        $worker_repair_mismatch_calls++;
	        return array( 'ok' => true, 'absent' => true );
	    }
	);
	eforms_test_assert(
	    empty( $worker_repair_mismatch['ok'] )
	        && $worker_repair_mismatch['reason'] === 'capacity_inconsistent'
	        && $worker_repair_mismatch_calls === 0
	        && eforms_test_managed_capacity_record( $worker_dir ) === $worker_repair_bad_capacity,
	    'Worker repair-only GC must fail closed instead of settling a capacity_released tombstone reservation whose exact cleanup authority mismatches.'
	);
	eforms_test_assert( ManagedCapacityStore::write( $worker_capacity_path, $worker_repair_capacity_before ), 'The Worker repair-only fixture should restore exact reservation authority.' );
	$worker_repair_calls = 0;
	$worker_repair_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    max( $worker_repair['validation_until'] + $worker_validation_drain, $worker_repair['deleted_at'] + $worker_drain ) + 1,
    20,
    false,
    array(),
    function () use ( &$worker_repair_calls ) {
        $worker_repair_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_repair_capacity_after = eforms_test_managed_capacity_record( $worker_dir );
$worker_repair_retry = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    max( $worker_repair['validation_until'] + $worker_validation_drain, $worker_repair['deleted_at'] + $worker_drain ) + 2,
    20,
    false,
    array(),
    function () use ( &$worker_repair_calls ) {
        $worker_repair_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_repair_result['ok'] === true
        && $worker_repair_result['released_bytes'] === $worker_repair['bytes']
        && $worker_repair_capacity_after['total_bytes'] === $worker_repair_capacity_before['total_bytes'] - $worker_repair['bytes']
        && $worker_repair_calls === 0
        && $worker_repair_retry['released_bytes'] === 0
        && eforms_test_managed_capacity_record( $worker_dir ) === $worker_repair_capacity_after,
    'Worker repair-only tombstones should settle retained capacity once without remote calls.'
);

$worker_manifest_race = eforms_test_gc_worker_fixture(
    $worker_dir,
    'manifest-race',
    $worker_base + 12000,
    $worker_base + 12000 + $worker_drain + 4000,
    $worker_base + 12500,
    $worker_base + 12000 + $worker_drain + 4000 + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 12100,
    array( 'item' )
);
$worker_manifest_race_capacity_before = eforms_test_managed_capacity_record( $worker_dir );
$worker_manifest_race_calls = 0;
$worker_manifest_race_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_dir,
    max( $worker_manifest_race['validation_until'] + $worker_validation_drain, $worker_manifest_race['deleted_at'] + $worker_drain ) + 1,
    20,
    false,
    array(),
    function () use ( &$worker_manifest_race_calls, $worker_manifest_race ) {
        $worker_manifest_race_calls++;
        $manifest = json_decode( file_get_contents( $worker_manifest_race['manifest_path'] ), true );
        $upload_id = $worker_manifest_race['uploads'][0]['upload_id'];
        $manifest['tombstones'][ $upload_id ]['capacity_release_started'] = false;
        file_put_contents( $worker_manifest_race['manifest_path'], json_encode( $manifest, JSON_UNESCAPED_SLASHES ) );
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_manifest_race_after = json_decode( file_get_contents( $worker_manifest_race['manifest_path'] ), true );
$worker_manifest_race_upload_id = $worker_manifest_race['uploads'][0]['upload_id'];
eforms_test_assert(
    $worker_manifest_race_result['ok'] === true
        && $worker_manifest_race_result['errors'] === 1
        && $worker_manifest_race_result['reason'] === 'remote_state_changed'
        && $worker_manifest_race_calls === 1
        && $worker_manifest_race_after['tombstones'][ $worker_manifest_race_upload_id ]['capacity_release_started'] === false
        && empty( $worker_manifest_race_after['tombstones'][ $worker_manifest_race_upload_id ]['capacity_released'] )
        && eforms_test_managed_capacity_record( $worker_dir )['total_bytes'] === $worker_manifest_race_capacity_before['total_bytes'],
    'Worker manifest mutation during lock-free remote work should report remote_state_changed, retain the valid mutation, and preserve charged unreleased capacity.'
);

$worker_capacity_dir = eforms_test_setup_uploads( 'eforms-gc-worker-capacity-race' );
eforms_test_gc_managed_configure( $worker_capacity_dir );
$worker_capacity_race = eforms_test_gc_worker_fixture(
    $worker_capacity_dir,
    'capacity-race',
    $worker_base + 15000,
    $worker_base + 15000 + $worker_drain + 4000,
    $worker_base + 15500,
    $worker_base + 15000 + $worker_drain + 4000 + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 15100,
    array( 'intent' )
);
$worker_capacity_extra_accept = $worker_base + 15000 + $worker_drain + 5000;
$worker_capacity_extra_secret = eforms_test_managed_batch_secret( "\x5c" );
$worker_capacity_extra_identity = hash( 'sha256', 'worker-gc-capacity-race-extra' );
$worker_capacity_extra_created = UploadBatchStore::create_batch(
    array(
        'raw_token' => 'worker-gc-capacity-race-extra',
        'form_id' => 'virtual-quote',
        'instance_id' => 'worker-gc-capacity-race-extra',
        'field_key' => 'project_photos',
        'accept_until' => $worker_capacity_extra_accept,
    ),
    $worker_capacity_extra_secret,
    array(
        'type' => 'files',
        'upload_mode' => 'staged',
        'accept' => array( 'image' ),
        'max_file_bytes' => 1048576,
        'max_files' => 1,
        'max_total_bytes' => 1048576,
    ),
    $worker_capacity_dir,
    $worker_base + 15000,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    $worker_capacity_extra_identity
);
eforms_test_assert( ! empty( $worker_capacity_extra_created['ok'] ), 'Worker capacity-race fixture should create an unrelated prepared aggregate.' );
$worker_capacity_race_capacity_before = eforms_test_managed_capacity_record( $worker_capacity_dir );
$worker_capacity_race_calls = 0;
$worker_capacity_race_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_capacity_dir,
    max( $worker_capacity_race['validation_until'] + $worker_validation_drain, $worker_capacity_race['deleted_at'] + $worker_drain ) + 1,
    20,
    false,
    array(),
    function () use ( &$worker_capacity_race_calls, $worker_capacity_dir, $worker_base, $worker_capacity_extra_accept, $worker_capacity_extra_secret, $worker_capacity_extra_identity, $worker_capacity_extra_created ) {
        $worker_capacity_race_calls++;
        $authorized = UploadBatchStore::worker_authorize_intent(
            $worker_capacity_extra_created['batch']['batch_id'],
            $worker_capacity_extra_secret,
            'cg_capacity_extra',
            0,
            'capacity-extra.png',
            1234,
            'image/png',
            $worker_capacity_dir,
            array(
                'now' => $worker_base + 15010,
                'storage_identity' => $worker_capacity_extra_identity,
                'validation_contract_version' => 'validation-v1',
                'upload_until' => $worker_base + 15120,
                'accept_until' => $worker_capacity_extra_accept,
                'validation_until' => $worker_capacity_extra_accept + 100,
                'staged_delete_after' => $worker_capacity_extra_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
            )
        );
        eforms_test_assert( ! empty( $authorized['ok'] ), 'Worker capacity-race callback should make a normal unrelated reservation: ' . json_encode( $authorized ) );
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_capacity_race_after = json_decode( file_get_contents( $worker_capacity_race['manifest_path'] ), true );
$worker_capacity_race_capacity_after = eforms_test_managed_capacity_record( $worker_capacity_dir );
$worker_capacity_race_upload_id = $worker_capacity_race['uploads'][0]['upload_id'];
$worker_capacity_extra_reservation_found = false;
foreach ( $worker_capacity_race_capacity_after['reservations'] as $reservation ) {
    $worker_capacity_extra_reservation_found = $worker_capacity_extra_reservation_found
        || ( is_array( $reservation ) && isset( $reservation['upload_id'] ) && $reservation['upload_id'] === 'cg_capacity_extra' );
}
eforms_test_assert(
    $worker_capacity_race_result['ok'] === true
        && $worker_capacity_race_result['errors'] === 1
        && $worker_capacity_race_result['reason'] === 'remote_state_changed'
        && $worker_capacity_race_calls === 1
        && empty( $worker_capacity_race_after['tombstones'][ $worker_capacity_race_upload_id ]['capacity_released'] )
        && $worker_capacity_race_capacity_after['total_bytes'] === $worker_capacity_race_capacity_before['total_bytes'] + 1234
        && $worker_capacity_extra_reservation_found,
    'Worker capacity mutation through a normal unrelated authorization should report remote_state_changed, preserve target charge, and retain the unrelated reservation: ' . json_encode(
        array(
            'result' => $worker_capacity_race_result,
            'calls' => $worker_capacity_race_calls,
        )
    )
);
eforms_test_remove_tree( $worker_capacity_dir );

$worker_other_family_calls = 0;
$worker_other_family = UploadBatchStore::gc_aggregates(
    'unsupported',
    $worker_dir,
    max( $worker_capacity_race['validation_until'] + $worker_validation_drain, $worker_capacity_race['deleted_at'] + $worker_drain ) + 1,
    20,
    false,
    array(),
    function () use ( &$worker_other_family_calls ) {
        $worker_other_family_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_other_family['ok'] === true
        && $worker_other_family['scanned'] === 0
        && $worker_other_family['candidates'] === 0
        && $worker_other_family_calls === 0,
    'Dormant candidate GC should return an empty page with no callback for unsupported families.'
);
eforms_test_remove_tree( $worker_dir );

$worker_finalized_fixture_dir = eforms_test_setup_uploads( 'eforms-gc-worker-finalized-helper' );
eforms_test_gc_managed_configure( $worker_finalized_fixture_dir );
$worker_finalized_fixture_accept = $worker_base + $worker_drain + 1750;
$worker_finalized_fixture_staged_delete_after = $worker_finalized_fixture_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$worker_finalized_fixture = eforms_test_gc_worker_finalized_fixture(
    $worker_finalized_fixture_dir,
    'finalized-helper',
    $worker_base + 70,
    $worker_finalized_fixture_accept,
    $worker_base + 770,
    $worker_finalized_fixture_staged_delete_after,
    $worker_base + 870
);
$worker_finalized_manifest = json_decode( file_get_contents( $worker_finalized_fixture['manifest_path'] ), true );
$worker_finalized_item = $worker_finalized_fixture['item'];
$worker_finalized_submission = UploadBatchStore::worker_submission(
    $worker_finalized_fixture['submission_id'],
    $worker_finalized_fixture_dir,
    $worker_finalized_fixture['finalized_at']
);
eforms_test_assert(
    ! empty( $worker_finalized_submission['ok'] )
        && $worker_finalized_fixture['path'] === $worker_finalized_fixture_dir . '/eforms-private/' . UploadBatchStore::SUBMISSIONS_DIR . '/' . Helpers::h2( $worker_finalized_fixture['submission_id'] ) . '/' . $worker_finalized_fixture['submission_id']
        && is_file( $worker_finalized_fixture['manifest_path'] )
        && is_file( $worker_finalized_fixture['review_snapshot_path'] )
        && is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $worker_finalized_fixture['path'] ) )
        && $worker_finalized_manifest['state'] === 'finalized'
        && $worker_finalized_manifest['batch_id'] === $worker_finalized_fixture['batch_id']
        && $worker_finalized_manifest['claim']['submission_id'] === $worker_finalized_fixture['submission_id']
        && $worker_finalized_manifest['finalized_at'] === $worker_finalized_fixture['finalized_at']
        && $worker_finalized_manifest['delete_after'] === $worker_finalized_fixture['delete_after']
        && $worker_finalized_manifest['intents'] === array()
        && isset( $worker_finalized_manifest['items'][ $worker_finalized_item['upload_id'] ] )
        && $worker_finalized_manifest['items'][ $worker_finalized_item['upload_id'] ]['object_version'] === $worker_finalized_item['object_version']
        && $worker_finalized_submission['submission']['delete_after'] === $worker_finalized_fixture['delete_after'],
    'Worker finalized fixture helper should produce a strict finalized manifest under submissions with review sidecar and internal lock.'
);
$worker_finalized_safe_after = max( $worker_finalized_fixture['validation_until'] + $worker_validation_drain, $worker_finalized_fixture['delete_after'] + $worker_drain );
$worker_finalized_capacity_before = eforms_test_managed_capacity_record( $worker_finalized_fixture_dir );
$worker_finalized_equal_calls = 0;
$worker_finalized_equal = UploadBatchStore::gc_aggregates(
    'finalized',
    $worker_finalized_fixture_dir,
    $worker_finalized_safe_after,
    20,
    false,
    array(),
    function () use ( &$worker_finalized_equal_calls ) {
        $worker_finalized_equal_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_finalized_equal_manifest = json_decode( file_get_contents( $worker_finalized_fixture['manifest_path'] ), true );
$worker_finalized_upload_id = $worker_finalized_item['upload_id'];
eforms_test_assert(
    $worker_finalized_equal['ok'] === true
        && $worker_finalized_equal['candidates'] === 1
        && $worker_finalized_equal['released_bytes'] === 0
        && $worker_finalized_equal_calls === 0
        && $worker_finalized_equal_manifest['state'] === 'finalized'
        && $worker_finalized_equal_manifest['items'] === array()
        && isset( $worker_finalized_equal_manifest['tombstones'][ $worker_finalized_upload_id ] )
        && empty( $worker_finalized_equal_manifest['tombstones'][ $worker_finalized_upload_id ]['capacity_release_started'] )
        && empty( $worker_finalized_equal_manifest['tombstones'][ $worker_finalized_upload_id ]['capacity_released'] )
        && is_file( $worker_finalized_fixture['review_snapshot_path'] )
        && is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $worker_finalized_fixture['path'] ) )
        && eforms_test_managed_capacity_record( $worker_finalized_fixture_dir ) === $worker_finalized_capacity_before,
    'Finalized candidate GC should convert at the numeric equality boundary without calling the Worker or releasing capacity.'
);
$worker_finalized_authority = array(
    'upload_id' => $worker_finalized_upload_id,
    'storage_identity' => $worker_finalized_fixture['identity'],
    'expected_composition_fingerprint' => $worker_finalized_fixture['identity'],
    'validation_contract_version' => 'validation-v1',
    'object_key' => $worker_finalized_item['object_key'],
    'object_version' => $worker_finalized_item['object_version'],
    'etag' => $worker_finalized_item['etag'],
    'bytes' => $worker_finalized_item['bytes'],
    'policy_fingerprint' => $worker_finalized_equal_manifest['tombstones'][ $worker_finalized_upload_id ]['policy_fingerprint'],
);
$worker_finalized_release_calls = array();
$worker_finalized_lock_checks = array();
$worker_finalized_release = UploadBatchStore::gc_aggregates(
    'finalized',
    $worker_finalized_fixture_dir,
    $worker_finalized_safe_after + 1,
    20,
    false,
    array(),
    function ( $authority ) use ( &$worker_finalized_release_calls, &$worker_finalized_lock_checks, $worker_finalized_fixture_dir, $worker_finalized_fixture ) {
        $worker_finalized_release_calls[] = $authority;
        $capacity_lock = ManagedCapacityStore::acquire_lock( $worker_finalized_fixture_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_LOCK_FILENAME, true, true, true );
        $aggregate_lock = ManagedCapacityStore::acquire_lock( UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $worker_finalized_fixture['path'] ), true, true, true );
        $worker_finalized_lock_checks[] = array(
            'capacity' => is_resource( $capacity_lock ),
            'aggregate' => is_resource( $aggregate_lock ),
        );
        if ( is_resource( $aggregate_lock ) ) {
            flock( $aggregate_lock, LOCK_UN );
            fclose( $aggregate_lock );
        }
        if ( is_resource( $capacity_lock ) ) {
            flock( $capacity_lock, LOCK_UN );
            fclose( $capacity_lock );
        }
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_finalized_release_manifest = json_decode( file_get_contents( $worker_finalized_fixture['manifest_path'] ), true );
$worker_finalized_capacity_after_release = eforms_test_managed_capacity_record( $worker_finalized_fixture_dir );
eforms_test_assert(
    $worker_finalized_release['ok'] === true
        && $worker_finalized_release['released_bytes'] === $worker_finalized_item['bytes']
        && $worker_finalized_release_calls === array( $worker_finalized_authority )
        && $worker_finalized_lock_checks === array( array( 'capacity' => true, 'aggregate' => true ) )
        && ! empty( $worker_finalized_release_manifest['tombstones'][ $worker_finalized_upload_id ]['capacity_release_started'] )
        && ! empty( $worker_finalized_release_manifest['tombstones'][ $worker_finalized_upload_id ]['capacity_released'] )
        && $worker_finalized_capacity_after_release['total_bytes'] === $worker_finalized_capacity_before['total_bytes'] - $worker_finalized_item['bytes']
        && is_file( $worker_finalized_fixture['manifest_path'] )
        && is_file( $worker_finalized_fixture['review_snapshot_path'] )
        && is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $worker_finalized_fixture['path'] ) ),
    'Finalized candidate GC should delete through exact result-aware authority outside the internal submissions lock and settle capacity once.'
);
$worker_finalized_delete = UploadBatchStore::gc_aggregates(
    'finalized',
    $worker_finalized_fixture_dir,
    $worker_finalized_safe_after + 2,
    20,
    false,
    array(),
    function () {
        return array( 'ok' => false, 'reason' => 'unexpected_remote_call' );
    }
);
$worker_finalized_empty = UploadBatchStore::gc_aggregates(
    'finalized',
    $worker_finalized_fixture_dir,
    $worker_finalized_safe_after + 3,
    20,
    false,
    array(),
    function () {
        return array( 'ok' => false, 'reason' => 'unexpected_remote_call' );
    }
);
$worker_finalized_capacity_after_delete = eforms_test_managed_capacity_record( $worker_finalized_fixture_dir );
eforms_test_assert(
    $worker_finalized_delete['ok'] === true
        && $worker_finalized_delete['deleted'] === 1
        && $worker_finalized_delete['released_bytes'] === 0
        && $worker_finalized_empty['ok'] === true
        && $worker_finalized_empty['scanned'] === 0
        && ! is_dir( $worker_finalized_fixture['path'] )
        && ! is_file( $worker_finalized_fixture['manifest_path'] )
        && ! is_file( $worker_finalized_fixture['review_snapshot_path'] )
        && ! is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $worker_finalized_fixture['path'] ) )
        && is_array( $worker_finalized_capacity_after_delete )
        && $worker_finalized_capacity_after_delete['total_bytes'] === 0
        && $worker_finalized_capacity_after_delete['reservations'] === array()
        && $worker_finalized_capacity_after_delete['releases'] === array(),
    'Finalized candidate GC should remove receipt-backed finalized manifest, review sidecar, and internal lock, then retry empty.'
);
eforms_test_remove_tree( $worker_finalized_fixture_dir );

$worker_finalized_null_dir = eforms_test_setup_uploads( 'eforms-gc-worker-finalized-null' );
eforms_test_gc_managed_configure( $worker_finalized_null_dir );
$worker_finalized_null = eforms_test_gc_worker_finalized_fixture(
    $worker_finalized_null_dir,
    'finalized-null-retention',
    $worker_base + 170,
    $worker_base + $worker_drain + 1950,
    $worker_base + 970,
    $worker_base + $worker_drain + 1950 + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 1070
);
$worker_finalized_null_manifest = json_decode( file_get_contents( $worker_finalized_null['manifest_path'] ), true );
$worker_finalized_null_manifest['delete_after'] = null;
file_put_contents( $worker_finalized_null['manifest_path'], json_encode( $worker_finalized_null_manifest, JSON_UNESCAPED_SLASHES ) );
$worker_finalized_null_before = file_get_contents( $worker_finalized_null['manifest_path'] );
$worker_finalized_null_capacity = eforms_test_managed_capacity_record( $worker_finalized_null_dir );
$worker_finalized_null_calls = 0;
$worker_finalized_null_gc = UploadBatchStore::gc_aggregates(
    'finalized',
    $worker_finalized_null_dir,
    $worker_base + 10000000,
    20,
    false,
    array(),
    function () use ( &$worker_finalized_null_calls ) {
        $worker_finalized_null_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_finalized_null_gc['ok'] === true
        && $worker_finalized_null_gc['candidates'] === 0
        && $worker_finalized_null_calls === 0
        && file_get_contents( $worker_finalized_null['manifest_path'] ) === $worker_finalized_null_before
        && eforms_test_managed_capacity_record( $worker_finalized_null_dir ) === $worker_finalized_null_capacity
        && is_file( $worker_finalized_null['review_snapshot_path'] )
        && is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::SUBMISSIONS_DIR, $worker_finalized_null['path'] ) ),
    'Finalized candidate GC should retain delete_after=null submissions without remote calls or mutation.'
);
eforms_test_remove_tree( $worker_finalized_null_dir );

$worker_aggregate_expiry_dir = eforms_test_setup_uploads( 'eforms-gc-worker-aggregate-expiry' );
eforms_test_gc_managed_configure( $worker_aggregate_expiry_dir );
$worker_aggregate_expiry_accept = $worker_base + $worker_drain + 1800;
$worker_aggregate_expiry_delete_after = $worker_aggregate_expiry_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$worker_aggregate_expiry = eforms_test_gc_worker_fixture(
    $worker_aggregate_expiry_dir,
    'aggregate-expiry',
    $worker_base,
    $worker_aggregate_expiry_accept,
    $worker_base + 700,
    $worker_aggregate_expiry_delete_after,
    $worker_aggregate_expiry_delete_after - 10,
    array( 'active_item', 'live_intent', 'item' )
);
$worker_aggregate_expiry_manifest_before = json_decode( file_get_contents( $worker_aggregate_expiry['manifest_path'] ), true );
$worker_aggregate_expiry_capacity_before = eforms_test_managed_capacity_record( $worker_aggregate_expiry_dir );
$worker_aggregate_expiry_calls = 0;
$worker_aggregate_expiry_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_aggregate_expiry_dir,
    $worker_aggregate_expiry_delete_after,
    20,
    false,
    array(),
    function () use ( &$worker_aggregate_expiry_calls ) {
        $worker_aggregate_expiry_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_aggregate_expiry_manifest_after = json_decode( file_get_contents( $worker_aggregate_expiry['manifest_path'] ), true );
$worker_aggregate_expiry_capacity_after = eforms_test_managed_capacity_record( $worker_aggregate_expiry_dir );
$worker_aggregate_expiry_item = $worker_aggregate_expiry['uploads'][0];
$worker_aggregate_expiry_intent = $worker_aggregate_expiry['uploads'][1];
$worker_aggregate_expiry_existing = $worker_aggregate_expiry['uploads'][2];
$worker_aggregate_expiry_source_item = $worker_aggregate_expiry_manifest_before['items'][ $worker_aggregate_expiry_item['upload_id'] ];
$worker_aggregate_expiry_source_intent = $worker_aggregate_expiry_manifest_before['intents'][ $worker_aggregate_expiry_intent['upload_id'] ];
$worker_aggregate_expiry_item_tombstone = $worker_aggregate_expiry_manifest_after['tombstones'][ $worker_aggregate_expiry_item['upload_id'] ];
$worker_aggregate_expiry_intent_tombstone = $worker_aggregate_expiry_manifest_after['tombstones'][ $worker_aggregate_expiry_intent['upload_id'] ];
$worker_aggregate_expiry_existing_before = $worker_aggregate_expiry_manifest_before['tombstones'][ $worker_aggregate_expiry_existing['upload_id'] ];
$worker_aggregate_expiry_expected_keys = array(
    'bytes', 'capacity_release_started', 'capacity_released', 'deleted_at', 'etag', 'object_key',
    'object_version', 'policy_fingerprint', 'storage_identity', 'validation_contract_version',
    'validation_until',
);
$worker_aggregate_expiry_item_keys = array_keys( $worker_aggregate_expiry_item_tombstone );
$worker_aggregate_expiry_intent_keys = array_keys( $worker_aggregate_expiry_intent_tombstone );
sort( $worker_aggregate_expiry_expected_keys, SORT_STRING );
sort( $worker_aggregate_expiry_item_keys, SORT_STRING );
sort( $worker_aggregate_expiry_intent_keys, SORT_STRING );
eforms_test_assert(
    $worker_aggregate_expiry_result['ok'] === true
        && $worker_aggregate_expiry_result['candidates'] === 1
        && $worker_aggregate_expiry_result['candidate_bytes'] === $worker_aggregate_expiry_item['bytes'] + $worker_aggregate_expiry_intent['bytes']
        && $worker_aggregate_expiry_result['candidate_artifact_bytes'] === $worker_aggregate_expiry_item['bytes'] + $worker_aggregate_expiry_intent['bytes']
        && $worker_aggregate_expiry_result['deleted'] === 0
        && $worker_aggregate_expiry_result['released_bytes'] === 0
        && $worker_aggregate_expiry_calls === 0
        && is_dir( $worker_aggregate_expiry['path'] )
        && is_file( $worker_aggregate_expiry['manifest_path'] )
        && $worker_aggregate_expiry_manifest_after['intents'] === array()
        && $worker_aggregate_expiry_manifest_after['items'] === array()
        && $worker_aggregate_expiry_manifest_after['artifact_bytes'] === $worker_aggregate_expiry_manifest_before['artifact_bytes'] - $worker_aggregate_expiry_item['bytes']
        && $worker_aggregate_expiry_manifest_after['tombstones'][ $worker_aggregate_expiry_existing['upload_id'] ] === $worker_aggregate_expiry_existing_before
        && $worker_aggregate_expiry_item_keys === $worker_aggregate_expiry_expected_keys
        && $worker_aggregate_expiry_item_tombstone['deleted_at'] === $worker_aggregate_expiry_delete_after
        && $worker_aggregate_expiry_item_tombstone['bytes'] === $worker_aggregate_expiry_source_item['bytes']
        && $worker_aggregate_expiry_item_tombstone['object_key'] === $worker_aggregate_expiry_source_item['object_key']
        && $worker_aggregate_expiry_item_tombstone['object_version'] === $worker_aggregate_expiry_source_item['object_version']
        && $worker_aggregate_expiry_item_tombstone['etag'] === $worker_aggregate_expiry_source_item['etag']
        && $worker_aggregate_expiry_item_tombstone['policy_fingerprint'] === $worker_aggregate_expiry_source_item['policy_fingerprint']
        && $worker_aggregate_expiry_item_tombstone['storage_identity'] === $worker_aggregate_expiry_source_item['storage_identity']
        && $worker_aggregate_expiry_item_tombstone['validation_contract_version'] === $worker_aggregate_expiry_source_item['validation_contract_version']
        && $worker_aggregate_expiry_item_tombstone['validation_until'] === $worker_aggregate_expiry_source_item['validation_until']
        && $worker_aggregate_expiry_item_tombstone['capacity_release_started'] === false
        && $worker_aggregate_expiry_item_tombstone['capacity_released'] === false
        && $worker_aggregate_expiry_intent_keys === $worker_aggregate_expiry_expected_keys
        && $worker_aggregate_expiry_intent_tombstone['deleted_at'] === $worker_aggregate_expiry_delete_after
        && $worker_aggregate_expiry_intent_tombstone['bytes'] === $worker_aggregate_expiry_source_intent['reserved_bytes']
        && $worker_aggregate_expiry_intent_tombstone['object_key'] === $worker_aggregate_expiry_source_intent['object_key']
        && $worker_aggregate_expiry_intent_tombstone['object_version'] === '-'
        && $worker_aggregate_expiry_intent_tombstone['etag'] === '-'
        && $worker_aggregate_expiry_intent_tombstone['policy_fingerprint'] === $worker_aggregate_expiry_source_intent['policy_fingerprint']
        && $worker_aggregate_expiry_intent_tombstone['storage_identity'] === $worker_aggregate_expiry_source_intent['storage_identity']
        && $worker_aggregate_expiry_intent_tombstone['validation_contract_version'] === $worker_aggregate_expiry_source_intent['validation_contract_version']
        && $worker_aggregate_expiry_intent_tombstone['validation_until'] === $worker_aggregate_expiry_source_intent['validation_until']
        && $worker_aggregate_expiry_intent_tombstone['capacity_release_started'] === false
        && $worker_aggregate_expiry_intent_tombstone['capacity_released'] === false
        && $worker_aggregate_expiry_capacity_after === $worker_aggregate_expiry_capacity_before,
    'Worker aggregate expiry at delete_after should tombstone active items and intents exactly without remote callbacks or capacity mutation.'
);
eforms_test_remove_tree( $worker_aggregate_expiry_dir );

$worker_aggregate_expiry_dry_dir = eforms_test_setup_uploads( 'eforms-gc-worker-aggregate-expiry-dry' );
eforms_test_gc_managed_configure( $worker_aggregate_expiry_dry_dir );
$worker_aggregate_expiry_dry_accept = $worker_base + $worker_drain + 1850;
$worker_aggregate_expiry_dry_delete_after = $worker_aggregate_expiry_dry_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$worker_aggregate_expiry_dry = eforms_test_gc_worker_fixture(
    $worker_aggregate_expiry_dry_dir,
    'aggregate-expiry-dry',
    $worker_base + 20,
    $worker_aggregate_expiry_dry_accept,
    $worker_base + 720,
    $worker_aggregate_expiry_dry_delete_after,
    $worker_aggregate_expiry_dry_delete_after - 10,
    array( 'active_item', 'live_intent', 'item' )
);
$worker_aggregate_expiry_dry_manifest_before_raw = file_get_contents( $worker_aggregate_expiry_dry['manifest_path'] );
$worker_aggregate_expiry_dry_manifest_before = json_decode( $worker_aggregate_expiry_dry_manifest_before_raw, true );
$worker_aggregate_expiry_dry_capacity_before = eforms_test_managed_capacity_record( $worker_aggregate_expiry_dry_dir );
$worker_aggregate_expiry_dry_calls = 0;
$worker_aggregate_expiry_dry_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_aggregate_expiry_dry_dir,
    $worker_aggregate_expiry_dry_delete_after,
    20,
    true,
    array(),
    function () use ( &$worker_aggregate_expiry_dry_calls ) {
        $worker_aggregate_expiry_dry_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_aggregate_expiry_dry_manifest_after_raw = file_get_contents( $worker_aggregate_expiry_dry['manifest_path'] );
$worker_aggregate_expiry_dry_item = $worker_aggregate_expiry_dry['uploads'][0];
$worker_aggregate_expiry_dry_intent = $worker_aggregate_expiry_dry['uploads'][1];
eforms_test_assert(
    $worker_aggregate_expiry_dry_result['ok'] === true
        && $worker_aggregate_expiry_dry_result['candidates'] === 1
        && $worker_aggregate_expiry_dry_result['candidate_bytes'] === $worker_aggregate_expiry_dry_item['bytes'] + $worker_aggregate_expiry_dry_intent['bytes']
        && $worker_aggregate_expiry_dry_result['candidate_artifact_bytes'] === $worker_aggregate_expiry_dry_item['bytes'] + $worker_aggregate_expiry_dry_intent['bytes']
        && $worker_aggregate_expiry_dry_result['deleted'] === 0
        && $worker_aggregate_expiry_dry_result['released_bytes'] === 0
        && $worker_aggregate_expiry_dry_calls === 0
        && $worker_aggregate_expiry_dry_manifest_after_raw === $worker_aggregate_expiry_dry_manifest_before_raw
        && json_decode( $worker_aggregate_expiry_dry_manifest_after_raw, true ) === $worker_aggregate_expiry_dry_manifest_before
        && eforms_test_managed_capacity_record( $worker_aggregate_expiry_dry_dir ) === $worker_aggregate_expiry_dry_capacity_before
        && is_dir( $worker_aggregate_expiry_dry['path'] )
        && is_file( $worker_aggregate_expiry_dry['manifest_path'] ),
    'Worker aggregate-expiry dry-run at delete_after should report conversion bytes without mutation, callback, release, or deletion.'
);
eforms_test_remove_tree( $worker_aggregate_expiry_dry_dir );

$worker_aggregate_expiry_late_dir = eforms_test_setup_uploads( 'eforms-gc-worker-aggregate-expiry-late' );
eforms_test_gc_managed_configure( $worker_aggregate_expiry_late_dir );
$worker_aggregate_expiry_late_accept = $worker_base + $worker_drain + 1900;
$worker_aggregate_expiry_late_delete_after = $worker_aggregate_expiry_late_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$worker_aggregate_expiry_late = eforms_test_gc_worker_fixture(
    $worker_aggregate_expiry_late_dir,
    'aggregate-expiry-late',
    $worker_base + 40,
    $worker_aggregate_expiry_late_accept,
    $worker_base + 740,
    $worker_aggregate_expiry_late_delete_after,
    $worker_aggregate_expiry_late_delete_after - 10,
    array( 'active_item', 'live_intent' )
);
$worker_aggregate_expiry_late_manifest_before = json_decode( file_get_contents( $worker_aggregate_expiry_late['manifest_path'] ), true );
$worker_aggregate_expiry_late_capacity_before = eforms_test_managed_capacity_record( $worker_aggregate_expiry_late_dir );
$worker_aggregate_expiry_late_item = $worker_aggregate_expiry_late['uploads'][0];
$worker_aggregate_expiry_late_intent = $worker_aggregate_expiry_late['uploads'][1];
$worker_aggregate_expiry_late_source_item = $worker_aggregate_expiry_late_manifest_before['items'][ $worker_aggregate_expiry_late_item['upload_id'] ];
$worker_aggregate_expiry_late_source_intent = $worker_aggregate_expiry_late_manifest_before['intents'][ $worker_aggregate_expiry_late_intent['upload_id'] ];
$worker_aggregate_expiry_late_now = max(
    $worker_aggregate_expiry_late_source_item['validation_until'],
    $worker_aggregate_expiry_late_source_intent['validation_until'],
    $worker_aggregate_expiry_late_delete_after + $worker_drain
) + 1;
$worker_aggregate_expiry_late_expected_authorities = array(
    $worker_aggregate_expiry_late_item['upload_id'] => array(
        'upload_id' => $worker_aggregate_expiry_late_item['upload_id'],
        'storage_identity' => $worker_aggregate_expiry_late_source_item['storage_identity'],
        'expected_composition_fingerprint' => $worker_aggregate_expiry_late_source_item['storage_identity'],
        'validation_contract_version' => $worker_aggregate_expiry_late_source_item['validation_contract_version'],
        'object_key' => $worker_aggregate_expiry_late_source_item['object_key'],
        'object_version' => $worker_aggregate_expiry_late_source_item['object_version'],
        'etag' => $worker_aggregate_expiry_late_source_item['etag'],
        'bytes' => $worker_aggregate_expiry_late_source_item['bytes'],
        'policy_fingerprint' => $worker_aggregate_expiry_late_source_item['policy_fingerprint'],
    ),
    $worker_aggregate_expiry_late_intent['upload_id'] => array(
        'upload_id' => $worker_aggregate_expiry_late_intent['upload_id'],
        'storage_identity' => $worker_aggregate_expiry_late_source_intent['storage_identity'],
        'expected_composition_fingerprint' => $worker_aggregate_expiry_late_source_intent['storage_identity'],
        'validation_contract_version' => $worker_aggregate_expiry_late_source_intent['validation_contract_version'],
        'object_key' => $worker_aggregate_expiry_late_source_intent['object_key'],
        'object_version' => '-',
        'etag' => '-',
        'bytes' => $worker_aggregate_expiry_late_source_intent['reserved_bytes'],
        'policy_fingerprint' => $worker_aggregate_expiry_late_source_intent['policy_fingerprint'],
    ),
);
ksort( $worker_aggregate_expiry_late_expected_authorities, SORT_STRING );
$worker_aggregate_expiry_late_authorities = array();
$worker_aggregate_expiry_late_lock_checks = array();
$worker_aggregate_expiry_late_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_aggregate_expiry_late_dir,
    $worker_aggregate_expiry_late_now,
    20,
    false,
    array(),
    function ( $authority ) use ( &$worker_aggregate_expiry_late_authorities, &$worker_aggregate_expiry_late_lock_checks, $worker_aggregate_expiry_late_dir, $worker_aggregate_expiry_late ) {
        if ( is_array( $authority ) && isset( $authority['upload_id'] ) ) {
            $worker_aggregate_expiry_late_authorities[ $authority['upload_id'] ] = $authority;
        }
        $capacity_lock = ManagedCapacityStore::acquire_lock( $worker_aggregate_expiry_late_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_LOCK_FILENAME, true, true );
        $aggregate_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $worker_aggregate_expiry_late['path'] );
        $aggregate_lock = fopen( $aggregate_lock_path, 'r+b' );
        $aggregate_free = is_resource( $aggregate_lock ) && flock( $aggregate_lock, LOCK_EX | LOCK_NB );
        $worker_aggregate_expiry_late_lock_checks[] = array(
            'capacity' => is_resource( $capacity_lock ),
            'aggregate' => $aggregate_free,
        );
        if ( $aggregate_free ) {
            flock( $aggregate_lock, LOCK_UN );
        }
        if ( is_resource( $aggregate_lock ) ) {
            fclose( $aggregate_lock );
        }
        if ( is_resource( $capacity_lock ) ) {
            flock( $capacity_lock, LOCK_UN );
            fclose( $capacity_lock );
        }
        return array( 'ok' => true, 'absent' => true );
    }
);
ksort( $worker_aggregate_expiry_late_authorities, SORT_STRING );
$worker_aggregate_expiry_late_manifest_after = json_decode( file_get_contents( $worker_aggregate_expiry_late['manifest_path'] ), true );
$worker_aggregate_expiry_late_capacity_after = eforms_test_managed_capacity_record( $worker_aggregate_expiry_late_dir );
$worker_aggregate_expiry_late_item_after = $worker_aggregate_expiry_late_manifest_after['tombstones'][ $worker_aggregate_expiry_late_item['upload_id'] ];
$worker_aggregate_expiry_late_intent_after = $worker_aggregate_expiry_late_manifest_after['tombstones'][ $worker_aggregate_expiry_late_intent['upload_id'] ];
eforms_test_assert(
    $worker_aggregate_expiry_late_result['ok'] === true
        && $worker_aggregate_expiry_late_result['candidates'] === 1
        && $worker_aggregate_expiry_late_result['candidate_bytes'] === $worker_aggregate_expiry_late_item['bytes'] + $worker_aggregate_expiry_late_intent['bytes']
        && $worker_aggregate_expiry_late_result['candidate_artifact_bytes'] === $worker_aggregate_expiry_late_item['bytes'] + $worker_aggregate_expiry_late_intent['bytes']
        && $worker_aggregate_expiry_late_result['deleted'] === 0
        && $worker_aggregate_expiry_late_result['released_bytes'] === $worker_aggregate_expiry_late_item['bytes'] + $worker_aggregate_expiry_late_intent['bytes']
        && $worker_aggregate_expiry_late_authorities === $worker_aggregate_expiry_late_expected_authorities
        && $worker_aggregate_expiry_late_lock_checks === array(
            array( 'capacity' => true, 'aggregate' => true ),
            array( 'capacity' => true, 'aggregate' => true ),
        )
        && $worker_aggregate_expiry_late_manifest_after['intents'] === array()
        && $worker_aggregate_expiry_late_manifest_after['items'] === array()
        && $worker_aggregate_expiry_late_item_after['deleted_at'] === $worker_aggregate_expiry_late_delete_after
        && $worker_aggregate_expiry_late_intent_after['deleted_at'] === $worker_aggregate_expiry_late_delete_after
        && $worker_aggregate_expiry_late_item_after['deleted_at'] !== $worker_aggregate_expiry_late_now
        && $worker_aggregate_expiry_late_intent_after['deleted_at'] !== $worker_aggregate_expiry_late_now
        && $worker_aggregate_expiry_late_item_after['capacity_release_started'] === true
        && $worker_aggregate_expiry_late_item_after['capacity_released'] === true
        && $worker_aggregate_expiry_late_intent_after['capacity_release_started'] === true
        && $worker_aggregate_expiry_late_intent_after['capacity_released'] === true
        && $worker_aggregate_expiry_late_capacity_after['total_bytes'] === $worker_aggregate_expiry_late_capacity_before['total_bytes'] - $worker_aggregate_expiry_late_item['bytes'] - $worker_aggregate_expiry_late_intent['bytes']
        && is_dir( $worker_aggregate_expiry_late['path'] )
        && is_file( $worker_aggregate_expiry_late['manifest_path'] ),
    'First far-late candidate aggregate GC should pin logical tombstones to delete_after, release both charges once, and retain the aggregate.'
);
$worker_aggregate_expiry_late_manifest_after_raw = file_get_contents( $worker_aggregate_expiry_late['manifest_path'] );
$worker_aggregate_expiry_late_retry_calls = 0;
$worker_aggregate_expiry_late_retry = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_aggregate_expiry_late_dir,
    $worker_aggregate_expiry_late_now,
    20,
    false,
    array(),
    function () use ( &$worker_aggregate_expiry_late_retry_calls ) {
        $worker_aggregate_expiry_late_retry_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_aggregate_expiry_late_retry_capacity = eforms_test_managed_capacity_record( $worker_aggregate_expiry_late_dir );
eforms_test_assert(
    $worker_aggregate_expiry_late_retry['ok'] === true
        && $worker_aggregate_expiry_late_retry['candidates'] === 1
        && $worker_aggregate_expiry_late_retry['candidate_bytes'] === 0
        && $worker_aggregate_expiry_late_retry['candidate_artifact_bytes'] === 0
        && $worker_aggregate_expiry_late_retry['deleted'] === 1
        && $worker_aggregate_expiry_late_retry['deleted_bytes'] === 0
        && $worker_aggregate_expiry_late_retry['deleted_artifact_bytes'] === 0
        && $worker_aggregate_expiry_late_retry['released_bytes'] === 0
        && $worker_aggregate_expiry_late_retry_calls === 0
        && ! is_dir( $worker_aggregate_expiry_late['path'] )
        && ! is_file( $worker_aggregate_expiry_late['manifest_path'] )
        && ! is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $worker_aggregate_expiry_late['path'] ) )
        && $worker_aggregate_expiry_late_retry_capacity['total_bytes'] === $worker_aggregate_expiry_late_capacity_after['total_bytes'],
    'Second far-late candidate aggregate GC should write the receipt, delete the ready aggregate without callbacks, and preserve settled capacity bytes.'
);
$worker_aggregate_expiry_late_third_calls = 0;
$worker_aggregate_expiry_late_third = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_aggregate_expiry_late_dir,
    $worker_aggregate_expiry_late_now,
    20,
    false,
    array(),
    function () use ( &$worker_aggregate_expiry_late_third_calls ) {
        $worker_aggregate_expiry_late_third_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_aggregate_expiry_late_third['ok'] === true
        && $worker_aggregate_expiry_late_third['candidates'] === 0
        && $worker_aggregate_expiry_late_third['candidate_bytes'] === 0
        && $worker_aggregate_expiry_late_third['candidate_artifact_bytes'] === 0
        && $worker_aggregate_expiry_late_third['deleted'] === 0
        && $worker_aggregate_expiry_late_third['released_bytes'] === 0
        && $worker_aggregate_expiry_late_third_calls === 0,
    'Third far-late candidate aggregate GC retry after deletion should be empty and idempotent.'
);
eforms_test_remove_tree( $worker_aggregate_expiry_late_dir );

$worker_finalizing_expiry_dir = eforms_test_setup_uploads( 'eforms-gc-worker-finalizing-expiry' );
eforms_test_gc_managed_configure( $worker_finalizing_expiry_dir );
$worker_finalizing_expiry_accept = $worker_base + $worker_drain + 1910;
$worker_finalizing_expiry_delete_after = $worker_finalizing_expiry_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$worker_finalizing_expiry = eforms_test_gc_worker_fixture(
    $worker_finalizing_expiry_dir,
    'finalizing-expiry',
    $worker_base + 50,
    $worker_finalizing_expiry_accept,
    $worker_base + 750,
    $worker_finalizing_expiry_delete_after,
    $worker_finalizing_expiry_delete_after - 10,
    array( 'active_item' )
);
$worker_finalizing_expiry_manifest_before = json_decode( file_get_contents( $worker_finalizing_expiry['manifest_path'] ), true );
$worker_finalizing_expiry_claim = array(
    'claimed_at' => $worker_base + 500,
    'submission_id' => 'submission-' . substr( hash( 'sha256', 'worker-finalizing-expiry' ), 0, 16 ),
);
$worker_finalizing_expiry_manifest_before['state'] = 'finalizing';
$worker_finalizing_expiry_manifest_before['claim'] = $worker_finalizing_expiry_claim;
file_put_contents( $worker_finalizing_expiry['manifest_path'], json_encode( $worker_finalizing_expiry_manifest_before, JSON_UNESCAPED_SLASHES ) );
$worker_finalizing_expiry_manifest_before = json_decode( file_get_contents( $worker_finalizing_expiry['manifest_path'] ), true );
$worker_finalizing_expiry_capacity_before = eforms_test_managed_capacity_record( $worker_finalizing_expiry_dir );
$worker_finalizing_expiry_item = $worker_finalizing_expiry['uploads'][0];
$worker_finalizing_expiry_source_item = $worker_finalizing_expiry_manifest_before['items'][ $worker_finalizing_expiry_item['upload_id'] ];
$worker_finalizing_expiry_calls = 0;
$worker_finalizing_expiry_equal = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_finalizing_expiry_dir,
    $worker_finalizing_expiry_delete_after,
    20,
    false,
    array(),
    function () use ( &$worker_finalizing_expiry_calls ) {
        $worker_finalizing_expiry_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_finalizing_expiry_manifest_equal = json_decode( file_get_contents( $worker_finalizing_expiry['manifest_path'] ), true );
$worker_finalizing_expiry_capacity_equal = eforms_test_managed_capacity_record( $worker_finalizing_expiry_dir );
$worker_finalizing_expiry_tombstone_equal = $worker_finalizing_expiry_manifest_equal['tombstones'][ $worker_finalizing_expiry_item['upload_id'] ];
eforms_test_assert(
    $worker_finalizing_expiry_equal['ok'] === true
        && $worker_finalizing_expiry_equal['candidates'] === 1
        && $worker_finalizing_expiry_equal['candidate_bytes'] === $worker_finalizing_expiry_item['bytes']
        && $worker_finalizing_expiry_equal['candidate_artifact_bytes'] === $worker_finalizing_expiry_item['bytes']
        && $worker_finalizing_expiry_equal['released_bytes'] === 0
        && $worker_finalizing_expiry_equal['deleted'] === 0
        && $worker_finalizing_expiry_calls === 0
        && $worker_finalizing_expiry_manifest_equal['state'] === 'finalizing'
        && $worker_finalizing_expiry_manifest_equal['claim'] === $worker_finalizing_expiry_claim
        && ! isset( $worker_finalizing_expiry_manifest_equal['finalized_at'], $worker_finalizing_expiry_manifest_equal['email_attempted_at'] )
        && $worker_finalizing_expiry_manifest_equal['items'] === array()
        && $worker_finalizing_expiry_manifest_equal['artifact_bytes'] === $worker_finalizing_expiry_manifest_before['artifact_bytes'] - $worker_finalizing_expiry_item['bytes']
        && $worker_finalizing_expiry_tombstone_equal['deleted_at'] === $worker_finalizing_expiry_delete_after
        && $worker_finalizing_expiry_tombstone_equal['bytes'] === $worker_finalizing_expiry_source_item['bytes']
        && $worker_finalizing_expiry_tombstone_equal['object_key'] === $worker_finalizing_expiry_source_item['object_key']
        && $worker_finalizing_expiry_tombstone_equal['object_version'] === $worker_finalizing_expiry_source_item['object_version']
        && $worker_finalizing_expiry_tombstone_equal['etag'] === $worker_finalizing_expiry_source_item['etag']
        && $worker_finalizing_expiry_tombstone_equal['policy_fingerprint'] === $worker_finalizing_expiry_source_item['policy_fingerprint']
        && $worker_finalizing_expiry_tombstone_equal['storage_identity'] === $worker_finalizing_expiry_source_item['storage_identity']
        && $worker_finalizing_expiry_tombstone_equal['validation_contract_version'] === $worker_finalizing_expiry_source_item['validation_contract_version']
        && $worker_finalizing_expiry_tombstone_equal['validation_until'] === $worker_finalizing_expiry_source_item['validation_until']
        && $worker_finalizing_expiry_tombstone_equal['capacity_release_started'] === false
        && $worker_finalizing_expiry_tombstone_equal['capacity_released'] === false
        && $worker_finalizing_expiry_capacity_equal === $worker_finalizing_expiry_capacity_before
        && is_dir( $worker_finalizing_expiry['path'] )
        && is_file( $worker_finalizing_expiry['manifest_path'] ),
    'Worker finalizing aggregate expiry at delete_after should tombstone the active item while preserving finalizing claim state.'
);
$worker_finalizing_expiry_safe_after = max(
    $worker_finalizing_expiry_tombstone_equal['validation_until'] + $worker_validation_drain,
    $worker_finalizing_expiry_tombstone_equal['deleted_at'] + $worker_drain
);
$worker_finalizing_expiry_authorities = array();
$worker_finalizing_expiry_lock_checks = array();
$worker_finalizing_expiry_release = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_finalizing_expiry_dir,
    $worker_finalizing_expiry_safe_after + 1,
    20,
    false,
    array(),
    function ( $authority ) use ( &$worker_finalizing_expiry_authorities, &$worker_finalizing_expiry_lock_checks, $worker_finalizing_expiry_dir, $worker_finalizing_expiry ) {
        $worker_finalizing_expiry_authorities[] = $authority;
        $capacity_lock = ManagedCapacityStore::acquire_lock( $worker_finalizing_expiry_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_LOCK_FILENAME, true, true );
        $aggregate_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $worker_finalizing_expiry['path'] );
        $aggregate_lock = fopen( $aggregate_lock_path, 'r+b' );
        $aggregate_free = is_resource( $aggregate_lock ) && flock( $aggregate_lock, LOCK_EX | LOCK_NB );
        $worker_finalizing_expiry_lock_checks[] = array(
            'capacity' => is_resource( $capacity_lock ),
            'aggregate' => $aggregate_free,
        );
        if ( $aggregate_free ) {
            flock( $aggregate_lock, LOCK_UN );
        }
        if ( is_resource( $aggregate_lock ) ) {
            fclose( $aggregate_lock );
        }
        if ( is_resource( $capacity_lock ) ) {
            flock( $capacity_lock, LOCK_UN );
            fclose( $capacity_lock );
        }
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_finalizing_expiry_manifest_release = json_decode( file_get_contents( $worker_finalizing_expiry['manifest_path'] ), true );
$worker_finalizing_expiry_capacity_release = eforms_test_managed_capacity_record( $worker_finalizing_expiry_dir );
$worker_finalizing_expiry_tombstone_release = $worker_finalizing_expiry_manifest_release['tombstones'][ $worker_finalizing_expiry_item['upload_id'] ];
$worker_finalizing_expiry_expected_authority = array(
    'upload_id' => $worker_finalizing_expiry_item['upload_id'],
    'storage_identity' => $worker_finalizing_expiry_source_item['storage_identity'],
    'expected_composition_fingerprint' => $worker_finalizing_expiry_source_item['storage_identity'],
    'validation_contract_version' => $worker_finalizing_expiry_source_item['validation_contract_version'],
    'object_key' => $worker_finalizing_expiry_source_item['object_key'],
    'object_version' => $worker_finalizing_expiry_source_item['object_version'],
    'etag' => $worker_finalizing_expiry_source_item['etag'],
    'bytes' => $worker_finalizing_expiry_source_item['bytes'],
    'policy_fingerprint' => $worker_finalizing_expiry_source_item['policy_fingerprint'],
);
eforms_test_assert(
    $worker_finalizing_expiry_release['ok'] === true
        && $worker_finalizing_expiry_release['candidates'] === 1
        && $worker_finalizing_expiry_release['released_bytes'] === $worker_finalizing_expiry_item['bytes']
        && $worker_finalizing_expiry_authorities === array( $worker_finalizing_expiry_expected_authority )
        && $worker_finalizing_expiry_lock_checks === array( array( 'capacity' => true, 'aggregate' => true ) )
        && $worker_finalizing_expiry_manifest_release['state'] === 'finalizing'
        && $worker_finalizing_expiry_manifest_release['claim'] === $worker_finalizing_expiry_claim
        && $worker_finalizing_expiry_tombstone_release['capacity_release_started'] === true
        && $worker_finalizing_expiry_tombstone_release['capacity_released'] === true
        && $worker_finalizing_expiry_capacity_release['total_bytes'] === $worker_finalizing_expiry_capacity_before['total_bytes'] - $worker_finalizing_expiry_item['bytes']
        && is_dir( $worker_finalizing_expiry['path'] )
        && is_file( $worker_finalizing_expiry['manifest_path'] ),
    'Worker finalizing aggregate after strict safe_after should perform one exact remote cleanup and retain the aggregate.'
);
$worker_finalizing_expiry_delete_calls = 0;
$worker_finalizing_expiry_delete = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_finalizing_expiry_dir,
    $worker_finalizing_expiry_safe_after + 1,
    20,
    false,
    array(),
    function () use ( &$worker_finalizing_expiry_delete_calls ) {
        $worker_finalizing_expiry_delete_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_finalizing_expiry_capacity_delete = eforms_test_managed_capacity_record( $worker_finalizing_expiry_dir );
eforms_test_assert(
    $worker_finalizing_expiry_delete['ok'] === true
        && $worker_finalizing_expiry_delete['candidates'] === 1
        && $worker_finalizing_expiry_delete['deleted'] === 1
        && $worker_finalizing_expiry_delete['released_bytes'] === 0
        && $worker_finalizing_expiry_delete_calls === 0
        && ! is_dir( $worker_finalizing_expiry['path'] )
        && ! is_file( $worker_finalizing_expiry['manifest_path'] )
        && ! is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $worker_finalizing_expiry['path'] ) )
        && $worker_finalizing_expiry_capacity_delete['total_bytes'] === $worker_finalizing_expiry_capacity_release['total_bytes'],
    'Worker finalizing ready aggregate should delete on the next pass without callbacks or double release.'
);
$worker_finalizing_expiry_empty_calls = 0;
$worker_finalizing_expiry_empty = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_finalizing_expiry_dir,
    $worker_finalizing_expiry_safe_after + 1,
    20,
    false,
    array(),
    function () use ( &$worker_finalizing_expiry_empty_calls ) {
        $worker_finalizing_expiry_empty_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_finalizing_expiry_empty['ok'] === true
        && $worker_finalizing_expiry_empty['candidates'] === 0
        && $worker_finalizing_expiry_empty['deleted'] === 0
        && $worker_finalizing_expiry_empty['released_bytes'] === 0
        && $worker_finalizing_expiry_empty_calls === 0,
    'Worker finalizing aggregate GC after deletion should be empty and idempotent.'
);
eforms_test_remove_tree( $worker_finalizing_expiry_dir );

$worker_receipt_predelete_dir = eforms_test_setup_uploads( 'eforms-gc-worker-receipt-predelete' );
eforms_test_gc_managed_configure( $worker_receipt_predelete_dir );
$worker_receipt_predelete_accept = $worker_base + $worker_drain + 1920;
$worker_receipt_predelete_delete_after = $worker_receipt_predelete_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$worker_receipt_predelete_now = max( $worker_base + 760, $worker_receipt_predelete_delete_after + $worker_drain ) + 1;
$worker_receipt_predelete = eforms_test_gc_worker_ready_fixture(
    $worker_receipt_predelete_dir,
    'receipt-predelete',
    $worker_base + 60,
    $worker_receipt_predelete_accept,
    $worker_base + 760,
    $worker_receipt_predelete_delete_after,
    $worker_receipt_predelete_now
);
$worker_receipt_predelete_capacity_path = $worker_receipt_predelete_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
$worker_receipt_predelete_release = eforms_test_gc_worker_release_once(
    $worker_receipt_predelete['ready_capacity'],
    $worker_receipt_predelete['ready_manifest'],
    $worker_receipt_predelete_now + 1
);
eforms_test_assert(
    ! empty( $worker_receipt_predelete_release['ok'] )
        && ! empty( $worker_receipt_predelete_release['changed'] )
        && $worker_receipt_predelete_release['released_bytes'] === 0
        && ManagedCapacityStore::write( $worker_receipt_predelete_capacity_path, $worker_receipt_predelete_release['record'] )
        && is_dir( $worker_receipt_predelete['path'] )
        && is_file( $worker_receipt_predelete['manifest_path'] ),
    'Worker pre-delete crash fixture should persist the zero-byte aggregate release while retaining the aggregate.'
);
$worker_receipt_predelete_capacity_with_receipt = eforms_test_managed_capacity_record( $worker_receipt_predelete_dir );
$worker_receipt_predelete_calls = 0;
$worker_receipt_predelete_retry = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_receipt_predelete_dir,
    $worker_receipt_predelete_now + 2,
    20,
    false,
    array(),
    function () use ( &$worker_receipt_predelete_calls ) {
        $worker_receipt_predelete_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_receipt_predelete_capacity_after = eforms_test_managed_capacity_record( $worker_receipt_predelete_dir );
eforms_test_assert(
    $worker_receipt_predelete_retry['ok'] === true
        && $worker_receipt_predelete_retry['candidates'] === 1
        && $worker_receipt_predelete_retry['deleted'] === 1
        && $worker_receipt_predelete_retry['released_bytes'] === 0
        && $worker_receipt_predelete_calls === 0
        && ! is_dir( $worker_receipt_predelete['path'] )
        && ! is_file( $worker_receipt_predelete['manifest_path'] )
        && ! is_file( UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $worker_receipt_predelete['path'] ) )
        && $worker_receipt_predelete_capacity_after['total_bytes'] === $worker_receipt_predelete_capacity_with_receipt['total_bytes']
        && empty( $worker_receipt_predelete_capacity_after['releases'] ),
    'Worker GC retry after a pre-delete crash should delete the aggregate without callbacks or double-debiting capacity.'
);
$worker_receipt_predelete_next = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_receipt_predelete_dir,
    $worker_receipt_predelete_now + 3,
    20,
    false,
    array(),
    function () {
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_receipt_predelete_next['ok'] === true
        && $worker_receipt_predelete_next['candidates'] === 0
        && $worker_receipt_predelete_next['deleted'] === 0
        && $worker_receipt_predelete_next['released_bytes'] === 0,
    'Worker GC after pre-delete crash recovery should be empty and idempotent.'
);
eforms_test_remove_tree( $worker_receipt_predelete_dir );

$worker_receipt_postdelete_dir = eforms_test_setup_uploads( 'eforms-gc-worker-receipt-postdelete' );
eforms_test_gc_managed_configure( $worker_receipt_postdelete_dir );
$worker_receipt_postdelete_accept = $worker_base + $worker_drain + 1960;
$worker_receipt_postdelete_delete_after = $worker_receipt_postdelete_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$worker_receipt_postdelete_now = max( $worker_base + 780, $worker_receipt_postdelete_delete_after + $worker_drain ) + 1;
$worker_receipt_postdelete = eforms_test_gc_worker_ready_fixture(
    $worker_receipt_postdelete_dir,
    'receipt-postdelete',
    $worker_base + 80,
    $worker_receipt_postdelete_accept,
    $worker_base + 780,
    $worker_receipt_postdelete_delete_after,
    $worker_receipt_postdelete_now
);
$worker_receipt_postdelete_capacity_path = $worker_receipt_postdelete_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
$worker_receipt_postdelete_release = eforms_test_gc_worker_release_once(
    $worker_receipt_postdelete['ready_capacity'],
    $worker_receipt_postdelete['ready_manifest'],
    $worker_receipt_postdelete_now + 1
);
eforms_test_assert(
    ! empty( $worker_receipt_postdelete_release['ok'] )
        && ! empty( $worker_receipt_postdelete_release['changed'] )
        && $worker_receipt_postdelete_release['released_bytes'] === 0
        && ManagedCapacityStore::write( $worker_receipt_postdelete_capacity_path, $worker_receipt_postdelete_release['record'] ),
    'Worker post-delete crash fixture should persist the zero-byte aggregate release receipt.'
);
$worker_receipt_postdelete_capacity_with_receipt = eforms_test_managed_capacity_record( $worker_receipt_postdelete_dir );
eforms_test_remove_tree( $worker_receipt_postdelete['path'] );
$worker_receipt_postdelete_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $worker_receipt_postdelete['path'] );
if ( is_file( $worker_receipt_postdelete_lock_path ) && ! is_link( $worker_receipt_postdelete_lock_path ) ) {
    unlink( $worker_receipt_postdelete_lock_path );
}
@rmdir( dirname( $worker_receipt_postdelete['path'] ) );
$worker_receipt_postdelete_calls = 0;
$worker_receipt_postdelete_reconcile = UploadBatchStore::reconcile_capacity(
    $worker_receipt_postdelete_dir,
    $worker_receipt_postdelete_now + 2,
    $worker_receipt_postdelete_now + 2,
    function () use ( &$worker_receipt_postdelete_calls ) {
        $worker_receipt_postdelete_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_receipt_postdelete_capacity_after = eforms_test_managed_capacity_record( $worker_receipt_postdelete_dir );
eforms_test_assert(
    ! empty( $worker_receipt_postdelete_reconcile['ok'] )
        && $worker_receipt_postdelete_calls === 0
        && $worker_receipt_postdelete_capacity_after['total_bytes'] === $worker_receipt_postdelete_capacity_with_receipt['total_bytes']
        && ! isset( $worker_receipt_postdelete_capacity_after['releases'][ $worker_receipt_postdelete['batch_id'] ] ),
    'Capacity reconcile after post-delete crash should remove the stale candidate aggregate receipt without callbacks or double release.'
);
$worker_receipt_postdelete_repeat_calls = 0;
$worker_receipt_postdelete_repeat = UploadBatchStore::reconcile_capacity(
    $worker_receipt_postdelete_dir,
    $worker_receipt_postdelete_now + 3,
    $worker_receipt_postdelete_now + 3,
    function () use ( &$worker_receipt_postdelete_repeat_calls ) {
        $worker_receipt_postdelete_repeat_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_receipt_postdelete_capacity_repeat = eforms_test_managed_capacity_record( $worker_receipt_postdelete_dir );
eforms_test_assert(
    ! empty( $worker_receipt_postdelete_repeat['ok'] )
        && $worker_receipt_postdelete_repeat_calls === 0
        && $worker_receipt_postdelete_capacity_repeat['total_bytes'] === $worker_receipt_postdelete_capacity_after['total_bytes']
        && ! isset( $worker_receipt_postdelete_capacity_repeat['releases'][ $worker_receipt_postdelete['batch_id'] ] ),
    'Repeating capacity reconcile after stale candidate receipt removal should be idempotent.'
);
eforms_test_remove_tree( $worker_receipt_postdelete_dir );

$worker_conversion_dry_dir = eforms_test_setup_uploads( 'eforms-gc-worker-conversion-dry' );
eforms_test_gc_managed_configure( $worker_conversion_dry_dir );
$worker_conversion_dry_accept = $worker_base + $worker_drain + 2000;
$worker_conversion_dry = eforms_test_gc_worker_fixture(
    $worker_conversion_dry_dir,
    'conversion-dry',
    $worker_base,
    $worker_conversion_dry_accept,
    $worker_base + 700,
    $worker_conversion_dry_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 100,
    array( 'live_intent' )
);
$worker_conversion_dry_manifest_before = file_get_contents( $worker_conversion_dry['manifest_path'] );
$worker_conversion_dry_capacity_before = eforms_test_managed_capacity_record( $worker_conversion_dry_dir );
$worker_conversion_dry_calls = 0;
$worker_conversion_dry_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_conversion_dry_dir,
    $worker_conversion_dry_accept,
    20,
    true,
    array(),
    function () use ( &$worker_conversion_dry_calls ) {
        $worker_conversion_dry_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_conversion_dry_result['ok'] === true
        && $worker_conversion_dry_result['candidates'] === 1
        && $worker_conversion_dry_result['candidate_bytes'] === $worker_conversion_dry['bytes']
        && $worker_conversion_dry_result['released_bytes'] === 0
        && $worker_conversion_dry_calls === 0
        && file_get_contents( $worker_conversion_dry['manifest_path'] ) === $worker_conversion_dry_manifest_before
        && eforms_test_managed_capacity_record( $worker_conversion_dry_dir ) === $worker_conversion_dry_capacity_before,
    'Worker expired-intent conversion dry-run should report the candidate without mutation or callback.'
);
eforms_test_remove_tree( $worker_conversion_dry_dir );

$worker_conversion_before_dir = eforms_test_setup_uploads( 'eforms-gc-worker-conversion-before' );
eforms_test_gc_managed_configure( $worker_conversion_before_dir );
$worker_conversion_before_accept = $worker_base + $worker_drain + 2200;
$worker_conversion_before = eforms_test_gc_worker_fixture(
    $worker_conversion_before_dir,
    'conversion-before',
    $worker_base + 100,
    $worker_conversion_before_accept,
    $worker_base + 900,
    $worker_conversion_before_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 200,
    array( 'live_intent' )
);
$worker_conversion_before_manifest = json_decode( file_get_contents( $worker_conversion_before['manifest_path'] ), true );
$worker_conversion_before_capacity = eforms_test_managed_capacity_record( $worker_conversion_before_dir );
$worker_conversion_before_calls = 0;
$worker_conversion_before_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_conversion_before_dir,
    $worker_conversion_before_accept - 1,
    20,
    false,
    array(),
    function () use ( &$worker_conversion_before_calls ) {
        $worker_conversion_before_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_conversion_before_result['ok'] === true
        && $worker_conversion_before_result['candidates'] === 0
        && $worker_conversion_before_calls === 0
        && json_decode( file_get_contents( $worker_conversion_before['manifest_path'] ), true ) === $worker_conversion_before_manifest
        && eforms_test_managed_capacity_record( $worker_conversion_before_dir ) === $worker_conversion_before_capacity,
    'Worker GC before accept_until should retain a still-live intent without callback or mutation.'
);
eforms_test_remove_tree( $worker_conversion_before_dir );

$worker_conversion_finalizing_dir = eforms_test_setup_uploads( 'eforms-gc-worker-conversion-finalizing' );
eforms_test_gc_managed_configure( $worker_conversion_finalizing_dir );
$worker_conversion_finalizing_accept = $worker_base + $worker_drain + 2300;
$worker_conversion_finalizing = eforms_test_gc_worker_fixture(
    $worker_conversion_finalizing_dir,
    'conversion-finalizing',
    $worker_base + 150,
    $worker_conversion_finalizing_accept,
    $worker_base + 950,
    $worker_conversion_finalizing_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 250,
    array()
);
$worker_conversion_finalizing_manifest = json_decode( file_get_contents( $worker_conversion_finalizing['manifest_path'] ), true );
$worker_conversion_finalizing_manifest['state'] = 'finalizing';
$worker_conversion_finalizing_manifest['claim'] = array(
    'claimed_at' => $worker_base + 400,
    'submission_id' => 'submission-' . substr( hash( 'sha256', 'worker-conversion-finalizing' ), 0, 16 ),
);
file_put_contents( $worker_conversion_finalizing['manifest_path'], json_encode( $worker_conversion_finalizing_manifest, JSON_UNESCAPED_SLASHES ) );
$worker_conversion_finalizing_manifest_before = json_decode( file_get_contents( $worker_conversion_finalizing['manifest_path'] ), true );
$worker_conversion_finalizing_capacity_before = eforms_test_managed_capacity_record( $worker_conversion_finalizing_dir );
$worker_conversion_finalizing_calls = 0;
$worker_conversion_finalizing_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_conversion_finalizing_dir,
    $worker_conversion_finalizing_accept + 1,
    20,
    false,
    array(),
    function () use ( &$worker_conversion_finalizing_calls ) {
        $worker_conversion_finalizing_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $worker_conversion_finalizing_result['ok'] === true
        && $worker_conversion_finalizing_result['candidates'] === 0
        && $worker_conversion_finalizing_calls === 0
        && json_decode( file_get_contents( $worker_conversion_finalizing['manifest_path'] ), true ) === $worker_conversion_finalizing_manifest_before
        && eforms_test_managed_capacity_record( $worker_conversion_finalizing_dir ) === $worker_conversion_finalizing_capacity_before,
    'Worker expired-intent conversion should leave valid non-open staged candidates structurally unchanged.'
);
eforms_test_remove_tree( $worker_conversion_finalizing_dir );

$worker_conversion_boundary_dir = eforms_test_setup_uploads( 'eforms-gc-worker-conversion-boundary' );
eforms_test_gc_managed_configure( $worker_conversion_boundary_dir );
$worker_conversion_boundary_accept = $worker_base + $worker_drain + 2400;
$worker_conversion_boundary = eforms_test_gc_worker_fixture(
    $worker_conversion_boundary_dir,
    'conversion-boundary',
    $worker_base + 200,
    $worker_conversion_boundary_accept,
    $worker_base + 1000,
    $worker_conversion_boundary_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 300,
    array( 'item', 'live_intent' )
);
$worker_conversion_boundary_manifest_before = json_decode( file_get_contents( $worker_conversion_boundary['manifest_path'] ), true );
$worker_conversion_boundary_capacity_before = eforms_test_managed_capacity_record( $worker_conversion_boundary_dir );
$worker_conversion_boundary_calls = 0;
$worker_conversion_boundary_persisted_before_callback = false;
$worker_conversion_boundary_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_conversion_boundary_dir,
    $worker_conversion_boundary_accept,
    20,
    false,
    array(),
    function () use ( &$worker_conversion_boundary_calls, &$worker_conversion_boundary_persisted_before_callback, $worker_conversion_boundary ) {
        $worker_conversion_boundary_calls++;
        $manifest = json_decode( file_get_contents( $worker_conversion_boundary['manifest_path'] ), true );
        $converted_id = $worker_conversion_boundary['uploads'][1]['upload_id'];
        $worker_conversion_boundary_persisted_before_callback = isset( $manifest['tombstones'][ $converted_id ] )
            && ! isset( $manifest['intents'][ $converted_id ] );
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_conversion_boundary_manifest = json_decode( file_get_contents( $worker_conversion_boundary['manifest_path'] ), true );
$worker_conversion_boundary_capacity_after = eforms_test_managed_capacity_record( $worker_conversion_boundary_dir );
$worker_conversion_boundary_item = $worker_conversion_boundary['uploads'][0];
$worker_conversion_boundary_intent = $worker_conversion_boundary['uploads'][1];
$worker_conversion_boundary_source_intent = $worker_conversion_boundary_manifest_before['intents'][ $worker_conversion_boundary_intent['upload_id'] ];
$worker_conversion_boundary_tombstone = $worker_conversion_boundary_manifest['tombstones'][ $worker_conversion_boundary_intent['upload_id'] ];
$worker_conversion_boundary_expected_keys = array(
    'bytes', 'capacity_release_started', 'capacity_released', 'deleted_at', 'etag', 'object_key',
    'object_version', 'policy_fingerprint', 'storage_identity', 'validation_contract_version',
    'validation_until',
);
$worker_conversion_boundary_actual_keys = array_keys( $worker_conversion_boundary_tombstone );
sort( $worker_conversion_boundary_actual_keys, SORT_STRING );
sort( $worker_conversion_boundary_expected_keys, SORT_STRING );
eforms_test_assert(
    $worker_conversion_boundary_result['ok'] === true
        && $worker_conversion_boundary_calls === 1
        && $worker_conversion_boundary_persisted_before_callback
        && ! isset( $worker_conversion_boundary_manifest['intents'][ $worker_conversion_boundary_intent['upload_id'] ] )
        && $worker_conversion_boundary_actual_keys === $worker_conversion_boundary_expected_keys
        && $worker_conversion_boundary_tombstone['deleted_at'] === $worker_conversion_boundary_accept
        && $worker_conversion_boundary_tombstone['bytes'] === $worker_conversion_boundary_intent['bytes']
        && $worker_conversion_boundary_tombstone['object_key'] === $worker_conversion_boundary_intent['object_key']
        && $worker_conversion_boundary_tombstone['object_version'] === '-'
        && $worker_conversion_boundary_tombstone['etag'] === '-'
        && $worker_conversion_boundary_tombstone['storage_identity'] === $worker_conversion_boundary['identity']
        && $worker_conversion_boundary_tombstone['validation_contract_version'] === 'validation-v1'
        && $worker_conversion_boundary_tombstone['validation_until'] === $worker_conversion_boundary['validation_until']
        && $worker_conversion_boundary_tombstone['capacity_release_started'] === false
        && $worker_conversion_boundary_tombstone['capacity_released'] === false
        && $worker_conversion_boundary_tombstone['policy_fingerprint'] === $worker_conversion_boundary_source_intent['policy_fingerprint']
        && $worker_conversion_boundary_result['released_bytes'] === $worker_conversion_boundary_item['bytes']
        && $worker_conversion_boundary_capacity_after['total_bytes'] === $worker_conversion_boundary_capacity_before['total_bytes'] - $worker_conversion_boundary_item['bytes'],
    'Worker GC at accept_until should persist exact dash-pair intent tombstones before remote callbacks and release no converted-intent capacity.'
);
$worker_conversion_safe_after = max(
    $worker_conversion_boundary_tombstone['validation_until'] + $worker_validation_drain,
    $worker_conversion_boundary_tombstone['deleted_at'] + $worker_drain
);
$worker_conversion_equal_calls = 0;
$worker_conversion_equal = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_conversion_boundary_dir,
    $worker_conversion_safe_after,
    20,
    false,
    array(),
    function () use ( &$worker_conversion_equal_calls ) {
        $worker_conversion_equal_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_conversion_plus_one_calls = 0;
$worker_conversion_plus_one = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_conversion_boundary_dir,
    $worker_conversion_safe_after + 1,
    20,
    false,
    array(),
    function () use ( &$worker_conversion_plus_one_calls ) {
        $worker_conversion_plus_one_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_conversion_after_release = json_decode( file_get_contents( $worker_conversion_boundary['manifest_path'] ), true );
eforms_test_assert(
    $worker_conversion_equal['ok'] === true
        && $worker_conversion_equal['candidates'] === 0
        && $worker_conversion_equal_calls === 0
        && $worker_conversion_plus_one['released_bytes'] === $worker_conversion_boundary_intent['bytes']
        && $worker_conversion_plus_one_calls === 1
        && ! empty( $worker_conversion_after_release['tombstones'][ $worker_conversion_boundary_intent['upload_id'] ]['capacity_released'] ),
    'Converted candidate tombstones should retain at strict safe_after equality and release through the existing +1 tombstone path.'
);
eforms_test_remove_tree( $worker_conversion_boundary_dir );

$worker_conversion_after_dir = eforms_test_setup_uploads( 'eforms-gc-worker-conversion-after' );
eforms_test_gc_managed_configure( $worker_conversion_after_dir );
$worker_conversion_after_accept = $worker_base + $worker_drain + 2600;
$worker_conversion_after = eforms_test_gc_worker_fixture(
    $worker_conversion_after_dir,
    'conversion-after',
    $worker_base + 300,
    $worker_conversion_after_accept,
    $worker_base + 1100,
    $worker_conversion_after_accept + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
    $worker_base + 400,
    array( 'live_intent' )
);
$worker_conversion_after_capacity_before = eforms_test_managed_capacity_record( $worker_conversion_after_dir );
$worker_conversion_after_calls = 0;
$worker_conversion_after_result = UploadBatchStore::gc_aggregates(
    'staged',
    $worker_conversion_after_dir,
    $worker_conversion_after_accept + 1,
    20,
    false,
    array(),
    function () use ( &$worker_conversion_after_calls ) {
        $worker_conversion_after_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_conversion_after_manifest = json_decode( file_get_contents( $worker_conversion_after['manifest_path'] ), true );
$worker_conversion_after_capacity_after = eforms_test_managed_capacity_record( $worker_conversion_after_dir );
$worker_conversion_after_upload = $worker_conversion_after['uploads'][0];
eforms_test_assert(
    $worker_conversion_after_result['ok'] === true
        && $worker_conversion_after_result['candidates'] === 1
        && $worker_conversion_after_result['released_bytes'] === 0
        && $worker_conversion_after_calls === 0
        && ! isset( $worker_conversion_after_manifest['intents'][ $worker_conversion_after_upload['upload_id'] ] )
        && $worker_conversion_after_manifest['tombstones'][ $worker_conversion_after_upload['upload_id'] ]['deleted_at'] === $worker_conversion_after_accept + 1
        && $worker_conversion_after_manifest['tombstones'][ $worker_conversion_after_upload['upload_id'] ]['capacity_release_started'] === false
        && $worker_conversion_after_manifest['tombstones'][ $worker_conversion_after_upload['upload_id'] ]['capacity_released'] === false
        && $worker_conversion_after_capacity_after === $worker_conversion_after_capacity_before,
    'Worker GC immediately after accept_until should convert the intent with deleted_at pinned to the current GC time.'
);
eforms_test_remove_tree( $worker_conversion_after_dir );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
Logging::reset_for_tests();
eforms_test_remove_tree( $corrupt_capacity_dir );
echo "All staged-upload GC tests passed.\n";
