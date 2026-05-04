<?php
/**
 * Integration tests for upload field rendering.
 *
 * Spec: Field descriptors and namespacing (docs/Canonical_Spec.md#sec-template-model-fields)
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
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

Config::reset_for_tests();
FormRenderer::reset_for_tests();

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
$control = $method->invoke( null, $descriptor, $field, 'demo', false, null );

eforms_test_assert( strpos( $control, 'type="file"' ) !== false, 'Files field should render a file input.' );
eforms_test_assert( strpos( $control, 'multiple="multiple"' ) !== false, 'Files field should render multiple.' );
eforms_test_assert( strpos( $control, 'required="required"' ) !== false, 'Files field should preserve required hint.' );
eforms_test_assert( strpos( $control, 'name="demo[attachments][]"' ) !== false, 'Files field should use form-scoped array naming.' );
eforms_test_assert( strpos( $control, 'id="demo-attachments"' ) !== false, 'Files field should use the deterministic id.' );
eforms_test_assert( strpos( $control, 'image/jpeg' ) !== false, 'Files field should include image MIME hints.' );
eforms_test_assert( strpos( $control, '.png' ) !== false, 'Files field should include image extension hints.' );
