<?php
/**
 * Static guard against reintroducing duplicated protocol/field-type seams.
 */

require_once __DIR__ . '/../bootstrap.php';

function eforms_protocol_guard_read( $relative ) {
    $path = dirname( __DIR__, 2 ) . '/' . ltrim( $relative, '/' );
    eforms_test_assert( is_file( $path ), 'Expected source file should exist: ' . $relative );
    $contents = file_get_contents( $path );
    eforms_test_assert( is_string( $contents ), 'Expected source file should be readable: ' . $relative );
    return $contents;
}

$template_validator = eforms_protocol_guard_read( 'src/Validation/TemplateValidator.php' );
$submit_handler = eforms_protocol_guard_read( 'src/Submission/SubmitHandler.php' );
$public_controller = eforms_protocol_guard_read( 'src/Submission/PublicRequestController.php' );
$forms_js = eforms_protocol_guard_read( 'assets/forms.js' );

eforms_test_assert( strpos( $template_validator, 'const FIELD_TYPES' ) === false, 'TemplateValidator must not own a local field type list.' );
eforms_test_assert( strpos( $template_validator, 'const RESERVED_KEYS' ) === false, 'TemplateValidator must not own a local reserved-key list.' );
eforms_test_assert( strpos( $template_validator, 'FieldTypeRegistry::is_supported' ) !== false, 'TemplateValidator should validate field types through FieldTypeRegistry.' );
eforms_test_assert( strpos( $template_validator, 'FormProtocol::reserved_field_key_map' ) !== false, 'TemplateValidator should validate reserved keys through FormProtocol.' );

eforms_test_assert( strpos( $submit_handler, 'private static function reserved_keys' ) === false, 'SubmitHandler must not own a reserved-key map.' );
eforms_test_assert( strpos( $submit_handler, 'FormProtocol::reserved_field_key_map' ) !== false, 'SubmitHandler should use FormProtocol reserved keys.' );

eforms_test_assert( strpos( $public_controller, 'private static function reserved_keys' ) === false, 'PublicRequestController must not own a reserved-key map.' );
eforms_test_assert( strpos( $public_controller, 'FormProtocol::post_detection_keys' ) !== false, 'PublicRequestController should use FormProtocol detection keys.' );
eforms_test_assert( strpos( $public_controller, 'FormProtocol::reserved_field_key_map' ) !== false, 'PublicRequestController should use FormProtocol reserved keys.' );

eforms_test_assert( strpos( $forms_js, 'settings.protocol' ) !== false, 'forms.js should consume the emitted protocol settings.' );
