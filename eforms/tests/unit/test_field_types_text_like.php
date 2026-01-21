<?php
/**
 * Unit tests for text-like field types and tel formatting.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 * Spec: display_format_tel tokens (docs/Canonical_Spec.md#sec-display-format-tel)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Validation/FieldTypeRegistry.php';
require_once __DIR__ . '/../../src/Validation/FieldTypes/TextLike.php';
require_once __DIR__ . '/../../src/Rendering/FieldRenderers/TextLike.php';
require_once __DIR__ . '/../../src/Rendering/RendererRegistry.php';

// Given built-in text-like types...
// When FieldTypeRegistry resolves them...
// Then descriptors include expected defaults.
$descriptor = FieldTypeRegistry::resolve( 'email' );
eforms_test_assert( $descriptor['type'] === 'email', 'Email descriptor should resolve.' );
eforms_test_assert( $descriptor['html']['type'] === 'email', 'Email descriptor should set input type.' );
eforms_test_assert( $descriptor['html']['inputmode'] === 'email', 'Email descriptor should set inputmode.' );
eforms_test_assert( $descriptor['constants']['spellcheck'] === 'false', 'Email descriptor should disable spellcheck.' );
eforms_test_assert( $descriptor['constants']['autocapitalize'] === 'off', 'Email descriptor should disable autocapitalize.' );

$descriptor = FieldTypeRegistry::resolve( 'url' );
eforms_test_assert( $descriptor['html']['type'] === 'url', 'URL descriptor should set input type.' );
eforms_test_assert( $descriptor['constants']['spellcheck'] === 'false', 'URL descriptor should disable spellcheck.' );
eforms_test_assert( $descriptor['constants']['autocapitalize'] === 'off', 'URL descriptor should disable autocapitalize.' );

$descriptor = FieldTypeRegistry::resolve( 'tel_us' );
eforms_test_assert( $descriptor['html']['type'] === 'tel', 'tel_us descriptor should use input type tel.' );
eforms_test_assert( $descriptor['html']['inputmode'] === 'tel', 'tel_us descriptor should set inputmode.' );

$descriptor = FieldTypeRegistry::resolve( 'zip_us' );
eforms_test_assert( $descriptor['html']['inputmode'] === 'numeric', 'zip_us descriptor should set inputmode.' );
eforms_test_assert( $descriptor['html']['pattern'] === '\\d{5}', 'zip_us descriptor should set pattern.' );

$descriptor = FieldTypeRegistry::resolve( 'number' );
eforms_test_assert( $descriptor['html']['type'] === 'number', 'number descriptor should use input type number.' );
eforms_test_assert( $descriptor['html']['inputmode'] === 'decimal', 'number descriptor should set inputmode.' );

$descriptor = FieldTypeRegistry::resolve( 'range' );
eforms_test_assert( $descriptor['html']['type'] === 'range', 'range descriptor should use input type range.' );
eforms_test_assert( $descriptor['html']['inputmode'] === 'decimal', 'range descriptor should set inputmode.' );

$descriptor = FieldTypeRegistry::resolve( 'date' );
eforms_test_assert( $descriptor['html']['type'] === 'date', 'date descriptor should use input type date.' );

$descriptor = FieldTypeRegistry::resolve( 'name' );
eforms_test_assert( $descriptor['alias_of'] === 'text', 'name should be an alias of text.' );
eforms_test_assert( $descriptor['defaults']['autocomplete'] === 'name', 'name should default autocomplete.' );

$descriptor = FieldTypeRegistry::resolve( 'first_name' );
eforms_test_assert( $descriptor['defaults']['autocomplete'] === 'given-name', 'first_name should default autocomplete.' );

$descriptor = FieldTypeRegistry::resolve( 'last_name' );
eforms_test_assert( $descriptor['defaults']['autocomplete'] === 'family-name', 'last_name should default autocomplete.' );

// Given a descriptor and field overrides...
// When the renderer builds attributes...
// Then it mirrors hints and defaults.
$descriptor = FieldTypeRegistry::resolve( 'email' );
$attrs = FieldRenderers_TextLike::build_attributes(
    $descriptor,
    array(
        'key' => 'email',
        'max_length' => 40,
        'size' => 30,
    )
);
eforms_test_assert( $attrs['type'] === 'email', 'Renderer should emit email type.' );
eforms_test_assert( $attrs['inputmode'] === 'email', 'Renderer should emit inputmode.' );
eforms_test_assert( $attrs['spellcheck'] === 'false', 'Renderer should emit constants.' );
eforms_test_assert( $attrs['maxlength'] === 40, 'Renderer should mirror maxlength.' );
eforms_test_assert( $attrs['size'] === 30, 'Renderer should mirror size.' );

$descriptor = FieldTypeRegistry::resolve( 'name' );
$attrs = FieldRenderers_TextLike::build_attributes(
    $descriptor,
    array(
        'key' => 'name',
    )
);
eforms_test_assert( $attrs['autocomplete'] === 'name', 'Renderer should emit default autocomplete.' );

// Given a tel_us value...
// When format_tel_us runs...
// Then it applies the requested display format.
$formatted = FieldTypes_TextLike::format_tel_us( '1 (212) 555-1212', '(xxx) xxx-xxxx' );
eforms_test_assert( $formatted === '(212) 555-1212', 'Tel formatting should match the token.' );

$formatted = FieldTypes_TextLike::format_tel_us( '2125551212', 'xxx.xxx.xxxx' );
eforms_test_assert( $formatted === '212.555.1212', 'Tel formatting should support dot format.' );

$formatted = FieldTypes_TextLike::format_tel_us( '2125551212', 'unknown' );
eforms_test_assert( $formatted === '212-555-1212', 'Unknown format should fall back to default.' );

// Given registry resolution...
// When RendererRegistry resolves text...
// Then it returns a callable.
$renderer = RendererRegistry::resolve( 'text' );
eforms_test_assert( is_callable( $renderer ), 'RendererRegistry should resolve a callable.' );
