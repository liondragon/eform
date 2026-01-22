<?php
/**
 * Integration test for accessibility error summary rendering.
 *
 * Spec: Accessibility (docs/Canonical_Spec.md#sec-accessibility)
 * Spec: Assets (docs/Canonical_Spec.md#sec-assets)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Errors.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return $path;
    }
}

Config::reset_for_tests();
FormRenderer::reset_for_tests();

$errors = new Errors();
$errors->add_global( 'EFORMS_ERR_TOKEN', 'Token expired.' );
$errors->add_field( 'name', 'EFORMS_ERR_SCHEMA_REQUIRED', 'Name is required.' );

$output = FormRenderer::render(
    'quote-request',
    array(
        'cacheable' => true,
        'errors' => $errors,
    )
);

eforms_test_assert( is_string( $output ), 'Renderer should return HTML.' );
eforms_test_assert(
    strpos( $output, 'class="eforms-error-summary"' ) !== false,
    'Renderer should include the error summary container.'
);
eforms_test_assert(
    strpos( $output, 'role="alert"' ) !== false,
    'Error summary should use role="alert".'
);
eforms_test_assert(
    strpos( $output, 'tabindex="-1"' ) !== false,
    'Error summary should be focusable.'
);
eforms_test_assert(
    strpos( $output, 'Token expired.' ) !== false,
    'Global error message should appear in the summary.'
);
eforms_test_assert(
    strpos( $output, 'href="#quote_request-name"' ) !== false,
    'Summary should link to the invalid control.'
);
eforms_test_assert(
    strpos( $output, 'id="error-quote_request-name"' ) !== false,
    'Field error span should be rendered.'
);
eforms_test_assert(
    strpos( $output, 'aria-invalid="true"' ) !== false,
    'Invalid field should include aria-invalid="true".'
);
eforms_test_assert(
    strpos( $output, 'aria-describedby="error-quote_request-name"' ) !== false,
    'Invalid field should reference its error via aria-describedby.'
);
eforms_test_assert(
    strpos( $output, 'for="quote_request-name"' ) !== false,
    'Field label should target the input id.'
);
eforms_test_assert(
    strpos( $output, 'class="eforms-required"' ) !== false,
    'Required fields should show the required marker.'
);
