<?php
/**
 * Unit tests for choice field types (select/radio/checkbox).
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 * Spec: Template options (docs/Canonical_Spec.md#sec-template-options)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Validation/FieldTypeRegistry.php';
require_once __DIR__ . '/../../src/Validation/FieldTypes/Choice.php';
require_once __DIR__ . '/../../src/Rendering/FieldRenderers/Choice.php';
require_once __DIR__ . '/../../src/Rendering/RendererRegistry.php';

// Given choice types...
// When FieldTypeRegistry resolves them...
// Then descriptors include the correct HTML hints.
$select = FieldTypeRegistry::resolve( 'select' );
eforms_test_assert( $select['html']['tag'] === 'select', 'Select should use select tag.' );
eforms_test_assert( $select['is_multivalue'] === false, 'Select should be single value by default.' );

$radio = FieldTypeRegistry::resolve( 'radio' );
eforms_test_assert( $radio['html']['type'] === 'radio', 'Radio should use input type radio.' );

$checkbox = FieldTypeRegistry::resolve( 'checkbox' );
eforms_test_assert( $checkbox['html']['type'] === 'checkbox', 'Checkbox should use input type checkbox.' );
eforms_test_assert( $checkbox['is_multivalue'] === true, 'Checkbox should be multivalue by default.' );

// Given a select field...
// When build_select_attributes runs...
// Then it mirrors required and names.
$attrs = FieldRenderers_Choice::build_select_attributes(
    $select,
    array(
        'key' => 'contact_method',
        'required' => true,
    ),
    array(
        'id_prefix' => 'form1',
    )
);
eforms_test_assert( $attrs['name'] === 'contact_method', 'Select should emit name.' );
eforms_test_assert( $attrs['id'] === 'form1-contact_method', 'Select should emit id with prefix.' );
eforms_test_assert( $attrs['required'] === 'required', 'Select should emit required attribute.' );

// Given checkbox options...
// When build_choice_input_attributes runs...
// Then it mirrors option values and disabled flags.
$attrs = FieldRenderers_Choice::build_choice_input_attributes(
    $checkbox,
    array(
        'key' => 'features',
    ),
    array(
        'key' => 'feature_a',
        'label' => 'Feature A',
        'disabled' => true,
    ),
    array(
        'id_prefix' => 'form1',
    )
);
eforms_test_assert( $attrs['type'] === 'checkbox', 'Choice input should set type.' );
eforms_test_assert( $attrs['name'] === 'features', 'Choice input should set name.' );
eforms_test_assert( $attrs['value'] === 'feature_a', 'Choice input should set value from option.' );
eforms_test_assert( $attrs['disabled'] === 'disabled', 'Choice input should carry disabled flag.' );
eforms_test_assert( $attrs['id'] === 'form1-features-feature_a', 'Choice input should include option key in id.' );

// Given registry resolution...
// When RendererRegistry resolves choice...
// Then it returns a callable.
$renderer = RendererRegistry::resolve( 'choice' );
eforms_test_assert( is_callable( $renderer ), 'RendererRegistry should resolve a callable for choice.' );

