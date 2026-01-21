<?php
/**
 * Unit tests for TemplateValidator schema/envelope validation.
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

// Given a minimal valid template...
// When TemplateValidator runs...
// Then no schema errors are reported.
$template = array(
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
    'rules' => array(
        array(
            'rule' => 'required_if',
            'target' => 'name',
            'field' => 'name',
            'equals' => 'yes',
        ),
    ),
);

$errors = TemplateValidator::validate_template_envelope( $template );
eforms_test_assert( ! $errors->any(), 'Valid template should not emit schema errors.' );

// Given unknown keys...
// When TemplateValidator runs...
// Then it emits schema unknown key errors.
$template['unknown_root'] = 'x';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY', $codes, true ), 'Unknown root keys should be rejected.' );
unset( $template['unknown_root'] );

$template['email']['unknown_email'] = 'x';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY', $codes, true ), 'Unknown email keys should be rejected.' );
unset( $template['email']['unknown_email'] );

$template['fields'][0]['unknown_field'] = 'x';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY', $codes, true ), 'Unknown field keys should be rejected.' );
unset( $template['fields'][0]['unknown_field'] );

// Given invalid enums...
// When TemplateValidator runs...
// Then it emits schema enum errors.
$template['success']['mode'] = 'bogus';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'Invalid success.mode should be rejected.' );
$template['success']['mode'] = 'inline';

$template['fields'][0]['type'] = 'bogus';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'Invalid field.type should be rejected.' );
$template['fields'][0]['type'] = 'text';

$template['rules'][0]['rule'] = 'bogus';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'Invalid rule.rule should be rejected.' );
$template['rules'][0]['rule'] = 'required_if';

// Given row group with invalid mode...
// When TemplateValidator runs...
// Then it emits schema enum errors.
$template['fields'][] = array(
    'type' => 'row_group',
    'mode' => 'middle',
);
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'Invalid row_group.mode should be rejected.' );

// Given an unbalanced row_group stack...
// When TemplateValidator runs...
// Then it emits row_group unbalanced errors.
$template = array(
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
            'type' => 'row_group',
            'mode' => 'end',
        ),
        array(
            'key' => 'name',
            'type' => 'text',
            'label' => 'Name',
        ),
    ),
    'submit_button_text' => 'Send',
);
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_ROW_GROUP_UNBALANCED', $codes, true ), 'Unbalanced row_group should emit EFORMS_ERR_ROW_GROUP_UNBALANCED.' );

$template = array(
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

// Given missing required keys...
// When TemplateValidator runs...
// Then it emits schema required errors.
$missing_email = $template;
unset( $missing_email['email'] );
$errors = TemplateValidator::validate_template_envelope( $missing_email );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_REQUIRED', $codes, true ), 'Missing required keys should be rejected.' );

// Given redirect mode without redirect_url...
// When TemplateValidator runs...
// Then it emits schema required errors.
$redirect_missing = $template;
$redirect_missing['success'] = array(
    'mode' => 'redirect',
);
$errors = TemplateValidator::validate_template_envelope( $redirect_missing );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_REQUIRED', $codes, true ), 'Redirect mode should require redirect_url.' );

// Given include_fields referencing unknown keys...
// When TemplateValidator runs...
// Then it emits schema unknown key errors.
$include_unknown = $template;
$include_unknown['email']['include_fields'] = array( 'name', 'missing_key' );
$errors = TemplateValidator::validate_template_envelope( $include_unknown );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY', $codes, true ), 'Unknown include_fields entries should be rejected.' );

// Given invalid email.to address...
// When TemplateValidator runs...
// Then it emits schema type errors.
$invalid_email = $template;
$invalid_email['email']['to'] = 'not-an-email';
$errors = TemplateValidator::validate_template_envelope( $invalid_email );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_TYPE', $codes, true ), 'Invalid email.to should be rejected.' );

// Given an unknown email_template...
// When TemplateValidator runs...
// Then it emits schema enum errors.
$unknown_template = $template;
$unknown_template['email']['email_template'] = 'missing_template';
$errors = TemplateValidator::validate_template_envelope( $unknown_template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'Unknown email_template should be rejected.' );

// Given an invalid display_format_tel token...
// When TemplateValidator runs...
// Then it emits schema enum errors.
$invalid_display_format = $template;
$invalid_display_format['email']['display_format_tel'] = 'bad-token';
$errors = TemplateValidator::validate_template_envelope( $invalid_display_format );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'Invalid display_format_tel should be rejected.' );
