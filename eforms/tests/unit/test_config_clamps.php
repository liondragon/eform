<?php
/**
 * Unit tests for Config numeric clamping.
 *
 * Spec: Anchors (docs/Canonical_Spec.md#sec-anchors); Configuration (docs/Canonical_Spec.md#sec-configuration).
 */

require_once __DIR__ . '/../bootstrap.php';

eforms_test_define_wp_content( 'eforms-config-clamp' );

require_once __DIR__ . '/../../src/Config.php';

$anchors  = Anchors::VALUES;
$required = array(
    'MIN_FILL_SECONDS_MIN',
    'MIN_FILL_SECONDS_MAX',
    'TOKEN_TTL_MIN',
    'TOKEN_TTL_MAX',
    'MAX_FORM_AGE_MIN',
    'MAX_FORM_AGE_MAX',
    'CHALLENGE_TIMEOUT_MIN',
    'CHALLENGE_TIMEOUT_MAX',
    'THROTTLE_MAX_PER_MIN_MIN',
    'THROTTLE_MAX_PER_MIN_MAX',
    'THROTTLE_COOLDOWN_MIN',
    'THROTTLE_COOLDOWN_MAX',
    'LOGGING_LEVEL_MIN',
    'LOGGING_LEVEL_MAX',
    'RETENTION_DAYS_MIN',
    'RETENTION_DAYS_MAX',
    'MAX_FIELDS_MIN',
    'MAX_FIELDS_MAX',
    'MAX_OPTIONS_MIN',
    'MAX_OPTIONS_MAX',
    'MAX_MULTIVALUE_MIN',
    'MAX_MULTIVALUE_MAX',
);
foreach ( $required as $anchor ) {
    eforms_test_assert( array_key_exists( $anchor, $anchors ), 'Missing anchor: ' . $anchor );
}

$dropin_path = WP_CONTENT_DIR . '/' . Config::DROPIN_FILENAME;

$override = array(
    'security'  => array(
        'min_fill_seconds'     => $anchors['MIN_FILL_SECONDS_MIN'] - $anchors['MIN_FILL_SECONDS_MAX'],
        'token_ttl_seconds'    => $anchors['TOKEN_TTL_MAX'] + $anchors['TOKEN_TTL_MAX'],
        'max_form_age_seconds' => $anchors['MAX_FORM_AGE_MIN'] - $anchors['MAX_FORM_AGE_MAX'],
    ),
    'challenge' => array(
        'http_timeout_seconds' => $anchors['CHALLENGE_TIMEOUT_MAX'] + $anchors['CHALLENGE_TIMEOUT_MAX'],
    ),
    'throttle'  => array(
        'per_ip' => array(
            'max_per_minute'   => $anchors['THROTTLE_MAX_PER_MIN_MIN'] - $anchors['THROTTLE_MAX_PER_MIN_MAX'],
            'cooldown_seconds' => $anchors['THROTTLE_COOLDOWN_MAX'] + $anchors['THROTTLE_COOLDOWN_MAX'],
        ),
    ),
    'logging'   => array(
        'level'          => $anchors['LOGGING_LEVEL_MAX'] + $anchors['LOGGING_LEVEL_MAX'],
        'retention_days' => $anchors['RETENTION_DAYS_MIN'] - $anchors['RETENTION_DAYS_MAX'],
        'fail2ban'       => array(
            'retention_days' => $anchors['RETENTION_DAYS_MAX'] + $anchors['RETENTION_DAYS_MAX'],
        ),
    ),
    'validation' => array(
        'max_fields_per_form'     => $anchors['MAX_FIELDS_MAX'] + $anchors['MAX_FIELDS_MAX'],
        'max_options_per_group'   => $anchors['MAX_OPTIONS_MIN'] - $anchors['MAX_OPTIONS_MAX'],
        'max_items_per_multivalue' => $anchors['MAX_MULTIVALUE_MAX'] + $anchors['MAX_MULTIVALUE_MAX'],
    ),
);

$dropin = "<?php\nreturn " . var_export( $override, true ) . ";\n";
file_put_contents( $dropin_path, $dropin );

Logging::reset();
Config::reset_for_tests();
$config = Config::get();

eforms_test_assert( $config['security']['min_fill_seconds'] === $anchors['MIN_FILL_SECONDS_MIN'], 'min_fill_seconds should clamp to min.' );
eforms_test_assert( $config['security']['token_ttl_seconds'] === $anchors['TOKEN_TTL_MAX'], 'token_ttl_seconds should clamp to max.' );
eforms_test_assert( $config['security']['max_form_age_seconds'] === $anchors['MAX_FORM_AGE_MIN'], 'max_form_age_seconds should clamp to min.' );

eforms_test_assert( $config['challenge']['http_timeout_seconds'] === $anchors['CHALLENGE_TIMEOUT_MAX'], 'http_timeout_seconds should clamp to max.' );

eforms_test_assert( $config['throttle']['per_ip']['max_per_minute'] === $anchors['THROTTLE_MAX_PER_MIN_MIN'], 'max_per_minute should clamp to min.' );
eforms_test_assert( $config['throttle']['per_ip']['cooldown_seconds'] === $anchors['THROTTLE_COOLDOWN_MAX'], 'cooldown_seconds should clamp to max.' );

eforms_test_assert( $config['logging']['level'] === $anchors['LOGGING_LEVEL_MAX'], 'logging.level should clamp to max.' );
eforms_test_assert( $config['logging']['retention_days'] === $anchors['RETENTION_DAYS_MIN'], 'logging.retention_days should clamp to min.' );
eforms_test_assert( $config['logging']['fail2ban']['retention_days'] === $anchors['RETENTION_DAYS_MAX'], 'logging.fail2ban.retention_days should clamp to max.' );

eforms_test_assert( $config['validation']['max_fields_per_form'] === $anchors['MAX_FIELDS_MAX'], 'max_fields_per_form should clamp to max.' );
eforms_test_assert( $config['validation']['max_options_per_group'] === $anchors['MAX_OPTIONS_MIN'], 'max_options_per_group should clamp to min.' );
eforms_test_assert( $config['validation']['max_items_per_multivalue'] === $anchors['MAX_MULTIVALUE_MAX'], 'max_items_per_multivalue should clamp to max.' );

unlink( $dropin_path );
