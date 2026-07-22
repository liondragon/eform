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
    $manifest_path = $path . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $manifest = json_decode( file_get_contents( $manifest_path ), true );
    $upload_id = 'photo_' . substr( hash( 'sha256', $name ), 0, 12 );
    $item_dir = $path . '/files/' . $upload_id;
    mkdir( $item_dir, 0700, true );
    $source_bytes = strlen( $name ) + 17;
    $master_bytes = strlen( $name ) + 13;
    $preview_bytes = strlen( $name ) + 7;
    file_put_contents( $item_dir . '/master.jpg', str_repeat( 'm', $master_bytes ) );
    file_put_contents( $item_dir . '/preview.jpg', str_repeat( 'p', $preview_bytes ) );
    chmod( $item_dir . '/master.jpg', 0600 );
    chmod( $item_dir . '/preview.jpg', 0600 );
    $manifest['items'][ $upload_id ] = array(
        'upload_id' => $upload_id,
        'ordinal' => 0,
        'source_display_name' => $name . '.png',
        'source_bytes' => $source_bytes,
        'source_mime' => 'image/png',
        'source_width' => 10,
        'source_height' => 10,
        'source_sha256' => hash( 'sha256', str_repeat( 's', $source_bytes ) ),
        'master_relpath' => 'files/' . $upload_id . '/master.jpg',
        'master_bytes' => $master_bytes,
        'master_width' => 10,
        'master_height' => 10,
        'master_sha256' => hash( 'sha256', str_repeat( 'm', $master_bytes ) ),
        'preview_relpath' => 'files/' . $upload_id . '/preview.jpg',
        'preview_bytes' => $preview_bytes,
        'preview_width' => 10,
        'preview_height' => 10,
        'preview_sha256' => hash( 'sha256', str_repeat( 'p', $preview_bytes ) ),
        'managed_bytes' => $master_bytes + $preview_bytes,
        'created_at' => $created_at,
    );
    $manifest['source_bytes'] = $source_bytes;
    $manifest['managed_bytes'] = $master_bytes + $preview_bytes;
    file_put_contents( $manifest_path, json_encode( $manifest ) );
    chmod( $manifest_path, 0600 );

    $capacity_path = $uploads_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
    $capacity = is_file( $capacity_path ) ? json_decode( file_get_contents( $capacity_path ), true ) : null;
    if ( ! is_array( $capacity ) ) {
        $capacity = array(
            'version' => UploadBatchStore::CAPACITY_VERSION,
            'total_bytes' => 0,
            'reservations' => array(),
            'updated_at' => $created_at,
        );
    }
    $capacity['total_bytes'] += $manifest['managed_bytes'];
    $capacity['updated_at'] = $created_at;
    file_put_contents( $capacity_path, json_encode( $capacity ) );
    chmod( $capacity_path, 0600 );

    $submission_id = null;
    if ( $finalized_at !== null ) {
        $submission_id = 'submission-' . substr( hash( 'sha256', $name ), 0, 16 );
        $resolved = UploadBatchStore::resolve_open( $batch_id, $secret, $binding, $field, $uploads_dir, $finalized_at - 1 );
        $claimed = UploadBatchStore::claim_finalization( $batch_id, $secret, $binding, $field, $resolved['items'], $submission_id, $uploads_dir, $finalized_at - 1 );
        eforms_test_assert( ! empty( $claimed['ok'] ), 'Managed GC fixture should claim finalization.' );
        $finalized = UploadBatchStore::finalize( $batch_id, $submission_id, $uploads_dir, $finalized_at );
        eforms_test_assert( ! empty( $finalized['ok'] ), 'Managed GC fixture should finalize.' );
        $path = $uploads_dir . '/eforms-private/submissions/' . Helpers::h2( $submission_id ) . '/' . $submission_id;
    }

    return array(
        'path' => $path,
        'batch_id' => $batch_id,
        'upload_id' => $upload_id,
        'submission_id' => $submission_id,
        'source_bytes' => $source_bytes,
        'master_bytes' => $master_bytes,
        'preview_bytes' => $preview_bytes,
        'managed_bytes' => $master_bytes + $preview_bytes,
        'staged_delete_after' => $staged_delete_after,
        'delete_after' => $finalized_at === null
            ? $created['batch']['delete_after']
            : $finalized['submission']['delete_after'],
    );
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

$uploads_dir = eforms_test_setup_uploads( 'eforms-gc-managed' );
eforms_test_gc_managed_configure( $uploads_dir );
$base = 1700000000;
$run_now = $base + Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' );

$expired_staged = eforms_test_gc_managed_fixture( $uploads_dir, 'expired-staged', $base, $base + 100 );
$fresh_staged = eforms_test_gc_managed_fixture( $uploads_dir, 'fresh-staged', $run_now, $run_now + 3600 );
$expired_final = eforms_test_gc_managed_fixture( $uploads_dir, 'expired-final', $base - 2, $base + 3600, $base );
$fresh_final = eforms_test_gc_managed_fixture( $uploads_dir, 'fresh-final', $base, $run_now + 3600, $run_now - 100 );
eforms_test_assert( $expired_staged['delete_after'] <= $run_now, 'The staged fixture should be expired at injected GC time.' );
eforms_test_assert( $expired_final['delete_after'] === $run_now, 'The finalized fixture should become eligible exactly at delete_after.' );

$capacity_before = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert( is_array( $capacity_before ), 'Managed capacity should be readable before GC.' );
$capacity_path = $uploads_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
$capacity_with_stale = $capacity_before;
$capacity_with_stale['total_bytes'] += 999;
$capacity_with_stale['reservations']['stale_gc_reservation'] = array(
    'batch_id' => $expired_staged['batch_id'],
    'upload_id' => 'interrupted_upload',
    'bytes' => 999,
    'created_at' => $run_now - Anchors::get( 'MANAGED_RESERVATION_STALE_SECONDS' ),
);
file_put_contents( $capacity_path, json_encode( $capacity_with_stale ) );
chmod( $capacity_path, 0600 );

$dry_run = GcRunner::run( array( 'dry_run' => true, 'now' => $run_now, 'limit' => 500 ) );
eforms_test_assert( $dry_run['ok'] === true, 'Managed aggregate dry-run should succeed: ' . json_encode( $dry_run ) );
eforms_test_assert( $dry_run['by_type']['staged_batches']['candidates'] === 1, 'Dry-run should find one expired staged aggregate.' );
eforms_test_assert( $dry_run['by_type']['finalized_submissions']['candidates'] === 1, 'Dry-run should find one expired finalized aggregate.' );
eforms_test_assert( $dry_run['by_type']['staged_batches']['candidate_master_bytes'] === $expired_staged['master_bytes'], 'Staged dry-run should report master bytes separately.' );
eforms_test_assert( $dry_run['by_type']['staged_batches']['candidate_preview_bytes'] === $expired_staged['preview_bytes'], 'Staged dry-run should report preview bytes separately.' );
eforms_test_assert( $dry_run['by_type']['finalized_submissions']['candidate_master_bytes'] === $expired_final['master_bytes'], 'Finalized dry-run should report master bytes separately.' );
eforms_test_assert( $dry_run['deleted'] === 0 && $dry_run['by_type']['staged_batches']['released_bytes'] === 0, 'Dry-run should delete nothing and release no capacity.' );
eforms_test_assert( is_dir( $expired_staged['path'] ) && is_dir( $expired_final['path'] ), 'Dry-run should preserve eligible aggregates.' );
$capacity_after_dry_run = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert( $capacity_after_dry_run === $capacity_with_stale, 'Dry-run should preserve capacity accounting and stale reservations.' );

$apply = GcRunner::run( array( 'now' => $run_now, 'limit' => 500, 'reconcile_capacity' => true ) );
$expected_release = $expired_staged['managed_bytes'] + $expired_final['managed_bytes'];
eforms_test_assert( $apply['ok'] === true && $apply['deleted'] === 2, 'Managed GC should delete both eligible aggregate families.' );
eforms_test_assert( $apply['capacity_reconciled'] === true && $apply['stale_reservations_removed'] === 1, 'Applying GC should reconcile one stale reservation before aggregate deletion.' );
eforms_test_assert( $apply['by_type']['staged_batches']['released_bytes'] === $expired_staged['managed_bytes'], 'Staged deletion should release its exact managed bytes.' );
eforms_test_assert( $apply['by_type']['finalized_submissions']['released_bytes'] === $expired_final['managed_bytes'], 'Finalized deletion should release its exact managed bytes.' );
eforms_test_assert( ! is_dir( $expired_staged['path'] ) && ! is_dir( $expired_final['path'] ), 'Apply should remove expired aggregate directories.' );
eforms_test_assert( is_dir( $fresh_staged['path'] ) && is_dir( $fresh_final['path'] ), 'Apply should preserve pre-expiry aggregates.' );
$capacity_after_apply = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert( $capacity_after_apply['total_bytes'] === $capacity_before['total_bytes'] - $expected_release, 'Apply should release aggregate capacity exactly once.' );

$idempotent = GcRunner::run( array( 'now' => $run_now, 'limit' => 500 ) );
eforms_test_assert( $idempotent['ok'] === true && $idempotent['candidates'] === 0 && $idempotent['deleted'] === 0, 'A repeated managed GC run should be idempotent.' );
$capacity_after_retry = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert( $capacity_after_retry['total_bytes'] === $capacity_after_apply['total_bytes'], 'An idempotent GC retry should not release capacity twice.' );

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
unset( $renamed_manifest['finalized_at'], $renamed_manifest['gallery_expires_at'], $renamed_manifest['email_attempted_at'] );
file_put_contents( $renamed_manifest_path, json_encode( $renamed_manifest ) );
chmod( $renamed_manifest_path, 0600 );
$renamed_capacity_before = eforms_test_managed_capacity_record( $renamed_finalizing_dir );
$renamed_dry_run = GcRunner::run( array( 'dry_run' => true, 'now' => $renamed_finalizing['staged_delete_after'], 'limit' => 500 ) );
eforms_test_assert( $renamed_dry_run['by_type']['finalized_submissions']['candidates'] === 1, 'Dry-run should find a post-rename finalizing aggregate at its staged deadline.' );
eforms_test_assert( is_dir( $renamed_finalizing['path'] ), 'Dry-run should preserve the post-rename finalizing aggregate.' );
$renamed_apply = GcRunner::run( array( 'now' => $renamed_finalizing['staged_delete_after'], 'limit' => 500 ) );
eforms_test_assert( $renamed_apply['ok'] === true && $renamed_apply['by_type']['finalized_submissions']['deleted'] === 1, 'GC should delete an expired post-rename finalizing aggregate.' );
eforms_test_assert( $renamed_apply['by_type']['finalized_submissions']['released_bytes'] === $renamed_finalizing['managed_bytes'], 'Post-rename finalizing GC should release exact managed capacity.' );
eforms_test_assert( ! is_dir( $renamed_finalizing['path'] ), 'GC should remove the expired post-rename finalizing directory.' );
$renamed_capacity_after = eforms_test_managed_capacity_record( $renamed_finalizing_dir );
eforms_test_assert( $renamed_capacity_after['total_bytes'] === $renamed_capacity_before['total_bytes'] - $renamed_finalizing['managed_bytes'], 'Post-rename finalizing GC should release capacity exactly once.' );
eforms_test_remove_tree( $renamed_finalizing_dir );

$committing_dir = eforms_test_setup_uploads( 'eforms-gc-committed-reservation' );
eforms_test_gc_managed_configure( $committing_dir );
$committing = eforms_test_gc_managed_fixture( $committing_dir, 'committing-expired', $base, $base + 100 );
$committing_capacity_path = $committing_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
$committing_capacity = eforms_test_managed_capacity_record( $committing_dir );
$committing_reserved_bytes = Anchors::get( 'STAGED_MASTER_MAX_BYTES' ) + Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' );
$committing_reservation_id = hash( 'sha256', $committing['batch_id'] . "\0" . $committing['upload_id'] );
$committing_capacity['total_bytes'] = $committing_reserved_bytes;
$committing_capacity['reservations'][ $committing_reservation_id ] = array(
    'batch_id' => $committing['batch_id'],
    'upload_id' => $committing['upload_id'],
    'bytes' => $committing_reserved_bytes,
    'created_at' => $base,
);
file_put_contents( $committing_capacity_path, json_encode( $committing_capacity ) );
chmod( $committing_capacity_path, 0600 );
$committing_health = eforms_gc_capacity_health( $committing_dir );
eforms_test_assert( ! empty( $committing_health['ok'] ) && $committing_health['capacity']['consistent'] === true, 'The committed-reservation GC fixture should begin in a supported healthy crash state.' );

$committing_gc = GcRunner::run( array( 'now' => $run_now, 'limit' => 500 ) );
eforms_test_assert( $committing_gc['ok'] === true && $committing_gc['deleted'] === 1, 'Ordinary GC should collect an expired aggregate with an unsettled committed reservation.' );
eforms_test_assert( $committing_gc['by_type']['staged_batches']['released_bytes'] === $committing_reserved_bytes, 'GC should release the reservation contribution instead of double-counting its committed item.' );
$committing_after = eforms_test_managed_capacity_record( $committing_dir );
eforms_test_assert( $committing_after['total_bytes'] === 0 && $committing_after['reservations'] === array(), 'Committed-reservation GC should leave zero managed capacity and no reservation.' );
eforms_test_remove_tree( $committing_dir );

$orphan_dir = eforms_test_setup_uploads( 'eforms-gc-materialized-orphan' );
eforms_test_gc_managed_configure( $orphan_dir );
$orphan = eforms_test_gc_managed_fixture( $orphan_dir, 'materialized-orphan-expired', $base, $base + 100 );
$orphan_manifest_path = $orphan['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$orphan_manifest = json_decode( file_get_contents( $orphan_manifest_path ), true );
unset( $orphan_manifest['items'][ $orphan['upload_id'] ] );
$orphan_manifest['source_bytes'] = 0;
$orphan_manifest['managed_bytes'] = 0;
file_put_contents( $orphan_manifest_path, json_encode( $orphan_manifest ) );
$orphan_capacity_path = $orphan_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
$orphan_capacity = eforms_test_managed_capacity_record( $orphan_dir );
$orphan_reserved_bytes = Anchors::get( 'STAGED_MASTER_MAX_BYTES' ) + Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' );
$orphan_reservation_id = hash( 'sha256', $orphan['batch_id'] . "\0" . $orphan['upload_id'] );
$orphan_capacity['total_bytes'] = $orphan_reserved_bytes;
$orphan_capacity['reservations'][ $orphan_reservation_id ] = array(
    'batch_id' => $orphan['batch_id'],
    'upload_id' => $orphan['upload_id'],
    'bytes' => $orphan_reserved_bytes,
    'created_at' => $base,
);
file_put_contents( $orphan_capacity_path, json_encode( $orphan_capacity ) );
chmod( $orphan_capacity_path, 0600 );
$orphan_health = eforms_gc_capacity_health( $orphan_dir );
eforms_test_assert(
    ! empty( $orphan_health['ok'] )
        && $orphan_health['capacity']['consistent'] === true
        && $orphan_health['capacity']['orphaned_bytes'] === $orphan['managed_bytes'],
    'Capacity health should attribute materialized files whose manifest item did not commit.'
);
if ( function_exists( 'symlink' ) ) {
    $linked_capacity_dir = eforms_test_setup_uploads( 'eforms-gc-linked-capacity-member' );
    eforms_test_gc_managed_configure( $linked_capacity_dir );
    $linked_capacity = eforms_test_gc_managed_fixture( $linked_capacity_dir, 'linked-capacity-member', $base, $base + 100 );
    $linked_manifest_path = $linked_capacity['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $linked_manifest = json_decode( file_get_contents( $linked_manifest_path ), true );
    $linked_relpath = $linked_manifest['items'][ $linked_capacity['upload_id'] ]['master_relpath'];
    $linked_member = $linked_capacity['path'] . '/' . $linked_relpath;
    $linked_target = eforms_test_write_file( $linked_capacity_dir, 'outside-capacity-member.bin', 'outside' );
    eforms_test_assert( unlink( $linked_member ) && symlink( $linked_target, $linked_member ), 'Capacity symlink fixture should replace only one managed member.' );
    $linked_health = eforms_gc_capacity_health( $linked_capacity_dir );
    eforms_test_assert( empty( $linked_health['ok'] ) && $linked_health['reason'] === 'capacity_scan_failed', 'Capacity health should fail closed on symlinked managed members.' );
    eforms_test_remove_tree( $linked_capacity_dir );
    eforms_test_gc_managed_configure( $orphan_dir );
}
$orphan_reconcile = UploadBatchStore::reconcile_capacity( $orphan_dir, $run_now, $run_now );
eforms_test_assert(
    ! empty( $orphan_reconcile['ok'] )
        && $orphan_reconcile['materialized_reservations_retained'] === 1
        && isset( $orphan_reconcile['capacity']['reservations'][ $orphan_reservation_id ] )
        && $orphan_reconcile['capacity']['total_bytes'] === $orphan_reserved_bytes,
    'Capacity repair must retain stale reservation attribution while its orphan item files exist.'
);
$orphan_gc = GcRunner::run( array( 'now' => $run_now, 'limit' => 500 ) );
eforms_test_assert( $orphan_gc['ok'] === true && $orphan_gc['deleted'] === 1, 'Ordinary GC should collect an expired aggregate with materialized orphan files.' );
eforms_test_assert( $orphan_gc['by_type']['staged_batches']['released_bytes'] === $orphan_reserved_bytes, 'Orphan GC should release its retained reservation contribution exactly once.' );
$orphan_after = eforms_test_managed_capacity_record( $orphan_dir );
eforms_test_assert( $orphan_after['total_bytes'] === 0 && $orphan_after['reservations'] === array(), 'Orphan GC should leave no stale capacity or reservation.' );
eforms_test_remove_tree( $orphan_dir );

$tombstone_dir = eforms_test_setup_uploads( 'eforms-gc-pending-tombstone' );
eforms_test_gc_managed_configure( $tombstone_dir );
$tombstone = eforms_test_gc_managed_fixture( $tombstone_dir, 'tombstone-expired', $base, $base + 100 );
$tombstone_manifest_path = $tombstone['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$tombstone_manifest = json_decode( file_get_contents( $tombstone_manifest_path ), true );
$tombstone_item = $tombstone_manifest['items'][ $tombstone['upload_id'] ];
unset( $tombstone_manifest['items'][ $tombstone['upload_id'] ] );
$tombstone_manifest['source_bytes'] = 0;
$tombstone_manifest['managed_bytes'] = 0;
$tombstone_manifest['tombstones'][ $tombstone['upload_id'] ] = array(
    'deleted_at' => $base + 1,
    'managed_bytes' => $tombstone['managed_bytes'],
    'master_relpath' => $tombstone_item['master_relpath'],
    'preview_relpath' => $tombstone_item['preview_relpath'],
    'capacity_release_started' => false,
    'capacity_released' => false,
);
file_put_contents( $tombstone_manifest_path, json_encode( $tombstone_manifest ) );
$tombstone_gc = GcRunner::run( array( 'now' => $run_now, 'limit' => 500 ) );
eforms_test_assert( $tombstone_gc['ok'] === true && $tombstone_gc['deleted'] === 1, 'Ordinary GC should collect an expired aggregate with a pending deletion tombstone.' );
$tombstone_capacity_after = eforms_test_managed_capacity_record( $tombstone_dir );
eforms_test_assert( $tombstone_capacity_after['total_bytes'] === 0, 'Pending-tombstone GC should release the tombstone-owned capacity exactly once.' );
eforms_test_remove_tree( $tombstone_dir );

$settled_tombstone_dir = eforms_test_setup_uploads( 'eforms-gc-settled-tombstone' );
eforms_test_gc_managed_configure( $settled_tombstone_dir );
$settled_tombstone = eforms_test_gc_managed_fixture( $settled_tombstone_dir, 'settled-tombstone-expired', $base, $base + 100 );
$settled_manifest_path = $settled_tombstone['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$settled_manifest = json_decode( file_get_contents( $settled_manifest_path ), true );
$settled_item = $settled_manifest['items'][ $settled_tombstone['upload_id'] ];
unset( $settled_manifest['items'][ $settled_tombstone['upload_id'] ] );
$settled_manifest['source_bytes'] = 0;
$settled_manifest['managed_bytes'] = 0;
$settled_manifest['tombstones'][ $settled_tombstone['upload_id'] ] = array(
    'deleted_at' => $base + 1,
    'managed_bytes' => $settled_tombstone['managed_bytes'],
    'master_relpath' => $settled_item['master_relpath'],
    'preview_relpath' => $settled_item['preview_relpath'],
    'capacity_release_started' => false,
    'capacity_released' => false,
);
file_put_contents( $settled_manifest_path, json_encode( $settled_manifest ) );
unlink( $settled_tombstone['path'] . '/' . $settled_item['master_relpath'] );
unlink( $settled_tombstone['path'] . '/' . $settled_item['preview_relpath'] );
$settled_gc = GcRunner::run( array( 'now' => $run_now, 'limit' => 500 ) );
eforms_test_assert( $settled_gc['ok'] === true && $settled_gc['deleted'] === 1, 'GC should conservatively recover when tombstone file deletion has an ambiguous capacity outcome.' );
$settled_capacity_after = eforms_test_managed_capacity_record( $settled_tombstone_dir );
eforms_test_assert( $settled_capacity_after['total_bytes'] === $settled_tombstone['managed_bytes'], 'Bounded ordinary GC should preserve uncertain tombstone bytes as a safe overcount.' );
$settled_reconcile = GcRunner::run( array( 'now' => $run_now, 'limit' => 500, 'reconcile_capacity' => true ) );
eforms_test_assert( $settled_reconcile['ok'] === true && $settled_reconcile['capacity_reconciled'] === true, 'Explicit repair should reconcile the ambiguous tombstone overcount.' );
$settled_capacity_repaired = eforms_test_managed_capacity_record( $settled_tombstone_dir );
eforms_test_assert( $settled_capacity_repaired['total_bytes'] === 0, 'Explicit reconciliation should restore exact capacity after ambiguous tombstone cleanup.' );
eforms_test_remove_tree( $settled_tombstone_dir );

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
mkdir( $stale_partial_path . '/files', 0700, true );
file_put_contents( $stale_partial_path . '/' . UploadBatchStore::LOCK_FILENAME, '' );
touch( $stale_partial_path . '/files', $run_now );
$temp_partial_id = eforms_test_gc_batch_id_for_name( 'stale-partial-with-manifest-temp' );
$temp_partial_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $temp_partial_id ) . '/' . $temp_partial_id;
mkdir( $temp_partial_path . '/files', 0700, true );
file_put_contents( $temp_partial_path . '/' . UploadBatchStore::LOCK_FILENAME, '' );
file_put_contents( $temp_partial_path . '/.manifest.json.fedcba9876543210.tmp', '{"version":' );
touch( $temp_partial_path . '/files', $run_now );
$fresh_partial_id = eforms_test_gc_batch_id_for_name( 'fresh-partial' );
$fresh_partial_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $fresh_partial_id ) . '/' . $fresh_partial_id;
mkdir( $fresh_partial_path . '/files', 0700, true );
file_put_contents( $fresh_partial_path . '/' . UploadBatchStore::LOCK_FILENAME, '' );
touch( $fresh_partial_path . '/files', $run_now + 1 );
$residue_partial_id = eforms_test_gc_batch_id_for_name( 'partial-with-residue' );
$residue_partial_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $residue_partial_id ) . '/' . $residue_partial_id;
mkdir( $residue_partial_path . '/files', 0700, true );
file_put_contents( $residue_partial_path . '/files/unexpected.pending', 'residue' );
touch( $residue_partial_path . '/files', $run_now );
$linked_partial_id = eforms_test_gc_batch_id_for_name( 'linked-partial' );
$linked_partial_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $linked_partial_id ) . '/' . $linked_partial_id;
$linked_target_path = $partial_dir . '/outside-managed-partial';
mkdir( dirname( $linked_partial_path ), 0700, true );
mkdir( $linked_target_path . '/files', 0700, true );
file_put_contents( $linked_target_path . '/files/do-not-delete', 'outside' );
symlink( $linked_target_path, $linked_partial_path );
$linked_files_id = eforms_test_gc_batch_id_for_name( 'linked-files-partial' );
$linked_files_path = $partial_dir . '/eforms-private/staged/' . Helpers::h2( $linked_files_id ) . '/' . $linked_files_id;
$linked_files_target = $partial_dir . '/outside-managed-files';
mkdir( $linked_files_path, 0700, true );
mkdir( $linked_files_target, 0700, true );
file_put_contents( $linked_files_target . '/do-not-delete', 'outside' );
symlink( $linked_files_target, $linked_files_path . '/files' );
clearstatcache();

$partial_dry_run = UploadBatchStore::gc_aggregates( 'staged', $partial_dir, $partial_now, 500, true );
eforms_test_assert( $partial_dry_run['ok'] === true && $partial_dry_run['candidates'] === 2 && $partial_dry_run['errors'] === 2, 'Dry-run should recognize stale safe partial batches with or without an owner-created manifest temp.' );
eforms_test_assert( is_dir( $stale_partial_path ) && is_dir( $temp_partial_path ) && is_dir( $fresh_partial_path ) && is_dir( $residue_partial_path ) && is_link( $linked_partial_path ), 'Partial-batch dry-run should preserve stale, fresh, recoverable, unrecognized, and symlinked directories.' );
$partial_apply = UploadBatchStore::gc_aggregates( 'staged', $partial_dir, $partial_now, 500 );
eforms_test_assert( $partial_apply['ok'] === true && $partial_apply['deleted'] === 2 && $partial_apply['errors'] === 2, 'Applying managed GC should collect stale manifest-less partial batches, including an abandoned owner temp, and reject unfamiliar or symlinked residue.' );
eforms_test_assert( ! is_dir( $stale_partial_path ) && ! is_dir( $temp_partial_path ) && is_dir( $fresh_partial_path ) && is_file( $residue_partial_path . '/files/unexpected.pending' ) && is_file( $linked_target_path . '/files/do-not-delete' ) && is_file( $linked_files_target . '/do-not-delete' ), 'Partial cleanup should delete only stale recognizable aggregates and never traverse symlinked aggregate or files paths.' );
eforms_test_remove_tree( $partial_dir );

$corrupt_manifest_dir = eforms_test_setup_uploads( 'eforms-gc-corrupt-manifest' );
eforms_test_gc_managed_configure( $corrupt_manifest_dir );
$corrupt_manifest = eforms_test_gc_managed_fixture( $corrupt_manifest_dir, 'corrupt-manifest', $base, $base + 10 );
file_put_contents( $corrupt_manifest['path'] . '/' . UploadBatchStore::MANIFEST_FILENAME, '{invalid' );
$manifest_failure = GcRunner::run( array( 'now' => $run_now, 'limit' => 500 ) );
eforms_test_assert( $manifest_failure['ok'] === false && strpos( $manifest_failure['reason'], 'manifest_invalid' ) !== false, 'A corrupt managed manifest should fail closed with an observable reason.' );
eforms_test_assert( is_dir( $corrupt_manifest['path'] ), 'A corrupt managed manifest should preserve its aggregate.' );
eforms_test_remove_tree( $corrupt_manifest_dir );

$corrupt_capacity_dir = eforms_test_setup_uploads( 'eforms-gc-corrupt-capacity' );
eforms_test_gc_managed_configure( $corrupt_capacity_dir );
$corrupt_capacity = eforms_test_gc_managed_fixture( $corrupt_capacity_dir, 'corrupt-capacity', $base, $base + 10 );
file_put_contents( $corrupt_capacity_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME, '{invalid' );
$capacity_failure = GcRunner::run( array( 'now' => $run_now, 'limit' => 500 ) );
eforms_test_assert( $capacity_failure['ok'] === false && strpos( $capacity_failure['reason'], 'capacity_invalid' ) !== false, 'A corrupt managed capacity record should fail closed with an observable reason.' );
eforms_test_assert( is_dir( $corrupt_capacity['path'] ), 'Corrupt capacity accounting should preserve the aggregate.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
Logging::reset_for_tests();
eforms_test_remove_tree( $corrupt_capacity_dir );
echo "All staged-upload GC tests passed.\n";
