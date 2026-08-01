<?php
/**
 * Integration tests for manifest-driven managed upload garbage collection.
 *
 * Contract: Managed Aggregate Contract
 * Contract: Runtime Storage GC Contract
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Gc/GcRunner.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';

function eforms_gc_capacity_health( $uploads_dir ) {
    $lease = PrivateDir::acquire_write_lease( $uploads_dir );
    eforms_test_assert( $lease instanceof PrivateDirLease, 'GC capacity health fixtures should acquire the lifecycle lease.' );
    try {
        return UploadBatchStore::capacity_health( $uploads_dir, $lease );
    } finally {
        $lease->release();
    }
}

function eforms_test_gc_managed_secret( $byte ) {
    return rtrim( strtr( base64_encode( str_repeat( $byte, Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
}

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
    $secret = eforms_test_gc_managed_secret( substr( hash( 'sha256', $name, true ), 0, 1 ) );
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

function eforms_test_gc_remote_fixture( $uploads_dir, $name, $created_at, $accept_until, $commit ) {
    $field = array(
        'type' => 'files',
        'upload_mode' => 'staged',
        'accept' => array( 'image' ),
        'max_file_bytes' => 1048576,
        'max_files' => 2,
        'max_total_bytes' => 2097152,
    );
    $binding = array(
        'raw_token' => 'remote-gc-token-' . $name,
        'form_id' => 'virtual-quote',
        'instance_id' => 'remote-gc-' . $name,
        'field_key' => 'project_photos',
        'accept_until' => $accept_until,
    );
    $secret = eforms_test_gc_managed_secret( substr( hash( 'sha256', 'remote-' . $name, true ), 0, 1 ) );
    $created = UploadBatchStore::create_batch(
        $binding,
        $secret,
        $field,
        $uploads_dir,
        $created_at,
        FormProtocol::UPLOAD_TRANSPORT_WORKER,
        str_repeat( 'a', 64 )
    );
    eforms_test_assert( ! empty( $created['ok'] ), 'Remote GC fixture should create its batch.' );
    $batch_id = $created['batch']['batch_id'];
    $upload_id = 'remote_' . substr( hash( 'sha256', $name ), 0, 12 );
    $authorized = UploadBatchStore::authorize_intent(
        $batch_id,
        $secret,
        $upload_id,
        0,
        $name . '.png',
        4096,
        'image/png',
        0,
        $uploads_dir,
        array(
            'now' => $created_at,
            'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
            'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
        )
    );
    eforms_test_assert( ! empty( $authorized['ok'] ), 'Remote GC fixture should reserve its intent.' );
    $intent = $authorized['intent'];
    $version = 'remote-' . substr( hash( 'sha256', $name ), 0, 24 );
    if ( $commit ) {
        $completed = UploadBatchStore::complete_receipt(
            $batch_id,
            $secret,
            $upload_id,
            array(
                'intent_id' => $intent['intent_id'],
                'batch_id' => $batch_id,
                'upload_id' => $upload_id,
                'ordinal' => 0,
                'object_key' => $intent['object_key'],
                'object_version' => $version,
                'etag' => 'etag-' . substr( hash( 'sha256', $name ), 0, 24 ),
                'bytes' => 4096,
                'mime' => 'image/png',
                'width' => 32,
                'height' => 24,
                'policy_fingerprint' => $intent['policy_fingerprint'],
                'expires_at' => $created_at + 60,
            ),
            $uploads_dir,
            $created_at + 1
        );
        eforms_test_assert( ! empty( $completed['ok'] ), 'Remote GC fixture should commit signed artifact facts.' );
    }
    return array(
        'batch_id' => $batch_id,
        'upload_id' => $upload_id,
        'secret' => $secret,
        'intent' => $intent,
        'version' => $commit ? $version : '',
        'path' => $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $batch_id ) . '/' . $batch_id,
        'delete_after' => $created['batch']['delete_after'],
        'bytes' => 4096,
    );
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
$capacity_with_stale['total_bytes'] += 999;
$capacity_with_stale['reservations']['stale_gc_reservation'] = array(
    'batch_id' => $expired_staged['batch_id'],
    'upload_id' => 'interrupted_upload',
    'bytes' => 999,
    'transient_bytes' => 0,
    'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_LOCAL,
    'artifact_store_identity' => UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY,
    'cleanup_started' => false,
    'object_key' => ManagedArtifactKey::create( $expired_staged['batch_id'], 0, eforms_test_digest( 'stale-gc-reservation' ), 'image/png' ),
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
$absent_secret = eforms_test_gc_managed_secret( "\x39" );
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

$limited = GcRunner::run( array( 'dry_run' => true, 'now' => $run_now, 'limit' => 1 ) );
eforms_test_assert( $limited['ok'] === true && $limited['scanned'] === 1 && $limited['reached_limit'] === true, 'Managed aggregate traversal should honor the runner global scan limit.' );

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
    $candidate_submission_id = 'submission-' . substr( hash( 'sha256', $candidate ), 0, 16 );
    $candidate_shard = Helpers::h2( $candidate_submission_id );
    if ( ! isset( $cursor_names[ $candidate_shard ] ) ) {
        $cursor_names[ $candidate_shard ] = array();
    }
    $cursor_names[ $candidate_shard ][] = $candidate;
    if ( count( $cursor_names[ $candidate_shard ] ) === 2 ) {
        $cursor_shard = $candidate_shard;
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

$remote_dir = eforms_test_setup_uploads( 'eforms-gc-remote-objects' );
eforms_test_gc_managed_configure( $remote_dir );
$remote_base = 1800000000;
$remote = eforms_test_gc_remote_fixture( $remote_dir, 'pending-delete', $remote_base, $remote_base + 7200, true );
$remote_removed = UploadBatchStore::delete_item( $remote['batch_id'], $remote['secret'], $remote['upload_id'], $remote_dir, $remote_base + 10 );
eforms_test_assert( ! empty( $remote_removed['ok'] ), 'Remote item removal should durably tombstone before physical deletion.' );
$queued_cancel_id = 'remote_queued_cancel';
$queued_cancel = UploadBatchStore::delete_item( $remote['batch_id'], $remote['secret'], $queued_cancel_id, $remote_dir, $remote_base + 10 );
eforms_test_assert( ! empty( $queued_cancel['ok'] ), 'Removing a queued card before authorization should persist a zero-byte terminal tombstone.' );
$remote_capacity_before = eforms_test_managed_capacity_record( $remote_dir );
$drain_seconds = Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' ) + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' ) + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
$remote_calls = 0;
$early_remote = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_dir,
    $remote_base + 10 + $drain_seconds - 1,
    20,
    false,
    array(),
    function () use ( &$remote_calls ) {
        $remote_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert( $early_remote['ok'] && $remote_calls === 0, 'Remote GC must retain a tombstone until every previously issued upload can no longer start.' );
eforms_test_assert( eforms_test_managed_capacity_record( $remote_dir ) === $remote_capacity_before, 'The remote drain window must retain exact charged bytes.' );

$failed_remote = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_dir,
    $remote_base + 10 + $drain_seconds,
    20,
    false,
    array(),
    function () use ( &$remote_calls ) {
        $remote_calls++;
        return array( 'ok' => false, 'reason' => 'provider_unavailable' );
    }
);
eforms_test_assert( $failed_remote['ok'] && $remote_calls === 0, 'Remote GC must also retain a tombstone at the final inclusive Worker acceptance boundary.' );
eforms_test_assert( eforms_test_managed_capacity_record( $remote_dir ) === $remote_capacity_before, 'The inclusive remote drain boundary must retain exact charged bytes.' );

$locks_were_free = false;
$remote_call_uses_fresh_clock = false;
$remote_retry = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_dir,
    $remote_base + 10 + $drain_seconds + 1,
    20,
    false,
    array(),
    function ( $object_key, $object_version, $artifact_store_identity ) use ( &$locks_were_free, &$remote_call_uses_fresh_clock, $remote_dir, $remote ) {
        $remote_call_uses_fresh_clock = func_num_args() === 3;
        eforms_test_assert( $object_key === $remote['intent']['object_key'] && $object_version === $remote['version'], 'Remote cleanup should address the manifest-owned exact object version.' );
        eforms_test_assert( $artifact_store_identity === str_repeat( 'a', 64 ), 'Remote cleanup must carry the aggregate\'s exact deployment identity.' );
        $capacity_lock = ManagedCapacityStore::acquire_lock( $remote_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_LOCK_FILENAME, true, true );
        $aggregate_lock_path = UploadBatchStore::aggregate_lock_path( UploadBatchStore::STAGED_DIR, $remote['path'] );
        $aggregate_lock = fopen( $aggregate_lock_path, 'r+b' );
        $aggregate_free = is_resource( $aggregate_lock ) && flock( $aggregate_lock, LOCK_EX | LOCK_NB );
        $locks_were_free = is_resource( $capacity_lock ) && $aggregate_free;
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
$remote_capacity_after = eforms_test_managed_capacity_record( $remote_dir );
$remote_manifest_after = json_decode( file_get_contents( $remote['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME ), true );
eforms_test_assert( $locks_were_free, 'Remote deletion must run without the object-budget or aggregate lock held.' );
eforms_test_assert( $remote_call_uses_fresh_clock, 'Remote GC should let the outbound owner mint each operation grant from its current clock.' );
eforms_test_assert( $remote_retry['errors'] === 0 && $remote_retry['released_bytes'] === $remote['bytes'], 'Confirmed remote absence should release exact capacity once.' );
eforms_test_assert( $remote_capacity_after['total_bytes'] === 0 && ! empty( $remote_manifest_after['tombstones'][ $remote['upload_id'] ]['capacity_released'] ), 'Confirmed absence should durably settle both accounting and tombstone phase.' );
eforms_test_assert( ! empty( $remote_manifest_after['tombstones'][ $queued_cancel_id ]['capacity_released'] ), 'A drained zero-byte cancellation should settle without requiring a capacity mutation.' );

$remote_intent = eforms_test_gc_remote_fixture( $remote_dir, 'expired-intent', $remote_base + 100, $remote_base + 7300, false );
$intent_cleanup_now = $remote_intent['intent']['expires_at'] + Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' );
$intent_cleanup = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_dir,
    $intent_cleanup_now,
    20,
    false,
    array(),
    function ( $object_key, $object_version, $artifact_store_identity ) use ( $remote_intent ) {
        eforms_test_assert( $object_key === $remote_intent['intent']['object_key'] && $object_version === '', 'Expired-intent cleanup should authorize its deterministic key without inventing a version.' );
        eforms_test_assert( $artifact_store_identity === str_repeat( 'a', 64 ), 'Expired-intent cleanup should remain bound to its Worker deployment.' );
        return array( 'ok' => true, 'absent' => true );
    }
);
$intent_manifest = json_decode( file_get_contents( $remote_intent['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME ), true );
eforms_test_assert( $intent_cleanup['released_bytes'] === $remote_intent['bytes'] && empty( $intent_manifest['intents'] ), 'Expired remote intents should become terminal tombstones only after authoritative absence.' );
eforms_test_assert( ! empty( $intent_manifest['tombstones'][ $remote_intent['upload_id'] ]['capacity_released'] ), 'Expired-intent absence should be durable and idempotent.' );

$remote_expired = eforms_test_gc_remote_fixture( $remote_dir, 'expired-aggregate', $remote_base + 200, $remote_base + 300, true );
$remote_expired_capacity_path = $remote_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
$remote_expired_capacity = ManagedCapacityStore::read( $remote_expired_capacity_path, UploadBatchStore::CAPACITY_VERSION, $remote_base + 301 );
$reservation_only_bytes = 17;
$reservation_only_id = hash( 'sha256', $remote_expired['batch_id'] . "\0reservation-only" );
$remote_expired_capacity['reservations'][ $reservation_only_id ] = array(
    'batch_id' => $remote_expired['batch_id'],
    'upload_id' => 'reservation-only',
    'bytes' => $reservation_only_bytes,
    'transient_bytes' => 0,
    'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
    'artifact_store_identity' => str_repeat( 'a', 64 ),
    'cleanup_started' => true,
    'created_at' => $remote_base + 201,
    'intent_id' => str_repeat( 'r', 43 ),
    'object_key' => ManagedArtifactKey::create(
        $remote_expired['batch_id'],
        99,
        str_repeat( 'r', 43 ),
        'image/png'
    ),
);
$remote_expired_capacity['total_bytes'] += $reservation_only_bytes;
$remote_expired_release = ManagedCapacityStore::release_remote_aggregate_once(
    $remote_expired_capacity,
    $remote_expired['batch_id'],
    $remote_expired['bytes'],
    array( $remote_expired['upload_id'] => $remote_expired['bytes'] ),
    array(),
    str_repeat( 'a', 64 ),
    $remote_base + 301
);
eforms_test_assert(
    ! empty( $remote_expired_release['ok'] )
        && $remote_expired_release['released_bytes'] === $remote_expired['bytes'] + $reservation_only_bytes
        && ManagedCapacityStore::write( $remote_expired_capacity_path, $remote_expired_release['record'] )
        && is_dir( $remote_expired['path'] ),
    'The aggregate recovery fixture should stop after durable capacity release but before manifest deletion.'
);
$remote_expired_retry = ManagedCapacityStore::release_remote_aggregate_once(
    $remote_expired_release['record'],
    $remote_expired['batch_id'],
    $remote_expired['bytes'],
    array( $remote_expired['upload_id'] => $remote_expired['bytes'] ),
    array(),
    str_repeat( 'a', 64 ),
    $remote_base + 302
);
eforms_test_assert(
    ! empty( $remote_expired_retry['ok'] )
        && empty( $remote_expired_retry['changed'] )
        && $remote_expired_retry['released_bytes'] === $remote_expired['bytes'] + $reservation_only_bytes,
    'A durable aggregate-release checkpoint should remain absorbing when its first release included reservation-only orphan bytes.'
);
$aggregate_cleanup = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_dir,
    $remote_expired['delete_after'],
    20,
    false,
    array(),
    function ( $object_key, $object_version, $artifact_store_identity ) use ( $remote_expired ) {
        eforms_test_assert( $object_key === $remote_expired['intent']['object_key'] && $object_version === $remote_expired['version'], 'Aggregate cleanup should retain exact-version deletion authority.' );
        eforms_test_assert( $artifact_store_identity === str_repeat( 'a', 64 ), 'Aggregate cleanup should retain exact deployment authority.' );
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert( $aggregate_cleanup['deleted'] === 1 && ! is_dir( $remote_expired['path'] ), 'Expired remote aggregate authority should be removed only after every object is absent.' );
eforms_test_assert( eforms_test_managed_capacity_record( $remote_dir )['total_bytes'] === 0, 'Remote tombstone, intent, and aggregate cleanup should release accounting exactly once.' );

$expired_intent_aggregate = eforms_test_gc_remote_fixture( $remote_dir, 'expired-intent-aggregate', $remote_base + 350, $remote_base + 9000, false );
$failed_expired_intent_deleted = array();
$failed_expired_intent_cleanup = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_dir,
    $expired_intent_aggregate['delete_after'],
    20,
    false,
    array(),
    function ( $object_key, $object_version, $artifact_store_identity ) use ( &$failed_expired_intent_deleted ) {
        $failed_expired_intent_deleted[] = array( $object_key, $object_version, $artifact_store_identity );
        return array( 'ok' => false, 'absent' => false );
    }
);
$failed_expired_intent_manifest = json_decode( file_get_contents( $expired_intent_aggregate['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME ), true );
eforms_test_assert(
    empty( $failed_expired_intent_cleanup['ok'] )
        && $failed_expired_intent_cleanup['reason'] === 'remote_delete_failed'
        && empty( $failed_expired_intent_manifest['intents'] )
        && $failed_expired_intent_manifest['tombstones'][ $expired_intent_aggregate['upload_id'] ]['deleted_at'] === $expired_intent_aggregate['delete_after']
        && $failed_expired_intent_deleted === array( array( $expired_intent_aggregate['intent']['object_key'], '', str_repeat( 'a', 64 ) ) ),
    'Aggregate expiry should persist a readable intent tombstone at the exact logical expiry boundary before remote deletion.'
);
$expired_intent_deleted = array();
$expired_intent_aggregate_cleanup = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_dir,
    $expired_intent_aggregate['delete_after'],
    20,
    false,
    array(),
    function ( $object_key, $object_version, $artifact_store_identity ) use ( $expired_intent_aggregate, &$expired_intent_deleted ) {
        $expired_intent_deleted[] = array( $object_key, $object_version, $artifact_store_identity );
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $expired_intent_aggregate_cleanup['errors'] === 0
        && $expired_intent_aggregate_cleanup['deleted'] >= 1
        && ! is_dir( $expired_intent_aggregate['path'] )
        && $expired_intent_deleted === array( array( $expired_intent_aggregate['intent']['object_key'], '', str_repeat( 'a', 64 ) ) )
        && eforms_test_managed_capacity_record( $remote_dir )['total_bytes'] === 0,
    'Aggregate-expiry retry should consume the durable unresolved-intent tombstone and settle exact cleanup.'
);

$remote_settlement_crash = eforms_test_gc_remote_fixture( $remote_dir, 'settlement-crash', $remote_base + 400, $remote_base + 7600, true );
$settlement_deleted = UploadBatchStore::delete_item(
    $remote_settlement_crash['batch_id'],
    $remote_settlement_crash['secret'],
    $remote_settlement_crash['upload_id'],
    $remote_dir,
    $remote_base + 410
);
eforms_test_assert( ! empty( $settlement_deleted['ok'] ), 'Remote settlement crash fixture should reach its durable delete-pending state.' );
$settlement_manifest_path = $remote_settlement_crash['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$settlement_manifest = json_decode( file_get_contents( $settlement_manifest_path ), true );
$settlement_manifest['tombstones'][ $remote_settlement_crash['upload_id'] ]['capacity_released'] = true;
file_put_contents( $settlement_manifest_path, json_encode( $settlement_manifest, JSON_UNESCAPED_SLASHES ) );
$settlement_remote_calls = 0;
$settlement_repair = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_dir,
    $remote_base + 410 + $drain_seconds,
    20,
    false,
    array(),
    function () use ( &$settlement_remote_calls ) {
        $settlement_remote_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $settlement_repair['errors'] === 0
        && $settlement_repair['released_bytes'] === $remote_settlement_crash['bytes']
        && $settlement_remote_calls === 0
        && eforms_test_managed_capacity_record( $remote_dir )['total_bytes'] === 0,
    'A crash after durable remote absence must settle the retained reservation without deleting the object again.'
);

$remote_survivor = eforms_test_gc_remote_fixture( $remote_dir, 'settlement-survivor', $remote_base + 500, $remote_base + 20000, true );
$settlement_expiry = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_dir,
    $remote_settlement_crash['delete_after'],
    20,
    false,
    array(),
    function () {
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    $settlement_expiry['errors'] === 0
        && ! is_dir( $remote_settlement_crash['path'] )
        && eforms_test_managed_capacity_record( $remote_dir )['total_bytes'] === $remote_survivor['bytes'],
    'Aggregate expiry after a repaired remote release must not debit an unrelated artifact a second time.'
);
eforms_test_remove_tree( $remote_dir );

$invalid_worker_dir = eforms_test_setup_uploads( 'eforms-gc-invalid-worker' );
eforms_test_gc_managed_configure( $invalid_worker_dir );
$invalid_worker_remote = eforms_test_gc_remote_fixture( $invalid_worker_dir, 'invalid-worker-expired', $remote_base + 400, $remote_base + 500, true );
$invalid_worker_lease = PrivateDir::acquire_write_lease( $invalid_worker_dir );
$invalid_worker_tokens = PrivateDir::leased_subdir( $invalid_worker_lease, GcRunner::TOKENS_DIR, true, true );
$invalid_worker_token = $invalid_worker_tokens . '/expired.json';
file_put_contents( $invalid_worker_token, json_encode( array( 'expires' => $remote_base ) ) );
$invalid_worker_preview_key = ManagedArtifactKey::create( eforms_test_digest( 'invalid-worker-preview' ), 0, eforms_test_digest( 'invalid-worker-preview-item' ), 'image/png' );
eforms_test_assert(
    LocalPreviewProvider::delete_cache( $invalid_worker_lease, $invalid_worker_preview_key, '22222222-2222-4222-8222-222222222222' ),
    'The invalid-Worker fixture should persist a later local preview cleanup candidate.'
);
$invalid_worker_preview_root = PrivateDir::leased_subdir( $invalid_worker_lease, LocalPreviewProvider::ROOT_DIR, false, true );
$invalid_worker_preview_paths = glob( $invalid_worker_preview_root . '/*/*/' . LocalPreviewProvider::DELETED_FILENAME );
eforms_test_assert( is_array( $invalid_worker_preview_paths ) && count( $invalid_worker_preview_paths ) === 1, 'The circuit-breaker fixture should expose one preview fence.' );
touch( $invalid_worker_preview_paths[0], $remote_base - Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' ) );
$invalid_worker_lease->release();
define( 'EFORMS_UPLOAD_COMPOSITION', 'unsupported-composition' );
$invalid_worker_gc = GcRunner::run( array( 'now' => $invalid_worker_remote['delete_after'] ) );
eforms_test_assert(
    empty( $invalid_worker_gc['ok'] )
        && strpos( $invalid_worker_gc['reason'], 'remote_delete_failed' ) !== false
        && ! is_file( $invalid_worker_token )
        && $invalid_worker_gc['by_type']['preview_fences']['scanned'] === 0
        && is_file( $invalid_worker_preview_paths[0] )
        && is_dir( $invalid_worker_remote['path'] ),
    'A remote provider failure must preserve the aggregate, stop later cleanup calls, and retain already completed unrelated cleanup.'
);
eforms_test_remove_tree( $invalid_worker_dir );

$remote_reconcile_dir = eforms_test_setup_uploads( 'eforms-gc-remote-reconcile-request' );
eforms_test_gc_managed_configure( $remote_reconcile_dir );
$remote_reconcile = eforms_test_gc_remote_fixture( $remote_reconcile_dir, 'remote-reconcile', $remote_base + 600, $remote_base + 7200, true );
$remote_reconcile_lease = PrivateDir::acquire_write_lease( $remote_reconcile_dir );
$remote_reconcile_tokens = PrivateDir::leased_subdir( $remote_reconcile_lease, GcRunner::TOKENS_DIR, true, true );
$remote_reconcile_token = $remote_reconcile_tokens . '/expired.json';
file_put_contents( $remote_reconcile_token, json_encode( array( 'expires' => $remote_base ) ) );
$remote_reconcile_lease->release();
$remote_reconcile_gc = GcRunner::run( array( 'now' => $remote_base + 700, 'reconcile_capacity' => true ) );
eforms_test_assert(
    ! empty( $remote_reconcile_gc['ok'] )
        && $remote_reconcile_gc['capacity_reconciled'] === true
        && ! is_file( $remote_reconcile_token )
        && is_dir( $remote_reconcile['path'] ),
    'A requested reconciliation should validate retained remote manifest authority without suppressing unrelated cleanup.'
);
eforms_test_remove_tree( $remote_reconcile_dir );

$remote_orphan_dir = eforms_test_setup_uploads( 'eforms-gc-remote-orphan-reconcile' );
eforms_test_gc_managed_configure( $remote_orphan_dir );
$remote_orphan = eforms_test_gc_remote_fixture( $remote_orphan_dir, 'remote-orphan', $remote_base + 800, $remote_base + 8000, false );
$remote_orphan_manifest_path = $remote_orphan['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$remote_orphan_manifest = json_decode( file_get_contents( $remote_orphan_manifest_path ), true );
unset( $remote_orphan_manifest['intents'][ $remote_orphan['upload_id'] ] );
file_put_contents( $remote_orphan_manifest_path, json_encode( $remote_orphan_manifest, JSON_UNESCAPED_SLASHES ) );
$remote_orphan_calls = 0;
$remote_orphan_drain_boundary = $remote_base + 800
    + Anchors::get( 'MANAGED_UPLOAD_INTENT_TTL_SECONDS' )
    + Anchors::get( 'WORKER_UPLOAD_MAX_SECONDS' )
    + Anchors::get( 'WORKER_CLOCK_SKEW_SECONDS' );
$remote_orphan_early = UploadBatchStore::reconcile_capacity(
    $remote_orphan_dir,
    $remote_base + 801,
    $remote_orphan_drain_boundary,
    function () use ( &$remote_orphan_calls ) {
        $remote_orphan_calls++;
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    ! empty( $remote_orphan_early['ok'] )
        && $remote_orphan_calls === 0
        && count( $remote_orphan_early['capacity']['reservations'] ) === 1,
    'Reconciliation must retain a manifest-less Worker reservation through the final inclusive late-upload boundary.'
);
$remote_orphan_reconciled = UploadBatchStore::reconcile_capacity(
    $remote_orphan_dir,
    $remote_base + 801,
    $remote_base + 9000,
    function ( $object_key, $object_version, $artifact_store_identity ) use ( &$remote_orphan_calls, $remote_orphan ) {
        $remote_orphan_calls++;
        eforms_test_assert( $object_key === $remote_orphan['intent']['object_key'] && $object_version === '', 'Remote orphan repair must delete the deterministic pre-manifest key.' );
        eforms_test_assert( $artifact_store_identity === str_repeat( 'a', 64 ), 'Remote orphan repair must retain the reservation-bound Worker identity.' );
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    ! empty( $remote_orphan_reconciled['ok'] )
        && $remote_orphan_calls === 1
        && $remote_orphan_reconciled['capacity']['total_bytes'] === 0
        && $remote_orphan_reconciled['capacity']['reservations'] === array(),
    'Reconciliation should fence, delete, and settle one stale Worker reservation whose manifest authority was lost.'
);
eforms_test_remove_tree( $remote_orphan_dir );

$remote_gc_orphan_dir = eforms_test_setup_uploads( 'eforms-gc-remote-orphan-expiry' );
eforms_test_gc_managed_configure( $remote_gc_orphan_dir );
$remote_gc_orphan = eforms_test_gc_remote_fixture( $remote_gc_orphan_dir, 'remote-gc-orphan', $remote_base + 850, $remote_base + 950, false );
$remote_gc_orphan_manifest_path = $remote_gc_orphan['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$remote_gc_orphan_manifest = json_decode( file_get_contents( $remote_gc_orphan_manifest_path ), true );
unset( $remote_gc_orphan_manifest['intents'][ $remote_gc_orphan['upload_id'] ] );
file_put_contents( $remote_gc_orphan_manifest_path, json_encode( $remote_gc_orphan_manifest, JSON_UNESCAPED_SLASHES ) );
$remote_gc_orphan_calls = 0;
$remote_gc_orphan_result = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_gc_orphan_dir,
    $remote_gc_orphan['delete_after'],
    20,
    false,
    array(),
    function ( $object_key, $object_version, $artifact_store_identity ) use ( &$remote_gc_orphan_calls, $remote_gc_orphan ) {
        $remote_gc_orphan_calls++;
        eforms_test_assert( $object_key === $remote_gc_orphan['intent']['object_key'] && $object_version === '', 'Expired aggregate GC must retain the reservation-owned pre-manifest locator.' );
        eforms_test_assert( $artifact_store_identity === str_repeat( 'a', 64 ), 'Expired aggregate GC must retain the reservation-bound Worker identity.' );
        return array( 'ok' => true, 'absent' => true );
    }
);
eforms_test_assert(
    ! empty( $remote_gc_orphan_result['ok'] )
        && $remote_gc_orphan_result['deleted'] === 1
        && $remote_gc_orphan_calls === 1
        && ! is_dir( $remote_gc_orphan['path'] )
        && eforms_test_managed_capacity_record( $remote_gc_orphan_dir )['total_bytes'] === 0,
    'Expired remote aggregate cleanup must delete a reservation-only object before releasing its locator and capacity.'
);
eforms_test_remove_tree( $remote_gc_orphan_dir );

$remote_breaker_dir = eforms_test_setup_uploads( 'eforms-gc-remote-breaker' );
eforms_test_gc_managed_configure( $remote_breaker_dir );
$remote_breaker_one = eforms_test_gc_remote_fixture( $remote_breaker_dir, 'breaker-one', $remote_base + 900, $remote_base + 1000, true );
$remote_breaker_two = eforms_test_gc_remote_fixture( $remote_breaker_dir, 'breaker-two', $remote_base + 901, $remote_base + 1001, true );
$remote_breaker_calls = 0;
$remote_breaker = UploadBatchStore::gc_aggregates(
    'staged',
    $remote_breaker_dir,
    max( $remote_breaker_one['delete_after'], $remote_breaker_two['delete_after'] ),
    20,
    false,
    array(),
    function () use ( &$remote_breaker_calls ) {
        $remote_breaker_calls++;
        return array( 'ok' => false, 'reason' => 'provider_unavailable' );
    }
);
eforms_test_assert(
    empty( $remote_breaker['ok'] )
        && $remote_breaker['reason'] === 'remote_delete_failed'
        && $remote_breaker_calls === 1
        && is_dir( $remote_breaker_one['path'] )
        && is_dir( $remote_breaker_two['path'] ),
    'Remote aggregate GC should stop issuing provider calls after the first failure and preserve later work for retry.'
);
eforms_test_remove_tree( $remote_breaker_dir );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
Logging::reset_for_tests();
eforms_test_remove_tree( $corrupt_capacity_dir );
echo "All staged-upload GC tests passed.\n";
