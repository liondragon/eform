<?php
/**
 * Settings -> eForms admin surface.
 *
 * Contract: Configuration
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Diagnostics/RuntimeHealthDiagnostic.php';
require_once __DIR__ . '/../Diagnostics/SpamSmokeDiagnostic.php';
require_once __DIR__ . '/AdminSettingsStore.php';
require_once __DIR__ . '/SettingsFields.php';

class SettingsAdmin {
    const SLUG = 'eforms-settings';
    const NONCE_ACTION = 'eforms_save_settings';
    const NONCE_FIELD = '_eforms_settings_nonce';
    const SAVE_ACTION = 'eforms_save_settings';
    const DIAGNOSTIC_ACTION = 'eforms_run_spam_smoke';
    const DIAGNOSTIC_NONCE_FIELD = '_eforms_spam_smoke_nonce';
    const RUNTIME_HEALTH_ACTION = 'eforms_run_runtime_doctor';
    const RUNTIME_HEALTH_NONCE_FIELD = '_eforms_runtime_doctor_nonce';
    const FORM_ID = 'eforms-settings-form';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
    }

    public static function register_menu() {
        add_options_page(
            'eForms Settings',
            'eForms',
            'manage_options',
            self::SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! self::can_manage() ) {
            wp_die( esc_html( 'Sorry, you are not allowed to access this page.' ) );
        }

        $post = null;
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) === 'POST' ) {
            $post = $_POST;
        }

        echo self::render_html( $post );
    }

    public static function render_html( $post = null ) {
        if ( ! self::can_manage() ) {
            return '';
        }

        $notice = null;
        $spam_diagnostic = null;
        $runtime_health = null;
        $action = is_array( $post ) && isset( $post['eforms_settings_action'] ) ? (string) $post['eforms_settings_action'] : '';
        if ( $action === self::SAVE_ACTION ) {
            $notice = self::handle_save( $post );
        } elseif ( $action === self::DIAGNOSTIC_ACTION ) {
            $run = self::handle_spam_smoke( $post );
            $notice = $run['notice'];
            $spam_diagnostic = $run['result'];
        } elseif ( $action === self::RUNTIME_HEALTH_ACTION ) {
            $run = self::handle_runtime_health( $post );
            $notice = $run['notice'];
            $runtime_health = $run['result'];
        }

        ob_start();
        echo '<div class="wrap eforms-settings-admin">';
        echo '<h1>' . esc_html( 'eForms' ) . '</h1>';
        self::render_admin_styles();
        self::render_help_script();
        if ( is_array( $notice ) ) {
            self::render_notice( $notice );
        }

        self::render_settings_navigation();
        self::render_settings_form();
        self::render_diagnostics_section( $spam_diagnostic, $runtime_health );

        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function handle_save( $post ) {
        if ( ! self::can_manage() ) {
            return self::notice( 'error', 'You are not allowed to save eForms settings.' );
        }

        $post = is_array( $post ) ? self::unslash( $post ) : array();
        $nonce = isset( $post[ self::NONCE_FIELD ] ) ? (string) $post[ self::NONCE_FIELD ] : '';
        if ( ! self::verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return self::notice( 'error', 'Settings were not saved because the security check failed.' );
        }

        $mapped = SettingsFields::overrides_from_submission(
            $post,
            AdminSettingsStore::read_overrides(),
            Config::effective_report()
        );
        if ( empty( $mapped['ok'] ) ) {
            return self::notice( 'error', 'Settings were not saved because one or more values were invalid.' );
        }

        $saved = AdminSettingsStore::replace_overrides( $mapped['overrides'] );
        if ( empty( $saved['ok'] ) ) {
            return self::notice( 'error', 'Settings were not saved because one or more values were invalid.' );
        }

        Config::refresh();

        return self::notice( 'success', 'Settings saved.' );
    }

    public static function handle_spam_smoke( $post ) {
        $gate = self::diagnostic_gate(
            $post,
            self::DIAGNOSTIC_NONCE_FIELD,
            self::DIAGNOSTIC_ACTION,
            'You are not allowed to run the eForms spam smoke test.',
            'Spam smoke test was not run because the security check failed.'
        );
        if ( ! empty( $gate['blocked'] ) ) {
            return $gate['response'];
        }

        $result = SpamSmokeDiagnostic::run();
        $type = ! empty( $result['ok'] ) ? 'success' : 'error';
        if ( empty( $result['checks'] ) ) {
            $message = 'Spam smoke test failed preflight: ' . SpamSmokeDiagnostic::preflight_error( $result ) . '.';
        } else {
            $message = 'Spam smoke test complete: ' . SpamSmokeDiagnostic::summary_line( $result ) . '.';
        }

        return array(
            'notice' => self::notice( $type, $message ),
            'result' => $result,
        );
    }

    public static function handle_runtime_health( $post ) {
        $gate = self::diagnostic_gate(
            $post,
            self::RUNTIME_HEALTH_NONCE_FIELD,
            self::RUNTIME_HEALTH_ACTION,
            'You are not allowed to run the eForms runtime health check.',
            'Runtime health check was not run because the security check failed.'
        );
        if ( ! empty( $gate['blocked'] ) ) {
            return $gate['response'];
        }

        $result = RuntimeHealthDiagnostic::run();
        $type = ! empty( $result['ok'] ) ? 'success' : 'error';
        $message = 'Runtime health check complete: ' . RuntimeHealthDiagnostic::summary_line( $result ) . '.';

        return array(
            'notice' => self::notice( $type, $message ),
            'result' => $result,
        );
    }

    private static function diagnostic_gate( $post, $nonce_field, $nonce_action, $capability_message, $nonce_message ) {
        if ( ! self::can_manage() ) {
            return array(
                'blocked' => true,
                'response' => array(
                    'notice' => self::notice( 'error', $capability_message ),
                    'result' => null,
                ),
            );
        }

        $post = is_array( $post ) ? self::unslash( $post ) : array();
        $nonce = isset( $post[ $nonce_field ] ) ? (string) $post[ $nonce_field ] : '';
        if ( ! self::verify_nonce( $nonce, $nonce_action ) ) {
            return array(
                'blocked' => true,
                'response' => array(
                    'notice' => self::notice( 'error', $nonce_message ),
                    'result' => null,
                ),
            );
        }

        return array( 'blocked' => false );
    }

    private static function render_settings_form() {
        $report = Config::effective_report();
        $config = Config::get();
        $stored = AdminSettingsStore::read_overrides();

        echo '<form id="' . esc_attr( self::FORM_ID ) . '" class="eforms-settings-form" method="post" action="">';
        echo '<input type="hidden" name="eforms_settings_action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
        self::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

        foreach ( SettingsFields::groups() as $key => $group ) {
            self::render_settings_table( self::section_id( $group['label'] ), $group['label'], $group['fields'], $config, $report, $stored, $key );
        }
        self::render_storage_table( $config, $report );
        self::submit_button();
        echo '</form>';
    }

    private static function render_settings_navigation() {
        echo '<nav class="eforms-settings-nav" aria-label="' . esc_attr( 'eForms settings sections' ) . '">';
        foreach ( SettingsFields::groups() as $group ) {
            echo '<a class="eforms-settings-nav__link" href="#' . esc_attr( self::section_id( $group['label'] ) ) . '">' . esc_html( $group['label'] ) . '</a>';
        }
        echo '<a class="eforms-settings-nav__link" href="#' . esc_attr( 'eforms-settings-storage' ) . '">' . esc_html( 'Storage' ) . '</a>';
        echo '<a class="eforms-settings-nav__link" href="#' . esc_attr( 'eforms-settings-diagnostics' ) . '">' . esc_html( 'Diagnostics' ) . '</a>';
        echo '<button type="submit" form="' . esc_attr( self::FORM_ID ) . '" class="button button-primary eforms-settings-nav__save">' . esc_html( 'Save Changes' ) . '</button>';
        echo '</nav>';
    }

    private static function render_settings_table( $id, $label, $fields, $config, $report, $stored, $group_key = '' ) {
        echo '<section id="' . esc_attr( $id ) . '" class="eforms-settings-section">';
        echo '<div class="eforms-settings-panel">';
        echo '<div class="eforms-settings-panel__header"><h2 class="eforms-settings-section-title">' . esc_html( $label ) . '</h2></div>';
        echo '<div class="eforms-settings-panel__body">';
        if ( $group_key === 'spam' ) {
            echo '<h3 class="eforms-settings-subtitle">' . esc_html( 'Settings' ) . '</h3>';
        }
        echo '<table class="widefat striped eforms-settings-table" aria-label="' . esc_attr( $label . ' settings' ) . '">';
        self::render_settings_table_head();
        echo '<tbody>';
        foreach ( $fields as $field ) {
            self::render_field_row( $field, $config, $report, $stored );
        }
        echo '</tbody></table>';
        if ( $group_key === 'spam' ) {
            self::render_protection_checks( $config );
        }
        echo '</div></div></section>';
    }

    private static function render_settings_table_head() {
        echo '<thead><tr>';
        echo '<th>' . esc_html( 'Name' ) . '</th><th>' . esc_html( 'Config Handle' ) . '</th><th>' . esc_html( 'Effective' ) . '</th><th>' . esc_html( 'Source' ) . '</th><th>' . esc_html( 'Setting' ) . '</th>';
        echo '</tr></thead>';
    }

    private static function render_protection_checks( $config ) {
        echo '<div class="eforms-protection-checks" aria-label="' . esc_attr( 'Spam protection checks' ) . '">';
        echo '<h3 class="eforms-settings-subtitle">' . esc_html( 'Built-in checks' ) . '</h3>';
        echo '<table class="widefat striped eforms-protection-checks-table">';
        echo '<thead><tr><th>' . esc_html( 'Check' ) . '</th><th>' . esc_html( 'Status' ) . '</th><th>' . esc_html( 'What happens' ) . '</th></tr></thead><tbody>';
        foreach ( self::protection_check_rows( $config ) as $row ) {
            echo '<tr class="eforms-protection-checks-table__row">';
            echo '<td>' . esc_html( $row['label'] ) . '</td>';
            echo '<td><span class="eforms-protection-checks-table__status">' . esc_html( $row['status'] ) . '</span></td>';
            echo '<td>' . esc_html( $row['effect'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    private static function protection_check_rows( $config ) {
        $origin_mode = (string) Config::value( $config, array( 'security', 'origin_mode' ), 'soft' );
        $js_hard = Config::bool( $config, array( 'security', 'js_hard_mode' ), false );

        $origin_status = 'Off';
        $origin_effect = 'Origin header differences are ignored.';
        if ( $origin_mode === 'soft' ) {
            $origin_status = 'Active, soft signal';
            $origin_effect = 'Missing or mismatched Origin adds origin_soft.';
        } elseif ( $origin_mode === 'hard' ) {
            $origin_status = 'Active, hard block';
            $origin_effect = 'Cross-site or unknown Origin is blocked; missing Origin follows the configured missing-Origin rule.';
        }

        return array(
            array(
                'label' => 'Hidden trap filled',
                'status' => 'Active, hard block',
                'effect' => 'A non-empty hidden trap field blocks the submission before validation and email.',
            ),
            array(
                'label' => 'Hidden trap missing',
                'status' => 'Active, soft signal',
                'effect' => 'A direct POST that omits the hidden trap field adds honeypot_missing.',
            ),
            array(
                'label' => 'JavaScript marker missing',
                'status' => $js_hard ? 'Active, hard block' : 'Active, soft signal',
                'effect' => $js_hard ? 'Missing JavaScript proof blocks the submission.' : 'Missing JavaScript proof adds js_missing.',
            ),
            array(
                'label' => 'Origin missing or mismatched',
                'status' => $origin_status,
                'effect' => $origin_effect,
            ),
        );
    }

    private static function render_diagnostics_section( $spam_result, $runtime_result ) {
        echo '<section id="eforms-settings-diagnostics" class="eforms-settings-section">';
        echo '<div class="eforms-settings-panel">';
        echo '<div class="eforms-settings-panel__header">';
        echo '<h2>' . esc_html( 'Diagnostics' ) . '</h2>';
        echo '</div><div class="eforms-settings-panel__body">';
        echo '<p class="description">' . esc_html( 'Runs the same focused spam smoke diagnostic as WP-CLI. Real email is suppressed; runtime artifacts may appear in eForms logs/storage and are cleaned by normal GC. This verifies wiring, not real-world spam effectiveness.' ) . '</p>';
        self::render_diagnostic_form( self::DIAGNOSTIC_ACTION, self::DIAGNOSTIC_NONCE_FIELD, 'Run Spam Smoke Test' );

        if ( is_array( $spam_result ) ) {
            self::render_diagnostic_result( $spam_result );
        }

        echo '<p class="description">' . esc_html( 'Runs active runtime checks for storage, shipped templates, GC readiness, CLI bootstrap, and config source visibility. Results are not stored, and cron configuration can only be inferred from observable runtime state.' ) . '</p>';
        self::render_diagnostic_form( self::RUNTIME_HEALTH_ACTION, self::RUNTIME_HEALTH_NONCE_FIELD, 'Run Runtime Health Check' );

        if ( is_array( $runtime_result ) ) {
            self::render_runtime_health_result( $runtime_result );
        }
        echo '</div></div></section>';
    }

    private static function render_diagnostic_form( $action, $nonce_field, $button_text ) {
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="eforms_settings_action" value="' . esc_attr( $action ) . '" />';
        self::nonce_field( $action, $nonce_field );
        echo '<p class="submit"><button type="submit" class="button">' . esc_html( $button_text ) . '</button></p>';
        echo '</form>';
    }

    private static function render_diagnostic_result( $result ) {
        $rows = SpamSmokeDiagnostic::rows( $result );
        if ( empty( $rows ) ) {
            echo '<p><strong>' . esc_html( 'Preflight failed:' ) . '</strong> ' . esc_html( SpamSmokeDiagnostic::preflight_error( $result ) ) . '</p>';
            return;
        }

        self::render_result_table(
            'eforms-spam-smoke-results',
            array(
                'name' => 'Check',
                'result' => 'Result',
                'observed' => 'Observed',
                'expected' => 'Expected',
                'config_scope' => 'Config Scope',
                'notes' => 'Notes',
            ),
            $rows
        );
    }

    private static function render_runtime_health_result( $result ) {
        self::render_result_table(
            'eforms-runtime-health-results',
            array(
                'name' => 'Check',
                'result' => 'Result',
                'observed' => 'Observed',
                'expected' => 'Expected',
                'notes' => 'Notes',
            ),
            RuntimeHealthDiagnostic::rows( $result )
        );
    }

    private static function render_result_table( $class, $columns, $rows ) {
        echo '<table class="widefat striped ' . esc_attr( $class ) . '"><thead><tr>';
        foreach ( $columns as $label ) {
            echo '<th>' . esc_html( $label ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( $columns as $key => $label ) {
                $value = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
                echo '<td>' . esc_html( $value ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_field_row( $field, $config, $report, $stored ) {
        $path = $field['path'];
        $report_entry = isset( $report[ $path ] ) && is_array( $report[ $path ] ) ? $report[ $path ] : array();
        $externally_controlled = isset( $report_entry['externally_controlled'] ) && (bool) $report_entry['externally_controlled'];
        $source = isset( $report_entry['source'] ) ? (string) $report_entry['source'] : 'default';
        $value = Config::value( $config, explode( '.', $path ), '' );
        $display_value = array_key_exists( 'display_value', $report_entry ) ? $report_entry['display_value'] : $value;

        echo '<tr>';
        echo '<td><label for="' . esc_attr( self::field_id( $path ) ) . '">' . esc_html( $field['label'] ) . '</label>';
        self::render_field_help( $field );
        echo '</td>';
        echo '<td><code>' . esc_html( $path ) . '</code></td>';
        echo '<td>' . esc_html( SettingsFields::display_value( $field, $display_value, $config ) ) . '</td>';
        echo '<td>' . esc_html( $source ) . '</td>';
        echo '<td>';
        if ( $externally_controlled ) {
            echo '<span class="description">' . esc_html( 'Controlled externally' ) . '</span>';
        } else {
            echo '<input type="hidden" name="' . esc_attr( SettingsFields::SUBMITTED_PATHS_KEY ) . '[]" value="' . esc_attr( $path ) . '" />';
            self::render_control( $field, $value, $stored );
        }
        echo '</td>';
        echo '</tr>';
    }

    private static function render_control( $field, $value, $stored ) {
        $path = $field['path'];
        $name = SettingsFields::VALUES_KEY . '[' . $path . ']';
        $id = self::field_id( $path );
        $control = isset( $field['control'] ) ? $field['control'] : 'text';

        if ( $control === 'checkbox' ) {
            $checked = $value ? ' checked="checked"' : '';
            echo '<label><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . $checked . ' /> ' . esc_html( 'Enabled' ) . '</label>';
            return;
        }

        if ( $control === 'select' ) {
            echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
            foreach ( $field['select_options'] as $option ) {
                $option_value = $option['value'];
                $option_label = $option['label'];
                $selected = (string) $value === (string) $option_value ? ' selected="selected"' : '';
                echo '<option value="' . esc_attr( $option_value ) . '"' . $selected . '>' . esc_html( $option_label ) . '</option>';
            }
            echo '</select>';
            return;
        }

        if ( ! empty( $field['secret'] ) ) {
            $has_stored = Config::has_path( $stored, explode( '.', $path ) );
            echo '<input id="' . esc_attr( $id ) . '" type="password" name="' . esc_attr( $name ) . '" value="" autocomplete="new-password" />';
            if ( $has_stored ) {
                echo '<p><label><input type="checkbox" name="' . esc_attr( SettingsFields::SECRET_CLEAR_KEY . '[' . $path . ']' ) . '" value="1" /> ' . esc_html( 'Clear stored admin secret' ) . '</label></p>';
            }
            return;
        }

        $type = $control === 'number' ? 'number' : 'text';
        $attrs = '';
        if ( isset( $field['min'] ) ) {
            $attrs .= ' min="' . esc_attr( $field['min'] ) . '"';
        }
        if ( isset( $field['max'] ) ) {
            $attrs .= ' max="' . esc_attr( $field['max'] ) . '"';
        }
        echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $attrs . ' class="regular-text" />';
    }

    private static function render_field_help( $field ) {
        $help = isset( $field['help'] ) && is_array( $field['help'] ) ? $field['help'] : array();
        if ( empty( $help ) ) {
            return;
        }

        $path = isset( $field['path'] ) ? (string) $field['path'] : '';
        $label = isset( $field['label'] ) ? (string) $field['label'] : $path;
        $help_id = self::field_id( $path ) . '-help';

        echo '<details class="eforms-setting-help">';
        echo '<summary aria-label="' . esc_attr( 'Help for ' . $label . ' setting (' . $path . ')' ) . '" aria-controls="' . esc_attr( $help_id ) . '"><span aria-hidden="true">?</span></summary>';
        echo '<div id="' . esc_attr( $help_id ) . '" class="eforms-setting-help-panel" role="note">';
        echo '<button type="button" class="button-link eforms-setting-help-dismiss" aria-label="' . esc_attr( 'Dismiss help for ' . $label . ' setting (' . $path . ')' ) . '">' . esc_html( 'Dismiss' ) . '</button>';
        foreach ( $help as $entry ) {
            echo '<p>' . esc_html( $entry ) . '</p>';
        }
        echo '</div>';
        echo '</details>';
    }

    private static function render_admin_styles() {
        echo '<style>';
        echo '.eforms-settings-admin{max-width:1120px;}';
        echo '.eforms-settings-form{max-width:1040px;}';
        echo '.eforms-settings-nav{position:sticky;z-index:10;top:32px;display:flex;flex-wrap:wrap;gap:6px;max-width:1040px;margin:0 0 24px;padding:10px 0;border-top:1px solid #dcdcde;border-bottom:1px solid #dcdcde;background:#f0f0f1;}';
        echo '.eforms-settings-nav__link{display:inline-flex;align-items:center;min-height:30px;padding:0 10px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;text-decoration:none;}';
        echo '.eforms-settings-nav__save{margin-left:auto;}';
        echo '.eforms-settings-section{max-width:1040px;margin:0 0 32px;scroll-margin-top:108px;}';
        echo '.eforms-settings-panel{overflow:visible;border:1px solid #dcdcde;border-radius:6px;background:#fff;}';
        echo '.eforms-settings-panel__header{padding:16px 18px;border-bottom:1px solid #f0f0f1;}';
        echo '.eforms-settings-panel__header h2,.eforms-settings-section-title{margin:0;font-size:18px;line-height:1.3;}';
        echo '.eforms-settings-panel__body{display:grid;gap:12px;padding:16px;}';
        echo '.eforms-protection-checks{display:grid;gap:12px;}';
        echo '.eforms-settings-subtitle{margin:0;font-size:14px;line-height:1.4;}';
        echo '.eforms-protection-checks-table__row{color:#50575e;}';
        echo '.eforms-protection-checks-table__status{display:inline-block;padding:2px 6px;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;color:#3c434a;}';
        echo '.eforms-settings-admin .eforms-setting-help{display:inline-block;position:relative;margin-left:6px;vertical-align:middle;}';
        echo '.eforms-settings-admin .eforms-setting-help summary{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border:1px solid #8c8f94;border-radius:50%;background:#fff;color:#1d2327;cursor:pointer;font-weight:600;line-height:1;list-style:none;}';
        echo '.eforms-settings-admin .eforms-setting-help summary::-webkit-details-marker{display:none;}';
        echo '.eforms-settings-admin .eforms-setting-help summary:focus{box-shadow:0 0 0 2px #2271b1;outline:2px solid transparent;}';
        echo '.eforms-settings-admin .eforms-setting-help-panel{position:absolute;z-index:20;top:26px;left:0;width:min(360px,70vw);padding:10px 12px;border:1px solid #c3c4c7;background:#fff;box-shadow:0 8px 18px rgba(0,0,0,.16);font-weight:400;}';
        echo '.eforms-settings-admin .eforms-setting-help-dismiss{float:right;margin-left:12px;}';
        echo '.eforms-settings-admin .eforms-setting-help-panel p{margin:.35em 0;}';
        echo '</style>';
    }

    private static function render_help_script() {
        echo '<script>';
        echo '(function(){';
        echo 'if(window.eformsSettingsHelpReady){return;}';
        echo 'window.eformsSettingsHelpReady=true;';
        echo 'function closeHelp(except){document.querySelectorAll(".eforms-setting-help[open]").forEach(function(node){if(node!==except){node.removeAttribute("open");}});}';
        echo 'document.addEventListener("click",function(event){';
        echo 'var target=event.target;';
        echo 'var help=target.closest?target.closest(".eforms-setting-help"):null;';
        echo 'var dismiss=target.closest?target.closest(".eforms-setting-help-dismiss"):null;';
        echo 'closeHelp(help);';
        echo 'if(dismiss&&help){help.removeAttribute("open");var summary=help.querySelector("summary");if(summary){summary.focus();}}';
        echo '});';
        echo 'document.addEventListener("keydown",function(event){if(event.key==="Escape"){closeHelp(null);}});';
        echo '}());';
        echo '</script>';
    }

    private static function render_storage_table( $config, $report ) {
        $uploads_dir = Config::value( $config, array( 'uploads', 'dir' ), '' );
        $uploads_entry = isset( $report['uploads.dir'] ) && is_array( $report['uploads.dir'] ) ? $report['uploads.dir'] : array();
        $uploads_source = isset( $uploads_entry['source'] ) ? (string) $uploads_entry['source'] : 'default';
        $status = 'Unavailable';
        if ( is_string( $uploads_dir ) && $uploads_dir !== '' && is_dir( $uploads_dir ) && is_writable( $uploads_dir ) ) {
            $status = 'Writable';
        }

        echo '<section id="eforms-settings-storage" class="eforms-settings-section">';
        echo '<div class="eforms-settings-panel">';
        echo '<div class="eforms-settings-panel__header"><h2 class="eforms-settings-section-title">' . esc_html( 'Storage' ) . '</h2></div>';
        echo '<div class="eforms-settings-panel__body">';
        echo '<table class="widefat striped eforms-settings-table" aria-label="' . esc_attr( 'Storage settings' ) . '">';
        self::render_settings_table_head();
        echo '<tbody>';
        echo '<tr><td>' . esc_html( 'Storage Base' ) . '</td><td><code>' . esc_html( 'uploads.dir' ) . '</code></td><td>' . esc_html( $status ) . '</td><td>' . esc_html( $uploads_source ) . '</td><td>' . esc_html( 'Read-only' ) . '</td></tr>';
        echo '</tbody></table>';
        echo '</div></div></section>';
    }

    private static function notice( $type, $message ) {
        return array( 'type' => $type, 'message' => $message );
    }

    private static function render_notice( $notice ) {
        $type = isset( $notice['type'] ) && $notice['type'] === 'success' ? 'success' : 'error';
        $message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
        echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
    }

    private static function field_id( $path ) {
        return 'eforms-setting-' . preg_replace( '/[^a-z0-9_-]+/i', '-', $path );
    }

    private static function section_id( $label ) {
        return 'eforms-settings-' . strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $label ) );
    }

    private static function can_manage() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }

    private static function nonce_field( $action, $field ) {
        if ( function_exists( 'wp_nonce_field' ) ) {
            wp_nonce_field( $action, $field );
            return;
        }
        echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="" />';
    }

    private static function verify_nonce( $nonce, $action ) {
        return function_exists( 'wp_verify_nonce' ) && wp_verify_nonce( $nonce, $action );
    }

    private static function submit_button() {
        if ( function_exists( 'submit_button' ) ) {
            submit_button( 'Save Changes' );
            return;
        }
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html( 'Save Changes' ) . '</button></p>';
    }

    private static function unslash( $value ) {
        return function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
    }
}
