<?php
/**
 * Unit tests for Coerce stage canonicalization.
 *
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Validation/Coercer.php';

$context = array(
    'descriptors' => array(
        array(
            'key' => 'email',
            'type' => 'email',
            'is_multivalue' => false,
            'validate' => array(),
        ),
        array(
            'key' => 'tel',
            'type' => 'tel_us',
            'is_multivalue' => false,
            'validate' => array(),
        ),
        array(
            'key' => 'name',
            'type' => 'name',
            'is_multivalue' => false,
            'validate' => array(),
        ),
        array(
            'key' => 'notes',
            'type' => 'text',
            'is_multivalue' => false,
            'validate' => array(),
        ),
        array(
            'key' => 'aliases',
            'type' => 'name',
            'is_multivalue' => true,
            'validate' => array(),
        ),
        array(
            'key' => 'canonical_text',
            'type' => 'text',
            'is_multivalue' => false,
            'validate' => array( 'canonicalize' => true ),
        ),
    ),
);

$values = array(
    'email' => 'Casey@EXAMPLE.COM',
    'tel' => '1 (212) 555-1212',
    'name' => 'Ada   Lovelace',
    'notes' => 'keep  spaces',
    'aliases' => array( 'Ada   Lovelace', 'Grace   Hopper' ),
    'canonical_text' => 'Hello   World',
);

$result = Coercer::coerce( $context, array( 'values' => $values ) );
$coerced = isset( $result['values'] ) ? $result['values'] : array();

// Given a validated email...
// When Coerce runs...
// Then it lowercases only the domain.
eforms_test_assert(
    isset( $coerced['email'] ) && $coerced['email'] === 'Casey@example.com',
    'Coerce should lowercase email domains only.'
);

// Given a validated tel_us value...
// When Coerce runs...
// Then it canonicalizes to digits-only NANP format.
eforms_test_assert(
    isset( $coerced['tel'] ) && $coerced['tel'] === '2125551212',
    'Coerce should canonicalize tel_us values to digits.'
);

// Given a name value with internal whitespace...
// When Coerce runs...
// Then it collapses internal whitespace.
eforms_test_assert(
    isset( $coerced['name'] ) && $coerced['name'] === 'Ada Lovelace',
    'Coerce should collapse whitespace for name-like fields.'
);

// Given a text value without canonicalization enabled...
// When Coerce runs...
// Then it preserves internal whitespace.
eforms_test_assert(
    isset( $coerced['notes'] ) && $coerced['notes'] === 'keep  spaces',
    'Coerce should not collapse whitespace when canonicalization is disabled.'
);

// Given a multivalue name list...
// When Coerce runs...
// Then it collapses whitespace per entry.
eforms_test_assert(
    isset( $coerced['aliases'] )
        && $coerced['aliases'] === array( 'Ada Lovelace', 'Grace Hopper' ),
    'Coerce should collapse whitespace for multivalue name fields.'
);

// Given a text field with canonicalize enabled...
// When Coerce runs...
// Then it collapses whitespace.
eforms_test_assert(
    isset( $coerced['canonical_text'] ) && $coerced['canonical_text'] === 'Hello World',
    'Coerce should honor descriptor canonicalize toggles.'
);
