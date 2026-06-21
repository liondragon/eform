<?php
/**
 * Unit tests for Config schema validation.
 *
 * Contract: Configuration.
 */

require_once __DIR__ . '/../bootstrap.php';
eforms_test_define_wp_content( 'eforms-config-validate' );

require_once __DIR__ . '/../../src/Config.php';

$dropin_path = WP_CONTENT_DIR . '/' . Config::DROPIN_FILENAME;

$write_dropin = function ($override_array) use ($dropin_path) {
    $content = "<?php\nreturn " . var_export($override_array, true) . ";\n";
    file_put_contents($dropin_path, $content);
};

$remove_dropin = function () use ($dropin_path) {
    if (file_exists($dropin_path)) {
        unlink($dropin_path);
    }
};

$defaults = Config::defaults();

// Case 1: invalid enum falls back per-key; valid key still applies; warning emitted.
Logging::reset_for_tests();
$write_dropin(array(
    'security' => array(
        'origin_mode' => 'bogus',
        'origin_missing_hard' => true,
    ),
));
Config::reset_for_tests();
$config = Config::get();

eforms_test_assert($config['security']['origin_mode'] === $defaults['security']['origin_mode'], 'Invalid enum should fall back to defaults.' );
eforms_test_assert($config['security']['origin_missing_hard'] === true, 'Valid bool override should apply.' );

eforms_test_assert(count(Logging::$events) >= 1, 'Drop-in schema error should emit a warning.' );
$found = false;
foreach (Logging::$events as $event) {
    if ($event['code'] === 'EFORMS_CONFIG_DROPIN_INVALID' && isset($event['meta']['path']) && $event['meta']['path'] === 'security.origin_mode') {
        $found = true;
        eforms_test_assert($event['meta']['reason'] === 'enum', 'Enum failures should be tagged with reason=enum.' );
    }
}

eforms_test_assert($found, 'Expected schema warning for security.origin_mode.' );

// Case 2: invalid bool type falls back; warning emitted.
Logging::reset_for_tests();
$write_dropin(array(
    'security' => array(
        'js_hard_mode' => '1',
    ),
));
Config::reset_for_tests();
$config = Config::get();

eforms_test_assert($config['security']['js_hard_mode'] === $defaults['security']['js_hard_mode'], 'Invalid bool should fall back to defaults.' );
$found = false;
foreach (Logging::$events as $event) {
    if ($event['code'] === 'EFORMS_CONFIG_DROPIN_INVALID' && isset($event['meta']['path']) && $event['meta']['path'] === 'security.js_hard_mode') {
        $found = true;
        eforms_test_assert($event['meta']['reason'] === 'type', 'Type failures should be tagged with reason=type.' );
    }
}

eforms_test_assert($found, 'Expected schema warning for security.js_hard_mode.' );

// Case 3: invalid object type for nested section falls back; other keys still apply.
Logging::reset_for_tests();
$write_dropin(array(
    'throttle' => array(
        'enable' => true,
        'per_ip' => 'nope',
    ),
));
Config::reset_for_tests();
$config = Config::get();

eforms_test_assert($config['throttle']['enable'] === true, 'Valid throttle.enable should apply.' );
eforms_test_assert($config['throttle']['per_ip'] === $defaults['throttle']['per_ip'], 'Invalid nested object should fall back to defaults.' );
$found = false;
foreach (Logging::$events as $event) {
    if ($event['code'] === 'EFORMS_CONFIG_DROPIN_INVALID' && isset($event['meta']['path']) && $event['meta']['path'] === 'throttle.per_ip') {
        $found = true;
        eforms_test_assert($event['meta']['reason'] === 'type', 'Nested type failures should be tagged with reason=type.' );
    }
}

eforms_test_assert($found, 'Expected schema warning for throttle.per_ip.' );

// Case 4: challenge.mode accepts only canonical enum values.
Logging::reset_for_tests();
$write_dropin(array(
    'challenge' => array(
        'mode' => 'always',
    ),
));
Config::reset_for_tests();
$config = Config::get();

eforms_test_assert($config['challenge']['mode'] === $defaults['challenge']['mode'], 'Non-canonical challenge modes should fall back to defaults.' );
$found = false;
foreach (Logging::$events as $event) {
    if ($event['code'] === 'EFORMS_CONFIG_DROPIN_INVALID' && isset($event['meta']['path']) && $event['meta']['path'] === 'challenge.mode') {
        $found = true;
        eforms_test_assert($event['meta']['reason'] === 'enum', 'Non-canonical challenge modes should be tagged with reason=enum.' );
    }
}

eforms_test_assert($found, 'Expected schema warning for challenge.mode.' );

// Case 5: filter invalid values are sanitized (no drop-in warning requirement).
$remove_dropin();
Logging::reset_for_tests();
eforms_test_set_filter(
    'eforms_config',
    function ( $config_in ) {
        $config_in['logging']['mode'] = 123;
        return $config_in;
    }
);

Config::reset_for_tests();
$config = Config::get();
eforms_test_assert($config['logging']['mode'] === $defaults['logging']['mode'], 'Invalid filter value should fall back to defaults.' );
eforms_test_assert(count(Logging::$events) === 0, 'Filter-derived schema errors should not be logged as drop-in errors.' );
eforms_test_set_filter( 'eforms_config', null );

// Case 6: shared lookup helpers are array-path based and bool reads remain strict.
$sample = array(
    'feature' => array(
        'enabled' => true,
        'disabled' => false,
        'truthy_string' => '1',
        'truthy_int' => 1,
        'null_value' => null,
    ),
);
eforms_test_assert( Config::value( $sample, array( 'feature', 'enabled' ), 'fallback' ) === true, 'Config::value should read nested array paths.' );
eforms_test_assert( Config::value( $sample, array( 'feature', 'missing' ), 'fallback' ) === 'fallback', 'Config::value should return fallback for missing paths.' );
eforms_test_assert( Config::value( $sample, array( 'feature', 'null_value' ), 'fallback' ) === null, 'Config::value should preserve explicit null values.' );
eforms_test_assert( Config::value( $sample, 'feature.enabled', 'fallback' ) === 'fallback', 'Config::value should require array paths.' );
eforms_test_assert( Config::has_path( $sample, array( 'feature', 'null_value' ) ) === true, 'Config::has_path should detect explicit null values.' );
eforms_test_assert( Config::has_path( $sample, array( 'feature', 'missing' ) ) === false, 'Config::has_path should return false for missing paths.' );
eforms_test_assert( Config::bool( $sample, array( 'feature', 'enabled' ), false ) === true, 'Config::bool should accept actual true.' );
eforms_test_assert( Config::bool( $sample, array( 'feature', 'disabled' ), true ) === false, 'Config::bool should accept actual false.' );
eforms_test_assert( Config::bool( $sample, array( 'feature', 'truthy_string' ), false ) === false, 'Config::bool must reject truthy strings.' );
eforms_test_assert( Config::bool( $sample, array( 'feature', 'truthy_int' ), true ) === true, 'Config::bool should return fallback for truthy integers.' );
eforms_test_assert( Config::bool( $sample, array( 'feature', 'missing' ), true ) === true, 'Config::bool should return boolean fallback for missing paths.' );

// Case 7: declined_review.retention_days null materializes to logging.retention_days after clamps.
$remove_dropin();
Logging::reset_for_tests();
eforms_test_set_filter(
    'eforms_config',
    function ( $config_in ) {
        $config_in['logging']['retention_days'] = 12;
        $config_in['declined_review']['enable'] = true;
        $config_in['declined_review']['retention_days'] = null;
        return $config_in;
    }
);

Config::reset_for_tests();
$config = Config::get();
eforms_test_assert( $config['declined_review']['enable'] === true, 'declined_review.enable should accept a strict bool.' );
eforms_test_assert( $config['declined_review']['retention_days'] === 12, 'Null declined retention should materialize to logging.retention_days.' );
eforms_test_set_filter( 'eforms_config', null );

$remove_dropin();
