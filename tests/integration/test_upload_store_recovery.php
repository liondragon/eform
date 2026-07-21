<?php
/**
 * Integration tests for idempotent synchronous-upload recovery.
 *
 * Contract: Uploads
 * Contract: Ledger reservation contract
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/UploadStore.php';

function eforms_test_finalize_recovery_submission( $uploads_dir, $submission_id, $suffix ) {
    $now = time();
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
        'raw_token' => 'recovery-token-' . $suffix,
        'form_id' => 'recovery-form',
        'instance_id' => 'recovery-instance-' . $suffix,
        'field_key' => 'photos',
        'accept_until' => $now + 3600,
    );
    $created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
    eforms_test_assert( ! empty( $created['ok'] ), 'The recovery fixture should create its managed batch.' );
    $batch_id = $created['batch']['batch_id'];
    $claimed = UploadBatchStore::claim_finalization( $batch_id, $secret, $binding, $field, array(), $submission_id, $uploads_dir, $now );
    eforms_test_assert( ! empty( $claimed['ok'] ), 'The recovery fixture should claim its managed batch.' );
    $finalized = UploadBatchStore::finalize( $batch_id, $submission_id, $uploads_dir, $now );
    eforms_test_assert( ! empty( $finalized['ok'] ), 'The recovery fixture should finalize its managed batch.' );
}

$uploads_dir = eforms_test_setup_uploads( 'eforms-upload-store-recovery' );
$submission_id = '123e4567-e89b-42d3-a456-426614174000';
$context = array(
    'descriptors' => array(
        array( 'key' => 'document', 'type' => 'file' ),
    ),
);
eforms_test_finalize_recovery_submission( $uploads_dir, $submission_id, 'exact' );

$first_source = eforms_test_write_file( $uploads_dir, 'first.pdf', "%PDF-1.4\nrecovery\n" );
$first = UploadStore::move_staged_after_ledger(
    $context,
    array(
        'document' => array(
            'tmp_name' => $first_source,
            'original_name' => 'Document.pdf',
            'size' => filesize( $first_source ),
            'error' => UPLOAD_ERR_OK,
        ),
    ),
    $submission_id,
    $uploads_dir
);
eforms_test_assert( ! empty( $first['ok'] ) && count( $first['stored'] ) === 1, 'The first synchronous commit should store one file.' );
$stored_path = $first['stored'][0]['path'];
eforms_test_assert(
    dirname( $stored_path ) === $uploads_dir . '/eforms-private/uploads/' . Helpers::h2( $submission_id ) . '/' . $submission_id,
    'Synchronous recovery should address only its stable per-submission directory.'
);
foreach ( array( PrivateDir::INDEX_FILENAME, PrivateDir::HTACCESS_FILENAME, PrivateDir::WEBCONFIG_FILENAME ) as $file ) {
    eforms_test_assert( is_file( $uploads_dir . '/eforms-private/' . UploadStore::UPLOADS_DIR . '/' . $file ), 'Synchronous upload storage should protect uploads root: ' . $file );
}

$retry_source = eforms_test_write_file( $uploads_dir, 'retry.pdf', "%PDF-1.4\nrecovery\n" );
$retry = UploadStore::move_staged_after_ledger(
    $context,
    array(
        'document' => array(
            'tmp_name' => $retry_source,
            'original_name' => 'Document.pdf',
            'size' => filesize( $retry_source ),
            'error' => UPLOAD_ERR_OK,
        ),
    ),
    $submission_id,
    $uploads_dir
);
eforms_test_assert( ! empty( $retry['ok'] ) && $retry['stored'][0]['path'] === $stored_path, 'An exact same-submission retry should reuse the committed synchronous file.' );
eforms_test_assert( ! is_file( $retry_source ), 'An exact retry should consume its redundant request temporary file.' );

if ( function_exists( 'symlink' ) ) {
    $linked_target = eforms_test_write_file( $uploads_dir, 'linked-existing.pdf', "%PDF-1.4\nrecovery\n" );
    eforms_test_assert( unlink( $stored_path ) && symlink( $linked_target, $stored_path ), 'The synchronous recovery symlink fixture should replace only the existing destination.' );
    $linked_source = eforms_test_write_file( $uploads_dir, 'linked-retry.pdf', "%PDF-1.4\nrecovery\n" );
    $linked_retry = UploadStore::move_staged_after_ledger(
        $context,
        array(
            'document' => array(
                'tmp_name' => $linked_source,
                'original_name' => 'Document.pdf',
                'size' => filesize( $linked_source ),
                'error' => UPLOAD_ERR_OK,
            ),
        ),
        $submission_id,
        $uploads_dir
    );
    eforms_test_assert( empty( $linked_retry['ok'] ) && $linked_retry['reason'] === 'upload_collision', 'Synchronous recovery should reject symlinked existing destinations.' );
    @unlink( $stored_path );
    file_put_contents( $stored_path, "%PDF-1.4\nrecovery\n" );
    @unlink( $linked_source );
}

if ( function_exists( 'symlink' ) ) {
    $linked_uploads = eforms_test_setup_uploads( 'eforms-upload-store-recovery-linked' );
    $outside_dir = eforms_test_tmp_root( 'eforms-upload-store-recovery-outside' );
    mkdir( $outside_dir, 0700, true );
    $linked_submission = '123e4567-e89b-42d3-a456-426614174010';
    eforms_test_finalize_recovery_submission( $linked_uploads, $linked_submission, 'linked-dir' );
    $linked_private = $linked_uploads . '/eforms-private';
    $linked_upload_root = $linked_private . '/' . UploadStore::UPLOADS_DIR;
    mkdir( $linked_upload_root, 0700, true );
    symlink( $outside_dir, $linked_upload_root . '/' . Helpers::h2( $linked_submission ) );

    $linked_source = eforms_test_write_file( $linked_uploads, 'linked-dir.pdf', "%PDF-1.4\nrecovery\n" );
    $linked_move = UploadStore::move_staged_after_ledger(
        $context,
        array(
            'document' => array(
                'tmp_name' => $linked_source,
                'original_name' => 'Document.pdf',
                'size' => filesize( $linked_source ),
                'error' => UPLOAD_ERR_OK,
            ),
        ),
        $linked_submission,
        $linked_uploads
    );
    eforms_test_assert( empty( $linked_move['ok'] ) && $linked_move['reason'] === 'uploads_store_unavailable', 'Synchronous recovery should reject symlinked submission upload dirs.' );
    eforms_test_assert( count( scandir( $outside_dir ) ) === 2, 'Synchronous recovery should not materialize uploads through a symlinked shard.' );

    $outside_submission = $outside_dir . '/' . $linked_submission;
    mkdir( $outside_submission, 0700, true );
    $outside_recovery = $outside_submission . '/' . $linked_submission . '-1-0-0123456789abcdef.pdf';
    file_put_contents( $outside_recovery, 'outside-recovery' );
    $linked_empty = UploadStore::move_staged_after_ledger(
        $context,
        array( 'document' => null ),
        $linked_submission,
        $linked_uploads
    );
    eforms_test_assert( empty( $linked_empty['ok'] ) && $linked_empty['reason'] === 'upload_cleanup_failed', 'An empty corrected retry should reject a symlinked recovery shard.' );
    eforms_test_assert( is_file( $outside_recovery ) && file_get_contents( $outside_recovery ) === 'outside-recovery', 'Empty retry cleanup must not unlink a matching file outside managed storage.' );

    eforms_test_remove_tree( $linked_uploads );
    eforms_test_remove_tree( $outside_dir );
}

$changed_source = eforms_test_write_file( $uploads_dir, 'changed.pdf', "%PDF-1.4\nchanged\n" );
$changed = UploadStore::move_staged_after_ledger(
    $context,
    array(
        'document' => array(
            'tmp_name' => $changed_source,
            'original_name' => 'Document.pdf',
            'size' => filesize( $changed_source ),
            'error' => UPLOAD_ERR_OK,
        ),
    ),
    $submission_id,
    $uploads_dir
);
eforms_test_assert( empty( $changed['ok'] ) && $changed['reason'] === 'upload_collision', 'A same-submission retry with different bytes should fail closed.' );
eforms_test_assert( is_file( $stored_path ), 'A conflicting retry must preserve the original committed file.' );
$attempted = UploadBatchStore::mark_email_attempted( $submission_id, $uploads_dir );
eforms_test_assert( ! empty( $attempted['ok'] ), 'The recovery fixture should persist its terminal email-attempt marker.' );
$late_source = eforms_test_write_file( $uploads_dir, 'late.pdf', "%PDF-1.4\nrecovery\n" );
$late = UploadStore::move_staged_after_ledger(
    $context,
    array(
        'document' => array(
            'tmp_name' => $late_source,
            'original_name' => 'Document.pdf',
            'size' => filesize( $late_source ),
            'error' => UPLOAD_ERR_OK,
        ),
    ),
    $submission_id,
    $uploads_dir
);
eforms_test_assert( empty( $late['ok'] ) && $late['reason'] === 'synchronous_commit_denied', 'The canonical lock owner should reject every synchronous commit after the durable attempt marker.' );
eforms_test_assert( is_file( $late_source ) && is_file( $stored_path ), 'A denied terminal replay must not consume its source or mutate the committed destination.' );

if ( function_exists( 'pcntl_fork' ) && function_exists( 'pcntl_waitpid' ) ) {
    $lock_submission = '123e4567-e89b-42d3-a456-426614174003';
    eforms_test_finalize_recovery_submission( $uploads_dir, $lock_submission, 'serialization' );
    $signal_root = eforms_test_tmp_root( 'eforms-recovery-lock-signals' );
    mkdir( $signal_root, 0700, true );
    $ready_path = $signal_root . '/ready';
    $go_path = $signal_root . '/go';
    $acquired_path = $signal_root . '/acquired';
    $result_path = $signal_root . '/result.json';
    $pid = pcntl_fork();
    eforms_test_assert( $pid >= 0, 'The recovery-lock proof should fork one waiter.' );
    if ( $pid === 0 ) {
        file_put_contents( $ready_path, 'ready' );
        $deadline = microtime( true ) + 5;
        while ( ! is_file( $go_path ) && microtime( true ) < $deadline ) {
            usleep( 10000 );
        }
        $child_result = UploadBatchStore::run_synchronous_commit(
            $lock_submission,
            $uploads_dir,
            function () use ( $acquired_path ) {
                file_put_contents( $acquired_path, 'acquired' );
                return array( 'ok' => true );
            }
        );
        file_put_contents( $result_path, json_encode( $child_result ) );
        exit( 0 );
    }

    $deadline = microtime( true ) + 5;
    while ( ! is_file( $ready_path ) && microtime( true ) < $deadline ) {
        usleep( 10000 );
    }
    eforms_test_assert( is_file( $ready_path ), 'The recovery-lock waiter should become ready.' );
    $submission_path = $uploads_dir . '/eforms-private/submissions/' . Helpers::h2( $lock_submission ) . '/' . $lock_submission;
    $guard = fopen( $submission_path . '/' . UploadBatchStore::LOCK_FILENAME, 'c+b' );
    eforms_test_assert( is_resource( $guard ) && flock( $guard, LOCK_EX ), 'The recovery-lock proof should hold the canonical submission lock.' );
    file_put_contents( $go_path, 'go' );
    usleep( 200000 );
    eforms_test_assert( ! is_file( $acquired_path ), 'A concurrent recovery must wait before entering synchronous destination handling.' );
    flock( $guard, LOCK_UN );
    fclose( $guard );
    pcntl_waitpid( $pid, $status );
    $child_result = is_file( $result_path ) ? json_decode( file_get_contents( $result_path ), true ) : null;
    eforms_test_assert( is_file( $acquired_path ) && is_array( $child_result ) && ! empty( $child_result['ok'] ), 'The waiting recovery should proceed after the canonical submission lock is released.' );
    eforms_test_remove_tree( $signal_root );
}

$field_slot_submission = '123e4567-e89b-42d3-a456-426614174001';
$field_slot_context = array(
    'descriptors' => array(
        array( 'key' => 'optional', 'type' => 'file' ),
        array( 'key' => 'document', 'type' => 'file' ),
    ),
);
eforms_test_finalize_recovery_submission( $uploads_dir, $field_slot_submission, 'field-slot' );
$optional_source = eforms_test_write_file( $uploads_dir, 'optional.pdf', "%PDF-1.4\noptional\n" );
$stable_source = eforms_test_write_file( $uploads_dir, 'stable.pdf', "%PDF-1.4\nstable\n" );
$field_slot_first = UploadStore::move_staged_after_ledger(
    $field_slot_context,
    array(
        'optional' => array( 'tmp_name' => $optional_source, 'original_name' => 'Optional.pdf', 'size' => filesize( $optional_source ), 'error' => UPLOAD_ERR_OK ),
        'document' => array( 'tmp_name' => $stable_source, 'original_name' => 'Stable.pdf', 'size' => filesize( $stable_source ), 'error' => UPLOAD_ERR_OK ),
    ),
    $field_slot_submission,
    $uploads_dir
);
eforms_test_assert( ! empty( $field_slot_first['ok'] ) && count( $field_slot_first['stored'] ) === 2, 'The stable-field fixture should store both optional uploads.' );
$stable_field_path = $field_slot_first['stored'][1]['path'];
$stable_retry_source = eforms_test_write_file( $uploads_dir, 'stable-retry.pdf', "%PDF-1.4\nstable\n" );
$field_slot_retry = UploadStore::move_staged_after_ledger(
    $field_slot_context,
    array(
        'optional' => null,
        'document' => array( 'tmp_name' => $stable_retry_source, 'original_name' => 'Stable.pdf', 'size' => filesize( $stable_retry_source ), 'error' => UPLOAD_ERR_OK ),
    ),
    $field_slot_submission,
    $uploads_dir
);
eforms_test_assert( ! empty( $field_slot_retry['ok'] ) && $field_slot_retry['stored'][0]['path'] === $stable_field_path, 'Omitting an earlier optional field should not change the later field recovery slot.' );

$item_slot_submission = '123e4567-e89b-42d3-a456-426614174002';
$item_slot_context = array( 'descriptors' => array( array( 'key' => 'documents', 'type' => 'files' ) ) );
eforms_test_finalize_recovery_submission( $uploads_dir, $item_slot_submission, 'item-slot' );
$item_zero_source = eforms_test_write_file( $uploads_dir, 'item-zero.pdf', "%PDF-1.4\nitem-zero\n" );
$item_one_source = eforms_test_write_file( $uploads_dir, 'item-one.pdf', "%PDF-1.4\nitem-one\n" );
$item_slot_first = UploadStore::move_staged_after_ledger(
    $item_slot_context,
    array(
        'documents' => array(
            array( 'tmp_name' => $item_zero_source, 'original_name' => 'Zero.pdf', 'size' => filesize( $item_zero_source ), 'error' => UPLOAD_ERR_OK, 'input_ordinal' => 0 ),
            array( 'tmp_name' => $item_one_source, 'original_name' => 'One.pdf', 'size' => filesize( $item_one_source ), 'error' => UPLOAD_ERR_OK, 'input_ordinal' => 1 ),
        ),
    ),
    $item_slot_submission,
    $uploads_dir
);
eforms_test_assert( ! empty( $item_slot_first['ok'] ) && count( $item_slot_first['stored'] ) === 2, 'The stable-item fixture should store both input positions.' );
$stable_item_path = $item_slot_first['stored'][1]['path'];
$item_one_retry_source = eforms_test_write_file( $uploads_dir, 'item-one-retry.pdf', "%PDF-1.4\nitem-one\n" );
$item_slot_retry = UploadStore::move_staged_after_ledger(
    $item_slot_context,
    array(
        'documents' => array(
            array( 'tmp_name' => $item_one_retry_source, 'original_name' => 'One.pdf', 'size' => filesize( $item_one_retry_source ), 'error' => UPLOAD_ERR_OK, 'input_ordinal' => 1 ),
        ),
    ),
    $item_slot_submission,
    $uploads_dir
);
eforms_test_assert( ! empty( $item_slot_retry['ok'] ) && $item_slot_retry['stored'][0]['path'] === $stable_item_path, 'Filtering an earlier empty item should not change a retained item recovery slot.' );

$partial_submission = '123e4567-e89b-42d3-a456-426614174004';
$partial_context = array( 'descriptors' => array( array( 'key' => 'documents', 'type' => 'files' ) ) );
eforms_test_finalize_recovery_submission( $uploads_dir, $partial_submission, 'partial' );
$partial_first_source = eforms_test_write_file( $uploads_dir, 'partial-first.pdf', "%PDF-1.4\npartial-first\n" );
$partial_missing_source = $uploads_dir . '/partial-missing.pdf';
$partial_failure = UploadStore::move_staged_after_ledger(
    $partial_context,
    array(
        'documents' => array(
            array( 'tmp_name' => $partial_first_source, 'original_name' => 'First.pdf', 'size' => filesize( $partial_first_source ), 'error' => UPLOAD_ERR_OK, 'input_ordinal' => 0 ),
            array( 'tmp_name' => $partial_missing_source, 'original_name' => 'Missing.pdf', 'size' => 10, 'error' => UPLOAD_ERR_OK, 'input_ordinal' => 1 ),
        ),
    ),
    $partial_submission,
    $uploads_dir
);
$partial_dir = $uploads_dir . '/eforms-private/uploads/' . Helpers::h2( $partial_submission ) . '/' . $partial_submission;
$partial_files = glob( $partial_dir . '/' . $partial_submission . '-1-0-*' );
eforms_test_assert( empty( $partial_failure['ok'] ) && $partial_failure['reason'] === 'upload_tmp_missing', 'A later missing recovery item should fail the current synchronous commit.' );
eforms_test_assert( is_array( $partial_files ) && count( $partial_files ) === 1 && is_file( $partial_files[0] ), 'A later recovery failure should preserve an earlier safely finalized exact destination.' );
$partial_preserved_path = $partial_files[0];
$partial_retry_first = eforms_test_write_file( $uploads_dir, 'partial-first-retry.pdf', "%PDF-1.4\npartial-first\n" );
$partial_retry_second = eforms_test_write_file( $uploads_dir, 'partial-second-retry.pdf', "%PDF-1.4\npartial-second\n" );
$partial_retry = UploadStore::move_staged_after_ledger(
    $partial_context,
    array(
        'documents' => array(
            array( 'tmp_name' => $partial_retry_first, 'original_name' => 'First.pdf', 'size' => filesize( $partial_retry_first ), 'error' => UPLOAD_ERR_OK, 'input_ordinal' => 0 ),
            array( 'tmp_name' => $partial_retry_second, 'original_name' => 'Second.pdf', 'size' => filesize( $partial_retry_second ), 'error' => UPLOAD_ERR_OK, 'input_ordinal' => 1 ),
        ),
    ),
    $partial_submission,
    $uploads_dir
);
eforms_test_assert( ! empty( $partial_retry['ok'] ) && count( $partial_retry['stored'] ) === 2, 'A retry should complete the partially finalized synchronous set.' );
eforms_test_assert( $partial_retry['stored'][0]['path'] === $partial_preserved_path && ! is_file( $partial_retry_first ), 'The retry should reuse the preserved exact destination and consume its redundant source.' );
$partial_second_path = $partial_retry['stored'][1]['path'];
$partial_omitted_retry_source = eforms_test_write_file( $uploads_dir, 'partial-omitted-retry.pdf', "%PDF-1.4\npartial-second\n" );
$partial_omitted_retry = UploadStore::move_staged_after_ledger(
    $partial_context,
    array(
        'documents' => array(
            array( 'tmp_name' => $partial_omitted_retry_source, 'original_name' => 'Second.pdf', 'size' => filesize( $partial_omitted_retry_source ), 'error' => UPLOAD_ERR_OK, 'input_ordinal' => 1 ),
        ),
    ),
    $partial_submission,
    $uploads_dir
);
eforms_test_assert( ! empty( $partial_omitted_retry['ok'] ) && $partial_omitted_retry['stored'][0]['path'] === $partial_second_path, 'A successful retry should reuse the retained item that is still present.' );
eforms_test_assert( ! is_file( $partial_preserved_path ), 'A successful retry should delete a previously preserved recovery file that the corrected request omits.' );

eforms_test_remove_tree( $uploads_dir );
echo "All upload store recovery tests passed.\n";
