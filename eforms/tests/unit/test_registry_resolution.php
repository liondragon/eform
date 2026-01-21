<?php
/**
 * Unit tests for registry resolution behavior.
 *
 * Spec: Central registries (docs/Canonical_Spec.md#sec-central-registries)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Validation/FieldTypeRegistry.php';
require_once __DIR__ . '/../../src/Validation/ValidatorRegistry.php';
require_once __DIR__ . '/../../src/Validation/NormalizerRegistry.php';
require_once __DIR__ . '/../../src/Rendering/RendererRegistry.php';

function eforms_test_parse_exception( $exception ) {
    $payload = json_decode( $exception->getMessage(), true );
    if ( ! is_array( $payload ) ) {
        return array();
    }
    return $payload;
}

// Given known IDs...
// When registries resolve...
// Then they return callables or descriptors.
$type = FieldTypeRegistry::resolve( 'text' );
eforms_test_assert( is_array( $type ), 'FieldTypeRegistry should return a descriptor array.' );

$validator = ValidatorRegistry::resolve( 'text' );
eforms_test_assert( is_callable( $validator ), 'ValidatorRegistry should resolve a callable.' );

$normalizer = NormalizerRegistry::resolve( 'text' );
eforms_test_assert( is_callable( $normalizer ), 'NormalizerRegistry should resolve a callable.' );

$renderer = RendererRegistry::resolve( 'text' );
eforms_test_assert( is_callable( $renderer ), 'RendererRegistry should resolve a callable.' );

// Given unknown IDs...
// When registries resolve...
// Then they throw deterministic RuntimeExceptions with payload fields.
$threw = false;
try {
    FieldTypeRegistry::resolve( 'missing' );
} catch ( RuntimeException $exception ) {
    $threw   = true;
    $payload = eforms_test_parse_exception( $exception );
    eforms_test_assert( $payload['type'] === 'handler_resolution', 'FieldTypeRegistry should encode type.' );
    eforms_test_assert( $payload['id'] === 'missing', 'FieldTypeRegistry should encode id.' );
    eforms_test_assert( $payload['registry'] === 'FieldTypeRegistry', 'FieldTypeRegistry should encode registry.' );
}
eforms_test_assert( $threw, 'FieldTypeRegistry should throw on unknown id.' );

$threw = false;
try {
    ValidatorRegistry::resolve( 'missing' );
} catch ( RuntimeException $exception ) {
    $threw   = true;
    $payload = eforms_test_parse_exception( $exception );
    eforms_test_assert( $payload['registry'] === 'ValidatorRegistry', 'ValidatorRegistry should encode registry.' );
}
eforms_test_assert( $threw, 'ValidatorRegistry should throw on unknown id.' );

$threw = false;
try {
    NormalizerRegistry::resolve( 'missing' );
} catch ( RuntimeException $exception ) {
    $threw   = true;
    $payload = eforms_test_parse_exception( $exception );
    eforms_test_assert( $payload['registry'] === 'NormalizerRegistry', 'NormalizerRegistry should encode registry.' );
}
eforms_test_assert( $threw, 'NormalizerRegistry should throw on unknown id.' );

$threw = false;
try {
    RendererRegistry::resolve( 'missing' );
} catch ( RuntimeException $exception ) {
    $threw   = true;
    $payload = eforms_test_parse_exception( $exception );
    eforms_test_assert( $payload['registry'] === 'RendererRegistry', 'RendererRegistry should encode registry.' );
}
eforms_test_assert( $threw, 'RendererRegistry should throw on unknown id.' );

