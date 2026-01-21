<?php
/**
 * Unit tests for TemplateValidator semantic preflight.
 *
 * Spec: Template model (docs/Canonical_Spec.md#sec-template-model)
 * Spec: Template validation (docs/Canonical_Spec.md#sec-template-validation)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Validation/TemplateValidator.php';

function eforms_test_collect_codes( $errors ) {
    if ( ! $errors instanceof Errors ) {
        return array();
    }

    $data  = $errors->to_array();
    $codes = array();

    foreach ( $data as $entries ) {
        if ( ! is_array( $entries ) ) {
            continue;
        }
        foreach ( $entries as $entry ) {
            if ( is_array( $entry ) && isset( $entry['code'] ) ) {
                $codes[] = $entry['code'];
            }
        }
    }

    return $codes;
}

function eforms_test_base_template() {
    return array(
        'id' => 'demo_form',
        'version' => '1',
        'title' => 'Demo',
        'success' => array(
            'mode' => 'inline',
            'message' => 'Thanks.',
        ),
        'email' => array(
            'to' => 'demo@example.com',
            'subject' => 'Demo',
            'email_template' => 'default',
            'include_fields' => array( 'name' ),
        ),
        'fields' => array(
            array(
                'key' => 'name',
                'type' => 'text',
                'label' => 'Name',
            ),
        ),
        'submit_button_text' => 'Send',
    );
}

// Given duplicate field keys...
// When TemplateValidator runs...
// Then it emits duplicate key errors.
$template = eforms_test_base_template();
$template['fields'][] = array(
    'key' => 'name',
    'type' => 'text',
    'label' => 'Name Again',
);
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_DUP_KEY', $codes, true ), 'Duplicate keys should emit EFORMS_ERR_SCHEMA_DUP_KEY.' );

// Given a reserved key...
// When TemplateValidator runs...
// Then it emits schema key errors.
$template = eforms_test_base_template();
$template['fields'][0]['key'] = 'form_id';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_KEY', $codes, true ), 'Reserved keys should emit EFORMS_ERR_SCHEMA_KEY.' );

// Given an invalid slug...
// When TemplateValidator runs...
// Then it emits schema key errors.
$template = eforms_test_base_template();
$template['fields'][0]['key'] = 'Bad Key';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_KEY', $codes, true ), 'Invalid key slug should emit EFORMS_ERR_SCHEMA_KEY.' );

// Given an unknown handler id...
// When TemplateValidator resolves handlers...
// Then it emits a configuration error.
$errors = new Errors();
TemplateValidator::validate_descriptor_handlers(
    array(
        'handlers' => array(
            'validator_id' => 'missing',
            'normalizer_id' => 'text',
            'renderer_id' => 'text',
        ),
    ),
    $errors
);
$codes = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Unknown handler ids should emit EFORMS_ERR_SCHEMA_OBJECT.' );

