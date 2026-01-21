<?php
/**
 * Unit tests for TemplateLoader (JSON decoding + version gate).
 *
 * Spec: Template JSON (docs/Canonical_Spec.md#sec-template-json)
 * Spec: Versioning & cache keys (docs/Canonical_Spec.md#sec-template-versioning)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Rendering/TemplateLoader.php';

function eforms_test_error_codes( $errors ) {
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

// Given a valid shipped template...
// When TemplateLoader loads it...
// Then it returns decoded JSON and the version string.
$result = TemplateLoader::load( 'contact' );
eforms_test_assert( $result['ok'] === true, 'TemplateLoader should load valid templates.' );
eforms_test_assert( is_array( $result['template'] ), 'TemplateLoader should return decoded template arrays.' );
eforms_test_assert( $result['version'] === '1', 'TemplateLoader should return the declared version string.' );

// Given an invalid slug...
// When TemplateLoader loads it...
// Then it returns a deterministic schema error.
$result = TemplateLoader::load( 'Bad/Slug' );
$codes  = eforms_test_error_codes( $result['errors'] );
eforms_test_assert( $result['ok'] === false, 'Invalid slugs should fail fast.' );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_KEY', $codes, true ), 'Invalid slug should map to EFORMS_ERR_SCHEMA_KEY.' );

// Given a missing template...
// When TemplateLoader loads it...
// Then it returns a deterministic schema error.
$result = TemplateLoader::load( 'missing-template' );
$codes  = eforms_test_error_codes( $result['errors'] );
eforms_test_assert( $result['ok'] === false, 'Missing templates should fail fast.' );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_REQUIRED', $codes, true ), 'Missing template should map to EFORMS_ERR_SCHEMA_REQUIRED.' );

// Given a malformed JSON file...
// When TemplateLoader loads it...
// Then it returns a deterministic schema error.
$tmp_dir = eforms_test_tmp_root( 'eforms-template' );
mkdir( $tmp_dir, 0700, true );
file_put_contents( $tmp_dir . '/bad.json', '{' );

$result = TemplateLoader::load( 'bad', $tmp_dir );
$codes  = eforms_test_error_codes( $result['errors'] );
eforms_test_assert( $result['ok'] === false, 'Malformed JSON should fail fast.' );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_OBJECT', $codes, true ), 'Malformed JSON should map to EFORMS_ERR_SCHEMA_OBJECT.' );

// Given a template with a non-string version...
// When TemplateLoader loads it...
// Then it rejects the version type.
file_put_contents( $tmp_dir . '/badversion.json', '{"id":"badversion","version":5}' );
$result = TemplateLoader::load( 'badversion', $tmp_dir );
$codes  = eforms_test_error_codes( $result['errors'] );
eforms_test_assert( $result['ok'] === false, 'Non-string version should fail fast.' );
eforms_test_assert( in_array( 'EFORMS_ERR_SCHEMA_TYPE', $codes, true ), 'Non-string version should map to EFORMS_ERR_SCHEMA_TYPE.' );

// Given a template without version...
// When TemplateLoader loads it...
// Then it falls back to filemtime().
$noversion_path = $tmp_dir . '/noversion.json';
file_put_contents( $noversion_path, '{"id":"noversion","title":"No Version","fields":[],"email":{},"success":{"mode":"inline"}}' );
$expected_mtime = (string) filemtime( $noversion_path );

$result = TemplateLoader::load( 'noversion', $tmp_dir );
eforms_test_assert( $result['ok'] === true, 'Missing version should fall back to filemtime.' );
eforms_test_assert( $result['version'] === $expected_mtime, 'Version fallback should use filemtime().' );

