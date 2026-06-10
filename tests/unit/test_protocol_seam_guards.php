<?php
/**
 * Static guard against reintroducing duplicated protocol/field-type seams.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/FormProtocol.php';

function eforms_protocol_guard_read( $relative ) {
    $path = dirname( __DIR__, 2 ) . '/' . ltrim( $relative, '/' );
    eforms_test_assert( is_file( $path ), 'Expected source file should exist: ' . $relative );
    $contents = file_get_contents( $path );
    eforms_test_assert( is_string( $contents ), 'Expected source file should be readable: ' . $relative );
    return $contents;
}

function eforms_protocol_guard_assert_protocol_owner( $contents, $constant, $path ) {
    eforms_test_assert(
        strpos( $contents, 'FormProtocol::' . $constant ) !== false,
        $path . ' should read protocol fields through FormProtocol::' . $constant . '.'
    );
}

function eforms_protocol_guard_assert_no_post_literal( $contents, $field, $path ) {
    $pattern = '/post_string\s*\(\s*\$post\s*,\s*[\'"]' . preg_quote( $field, '/' ) . '[\'"]\s*\)/';
    eforms_test_assert(
        preg_match( $pattern, $contents ) !== 1,
        $path . ' must not read protocol field "' . $field . '" through a local literal.'
    );
}

$template_validator = eforms_protocol_guard_read( 'src/Validation/TemplateValidator.php' );
$submit_handler = eforms_protocol_guard_read( 'src/Submission/SubmitHandler.php' );
$public_controller = eforms_protocol_guard_read( 'src/Submission/PublicRequestController.php' );
$form_renderer = eforms_protocol_guard_read( 'src/Rendering/FormRenderer.php' );
$security = eforms_protocol_guard_read( 'src/Security/Security.php' );
$timing_signals = eforms_protocol_guard_read( 'src/Security/TimingSignals.php' );
$normalizer = eforms_protocol_guard_read( 'src/Validation/Normalizer.php' );
$validator = eforms_protocol_guard_read( 'src/Validation/Validator.php' );
$upload_store = eforms_protocol_guard_read( 'src/Uploads/UploadStore.php' );
$emailer = eforms_protocol_guard_read( 'src/Email/Emailer.php' );
$jsonl_logger = eforms_protocol_guard_read( 'src/Logging/JsonlLogger.php' );
$fail2ban_logger = eforms_protocol_guard_read( 'src/Logging/Fail2banLogger.php' );
$forms_js = eforms_protocol_guard_read( 'assets/forms.js' );
$config_consumers = array(
    'src/Security/PostSize.php' => eforms_protocol_guard_read( 'src/Security/PostSize.php' ),
    'src/Security/Throttle.php' => eforms_protocol_guard_read( 'src/Security/Throttle.php' ),
    'src/Security/OriginPolicy.php' => eforms_protocol_guard_read( 'src/Security/OriginPolicy.php' ),
    'src/Security/TimingSignals.php' => eforms_protocol_guard_read( 'src/Security/TimingSignals.php' ),
    'src/Logging.php' => eforms_protocol_guard_read( 'src/Logging.php' ),
    'src/Privacy/ClientIp.php' => eforms_protocol_guard_read( 'src/Privacy/ClientIp.php' ),
    'src/Email/Emailer.php' => $emailer,
    'src/Logging/Fail2banLogger.php' => $fail2ban_logger,
);

eforms_test_assert( strpos( $template_validator, 'const FIELD_TYPES' ) === false, 'TemplateValidator must not own a local field type list.' );
eforms_test_assert( strpos( $template_validator, 'const RESERVED_KEYS' ) === false, 'TemplateValidator must not own a local reserved-key list.' );
eforms_test_assert( strpos( $template_validator, 'FieldTypeRegistry::is_supported' ) !== false, 'TemplateValidator should validate field types through FieldTypeRegistry.' );
eforms_test_assert( strpos( $template_validator, 'FormProtocol::reserved_field_key_map' ) !== false, 'TemplateValidator should validate reserved keys through FormProtocol.' );

eforms_test_assert( strpos( $submit_handler, 'private static function reserved_keys' ) === false, 'SubmitHandler must not own a reserved-key map.' );
eforms_test_assert( strpos( $submit_handler, 'FormProtocol::reserved_field_key_map' ) !== false, 'SubmitHandler should use FormProtocol reserved keys.' );
eforms_test_assert( strpos( $submit_handler, 'spam_short_circuit_result' ) !== false, 'SubmitHandler should share spam short-circuit branch cleanup.' );

eforms_test_assert( strpos( $public_controller, 'private static function reserved_keys' ) === false, 'PublicRequestController must not own a reserved-key map.' );
eforms_test_assert( strpos( $public_controller, 'FormProtocol::post_detection_keys' ) !== false, 'PublicRequestController should use FormProtocol detection keys.' );
eforms_test_assert( strpos( $public_controller, 'FormProtocol::reserved_field_key_map' ) !== false, 'PublicRequestController should use FormProtocol reserved keys.' );

eforms_protocol_guard_assert_protocol_owner( $security, 'FIELD_TOKEN', 'Security' );
eforms_protocol_guard_assert_protocol_owner( $security, 'FIELD_INSTANCE_ID', 'Security' );
eforms_protocol_guard_assert_protocol_owner( $security, 'FIELD_MODE', 'Security' );
eforms_protocol_guard_assert_protocol_owner( $timing_signals, 'FIELD_JS_OK', 'TimingSignals' );
eforms_protocol_guard_assert_no_post_literal( $security, FormProtocol::FIELD_TOKEN, 'Security' );
eforms_protocol_guard_assert_no_post_literal( $security, FormProtocol::FIELD_INSTANCE_ID, 'Security' );
eforms_protocol_guard_assert_no_post_literal( $security, FormProtocol::FIELD_MODE, 'Security' );
eforms_protocol_guard_assert_no_post_literal( $timing_signals, FormProtocol::FIELD_JS_OK, 'TimingSignals' );

eforms_test_assert( strpos( $form_renderer, 'last_textlike_index' ) === false, 'FormRenderer must not own a local text-like enterkeyhint list.' );
eforms_test_assert( strpos( $form_renderer, 'is_textlike_descriptor' ) === false, 'FormRenderer must not own text-like descriptor predicates.' );
eforms_test_assert( strpos( $form_renderer, 'descriptor_accepts_enterkeyhint' ) !== false, 'FormRenderer should consume descriptor enterkeyhint metadata.' );

eforms_test_assert( strpos( $normalizer, 'UploadValue::' ) !== false, 'Normalizer should route upload shape checks through UploadValue.' );
eforms_test_assert( strpos( $validator, 'UploadValue::' ) !== false, 'Validator should route upload shape checks through UploadValue.' );
eforms_test_assert( strpos( $upload_store, 'UploadValue::' ) !== false, 'UploadStore should route upload shape checks through UploadValue.' );
eforms_test_assert( strpos( $emailer, 'UploadValue::' ) !== false, 'Emailer should route upload shape checks through UploadValue.' );
eforms_test_assert( strpos( $normalizer, 'private static function is_file_item' ) === false, 'Normalizer must not keep a local upload item predicate.' );
eforms_test_assert( strpos( $normalizer, 'private static function is_no_file' ) === false, 'Normalizer must not keep a local no-file predicate.' );
eforms_test_assert( strpos( $validator, 'private static function is_upload_item' ) === false, 'Validator must not keep a local upload item predicate.' );
eforms_test_assert( strpos( $upload_store, 'private static function is_upload_item' ) === false, 'UploadStore must not keep a local upload item predicate.' );
eforms_test_assert( strpos( $emailer, 'private static function is_upload_item' ) === false, 'Emailer must not keep a local upload item predicate.' );

eforms_test_assert( strpos( $jsonl_logger, 'FileSink::append_dated_jsonl' ) !== false, 'JsonlLogger should delegate dated JSONL append mechanics to FileSink.' );
eforms_test_assert( strpos( $jsonl_logger, 'FileSink::prune_old_files' ) !== false, 'JsonlLogger should delegate generic pruning mechanics to FileSink.' );
eforms_test_assert( strpos( $fail2ban_logger, 'FileSink::append_with_rotation' ) !== false, 'Fail2banLogger should delegate locked append mechanics to FileSink.' );
eforms_test_assert( strpos( $fail2ban_logger, 'FileSink::prune_old_files' ) !== false, 'Fail2banLogger should delegate generic pruning mechanics to FileSink.' );
eforms_test_assert( strpos( $jsonl_logger, 'FILE_PREFIX' ) !== false && strpos( $jsonl_logger, 'FILE_EXT' ) !== false, 'JsonlLogger should continue owning JSONL file-family naming.' );
eforms_test_assert( strpos( $fail2ban_logger, 'next_rotated_path' ) !== false, 'Fail2banLogger should continue owning fail2ban sibling naming.' );

foreach ( $config_consumers as $path => $contents ) {
    eforms_test_assert( strpos( $contents, 'foreach ( $path' ) === false, $path . ' must not keep local array-path traversal.' );
    eforms_test_assert( strpos( $contents, 'array_key_exists( $segment' ) === false, $path . ' must not keep local array-path traversal.' );
}

eforms_test_assert( strpos( $forms_js, 'settings.protocol' ) !== false, 'forms.js should consume the emitted protocol settings.' );
