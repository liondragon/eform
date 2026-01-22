<?php
/**
 * Unit tests for deterministic validation ordering.
 *
 * Spec: DRY principles (docs/Canonical_Spec.md#sec-dry-principles)
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Validation/Validator.php';

Config::reset_for_tests();

$context = array(
    'fields' => array(
        array(
            'key' => 'a',
            'type' => 'text',
            'required' => true,
        ),
        array(
            'key' => 'b',
            'type' => 'text',
            'pattern' => '^\\d+$',
        ),
        array(
            'key' => 'c',
            'type' => 'text',
        ),
        array(
            'key' => 'd',
            'type' => 'text',
        ),
    ),
    'descriptors' => array(
        array(
            'key' => 'a',
            'type' => 'text',
            'is_multivalue' => false,
        ),
        array(
            'key' => 'b',
            'type' => 'text',
            'is_multivalue' => false,
        ),
        array(
            'key' => 'c',
            'type' => 'text',
            'is_multivalue' => false,
        ),
        array(
            'key' => 'd',
            'type' => 'text',
            'is_multivalue' => false,
        ),
    ),
    'rules' => array(
        array(
            'rule' => 'one_of',
            'fields' => array( 'c', 'd' ),
        ),
    ),
);

$values = array(
    'a' => '',
    'b' => 'not-a-number',
    'c' => '',
    'd' => '',
    'extraneous' => 'ignored',
);

$r1 = Validator::validate( $context, array( 'values' => $values ) );
$r2 = Validator::validate( $context, array( 'values' => $values ) );

$e1 = $r1['errors']->to_array();
$e2 = $r2['errors']->to_array();

// Given identical inputs...
// When validation runs multiple times...
// Then errors are stable and deterministic.
eforms_test_assert( $e1 === $e2, 'Validation errors should be deterministic.' );

// Given global errors and per-field errors...
// When errors are exported...
// Then global errors come first, then fields in template order.
$keys = array_keys( $e1 );
eforms_test_assert( $keys === array( '_global', 'a', 'b' ), 'Error key ordering should be global then fields in descriptor order.' );

eforms_test_assert(
    isset( $e1['_global'] ) && is_array( $e1['_global'] ) && $e1['_global'][0]['code'] === 'EFORMS_ERR_SCHEMA_REQUIRED',
    'one_of should add a global required error when all are missing.'
);

eforms_test_assert(
    isset( $e1['a'] ) && is_array( $e1['a'] ) && $e1['a'][0]['code'] === 'EFORMS_ERR_SCHEMA_REQUIRED',
    'Required field should produce a required error.'
);

eforms_test_assert(
    isset( $e1['b'] ) && is_array( $e1['b'] ) && $e1['b'][0]['code'] === 'EFORMS_ERR_SCHEMA_TYPE',
    'Pattern mismatch should produce a type error.'
);
