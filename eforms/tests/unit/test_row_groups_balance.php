<?php
/**
 * Unit tests for row group balance and wrapper emission.
 *
 * Spec: Row groups (docs/Canonical_Spec.md#sec-template-row-groups)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Validation/TemplateValidator.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src, $deps = array(), $ver = false ) {
        $GLOBALS['eforms_test_styles'][] = array( 'handle' => $handle, 'src' => $src );
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
        $GLOBALS['eforms_test_scripts'][] = array( 'handle' => $handle, 'src' => $src );
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return $path;
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

// Given an unmatched row_group start...
// When TemplateValidator runs...
// Then it emits EFORMS_ERR_ROW_GROUP_UNBALANCED.
$template = eforms_test_base_template();
$template['fields'] = array(
    array(
        'type' => 'row_group',
        'mode' => 'start',
    ),
    array(
        'key' => 'name',
        'type' => 'text',
        'label' => 'Name',
    ),
);
$errors = TemplateValidator::validate_template_envelope( $template );
$codes = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_ROW_GROUP_UNBALANCED', $codes, true ), 'Unbalanced row groups should emit EFORMS_ERR_ROW_GROUP_UNBALANCED.' );

// Given an unmatched row_group end...
// When TemplateValidator runs...
// Then it emits EFORMS_ERR_ROW_GROUP_UNBALANCED.
$template = eforms_test_base_template();
$template['fields'] = array(
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
$errors = TemplateValidator::validate_template_envelope( $template );
$codes = eforms_test_collect_codes( $errors );
eforms_test_assert( in_array( 'EFORMS_ERR_ROW_GROUP_UNBALANCED', $codes, true ), 'Dangling row_group ends should emit EFORMS_ERR_ROW_GROUP_UNBALANCED.' );

// Given a balanced row_group...
// When FormRenderer renders a cacheable template...
// Then it emits the wrapper with the base row class.
FormRenderer::reset_for_tests();
$output = FormRenderer::render( 'quote-request', array( 'cacheable' => true ) );
eforms_test_assert( is_string( $output ), 'Renderer should return HTML.' );
eforms_test_assert(
    strpos( $output, 'class="eforms-row columns_nomargins"' ) !== false,
    'Renderer should emit row_group wrapper with base class.'
);
