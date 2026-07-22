<?php
/**
 * Integration coverage for the optional bounded local preview cache.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/LocalPreviewProvider.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';

$uploads_dir = eforms_test_setup_uploads( 'eforms-local-preview' );
$lease = PrivateDir::acquire_write_lease( $uploads_dir );
eforms_test_assert( $lease instanceof PrivateDirLease, 'The preview fixture should acquire the shared upload lifecycle lease.' );

$first = eforms_test_local_preview_artifact( $lease, $uploads_dir, 'preview-batch-a', 'preview-a' );
$encodes = 0;
$encoder = function ( $source, $mime, $destination ) use ( &$encodes ) {
    $encodes++;
    return file_put_contents( $destination, "\xff\xd8\xff\xd9" ) === 4;
};
$admission = function ( $preview_lease, $path, $bytes ) {
    return $preview_lease instanceof PrivateDirLease
        && ! file_exists( $path )
        && file_put_contents( $path, str_repeat( "\0", $bytes ), LOCK_EX ) === $bytes
        && chmod( $path, 0600 );
};
$rendered = LocalPreviewProvider::render( $first, $uploads_dir, 1, $encoder, $admission );
eforms_test_assert( ! empty( $rendered['ok'] ) && $rendered['mime'] === 'image/jpeg' && $rendered['bytes'] === 4, 'The local provider should atomically publish one bounded JPEG cache member.' );
if ( isset( $rendered['stream'] ) && is_resource( $rendered['stream'] ) ) {
    fclose( $rendered['stream'] );
}
$cached = LocalPreviewProvider::render( $first, $uploads_dir, 1, $encoder, $admission );
eforms_test_assert( ! empty( $cached['ok'] ) && $encodes === 1, 'An immutable object/version/recipe cache hit should not convert again.' );
if ( isset( $cached['stream'] ) && is_resource( $cached['stream'] ) ) {
    fclose( $cached['stream'] );
}
$live_cache_gc = LocalPreviewProvider::gc_deleted_fences( $lease, time(), 1, false );
eforms_test_assert(
    ! empty( $live_cache_gc['ok'] )
        && $live_cache_gc['scanned'] === 1
        && $live_cache_gc['candidates'] === 0
        && $live_cache_gc['deleted'] === 0,
    'Preview-fence GC should skip a healthy live cache without reporting corrupt fence state.'
);

$progress_uploads = eforms_test_setup_uploads( 'eforms-local-preview-fence-progress' );
$progress_lease = PrivateDir::acquire_write_lease( $progress_uploads );
eforms_test_assert( $progress_lease instanceof PrivateDirLease, 'The preview-fence progress fixture should acquire its lifecycle lease.' );
$progress_root = PrivateDir::leased_subdir( $progress_lease, LocalPreviewProvider::ROOT_DIR, true, true );
$progress_identities = array();
foreach ( array( 'live-prefix', 'eligible-fence' ) as $label ) {
    $identity = hash( 'sha256', $label );
    $progress_identities[] = array( 'identity' => $identity, 'shard' => Helpers::h2( $identity ) );
}
usort(
    $progress_identities,
    function ( $left, $right ) {
        return strcmp( $left['shard'] . '/' . $left['identity'], $right['shard'] . '/' . $right['identity'] );
    }
);
foreach ( $progress_identities as $entry ) {
    $shard_path = $progress_root . '/' . $entry['shard'];
    $identity_path = $shard_path . '/' . $entry['identity'];
    if ( ! is_dir( $shard_path ) ) {
        mkdir( $shard_path, 0700 );
    }
    mkdir( $identity_path, 0700 );
}
$eligible_path = $progress_root . '/' . $progress_identities[1]['shard'] . '/' . $progress_identities[1]['identity'];
file_put_contents( $eligible_path . '/' . LocalPreviewProvider::LOCK_FILENAME, '' );
file_put_contents( $eligible_path . '/' . LocalPreviewProvider::DELETED_FILENAME, "deleted\n" );
$progress_now = time();
touch( $eligible_path . '/' . LocalPreviewProvider::DELETED_FILENAME, $progress_now - Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' ) );
$progress_first = LocalPreviewProvider::gc_deleted_fences( $progress_lease, $progress_now, 1, false );
eforms_test_assert(
    ! empty( $progress_first['ok'] )
        && $progress_first['scanned'] === 1
        && $progress_first['candidates'] === 0
        && $progress_first['cursor'] === array(
            'shard' => $progress_identities[0]['shard'],
            'identity' => $progress_identities[0]['identity'],
        ),
    'A live cache that consumes the scan budget should retain its cursor for the next bounded pass.'
);
$progress_second = LocalPreviewProvider::gc_deleted_fences( $progress_lease, $progress_now, 1, false, $progress_first['cursor'] );
eforms_test_assert(
    ! empty( $progress_second['ok'] )
        && $progress_second['scanned'] === 1
        && $progress_second['candidates'] === 1
        && $progress_second['deleted'] === 1
        && ! file_exists( $eligible_path ),
    'The next bounded pass should advance beyond a live prefix and reclaim the eligible fence.'
);
$progress_lease->release();
eforms_test_remove_tree( $progress_uploads );

$invalid = LocalPreviewProvider::render( $first, $uploads_dir, Anchors::get( 'LOCAL_PREVIEW_CONCURRENCY_MAX' ) + 1, $encoder, $admission );
eforms_test_assert( empty( $invalid['ok'] ) && $invalid['reason'] === 'configuration_invalid', 'Concurrency above the hard ceiling should reject only the optional provider.' );
$wrong_shard = $first;
$wrong_shard['object_key'] = preg_replace( '#^artifacts/[0-9a-f]{2}/#', strpos( $first['object_key'], 'artifacts/00/' ) === 0 ? 'artifacts/ff/' : 'artifacts/00/', $first['object_key'] );
$invalid = LocalPreviewProvider::render( $wrong_shard, $uploads_dir, 1, $encoder, $admission );
eforms_test_assert( empty( $invalid['ok'] ) && $invalid['reason'] === 'configuration_invalid', 'The preview provider should reject an object key whose shard does not match its identity.' );

$missing_decoder = LocalPreviewProvider::readiness(
    1,
    array(
        'memory_limit' => -1,
        'execution_limit' => 0,
        'imagick_support' => function ( $mime ) {
            return $mime !== 'image/heic' && $mime !== 'image/heif';
        },
        'imagick_jpeg_encode' => true,
    )
);
eforms_test_assert(
    empty( $missing_decoder['ok'] )
        && $missing_decoder['missing_mimes'] === array( 'image/heic', 'image/heif' ),
    'Local preview readiness must reject an Imagick installation missing any accepted input decoder.'
);
$missing_encoder = LocalPreviewProvider::readiness(
    1,
    array(
        'memory_limit' => -1,
        'execution_limit' => 0,
        'imagick_support' => function () {
            return true;
        },
        'imagick_jpeg_encode' => false,
    )
);
eforms_test_assert(
    empty( $missing_encoder['ok'] )
        && $missing_encoder['missing_operations'] === array( 'jpeg_encode' ),
    'Local preview readiness must verify the fixed JPEG output operation.'
);
$memory_unavailable = LocalPreviewProvider::readiness(
    1,
    array( 'memory_limit' => '767M', 'execution_limit' => 0, 'imagick' => true )
);
eforms_test_assert(
    empty( $memory_unavailable['ok'] ) && $memory_unavailable['reason'] === 'memory_limit',
    'Local preview readiness must preserve the fixed memory floor required by its full-image decode.'
);
$execution_unavailable = LocalPreviewProvider::readiness(
    1,
    array( 'memory_limit' => '768M', 'execution_limit' => 59, 'imagick' => true )
);
eforms_test_assert(
    empty( $execution_unavailable['ok'] ) && $execution_unavailable['reason'] === 'execution_limit',
    'Local preview readiness must preserve the fixed execution-time floor required by its full-image decode.'
);

$root = PrivateDir::leased_subdir( $lease, LocalPreviewProvider::ROOT_DIR, false, true );
$slot_dir = $root . '/' . LocalPreviewProvider::SLOTS_DIR;
$slot = fopen( $slot_dir . '/slot-0.lock', 'c+b' );
eforms_test_assert( is_resource( $slot ) && flock( $slot, LOCK_EX | LOCK_NB ), 'The saturation fixture should hold the sole global conversion slot.' );
$second = eforms_test_local_preview_artifact( $lease, $uploads_dir, 'preview-batch-b', 'preview-b' );
$busy = LocalPreviewProvider::render( $second, $uploads_dir, 1, $encoder, $admission );
eforms_test_assert( empty( $busy['ok'] ) && ! empty( $busy['transient'] ) && $busy['retry_after'] === Anchors::get( 'LOCAL_PREVIEW_RETRY_AFTER_SECONDS' ), 'A saturated local provider should return one bounded transient result without converting.' );
$parallel = LocalPreviewProvider::render( $second, $uploads_dir, 2, $encoder, $admission );
eforms_test_assert( ! empty( $parallel['ok'] ), 'The approved concurrency of two should allow another object to use the second slot.' );
if ( isset( $parallel['stream'] ) && is_resource( $parallel['stream'] ) ) {
    fclose( $parallel['stream'] );
}
flock( $slot, LOCK_UN );
fclose( $slot );

$claim_uploads_dir = eforms_test_setup_uploads( 'eforms-local-preview-capacity' );
$claim_lease = PrivateDir::acquire_write_lease( $claim_uploads_dir );
eforms_test_assert( $claim_lease instanceof PrivateDirLease, 'The preview-capacity fixture should acquire its own lifecycle lease.' );
$claim_now = time();
$claim_secret = rtrim(
    strtr( base64_encode( str_repeat( "\x6a", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ),
    '='
);
$claim_field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 1048576,
    'max_files' => 1,
    'max_total_bytes' => 1048576,
);
$claim_batch = UploadBatchStore::create_batch(
    array(
        'raw_token' => 'preview-capacity-token',
        'form_id' => 'preview-capacity-form',
        'instance_id' => 'preview-capacity-instance',
        'field_key' => 'photos',
        'accept_until' => $claim_now + 3600,
    ),
    $claim_secret,
    $claim_field,
    $claim_uploads_dir,
    $claim_now
);
eforms_test_assert( ! empty( $claim_batch['ok'] ), 'The preview-capacity fixture should create one local aggregate.' );
$claim_upload_id = 'preview_capacity_pending';
$claim_intent = UploadBatchStore::authorize_intent(
    $claim_batch['batch']['batch_id'],
    $claim_secret,
    $claim_upload_id,
    0,
    'pending.png',
    1048576,
    'image/png',
    1048576,
    $claim_uploads_dir,
    array(
        'now' => $claim_now,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 8388608,
    )
);
eforms_test_assert( ! empty( $claim_intent['ok'] ), 'The preview-capacity fixture should reserve artifact and multipart allocations.' );
$third = eforms_test_local_preview_artifact( $claim_lease, $claim_uploads_dir, 'preview-batch-c', 'preview-c' );
$denied_admission = function ( $preview_lease, $path, $bytes ) use ( $claim_uploads_dir ) {
    $outstanding = 2 * 1048576;
    return UploadBatchStore::reserve_preview_cache_allocation(
        $claim_uploads_dir,
        $preview_lease,
        $path,
        $bytes,
        array( 'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + $outstanding + $bytes - 1 )
    );
};
$encodes_before_denial = $encodes;
$headroom_denied = LocalPreviewProvider::render( $third, $claim_uploads_dir, 1, $encoder, $denied_admission );
eforms_test_assert(
    empty( $headroom_denied['ok'] )
        && $headroom_denied['reason'] === 'preview_failed'
        && $encodes === $encodes_before_denial,
    'Preview admission must preserve artifact and transient bytes already promised to local uploads.'
);
$claim_deleted = UploadBatchStore::delete_item(
    $claim_batch['batch']['batch_id'],
    $claim_secret,
    $claim_upload_id,
    $claim_uploads_dir,
    $claim_now
);
eforms_test_assert( ! empty( $claim_deleted['ok'] ), 'The preview-capacity fixture should release its pending allocation.' );
$claim_lease->release();
eforms_test_remove_tree( $claim_uploads_dir );

eforms_test_assert( LocalPreviewProvider::delete_cache( $lease, $first['object_key'], $first['object_version'] ), 'Artifact cleanup should remove and fence the matching optional cache.' );
$after_delete = LocalPreviewProvider::render( $first, $uploads_dir, 1, $encoder, $admission );
eforms_test_assert( empty( $after_delete['ok'] ) && $after_delete['reason'] === 'artifact_deleted' && $encodes === 2, 'A retained cache fence should prevent late preview recreation after deletion starts.' );

$absent_cache_uploads = eforms_test_setup_uploads( 'eforms-local-preview-absent-cache' );
$absent_cache_lease = PrivateDir::acquire_write_lease( $absent_cache_uploads );
eforms_test_assert( $absent_cache_lease instanceof PrivateDirLease, 'The absent-cache deletion fixture should acquire its lifecycle lease.' );
$never_rendered = eforms_test_local_preview_artifact( $absent_cache_lease, $absent_cache_uploads, 'preview-batch-never-rendered', 'preview-never-rendered' );
eforms_test_assert(
    LocalPreviewProvider::delete_cache( $absent_cache_lease, $never_rendered['object_key'], $never_rendered['object_version'] ),
    'Artifact deletion should durably fence a preview identity even when the cache root did not exist.'
);
$encodes_before_absent_cache_retry = $encodes;
$after_absent_cache_delete = LocalPreviewProvider::render( $never_rendered, $absent_cache_uploads, 1, $encoder, $admission );
eforms_test_assert(
    empty( $after_absent_cache_delete['ok'] )
        && $after_absent_cache_delete['reason'] === 'artifact_deleted'
        && $encodes === $encodes_before_absent_cache_retry,
    'A first render starting after absent-cache deletion must observe the durable object fence and perform no conversion.'
);
$never_rendered_deleted = LocalArtifactStore::delete( $absent_cache_lease, $never_rendered['object_key'], $never_rendered['object_version'] );
eforms_test_assert( $never_rendered_deleted === true, 'The absent-cache fixture should remove the authoritative artifact after publishing its preview fence.' );
$absent_root = PrivateDir::leased_subdir( $absent_cache_lease, LocalPreviewProvider::ROOT_DIR, false, true );
$fence_paths = glob( $absent_root . '/*/*/' . LocalPreviewProvider::DELETED_FILENAME );
eforms_test_assert( is_array( $fence_paths ) && count( $fence_paths ) === 1, 'Absent-cache deletion should leave one bounded durable fence.' );
$fence_mtime = filemtime( $fence_paths[0] );
$early_fence_gc = LocalPreviewProvider::gc_deleted_fences(
    $absent_cache_lease,
    $fence_mtime + Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' ) - 1,
    1,
    false
);
eforms_test_assert(
    ! empty( $early_fence_gc['ok'] ) && $early_fence_gc['candidates'] === 0 && is_file( $fence_paths[0] ),
    'Normal GC must preserve a preview fence throughout the delayed-request grace.'
);
$expired_fence_gc = LocalPreviewProvider::gc_deleted_fences(
    $absent_cache_lease,
    $fence_mtime + Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' ),
    1,
    false
);
eforms_test_assert(
    ! empty( $expired_fence_gc['ok'] ) && $expired_fence_gc['candidates'] === 1 && $expired_fence_gc['deleted'] === 1 && ! file_exists( dirname( $fence_paths[0] ) ),
    'Normal GC should reclaim the complete preview fence directory at the safe request horizon.'
);
$absent_cache_lease->release();
eforms_test_remove_tree( $absent_cache_uploads );

if ( function_exists( 'pcntl_fork' ) && function_exists( 'pcntl_waitpid' ) ) {
    $overlap_uploads = eforms_test_setup_uploads( 'eforms-local-preview-overlap' );
    $overlap_lease = PrivateDir::acquire_write_lease( $overlap_uploads );
    eforms_test_assert( $overlap_lease instanceof PrivateDirLease, 'The overlap fixture should acquire its lifecycle lease.' );
    $same_artifact = eforms_test_local_preview_artifact( $overlap_lease, $overlap_uploads, 'preview-overlap-same', 'same' );
    $parallel_a = eforms_test_local_preview_artifact( $overlap_lease, $overlap_uploads, 'preview-overlap-a', 'parallel-a' );
    $parallel_b = eforms_test_local_preview_artifact( $overlap_lease, $overlap_uploads, 'preview-overlap-b', 'parallel-b' );
    $parallel_c = eforms_test_local_preview_artifact( $overlap_lease, $overlap_uploads, 'preview-overlap-c', 'parallel-c' );
    $overlap_lease->release();

    $same_entered = $overlap_uploads . '/same-entered';
    $same_release = $overlap_uploads . '/same-release';
    $same_first_result = $overlap_uploads . '/same-first.json';
    $same_second_result = $overlap_uploads . '/same-second.json';
    $same_encoder = eforms_test_blocking_preview_encoder( $same_entered, $same_release );
    $same_first_pid = pcntl_fork();
    eforms_test_assert( $same_first_pid >= 0, 'The same-object overlap fixture should fork its first renderer.' );
    if ( $same_first_pid === 0 ) {
        eforms_test_preview_child( $same_artifact, $overlap_uploads, 2, $same_encoder, $admission, $same_first_result );
    }
    eforms_test_wait_for_preview_entries( $same_entered, 1, 'The first same-object renderer should enter conversion.' );
    $same_second_pid = pcntl_fork();
    eforms_test_assert( $same_second_pid >= 0, 'The same-object overlap fixture should fork its competing renderer.' );
    if ( $same_second_pid === 0 ) {
        eforms_test_preview_child( $same_artifact, $overlap_uploads, 2, $same_encoder, $admission, $same_second_result );
    }
    eforms_test_wait_for_preview_child( $same_second_pid, 'The competing same-object renderer should return without waiting for conversion.' );
    file_put_contents( $same_release, 'release' );
    eforms_test_wait_for_preview_child( $same_first_pid, 'The first same-object renderer should finish after release.' );
    $same_first = eforms_test_preview_child_result( $same_first_result );
    $same_second = eforms_test_preview_child_result( $same_second_result );
    eforms_test_assert(
        ! empty( $same_first['ok'] )
            && empty( $same_second['ok'] )
            && ! empty( $same_second['transient'] )
            && count( file( $same_entered, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) === 1,
        'Concurrent requests for one immutable object should run exactly one conversion and return one bounded busy result.'
    );

    $parallel_entered = $overlap_uploads . '/parallel-entered';
    $parallel_release = $overlap_uploads . '/parallel-release';
    $parallel_a_result = $overlap_uploads . '/parallel-a.json';
    $parallel_b_result = $overlap_uploads . '/parallel-b.json';
    $parallel_encoder = eforms_test_blocking_preview_encoder( $parallel_entered, $parallel_release, $parallel_b['source_path'] );
    $parallel_a_pid = pcntl_fork();
    eforms_test_assert( $parallel_a_pid >= 0, 'The different-object overlap fixture should fork its first renderer.' );
    if ( $parallel_a_pid === 0 ) {
        eforms_test_preview_child( $parallel_a, $overlap_uploads, 2, $parallel_encoder, $admission, $parallel_a_result );
    }
    $parallel_b_pid = pcntl_fork();
    eforms_test_assert( $parallel_b_pid >= 0, 'The different-object overlap fixture should fork its second renderer.' );
    if ( $parallel_b_pid === 0 ) {
        eforms_test_preview_child( $parallel_b, $overlap_uploads, 2, $parallel_encoder, $admission, $parallel_b_result );
    }
    eforms_test_wait_for_preview_entries( $parallel_entered, 2, 'Two different objects should occupy the approved global conversion slots.' );
    $parallel_busy = LocalPreviewProvider::render( $parallel_c, $overlap_uploads, 2, $encoder, $admission );
    eforms_test_assert(
        empty( $parallel_busy['ok'] )
            && ! empty( $parallel_busy['transient'] )
            && count( file( $parallel_entered, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) === 2,
        'A third object should not start conversion while the two approved global slots are occupied.'
    );
    file_put_contents( $parallel_release, 'release' );
    eforms_test_wait_for_preview_child( $parallel_a_pid, 'The first different-object renderer should finish after release.' );
    eforms_test_wait_for_preview_child( $parallel_b_pid, 'The failing different-object renderer should finish after release.' );
    $parallel_a_outcome = eforms_test_preview_child_result( $parallel_a_result );
    $parallel_b_outcome = eforms_test_preview_child_result( $parallel_b_result );
    eforms_test_assert(
        ! empty( $parallel_a_outcome['ok'] )
            && empty( $parallel_b_outcome['ok'] )
            && $parallel_b_outcome['reason'] === 'preview_failed',
        'Different objects may convert concurrently, and one conversion failure should remain presentation-only.'
    );
    $after_failed_conversion = LocalPreviewProvider::render( $parallel_c, $overlap_uploads, 2, $encoder, $admission );
    eforms_test_assert( ! empty( $after_failed_conversion['ok'] ), 'A failed conversion must release its global slot so later preview work progresses.' );
    if ( isset( $after_failed_conversion['stream'] ) && is_resource( $after_failed_conversion['stream'] ) ) {
        fclose( $after_failed_conversion['stream'] );
    }
    eforms_test_remove_tree( $overlap_uploads );
}

if ( ! empty( LocalPreviewProvider::readiness( 1 )['ok'] ) ) {
    foreach ( array( array( 'real-png', 'staged-landscape.png', 'image/png' ), array( 'real-heic', 'staged-landscape.heic', 'image/heic' ) ) as $fixture ) {
        $artifact = eforms_test_local_preview_artifact( $lease, $uploads_dir, 'preview-' . $fixture[0], $fixture[0], $fixture[1], $fixture[2] );
        $real = LocalPreviewProvider::render( $artifact, $uploads_dir, 1, null, $admission );
        $start = ! empty( $real['ok'] ) && is_resource( $real['stream'] ) ? fread( $real['stream'], 2 ) : false;
        if ( isset( $real['stream'] ) && is_resource( $real['stream'] ) ) {
            fclose( $real['stream'] );
        }
        eforms_test_assert( ! empty( $real['ok'] ) && $start === "\xff\xd8", 'Available Imagick should execute the fixed lazy-preview recipe for ' . $fixture[2] . '.' );
    }
}

$lease->release();
eforms_test_remove_tree( $uploads_dir );
echo "Local preview provider tests passed.\n";

function eforms_test_local_preview_artifact( $lease, $uploads_dir, $batch_id, $upload_id, $fixture = 'staged-landscape.png', $mime = 'image/png' ) {
    $source = eforms_test_write_file( $uploads_dir, $upload_id . '.source', eforms_test_fixture_bytes( $fixture ) );
    $key = LocalArtifactStore::object_key( $batch_id, $upload_id );
    $stored = LocalArtifactStore::write( $lease, $key, $source, filesize( $source ) );
    eforms_test_assert( ! empty( $stored['ok'] ), 'The preview fixture should persist one authoritative local artifact.' );
    return array(
        'object_key' => $key,
        'object_version' => $stored['object_version'],
        'mime' => $mime,
        'bytes' => (int) $stored['bytes'],
        'source_path' => $stored['path'],
    );
}

function eforms_test_blocking_preview_encoder( $entered_path, $release_path, $failure_source = '' ) {
    return function ( $source, $mime, $destination ) use ( $entered_path, $release_path, $failure_source ) {
        file_put_contents( $entered_path, basename( $source ) . "\n", FILE_APPEND | LOCK_EX );
        $deadline = microtime( true ) + 5;
        while ( ! is_file( $release_path ) && microtime( true ) < $deadline ) {
            usleep( 10000 );
        }
        if ( ! is_file( $release_path ) || ( $failure_source !== '' && $source === $failure_source ) ) {
            return false;
        }
        return file_put_contents( $destination, "\xff\xd8\xff\xd9" ) === 4;
    };
}

function eforms_test_preview_child( $artifact, $uploads_dir, $concurrency, $encoder, $admission, $result_path ) {
    $result = LocalPreviewProvider::render( $artifact, $uploads_dir, $concurrency, $encoder, $admission );
    if ( isset( $result['stream'] ) && is_resource( $result['stream'] ) ) {
        fclose( $result['stream'] );
    }
    file_put_contents(
        $result_path,
        json_encode(
            array(
                'ok' => ! empty( $result['ok'] ),
                'transient' => ! empty( $result['transient'] ),
                'reason' => isset( $result['reason'] ) ? (string) $result['reason'] : '',
            )
        )
    );
    exit( 0 );
}

function eforms_test_wait_for_preview_entries( $path, $count, $message ) {
    $deadline = microtime( true ) + 5;
    do {
        $entries = is_file( $path ) ? file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
        if ( is_array( $entries ) && count( $entries ) >= $count ) {
            return;
        }
        usleep( 10000 );
    } while ( microtime( true ) < $deadline );
    eforms_test_assert( false, $message );
}

function eforms_test_wait_for_preview_child( $pid, $message ) {
    $deadline = microtime( true ) + 5;
    do {
        $waited = pcntl_waitpid( $pid, $status, WNOHANG );
        if ( $waited === $pid ) {
            eforms_test_assert( pcntl_wifexited( $status ) && pcntl_wexitstatus( $status ) === 0, $message );
            return;
        }
        usleep( 10000 );
    } while ( microtime( true ) < $deadline );
    if ( function_exists( 'posix_kill' ) ) {
        posix_kill( $pid, SIGTERM );
    }
    pcntl_waitpid( $pid, $status );
    eforms_test_assert( false, $message );
}

function eforms_test_preview_child_result( $path ) {
    $result = is_file( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
    eforms_test_assert( is_array( $result ), 'A preview overlap child should persist its bounded result.' );
    return $result;
}
