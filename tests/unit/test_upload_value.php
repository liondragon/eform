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
