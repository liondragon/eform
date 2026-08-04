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
require_once __DIR__ . '/../../src/Admin/SubmissionsAdmin.php';
require_once __DIR__ . '/../../src/Admin/DeclinedReviewAdmin.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return 'https://example.com';
    }
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src, $deps = array(), $ver = false ) {
        $GLOBALS['eforms_test_styles'][] = array( 'handle' => $handle, 'src' => $src, 'deps' => $deps );
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
        $GLOBALS['eforms_test_scripts'][] = array( 'handle' => $handle, 'src' => $src, 'in_footer' => $in_footer );
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return $path;
    }
}

$uploads_dir = eforms_test_tmp_root( 'eforms-admin-settings' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
$GLOBALS['eforms_test_nonce'] = 'valid-nonce';
$dropin_path = WP_CONTENT_DIR . '/' . Config::DROPIN_FILENAME;
$admin_script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin-settings.js' );

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
    $GLOBALS['eforms_test_styles'] = array();
    $GLOBALS['eforms_test_scripts'] = array();
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

// Settings -> eForms and retained submissions register independently from declined review.
$reset();
eforms_register_admin();
eforms_test_assert( count( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) === 2, 'Settings and retained submissions should register when declined review is disabled.' );
SettingsAdmin::register_menu();
SubmissionsAdmin::register_menu();
SettingsAdmin::enqueue_assets( 'settings_page_' . SettingsAdmin::SLUG );
eforms_test_assert( count( $GLOBALS['eforms_test_options_pages'] ) === 1, 'Settings page should register one Options page.' );
eforms_test_assert( $GLOBALS['eforms_test_options_pages'][0]['menu_slug'] === SettingsAdmin::SLUG, 'Settings page should use the expected slug.' );
eforms_test_assert( $GLOBALS['eforms_test_options_pages'][0]['capability'] === 'manage_options', 'Settings page should require manage_options.' );
eforms_test_assert( count( $GLOBALS['eforms_test_management_pages'] ) === 1, 'Disabled declined review should still register retained submissions.' );
eforms_test_assert( count( $GLOBALS['eforms_test_styles'] ) === 1 && $GLOBALS['eforms_test_styles'][0]['handle'] === 'eforms-admin-settings', 'Settings page should enqueue its canonical admin stylesheet.' );
eforms_test_assert( count( $GLOBALS['eforms_test_scripts'] ) === 1 && $GLOBALS['eforms_test_scripts'][0]['handle'] === 'eforms-admin-settings' && $GLOBALS['eforms_test_scripts'][0]['in_footer'] === true, 'Settings page should enqueue its canonical admin runtime in the footer.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][0]['menu_slug'] === SubmissionsAdmin::SLUG, 'Retained submissions should keep its Tools page slug.' );

$reset();
eforms_test_configure_declined_review( $uploads_dir, true );
$GLOBALS['eforms_test_options_pages'] = array();
$GLOBALS['eforms_test_management_pages'] = array();
$GLOBALS['eforms_test_hooks']['action']['admin_menu'] = array();
eforms_register_admin();
eforms_test_assert( count( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) === 3, 'Enabled declined review should register Settings and Tools hooks.' );
SettingsAdmin::register_menu();
SubmissionsAdmin::register_menu();
DeclinedReviewAdmin::register_menu();
eforms_test_assert( count( $GLOBALS['eforms_test_options_pages'] ) === 1, 'Enabled declined review should still register one Settings page.' );
eforms_test_assert( count( $GLOBALS['eforms_test_management_pages'] ) === 2, 'Enabled declined review should register both Tools pages.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][0]['menu_slug'] === SubmissionsAdmin::SLUG, 'Retained submissions should keep its Tools page slug.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][1]['menu_slug'] === DeclinedReviewAdmin::SLUG, 'Declined review should keep its Tools page slug.' );

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
eforms_test_assert( strpos( $html, '<th>Name</th><th>Setting</th>' ) !== false, 'Settings table should show only the user-facing setting columns.' );
eforms_test_assert( strpos( $html, '<th>Config Handle</th>' ) === false && strpos( $html, '<th>Effective</th>' ) === false && strpos( $html, '<th>Source</th>' ) === false, 'Settings table should not show redundant config, effective, or source columns.' );
eforms_test_assert( strpos( $html, 'class="eforms-setting-help"' ) !== false, 'Settings page should render pop-out setting help.' );
eforms_test_assert( strpos( $html, 'Config handle: <code>logging.mode</code>' ) !== false, 'Setting help should keep the config handle available without making it a table column.' );
eforms_test_assert( strpos( $html, 'class="button-link eforms-setting-help-dismiss"' ) !== false, 'Setting help should render a dismiss control.' );
eforms_test_assert( strpos( $html, 'Dismiss help for Mode setting (challenge.mode)' ) !== false, 'Setting help dismiss buttons should be labelled.' );
eforms_test_assert( is_string( $admin_script ) && strpos( $admin_script, 'eformsSettingsHelpReady' ) !== false && strpos( $admin_script, "removeAttribute('open')" ) !== false, 'Setting help should include close behavior in the canonical admin runtime.' );
eforms_test_assert( strpos( $html, '<style' ) === false && strpos( $html, '<script' ) === false, 'Settings markup should not embed page-local styles or runtimes.' );
eforms_test_assert( strpos( $html, 'Help for Mode' ) !== false, 'Setting help should be labelled for assistive technology.' );
eforms_test_assert( strpos( $html, 'Help for Mode setting (challenge.mode)' ) !== false, 'Setting help labels should disambiguate duplicate labels.' );
eforms_test_assert( strpos( $html, 'Available options: Off, Auto, Always Post.' ) !== false, 'Select help should derive available options from field metadata.' );
eforms_test_assert( strpos( $html, 'Auto: only suspicious submissions are asked to verify.' ) !== false, 'Challenge help should explain available options in plain language.' );
eforms_test_assert( strpos( $html, 'Email' ) !== false && strpos( $html, 'Reply-To mode' ) !== false && strpos( $html, 'HTML email' ) !== false, 'Settings page should expose email formatting and reply-to controls.' );
eforms_test_assert( strpos( $html, 'Available options: Auto, Field, Fixed, None.' ) !== false, 'Reply-To mode help should derive available options from field metadata.' );
eforms_test_assert( strpos( $html, 'Auto: uses the fixed Reply-To address when set; otherwise uses the submitted email field.' ) !== false, 'Reply-To mode help should explain the compatibility-preserving auto mode.' );
eforms_test_assert( strpos( $html, 'Used by Auto when set, and required when Reply-To mode is Fixed.' ) !== false, 'Fixed Reply-To help should match auto/fixed precedence.' );
eforms_test_assert( strpos( $html, 'Used by Field mode, and by Auto when no fixed Reply-To address is set.' ) !== false, 'Reply-To field help should match auto/field precedence.' );
eforms_test_assert( strpos( $html, 'On: eForms sends HTML with a plain-text alternative for better mail compatibility.' ) !== false, 'HTML email help should explain the multipart plain-text alternative.' );
eforms_test_assert( strpos( $html, 'Spam Protection' ) !== false && strpos( $html, 'Rejection threshold' ) !== false, 'Settings page should expose spam protection controls.' );
eforms_test_assert( strpos( $html, 'Controls how many suspicious signals are needed' ) !== false, 'Spam threshold help should explain practical effect.' );
eforms_test_assert( strpos( $html, 'Blocked content' ) !== false && strpos( $html, '>Action</label>' ) !== false && strpos( $html, '>Blocked phrases</label>' ) !== false, 'Settings page should group content filter controls under one Blocked content setting.' );
eforms_test_assert( strpos( $html, 'Content filter mode' ) === false, 'Settings page should not keep the old standalone content-filter mode label.' );
eforms_test_assert( strpos( $html, 'Available options: Off, Suspect, Reject.' ) !== false, 'Content filter mode help should derive available options from field metadata.' );
eforms_test_assert( strpos( $html, 'Controls what happens when submitted text matches a blocked phrase.' ) !== false, 'Blocked content action help should explain the grouped setting.' );
eforms_test_assert( strpos( $html, 'Suspect: matching submissions still send email, but the email and logs are tagged for review.' ) !== false, 'Content filter help should explain suspect mode in plain language.' );
eforms_test_assert( strpos( $html, 'class="eforms-content-terms-editor" data-eforms-content-terms-editor' ) !== false, 'Blocked Phrases should render the single-field editor shell.' );
eforms_test_assert( strpos( $html, '<textarea id="eforms-setting-spam-content_filter-blocked_terms" name="' . SettingsFields::VALUES_KEY . '[spam.content_filter.blocked_terms]" rows="6" class="large-text code eforms-content-terms-editor__textarea" data-eforms-content-terms-source>' ) !== false, 'Blocked Phrases should keep the canonical submitted textarea as the no-JS fallback.' );
eforms_test_assert( strpos( $html, 'data-eforms-content-entry' ) !== false && strpos( $html, 'data-eforms-content-add>Add</button>' ) !== false, 'Blocked Phrases should render one universal entry field and one add action.' );
eforms_test_assert( strpos( $html, 'data-eforms-content-bulk' ) === false && strpos( $html, 'Paste multiple' ) === false, 'Blocked Phrases should not render a second bulk-paste field.' );
eforms_test_assert( strpos( $admin_script, 'Already added.' ) !== false, 'Blocked Phrases editor should include duplicate feedback.' );
eforms_test_assert( strpos( $admin_script, 'No blocked phrases yet.' ) !== false, 'Blocked Phrases editor should restore the empty state after the last pill is removed.' );
eforms_test_assert( strpos( $admin_script, "event.key === 'Enter' && !event.shiftKey" ) !== false, 'Blocked Phrases entry should use Enter for approval and leave Shift+Enter for new lines.' );
eforms_test_assert( strpos( $admin_script, 'sourceTerms(input && input.value)' ) !== false, 'Blocked Phrases entry should parse multi-line input from the single field.' );
eforms_test_assert( strpos( $admin_script, "form.addEventListener('submit'" ) !== false, 'Blocked Phrases editor should commit pending entry text before settings save.' );
eforms_test_assert( strpos( $admin_script, "source.value = terms.join('\\n')" ) !== false, 'Blocked Phrases editor should submit one normalized term per line.' );
eforms_test_assert( strpos( $html, 'Type a phrase and press Enter or Add.' ) !== false && strpos( $html, 'Use Shift+Enter or paste to enter multiple lines before adding.' ) !== false, 'Blocked terms help should explain Enter approval and multi-line entry.' );
eforms_test_assert( strpos( $html, 'Spam rejection response' ) !== false, 'Spam response setting should be labelled by the decision it controls.' );
eforms_test_assert( strpos( $html, 'Spam rejection response' ) < strpos( $html, 'eforms-settings-blocked-content-row' ), 'Blocked content should be the last editable Spam Protection setting.' );
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
foreach ( array( 'baseline', 'honeypot', 'missing-js', 'missing-honeypot', 'too-fast', 'combined-soft', 'content-filter-suspect', 'content-filter-reject', 'challenge-auto', 'throttle', 'mint-oversized', 'mint-no-origin' ) as $name ) {
    eforms_test_assert( strpos( $smoke_html, '>' . $name . '<' ) !== false, 'Smoke result table should include check: ' . $name );
}
eforms_test_assert( substr_count( $smoke_html, '>PASS<' ) === 12, 'Successful smoke run should render twelve passing rows.' );
eforms_test_assert( strpos( $smoke_html, '>Expected<' ) !== false, 'Smoke result table should show expected outcomes.' );
eforms_test_assert( strpos( $smoke_html, '>Config Scope<' ) !== false, 'Smoke result table should show temporary config assumptions.' );
eforms_test_assert( strpos( $smoke_html, 'content=reject burn=1' ) !== false, 'Smoke result table should show content-filter reject evidence.' );
eforms_test_assert( strpos( $smoke_html, 'operator terms not read' ) !== false, 'Smoke result table should disclose synthetic content-filter config.' );
eforms_test_assert( strpos( $smoke_html, 'real email is suppressed' ) !== false || strpos( $smoke_html, 'Real email is suppressed' ) !== false, 'Smoke section should disclose that real email is suppressed.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === array( 'logging' => array( 'mode' => 'jsonl' ) ), 'Smoke run should not persist settings.' );
eforms_test_assert( ! isset( $_SERVER['CONTENT_LENGTH'] ), 'Smoke run should restore CONTENT_LENGTH after admin execution.' );

// Run the runtime health diagnostic through the page route without saving settings.
$reset();
AdminSettingsStore::replace_overrides( array( 'logging' => array( 'mode' => 'jsonl' ) ) );
$doctor_html = SettingsAdmin::render_html( $runtime_health_post() );
eforms_test_assert( strpos( $doctor_html, 'eforms-runtime-health-results' ) !== false, 'Runtime health run should render a compact result table.' );
foreach ( array( 'uploads-base', 'private-storage', 'runtime-dirs', 'managed-upload-dirs', 'staged-artifact-readiness', 'managed-capacity', 'staged-request-limits', 'staged-throttle', 'templates', 'mail-format', 'gc-readiness', 'cli-bootstrap', 'config-sources', 'challenge-config' ) as $name ) {
    eforms_test_assert( strpos( $doctor_html, '>' . $name . '<' ) !== false, 'Runtime health result table should include check: ' . $name );
}
eforms_test_assert( strpos( $doctor_html, '>staged-throttle</td><td>FAIL<' ) !== false, 'Default disabled throttle should render a staged production-readiness failure.' );
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
    'email.from_address' => 'contact@example.com',
    'email.html' => '1',
    'email.reply_to_mode' => 'fixed',
    'email.reply_to_address' => 'team@example.com',
    'email.reply_to_field' => 'email',
    'spam.soft_fail_threshold' => '3',
    'spam.content_filter.mode' => 'suspect',
    'spam.content_filter.blocked_terms' => "Casino\nSEO   Services",
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
eforms_test_assert( $stored['email']['from_address'] === 'contact@example.com' && $stored['email']['html'] === true && $stored['email']['reply_to_mode'] === 'fixed' && $stored['email']['reply_to_address'] === 'team@example.com' && $stored['email']['reply_to_field'] === 'email', 'Email group should save.' );
eforms_test_assert( $stored['spam']['soft_fail_threshold'] === 3 && $stored['spam']['content_filter']['mode'] === 'suspect' && $stored['spam']['content_filter']['blocked_terms'] === "casino\nseo services" && $stored['security']['min_fill_seconds'] === 4 && $stored['security']['honeypot_response'] === 'hard_fail', 'Spam protection group should save.' );
eforms_test_assert( $stored['challenge']['mode'] === 'auto' && $stored['challenge']['site_key'] === 'site-key' && $stored['challenge']['secret_key'] === 'stored-secret', 'Challenge group should save.' );
eforms_test_assert( $stored['throttle']['enable'] === true && $stored['throttle']['per_ip']['max_per_minute'] === 60 && $stored['throttle']['per_ip']['cooldown_seconds'] === 5, 'Throttle group should save.' );
eforms_test_assert( $stored['privacy']['ip_mode'] === 'hash', 'Privacy group should save.' );
$challenge_html = SettingsAdmin::render_html();
eforms_test_assert( strpos( $challenge_html, 'Status: Configured' ) !== false, 'Challenge mode status should stay near its control without requiring an Effective column.' );

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
            'spam.content_filter.blocked_terms' => '',
        ),
        array( 'declined_review.retention_days', 'challenge.site_key', 'spam.content_filter.blocked_terms' )
    )
);
eforms_test_assert( $notice['type'] === 'success', 'Blank nullable/text fields should save as clears.' );
$cleared = AdminSettingsStore::read_overrides();
eforms_test_assert( ! isset( $cleared['declined_review']['retention_days'] ), 'Blank nullable field should clear stored override.' );
eforms_test_assert( ! isset( $cleared['challenge']['site_key'] ), 'Blank site key should clear stored override.' );
eforms_test_assert( ! isset( $cleared['spam']['content_filter']['blocked_terms'] ), 'Blank blocked terms textarea should clear stored override.' );

// Content filter textarea input is normalized, and invalid lists preserve the existing option.
$reset();
$content_save = SettingsAdmin::handle_save(
    $post(
        array(
            'spam.content_filter.mode' => 'reject',
            'spam.content_filter.blocked_terms' => " Casino \n\n SEO   Services \n",
        ),
        array( 'spam.content_filter.mode', 'spam.content_filter.blocked_terms' )
    )
);
eforms_test_assert( $content_save['type'] === 'success', 'Content filter settings should save through the admin mapper.' );
$content_stored = AdminSettingsStore::read_overrides();
eforms_test_assert( $content_stored['spam']['content_filter']['mode'] === 'reject', 'Content filter mode should persist.' );
eforms_test_assert( $content_stored['spam']['content_filter']['blocked_terms'] === "casino\nseo services", 'Content filter terms should normalize and remove blank lines on save.' );
$content_editor_html = SettingsAdmin::render_html();
eforms_test_assert( is_string( $admin_script ) && strpos( $admin_script, 'terms = sourceTerms(source.value)' ) !== false, 'Blocked Phrases editor should hydrate pills from the canonical textarea value.' );
eforms_test_assert( strpos( $admin_script, "remove.setAttribute('aria-label', 'Remove blocked phrase: ' + term)" ) !== false, 'Hydrated blocked phrase pills should expose accessible remove buttons.' );
eforms_test_assert( strpos( $content_editor_html, 'data-term="casino"' ) === false && strpos( $content_editor_html, 'data-term="seo services"' ) === false, 'Blocked Phrases should not duplicate hidden server-rendered pills.' );
eforms_test_assert( strpos( $content_editor_html, "casino\nseo services</textarea>" ) !== false, 'Canonical textarea should keep one normalized term per line.' );

$comma_phrase = SettingsAdmin::handle_save(
    $post(
        array(
            'spam.content_filter.blocked_terms' => "ACME, Inc\nCasino",
        ),
        array( 'spam.content_filter.blocked_terms' )
    )
);
eforms_test_assert( $comma_phrase['type'] === 'success', 'Comma phrases should remain valid blocked phrases.' );
$comma_phrase_html = SettingsAdmin::render_html();
eforms_test_assert( strpos( $comma_phrase_html, 'data-term="acme, inc"' ) === false, 'Blocked Phrases should not server-render comma phrase pills.' );
eforms_test_assert( strpos( $comma_phrase_html, "acme, inc\ncasino</textarea>" ) !== false, 'Canonical textarea should preserve comma phrases as newline-separated terms.' );
$current_content_stored = AdminSettingsStore::read_overrides();

$duplicate_terms = SettingsAdmin::handle_save(
    $post(
        array(
            'spam.content_filter.blocked_terms' => "Casino\n casino ",
        ),
        array( 'spam.content_filter.blocked_terms' )
    )
);
eforms_test_assert( $duplicate_terms['type'] === 'error', 'Duplicate content filter terms should reject the save.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === $current_content_stored, 'Duplicate content terms should preserve the existing admin option.' );

$oversized_terms = SettingsAdmin::handle_save(
    $post(
        array(
            'spam.content_filter.blocked_terms' => str_repeat( 'x', Anchors::get( 'CONTENT_FILTER_MAX_TERM_CHARS' ) + 1 ),
        ),
        array( 'spam.content_filter.blocked_terms' )
    )
);
eforms_test_assert( $oversized_terms['type'] === 'error', 'Oversized content filter terms should reject the save.' );
eforms_test_assert( AdminSettingsStore::read_overrides() === $current_content_stored, 'Oversized content terms should preserve the existing admin option.' );

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
eforms_test_assert( strpos( $external_html, 'Controlled externally by config file.' ) !== false, 'Externally controlled fields should show provenance only when it affects editability.' );
eforms_test_assert( strpos( $external_html, 'name="' . SettingsFields::VALUES_KEY . '[challenge.mode]"' ) !== false, 'Externally controlled settings should not disable unrelated fields.' );

$reset();
$write_dropin( array( 'logging' => array( 'level' => 999 ) ) );
Config::reset_for_tests();
$clamped_external_html = SettingsAdmin::render_html();
eforms_test_assert( strpos( $clamped_external_html, 'logging.level' ) !== false, 'Clamped external fields should render in the settings table.' );
eforms_test_assert( strpos( $clamped_external_html, '<input type="hidden" name="' . SettingsFields::SUBMITTED_PATHS_KEY . '[]" value="logging.level"' ) === false, 'Clamped external fields should not render as editable settings.' );

$reset();
$write_dropin( array( 'spam' => array( 'content_filter' => array( 'mode' => 'reject', 'blocked_terms' => 'casino' ) ) ) );
Config::reset_for_tests();
$external_content_html = SettingsAdmin::render_html();
eforms_test_assert( strpos( $external_content_html, 'spam.content_filter.mode' ) !== false && strpos( $external_content_html, 'spam.content_filter.blocked_terms' ) !== false, 'Externally controlled content filter fields should still show their values.' );
eforms_test_assert( strpos( $external_content_html, '<input type="hidden" name="' . SettingsFields::SUBMITTED_PATHS_KEY . '[]" value="spam.content_filter.mode"' ) === false, 'Externally controlled content mode should not render as editable.' );
eforms_test_assert( strpos( $external_content_html, '<textarea id="eforms-setting-spam-content_filter-blocked_terms"' ) === false, 'Externally controlled blocked terms should not render the textarea.' );
eforms_test_assert( strpos( $external_content_html, '<div class="eforms-content-terms-editor" data-eforms-content-terms-editor' ) === false, 'Externally controlled blocked terms should not render the editable term editor shell.' );
eforms_test_assert( strpos( $external_content_html, 'Blocked content' ) !== false && substr_count( $external_content_html, 'Controlled externally by config file.' ) >= 2, 'Externally controlled blocked content should stay grouped while showing each controlling source.' );

// Grouped settings tables keep editable controls with Config provenance and passive runtime checks.
$reset();
SettingsAdmin::handle_save( $post( array( 'logging.mode' => 'jsonl', 'challenge.secret_key' => 'stored-secret' ), array( 'logging.mode', 'challenge.secret_key' ) ) );
$private_path = PrivateDir::path( $uploads_dir );
eforms_test_remove_tree( $private_path );
$settings = SettingsAdmin::render_html();
eforms_test_assert( strpos( $settings, 'href="#eforms-settings-logging"' ) !== false && strpos( $settings, 'href="#eforms-settings-spam-protection"' ) !== false && strpos( $settings, 'href="#eforms-settings-storage"' ) !== false, 'Settings navigation should link to settings groups.' );
eforms_test_assert( substr_count( $settings, 'class="widefat striped eforms-settings-table"' ) > 1, 'Settings page should render separate grouped settings tables.' );
eforms_test_assert( strpos( $settings, 'aria-label="Storage settings"' ) !== false, 'Settings page should render storage as its own settings group.' );
eforms_test_assert( strpos( $settings, 'colspan="2"' ) === false && strpos( $settings, 'colspan="5"' ) === false, 'Settings page should not render group headings as table rows.' );
eforms_test_assert( strpos( $settings, 'eforms-' . 'overview' ) === false && strpos( $settings, 'nav-' . 'tab-wrapper' ) === false, 'Settings page should not keep legacy alternate-surface markup.' );
eforms_test_assert( strpos( $settings, 'Config handle: <code>logging.mode</code>' ) !== false && strpos( $settings, '>admin option<' ) === false, 'Settings table should hide routine provenance while keeping config handles in help.' );
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
