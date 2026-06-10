<?php
/**
 * Field matrix and form mapper for Settings -> eForms.
 *
 * Spec: Configuration (docs/Canonical_Spec.md#sec-configuration)
 */

require_once __DIR__ . '/../Config.php';

class SettingsFields {
    const VALUES_KEY = 'eforms_settings';
    const SUBMITTED_PATHS_KEY = 'eforms_submitted_paths';
    const SECRET_CLEAR_KEY = 'eforms_secret_clear';

    public static function groups() {
        $schema = Config::admin_schema();
        $groups = array(
            'declined_review' => array(
                'label' => 'Declined Review',
                'fields' => array(
                    self::field( 'declined_review.enable', 'Enable declined review', 'checkbox', $schema ),
                    self::field( 'declined_review.retention_days', 'Retention days', 'number', $schema ),
                ),
            ),
            'logging' => array(
                'label' => 'Logging',
                'fields' => array(
                    self::field( 'logging.mode', 'Mode', 'select', $schema ),
                    self::field( 'logging.level', 'Level', 'number', $schema ),
                    self::field( 'logging.retention_days', 'Retention days', 'number', $schema ),
                ),
            ),
            'challenge' => array(
                'label' => 'Challenge',
                'fields' => array(
                    self::field( 'challenge.mode', 'Mode', 'select', $schema, array( 'display' => 'challenge_status' ) ),
                    self::field( 'challenge.site_key', 'Site key', 'text', $schema ),
                    self::field( 'challenge.secret_key', 'Secret key', 'password', $schema ),
                ),
            ),
            'throttle' => array(
                'label' => 'Throttle',
                'fields' => array(
                    self::field( 'throttle.enable', 'Enable throttle', 'checkbox', $schema ),
                    self::field( 'throttle.per_ip.max_per_minute', 'Max per minute', 'number', $schema ),
                    self::field( 'throttle.per_ip.cooldown_seconds', 'Cooldown seconds', 'number', $schema ),
                ),
            ),
            'privacy' => array(
                'label' => 'Privacy',
                'fields' => array(
                    self::field( 'privacy.ip_mode', 'IP mode', 'select', $schema ),
                ),
            ),
        );

        return $groups;
    }

    public static function field_paths() {
        return array_keys( self::fields_by_path() );
    }

    public static function fields_by_path() {
        $fields = array();
        foreach ( self::groups() as $group ) {
            foreach ( $group['fields'] as $field ) {
                $fields[ $field['path'] ] = $field;
            }
        }

        return $fields;
    }

    public static function display_value( $field, $value, $config ) {
        if ( isset( $field['display'] ) && $field['display'] === 'challenge_status' ) {
            return self::challenge_status( $config );
        }
        if ( isset( $field['control'] ) && $field['control'] === 'checkbox' ) {
            return $value ? 'On' : 'Off';
        }

        return self::stringify( $value );
    }

    public static function overrides_from_submission( $post, $current_overrides, $effective_report ) {
        $post = is_array( $post ) ? $post : array();
        if ( isset( $post[ self::VALUES_KEY ] ) && ! is_array( $post[ self::VALUES_KEY ] ) ) {
            return self::error( self::VALUES_KEY, 'type' );
        }
        if ( isset( $post[ self::SUBMITTED_PATHS_KEY ] ) && ! is_array( $post[ self::SUBMITTED_PATHS_KEY ] ) ) {
            return self::error( self::SUBMITTED_PATHS_KEY, 'submitted_path' );
        }
        if ( isset( $post[ self::SECRET_CLEAR_KEY ] ) && ! is_array( $post[ self::SECRET_CLEAR_KEY ] ) ) {
            return self::error( self::SECRET_CLEAR_KEY, 'secret_action' );
        }

        $values = isset( $post[ self::VALUES_KEY ] ) ? $post[ self::VALUES_KEY ] : array();
        $submitted = isset( $post[ self::SUBMITTED_PATHS_KEY ] ) ? $post[ self::SUBMITTED_PATHS_KEY ] : array();
        $secret_clear = isset( $post[ self::SECRET_CLEAR_KEY ] ) ? $post[ self::SECRET_CLEAR_KEY ] : array();

        $fields = self::fields_by_path();

        $submitted_map = array();
        foreach ( $submitted as $path ) {
            $path = (string) $path;
            if ( ! isset( $fields[ $path ] ) ) {
                return self::error( $path, 'submitted_path' );
            }
            $submitted_map[ $path ] = true;
        }

        foreach ( array_keys( $values ) as $path ) {
            $path = (string) $path;
            if ( ! isset( $fields[ $path ] ) ) {
                return self::error( $path, 'unknown' );
            }
            if ( ! isset( $submitted_map[ $path ] ) ) {
                return self::error( $path, 'submitted_path' );
            }
        }

        foreach ( array_keys( $secret_clear ) as $path ) {
            $path = (string) $path;
            if ( ! isset( $fields[ $path ] ) || empty( $fields[ $path ]['secret'] ) ) {
                return self::error( $path, 'secret_action' );
            }
            if ( ! isset( $submitted_map[ $path ] ) ) {
                return self::error( $path, 'submitted_path' );
            }
        }

        $flat = self::flatten_known_overrides( $current_overrides, $fields );
        foreach ( $fields as $path => $field ) {
            if ( self::is_externally_controlled( $path, $effective_report ) ) {
                continue;
            }

            if ( ! isset( $submitted_map[ $path ] ) ) {
                continue;
            }

            $control = isset( $field['control'] ) ? $field['control'] : '';
            if ( $control === 'checkbox' ) {
                $flat[ $path ] = isset( $values[ $path ] ) && (string) $values[ $path ] === '1';
                continue;
            }

            if ( ! array_key_exists( $path, $values ) ) {
                return self::error( $path, 'submitted_value' );
            }

            $raw = $values[ $path ];
            if ( is_array( $raw ) ) {
                return self::error( $path, 'type' );
            }

            if ( ! empty( $field['secret'] ) ) {
                $clear = isset( $secret_clear[ $path ] ) && (string) $secret_clear[ $path ] === '1';
                $replacement = trim( (string) $raw );
                if ( $clear && $replacement !== '' ) {
                    return self::error( $path, 'secret_action' );
                }
                if ( $clear ) {
                    unset( $flat[ $path ] );
                    continue;
                }
                if ( $replacement !== '' ) {
                    $flat[ $path ] = $replacement;
                }
                continue;
            }

            $value = trim( (string) $raw );
            if ( $value === '' && ( ! empty( $field['nullable'] ) || $control === 'text' ) ) {
                unset( $flat[ $path ] );
                continue;
            }

            $flat[ $path ] = $value;
        }

        $validated = Config::validate_admin_flat_overrides( $flat );
        if ( empty( $validated['ok'] ) ) {
            return $validated;
        }

        return array( 'ok' => true, 'overrides' => $validated['overrides'], 'errors' => array() );
    }

    private static function field( $path, $label, $control, $schema, $metadata = array() ) {
        $rule = isset( $schema[ $path ] ) ? $schema[ $path ] : array();
        $field = $rule;
        $field['path'] = $path;
        $field['label'] = $label;
        $field['control'] = $control;
        foreach ( $metadata as $key => $value ) {
            $field[ $key ] = $value;
        }

        return $field;
    }

    private static function is_externally_controlled( $path, $effective_report ) {
        return isset( $effective_report[ $path ]['externally_controlled'] )
            && (bool) $effective_report[ $path ]['externally_controlled'];
    }

    private static function stringify( $value ) {
        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        if ( $value === null ) {
            return '';
        }
        if ( is_array( $value ) ) {
            return wp_json_encode( $value );
        }
        return (string) $value;
    }

    private static function challenge_status( $config ) {
        $mode = (string) Config::value( $config, array( 'challenge', 'mode' ), 'off' );
        if ( $mode === 'off' ) {
            return 'Off';
        }

        $site = (string) Config::value( $config, array( 'challenge', 'site_key' ), '' );
        $secret = (string) Config::value( $config, array( 'challenge', 'secret_key' ), '' );
        return $site !== '' && $secret !== '' ? 'Configured' : 'Missing keys';
    }

    private static function flatten_known_overrides( $overrides, $fields ) {
        $flat = array();
        foreach ( array_keys( $fields ) as $path ) {
            $segments = explode( '.', $path );
            if ( Config::has_path( $overrides, $segments ) ) {
                $flat[ $path ] = Config::value( $overrides, $segments, null );
            }
        }

        return $flat;
    }

    private static function error( $path, $reason ) {
        return array(
            'ok' => false,
            'overrides' => array(),
            'errors' => array( array( 'path' => $path, 'reason' => $reason ) ),
        );
    }
}
