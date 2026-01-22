<?php
/**
 * Unit tests for Normalize stage behavior.
 *
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Helpers.php';
require_once __DIR__ . '/../../src/Validation/Normalizer.php';
require_once __DIR__ . '/../../src/Validation/NormalizerRegistry.php';

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $entry ) {
                $out[ $key ] = wp_unslash( $entry );
            }
            return $out;
        }

        if ( is_string( $value ) ) {
            return stripslashes( $value );
        }

        return $value;
    }
}

$text_handler = NormalizerRegistry::resolve( 'text' );
$choice_handler = NormalizerRegistry::resolve( 'choice' );

$context = array(
    'descriptors' => array(
        array(
            'key' => 'name',
            'type' => 'text',
            'is_multivalue' => false,
            'handlers' => array( 'n' => $text_handler ),
        ),
        array(
            'key' => 'single_array',
            'type' => 'text',
            'is_multivalue' => false,
            'handlers' => array( 'n' => $text_handler ),
        ),
        array(
            'key' => 'tags',
            'type' => 'text',
            'is_multivalue' => true,
            'handlers' => array( 'n' => $text_handler ),
        ),
        array(
            'key' => 'choice',
            'type' => 'select',
            'is_multivalue' => false,
            'handlers' => array( 'n' => $choice_handler ),
        ),
        array(
            'key' => 'upload',
            'type' => 'file',
            'is_multivalue' => false,
            'handlers' => array( 'n' => $text_handler ),
        ),
        array(
            'key' => 'uploads',
            'type' => 'files',
            'is_multivalue' => true,
            'handlers' => array( 'n' => $text_handler ),
        ),
        array(
            'key' => 'upload_empty',
            'type' => 'file',
            'is_multivalue' => false,
            'handlers' => array( 'n' => $text_handler ),
        ),
    ),
);

$post = array(
    'name' => "  O\\'Reilly\r\nLine\rMore  ",
    'single_array' => array( '  Alpha  ', '' ),
    'tags' => array( ' one ', '', null, "two\r\n" ),
    'choice' => ' option_a ',
);

$files = array(
    'name' => array(
        'upload' => "C:\\temp\\my\r\n file..txt",
        'uploads' => array( 'a.txt', '' ),
        'upload_empty' => '',
    ),
    'tmp_name' => array(
        'upload' => '/tmp/php123',
        'uploads' => array( '/tmp/phpa', '' ),
        'upload_empty' => '',
    ),
    'error' => array(
        'upload' => 0,
        'uploads' => array( 0, UPLOAD_ERR_NO_FILE ),
        'upload_empty' => UPLOAD_ERR_NO_FILE,
    ),
    'size' => array(
        'upload' => 12,
        'uploads' => array( 3, 0 ),
        'upload_empty' => 0,
    ),
);

$result = NormalizerStage::normalize( $context, $post, $files );
$values = isset( $result['values'] ) ? $result['values'] : array();

// Given text input with slashes and CRLF...
// When normalization runs...
// Then it unslashes, trims, and normalizes line endings.
eforms_test_assert(
    isset( $values['name'] ) && $values['name'] === "O'Reilly\nLine\nMore",
    'Text normalization should unslash, trim, and normalize line endings.'
);

// Given a single-value field that received an array...
// When normalization runs...
// Then it preserves the array for Validate to reject deterministically.
eforms_test_assert(
    isset( $values['single_array'] ) && is_array( $values['single_array'] ) && $values['single_array'][0] === 'Alpha',
    'Single-value arrays should be preserved.'
);

// Given a multivalue field with empty entries...
// When normalization runs...
// Then empty-string and null entries are discarded.
eforms_test_assert(
    isset( $values['tags'] ) && $values['tags'] === array( 'one', 'two' ),
    'Multivalue arrays should discard empty entries and trim items.'
);

// Given a choice input with surrounding whitespace...
// When normalization runs...
// Then whitespace is trimmed without rejection.
eforms_test_assert(
    isset( $values['choice'] ) && $values['choice'] === 'option_a',
    'Choice values should be trimmed during normalization.'
);

// Given upload data with paths and control characters...
// When normalization runs...
// Then it strips paths and sanitizes original_name_safe.
eforms_test_assert(
    isset( $values['upload'] )
        && is_array( $values['upload'] )
        && isset( $values['upload']['original_name_safe'] )
        && $values['upload']['original_name_safe'] === 'my file.txt',
    'Upload original_name_safe should be sanitized and path-free.'
);

// Given multivalue uploads with a no-file entry...
// When normalization runs...
// Then no-file entries are removed.
eforms_test_assert(
    isset( $values['uploads'] ) && $values['uploads'] === array(
        array(
            'tmp_name' => '/tmp/phpa',
            'original_name' => 'a.txt',
            'size' => 3,
            'error' => 0,
            'original_name_safe' => 'a.txt',
        ),
    ),
    'Upload arrays should drop UPLOAD_ERR_NO_FILE entries.'
);

// Given a single upload with no file...
// When normalization runs...
// Then it is treated as no value.
eforms_test_assert(
    array_key_exists( 'upload_empty', $values ) && $values['upload_empty'] === null,
    'UPLOAD_ERR_NO_FILE should be treated as no value.'
);
