<?php
/**
 * Unit tests for admin settings option persistence and config precedence.
 *
 * Contract: Configuration.
 */

require_once __DIR__ . '/../bootstrap.php';
eforms_test_define_wp_content( 'eforms-admin-settings-store' );
eforms_test_reset_options();

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Admin/AdminSettingsStore.php';

$dropin_path = WP_CONTENT_DIR . '/' . Config::DROPIN_FILENAME;

$write_dropin = function ( $override_array ) use ( $dropin_path ) {
    file_put_contents( $dropin_path, "<?php\nreturn " . var_export( $override_array, true ) . ";\n" );
};

$remove_dropin = function () use ( $dropin_path ) {
    if ( file_exists( $dropin_path ) ) {
        unlink( $dropin_path );
    }
};

$reset = function () use ( $remove_dropin ) {
    $remove_dropin();
    eforms_test_reset_options();
    eforms_test_set_filter( 'eforms_config', null );
    Config::reset_for_tests();
};

$reset();
$saved = AdminSettingsStore::replace_overrides(
    array(
        'logging' => array(
            'mode' => 'jsonl',
        ),
    )
);
eforms_test_assert( $saved['ok'] === true, 'Valid admin overrides should save.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, array() ) === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Store should persist sanitized overrides.' );
eforms_test_assert( $GLOBALS['eforms_test_option_autoload'][ AdminSettingsStore::OPTION_NAME ] === 'no', 'First option creation should request autoload disabled.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Store should read saved overrides.' );

$invalid = AdminSettingsStore::replace_overrides( array( 'security' => array( 'origin_mode' => 'hard' ) ) );
eforms_test_assert( $invalid['ok'] === false, 'Store should reject unknown admin override keys.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, array() ) === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Rejected save should not partially replace existing option.' );

$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => 'jsonl' ) ) );
Config::reset_for_tests();
$config = Config::get();
eforms_test_assert( $config['logging']['mode'] === 'jsonl', 'Admin option should affect Config::get().' );
$report = Config::effective_report();
eforms_test_assert( $report['logging.mode']['source'] === 'admin option', 'Admin-only override should report admin option source.' );

$reset();
$defaults = Config::defaults();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => $defaults['logging']['mode'] ) ) );
Config::reset_for_tests();
$report = Config::effective_report();
eforms_test_assert( $report['logging.mode']['source'] === 'admin option', 'Equal-value admin override should still report admin option source.' );

$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => 'jsonl' ) ) );
$write_dropin( array( 'logging' => array( 'mode' => 'minimal' ) ) );
Config::reset_for_tests();
$config = Config::get();
$report = Config::effective_report();
eforms_test_assert( $config['logging']['mode'] === 'minimal', 'Drop-in should override admin option.' );
eforms_test_assert( $report['logging.mode']['source'] === 'config file', 'Drop-in override should report config file source.' );
eforms_test_assert( $report['logging.mode']['externally_controlled'] === true, 'Drop-in-controlled admin fields should report externally controlled.' );

$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => 'jsonl' ) ) );
$write_dropin( array( 'logging' => array( 'mode' => 'minimal' ) ) );
eforms_test_set_filter(
    'eforms_config',
    function ( $config_in ) {
        $config_in['logging']['mode'] = 'off';
        return $config_in;
    }
);
Config::reset_for_tests();
$config = Config::get();
$report = Config::effective_report();
eforms_test_assert( $config['logging']['mode'] === 'off', 'Filter should override admin option and drop-in.' );
eforms_test_assert( $report['logging.mode']['source'] === 'filter', 'Filter override should report filter source.' );
eforms_test_assert( $report['challenge.mode']['source'] === 'default', 'Filter provenance should not mark unchanged paths as filter-owned.' );
eforms_test_assert( $report['challenge.mode']['externally_controlled'] === false, 'Unchanged admin fields should remain editable when a filter changes another path.' );

$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => 'jsonl' ) ) );
eforms_test_set_filter(
    'eforms_config',
    function ( $config_in ) {
        return $config_in;
    }
);
Config::reset_for_tests();
$report = Config::effective_report();
eforms_test_assert( $report['logging.mode']['source'] === 'admin option', 'No-op filters should preserve existing admin provenance.' );
eforms_test_assert( $report['challenge.mode']['source'] === 'default', 'No-op filters should preserve default provenance.' );

$reset();
$write_dropin( array( 'logging' => array( 'level' => 999 ) ) );
Config::reset_for_tests();
$config = Config::get();
$report = Config::effective_report();
eforms_test_assert( $config['logging']['level'] === Anchors::get( 'LOGGING_LEVEL_MAX' ), 'Drop-in numeric values should still clamp through Config.' );
eforms_test_assert( $report['logging.level']['source'] === 'config file', 'Clamped drop-in values should preserve external provenance.' );
eforms_test_assert( $report['logging.level']['externally_controlled'] === true, 'Clamped drop-in fields should remain externally controlled.' );

$reset();
update_option( AdminSettingsStore::OPTION_NAME, array( 'logging' => array( 'mode' => 'verbose' ) ), false );
Config::reset_for_tests();
$config = Config::get();
eforms_test_assert( $config['logging']['mode'] === Config::defaults()['logging']['mode'], 'Invalid persisted admin option should fail closed to empty overrides.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === array(), 'Invalid persisted admin option should read as empty overrides.' );

$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'level' => 999 ) ) );
Config::reset_for_tests();
$config = Config::get();
$report = Config::effective_report();
eforms_test_assert( $config['logging']['level'] === Anchors::get( 'LOGGING_LEVEL_MAX' ), 'Admin numeric values should clamp through Config.' );
eforms_test_assert( $report['logging.level']['source'] === 'clamped', 'Clamped admin values should report clamped source.' );

$reset();
