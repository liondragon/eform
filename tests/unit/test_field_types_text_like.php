<?php
/**
 * Unit tests for text-like field types and tel formatting.
 *
 * Contract: Field types
 * Contract: display_format_tel tokens
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/FormProtocol.php';
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
eforms_test_assert( $descriptor['render']['protocol_attrs'][ FormProtocol::DATA_URL_NORMALIZE ] === '1', 'URL descriptor should own the browser normalize marker.' );

$descriptor = FieldTypeRegistry::resolve( 'tel_us' );
eforms_test_assert( $descriptor['html']['type'] === 'tel', 'tel_us descriptor should use input type tel.' );
eforms_test_assert( $descriptor['html']['inputmode'] === 'tel', 'tel_us descriptor should set inputmode.' );
eforms_test_assert( $descriptor['render']['protocol_attrs'][ FormProtocol::DATA_PHONE_FORMAT ] === 'tel_us', 'tel_us descriptor should own the browser phone-format marker.' );
$data_attrs = FormProtocol::browser_settings()['dataAttributes'];
eforms_test_assert( $data_attrs['phone_format'] === FormProtocol::DATA_PHONE_FORMAT, 'Protocol should expose the phone-format marker.' );
eforms_test_assert( $data_attrs['zip_format'] === FormProtocol::DATA_ZIP_FORMAT, 'Protocol should expose the ZIP-format marker.' );
eforms_test_assert( $data_attrs['integer_format'] === FormProtocol::DATA_INTEGER_FORMAT, 'Protocol should expose the integer-format marker.' );
eforms_test_assert( $data_attrs['url_normalize'] === FormProtocol::DATA_URL_NORMALIZE, 'Protocol should expose the URL-normalize marker.' );

$descriptor = FieldTypeRegistry::resolve( 'zip_us' );
eforms_test_assert( $descriptor['html']['inputmode'] === 'numeric', 'zip_us descriptor should set inputmode.' );
eforms_test_assert( $descriptor['html']['pattern'] === '\\d{5}', 'zip_us descriptor should set pattern.' );
eforms_test_assert( $descriptor['defaults']['maxlength'] === 5, 'zip_us descriptor should default to five visible digits.' );
eforms_test_assert( $descriptor['render']['protocol_attrs'][ FormProtocol::DATA_ZIP_FORMAT ] === 'zip_us', 'zip_us descriptor should own the browser ZIP-format marker.' );

$descriptor = FieldTypeRegistry::resolve( 'number' );
eforms_test_assert( $descriptor['html']['type'] === 'text', 'number descriptor should avoid native spinner controls.' );
eforms_test_assert( $descriptor['html']['inputmode'] === 'decimal', 'number descriptor should set inputmode.' );
eforms_test_assert( $descriptor['render']['integer_format_when'] === 'non_negative_step_one', 'number descriptor should own the integer-format eligibility rule.' );
eforms_test_assert(
    FieldTypes_TextLike::render_protocol_attributes( $descriptor, array( 'min' => 0, 'step' => 1, 'unit' => 'sqft' ) ) === array(
        FormProtocol::DATA_INTEGER_FORMAT => '1',
        FormProtocol::DATA_INPUT_UNIT => 'sqft',
    ),
    'TextLike render metadata should emit integer and unit protocol attributes from the field-type owner.'
);
eforms_test_assert(
    FieldTypes_TextLike::render_protocol_attributes( $descriptor, array( 'min' => 0.5, 'step' => 1, 'unit' => 'sqft' ) ) === array(
        FormProtocol::DATA_INPUT_UNIT => 'sqft',
    ),
    'TextLike render metadata should not mark fractional number ranges as integer-only.'
);
eforms_test_assert(
    FieldTypes_TextLike::render_protocol_attributes( array( 'type' => 'textarea' ), array( 'unit' => 'sqft' ) ) === array(),
    'TextLike render metadata should not emit unit protocol attributes without descriptor opt-in.'
);

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

// Given tel_us normalization...
// When a value contains alphabetic or malformed plus characters...
// Then it rejects the value instead of silently stripping them.
eforms_test_assert(
    FieldTypes_TextLike::normalize_tel_us( '+1 (212) 555-1212' ) === '2125551212',
    'Tel normalization should accept common NANP formatting characters.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_tel_us( '+12125551212fds' ) === null,
    'Tel normalization should reject alphabetic suffixes.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_tel_us( '212+5551212' ) === null,
    'Tel normalization should reject plus signs outside the prefix.'
);

eforms_test_assert(
    FieldTypes_TextLike::normalize_zip_us( '80231' ) === '80231',
    'ZIP normalization should accept exact five-digit input.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_zip_us( '80231-1234' ) === '80231',
    'ZIP normalization should accept ZIP+4 input and keep the service-area ZIP.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_zip_us( '802311234' ) === null,
    'ZIP normalization should reject unhyphenated overlong digits.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_zip_us( '12-345-6789' ) === null,
    'ZIP normalization should reject malformed hyphen grouping.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_zip_us( '80231 1234' ) === null,
    'ZIP normalization should reject embedded-space ZIP+4 input.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_zip_us( '8023A' ) === null,
    'ZIP normalization should reject letters.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_number( '1,200' ) === '1200',
    'Number normalization should accept grouped thousands.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_number( '1,2' ) === '12',
    'Number normalization should treat comma placement as decorative.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_number( '12,,34' ) === '1234',
    'Number normalization should strip repeated decorative commas.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_number( '1,2.3' ) === '12.3',
    'Number normalization should preserve decimal semantics while stripping commas.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_number( '12,34a' ) === null,
    'Number normalization should still reject non-numeric values after comma stripping.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_url( 'zillow.com/homedetails/123' ) === 'https://zillow.com/homedetails/123',
    'URL normalization should accept bare listing domains.'
);
eforms_test_assert(
    FieldTypes_TextLike::normalize_url( 'javascript:alert(1)' ) === null,
    'URL normalization should reject non-http schemes.'
);

// Given registry resolution...
// When RendererRegistry resolves text...
// Then it returns a callable.
$renderer = RendererRegistry::resolve( 'text' );
eforms_test_assert( is_callable( $renderer ), 'RendererRegistry should resolve a callable.' );
