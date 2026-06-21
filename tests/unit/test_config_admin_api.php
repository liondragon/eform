<?php
/**
 * Unit tests for Config admin schema, validation, and reporting.
 *
 * Contract: Configuration.
 */

require_once __DIR__ . '/../bootstrap.php';
eforms_test_define_wp_content( 'eforms-config-admin-api' );
eforms_test_reset_options();

require_once __DIR__ . '/../../src/Config.php';

$schema = Config::admin_schema();
$expected_paths = array(
    'declined_review.enable',
    'declined_review.retention_days',
    'logging.mode',
    'logging.level',
    'logging.retention_days',
    'security.honeypot_response',
    'security.min_fill_seconds',
    'spam.soft_fail_threshold',
    'challenge.mode',
    'challenge.site_key',
    'challenge.secret_key',
    'throttle.enable',
    'throttle.per_ip.max_per_minute',
    'throttle.per_ip.cooldown_seconds',
    'privacy.ip_mode',
);

sort( $expected_paths );
$actual_paths = array_keys( $schema );
sort( $actual_paths );
eforms_test_assert( $actual_paths === $expected_paths, 'Admin schema should expose exactly the editable allowlist.' );
eforms_test_assert( ! isset( $schema['security.origin_mode'] ), 'Admin schema must not expose non-allowlisted config paths.' );
eforms_test_assert( $schema['challenge.secret_key']['secret'] === true, 'Secret key should be marked secret.' );
eforms_test_assert( $schema['declined_review.retention_days']['nullable'] === true, 'Declined retention should be nullable.' );
eforms_test_assert( $schema['logging.level']['min_anchor'] === 'LOGGING_LEVEL_MIN', 'Logging level should derive its min anchor.' );
eforms_test_assert( $schema['security.min_fill_seconds']['min_anchor'] === 'MIN_FILL_SECONDS_MIN', 'Minimum fill time should derive its min anchor.' );
eforms_test_assert( $schema['spam.soft_fail_threshold']['min'] === 1, 'Spam threshold should expose its minimum.' );

$valid = Config::validate_admin_overrides(
    array(
        'declined_review' => array(
            'enable' => true,
            'retention_days' => null,
        ),
        'logging' => array(
            'mode' => 'jsonl',
            'level' => '2',
        ),
        'security' => array(
            'honeypot_response' => 'hard_fail',
            'min_fill_seconds' => '3',
        ),
        'spam' => array(
            'soft_fail_threshold' => '3',
        ),
        'challenge' => array(
            'secret_key' => 'top-secret',
        ),
    )
);
eforms_test_assert( $valid['ok'] === true, 'Valid admin overrides should pass.' );
eforms_test_assert( $valid['overrides']['declined_review']['enable'] === true, 'Strict bool should be preserved.' );
eforms_test_assert( $valid['overrides']['declined_review']['retention_days'] === null, 'Nullable values should be preserved.' );
eforms_test_assert( $valid['overrides']['logging']['level'] === 2, 'Numeric admin ints should be normalized to int.' );
eforms_test_assert( $valid['overrides']['security']['honeypot_response'] === 'hard_fail', 'Admin enum spam settings should be preserved.' );
eforms_test_assert( $valid['overrides']['security']['min_fill_seconds'] === 3, 'Minimum fill time should save as int.' );
eforms_test_assert( $valid['overrides']['spam']['soft_fail_threshold'] === 3, 'Spam threshold should save as int.' );

$flat_valid = Config::validate_admin_flat_overrides(
    array(
        'logging.mode' => 'jsonl',
        'logging.level' => '2',
        'throttle.enable' => false,
    )
);
eforms_test_assert( $flat_valid['ok'] === true, 'Flat admin override maps should validate without caller-side unflattening.' );
eforms_test_assert( $flat_valid['overrides']['logging']['mode'] === 'jsonl', 'Flat admin validation should return nested sanitized overrides.' );
eforms_test_assert( $flat_valid['overrides']['logging']['level'] === 2, 'Flat admin validation should normalize ints.' );
eforms_test_assert( $flat_valid['overrides']['throttle']['enable'] === false, 'Flat admin validation should preserve strict bools.' );

$flat_unknown = Config::validate_admin_flat_overrides( array( 'security.origin_mode' => 'hard' ) );
eforms_test_assert( $flat_unknown['ok'] === false, 'Flat admin validation should reject non-allowlisted paths.' );
eforms_test_assert( $flat_unknown['overrides'] === array(), 'Rejected flat admin payload should not return partial overrides.' );

$unknown = Config::validate_admin_overrides( array( 'security' => array( 'origin_mode' => 'hard' ) ) );
eforms_test_assert( $unknown['ok'] === false, 'Unknown/non-allowlisted admin keys should reject the whole payload.' );
eforms_test_assert( $unknown['overrides'] === array(), 'Rejected admin payload should not return partial overrides.' );

$bad_enum = Config::validate_admin_overrides( array( 'logging' => array( 'mode' => 'verbose' ) ) );
eforms_test_assert( $bad_enum['ok'] === false, 'Invalid admin enum values should reject the whole payload.' );
eforms_test_assert( $bad_enum['errors'][0]['path'] === 'logging.mode', 'Enum errors should identify the path.' );
eforms_test_assert( $bad_enum['errors'][0]['reason'] === 'enum', 'Enum errors should identify the reason.' );

$bad_type = Config::validate_admin_overrides( array( 'throttle' => array( 'enable' => '1' ) ) );
eforms_test_assert( $bad_type['ok'] === false, 'Invalid admin bool types should reject the whole payload.' );

$masked = Config::mask_secret_value( 'challenge.secret_key', 'top-secret' );
eforms_test_assert( $masked !== 'top-secret' && $masked === '********', 'Secret masking should be centralized in Config.' );
eforms_test_assert( Config::mask_secret_value( 'challenge.secret_key', '' ) === '', 'Empty secret display should stay empty.' );
eforms_test_assert( Config::mask_secret_value( 'challenge.site_key', 'site-key' ) === 'site-key', 'Non-secret values should not be masked.' );

Config::reset_for_tests();
$report = Config::effective_report();
eforms_test_assert( isset( $report['challenge.secret_key'] ), 'Effective report should include leaf config paths.' );
eforms_test_assert( $report['challenge.secret_key']['display_value'] === '', 'Effective report should mask empty secrets as empty.' );
eforms_test_assert( $report['logging.mode']['source'] === 'default', 'Default report source should be default.' );
