<?php
/**
 * Unit tests for shipped template fixtures and registry completeness.
 *
 * Spec: Template validation (docs/Canonical_Spec.md#sec-template-validation)
 * Spec: Templates to include (docs/Canonical_Spec.md#sec-templates-to-include)
 * Spec: Central registries (docs/Canonical_Spec.md#sec-central-registries)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Rendering/TemplateLoader.php';
require_once __DIR__ . '/../../src/Validation/TemplateValidator.php';
require_once __DIR__ . '/../../src/Validation/FieldTypeRegistry.php';

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

function eforms_test_template_dir() {
    return dirname( __DIR__, 2 ) . '/templates/forms';
}

// Given shipped JSON templates...
// When loading and preflighting each template...
// Then every fixture passes preflight and resolves handlers.
$base_dir = eforms_test_template_dir();
$files = glob( $base_dir . '/*.json' );
if ( $files === false ) {
    $files = array();
}
sort( $files );

eforms_test_assert( ! empty( $files ), 'Shipped templates directory should include JSON fixtures.' );

$contact = $base_dir . '/contact.json';
$quote   = $base_dir . '/quote-request.json';
eforms_test_assert( in_array( $contact, $files, true ), 'contact.json must be shipped per spec.' );
eforms_test_assert( in_array( $quote, $files, true ), 'quote-request.json must be shipped per spec.' );

foreach ( $files as $path ) {
    $slug = basename( $path, '.json' );
    $result = TemplateLoader::load( $slug, $base_dir );
    eforms_test_assert( $result['ok'] === true, 'TemplateLoader should load ' . $slug . '.' );

    $template = $result['template'];
    $errors = TemplateValidator::validate_template_envelope( $template );
    $codes = eforms_test_collect_codes( $errors );
    eforms_test_assert( empty( $codes ), 'Shipped template ' . $slug . ' should pass preflight.' );

    if ( isset( $template['fields'] ) && is_array( $template['fields'] ) ) {
        foreach ( $template['fields'] as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            if ( isset( $field['type'] ) && $field['type'] === 'row_group' ) {
                continue;
            }

            $descriptor = null;
            try {
                $descriptor = FieldTypeRegistry::resolve( $field['type'] );
            } catch ( RuntimeException $exception ) {
                $descriptor = null;
            }

            eforms_test_assert(
                is_array( $descriptor ),
                'Field type ' . $field['type'] . ' should resolve for template ' . $slug . '.'
            );

            $handler_errors = new Errors();
            TemplateValidator::validate_descriptor_handlers( $descriptor, $handler_errors );
            $handler_codes = eforms_test_collect_codes( $handler_errors );
            eforms_test_assert(
                empty( $handler_codes ),
                'Handler ids should resolve for field type ' . $field['type'] . ' in template ' . $slug . '.'
            );
        }
    }
}

// Given the declared field type list...
// When resolving each declared field type...
// Then the registry resolves every built-in type.
$field_types = TemplateValidator::FIELD_TYPES;
foreach ( $field_types as $type ) {
    if ( $type === 'row_group' ) {
        continue;
    }

    $descriptor = null;
    try {
        $descriptor = FieldTypeRegistry::resolve( $type );
    } catch ( RuntimeException $exception ) {
        $descriptor = null;
    }

    eforms_test_assert( is_array( $descriptor ), 'Registry should resolve built-in type ' . $type . '.' );

    $handler_errors = new Errors();
    TemplateValidator::validate_descriptor_handlers( $descriptor, $handler_errors );
    $handler_codes = eforms_test_collect_codes( $handler_errors );
    eforms_test_assert( empty( $handler_codes ), 'Handlers should resolve for built-in type ' . $type . '.' );
}
