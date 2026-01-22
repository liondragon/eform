<?php
/**
 * Configuration bootstrap and snapshot.
 */

require_once __DIR__ . '/Anchors.php';

class Config
{
    const DROPIN_FILENAME = 'eforms.config.php';

    const DEFAULT_TOKEN_TTL_SECONDS = 3600;
    const DEFAULT_MIN_FILL_SECONDS = 0;
    const DEFAULT_SPAM_SOFT_FAIL_THRESHOLD = 2;
    const DEFAULT_CHALLENGE_TIMEOUT_SECONDS = 3;
    const DEFAULT_THROTTLE_MAX_PER_MINUTE = 30;
    const DEFAULT_THROTTLE_COOLDOWN_SECONDS = 0;
    const DEFAULT_LOGGING_LEVEL = 1;
    const DEFAULT_LOGGING_RETENTION_DAYS = 30;
    const DEFAULT_VALIDATION_MAX_FIELDS = 250;
    const DEFAULT_VALIDATION_MAX_OPTIONS = 200;
    const DEFAULT_VALIDATION_MAX_MULTIVALUE = 100;
    const BYTES_IN_MIB = 1048576;
    const DEFAULT_UPLOAD_MAX_EMAIL_BYTES = self::BYTES_IN_MIB * 10;
    const DEFAULT_UPLOAD_ORIGINAL_MAXLEN = 128;
    const DEFAULT_EMAIL_MAX_ATTACHMENTS = 5;


    const DEFAULTS = array(
        'security' => array(
            'origin_mode' => 'soft',
            'origin_missing_hard' => false,
            'honeypot_response' => 'stealth_success',
            'min_fill_seconds' => self::DEFAULT_MIN_FILL_SECONDS,
            'token_ttl_seconds' => self::DEFAULT_TOKEN_TTL_SECONDS,
            'max_form_age_seconds' => null,
            'max_post_bytes' => PHP_INT_MAX,
            'js_hard_mode' => false,
        ),
        'spam' => array(
            'soft_fail_threshold' => self::DEFAULT_SPAM_SOFT_FAIL_THRESHOLD,
        ),
        'challenge' => array(
            'mode' => 'off',
            'provider' => 'turnstile',
            'site_key' => '',
            'secret_key' => '',
            'http_timeout_seconds' => self::DEFAULT_CHALLENGE_TIMEOUT_SECONDS,
        ),
        'email' => array(
            'from_address' => '',
            'reply_to_address' => '',
            'reply_to_field' => '',
            'html' => false,
            'suspect_subject_tag' => '[Suspect]',
            'upload_max_attachments' => self::DEFAULT_EMAIL_MAX_ATTACHMENTS,
        ),
        'html5' => array(
            'client_validation' => true,
        ),
        'throttle' => array(
            'enable' => false,
            'per_ip' => array(
                'max_per_minute' => self::DEFAULT_THROTTLE_MAX_PER_MINUTE,
                'cooldown_seconds' => self::DEFAULT_THROTTLE_COOLDOWN_SECONDS,
            ),
        ),
        'logging' => array(
            'mode' => 'minimal',
            'level' => self::DEFAULT_LOGGING_LEVEL,
            'headers' => false,
            'pii' => false,
            'retention_days' => self::DEFAULT_LOGGING_RETENTION_DAYS,
            'fail2ban' => array(
                'target' => 'file',
                'file' => '',
                'retention_days' => null,
            ),
        ),
        'privacy' => array(
            'ip_mode' => 'masked',
            'client_ip_header' => '',
            'trusted_proxies' => array(),
        ),
        'validation' => array(
            'max_fields_per_form' => self::DEFAULT_VALIDATION_MAX_FIELDS,
            'max_options_per_group' => self::DEFAULT_VALIDATION_MAX_OPTIONS,
            'max_items_per_multivalue' => self::DEFAULT_VALIDATION_MAX_MULTIVALUE,
        ),
        'uploads' => array(
            'dir' => '',
            'enable' => false,
            'total_request_bytes' => PHP_INT_MAX,
            'max_email_bytes' => self::DEFAULT_UPLOAD_MAX_EMAIL_BYTES,
            'retention_seconds' => 0,
            'original_maxlen' => self::DEFAULT_UPLOAD_ORIGINAL_MAXLEN,
        ),
        'assets' => array(
            'css_disable' => false,
        ),
        'install' => array(
            'min_php_version' => '8.1',
            'min_wp_version' => '5.8',
            'uninstall' => array(
                'purge_logs' => false,
                'purge_uploads' => false,
            ),
        ),
    );

    private static $snapshot = null;
    private static $bootstrapped = false;

    /**
     * Return the frozen config snapshot for the current request.
     */
    public static function get()
    {
        if (!self::$bootstrapped) {
            self::bootstrap();
        }

        return self::deep_copy(self::$snapshot);
    }

    /**
     * Build the per-request snapshot (idempotent per request).
     */
    public static function bootstrap()
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        $defaults = self::defaults();
        $config = $defaults;
        $dropin_schema_errors = array();

        list($dropin, $dropin_error) = self::load_dropin();
        if ($dropin_error !== null) {
            self::log_config_warning($config, $dropin_error['code'], $dropin_error['meta']);
        }

        if (is_array($dropin)) {
            $dropin = self::sanitize_override_schema($dropin, $defaults, $dropin_schema_errors, '');
            $config = self::merge_overrides_or_default($config, $dropin, true);
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('eforms_config', $config);
            if (is_array($filtered)) {
                $filter_schema_errors = array();
                $filtered = self::sanitize_override_schema($filtered, $defaults, $filter_schema_errors, '');
                $config = self::merge_overrides_or_default($config, $filtered, false);
            }
        }

        $schema_errors = array();
        $config = self::sanitize_config_schema($config, $defaults, $schema_errors, '');
        $config = self::apply_anchor_clamps($config, $defaults);
        self::emit_dropin_schema_errors($config, $dropin_schema_errors);

        self::$snapshot = self::deep_copy($config);
    }

    /**
     * Build defaults with runtime-derived values (e.g., uploads.dir).
     */
    public static function defaults()
    {
        $defaults = self::deep_copy(self::DEFAULTS);

        if (isset($defaults['security']['max_form_age_seconds']) && $defaults['security']['max_form_age_seconds'] === null) {
            $defaults['security']['max_form_age_seconds'] = $defaults['security']['token_ttl_seconds'];
        }

        if (isset($defaults['logging']['fail2ban']['retention_days']) && $defaults['logging']['fail2ban']['retention_days'] === null) {
            $defaults['logging']['fail2ban']['retention_days'] = $defaults['logging']['retention_days'];
        }

        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            if (is_array($uploads) && isset($uploads['basedir']) && $uploads['basedir'] !== '') {
                $defaults['uploads']['dir'] = $uploads['basedir'];
            }
        }

        return $defaults;
    }

    /**
     * Test-only helper to reset the snapshot between unit cases.
     */
    public static function reset_for_tests()
    {
        self::$snapshot = null;
        self::$bootstrapped = false;
    }

    private static function load_dropin()
    {
        $path = self::dropin_path();
        if ($path === null) {
            return array(null, null);
        }

        if (!file_exists($path)) {
            return array(null, null);
        }

        if (!is_readable($path)) {
            return array(
                null,
                array(
                    'code' => 'EFORMS_CONFIG_DROPIN_IO',
                    'meta' => array('path' => $path, 'reason' => 'unreadable'),
                )
            );
        }

        $errors = array();
        $handler = function ($errno, $errstr) use (&$errors) {
            $errors[] = array('errno' => $errno, 'message' => $errstr);
            return true;
        };

        $result = null;
        ob_start();
        set_error_handler($handler);
        $result = include $path;
        $output = ob_get_clean();
        restore_error_handler();

        if ($output !== '' || !empty($errors)) {
            return array(
                null,
                array(
                    'code' => 'EFORMS_CONFIG_DROPIN_INVALID',
                    'meta' => array('path' => $path, 'reason' => 'output_or_warning'),
                )
            );
        }

        if (!is_array($result)) {
            return array(
                null,
                array(
                    'code' => 'EFORMS_CONFIG_DROPIN_INVALID',
                    'meta' => array('path' => $path, 'reason' => 'non_array'),
                )
            );
        }

        return array($result, null);
    }

    private static function dropin_path()
    {
        if (!defined('ABSPATH') || !defined('WP_CONTENT_DIR')) {
            return null;
        }

        $base = rtrim(WP_CONTENT_DIR, '/\\');

        return $base . '/' . self::DROPIN_FILENAME;
    }

    private static function merge_overrides_or_default($base, $override, $is_dropin)
    {
        $unknown = array();
        $merged = self::merge_overrides($base, $override, $unknown, '');

        if (empty($unknown)) {
            return $merged;
        }

        if ($is_dropin) {
            self::log_config_warning(
                $base,
                'EFORMS_CONFIG_DROPIN_INVALID',
                array('path' => '_root', 'reason' => 'unknown_keys', 'keys' => $unknown)
            );
        }

        return $base;
    }

    private static function merge_overrides($base, $override, &$unknown, $path)
    {
        if (!is_array($override)) {
            return $override;
        }

        if (!is_array($base)) {
            return $override;
        }

        if (self::is_list_array($base) || self::is_list_array($override)) {
            return $override;
        }

        $result = $base;

        foreach ($override as $key => $value) {
            if (!array_key_exists($key, $base)) {
                $unknown[] = $path . $key;
                continue;
            }

            $result[$key] = self::merge_overrides($base[$key], $value, $unknown, $path . $key . '.');
        }

        return $result;
    }

    private static function is_list_array($value)
    {
        if (!is_array($value)) {
            return false;
        }

        $keys = array_keys($value);
        $count = count($keys);
        for ($i = 0; $i < $count; $i++) {
            if ($keys[$i] !== $i) {
                return false;
            }
        }

        return true;
    }

    private static function deep_copy($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $copy = array();
        foreach ($value as $key => $item) {
            $copy[$key] = self::deep_copy($item);
        }

        return $copy;
    }

    private static function emit_dropin_schema_errors($config, $errors)
    {
        if (empty($errors)) {
            return;
        }

        foreach ($errors as $error) {
            self::log_config_warning(
                $config,
                'EFORMS_CONFIG_DROPIN_INVALID',
                array(
                    'path' => $error['path'],
                    'reason' => $error['reason'],
                )
            );
        }
    }

    private static function sanitize_override_schema($override, $defaults, &$errors, $prefix)
    {
        if (!is_array($override) || !is_array($defaults)) {
            return $override;
        }

        $sanitized = array();
        foreach ($override as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                $sanitized[$key] = $value;
                continue;
            }

            $path = $prefix === '' ? $key : ($prefix . '.' . $key);
            $clean = self::sanitize_value_schema($value, $defaults[$key], $path, $errors, true);
            if ($clean !== null) {
                $sanitized[$key] = $clean;
            }
        }

        return $sanitized;
    }

    private static function sanitize_config_schema($config, $defaults, &$errors, $prefix)
    {
        if (!is_array($defaults)) {
            return $defaults;
        }

        $result = self::deep_copy($defaults);
        if (!is_array($config)) {
            self::record_schema_error($errors, $prefix === '' ? '_root' : $prefix, 'type');
            return $result;
        }

        foreach ($config as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                continue;
            }

            $path = $prefix === '' ? $key : ($prefix . '.' . $key);
            $clean = self::sanitize_value_schema($value, $defaults[$key], $path, $errors, false);
            if ($clean !== null) {
                $result[$key] = $clean;
            }
        }

        return $result;
    }

    private static function sanitize_value_schema($value, $default, $path, &$errors, $is_override)
    {
        if (is_array($default)) {
            if (!is_array($value)) {
                self::record_schema_error($errors, $path, 'type');
                return $is_override ? null : self::deep_copy($default);
            }

            $default_is_list = self::is_list_array($default);
            if ($default_is_list) {
                if (!self::is_list_array($value)) {
                    self::record_schema_error($errors, $path, 'type');
                    return $is_override ? null : self::deep_copy($default);
                }

                foreach ($value as $item) {
                    if (!is_string($item)) {
                        self::record_schema_error($errors, $path, 'type');
                        return $is_override ? null : self::deep_copy($default);
                    }
                }

                return $value;
            }

            return $is_override
                ? self::sanitize_override_schema($value, $default, $errors, $path)
                : self::sanitize_config_schema($value, $default, $errors, $path);
        }

        $rule = self::schema_rule($path);

        if ($rule !== null && isset($rule['type']) && $rule['type'] === 'enum') {
            if (!is_string($value)) {
                self::record_schema_error($errors, $path, 'type');
                return $is_override ? null : $default;
            }

            if (isset($rule['aliases'][$value])) {
                $value = $rule['aliases'][$value];
            }

            if (!in_array($value, $rule['values'], true)) {
                self::record_schema_error($errors, $path, 'enum');
                return $is_override ? null : $default;
            }

            return $value;
        }

        if (is_bool($default)) {
            if (!is_bool($value)) {
                self::record_schema_error($errors, $path, 'type');
                return $is_override ? null : $default;
            }
            return $value;
        }

        if (is_int($default)) {
            if (!is_numeric($value)) {
                self::record_schema_error($errors, $path, 'type');
                return $is_override ? null : $default;
            }
            return (int) $value;
        }

        if (is_string($default)) {
            if (!is_string($value)) {
                self::record_schema_error($errors, $path, 'type');
                return $is_override ? null : $default;
            }
            return $value;
        }

        return $value;
    }

    private static function record_schema_error(&$errors, $path, $reason)
    {
        foreach ($errors as $existing) {
            if (isset($existing['path']) && $existing['path'] === $path) {
                return;
            }
        }

        $errors[] = array(
            'path' => $path,
            'reason' => $reason,
        );
    }

    private static function schema_rule($path)
    {
        $rules = array(
            'security.origin_mode' => array('type' => 'enum', 'values' => array('off', 'soft', 'hard'), 'aliases' => array()),
            'security.honeypot_response' => array('type' => 'enum', 'values' => array('stealth_success', 'hard_fail'), 'aliases' => array()),
            'challenge.mode' => array('type' => 'enum', 'values' => array('off', 'auto', 'always_post'), 'aliases' => array('always' => 'always_post')),
            'challenge.provider' => array('type' => 'enum', 'values' => array('turnstile'), 'aliases' => array()),
            'logging.mode' => array('type' => 'enum', 'values' => array('off', 'minimal', 'jsonl'), 'aliases' => array()),
            'logging.fail2ban.target' => array('type' => 'enum', 'values' => array('file'), 'aliases' => array()),
            'privacy.ip_mode' => array('type' => 'enum', 'values' => array('none', 'masked', 'hash', 'full'), 'aliases' => array()),
        );

        if (isset($rules[$path])) {
            return $rules[$path];
        }

        return null;
    }

    private static function apply_anchor_clamps($config, $defaults)
    {
        $security = isset($config['security']) && is_array($config['security']) ? $config['security'] : array();
        $security_defaults = $defaults['security'];

        $security['min_fill_seconds'] = self::clamp_anchor_value(
            self::value_or_default($security, 'min_fill_seconds', $security_defaults['min_fill_seconds']),
            $security_defaults['min_fill_seconds'],
            'MIN_FILL_SECONDS_MIN',
            'MIN_FILL_SECONDS_MAX'
        );

        $security['token_ttl_seconds'] = self::clamp_anchor_value(
            self::value_or_default($security, 'token_ttl_seconds', $security_defaults['token_ttl_seconds']),
            $security_defaults['token_ttl_seconds'],
            'TOKEN_TTL_MIN',
            'TOKEN_TTL_MAX'
        );

        $max_form_age = self::value_or_default($security, 'max_form_age_seconds', $security_defaults['max_form_age_seconds']);
        if (!is_numeric($max_form_age)) {
            $max_form_age = $security['token_ttl_seconds'];
        }
        $security['max_form_age_seconds'] = self::clamp_anchor_value(
            $max_form_age,
            $security_defaults['max_form_age_seconds'],
            'MAX_FORM_AGE_MIN',
            'MAX_FORM_AGE_MAX'
        );

        $config['security'] = $security;

        $challenge = isset($config['challenge']) && is_array($config['challenge']) ? $config['challenge'] : array();
        $challenge_defaults = $defaults['challenge'];
        $challenge['http_timeout_seconds'] = self::clamp_anchor_value(
            self::value_or_default($challenge, 'http_timeout_seconds', $challenge_defaults['http_timeout_seconds']),
            $challenge_defaults['http_timeout_seconds'],
            'CHALLENGE_TIMEOUT_MIN',
            'CHALLENGE_TIMEOUT_MAX'
        );
        $config['challenge'] = $challenge;

        $throttle = isset($config['throttle']) && is_array($config['throttle']) ? $config['throttle'] : array();
        $throttle_defaults = $defaults['throttle'];
        $per_ip = isset($throttle['per_ip']) && is_array($throttle['per_ip']) ? $throttle['per_ip'] : array();
        $per_ip_defaults = $throttle_defaults['per_ip'];
        $per_ip['max_per_minute'] = self::clamp_anchor_value(
            self::value_or_default($per_ip, 'max_per_minute', $per_ip_defaults['max_per_minute']),
            $per_ip_defaults['max_per_minute'],
            'THROTTLE_MAX_PER_MIN_MIN',
            'THROTTLE_MAX_PER_MIN_MAX'
        );
        $per_ip['cooldown_seconds'] = self::clamp_anchor_value(
            self::value_or_default($per_ip, 'cooldown_seconds', $per_ip_defaults['cooldown_seconds']),
            $per_ip_defaults['cooldown_seconds'],
            'THROTTLE_COOLDOWN_MIN',
            'THROTTLE_COOLDOWN_MAX'
        );
        $throttle['per_ip'] = $per_ip;
        $config['throttle'] = $throttle;

        $logging = isset($config['logging']) && is_array($config['logging']) ? $config['logging'] : array();
        $logging_defaults = $defaults['logging'];
        $logging['level'] = self::clamp_anchor_value(
            self::value_or_default($logging, 'level', $logging_defaults['level']),
            $logging_defaults['level'],
            'LOGGING_LEVEL_MIN',
            'LOGGING_LEVEL_MAX'
        );
        $logging['retention_days'] = self::clamp_anchor_value(
            self::value_or_default($logging, 'retention_days', $logging_defaults['retention_days']),
            $logging_defaults['retention_days'],
            'RETENTION_DAYS_MIN',
            'RETENTION_DAYS_MAX'
        );

        $fail2ban = isset($logging['fail2ban']) && is_array($logging['fail2ban']) ? $logging['fail2ban'] : array();
        $fail2ban_defaults = $logging_defaults['fail2ban'];
        $fail2ban_retention = self::value_or_default($fail2ban, 'retention_days', $fail2ban_defaults['retention_days']);
        if (!is_numeric($fail2ban_retention)) {
            $fail2ban_retention = $logging['retention_days'];
        }
        $fail2ban['retention_days'] = self::clamp_anchor_value(
            $fail2ban_retention,
            $fail2ban_defaults['retention_days'],
            'RETENTION_DAYS_MIN',
            'RETENTION_DAYS_MAX'
        );
        $logging['fail2ban'] = $fail2ban;
        $config['logging'] = $logging;

        $validation = isset($config['validation']) && is_array($config['validation']) ? $config['validation'] : array();
        $validation_defaults = $defaults['validation'];
        $validation['max_fields_per_form'] = self::clamp_anchor_value(
            self::value_or_default($validation, 'max_fields_per_form', $validation_defaults['max_fields_per_form']),
            $validation_defaults['max_fields_per_form'],
            'MAX_FIELDS_MIN',
            'MAX_FIELDS_MAX'
        );
        $validation['max_options_per_group'] = self::clamp_anchor_value(
            self::value_or_default($validation, 'max_options_per_group', $validation_defaults['max_options_per_group']),
            $validation_defaults['max_options_per_group'],
            'MAX_OPTIONS_MIN',
            'MAX_OPTIONS_MAX'
        );
        $validation['max_items_per_multivalue'] = self::clamp_anchor_value(
            self::value_or_default($validation, 'max_items_per_multivalue', $validation_defaults['max_items_per_multivalue']),
            $validation_defaults['max_items_per_multivalue'],
            'MAX_MULTIVALUE_MIN',
            'MAX_MULTIVALUE_MAX'
        );
        $config['validation'] = $validation;

        // Clamp spam.soft_fail_threshold to minimum 1 per spec §8 (Spam Decision).
        $spam = isset($config['spam']) && is_array($config['spam']) ? $config['spam'] : array();
        $spam_defaults = $defaults['spam'];
        $value = self::value_or_default($spam, 'soft_fail_threshold', $spam_defaults['soft_fail_threshold']);
        $spam['soft_fail_threshold'] = max(1, (int) $value);
        $config['spam'] = $spam;

        return $config;
    }

    private static function clamp_anchor_value($value, $fallback, $min_anchor, $max_anchor)
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        $value = (int) $value;
        $min = self::anchor_value($min_anchor);
        $max = self::anchor_value($max_anchor);

        // Fail-closed: if anchors missing, use fallback (don't pass through unclamped).
        // Anchors::get() already warns in WP_DEBUG mode.
        if ($min === null || $max === null) {
            return $fallback;
        }

        return max($min, min($max, $value));
    }

    private static function value_or_default($section, $key, $fallback)
    {
        if (!is_array($section)) {
            return $fallback;
        }

        if (array_key_exists($key, $section)) {
            return $section[$key];
        }

        return $fallback;
    }

    private static function anchor_value($anchor)
    {
        return Anchors::get($anchor);
    }

    private static function log_config_warning($config, $code, $meta)
    {
        if (!self::should_log_warning($config)) {
            return;
        }

        if (class_exists('Logging') && method_exists('Logging', 'event')) {
            Logging::event('warning', $code, $meta);
            return;
        }

        $payload = array(
            'code' => $code,
            'meta' => $meta,
        );

        $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        error_log('eforms config warning ' . $encoded);
    }

    private static function should_log_warning($config)
    {
        if (!isset($config['logging']['mode'])) {
            return false;
        }

        if ($config['logging']['mode'] === 'off') {
            return false;
        }

        $level = 0;
        if (isset($config['logging']['level'])) {
            $level = (int) $config['logging']['level'];
        }

        return $level >= 1;
    }
}
