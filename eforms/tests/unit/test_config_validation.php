<?php
/**
 * Unit tests for Config schema validation.
 *
 * Spec: Configuration (docs/Canonical_Spec.md#sec-configuration).
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
Logging::reset();
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
Logging::reset();
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
Logging::reset();
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

// Case 4: filter invalid values are sanitized (no drop-in warning requirement).
$remove_dropin();
Logging::reset();
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

$remove_dropin();
