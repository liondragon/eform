<?php
/**
 * Integration test for upload accept-token policy and validation.
 *
 * Spec: Uploads accept-token policy (docs/Canonical_Spec.md#sec-uploads-accept-tokens)
 * Spec: Default accept tokens callout (docs/Canonical_Spec.md#sec-uploads-accept-defaults)
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 * Spec: Uploads (docs/Canonical_Spec.md#sec-uploads)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Validation/TemplateValidator.php';
require_once __DIR__ . '/../../src/Rendering/TemplateContext.php';
require_once __DIR__ . '/../../src/Validation/Normalizer.php';
require_once __DIR__ . '/../../src/Validation/Validator.php';
require_once __DIR__ . '/../../src/Uploads/UploadPolicy.php';

if ( ! function_exists( 'eforms_test_remove_tree' ) ) {
    function eforms_test_remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
            return;
        }

        $items = array_diff( scandir( $path ), array( '.', '..' ) );
        foreach ( $items as $item ) {
            eforms_test_remove_tree( $path . '/' . $item );
        }
        @rmdir( $path );
    }
}

if ( ! function_exists( 'eforms_test_template_with_upload' ) ) {
    function eforms_test_template_with_upload( $accept_tokens = null ) {
        $field = array(
            'key' => 'upload',
            'type' => 'file',
            'label' => 'Upload',
        );

        if ( $accept_tokens !== null ) {
            $field['accept'] = $accept_tokens;
        }

        return array(
            'id' => 'demo',
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
                'include_fields' => array( 'upload' ),
            ),
            'fields' => array( $field ),
            'submit_button_text' => 'Send',
        );
    }
}

if ( ! function_exists( 'eforms_test_write_file' ) ) {
    function eforms_test_write_file( $dir, $name, $bytes ) {
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0700, true );
        }

        $path = rtrim( $dir, '/\\' ) . '/' . $name;
        file_put_contents( $path, $bytes );
        return $path;
    }
}

if ( ! function_exists( 'eforms_test_has_global_error' ) ) {
    function eforms_test_has_global_error( $errors, $code ) {
        if ( ! ( $errors instanceof Errors ) ) {
            return false;
        }

        $data = $errors->to_array();
        if ( ! isset( $data['_global'] ) || ! is_array( $data['_global'] ) ) {
            return false;
        }

        foreach ( $data['_global'] as $entry ) {
            if ( is_array( $entry ) && isset( $entry['code'] ) && $entry['code'] === $code ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'eforms_test_field_has_error' ) ) {
    function eforms_test_field_has_error( $errors, $field_key, $code ) {
        if ( ! ( $errors instanceof Errors ) ) {
            return false;
        }

        $data = $errors->to_array();
        if ( ! isset( $data[ $field_key ] ) || ! is_array( $data[ $field_key ] ) ) {
            return false;
        }

        foreach ( $data[ $field_key ] as $entry ) {
            if ( is_array( $entry ) && isset( $entry['code'] ) && $entry['code'] === $code ) {
                return true;
            }
        }

        return false;
    }
}

Config::reset_for_tests();

// Given a file field with invalid accept tokens...
// When TemplateValidator runs...
// Then it emits EFORMS_ERR_ACCEPT_EMPTY.
$invalid_template = eforms_test_template_with_upload( array( 'exe' ) );
$invalid_errors = TemplateValidator::validate_template_envelope( $invalid_template );
eforms_test_assert(
    eforms_test_has_global_error( $invalid_errors, 'EFORMS_ERR_ACCEPT_EMPTY' ),
    'Invalid accept tokens should produce EFORMS_ERR_ACCEPT_EMPTY.'
);

$tmp_dir = eforms_test_tmp_root( 'eforms-upload-accept' );
$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2Z6G0AAAAASUVORK5CYII=' );
$png_path = eforms_test_write_file( $tmp_dir, 'pixel.png', $png_bytes );
$txt_path = eforms_test_write_file( $tmp_dir, 'note.txt', 'hello' );

$template = eforms_test_template_with_upload( array( 'image' ) );
$context_result = TemplateContext::build( $template, null );
eforms_test_assert( is_array( $context_result ) && ! empty( $context_result['ok'] ), 'TemplateContext should build for upload template.' );
$context = $context_result['context'];

$finfo_available = UploadPolicy::finfo_available();

if ( $finfo_available ) {
    $files = array(
        'name' => array( 'upload' => 'pixel.png' ),
        'tmp_name' => array( 'upload' => $png_path ),
        'error' => array( 'upload' => 0 ),
        'size' => array( 'upload' => filesize( $png_path ) ),
    );
    $normalized = NormalizerStage::normalize( $context, array(), $files );
    $validated = Validator::validate( $context, $normalized );
    eforms_test_assert( $validated['ok'] === true, 'PNG upload should pass validation.' );

    $files_bad = array(
        'name' => array( 'upload' => 'note.txt' ),
        'tmp_name' => array( 'upload' => $txt_path ),
        'error' => array( 'upload' => 0 ),
        'size' => array( 'upload' => filesize( $txt_path ) ),
    );
    $normalized_bad = NormalizerStage::normalize( $context, array(), $files_bad );
    $validated_bad = Validator::validate( $context, $normalized_bad );
    eforms_test_assert( $validated_bad['ok'] === false, 'Unsupported upload should fail validation.' );
    eforms_test_assert(
        eforms_test_field_has_error( $validated_bad['errors'], 'upload', 'EFORMS_ERR_UPLOAD_TYPE' ),
        'Unsupported upload should emit EFORMS_ERR_UPLOAD_TYPE.'
    );

    if ( ! defined( 'EFORMS_FINFO_UNAVAILABLE' ) ) {
        define( 'EFORMS_FINFO_UNAVAILABLE', true );
    }

    $normalized_missing = NormalizerStage::normalize( $context, array(), $files );
    $validated_missing = Validator::validate( $context, $normalized_missing );
    eforms_test_assert( $validated_missing['ok'] === false, 'Missing fileinfo should fail validation.' );
    eforms_test_assert(
        eforms_test_has_global_error( $validated_missing['errors'], 'EFORMS_FINFO_UNAVAILABLE' ),
        'Missing fileinfo should emit EFORMS_FINFO_UNAVAILABLE.'
    );
} else {
    $files_missing = array(
        'name' => array( 'upload' => 'pixel.png' ),
        'tmp_name' => array( 'upload' => $png_path ),
        'error' => array( 'upload' => 0 ),
        'size' => array( 'upload' => filesize( $png_path ) ),
    );
    $normalized_missing = NormalizerStage::normalize( $context, array(), $files_missing );
    $validated_missing = Validator::validate( $context, $normalized_missing );
    eforms_test_assert( $validated_missing['ok'] === false, 'Missing fileinfo should fail validation.' );
    eforms_test_assert(
        eforms_test_has_global_error( $validated_missing['errors'], 'EFORMS_FINFO_UNAVAILABLE' ),
        'Missing fileinfo should emit EFORMS_FINFO_UNAVAILABLE.'
    );
}

eforms_test_remove_tree( $tmp_dir );
