<?php
/**
 * Settings -> eForms admin surface.
 *
 * Contract: Configuration
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../EformsAssets.php';
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
    const CONTENT_FILTER_MODE_PATH = 'spam.content_filter.mode';
    const CONTENT_FILTER_TERMS_PATH = 'spam.content_filter.blocked_terms';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function enqueue_assets( $hook_suffix ) {
        if ( $hook_suffix === 'settings_page_' . self::SLUG ) {
            EformsAssets::enqueue_admin_settings();
        }
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
        self::render_storage_table( $config );
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
        $fields_by_path = self::fields_by_path( $fields );
        foreach ( $fields as $field ) {
            if ( $group_key === 'spam' && $field['path'] === self::CONTENT_FILTER_MODE_PATH && isset( $fields_by_path[ self::CONTENT_FILTER_TERMS_PATH ] ) ) {
                continue;
            }
            if ( $group_key === 'spam' && $field['path'] === self::CONTENT_FILTER_TERMS_PATH && isset( $fields_by_path[ self::CONTENT_FILTER_MODE_PATH ] ) ) {
                self::render_blocked_content_row( $fields_by_path[ self::CONTENT_FILTER_MODE_PATH ], $field, $config, $report, $stored );
                continue;
            }
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
        echo '<th>' . esc_html( 'Name' ) . '</th><th>' . esc_html( 'Setting' ) . '</th>';
        echo '</tr></thead>';
    }

    private static function fields_by_path( $fields ) {
        $out = array();
        foreach ( $fields as $field ) {
            if ( isset( $field['path'] ) ) {
                $out[ $field['path'] ] = $field;
            }
        }

        return $out;
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
        echo '<tr>';
        echo '<td><label class="eforms-settings-row-label" for="' . esc_attr( self::field_id( $field['path'] ) ) . '">' . esc_html( $field['label'] ) . '</label>';
        self::render_field_help( $field );
        echo '</td>';
        echo '<td>';
        self::render_setting_cell( $field, $config, $report, $stored );
        echo '</td>';
        echo '</tr>';
    }

    private static function render_blocked_content_row( $mode_field, $terms_field, $config, $report, $stored ) {
        echo '<tr class="eforms-settings-table__grouped-row eforms-settings-blocked-content-row">';
        echo '<td><span class="eforms-settings-row-label">' . esc_html( 'Blocked content' ) . '</span></td>';
        echo '<td><div class="eforms-settings-compound-control">';
        self::render_compound_setting( $mode_field, $config, $report, $stored );
        self::render_compound_setting( $terms_field, $config, $report, $stored );
        echo '</div></td>';
        echo '</tr>';
    }

    private static function render_compound_setting( $field, $config, $report, $stored ) {
        echo '<div class="eforms-settings-compound-control__item">';
        echo '<div class="eforms-settings-compound-control__label"><label for="' . esc_attr( self::field_id( $field['path'] ) ) . '">' . esc_html( $field['label'] ) . '</label>';
        self::render_field_help( $field );
        echo '</div>';
        self::render_setting_cell( $field, $config, $report, $stored );
        echo '</div>';
    }

    private static function render_setting_cell( $field, $config, $report, $stored ) {
        $path = $field['path'];
        $report_entry = isset( $report[ $path ] ) && is_array( $report[ $path ] ) ? $report[ $path ] : array();
        $externally_controlled = isset( $report_entry['externally_controlled'] ) && (bool) $report_entry['externally_controlled'];
        $source = isset( $report_entry['source'] ) ? (string) $report_entry['source'] : 'default';
        $value = Config::value( $config, explode( '.', $path ), '' );
        $display_value = array_key_exists( 'display_value', $report_entry ) ? $report_entry['display_value'] : $value;

        if ( $externally_controlled ) {
            echo '<span class="eforms-settings-readonly-value">' . esc_html( SettingsFields::display_value( $field, $display_value, $config ) ) . '</span>';
            echo '<p class="description">' . esc_html( 'Controlled externally by ' . $source . '.' ) . '</p>';
        } else {
            echo '<input type="hidden" name="' . esc_attr( SettingsFields::SUBMITTED_PATHS_KEY ) . '[]" value="' . esc_attr( $path ) . '" />';
            self::render_control( $field, $value, $stored );
            self::render_control_status( $field, $display_value, $config );
        }
    }

    private static function render_control_status( $field, $display_value, $config ) {
        if ( ! empty( $field['secret'] ) ) {
            $display = SettingsFields::display_value( $field, $display_value, $config );
            if ( $display !== '' ) {
                echo '<p class="description">' . esc_html( 'Stored: ' . $display ) . '</p>';
            }
            return;
        }

        if ( ! isset( $field['display'] ) || $field['display'] !== 'challenge_status' ) {
            return;
        }

        echo '<p class="description">' . esc_html( 'Status: ' . SettingsFields::display_value( $field, $display_value, $config ) ) . '</p>';
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

        if ( $control === 'textarea' ) {
            echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="6" class="large-text code">' . self::esc_textarea( $value ) . '</textarea>';
            return;
        }

        if ( $control === 'content_terms' ) {
            self::render_content_terms_editor( $id, $name, $value );
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
        if ( $path !== '' ) {
            echo '<p>' . esc_html( 'Config handle: ' ) . '<code>' . esc_html( $path ) . '</code></p>';
        }
        foreach ( $help as $entry ) {
            echo '<p>' . esc_html( $entry ) . '</p>';
        }
        echo '</div>';
        echo '</details>';
    }

    private static function render_content_terms_editor( $id, $name, $value ) {
        echo '<div class="eforms-content-terms-editor" data-eforms-content-terms-editor data-source="' . esc_attr( $id ) . '" hidden>';
        echo '<ul class="eforms-content-terms-editor__list" data-eforms-content-list></ul>';
        echo '<div class="eforms-content-terms-editor__entry">';
        echo '<textarea class="large-text code" rows="2" data-eforms-content-entry aria-label="' . esc_attr( 'Add blocked phrase' ) . '"></textarea>';
        echo '<button type="button" class="button" data-eforms-content-add>' . esc_html( 'Add' ) . '</button>';
        echo '</div>';
        echo '<p class="eforms-content-terms-editor__message" data-eforms-content-message role="status" aria-live="polite"></p>';
        echo '</div>';
        echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="6" class="large-text code eforms-content-terms-editor__textarea" data-eforms-content-terms-source>' . self::esc_textarea( $value ) . '</textarea>';
    }

    private static function render_storage_table( $config ) {
        $uploads_dir = Config::value( $config, array( 'uploads', 'dir' ), '' );
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
        echo '<tr><td>' . esc_html( 'Storage Base' ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
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

    private static function esc_textarea( $value ) {
        if ( function_exists( 'esc_textarea' ) ) {
            return esc_textarea( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}
