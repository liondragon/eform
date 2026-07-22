<?php
/**
 * Unit tests for canonical plugin HTML serialization.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/EformsMarkup.php';

$attributes = EformsMarkup::attributes(
    array(
        'id' => 'field-1',
        'required' => 'required',
        'hidden' => '',
        'omitted' => false,
        'missing' => null,
        'data-value' => 'A&B"C',
    )
);
eforms_test_assert( strpos( $attributes, 'id="field-1"' ) !== false, 'Attribute serialization should retain scalar values.' );
eforms_test_assert( strpos( $attributes, 'required="required"' ) !== false, 'Attribute serialization should retain explicit HTML hints.' );
eforms_test_assert( strpos( $attributes, ' hidden' ) !== false, 'Attribute serialization should emit empty boolean attributes.' );
eforms_test_assert( strpos( $attributes, 'omitted' ) === false, 'Attribute serialization should omit false attributes.' );
eforms_test_assert( strpos( $attributes, 'missing' ) === false, 'Attribute serialization should omit null attributes.' );
eforms_test_assert( strpos( $attributes, 'data-value="A&amp;B&quot;C"' ) !== false, 'Attribute serialization should escape untrusted values once.' );

$control = EformsMarkup::apply_control_context(
    array( 'type' => 'text', 'name' => 'local', 'id' => 'local-id' ),
    array(
        'name' => 'demo[field]',
        'id' => 'demo-field',
        'enterkeyhint' => true,
        'attributes' => array( 'aria-invalid' => 'true' ),
    )
);
eforms_test_assert( $control['name'] === 'demo[field]' && $control['id'] === 'demo-field', 'Control context should apply canonical form-scoped identity before rendering.' );
eforms_test_assert( $control['enterkeyhint'] === 'send' && $control['aria-invalid'] === 'true', 'Control context should merge interaction and accessibility attributes before rendering.' );

echo "Eforms markup tests passed.\n";
