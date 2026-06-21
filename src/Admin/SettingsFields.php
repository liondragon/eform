<?php
/**
 * Field matrix and form mapper for Settings -> eForms.
 *
 * Contract: Configuration
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Security/Challenge.php';

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
                    self::field(
                        'declined_review.enable',
                        'Enable declined review',
                        'checkbox',
                        $schema,
                        array(
                            'help' => array(
                                'Turn this on when you want administrators to inspect some declined submissions for possible false positives.',
                                'Off: declined form content is not captured for review.',
                                'On: selected spam-review outcomes are saved in the declined-review viewer with bounded content and normal retention cleanup.',
                            ),
                        )
                    ),
                    self::field(
                        'declined_review.retention_days',
                        'Retention days',
                        'number',
                        $schema,
                        array(
                            'help' => array(
                                'Controls how long declined-review files are kept before cleanup can remove them.',
                                'Leave blank to use the logging retention period.',
                                'Use a whole number of days when you want declined-review content to expire sooner or later than operational logs.',
                            ),
                        )
                    ),
                ),
            ),
            'logging' => array(
                'label' => 'Logging',
                'fields' => array(
                    self::field(
                        'logging.mode',
                        'Mode',
                        'select',
                        $schema,
                        array(
                            'help' => array(
                                'Chooses where eForms writes operational events.',
                                'Off: no plugin log files are written, apart from any separate Fail2ban setup.',
                                'Minimal: writes compact entries to the normal PHP/WordPress error log.',
                                'Jsonl: writes structured log files that are easier to inspect during spam or delivery troubleshooting.',
                            ),
                        )
                    ),
                    self::field(
                        'logging.level',
                        'Level',
                        'number',
                        $schema,
                        array(
                            'help' => array(
                                'Controls how much detail logging includes.',
                                '0: errors only.',
                                '1: errors plus warnings such as rejected submissions, challenge failures, or configuration issues.',
                                '2: errors, warnings, and informational events such as successful sends and cleanup summaries.',
                            ),
                        )
                    ),
                    self::field(
                        'logging.retention_days',
                        'Retention days',
                        'number',
                        $schema,
                        array(
                            'help' => array(
                                'Controls how long plugin log files are kept before cleanup can remove old files.',
                                'Use a lower number to reduce stored operational history.',
                                'Use a higher number when you need more time to investigate spam, delivery, or configuration issues.',
                            ),
                        )
                    ),
                ),
            ),
            'spam' => array(
                'label' => 'Spam Protection',
                'fields' => array(
                    self::field(
                        'spam.soft_fail_threshold',
                        'Rejection threshold',
                        'number',
                        $schema,
                        array(
                            'help' => array(
                                'Controls how many suspicious signals are needed before eForms rejects a submission as spam.',
                                '1: strict; one signal such as missing JavaScript is enough to reject.',
                                '2: balanced default; two signals together reject while one signal can still be treated as suspect.',
                                'Higher numbers are more forgiving and can let more spam through.',
                            ),
                        )
                    ),
                    self::field(
                        'security.min_fill_seconds',
                        'Minimum fill time',
                        'number',
                        $schema,
                        array(
                            'help' => array(
                                'Marks a submission suspicious when it arrives faster than a real visitor could reasonably fill the form.',
                                '0: disabled; fast submissions do not add this signal.',
                                'Values above 0 add the min_fill_time signal when the form is submitted too quickly.',
                            ),
                        )
                    ),
                    self::field(
                        'security.honeypot_response',
                        'Spam rejection response',
                        'select',
                        $schema,
                        array(
                            'help' => array(
                                'Controls what rejected spam sees when eForms blocks the submission.',
                                'Stealth success: rejected spam sees a success-shaped response, but eForms skips validation and email.',
                                'Hard fail: rejected spam sees a generic form error.',
                                'Used when the hidden trap is filled or when suspicious signals reach the rejection threshold.',
                            ),
                        )
                    ),
                ),
            ),
            'challenge' => array(
                'label' => 'Challenge',
                'fields' => array(
                    self::field(
                        'challenge.mode',
                        'Mode',
                        'select',
                        $schema,
                        array(
                            'display' => 'challenge_status',
                            'help' => array(
                                'Controls when visitors must complete Turnstile verification.',
                                'Off: no challenge is shown.',
                                'Auto: only suspicious submissions are asked to verify.',
                                'Always post: every submitted form must pass verification.',
                                'Any mode other than Off requires both Turnstile keys.',
                            ),
                        )
                    ),
                    self::field(
                        'challenge.site_key',
                        'Site key',
                        'text',
                        $schema,
                        array(
                            'help' => array(
                                'Paste the public Turnstile site key for this site.',
                                'Leave blank to clear the admin override or to rely on a drop-in file or filter.',
                                'Required together with the secret key when challenge mode is Auto or Always post.',
                            ),
                        )
                    ),
                    self::field(
                        'challenge.secret_key',
                        'Secret key',
                        'password',
                        $schema,
                        array(
                            'help' => array(
                                'Paste the private Turnstile secret key used to verify challenge responses.',
                                'A saved secret is never shown on this page.',
                                'Leave the field blank to keep the current admin secret, or use the clear checkbox to remove the stored admin secret.',
                            ),
                        )
                    ),
                ),
            ),
            'throttle' => array(
                'label' => 'Throttle',
                'fields' => array(
                    self::field(
                        'throttle.enable',
                        'Enable throttle',
                        'checkbox',
                        $schema,
                        array(
                            'help' => array(
                                'Turns on the built-in per-IP rate limit for form requests.',
                                'Off: eForms does not apply its file-based rate limit.',
                                'On: repeated requests from the same IP can be temporarily blocked before normal form processing.',
                                'Use this only when your host supports reliable file locking.',
                            ),
                        )
                    ),
                    self::field(
                        'throttle.per_ip.max_per_minute',
                        'Max per minute',
                        'number',
                        $schema,
                        array(
                            'help' => array(
                                'Sets how many form requests one IP can make in a 60 second window before throttling starts.',
                                'Lower values are stricter and can stop bursts sooner.',
                                'Higher values are more forgiving for busy offices, shared networks, or testing.',
                            ),
                        )
                    ),
                    self::field(
                        'throttle.per_ip.cooldown_seconds',
                        'Cooldown seconds',
                        'number',
                        $schema,
                        array(
                            'help' => array(
                                'Sets how long an IP remains blocked after it goes over the per-minute limit.',
                                '0: no cooldown sentinel is used after the limit is hit.',
                                'Any value above 0: block that IP for that many seconds before allowing another try.',
                            ),
                        )
                    ),
                ),
            ),
            'privacy' => array(
                'label' => 'Privacy',
                'fields' => array(
                    self::field(
                        'privacy.ip_mode',
                        'IP mode',
                        'select',
                        $schema,
                        array(
                            'help' => array(
                                'Controls how visitor IP addresses appear in logs, emails, and declined-review records.',
                                'None: do not show the IP in those operator-facing outputs.',
                                'Masked: show a shortened IP.',
                                'Hash: show a one-way hash so repeated activity can be linked without showing the raw IP.',
                                'Full: show the full IP address.',
                                'Throttle and Fail2ban enforcement still use the resolved IP internally.',
                            ),
                        )
                    ),
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
        $custom_help = isset( $metadata['help'] ) && is_array( $metadata['help'] ) ? $metadata['help'] : array();
        unset( $metadata['help'] );

        $field = array(
            'path' => $path,
            'label' => $label,
            'control' => $control,
        );
        foreach ( array( 'secret', 'nullable' ) as $key ) {
            if ( ! empty( $rule[ $key ] ) ) {
                $field[ $key ] = true;
            }
        }
        if ( $control === 'number' ) {
            foreach ( array( 'min', 'max' ) as $key ) {
                if ( array_key_exists( $key, $rule ) ) {
                    $field[ $key ] = $rule[ $key ];
                }
            }
        }
        foreach ( $metadata as $key => $value ) {
            $field[ $key ] = $value;
        }
        if ( $control === 'select' ) {
            $field['select_options'] = self::select_options( $rule );
        }
        $field['help'] = self::help_entries( $field, $custom_help );

        return $field;
    }

    private static function select_options( $field ) {
        $values = isset( $field['values'] ) && is_array( $field['values'] ) ? $field['values'] : array();
        $options = array();
        foreach ( $values as $value ) {
            $options[] = array(
                'value' => $value,
                'label' => self::option_label( $value ),
            );
        }

        return $options;
    }

    private static function option_label( $value ) {
        return ucwords( str_replace( '_', ' ', (string) $value ) );
    }

    private static function help_entries( $field, $custom_help ) {
        $entries = array();
        $options = self::help_options_entry( $field );
        if ( $options !== '' ) {
            $entries[] = $options;
        }

        foreach ( $custom_help as $entry ) {
            if ( is_string( $entry ) && trim( $entry ) !== '' ) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private static function help_options_entry( $field ) {
        $control = isset( $field['control'] ) ? (string) $field['control'] : 'text';
        if ( $control === 'select' ) {
            $options = isset( $field['select_options'] ) && is_array( $field['select_options'] ) ? $field['select_options'] : array();
            $labels = array();
            foreach ( $options as $option ) {
                if ( isset( $option['label'] ) ) {
                    $labels[] = (string) $option['label'];
                }
            }
            return empty( $labels ) ? '' : 'Available options: ' . implode( ', ', $labels ) . '.';
        }

        if ( $control === 'number' ) {
            $parts = array();
            if ( isset( $field['min'], $field['max'] ) ) {
                $parts[] = 'Available range: ' . $field['min'] . ' to ' . $field['max'] . '.';
            }
            return implode( ' ', $parts );
        }

        return '';
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

        $status = Challenge::configuration_status( $config );
        return ! empty( $status['configured'] ) ? 'Configured' : 'Missing keys';
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
