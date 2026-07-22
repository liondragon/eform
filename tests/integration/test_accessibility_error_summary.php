<?php
/**
 * Integration test for accessibility error summary rendering.
 *
 * Contract: Accessibility
 * Contract: Assets
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
$errors->add_field( 'name', 'EFORMS_ERR_FIELD_REQUIRED' );
$errors->add_field( 'email', 'EFORMS_ERR_FIELD_INVALID' );

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
    strpos( $output, 'href="#quote-request-name"' ) !== false,
    'Summary should link to the invalid control.'
);
eforms_test_assert(
    strpos( $output, '<a href="#quote-request-name">Please complete Your Name.</a>' ) !== false,
    'Summary should show the field error text and link to the invalid control.'
);
eforms_test_assert(
    strpos( $output, '<span id="error-quote-request-name" class="eforms-error eforms-field-error" data-eforms-field-key="name" data-eforms-field-error-mount="1">Please complete Your Name.</span>' ) !== false
        && strpos( $output, '<span class="screen-reader-text">Please complete Your Name.</span>' ) === false
        && strpos( $output, 'eforms-error-icon' ) === false
        && strpos( $output, 'data-eforms-field-error-icon' ) === false,
    'Required field mount should expose visible accessible copy without duplicate screen-reader-only copy or dead icon markers.'
);
eforms_test_assert(
    strpos( $output, '<a href="#quote-request-email">Email address must be a valid email address.</a>' ) !== false
        && strpos( $output, 'Please check this field.' ) === false,
    'Invalid field summary should identify the field and known concern without generic fallback copy.'
);
eforms_test_assert(
    strpos( $output, 'id="error-quote-request-name"' ) !== false,
    'Field error span should be rendered.'
);
eforms_test_assert(
    strpos( $output, 'aria-invalid="true"' ) !== false,
    'Invalid field should include aria-invalid="true".'
);
eforms_test_assert(
    strpos( $output, 'aria-describedby="error-quote-request-name"' ) !== false,
    'Invalid field should reference its error via aria-describedby.'
);
eforms_test_assert(
    strpos( $output, FormProtocol::DATA_FIELD_KEY . '="name"' ) !== false
        && strpos( $output, FormProtocol::DATA_FIELD_CONTROL . '="1"' ) !== false
        && strpos( $output, FormProtocol::DATA_FIELD_ERROR_MOUNT . '="1"' ) !== false,
    'Renderer should expose protocol-named field, control, and error mounts without reconstructing identifiers.'
);
eforms_test_assert(
    strpos( $output, 'for="quote-request-name"' ) !== false,
    'Field label should target the input id.'
);
eforms_test_assert(
    strpos( $output, 'class="eforms-required"' ) !== false,
    'Required fields should show the required marker.'
);

FormRenderer::reset_for_tests();
$clean_output = FormRenderer::render( 'quote-request', array( 'cacheable' => true ) );
eforms_test_assert(
    preg_match( '/<input[^>]*' . preg_quote( FormProtocol::DATA_FIELD_CONTROL, '/' ) . '="1"[^>]*>\s*<span[^>]*' . preg_quote( FormProtocol::DATA_FIELD_ERROR_MOUNT, '/' ) . '="1"[^>]*hidden="hidden"/', $clean_output ) === 1,
    'Fresh renders should keep one initially hidden error mount as the control sibling without a layout wrapper.'
);
