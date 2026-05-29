<?php
/**
 * Append-only guard for the stable code surface.
 *
 * Spec: Error handling (docs/Canonical_Spec.md#sec-error-handling)
 * Spec: Configuration (append-only machine-readable surfaces)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/bootstrap.php';

require_once __DIR__ . '/../../src/ErrorCodes.php';
require_once __DIR__ . '/../../src/ErrorMessages.php';
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
    'EFORMS_ERR_SPAM',
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

$email_send_message = ErrorMessages::message( 'EFORMS_ERR_EMAIL_SEND' );
eforms_test_assert( $email_send_message === ErrorMessages::EMAIL_SEND, 'ErrorMessages should own email failure copy.' );
eforms_test_assert( eforms_error_message( 'EFORMS_ERR_EMAIL_SEND' ) === $email_send_message, 'Public helper should delegate email failure copy.' );

$runtime_message_sources = array();
$source_needles = array(
    $email_send_message,
    str_replace( "'", "\\'", $email_send_message ),
);
$runtime_paths = array_merge(
    glob( dirname( __DIR__, 2 ) . '/src/*.php' ),
    glob( dirname( __DIR__, 2 ) . '/src/*/*.php' ),
    glob( dirname( __DIR__, 2 ) . '/templates/pages/*.php' )
);
foreach ( $runtime_paths as $path ) {
    $source = file_get_contents( $path );
    foreach ( $source_needles as $needle ) {
        if ( is_string( $source ) && strpos( $source, $needle ) !== false ) {
            $runtime_message_sources[] = basename( $path );
            break;
        }
    }
}
eforms_test_assert(
    $runtime_message_sources === array( 'ErrorMessages.php' ),
    'Runtime email failure copy should have exactly one source owner.'
);

$email_error_html = eforms_render_error( 'EFORMS_ERR_EMAIL_SEND' );
eforms_test_assert(
    strpos( $email_error_html, 'data-eforms-error="EFORMS_ERR_EMAIL_SEND"' ) !== false,
    'Rendered email failure error should expose the email-send code.'
);
eforms_test_assert(
    strpos( $email_error_html, 'We couldn&#039;t send your request right now, so it may not have reached us. Please try again in a few minutes. If the issue keeps happening, call 720.900.5278 or message us directly.' ) !== false
        || strpos( $email_error_html, $email_send_message ) !== false,
    'Rendered email failure error should use retry-oriented copy.'
);
eforms_test_assert(
    strpos( $email_error_html, 'Form configuration error.' ) === false,
    'Rendered email failure error should not use generic configuration copy.'
);

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
