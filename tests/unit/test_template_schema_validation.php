<?php
/**
 * Unit tests for TemplateValidator schema/envelope validation.
 *
 * Contract: Template model
 * Contract: Template validation
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
    'result_pages' => array(
        'success' => array(
            'title' => 'Thanks',
            'message' => 'Thanks.',
        ),
        'email_failure' => array(
            'title' => 'Request Not Sent',
            'message' => 'Please try again.',
        ),
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

$one_of_message = $template;
$one_of_message['rules'] = array(
    array(
        'rule' => 'one_of',
        'fields' => array( 'name' ),
        'message' => 'Please provide a name.',
    ),
);
$errors = TemplateValidator::validate_template_envelope( $one_of_message );
eforms_test_assert( ! $errors->any(), 'one_of should accept an optional string message.' );

$invalid_one_of_message = $one_of_message;
$invalid_one_of_message['rules'][0]['message'] = array( 'not', 'copy' );
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $invalid_one_of_message ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_TYPE', $codes, true ), 'one_of should reject a non-string message.' );

$unsupported_rule_message = $template;
$unsupported_rule_message['rules'][0]['message'] = 'Ignored copy is forbidden.';
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $unsupported_rule_message ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY', $codes, true ), 'Rule types without custom global copy should reject message.' );

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

// Given non-schema result destination fields...
// When TemplateValidator runs...
// Then it rejects them instead of treating them as live behavior.
$template['success'] = array(
    'mode' => 'redirect',
    'redirect_url' => 'https://example.com/thanks/',
);
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY', $codes, true ), 'Legacy success block should be rejected.' );
unset( $template['success'] );

$template['result_pages']['success']['redirect_url'] = 'https://example.com/thanks/';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY', $codes, true ), 'Result page config must not accept redirect_url.' );
unset( $template['result_pages']['success']['redirect_url'] );

$template['result_pages'] = null;
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'result_pages must be an object when present.' );
$template['result_pages'] = array(
    'success' => array(
        'title' => 'Thanks',
        'message' => 'Thanks.',
    ),
    'email_failure' => array(
        'title' => 'Request Not Sent',
        'message' => 'Please try again.',
    ),
);

$template['result_pages']['success'] = null;
$errors = TemplateValidator::validate_template_envelope( $template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'result_pages.success must be an object when present.' );
$template['result_pages']['success'] = array(
    'title' => 'Thanks',
    'message' => 'Thanks.',
);

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
    'result_pages' => array(
        'success' => array(
            'message' => 'Thanks.',
        ),
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
    'result_pages' => array(
        'success' => array(
            'message' => 'Thanks.',
        ),
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

// Given result-page customization without redirect behavior...
// When TemplateValidator runs...
// Then the template remains structurally valid.
$custom_result_page = $template;
$custom_result_page['result_pages'] = array(
    'success' => array(
        'title' => 'Custom Thanks',
        'message' => 'Custom result copy.',
    ),
);
$errors = TemplateValidator::validate_template_envelope( $custom_result_page );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( ! in_array( 'EFORMS_ERR_SCHEMA_REQUIRED', $codes, true ), 'Result-page customization should not require redirect fields.' );
eforms_test_assert( ! in_array( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY', $codes, true ), 'Result-page customization should use the canonical schema.' );

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

// Given the registry-owned field type list...
// When TemplateValidator validates each supported type...
// Then every real registry type is accepted by schema validation.
foreach ( FieldTypeRegistry::supported_types() as $field_type ) {
    $field_type_template = $template;
    $field_type_template['fields'][0]['type'] = $field_type;

    if ( $field_type === 'select' || $field_type === 'radio' || $field_type === 'checkbox' ) {
        $field_type_template['fields'][0]['options'] = array(
            array(
                'key' => 'yes',
                'label' => 'Yes',
            ),
        );
    }

    $errors = TemplateValidator::validate_template_envelope( $field_type_template );
    $codes  = eforms_test_collect_codes( $errors );
    eforms_test_assert( ! in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'Registry type ' . $field_type . ' should be accepted.' );
}

// Given unit metadata on an eligible number field...
// When TemplateValidator runs...
// Then the descriptor-owned display metadata is accepted.
$unit_number_template = $template;
$unit_number_template['fields'][0]['type'] = 'number';
$unit_number_template['fields'][0]['unit'] = 'sqft';
$errors = TemplateValidator::validate_template_envelope( $unit_number_template );
$codes = eforms_test_collect_codes( $errors );
eforms_test_assert( ! in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Number fields should accept descriptor-owned unit metadata.' );

// Given unit metadata on a field without descriptor support...
// When TemplateValidator runs...
// Then the template is rejected instead of silently ignoring the unit.
$unit_text_template = $template;
$unit_text_template['fields'][0]['type'] = 'text';
$unit_text_template['fields'][0]['unit'] = 'sqft';
$errors = TemplateValidator::validate_template_envelope( $unit_text_template );
$codes = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Non-number fields should reject unit metadata.' );

// Given row_group as a pseudo-field...
// When TemplateValidator validates it...
// Then it is accepted outside FieldTypeRegistry ownership.
$row_group_template = $template;
$row_group_template['fields'] = array(
    array(
        'type' => 'row_group',
        'mode' => 'start',
    ),
    array(
        'key' => 'name',
        'type' => 'text',
        'label' => 'Name',
    ),
    array(
        'type' => 'row_group',
        'mode' => 'end',
    ),
);
$errors = TemplateValidator::validate_template_envelope( $row_group_template );
$codes  = eforms_test_collect_codes( $errors );
eforms_test_assert( ! in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'row_group pseudo-fields should remain valid.' );
eforms_test_assert( FieldTypeRegistry::is_supported( 'row_group' ) === false, 'row_group should not be a registry field type.' );

// Given the complete staged files contract...
// Then schema validation accepts its bounded, non-attachment policy.
$staged_template = $template;
$staged_template['fields'][] = array(
    'key' => 'project_photos',
    'type' => 'files',
    'label' => 'Project photos',
    'required' => true,
    'accept' => array( 'image' ),
    'upload_mode' => 'staged',
    'max_file_bytes' => 20971520,
    'max_files' => 24,
    'max_total_bytes' => 314572800,
    'email_attach' => false,
);
$staged_template['email']['include_fields'][] = 'project_photos';
$errors = TemplateValidator::validate_template_envelope( $staged_template );
eforms_test_assert( ! $errors->any(), 'A complete staged files policy should pass schema validation.' );

$heic_staged = $staged_template;
$heic_staged['fields'][1]['accept'] = array( 'image', 'heic' );
$errors = TemplateValidator::validate_template_envelope( $heic_staged );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_ENUM', eforms_test_collect_codes( $errors ), true ), 'A staged files policy should reject the removed HEIC opt-in token.' );

$staged_without_gallery = $staged_template;
$staged_without_gallery['email']['include_fields'] = array( 'name' );
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $staged_without_gallery ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_REQUIRED', $codes, true ), 'A staged field must be included in email so recipients receive its review gallery.' );

$multiple_staged = $staged_template;
$second_staged = $multiple_staged['fields'][1];
$second_staged['key'] = 'more_photos';
$multiple_staged['fields'][] = $second_staged;
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $multiple_staged ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'V1 should reject multiple staged fields because one aggregate owns the submission rename.' );

$invalid_staged = $staged_template;
$invalid_staged['fields'][1]['type'] = 'file';
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $invalid_staged ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Staged mode should require the files type.' );

$invalid_staged = $staged_template;
$invalid_staged['fields'][1]['accept'] = array( 'image', 'pdf' );
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $invalid_staged ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'Staged mode should reject every token other than image.' );

$invalid_staged = $staged_template;
$invalid_staged['fields'][1]['accept'] = array( 'heic' );
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $invalid_staged ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_ENUM', $codes, true ), 'HEIC should not exist as a standalone staged token.' );

$invalid_staged = $staged_template;
$invalid_staged['fields'][1]['email_attach'] = true;
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $invalid_staged ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Staged mode should reject email attachments.' );

$invalid_staged = $staged_template;
unset( $invalid_staged['fields'][1]['max_total_bytes'] );
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $invalid_staged ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_REQUIRED', $codes, true ), 'Staged mode should require a total-original-byte bound.' );

$invalid_staged = $staged_template;
$invalid_staged['fields'][1]['max_total_bytes'] = 1;
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $invalid_staged ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Staged total bytes should cover at least one allowed file.' );

$invalid_staged = $staged_template;
$invalid_staged['fields'][1]['max_file_bytes'] = PHP_INT_MAX;
$invalid_staged['fields'][1]['max_files'] = 2;
$invalid_staged['fields'][1]['max_total_bytes'] = PHP_INT_MAX;
$codes = eforms_test_collect_codes( TemplateValidator::validate_template_envelope( $invalid_staged ) );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Staged policy products must fit in a PHP integer.' );
