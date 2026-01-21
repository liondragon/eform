<?php
/**
 * Append-only guard for the stable code surface.
 *
 * Spec: Error handling (docs/Canonical_Spec.md#sec-error-handling)
 * Spec: Configuration (append-only machine-readable surfaces)
 */

require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/../../src/ErrorCodes.php';
require_once __DIR__ . '/../../src/Errors.php';

// Baseline list (append-only): this is the v1 set. Future changes may add
// codes, but must never remove or rename these.
$baseline = array(
    'EFORMS_CHALLENGE_UNCONFIGURED',
    'EFORMS_CONFIG_CLAMPED',
    'EFORMS_CONFIG_DROPIN_INVALID',
    'EFORMS_CONFIG_DROPIN_IO',
    'EFORMS_ERR_ACCEPT_EMPTY',
    'EFORMS_ERR_CHALLENGE_FAILED',
    'EFORMS_ERR_DUPLICATE_FORM_ID',
    'EFORMS_ERR_EMAIL_SEND',
    'EFORMS_ERR_HONEYPOT',
    'EFORMS_ERR_INLINE_SUCCESS_REQUIRES_NONCACHEABLE',
    'EFORMS_ERR_INVALID_FORM_ID',
    'EFORMS_ERR_LEDGER_IO',
    'EFORMS_ERR_METHOD_NOT_ALLOWED',
    'EFORMS_ERR_MINT_FAILED',
    'EFORMS_ERR_ORIGIN_FORBIDDEN',
    'EFORMS_ERR_ROW_GROUP_UNBALANCED',
    'EFORMS_ERR_SCHEMA_DUP_KEY',
    'EFORMS_ERR_SCHEMA_ENUM',
    'EFORMS_ERR_SCHEMA_KEY',
    'EFORMS_ERR_SCHEMA_OBJECT',
    'EFORMS_ERR_SCHEMA_REQUIRED',
    'EFORMS_ERR_SCHEMA_TYPE',
    'EFORMS_ERR_SCHEMA_UNKNOWN_KEY',
    'EFORMS_ERR_STORAGE_UNAVAILABLE',
    'EFORMS_ERR_THROTTLED',
    'EFORMS_ERR_TOKEN',
    'EFORMS_ERR_TYPE',
    'EFORMS_ERR_UPLOAD_TYPE',
    'EFORMS_FAIL2BAN_IO',
    'EFORMS_FINFO_UNAVAILABLE',
    'EFORMS_LEDGER_IO',
    'EFORMS_RESERVE',
);

eforms_test_assert( is_array( ErrorCodes::ALL ), 'ErrorCodes::ALL should be an array.' );

$unique = array_unique( ErrorCodes::ALL );
eforms_test_assert( count( $unique ) === count( ErrorCodes::ALL ), 'ErrorCodes::ALL must not contain duplicates.' );

foreach ( $baseline as $code ) {
    eforms_test_assert( ErrorCodes::is_known( $code ), 'Missing stable code: ' . $code );
}

// Structured error container shape: _global + per-field.
$errors = new Errors();
$errors->add_global( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'Example message.' );
$errors->add_field( 'email', 'EFORMS_ERR_SCHEMA_TYPE', 'Example field error.' );

eforms_test_assert( $errors->any(), 'Errors container should report errors.' );

$as_array = $errors->to_array();
eforms_test_assert( is_array( $as_array ), 'Errors::to_array() should return an array.' );
eforms_test_assert( isset( $as_array['_global'] ) && is_array( $as_array['_global'] ), '_global should exist and be an array.' );
eforms_test_assert( isset( $as_array['email'] ) && is_array( $as_array['email'] ), 'Field key should exist and be an array.' );

eforms_test_assert(
    isset( $as_array['_global'][0]['code'] ) && $as_array['_global'][0]['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE',
    'Global error entry should contain the error code.'
);
eforms_test_assert(
    isset( $as_array['email'][0]['code'] ) && $as_array['email'][0]['code'] === 'EFORMS_ERR_SCHEMA_TYPE',
    'Field error entry should contain the error code.'
);
