<?php
/**
 * Unit tests for TemplateContext (descriptor resolution).
 *
 * Contract: Template model
 * Contract: TemplateContext
 * Contract: Request lifecycle GET
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Rendering/TemplateContext.php';

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
}

// Given a valid template...
// When TemplateContext builds with a version override...
// Then it resolves descriptors with callable handlers.
$template = eforms_test_base_template();
$result   = TemplateContext::build( $template, 'v1' );
eforms_test_assert( $result['ok'] === true, 'TemplateContext should accept valid templates.' );
eforms_test_assert( $result['errors'] instanceof Errors, 'TemplateContext should return an Errors container.' );

$context = $result['context'];
eforms_test_assert( is_array( $context ), 'TemplateContext should return a context array.' );
eforms_test_assert( $context['id'] === 'demo_form', 'TemplateContext should preserve the template id.' );
eforms_test_assert( $context['version'] === 'v1', 'TemplateContext should honor the version override.' );
eforms_test_assert( $context['has_synchronous_uploads'] === false, 'TemplateContext should report no synchronous upload transport for text-only templates.' );
eforms_test_assert( $context['staged_field'] === null, 'TemplateContext should expose no staged field for text-only templates.' );

$descriptors = $context['descriptors'];
eforms_test_assert( is_array( $descriptors ), 'TemplateContext should expose descriptors as an array.' );
eforms_test_assert( count( $descriptors ) === count( $template['fields'] ), 'Descriptor count should match field count.' );

$descriptor = $descriptors[0];
eforms_test_assert( $descriptor['key'] === 'name', 'Descriptor key should match the field key.' );
eforms_test_assert( $descriptor['name_tpl'] === 'demo_form[{key}]', 'Descriptor name template should include the form id.' );
eforms_test_assert( $descriptor['id_prefix'] === 'demo_form', 'Descriptor id_prefix should match the form id.' );
eforms_test_assert( isset( $descriptor['handlers']['v'] ) && is_callable( $descriptor['handlers']['v'] ), 'Validator handler should resolve to a callable.' );
eforms_test_assert( isset( $descriptor['handlers']['n'] ) && is_callable( $descriptor['handlers']['n'] ), 'Normalizer handler should resolve to a callable.' );
eforms_test_assert( isset( $descriptor['handlers']['r'] ) && is_callable( $descriptor['handlers']['r'] ), 'Renderer handler should resolve to a callable.' );

$expected_vars = count( $descriptors );
eforms_test_assert(
    $context['max_input_vars_estimate'] === $expected_vars,
    'max_input_vars_estimate should reflect the number of rendered fields.'
);

// Given a valid template without override...
// When TemplateContext builds...
// Then it uses the template version string.
$result = TemplateContext::build( $template );
$codes  = eforms_test_collect_codes( $result['errors'] );
eforms_test_assert( $result['ok'] === true, 'TemplateContext should accept valid templates without overrides.' );
eforms_test_assert( empty( $codes ), 'TemplateContext should not emit errors for valid templates.' );
eforms_test_assert( $result['context']['version'] === '1', 'TemplateContext should use the template version when provided.' );

$staged_template = eforms_test_base_template();
$staged_template['id'] = 'demo_staged';
$staged_template['version'] = '2';
$staged_template['fields'][] = array(
    'key' => 'photos',
    'type' => 'files',
    'accept' => array( 'image' ),
    'upload_mode' => 'staged',
    'max_file_bytes' => 1048576,
    'max_files' => 2,
    'max_total_bytes' => 2097152,
);
$staged_template['email']['include_fields'][] = 'photos';
$staged_result = TemplateContext::build( $staged_template );
eforms_test_assert( $staged_result['ok'] === true, 'TemplateContext should accept one staged field.' );
eforms_test_assert( $staged_result['context']['staged_field']['key'] === 'photos', 'TemplateContext should expose the single validated staged field.' );

$max_staged_template = $staged_template;
$max_staged_template['fields'][1]['max_files'] = Anchors::get( 'MANAGED_STAGED_MAX_FILES' );
$max_staged_template['fields'][1]['max_total_bytes'] = $max_staged_template['fields'][1]['max_file_bytes'] * $max_staged_template['fields'][1]['max_files'];
$max_staged_result = TemplateContext::build( $max_staged_template );
eforms_test_assert( $max_staged_result['ok'] === true, 'TemplateContext should accept the Anchor-owned staged file ceiling.' );

$too_many_staged_template = $max_staged_template;
$too_many_staged_template['fields'][1]['max_files'] = Anchors::get( 'MANAGED_STAGED_MAX_FILES' ) + 1;
$too_many_staged_template['fields'][1]['max_total_bytes'] = $too_many_staged_template['fields'][1]['max_file_bytes'] * $too_many_staged_template['fields'][1]['max_files'];
$too_many_staged_result = TemplateContext::build( $too_many_staged_template );
$too_many_staged_errors = $too_many_staged_result['errors']->to_array();
eforms_test_assert( $too_many_staged_result['ok'] === false, 'TemplateContext should reject staged max_files above the Anchor-owned ceiling.' );
eforms_test_assert(
    isset( $too_many_staged_errors['_global'][0]['message'] )
        && $too_many_staged_errors['_global'][0]['message'] === sprintf( 'Staged upload max_files must be %d or fewer.', Anchors::get( 'MANAGED_STAGED_MAX_FILES' ) ),
    'TemplateContext should return actionable staged max_files guidance.'
);
