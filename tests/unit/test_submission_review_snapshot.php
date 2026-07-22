<?php
/**
 * Tests for managed-photo operator review snapshots.
 *
 * Contract: Operator review snapshot
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Rendering/TemplateContext.php';
require_once __DIR__ . '/../../src/Submission/SubmissionReviewSnapshot.php';

$template = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/templates/forms/virtual-estimate.json' ), true );
$context_result = TemplateContext::build( $template, '1.0-test' );
eforms_test_assert( ! empty( $context_result['ok'] ), 'Virtual-estimate template context should build for snapshot tests.' );
$context = $context_result['context'];

$values = array(
    'name' => 'Ada Lovelace',
    'email' => 'ada@example.test',
    'tel_us' => '7209005278',
    'zip_us' => '80231',
    'square_footage' => '1145',
    'listing_url' => 'https://example.test/listing',
    'project_description' => 'Refinish the main floor.',
    'project_photos' => array(
        array(
            'staged' => true,
            'upload_id' => 'photo_one',
            'ordinal' => 0,
            'original_name_safe' => 'Room.png',
            'size' => 1234,
            'mime' => 'image/png',
            'width' => 640,
            'height' => 480,
        ),
    ),
);

$built = SubmissionReviewSnapshot::build( $context, $values, 'submission-1', '2026-07-26T12:34:56+00:00' );
eforms_test_assert( ! empty( $built['ok'] ), 'Snapshot builder should accept virtual-estimate coerced values.' );
$snapshot = $built['snapshot'];

eforms_test_assert(
    array_keys( $snapshot ) === array( 'schema_version', 'form_id', 'template_version', 'submission_id', 'submitted_at', 'title', 'header', 'operator_rows' ),
    'Snapshot should store only the v1 review fields.'
);
eforms_test_assert( $snapshot['schema_version'] === 1, 'Snapshot should use schema version 1.' );
eforms_test_assert( $snapshot['form_id'] === 'virtual-estimate', 'Snapshot should retain form identity.' );
eforms_test_assert( $snapshot['template_version'] === '1.0-test', 'Snapshot should retain template version.' );
eforms_test_assert( $snapshot['submission_id'] === 'submission-1', 'Snapshot should retain submission identity.' );
eforms_test_assert( $snapshot['submitted_at'] === '2026-07-26T12:34:56+00:00', 'Snapshot should use the supplied submission timestamp.' );
eforms_test_assert( $snapshot['title'] === 'Virtual Estimate Request', 'Snapshot should use the operator review title.' );

eforms_test_assert(
    array_column( $snapshot['header'], 'key' ) === array( 'name', 'zip_us' )
        && $snapshot['header'][0]['value'] === 'Ada Lovelace'
        && $snapshot['header'][1]['value'] === '80231',
    'Snapshot header should contain name and ZIP display rows.'
);
eforms_test_assert(
    array_column( $snapshot['operator_rows'], 'key' ) === array( 'email', 'tel_us', 'project_description', 'square_footage', 'listing_url' ),
    'Snapshot operator rows should contain the approved virtual-estimate fields in order.'
);
eforms_test_assert( $snapshot['operator_rows'][1]['value'] === '720-900-5278', 'Snapshot should reuse descriptor-aware phone formatting.' );
eforms_test_assert( $snapshot['operator_rows'][4]['type'] === 'url', 'Snapshot should mark listing URL rows.' );

$json = json_encode( $snapshot );
foreach ( array( 'project_photos', 'photo_one', 'Room.png', 'eforms_upload_batches', 'batch_secret', 'object_key', 'signature', 'provider_url' ) as $forbidden ) {
    eforms_test_assert( strpos( $json, $forbidden ) === false, 'Snapshot should not contain forbidden field or storage facts: ' . $forbidden );
}

$without_listing = $values;
$without_listing['listing_url'] = '';
$without_listing_result = SubmissionReviewSnapshot::build( $context, $without_listing, 'submission-2', '2026-07-26T12:35:56+00:00' );
eforms_test_assert( ! empty( $without_listing_result['ok'] ), 'Snapshot should build when optional listing URL is absent.' );
eforms_test_assert(
    array_column( $without_listing_result['snapshot']['operator_rows'], 'key' ) === array( 'email', 'tel_us', 'project_description', 'square_footage' ),
    'Snapshot should omit absent optional listing URL rows.'
);

$wide_text_values = $values;
$wide_text_values['project_description'] = str_repeat( "\xF0\x9F\x98\x80", 2000 );
$wide_text_result = SubmissionReviewSnapshot::build( $context, $wide_text_values, 'submission-wide', '2026-07-26T12:36:56+00:00' );
eforms_test_assert( ! empty( $wide_text_result['ok'] ), 'Snapshot should build from accepted multibyte text values.' );
$wide_text_snapshot = $wide_text_result['snapshot'];
eforms_test_assert( ! empty( SubmissionReviewSnapshot::validate( $wide_text_snapshot )['ok'] ), 'Snapshot builder should bound multibyte rows before sidecar validation.' );
eforms_test_assert(
    strlen( $wide_text_snapshot['operator_rows'][2]['value'] ) <= Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_VALUE_MAX_BYTES' )
        && preg_match( '//u', $wide_text_snapshot['operator_rows'][2]['value'] ) === 1,
    'Snapshot builder should cap sidecar row values without splitting UTF-8.'
);

$validated = SubmissionReviewSnapshot::validate( $snapshot );
eforms_test_assert( ! empty( $validated['ok'] ), 'Snapshot validator should accept the builder output.' );

$long_values = $values;
$long_values['square_footage'] = str_repeat( '1', Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_VALUE_MAX_BYTES' ) + 100 );
$long_values['listing_url'] = 'https://example.test/' . str_repeat( 'a', Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_VALUE_MAX_BYTES' ) + 100 );
$long_built = SubmissionReviewSnapshot::build( $context, $long_values, 'submission-long', '2026-07-26T12:36:56+00:00' );
eforms_test_assert( ! empty( $long_built['ok'] ), 'Snapshot builder should bound oversized display values before storage validation.' );
$long_validated = SubmissionReviewSnapshot::validate( $long_built['snapshot'] );
eforms_test_assert( ! empty( $long_validated['ok'] ), 'Snapshot validator should accept builder-bounded oversized display values.' );
foreach ( $long_built['snapshot']['operator_rows'] as $row ) {
    eforms_test_assert( strlen( $row['value'] ) <= Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_VALUE_MAX_BYTES' ), 'Builder-bounded row values should stay within the sidecar value limit.' );
}
$unbounded_rows = SubmissionReviewSnapshot::display_rows(
    $context,
    $long_values,
    array( 'email', 'tel_us', 'listing_url' ),
    array(),
    array(),
    true
);
eforms_test_assert(
    isset( $unbounded_rows[2]['value'] )
        && strlen( $unbounded_rows[2]['value'] ) > Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_VALUE_MAX_BYTES' )
        && array_column( $unbounded_rows, 'type' ) === array( 'email', 'text', 'text' ),
    'Shared display rows should preserve email row typing without applying review sidecar bounds to email rendering.'
);

$public_summary = SubmissionReviewSnapshot::public_summary( $snapshot );
eforms_test_assert(
    ! empty( $public_summary['ok'] )
        && array_keys( $public_summary['summary'] ) === array( 'details' )
        && array_column( $public_summary['summary']['details'], 'key' ) === array( 'project_description', 'square_footage' )
        && strpos( json_encode( $public_summary['summary'] ), 'Ada Lovelace' ) === false
        && strpos( json_encode( $public_summary['summary'] ), 'ada@example.test' ) === false
        && strpos( json_encode( $public_summary['summary'] ), 'listing' ) === false,
    'Snapshot public summary should expose only approved non-contact project rows.'
);
$operator_review = SubmissionReviewSnapshot::operator_review( $snapshot );
eforms_test_assert(
    ! empty( $operator_review['ok'] )
        && array_keys( $operator_review['review'] ) === array( 'title', 'header', 'details' )
        && $operator_review['review']['title'] === 'Virtual Estimate Request'
        && $operator_review['review']['header'] === $snapshot['header']
        && $operator_review['review']['details'] === $snapshot['operator_rows'],
    'Snapshot operator review projection should expose the approved operator page rows from the snapshot owner.'
);
$non_public_form = $snapshot;
$non_public_form['form_id'] = 'custom-photo-form';
$non_public_summary = SubmissionReviewSnapshot::public_summary( $non_public_form );
eforms_test_assert(
    ! empty( $non_public_summary['ok'] )
        && $non_public_summary['summary']['details'] === array(),
    'Snapshot public summary should not expose same-named rows for forms without anonymous approval.'
);
$with_unknown = $snapshot;
$with_unknown['crm_delivery_id'] = 'crm-1';
eforms_test_assert( empty( SubmissionReviewSnapshot::validate( $with_unknown )['ok'] ), 'Snapshot validator should reject CRM or unknown sidecar fields.' );

$with_unknown_row = $snapshot;
$with_unknown_row['operator_rows'][] = array(
    'key' => 'secret_token',
    'label' => 'Secret',
    'value' => 'do-not-render',
    'type' => 'text',
);
eforms_test_assert( empty( SubmissionReviewSnapshot::validate( $with_unknown_row )['ok'] ), 'Snapshot validator should reject arbitrary unmapped row keys.' );

$with_gallery_metadata = $snapshot;
$with_gallery_metadata['operator_rows'][0]['url'] = 'https://example.test/private';
eforms_test_assert( empty( SubmissionReviewSnapshot::validate( $with_gallery_metadata )['ok'] ), 'Snapshot validator should reject email or gallery-only row metadata.' );

$with_wrong_type = $snapshot;
$with_wrong_type['operator_rows'][1]['type'] = 'text';
eforms_test_assert( empty( SubmissionReviewSnapshot::validate( $with_wrong_type )['ok'] ), 'Snapshot validator should reject row types that do not match the approved sidecar key.' );

$with_duplicate_key = $snapshot;
$with_duplicate_key['header'][] = array(
    'key' => 'name',
    'label' => 'Name',
    'value' => 'Mallory',
    'type' => 'text',
);
eforms_test_assert( empty( SubmissionReviewSnapshot::validate( $with_duplicate_key )['ok'] ), 'Snapshot validator should reject duplicate sidecar row keys.' );

$with_long_label = $snapshot;
$with_long_label['operator_rows'][0]['label'] = str_repeat( 'L', Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_LABEL_MAX_BYTES' ) + 1 );
eforms_test_assert( empty( SubmissionReviewSnapshot::validate( $with_long_label )['ok'] ), 'Snapshot validator should reject overlarge row labels.' );

$with_long_value = $snapshot;
$with_long_value['operator_rows'][2]['value'] = str_repeat( 'V', Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_VALUE_MAX_BYTES' ) + 1 );
eforms_test_assert( empty( SubmissionReviewSnapshot::validate( $with_long_value )['ok'] ), 'Snapshot validator should reject overlarge row values.' );
