<?php
/**
 * Unit tests for textarea field type descriptor + renderer.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Validation/FieldTypeRegistry.php';
require_once __DIR__ . '/../../src/Validation/FieldTypes/Textarea.php';
require_once __DIR__ . '/../../src/Rendering/FieldRenderers/Textarea.php';
require_once __DIR__ . '/../../src/Rendering/RendererRegistry.php';

// Given the textarea type...
// When FieldTypeRegistry resolves it...
// Then it returns a textarea descriptor.
$descriptor = FieldTypeRegistry::resolve( 'textarea' );
eforms_test_assert( $descriptor['type'] === 'textarea', 'Textarea descriptor should resolve.' );
eforms_test_assert( $descriptor['html']['tag'] === 'textarea', 'Textarea descriptor should use textarea tag.' );
eforms_test_assert( $descriptor['handlers']['renderer_id'] === 'textarea', 'Textarea renderer id should be set.' );

// Given a textarea field...
// When the renderer builds attributes...
// Then it mirrors max_length and required flags.
$attrs = FieldRenderers_Textarea::build_attributes(
    $descriptor,
    array(
        'key' => 'message',
        'max_length' => 200,
        'required' => true,
        'placeholder' => 'Message',
    ),
    array(
        'id_prefix' => 'form1',
    )
);
eforms_test_assert( $attrs['maxlength'] === 200, 'Textarea should mirror maxlength.' );
eforms_test_assert( $attrs['required'] === 'required', 'Textarea should emit required attribute.' );
eforms_test_assert( $attrs['placeholder'] === 'Message', 'Textarea should emit placeholder.' );
eforms_test_assert( $attrs['name'] === 'message', 'Textarea should emit name attribute.' );
eforms_test_assert( $attrs['id'] === 'form1-message', 'Textarea should emit id attribute with prefix.' );

// Given registry resolution...
// When RendererRegistry resolves textarea...
// Then it returns a callable.
$renderer = RendererRegistry::resolve( 'textarea' );
eforms_test_assert( is_callable( $renderer ), 'RendererRegistry should resolve a callable for textarea.' );

