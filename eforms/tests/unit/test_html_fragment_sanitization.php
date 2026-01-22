<?php
/**
 * Unit tests for HTML fragment sanitization and enforcement.
 *
 * Spec: Template model (docs/Canonical_Spec.md#sec-template-model)
 * Spec: HTML-bearing fields (docs/Canonical_Spec.md#sec-html-fields)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Validation/TemplateValidator.php';

if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $value ) {
        return '[sanitized]' . $value;
    }
}

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

// Given a fragment with inline styles...
// When TemplateValidator runs...
// Then it rejects the fragment.
$template = eforms_test_base_template();
$template['fields'][0]['before_html'] = '<p style="color:red">Hi</p>';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Inline styles should be rejected.' );

// Given a fragment that leaves a div unclosed...
// When TemplateValidator runs...
// Then it rejects the fragment.
$template = eforms_test_base_template();
$template['fields'][0]['before_html'] = '<div>';
$errors = TemplateValidator::validate_template_envelope( $template );
$codes = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Fragments should not cross row_group boundaries.' );

// Given a fragment with markup...
// When TemplateValidator sanitizes fields...
// Then the sanitized HTML becomes canonical.
$fields = array(
    array(
        'key' => 'name',
        'type' => 'text',
        'label' => 'Name',
        'before_html' => '<strong>Hi</strong>',
    ),
);
$sanitized = TemplateValidator::sanitize_fields( $fields );
$before_html = $sanitized[0]['before_html'];
eforms_test_assert(
    $before_html === '[sanitized]<strong>Hi</strong>',
    'Sanitized HTML should be used as the canonical fragment.'
);
