<?php
/**
 * Configuration bootstrap and snapshot.
 */

require_once __DIR__ . '/Anchors.php';

class Config
{
    const DROPIN_FILENAME = 'eforms.config.php';
    const SOURCE_DEFAULT = 'default';
    const SOURCE_ADMIN_OPTION = 'admin option';
    const SOURCE_CONFIG_FILE = 'config file';
    const SOURCE_FILTER = 'filter';
    const SOURCE_CLAMPED = 'clamped';

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
        'declined_review' => array(
            'enable' => false,
            'retention_days' => null,
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
    private static $effective_report = null;
    private static $admin_schema = null;

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
        $provenance = array();
        $dropin_schema_errors = array();

        list($admin_override, $admin_valid) = self::load_admin_overrides();
        if ($admin_valid && is_array($admin_override) && !empty($admin_override)) {
            $config = self::merge_overrides_or_default($config, $admin_override, false);
            self::mark_source_paths($provenance, $admin_override, self::SOURCE_ADMIN_OPTION, '');
        }

        list($dropin, $dropin_error) = self::load_dropin();
        if ($dropin_error !== null) {
            self::log_config_warning($config, $dropin_error['code'], $dropin_error['meta']);
        }

        if (is_array($dropin)) {
            $dropin = self::sanitize_override_schema($dropin, $defaults, $dropin_schema_errors, '');
            $config = self::merge_overrides_or_default($config, $dropin, true);
            self::mark_source_paths($provenance, $dropin, self::SOURCE_CONFIG_FILE, '');
        }

        if (function_exists('apply_filters')) {
            $before_filter = self::deep_copy($config);
            $filtered = apply_filters('eforms_config', $config);
            if (is_array($filtered)) {
                $filter_schema_errors = array();
                $filtered = self::sanitize_override_schema($filtered, $defaults, $filter_schema_errors, '');
                $merged = self::merge_overrides_or_default($config, $filtered, false);
                self::mark_changed_source_paths($provenance, $before_filter, $merged, self::SOURCE_FILTER, '');
                $config = $merged;
            }
        }

        $schema_errors = array();
        $config = self::sanitize_config_schema($config, $defaults, $schema_errors, '');
        $clamped_paths = array();
        $config = self::apply_anchor_clamps($config, $defaults, $clamped_paths);
        self::mark_clamped_paths($provenance, array_keys($clamped_paths));
        self::emit_dropin_schema_errors($config, $dropin_schema_errors);

        self::$snapshot = self::deep_copy($config);
        self::$effective_report = self::build_effective_report($config, $provenance);
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

    public static function value($config, $path, $fallback = null)
    {
        if (!is_array($config) || !is_array($path)) {
            return $fallback;
        }

        $cursor = $config;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $fallback;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public static function bool($config, $path, $fallback = false)
    {
        $fallback = is_bool($fallback) ? $fallback : false;
        $value = self::value($config, $path, null);
        if (is_bool($value)) {
            return $value;
        }

        return $fallback;
    }

    public static function has_path($config, $path)
    {
        if (!is_array($config) || !is_array($path)) {
            return false;
        }

        $cursor = $config;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }

        return true;
    }

    public static function admin_schema()
    {
        return self::deep_copy(self::admin_schema_cached());
    }

    private static function admin_schema_cached()
    {
        if (self::$admin_schema !== null) {
            return self::$admin_schema;
        }

        $schema = array(
            'declined_review.enable' => array('type' => 'bool'),
            'declined_review.retention_days' => array(
                'type' => 'int',
                'nullable' => true,
                'min_anchor' => 'RETENTION_DAYS_MIN',
                'max_anchor' => 'RETENTION_DAYS_MAX',
            ),
            'logging.mode' => array('type' => 'enum'),
            'logging.level' => array(
                'type' => 'int',
                'min_anchor' => 'LOGGING_LEVEL_MIN',
                'max_anchor' => 'LOGGING_LEVEL_MAX',
            ),
            'logging.retention_days' => array(
                'type' => 'int',
                'min_anchor' => 'RETENTION_DAYS_MIN',
                'max_anchor' => 'RETENTION_DAYS_MAX',
            ),
            'security.honeypot_response' => array('type' => 'enum'),
            'security.min_fill_seconds' => array(
                'type' => 'int',
                'min_anchor' => 'MIN_FILL_SECONDS_MIN',
                'max_anchor' => 'MIN_FILL_SECONDS_MAX',
            ),
            'spam.soft_fail_threshold' => array(
                'type' => 'int',
                'min' => 1,
            ),
            'challenge.mode' => array('type' => 'enum'),
            'challenge.site_key' => array('type' => 'string'),
            'challenge.secret_key' => array('type' => 'string', 'secret' => true),
            'throttle.enable' => array('type' => 'bool'),
            'throttle.per_ip.max_per_minute' => array(
                'type' => 'int',
                'min_anchor' => 'THROTTLE_MAX_PER_MIN_MIN',
                'max_anchor' => 'THROTTLE_MAX_PER_MIN_MAX',
            ),
            'throttle.per_ip.cooldown_seconds' => array(
                'type' => 'int',
                'min_anchor' => 'THROTTLE_COOLDOWN_MIN',
                'max_anchor' => 'THROTTLE_COOLDOWN_MAX',
            ),
            'privacy.ip_mode' => array('type' => 'enum'),
        );

        foreach ($schema as $path => $rule) {
            if (isset($rule['type']) && $rule['type'] === 'enum' && !isset($rule['values'])) {
                $schema_rule = self::schema_rule($path);
                if (is_array($schema_rule) && isset($schema_rule['values']) && is_array($schema_rule['values'])) {
                    $schema[$path]['values'] = $schema_rule['values'];
                }
            }
            if (isset($rule['min_anchor'])) {
                $schema[$path]['min'] = self::anchor_value($rule['min_anchor']);
            }
            if (isset($rule['max_anchor'])) {
                $schema[$path]['max'] = self::anchor_value($rule['max_anchor']);
            }
            if (!isset($schema[$path]['nullable'])) {
                $schema[$path]['nullable'] = false;
            }
            if (!isset($schema[$path]['secret'])) {
                $schema[$path]['secret'] = false;
            }
        }

        self::$admin_schema = $schema;
        return self::$admin_schema;
    }

    public static function validate_admin_overrides($overrides)
    {
        $schema = self::admin_schema_cached();
        $errors = array();
        $flat = array();
        self::flatten_admin_overrides($overrides, '', $schema, $flat, $errors);

        if (!empty($errors)) {
            return array('ok' => false, 'overrides' => array(), 'errors' => $errors);
        }

        return self::validate_admin_flat_map($flat, $schema);
    }

    public static function validate_admin_flat_overrides($flat)
    {
        if (!is_array($flat)) {
            return array('ok' => false, 'overrides' => array(), 'errors' => array(array('path' => '_root', 'reason' => 'type')));
        }

        return self::validate_admin_flat_map($flat, self::admin_schema_cached());
    }

    private static function validate_admin_flat_map($flat, $schema)
    {
        $errors = array();
        $sanitized = array();
        foreach ($flat as $path => $value) {
            $path = (string) $path;
            if (!isset($schema[$path])) {
                $errors[] = array('path' => $path, 'reason' => 'unknown');
                continue;
            }

            $clean = self::validate_admin_value($path, $value, $schema[$path], $errors);
            if (empty($errors)) {
                self::set_path($sanitized, explode('.', $path), $clean);
            }
        }

        if (!empty($errors)) {
            return array('ok' => false, 'overrides' => array(), 'errors' => $errors);
        }

        return array('ok' => true, 'overrides' => $sanitized, 'errors' => array());
    }

    public static function effective_report()
    {
        if (!self::$bootstrapped) {
            self::bootstrap();
        }

        return self::deep_copy(self::$effective_report);
    }

    public static function refresh()
    {
        self::clear_snapshot();
        return self::get();
    }

    public static function mask_secret_value($path, $value)
    {
        $schema = self::admin_schema_cached();
        if (!isset($schema[$path]) || empty($schema[$path]['secret'])) {
            return $value;
        }

        return is_string($value) && $value !== '' ? '********' : '';
    }

    /**
     * Clear the frozen per-request config snapshot.
     *
     * Normal public requests should not need this. Long-running tooling such as
     * WP-CLI diagnostics may rebuild isolated snapshots inside one PHP process.
     */
    public static function reset_snapshot()
    {
        self::clear_snapshot();
    }

    /**
     * Test-only helper to reset the snapshot between unit cases.
     */
    public static function reset_for_tests()
    {
        self::reset_snapshot();
    }

    private static function clear_snapshot()
    {
        self::$snapshot = null;
        self::$bootstrapped = false;
        self::$effective_report = null;
    }

    private static function load_admin_overrides()
    {
        if (!class_exists('AdminSettingsStore')) {
            require_once __DIR__ . '/Admin/AdminSettingsStore.php';
        }

        $result = AdminSettingsStore::read_overrides_result();
        if (!is_array($result) || empty($result['ok']) || !isset($result['overrides']) || !is_array($result['overrides'])) {
            return array(array(), false);
        }

        return array($result['overrides'], true);
    }

    private static function flatten_admin_overrides($value, $prefix, $schema, &$flat, &$errors)
    {
        if ($prefix === '' && !is_array($value)) {
            $errors[] = array('path' => '_root', 'reason' => 'type');
            return;
        }

        if (!is_array($value)) {
            $flat[$prefix] = $value;
            return;
        }

        if ($prefix !== '' && isset($schema[$prefix])) {
            $errors[] = array('path' => $prefix, 'reason' => 'type');
            return;
        }

        foreach ($value as $key => $entry) {
            if (!is_string($key) && !is_int($key)) {
                $errors[] = array('path' => $prefix === '' ? '_root' : $prefix, 'reason' => 'unknown');
                continue;
            }

            $key = (string) $key;
            $path = $prefix === '' ? $key : ($prefix . '.' . $key);
            if (!self::is_admin_path_or_prefix($path, $schema)) {
                $errors[] = array('path' => $path, 'reason' => 'unknown');
                continue;
            }

            self::flatten_admin_overrides($entry, $path, $schema, $flat, $errors);
        }
    }

    private static function is_admin_path_or_prefix($path, $schema)
    {
        if (isset($schema[$path])) {
            return true;
        }

        $prefix = $path . '.';
        foreach ($schema as $schema_path => $rule) {
            if (strpos($schema_path, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function validate_admin_value($path, $value, $rule, &$errors)
    {
        if ($value === null) {
            if (!empty($rule['nullable'])) {
                return null;
            }
            $errors[] = array('path' => $path, 'reason' => 'type');
            return null;
        }

        $type = isset($rule['type']) ? $rule['type'] : '';
        if ($type === 'bool') {
            if (!is_bool($value)) {
                $errors[] = array('path' => $path, 'reason' => 'type');
                return null;
            }
            return $value;
        }

        if ($type === 'int') {
            if (!is_numeric($value)) {
                $errors[] = array('path' => $path, 'reason' => 'type');
                return null;
            }
            return (int) $value;
        }

        if ($type === 'string') {
            if (!is_string($value)) {
                $errors[] = array('path' => $path, 'reason' => 'type');
                return null;
            }
            return $value;
        }

        if ($type === 'enum') {
            if (!is_string($value)) {
                $errors[] = array('path' => $path, 'reason' => 'type');
                return null;
            }
            if (!isset($rule['values']) || !in_array($value, $rule['values'], true)) {
                $errors[] = array('path' => $path, 'reason' => 'enum');
                return null;
            }
            return $value;
        }

        $errors[] = array('path' => $path, 'reason' => 'type');
        return null;
    }

    private static function set_path(&$target, $segments, $value)
    {
        $cursor =& $target;
        $count = count($segments);
        for ($i = 0; $i < $count; $i++) {
            $segment = $segments[$i];
            if ($i === $count - 1) {
                $cursor[$segment] = $value;
                return;
            }
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = array();
            }
            $cursor =& $cursor[$segment];
        }
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

    private static function mark_source_paths(&$provenance, $values, $source, $prefix)
    {
        if (!is_array($values) || self::is_list_array($values)) {
            if ($prefix !== '') {
                $provenance[$prefix] = $source;
            }
            return;
        }

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : ($prefix . '.' . (string) $key);
            self::mark_source_paths($provenance, $value, $source, $path);
        }
    }

    private static function mark_changed_source_paths(&$provenance, $before, $after, $source, $prefix)
    {
        if (!is_array($after) || self::is_list_array($after) || !is_array($before) || self::is_list_array($before)) {
            if ($prefix !== '' && $before !== $after) {
                $provenance[$prefix] = $source;
            }
            return;
        }

        foreach ($after as $key => $value) {
            $path = $prefix === '' ? (string) $key : ($prefix . '.' . (string) $key);
            $before_value = array_key_exists($key, $before) ? $before[$key] : null;
            self::mark_changed_source_paths($provenance, $before_value, $value, $source, $path);
        }
    }

    private static function mark_clamped_paths(&$provenance, $paths)
    {
        foreach ($paths as $path) {
            $source = isset($provenance[$path]) ? $provenance[$path] : self::SOURCE_DEFAULT;
            if (!self::is_externally_controlled_source($source)) {
                $provenance[$path] = self::SOURCE_CLAMPED;
            }
        }
    }

    private static function is_externally_controlled_source($source)
    {
        return in_array($source, array(self::SOURCE_CONFIG_FILE, self::SOURCE_FILTER), true);
    }

    private static function build_effective_report($config, $provenance)
    {
        $report = array();
        self::build_effective_report_walk($report, $config, $provenance, '');
        return $report;
    }

    private static function build_effective_report_walk(&$report, $values, $provenance, $prefix)
    {
        if (!is_array($values) || self::is_list_array($values)) {
            if ($prefix !== '') {
                $source = isset($provenance[$prefix]) ? $provenance[$prefix] : self::SOURCE_DEFAULT;
                $report[$prefix] = array(
                    'value' => self::deep_copy($values),
                    'display_value' => self::mask_secret_value($prefix, $values),
                    'source' => $source,
                    'externally_controlled' => self::is_externally_controlled_source($source),
                );
            }
            return;
        }

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : ($prefix . '.' . (string) $key);
            self::build_effective_report_walk($report, $value, $provenance, $path);
        }
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
            'security.origin_mode' => array('type' => 'enum', 'values' => array('off', 'soft', 'hard')),
            'security.honeypot_response' => array('type' => 'enum', 'values' => array('stealth_success', 'hard_fail')),
            'challenge.mode' => array('type' => 'enum', 'values' => array('off', 'auto', 'always_post')),
            'challenge.provider' => array('type' => 'enum', 'values' => array('turnstile')),
            'logging.mode' => array('type' => 'enum', 'values' => array('off', 'minimal', 'jsonl')),
            'logging.fail2ban.target' => array('type' => 'enum', 'values' => array('file')),
            'privacy.ip_mode' => array('type' => 'enum', 'values' => array('none', 'masked', 'hash', 'full')),
        );

        if (isset($rules[$path])) {
            return $rules[$path];
        }

        return null;
    }

    private static function apply_anchor_clamps($config, $defaults, &$clamped_paths = null)
    {
        $security = isset($config['security']) && is_array($config['security']) ? $config['security'] : array();
        $security_defaults = $defaults['security'];

        $security['min_fill_seconds'] = self::clamp_anchor_path(
            'security.min_fill_seconds',
            self::value_or_default($security, 'min_fill_seconds', $security_defaults['min_fill_seconds']),
            $security_defaults['min_fill_seconds'],
            'MIN_FILL_SECONDS_MIN',
            'MIN_FILL_SECONDS_MAX',
            $clamped_paths
        );

        $security['token_ttl_seconds'] = self::clamp_anchor_path(
            'security.token_ttl_seconds',
            self::value_or_default($security, 'token_ttl_seconds', $security_defaults['token_ttl_seconds']),
            $security_defaults['token_ttl_seconds'],
            'TOKEN_TTL_MIN',
            'TOKEN_TTL_MAX',
            $clamped_paths
        );

        $max_form_age = self::value_or_default($security, 'max_form_age_seconds', $security_defaults['max_form_age_seconds']);
        if (!is_numeric($max_form_age)) {
            $max_form_age = $security['token_ttl_seconds'];
        }
        $security['max_form_age_seconds'] = self::clamp_anchor_path(
            'security.max_form_age_seconds',
            $max_form_age,
            $security_defaults['max_form_age_seconds'],
            'MAX_FORM_AGE_MIN',
            'MAX_FORM_AGE_MAX',
            $clamped_paths
        );

        $config['security'] = $security;

        $challenge = isset($config['challenge']) && is_array($config['challenge']) ? $config['challenge'] : array();
        $challenge_defaults = $defaults['challenge'];
        $challenge['http_timeout_seconds'] = self::clamp_anchor_path(
            'challenge.http_timeout_seconds',
            self::value_or_default($challenge, 'http_timeout_seconds', $challenge_defaults['http_timeout_seconds']),
            $challenge_defaults['http_timeout_seconds'],
            'CHALLENGE_TIMEOUT_MIN',
            'CHALLENGE_TIMEOUT_MAX',
            $clamped_paths
        );
        $config['challenge'] = $challenge;

        $throttle = isset($config['throttle']) && is_array($config['throttle']) ? $config['throttle'] : array();
        $throttle_defaults = $defaults['throttle'];
        $per_ip = isset($throttle['per_ip']) && is_array($throttle['per_ip']) ? $throttle['per_ip'] : array();
        $per_ip_defaults = $throttle_defaults['per_ip'];
        $per_ip['max_per_minute'] = self::clamp_anchor_path(
            'throttle.per_ip.max_per_minute',
            self::value_or_default($per_ip, 'max_per_minute', $per_ip_defaults['max_per_minute']),
            $per_ip_defaults['max_per_minute'],
            'THROTTLE_MAX_PER_MIN_MIN',
            'THROTTLE_MAX_PER_MIN_MAX',
            $clamped_paths
        );
        $per_ip['cooldown_seconds'] = self::clamp_anchor_path(
            'throttle.per_ip.cooldown_seconds',
            self::value_or_default($per_ip, 'cooldown_seconds', $per_ip_defaults['cooldown_seconds']),
            $per_ip_defaults['cooldown_seconds'],
            'THROTTLE_COOLDOWN_MIN',
            'THROTTLE_COOLDOWN_MAX',
            $clamped_paths
        );
        $throttle['per_ip'] = $per_ip;
        $config['throttle'] = $throttle;

        $logging = isset($config['logging']) && is_array($config['logging']) ? $config['logging'] : array();
        $logging_defaults = $defaults['logging'];
        $logging['level'] = self::clamp_anchor_path(
            'logging.level',
            self::value_or_default($logging, 'level', $logging_defaults['level']),
            $logging_defaults['level'],
            'LOGGING_LEVEL_MIN',
            'LOGGING_LEVEL_MAX',
            $clamped_paths
        );
        $logging['retention_days'] = self::clamp_anchor_path(
            'logging.retention_days',
            self::value_or_default($logging, 'retention_days', $logging_defaults['retention_days']),
            $logging_defaults['retention_days'],
            'RETENTION_DAYS_MIN',
            'RETENTION_DAYS_MAX',
            $clamped_paths
        );

        $fail2ban = isset($logging['fail2ban']) && is_array($logging['fail2ban']) ? $logging['fail2ban'] : array();
        $fail2ban_defaults = $logging_defaults['fail2ban'];
        $fail2ban_retention = self::value_or_default($fail2ban, 'retention_days', $fail2ban_defaults['retention_days']);
        if (!is_numeric($fail2ban_retention)) {
            $fail2ban_retention = $logging['retention_days'];
        }
        $fail2ban['retention_days'] = self::clamp_anchor_path(
            'logging.fail2ban.retention_days',
            $fail2ban_retention,
            $fail2ban_defaults['retention_days'],
            'RETENTION_DAYS_MIN',
            'RETENTION_DAYS_MAX',
            $clamped_paths
        );
        $logging['fail2ban'] = $fail2ban;
        $config['logging'] = $logging;

        $declined = isset($config['declined_review']) && is_array($config['declined_review']) ? $config['declined_review'] : array();
        $declined_defaults = $defaults['declined_review'];
        $declined_retention = self::value_or_default($declined, 'retention_days', $declined_defaults['retention_days']);
        if (!is_numeric($declined_retention)) {
            $declined_retention = $logging['retention_days'];
        }
        $declined['retention_days'] = self::clamp_anchor_path(
            'declined_review.retention_days',
            $declined_retention,
            $logging['retention_days'],
            'RETENTION_DAYS_MIN',
            'RETENTION_DAYS_MAX',
            $clamped_paths
        );
        $config['declined_review'] = $declined;

        $validation = isset($config['validation']) && is_array($config['validation']) ? $config['validation'] : array();
        $validation_defaults = $defaults['validation'];
        $validation['max_fields_per_form'] = self::clamp_anchor_path(
            'validation.max_fields_per_form',
            self::value_or_default($validation, 'max_fields_per_form', $validation_defaults['max_fields_per_form']),
            $validation_defaults['max_fields_per_form'],
            'MAX_FIELDS_MIN',
            'MAX_FIELDS_MAX',
            $clamped_paths
        );
        $validation['max_options_per_group'] = self::clamp_anchor_path(
            'validation.max_options_per_group',
            self::value_or_default($validation, 'max_options_per_group', $validation_defaults['max_options_per_group']),
            $validation_defaults['max_options_per_group'],
            'MAX_OPTIONS_MIN',
            'MAX_OPTIONS_MAX',
            $clamped_paths
        );
        $validation['max_items_per_multivalue'] = self::clamp_anchor_path(
            'validation.max_items_per_multivalue',
            self::value_or_default($validation, 'max_items_per_multivalue', $validation_defaults['max_items_per_multivalue']),
            $validation_defaults['max_items_per_multivalue'],
            'MAX_MULTIVALUE_MIN',
            'MAX_MULTIVALUE_MAX',
            $clamped_paths
        );
        $config['validation'] = $validation;

        // Keep the spam rejection threshold at one or more signals.
        $spam = isset($config['spam']) && is_array($config['spam']) ? $config['spam'] : array();
        $spam_defaults = $defaults['spam'];
        $value = self::value_or_default($spam, 'soft_fail_threshold', $spam_defaults['soft_fail_threshold']);
        $spam['soft_fail_threshold'] = max(1, (int) $value);
        if (is_array($clamped_paths) && $spam['soft_fail_threshold'] !== $value) {
            $clamped_paths['spam.soft_fail_threshold'] = true;
        }
        $config['spam'] = $spam;

        return $config;
    }

    private static function clamp_anchor_path($path, $value, $fallback, $min_anchor, $max_anchor, &$clamped_paths = null)
    {
        $clamped = self::clamp_anchor_value($value, $fallback, $min_anchor, $max_anchor);
        if (is_array($clamped_paths) && $value !== $clamped) {
            $clamped_paths[$path] = true;
        }
        return $clamped;
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
