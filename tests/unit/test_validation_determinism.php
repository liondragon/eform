<?php
/**
 * Unit tests for deterministic validation ordering.
 *
 * Contract: DRY principles
 * Contract: Validation pipeline
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
        array(
            'key' => 'tel',
            'type' => 'tel_us',
        ),
        array(
            'key' => 'zip',
            'type' => 'zip_us',
        ),
        array(
            'key' => 'area',
            'type' => 'number',
            'min' => 1,
        ),
        array(
            'key' => 'listing',
            'type' => 'url',
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
        array(
            'key' => 'tel',
            'type' => 'tel_us',
            'is_multivalue' => false,
        ),
        array(
            'key' => 'zip',
            'type' => 'zip_us',
            'is_multivalue' => false,
        ),
        array(
            'key' => 'area',
            'type' => 'number',
            'is_multivalue' => false,
        ),
        array(
            'key' => 'listing',
            'type' => 'url',
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
    'tel' => '+12125551212fds',
    'zip' => '80231-1234',
    'area' => '1,200',
    'listing' => 'zillow.com/homedetails/123',
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
eforms_test_assert( $keys === array( '_global', 'a', 'b', 'tel' ), 'Error key ordering should be global then fields in descriptor order.' );

eforms_test_assert(
    isset( $e1['_global'] ) && is_array( $e1['_global'] ) && $e1['_global'][0]['code'] === 'EFORMS_ERR_ONE_OF_REQUIRED',
    'one_of should add a global required error when all are missing.'
);

eforms_test_assert(
    isset( $e1['a'] ) && is_array( $e1['a'] ) && $e1['a'][0]['code'] === 'EFORMS_ERR_FIELD_REQUIRED',
    'Required field should produce a required error.'
);

eforms_test_assert(
    isset( $e1['b'] ) && is_array( $e1['b'] ) && $e1['b'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID',
    'Pattern mismatch should produce a type error.'
);

eforms_test_assert(
    isset( $e1['tel'] ) && is_array( $e1['tel'] ) && $e1['tel'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID',
    'Malformed tel_us values should produce an invalid-field error.'
);

$max_context = $context;
$max_context['fields'][6]['max'] = 1000;
$max_result = Validator::validate( $max_context, array( 'values' => array( 'area' => '1,234' ) ) );
$max_errors = $max_result['errors']->to_array();
eforms_test_assert(
    isset( $max_errors['area'] ) && $max_errors['area'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID',
    'Grouped number validation should compare max against the normalized numeric value.'
);

$min_context = $context;
$min_context['fields'][6]['min'] = 1500;
$min_result = Validator::validate( $min_context, array( 'values' => array( 'area' => '1,200' ) ) );
$min_errors = $min_result['errors']->to_array();
eforms_test_assert(
    isset( $min_errors['area'] ) && $min_errors['area'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID',
    'Grouped number validation should compare min against the normalized numeric value.'
);

$bounded_context = $context;
$bounded_context['fields'][6]['min'] = 1000;
$bounded_context['fields'][6]['max'] = 1500;
$bounded_result = Validator::validate( $bounded_context, array( 'values' => array( 'area' => '1,200' ) ) );
$bounded_errors = $bounded_result['errors']->to_array();
eforms_test_assert(
    ! isset( $bounded_errors['area'] ),
    'Grouped number validation should allow values inside normalized numeric bounds.'
);

$fractional_min_context = $context;
$fractional_min_context['fields'][6]['min'] = 0.5;
$fractional_min_result = Validator::validate( $fractional_min_context, array( 'values' => array( 'area' => '0.25' ) ) );
$fractional_min_errors = $fractional_min_result['errors']->to_array();
eforms_test_assert(
    isset( $fractional_min_errors['area'] ) && $fractional_min_errors['area'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID',
    'Number validation should enforce schema-valid fractional minimum bounds.'
);

$fractional_max_context = $context;
$fractional_max_context['fields'][6]['max'] = 10.5;
$fractional_max_result = Validator::validate( $fractional_max_context, array( 'values' => array( 'area' => '10.75' ) ) );
$fractional_max_errors = $fractional_max_result['errors']->to_array();
eforms_test_assert(
    isset( $fractional_max_errors['area'] ) && $fractional_max_errors['area'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID',
    'Number validation should enforce schema-valid fractional maximum bounds.'
);

$step_context = $context;
$step_context['fields'][6]['min'] = 0;
$step_context['fields'][6]['step'] = 1;
$step_result = Validator::validate( $step_context, array( 'values' => array( 'area' => '1.5' ) ) );
$step_errors = $step_result['errors']->to_array();
eforms_test_assert(
    isset( $step_errors['area'] ) && $step_errors['area'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID',
    'Number validation should enforce integer step when browser numeric controls are not authoritative.'
);

$fractional_step_context = $context;
$fractional_step_context['fields'][6]['min'] = 0.5;
$fractional_step_context['fields'][6]['step'] = 0.25;
$fractional_step_result = Validator::validate( $fractional_step_context, array( 'values' => array( 'area' => '1.0' ) ) );
$fractional_step_errors = $fractional_step_result['errors']->to_array();
eforms_test_assert(
    ! isset( $fractional_step_errors['area'] ),
    'Number validation should allow values aligned to a fractional step from the minimum.'
);

$fractional_step_mismatch = Validator::validate( $fractional_step_context, array( 'values' => array( 'area' => '1.1' ) ) );
$fractional_step_mismatch_errors = $fractional_step_mismatch['errors']->to_array();
eforms_test_assert(
    isset( $fractional_step_mismatch_errors['area'] ) && $fractional_step_mismatch_errors['area'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID',
    'Number validation should reject values misaligned with a fractional step.'
);

$range_context = $bounded_context;
$range_context['fields'][6]['type'] = 'range';
$range_context['descriptors'][6]['type'] = 'range';
$range_result = Validator::validate( $range_context, array( 'values' => array( 'area' => '1,600' ) ) );
$range_errors = $range_result['errors']->to_array();
eforms_test_assert(
    isset( $range_errors['area'] ) && $range_errors['area'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID',
    'Grouped range validation should compare bounds against the normalized numeric value.'
);
