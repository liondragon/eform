<?php
/**
 * Integration tests for Settings -> eForms.
 *
 * Contract: Configuration.
 */

require_once __DIR__ . '/../bootstrap.php';
eforms_test_define_wp_content( 'eforms-admin-settings-page' );

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Diagnostics/SpamSmokeDiagnostic.php';
require_once __DIR__ . '/../../src/Admin/SettingsAdmin.php';
require_once __DIR__ . '/../../src/Admin/SettingsFields.php';
require_once __DIR__ . '/../../src/Admin/AdminSettingsStore.php';
require_once __DIR__ . '/../../src/Admin/DeclinedReviewAdmin.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';

$uploads_dir = eforms_test_tmp_root( 'eforms-admin-settings' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
$GLOBALS['eforms_test_nonce'] = 'valid-nonce';
$dropin_path = WP_CONTENT_DIR . '/' . Config::DROPIN_FILENAME;

$remove_dropin = function () use ( $dropin_path ) {
    if ( file_exists( $dropin_path ) ) {
        unlink( $dropin_path );
    }
};

$write_dropin = function ( $override ) use ( $dropin_path ) {
    file_put_contents( $dropin_path, "<?php\nreturn " . var_export( $override, true ) . ";\n" );
};

$reset = function () use ( $remove_dropin ) {
    $remove_dropin();
    eforms_test_reset_options();
    eforms_test_set_filter( 'eforms_config', null );
    $GLOBALS['eforms_test_can_manage'] = true;
    $GLOBALS['eforms_test_options_pages'] = array();
    $GLOBALS['eforms_test_management_pages'] = array();
    $GLOBALS['eforms_test_hooks']['action']['admin_menu'] = array();
    $_SERVER['HTTP_HOST'] = 'example.com';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
    unset( $_SERVER['CONTENT_LENGTH'] );
    Config::reset_for_tests();
};

$post = function ( $values, $submitted = null, $secret_clear = array(), $nonce = 'valid-nonce' ) {
    if ( $submitted === null ) {
        $submitted = SettingsFields::field_paths();
    }
    return array(
        'eforms_settings_action' => SettingsAdmin::SAVE_ACTION,
        SettingsAdmin::NONCE_FIELD => $nonce,
        SettingsFields::SUBMITTED_PATHS_KEY => $submitted,
        SettingsFields::VALUES_KEY => $values,
        SettingsFields::SECRET_CLEAR_KEY => $secret_clear,
    );
};

$diagnostic_post = function ( $nonce = 'valid-nonce' ) {
    return array(
        'eforms_settings_action' => SettingsAdmin::DIAGNOSTIC_ACTION,
        SettingsAdmin::DIAGNOSTIC_NONCE_FIELD => $nonce,
    );
};

$runtime_health_post = function ( $nonce = 'valid-nonce' ) {
    return array(
        'eforms_settings_action' => SettingsAdmin::RUNTIME_HEALTH_ACTION,
        SettingsAdmin::RUNTIME_HEALTH_NONCE_FIELD => $nonce,
    );
};

// Settings -> eForms registers independently from the declined-review Tools page.
$reset();
eforms_register_admin();
eforms_test_assert( count( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) === 1, 'Settings page should register when declined review is disabled.' );
SettingsAdmin::register_menu();
eforms_test_assert( count( $GLOBALS['eforms_test_options_pages'] ) === 1, 'Settings page should register one Options page.' );
eforms_test_assert( $GLOBALS['eforms_test_options_pages'][0]['menu_slug'] === SettingsAdmin::SLUG, 'Settings page should use the expected slug.' );
eforms_test_assert( $GLOBALS['eforms_test_options_pages'][0]['capability'] === 'manage_options', 'Settings page should require manage_options.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'] === array(), 'Disabled declined review should not register the Tools page.' );

$reset();
eforms_test_configure_declined_review( $uploads_dir, true );
$GLOBALS['eforms_test_options_pages'] = array();
$GLOBALS['eforms_test_management_pages'] = array();
$GLOBALS['eforms_test_hooks']['action']['admin_menu'] = array();
eforms_register_admin();
eforms_test_assert( count( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) === 2, 'Enabled declined review should register Settings and Tools hooks.' );
SettingsAdmin::register_menu();
DeclinedReviewAdmin::register_menu();
eforms_test_assert( count( $GLOBALS['eforms_test_options_pages'] ) === 1, 'Enabled declined review should still register one Settings page.' );
eforms_test_assert( count( $GLOBALS['eforms_test_management_pages'] ) === 1, 'Enabled declined review should register one Tools page.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][0]['menu_slug'] === DeclinedReviewAdmin::SLUG, 'Declined review should keep its Tools page slug.' );

// Capability and nonce gates reject render/save without mutating the option.
$reset();
$GLOBALS['eforms_test_can_manage'] = false;
eforms_test_assert( SettingsAdmin::render_html() === '', 'Unauthorized settings render should return no HTML.' );
$unauthorized = SettingsAdmin::handle_save( $post( array( 'logging.mode' => 'jsonl' ), array( 'logging.mode' ) ) );
eforms_test_assert( $unauthorized['type'] === 'error', 'Unauthorized save should fail.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, array() ) === array(), 'Unauthorized save should not write the admin option.' );
$unauthorized_smoke = SettingsAdmin::handle_spam_smoke( $diagnostic_post() );
eforms_test_assert( $unauthorized_smoke['notice']['type'] === 'error', 'Unauthorized smoke run should fail.' );
eforms_test_assert( $unauthorized_smoke['result'] === null, 'Unauthorized smoke run should not expose diagnostic output.' );
$unauthorized_doctor = SettingsAdmin::handle_runtime_health( $runtime_health_post() );
eforms_test_assert( $unauthorized_doctor['notice']['type'] === 'error', 'Unauthorized runtime health run should fail.' );
eforms_test_assert( $unauthorized_doctor['result'] === null, 'Unauthorized runtime health run should not expose diagnostic output.' );

$reset();
$bad_nonce = SettingsAdmin::handle_save( $post( array( 'logging.mode' => 'jsonl' ), array( 'logging.mode' ), array(), 'bad-nonce' ) );
eforms_test_assert( $bad_nonce['type'] === 'error', 'Bad nonce save should fail.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, array() ) === array(), 'Bad nonce save should not write the admin option.' );
$bad_smoke = SettingsAdmin::handle_spam_smoke( $diagnostic_post( 'bad-nonce' ) );
eforms_test_assert( $bad_smoke['notice']['type'] === 'error', 'Bad nonce smoke run should fail.' );
eforms_test_assert( $bad_smoke['result'] === null, 'Bad nonce smoke run should not expose diagnostic output.' );
$bad_doctor = SettingsAdmin::handle_runtime_health( $runtime_health_post( 'bad-nonce' ) );
eforms_test_assert( $bad_doctor['notice']['type'] === 'error', 'Bad nonce runtime health run should fail.' );
eforms_test_assert( $bad_doctor['result'] === null, 'Bad nonce runtime health run should not expose diagnostic output.' );
eforms_test_assert( get_option( AdminSettingsStore::OPTION_NAME, array() ) === array(), 'Bad nonce smoke run should not write the admin option.' );

$reset();
$_GET = array( 'tab' => '<script>alert(1)</script>' );
ob_start();
SettingsAdmin::render_page();
$html = ob_get_clean();
eforms_test_assert( strpos( $html, 'class="eforms-settings-nav"' ) !== false, 'Settings page should render a section navigation bar.' );
eforms_test_assert( strpos( $html, 'form="eforms-settings-form"' ) !== false, 'Settings navigation should include a save action tied to the settings form.' );
eforms_test_assert( strpos( $html, 'id="eforms-settings-form"' ) !== false, 'Settings controls should stay in one save form.' );
eforms_test_assert( strpos( $html, 'id="eforms-settings-logging"' ) !== false && strpos( $html, 'id="eforms-settings-spam-protection"' ) !== false && strpos( $html, 'id="eforms-settings-diagnostics"' ) !== false, 'Settings sections should have stable anchor targets.' );
eforms_test_assert( strpos( $html, 'class="eforms-settings-panel"' ) !== false, 'Settings sections should render inside admin panels.' );
eforms_test_assert( strpos( $html, 'aria-label="Logging settings"' ) !== false && strpos( $html, 'aria-label="Throttle settings"' ) !== false, 'Settings page should render grouped settings tables.' );
eforms_test_assert( strpos( $html, 'Config Handle' ) !== false && strpos( $html, 'Effective' ) !== false && strpos( $html, 'Source' ) !== false && strpos( $html, 'Setting' ) !== false, 'Settings table should show controls with effective values and sources.' );
eforms_test_assert( strpos( $html, 'class="eforms-setting-help"' ) !== false, 'Settings page should render pop-out setting help.' );
eforms_test_assert( strpos( $html, 'class="button-link eforms-setting-help-dismiss"' ) !== false, 'Setting help should render a dismiss control.' );
eforms_test_assert( strpos( $html, 'Dismiss help for Mode setting (challenge.mode)' ) !== false, 'Setting help dismiss buttons should be labelled.' );
eforms_test_assert( strpos( $html, 'eformsSettingsHelpReady' ) !== false && strpos( $html, 'removeAttribute("open")' ) !== false, 'Setting help should include close behavior.' );
eforms_test_assert( strpos( $html, 'Help for Mode' ) !== false, 'Setting help should be labelled for assistive technology.' );
eforms_test_assert( strpos( $html, 'Help for Mode setting (challenge.mode)' ) !== false, 'Setting help labels should disambiguate duplicate labels.' );
eforms_test_assert( strpos( $html, 'Available options: Off, Auto, Always Post.' ) !== false, 'Select help should derive available options from field metadata.' );
eforms_test_assert( strpos( $html, 'Auto: only suspicious submissions are asked to verify.' ) !== false, 'Challenge help should explain available options in plain language.' );
eforms_test_assert( strpos( $html, 'Spam Protection' ) !== false && strpos( $html, 'Rejection threshold' ) !== false, 'Settings page should expose spam protection controls.' );
eforms_test_assert( strpos( $html, 'Controls how many suspicious signals are needed' ) !== false, 'Spam threshold help should explain practical effect.' );
eforms_test_assert( strpos( $html, 'Spam rejection response' ) !== false, 'Spam response setting should be labelled by the decision it controls.' );
eforms_test_assert( strpos( $html, 'eforms-protection-checks-table' ) !== false, 'Spam Protection should render a read-only checks table.' );
eforms_test_assert( strpos( $html, '>Settings</h3>' ) !== false && strpos( $html, '>Built-in checks</h3>' ) !== false, 'Spam Protection should label editable settings and read-only checks consistently.' );
foreach ( array( 'Hidden trap filled', 'Hidden trap missing', 'JavaScript marker missing', 'Origin missing or mismatched' ) as $check_label ) {
    eforms_test_assert( strpos( $html, $check_label ) !== false, 'Protection checks table should include: ' . $check_label );
}
foreach ( array( 'Too fast', 'Too old', 'Spam threshold', 'Per-IP throttle' ) as $duplicate_label ) {
    eforms_test_assert( strpos( $html, '<td>' . $duplicate_label . '</td>' ) === false, 'Protection checks table should not duplicate setting row: ' . $duplicate_label );
}
eforms_test_assert( strpos( $html, 'A direct POST that omits the hidden trap field adds honeypot_missing.' ) !== false, 'Protection checks should explain the missing honeypot soft signal.' );
eforms_test_assert( strpos( $html, 'Run Spam Smoke Test' ) !== false, 'Settings page should render the spam smoke diagnostic action.' );
eforms_test_assert( strpos( $html, 'Run Runtime Health Check' ) !== false, 'Settings page should render the runtime health diagnostic action.' );
eforms_test_assert( strpos( $html, 'eforms-runtime-health-results' ) === false, 'Settings page should not render passive runtime health results before action.' );
eforms_test_assert( strpos( $html, 'nav-' . 'tab-wrapper' ) === false, 'Settings page should render one surface.' );
eforms_test_assert( strpos( $html, '<script>alert(1)</script>' ) === false, 'Request output should be escaped.' );

// Save one field through the page route.
$reset();
$save_html = SettingsAdmin::render_html( $post( array( 'logging.mode' => 'jsonl' ), array( 'logging.mode' ) ) );
eforms_test_assert( strpos( $save_html, 'notice-success' ) !== false, 'Valid save should render a success notice.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Valid save should persist through AdminSettingsStore.' );

// Run the spam smoke diagnostic through the page route without saving settings.
$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => 'jsonl' ) ) );
$smoke_html = SettingsAdmin::render_html( $diagnostic_post() );
eforms_test_assert( strpos( $smoke_html, 'eforms-spam-smoke-results' ) !== false, 'Smoke run should render a compact result table.' );
foreach ( array( 'baseline', 'honeypot', 'missing-js', 'missing-honeypot', 'too-fast', 'combined-soft', 'challenge-auto', 'throttle', 'mint-oversized', 'mint-no-origin' ) as $name ) {
    eforms_test_assert( strpos( $smoke_html, '>' . $name . '<' ) !== false, 'Smoke result table should include check: ' . $name );
}
eforms_test_assert( substr_count( $smoke_html, '>PASS<' ) === 10, 'Successful smoke run should render ten passing rows.' );
eforms_test_assert( strpos( $smoke_html, '>Expected<' ) !== false, 'Smoke result table should show expected outcomes.' );
eforms_test_assert( strpos( $smoke_html, '>Config Scope<' ) !== false, 'Smoke result table should show temporary config assumptions.' );
eforms_test_assert( strpos( $smoke_html, 'real email is suppressed' ) !== false || strpos( $smoke_html, 'Real email is suppressed' ) !== false, 'Smoke section should disclose that real email is suppressed.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Smoke run should not persist settings.' );
eforms_test_assert( ! isset( $_SERVER['CONTENT_LENGTH'] ), 'Smoke run should restore CONTENT_LENGTH after admin execution.' );

// Run the runtime health diagnostic through the page route without saving settings.
$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => 'jsonl' ) ) );
$doctor_html = SettingsAdmin::render_html( $runtime_health_post() );
eforms_test_assert( strpos( $doctor_html, 'eforms-runtime-health-results' ) !== false, 'Runtime health run should render a compact result table.' );
foreach ( array( 'uploads-base', 'private-storage', 'runtime-dirs', 'templates', 'gc-readiness', 'cli-bootstrap', 'config-sources', 'challenge-config' ) as $name ) {
    eforms_test_assert( strpos( $doctor_html, '>' . $name . '<' ) !== false, 'Runtime health result table should include check: ' . $name );
}
eforms_test_assert( substr_count( $doctor_html, '>FAIL<' ) === 0, 'Default runtime health run should not render failing rows.' );
eforms_test_assert( strpos( $doctor_html, '>WARN<' ) !== false, 'Admin runtime health run should show the non-CLI bootstrap warning.' );
eforms_test_assert( strpos( $doctor_html, '>Expected<' ) !== false, 'Runtime health result table should show expected outcomes.' );
eforms_test_assert( strpos( $doctor_html, $uploads_dir ) === false, 'Runtime health result table should not expose raw upload paths.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Runtime health run should not persist settings.' );

// Every curated settings group maps through the field owner into sparse overrides.
$reset();
$all_values = array(
    'declined_review.enable' => '1',
    'declined_review.retention_days' => '14',
    'logging.mode' => 'jsonl',
    'logging.level' => '2',
    'logging.retention_days' => '45',
    'spam.soft_fail_threshold' => '3',
    'security.min_fill_seconds' => '4',
    'security.honeypot_response' => 'hard_fail',
    'challenge.mode' => 'auto',
    'challenge.site_key' => 'site-key',
    'challenge.secret_key' => 'stored-secret',
    'throttle.enable' => '1',
    'throttle.per_ip.max_per_minute' => '60',
    'throttle.per_ip.cooldown_seconds' => '5',
    'privacy.ip_mode' => 'hash',
);
$notice = SettingsAdmin::handle_save( $post( $all_values ) );
eforms_test_assert( $notice['type'] === 'success', 'Full curated settings save should succeed.' );
$stored = AdminSettingsStore::read_overrides();
eforms_test_assert( $stored['declined_review']['enable'] === true, 'Declined review checkbox should save true.' );
eforms_test_assert( $stored['declined_review']['retention_days'] === 14, 'Declined review retention should save as int.' );
eforms_test_assert( $stored['logging']['mode'] === 'jsonl' && $stored['logging']['level'] === 2 && $stored['logging']['retention_days'] === 45, 'Logging group should save.' );
eforms_test_assert( $stored['spam']['soft_fail_threshold'] === 3 && $stored['security']['min_fill_seconds'] === 4 && $stored['security']['honeypot_response'] === 'hard_fail', 'Spam protection group should save.' );
eforms_test_assert( $stored['challenge']['mode'] === 'auto' && $stored['challenge']['site_key'] === 'site-key' && $stored['challenge']['secret_key'] === 'stored-secret', 'Challenge group should save.' );
eforms_test_assert( $stored['throttle']['enable'] === true && $stored['throttle']['per_ip']['max_per_minute'] === 60 && $stored['throttle']['per_ip']['cooldown_seconds'] === 5, 'Throttle group should save.' );
eforms_test_assert( $stored['privacy']['ip_mode'] === 'hash', 'Privacy group should save.' );
$challenge_html = SettingsAdmin::render_html();
eforms_test_assert( strpos( $challenge_html, '>Configured<' ) !== false, 'Challenge mode display should be derived from field metadata and key state.' );

// Missing checkbox values map to false only when the field was editable/submitted.
$notice = SettingsAdmin::handle_save( $post( array(), array( 'throttle.enable' ) ) );
eforms_test_assert( $notice['type'] === 'success', 'Submitted missing checkbox should save false.' );
eforms_test_assert( AdminSettingsStore::read_overrides()['throttle']['enable'] === false, 'Submitted missing checkbox should persist false.' );

// Blank nullable/text values clear their admin override.
$notice = SettingsAdmin::handle_save(
    $post(
        array(
            'declined_review.retention_days' => '',
            'challenge.site_key' => '',
        ),
        array( 'declined_review.retention_days', 'challenge.site_key' )
    )
);
eforms_test_assert( $notice['type'] === 'success', 'Blank nullable/text fields should save as clears.' );
$cleared = AdminSettingsStore::read_overrides();
eforms_test_assert( ! isset( $cleared['declined_review']['retention_days'] ), 'Blank nullable field should clear stored override.' );
eforms_test_assert( ! isset( $cleared['challenge']['site_key'] ), 'Blank site key should clear stored override.' );

// Secrets are masked, blank keeps the stored secret, and explicit clear removes only the admin override.
$reset();
SettingsAdmin::handle_save( $post( array( 'challenge.secret_key' => 'stored-secret' ), array( 'challenge.secret_key' ) ) );
$secret_html = SettingsAdmin::render_html();
eforms_test_assert( strpos( $secret_html, 'stored-secret' ) === false, 'Settings page must never echo the raw stored secret.' );
eforms_test_assert( strpos( $secret_html, '********' ) !== false, 'Settings page should show masked stored secret state.' );

$keep = SettingsAdmin::handle_save( $post( array( 'challenge.secret_key' => '' ), array( 'challenge.secret_key' ) ) );
eforms_test_assert( $keep['type'] === 'success', 'Blank secret submission should keep existing stored secret.' );
eforms_test_assert( AdminSettingsStore::read_overrides()['challenge']['secret_key'] === 'stored-secret', 'Blank secret submission should preserve the stored secret.' );

$invalid_secret = SettingsAdmin::handle_save( $post( array( 'challenge.secret_key' => 'new-secret' ), array( 'challenge.secret_key' ), array( 'challenge.secret_key' => '1' ) ) );
eforms_test_assert( $invalid_secret['type'] === 'error', 'Clear plus replacement should reject as invalid.' );
eforms_test_assert( AdminSettingsStore::read_overrides()['challenge']['secret_key'] === 'stored-secret', 'Invalid secret action should preserve existing option.' );

$clear = SettingsAdmin::handle_save( $post( array( 'challenge.secret_key' => '' ), array( 'challenge.secret_key' ), array( 'challenge.secret_key' => '1' ) ) );
eforms_test_assert( $clear['type'] === 'success', 'Explicit secret clear should save.' );
eforms_test_assert( ! isset( AdminSettingsStore::read_overrides()['challenge']['secret_key'] ), 'Explicit secret clear should remove the stored admin secret.' );

// Unknown/non-allowlisted input rejects the whole submitted payload.
$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => 'jsonl' ) ) );
$unknown = SettingsAdmin::handle_save( $post( array( 'security.origin_mode' => 'hard' ), array( 'security.origin_mode' ) ) );
eforms_test_assert( $unknown['type'] === 'error', 'Unknown settings field should reject the save.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Unknown field rejection should preserve existing option.' );

$bad_sentinel = SettingsAdmin::handle_save( $post( array( 'logging.mode' => 'off' ), array() ) );
eforms_test_assert( $bad_sentinel['type'] === 'error', 'Value without submitted-field sentinel should reject the save.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Bad sentinel rejection should preserve existing option.' );

// Externally controlled fields are excluded from mutation and preserve stored admin overrides.
$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => 'jsonl' ) ) );
$write_dropin( array( 'logging' => array( 'mode' => 'minimal' ) ) );
Config::reset_for_tests();
$external = SettingsAdmin::handle_save( $post( array( 'logging.mode' => 'off' ), array( 'logging.mode' ) ) );
eforms_test_assert( $external['type'] === 'success', 'Externally controlled submitted field should not fail the save.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Externally controlled field should preserve the stored admin override.' );
$external_html = SettingsAdmin::render_html();
eforms_test_assert( strpos( $external_html, 'Controlled externally' ) !== false, 'Externally controlled fields should be visibly non-editable.' );
eforms_test_assert( strpos( $external_html, 'name="' . SettingsFields::VALUES_KEY . '[challenge.mode]"' ) !== false, 'Externally controlled settings should not disable unrelated fields.' );

$reset();
$write_dropin( array( 'logging' => array( 'level' => 999 ) ) );
Config::reset_for_tests();
$clamped_external_html = SettingsAdmin::render_html();
eforms_test_assert( strpos( $clamped_external_html, 'logging.level' ) !== false, 'Clamped external fields should render in the settings table.' );
eforms_test_assert( strpos( $clamped_external_html, '<input type="hidden" name="' . SettingsFields::SUBMITTED_PATHS_KEY . '[]" value="logging.level"' ) === false, 'Clamped external fields should not render as editable settings.' );

// Grouped settings tables keep editable controls with Config provenance and passive runtime checks.
$reset();
SettingsAdmin::handle_save( $post( array( 'logging.mode' => 'jsonl', 'challenge.secret_key' => 'stored-secret' ), array( 'logging.mode', 'challenge.secret_key' ) ) );
$private_path = PrivateDir::path( $uploads_dir );
eforms_test_remove_tree( $private_path );
$settings = SettingsAdmin::render_html();
eforms_test_assert( strpos( $settings, 'href="#eforms-settings-logging"' ) !== false && strpos( $settings, 'href="#eforms-settings-spam-protection"' ) !== false && strpos( $settings, 'href="#eforms-settings-storage"' ) !== false, 'Settings navigation should link to settings groups.' );
eforms_test_assert( substr_count( $settings, 'class="widefat striped eforms-settings-table"' ) > 1, 'Settings page should render separate grouped settings tables.' );
eforms_test_assert( strpos( $settings, 'aria-label="Storage settings"' ) !== false, 'Settings page should render storage as its own settings group.' );
eforms_test_assert( strpos( $settings, 'colspan="5"' ) === false, 'Settings page should not render group headings as table rows.' );
eforms_test_assert( strpos( $settings, 'eforms-' . 'overview' ) === false && strpos( $settings, 'nav-' . 'tab-wrapper' ) === false, 'Settings page should not keep legacy alternate-surface markup.' );
eforms_test_assert( strpos( $settings, 'logging.mode' ) !== false && strpos( $settings, 'admin option' ) !== false, 'Settings table should show Config source labels.' );
eforms_test_assert( strpos( $settings, 'Storage Base' ) !== false && strpos( $settings, 'Writable' ) !== false, 'Settings table should report writable upload-base state.' );
eforms_test_assert( strpos( $settings, 'Private ' . 'Storage' ) === false && strpos( $settings, 'Drop-in ' . 'File' ) === false, 'Settings table should not show passive legacy status rows.' );
eforms_test_assert( strpos( $settings, 'stored-secret' ) === false, 'Settings table must not expose raw secrets.' );
eforms_test_assert( strpos( $settings, '********' ) !== false, 'Settings table should show masked stored secret state.' );
eforms_test_assert( strpos( $settings, $uploads_dir ) === false, 'Settings table should not expose raw upload paths.' );
eforms_test_assert( ! is_dir( $private_path ), 'Settings page render should not create private storage.' );

$remove_dropin();
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
