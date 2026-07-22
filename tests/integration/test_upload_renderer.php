<?php
/**
 * Integration tests for upload field rendering.
 *
 * Contract: Field descriptors and namespacing
 * Contract: Field types
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Errors.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';
require_once __DIR__ . '/../../src/Rendering/FieldRenderers/Upload.php';
require_once __DIR__ . '/../../src/Validation/FieldTypes/Upload.php';

function eforms_upload_test_security() {
    return array(
        'mode' => 'hidden',
        'token' => 'tok',
        'instance_id' => 'inst',
        'timestamp' => '123',
    );
}

function eforms_upload_test_batch_input_name( $field_key, $child_key ) {
    return FormProtocol::FIELD_UPLOAD_BATCHES . '[' . $field_key . '][' . $child_key . ']';
}

Config::reset_for_tests();
FormRenderer::reset_for_tests();

$upload_endpoint = new ReflectionMethod( 'FormRenderer', 'upload_batch_endpoint_url' );
$upload_endpoint->setAccessible( true );
eforms_test_assert( $upload_endpoint->invoke( null ) === '', 'Renderer should not emit an unowned staged upload fallback when rest_url() is unavailable.' );

// Given the shipped upload template...
// When the renderer runs...
// Then upload controls render instead of a schema error.
$html = FormRenderer::render(
    'upload-test',
    array(
        'security' => eforms_upload_test_security(),
    )
);

eforms_test_assert( strpos( $html, 'data-eforms-error="EFORMS_ERR_SCHEMA_OBJECT"' ) === false, 'Upload template should not render a schema error.' );
eforms_test_assert( strpos( $html, 'enctype="multipart/form-data"' ) !== false, 'Upload forms should use multipart encoding.' );
eforms_test_assert( strpos( $html, 'type="file"' ) !== false, 'Upload field should render a file input.' );
eforms_test_assert( strpos( $html, 'name="upload-test[file1]"' ) !== false, 'Upload field should use the form-scoped name.' );
eforms_test_assert( strpos( $html, 'id="upload-test-file1"' ) !== false, 'Upload field should use the deterministic form-scoped id.' );
eforms_test_assert( strpos( $html, 'accept="application/pdf,.pdf"' ) !== false, 'Upload field should render the PDF accept hint.' );
eforms_test_assert( strpos( $html, 'name="file1"' ) === false, 'Upload field should not leave the local field name active.' );
eforms_test_assert( strpos( $html, 'id="upload-test-photos"' ) !== false, 'Public renderer should retain staged picker label targeting.' );
eforms_test_assert( strpos( $html, FormProtocol::DATA_UPLOAD_FIELD . '="photos"' ) !== false, 'Public renderer should emit the staged mount.' );
eforms_test_assert( strpos( $html, 'name="upload-test[photos][]"' ) === false, 'Fresh public render should not name the staged picker.' );
eforms_test_assert( strpos( $html, FormProtocol::FIELD_UPLOAD_BATCHES . '[' ) === false, 'Fresh public render should contain no batch credentials.' );

FormRenderer::reset_for_tests();
$html = FormRenderer::render(
    'upload-test',
    array(
        'security' => eforms_upload_test_security(),
        'validated_upload_batches' => array(
            'photos' => array(
                FormProtocol::UPLOAD_BATCH_ID => 'batch-id',
                FormProtocol::UPLOAD_BATCH_SECRET => 'batch-secret',
            ),
        ),
    )
);
eforms_test_assert( strpos( $html, 'name="' . eforms_upload_test_batch_input_name( 'photos', FormProtocol::UPLOAD_BATCH_ID ) . '"' ) !== false, 'Validated public rerender should emit the staged batch ID.' );
eforms_test_assert( strpos( $html, 'name="' . eforms_upload_test_batch_input_name( 'photos', FormProtocol::UPLOAD_BATCH_SECRET ) . '"' ) !== false, 'Validated public rerender should emit the staged batch secret.' );

// Given upload field errors...
// Then FormRenderer attaches the existing accessibility attributes to the file input.
FormRenderer::reset_for_tests();
$errors = new Errors();
$errors->add_field( 'file1', 'EFORMS_ERR_UPLOAD_TYPE', 'This file type isn\'t allowed.' );
$html = FormRenderer::render(
    'upload-test',
    array(
        'security' => eforms_upload_test_security(),
        'errors' => $errors,
    )
);
eforms_test_assert( strpos( $html, 'aria-invalid="true"' ) !== false, 'Upload errors should mark the file input invalid.' );
eforms_test_assert( strpos( $html, 'aria-describedby="error-upload-test-file1"' ) !== false, 'Upload errors should describe the file input.' );
eforms_test_assert( strpos( $html, 'id="error-upload-test-file1"' ) !== false, 'Upload errors should render an error message target.' );

// Given a multi-file descriptor...
// Then the canonical FormRenderer control path applies multiple and [] naming.
$descriptor = FieldTypes_Upload::descriptor( 'files' );
$descriptor['id_prefix'] = 'demo';
$descriptor['handlers'] = array(
    'r' => array( 'FieldRenderers_Upload', 'render' ),
);
$field = array(
    'key' => 'attachments',
    'type' => 'files',
    'label' => 'Attachments',
    'required' => true,
    'accept' => array( 'image' ),
);

$method = new ReflectionMethod( 'FormRenderer', 'render_control' );
$method->setAccessible( true );
$control = $method->invoke( null, $descriptor, $field, 'demo', 'demo-attachments', false, null, array() );

eforms_test_assert( strpos( $control, 'type="file"' ) !== false, 'Files field should render a file input.' );
eforms_test_assert( strpos( $control, 'multiple="multiple"' ) !== false, 'Files field should render multiple.' );
eforms_test_assert( strpos( $control, 'required="required"' ) !== false, 'Files field should preserve required hint.' );
eforms_test_assert( strpos( $control, 'name="demo[attachments][]"' ) !== false, 'Files field should use form-scoped array naming.' );
eforms_test_assert( strpos( $control, 'id="demo-attachments"' ) !== false, 'Files field should use the deterministic id.' );
eforms_test_assert( strpos( $control, 'image/jpeg' ) !== false, 'Files field should include image MIME hints.' );
eforms_test_assert( strpos( $control, '.png' ) !== false, 'Files field should include image extension hints.' );

// Given a staged files field...
// Then the same renderer emits an unnamed disabled picker plus one managed mount.
$staged = $field;
$staged['key'] = 'project_photos';
$staged['upload_mode'] = 'staged';
$staged['max_file_bytes'] = 20971520;
$staged['max_files'] = 24;
$staged['max_total_bytes'] = 314572800;
$control = $method->invoke( null, $descriptor, $staged, 'demo', 'demo-project_photos', false, null, array() );

eforms_test_assert( strpos( $control, 'id="demo-project_photos"' ) !== false, 'Staged picker should retain deterministic label targeting.' );
eforms_test_assert( strpos( $control, 'disabled="disabled"' ) !== false, 'Staged picker should render disabled before enhancement.' );
eforms_test_assert( strpos( $control, 'name=' ) === false, 'Staged picker should never submit a multipart photo body.' );
eforms_test_assert( substr_count( $control, FormProtocol::DATA_UPLOAD_MOUNT . '="1"' ) === 1, 'Staged field should emit one canonical managed mount.' );
eforms_test_assert( strpos( $control, FormProtocol::DATA_UPLOAD_MAX_FILES . '="24"' ) !== false, 'Managed mount should disclose the count bound.' );
eforms_test_assert( strpos( $control, FormProtocol::DATA_UPLOAD_MAX_FILE_BYTES . '="' . Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' ) . '"' ) !== false, 'Managed mount should disclose the effective managed-artifact bound.' );
eforms_test_assert( strpos( $control, FormProtocol::DATA_UPLOAD_MAX_TOTAL_BYTES . '="314572800"' ) !== false, 'Managed mount should disclose the total-original-byte bound.' );
eforms_test_assert( strpos( $control, 'accept="image/*"' ) !== false, 'Staged picker should use the broad image hint for native mobile photo pickers.' );
eforms_test_assert( strpos( $control, FormProtocol::DATA_UPLOAD_ACCEPT . '="image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif"' ) !== false, 'Managed mount should retain the exact staged accept policy.' );
eforms_test_assert( strpos( $control, 'image/webp' ) !== false && strpos( $control, 'image/gif' ) === false, 'Staged policy hints should include WebP and exclude GIF.' );
eforms_test_assert(
    strpos( $control, 'image/heic' ) !== false
        && strpos( $control, 'image/heif' ) !== false
        && strpos( $control, '.heic' ) !== false
        && strpos( $control, '.heif' ) !== false,
    'The staged image token should expose HEIC and HEIF picker hints.'
);
eforms_test_assert( strpos( $control, '<noscript>' ) !== false, 'Staged field should explain its JavaScript requirement.' );

$long_form_id = str_repeat( 'f', 64 );
$long_descriptor = $descriptor;
$long_descriptor['id_prefix'] = $long_form_id;
$long_staged = $staged;
$long_staged['key'] = str_repeat( 'p', 64 );
$long_picker_id = Helpers::cap_id( $long_form_id . '-' . $long_staged['key'] );
$long_control = $method->invoke( null, $long_descriptor, $long_staged, $long_form_id, $long_picker_id, false, null, array() );
eforms_test_assert( strpos( $long_control, 'id="' . $long_picker_id . '"' ) !== false, 'Long staged picker IDs should use the shared cap owner.' );
eforms_test_assert(
    strpos( $long_control, FormProtocol::DATA_UPLOAD_PICKER_ID . '="' . $long_picker_id . '"' ) !== false,
    'The managed mount should reference the exact capped picker ID.'
);
eforms_test_assert( substr( $long_picker_id, -( strlen( $long_staged['key'] ) + 1 ) ) !== '-' . $long_staged['key'], 'The long-ID fixture should exercise a suffix that cannot survive the cap.' );

// Renderer credentials are accepted only through the explicitly validated internal option.
$parse_batches = new ReflectionMethod( 'FormRenderer', 'parse_validated_upload_batches' );
$parse_batches->setAccessible( true );
eforms_test_assert( $parse_batches->invoke( null, array( 'upload_batches' => array( 'x' ) ) ) === array(), 'Raw upload batch options should not be reflected.' );

$render_batches = new ReflectionMethod( 'FormRenderer', 'render_upload_batch_credentials' );
$render_batches->setAccessible( true );
$context = array(
    'fields' => array( $staged ),
    'staged_field' => $staged,
);
$validated = array(
    'project_photos' => array(
        FormProtocol::UPLOAD_BATCH_ID => 'batch-id',
        FormProtocol::UPLOAD_BATCH_SECRET => 'batch-secret',
    ),
);
eforms_test_assert( $render_batches->invoke( null, $context, array() ) === '', 'Fresh renders should contain no managed-upload credentials.' );
$credentials = $render_batches->invoke( null, $context, $validated );
eforms_test_assert( strpos( $credentials, 'name="' . eforms_upload_test_batch_input_name( 'project_photos', FormProtocol::UPLOAD_BATCH_ID ) . '"' ) !== false, 'Validated rerender should emit the field-scoped batch ID.' );
eforms_test_assert( strpos( $credentials, 'name="' . eforms_upload_test_batch_input_name( 'project_photos', FormProtocol::UPLOAD_BATCH_SECRET ) . '"' ) !== false, 'Validated rerender should emit the field-scoped batch secret.' );
eforms_test_assert( $render_batches->invoke( null, $context, array( 'foreign' => $validated['project_photos'] ) ) === '', 'Foreign fields should never receive reflected credentials.' );
