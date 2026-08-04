<?php
/**
 * Unit tests for shared upload value shape helpers.
 *
 * Contract: Uploads
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/UploadValue.php';

$minimal = array(
    'tmp_name' => '/tmp/php-upload',
    'original_name' => 'original.pdf',
    'size' => 123,
    'error' => UPLOAD_ERR_OK,
);

$normalized = $minimal;
$normalized['original_name_safe'] = 'safe.pdf';
$normalized['stored'] = array(
    'path' => '/tmp/stored-file.pdf',
    'bytes' => 456,
);

eforms_test_assert( UploadValue::is_item( $minimal ) === true, 'Minimal upload item shape should be recognized.' );
eforms_test_assert( UploadValue::is_normalized_item( $minimal ) === false, 'Minimal shape without safe name is not normalized.' );
eforms_test_assert( UploadValue::is_normalized_item( $normalized ) === true, 'Normalized upload item should require original_name_safe.' );

eforms_test_assert( UploadValue::items( $minimal ) === array( $minimal ), 'Single minimal item should normalize to one item.' );
eforms_test_assert( UploadValue::items( array( $minimal, 'bad', $normalized ) ) === array( $minimal, $normalized ), 'Item lists should filter non-items.' );
eforms_test_assert( UploadValue::items( array( $minimal, $normalized ), true ) === array( $normalized ), 'Safe-name-required lists should reject items missing original_name_safe.' );

$single = UploadValue::items_with_single( $minimal );
eforms_test_assert( $single['single'] === true && $single['items'] === array( $minimal ), 'items_with_single should preserve single-item shape.' );
$list = UploadValue::items_with_single( array( $minimal ) );
eforms_test_assert( $list['single'] === false && $list['items'] === array( $minimal ), 'items_with_single should preserve list shape.' );

$no_file = $minimal;
$no_file['error'] = UPLOAD_ERR_NO_FILE;
eforms_test_assert( UploadValue::is_no_file( $no_file ) === true, 'UPLOAD_ERR_NO_FILE should be treated as no file.' );
$empty_name = $minimal;
$empty_name['original_name'] = '';
eforms_test_assert( UploadValue::is_no_file( $empty_name ) === true, 'Empty original name should be treated as no file.' );

$ordinal_map = UploadValue::file_map_from_payload(
    array(
        'name' => array( 'photos' => array( '', 'second.pdf' ) ),
        'tmp_name' => array( 'photos' => array( '', '/tmp/second.pdf' ) ),
        'error' => array( 'photos' => array( UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK ) ),
        'size' => array( 'photos' => array( 0, 42 ) ),
    )
);
eforms_test_assert( $ordinal_map['photos'][0]['input_ordinal'] === 0 && $ordinal_map['photos'][1]['input_ordinal'] === 1, 'Upload payload mapping should retain original item positions before no-file filtering.' );
eforms_test_assert( UploadValue::input_ordinal( $ordinal_map['photos'][1], 0 ) === 1, 'The upload shape owner should expose a retained input ordinal.' );
eforms_test_assert( UploadValue::input_ordinal( $minimal, 4 ) === 4, 'Upload values without transport metadata should use their deterministic list-position fallback.' );

$single_payload = array(
    'file' => array(
        'name' => '../ Camera  Image.jpg',
        'tmp_name' => '/tmp/camera-image',
        'error' => UPLOAD_ERR_OK,
        'size' => 42,
        'type' => 'image/jpeg',
    ),
);
$single_item = UploadValue::file_item_from_payload( $single_payload, 'file', 128, true );
eforms_test_assert( UploadValue::is_normalized_item( $single_item ), 'Single-file payload mapping should produce the canonical normalized item shape.' );
eforms_test_assert( $single_item['original_name_safe'] === 'Camera Image.jpg' && $single_item['type'] === 'image/jpeg', 'Single-file payload mapping should reuse canonical filename and optional type handling.' );
eforms_test_assert( UploadValue::file_item_from_payload( $single_payload, 'missing' ) === null, 'Missing single-file parameters should fail closed.' );
$single_payload['file']['name'] = array( 'nested.jpg' );
eforms_test_assert( UploadValue::file_item_from_payload( $single_payload, 'file' ) === null, 'Nested single-file parameters should fail closed.' );
$long_name = str_repeat( "\u{754C}", Anchors::get( 'MANAGED_DISPLAY_NAME_MAX_CHARS' ) ) . '.png';
$safe_long_name = UploadValue::sanitize_display_name( $long_name );
eforms_test_assert( substr( $safe_long_name, -4 ) === '.png', 'Display-name truncation should preserve the validated extension.' );
eforms_test_assert( preg_match( '//u', $safe_long_name ) === 1, 'Display-name truncation should retain valid UTF-8.' );
$safe_long_chars = function_exists( 'mb_strlen' ) ? mb_strlen( $safe_long_name, 'UTF-8' ) : count( preg_split( '//u', $safe_long_name, -1, PREG_SPLIT_NO_EMPTY ) );
eforms_test_assert( $safe_long_chars === Anchors::get( 'MANAGED_DISPLAY_NAME_MAX_CHARS' ), 'Display-name truncation should honor the code-point bound.' );

eforms_test_assert( UploadValue::name_for_validation( $normalized ) === 'safe.pdf', 'Validation name should prefer non-empty safe name.' );
$empty_safe = $normalized;
$empty_safe['original_name_safe'] = '';
eforms_test_assert( UploadValue::name_for_validation( $empty_safe ) === 'original.pdf', 'Validation name should fall back from empty safe name.' );
eforms_test_assert( UploadValue::name_for_storage( $empty_safe ) === '', 'Storage name should preserve an explicitly empty safe name.' );

eforms_test_assert( UploadValue::display_name( $normalized ) === 'safe.pdf', 'Display name should prefer safe name.' );
eforms_test_assert( UploadValue::display_name( $empty_safe ) === 'original.pdf', 'Display name should fall back to original name when safe name is empty.' );
$no_names = $normalized;
$no_names['original_name_safe'] = '';
$no_names['original_name'] = '';
eforms_test_assert( UploadValue::display_name( $no_names, '/tmp/fallback.bin' ) === 'fallback.bin', 'Display name should fall back to stored basename.' );

eforms_test_assert( UploadValue::stored_path( $normalized ) === '/tmp/stored-file.pdf', 'Stored path should extract the stored string only.' );
eforms_test_assert( UploadValue::stored_bytes( $normalized ) === 456, 'Stored bytes should extract numeric stored bytes.' );
eforms_test_assert( UploadValue::stored_bytes( $minimal ) === null, 'Missing stored bytes should return null.' );
eforms_test_assert( UploadValue::temporary_path( $minimal ) === '/tmp/php-upload', 'Temporary path should extract only a string upload source.' );
eforms_test_assert( UploadValue::temporary_path( array( 'tmp_name' => array() ) ) === '', 'Malformed temporary paths should fail closed.' );

$staged = UploadValue::staged_item(
    array(
        'upload_id' => 'upload-1',
        'ordinal' => 2,
        'display_name' => '../ Camera   Image.jpg',
        'bytes' => 789,
        'mime' => 'image/jpeg',
        'width' => 1200,
        'height' => 800,
        'original_path' => '/private/original.jpg',
        'preview_path' => '/private/preview.jpg',
    )
);
eforms_test_assert( UploadValue::is_staged_item( $staged ) === true, 'A resolved staged upload should use the canonical public value shape.' );
eforms_test_assert( $staged['original_name_safe'] === 'Camera Image.jpg', 'Staged display names should use the shared sanitizer.' );
eforms_test_assert( ! isset( $staged['original_path'] ) && ! isset( $staged['preview_path'] ), 'Staged values must not expose private paths.' );
eforms_test_assert( UploadValue::staged_items( $staged ) === array( $staged ), 'A singular staged value should normalize to one item.' );
eforms_test_assert( UploadValue::staged_items( array( array( 'invalid' => true ), $staged ) ) === array( $staged ), 'Staged list shaping should retain only canonical staged items.' );
eforms_test_assert( UploadValue::staged_item( array( 'upload_id' => 'incomplete' ) ) === array(), 'Incomplete staged manifest items should not resolve.' );
$worker_review = UploadValue::review_staged_item(
    array(
        'upload_id' => 'worker-photo',
        'ordinal' => 1,
        'display_name' => 'Worker <Photo>.png',
        'bytes' => 1200,
        'object_key' => 'private-worker-key',
    )
);
eforms_test_assert( UploadValue::is_review_staged_item( $worker_review ) === true, 'Worker review references should use the canonical staged-review value shape.' );
eforms_test_assert( UploadValue::is_staged_item( $worker_review ) === false, 'Worker review references must not pretend to carry local media facts.' );
eforms_test_assert( $worker_review['original_name_safe'] === 'Worker <Photo>.png' && ! isset( $worker_review['object_key'] ), 'Worker review references should use the shared display-name sanitizer and omit provider locators.' );
eforms_test_assert( UploadValue::review_staged_items( array( array( 'invalid' => true ), $worker_review, $staged ) ) === array( $worker_review, $staged ), 'Review staged list shaping should retain Worker references and local staged items.' );
eforms_test_assert( UploadValue::review_staged_items( array( 'upload_id' => 'raw', 'ordinal' => 2, 'display_name' => 'raw.png', 'bytes' => 10 ) ) === array(), 'Review list shaping must not convert raw storage summaries at a consumer boundary.' );
