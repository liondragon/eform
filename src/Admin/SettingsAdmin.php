<?php
/**
 * Settings -> eForms admin surface.
 *
 * Spec: Configuration (docs/Canonical_Spec.md#sec-configuration)
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
        if ( is_array( $notice ) ) {
            self::render_notice( $notice );
        }

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

        echo '<form method="post" action="">';
        echo '<input type="hidden" name="eforms_settings_action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
        self::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        echo '<table class="widefat striped eforms-settings-table"><thead><tr>';
        echo '<th>' . esc_html( 'Name' ) . '</th><th>' . esc_html( 'Config Handle' ) . '</th><th>' . esc_html( 'Effective' ) . '</th><th>' . esc_html( 'Source' ) . '</th><th>' . esc_html( 'Setting' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( SettingsFields::groups() as $group ) {
            echo '<tr><th colspan="5">' . esc_html( $group['label'] ) . '</th></tr>';
            foreach ( $group['fields'] as $field ) {
                self::render_field_row( $field, $config, $report, $stored );
            }
        }
        self::render_storage_row( $config, $report );

        echo '</tbody></table>';
        self::submit_button();
        echo '</form>';
    }

    private static function render_diagnostics_section( $spam_result, $runtime_result ) {
        echo '<h2>' . esc_html( 'Diagnostics' ) . '</h2>';
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
        echo '<td><label for="' . esc_attr( self::field_id( $path ) ) . '">' . esc_html( $field['label'] ) . '</label></td>';
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
            $values = isset( $field['values'] ) && is_array( $field['values'] ) ? $field['values'] : array();
            foreach ( $values as $option ) {
                $selected = (string) $value === (string) $option ? ' selected="selected"' : '';
                echo '<option value="' . esc_attr( $option ) . '"' . $selected . '>' . esc_html( self::option_label( $option ) ) . '</option>';
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

    private static function render_storage_row( $config, $report ) {
        $uploads_dir = Config::value( $config, array( 'uploads', 'dir' ), '' );
        $uploads_entry = isset( $report['uploads.dir'] ) && is_array( $report['uploads.dir'] ) ? $report['uploads.dir'] : array();
        $uploads_source = isset( $uploads_entry['source'] ) ? (string) $uploads_entry['source'] : 'default';
        $status = 'Unavailable';
        if ( is_string( $uploads_dir ) && $uploads_dir !== '' && is_dir( $uploads_dir ) && is_writable( $uploads_dir ) ) {
            $status = 'Writable';
        }

        echo '<tr><th colspan="5">' . esc_html( 'Storage' ) . '</th></tr>';
        echo '<tr><td>' . esc_html( 'Storage Base' ) . '</td><td><code>' . esc_html( 'uploads.dir' ) . '</code></td><td>' . esc_html( $status ) . '</td><td>' . esc_html( $uploads_source ) . '</td><td>' . esc_html( 'Read-only' ) . '</td></tr>';
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

    private static function option_label( $value ) {
        return ucwords( str_replace( '_', ' ', (string) $value ) );
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
