<?php
declare(strict_types=1);

namespace EForms;

class Config
{
    public const DEFAULTS = [
        'security' => [
            'token_ledger' => ['enable' => true],
            'token_ttl_seconds' => 600,
            'submission_token' => ['required' => true],
            'success_ticket_ttl_seconds' => 300,
            'origin_mode' => 'soft',
            'origin_missing_soft' => false,
            'origin_missing_hard' => false,
            'min_fill_seconds' => 4,
            'max_form_age_seconds' => 600,
            'js_hard_mode' => false,
            'max_post_bytes' => 25000000,
            'ua_maxlen' => 256,
            'honeypot_response' => 'stealth_success',
            'cookie_missing_policy' => 'soft',
            'cookie_mode_slots_enabled' => false,
            'cookie_mode_slots_allowed' => [],
        ],
        'spam' => [
            'soft_fail_threshold' => 2,
        ],
        'throttle' => [
            'enable' => false,
            'per_ip' => [
                'max_per_minute' => 5,
                'cooldown_seconds' => 60,
                'hard_multiplier' => 3.0,
            ],
        ],
        'challenge' => [
            'mode' => 'off',
            'provider' => 'turnstile',
            'turnstile' => ['site_key' => null, 'secret_key' => null],
            'hcaptcha' => ['site_key' => null, 'secret_key' => null],
            'recaptcha' => ['site_key' => null, 'secret_key' => null],
            'http_timeout_seconds' => 2,
        ],
        'html5' => [
            'client_validation' => false,
        ],
        'email' => [
            'policy' => 'strict',
            'smtp' => [
                'timeout_seconds' => 10,
                'max_retries' => 2,
                'retry_backoff_seconds' => 2,
            ],
            'html' => false,
            'from_address' => '',
            'from_name' => '',
            'reply_to_field' => '',
            'envelope_sender' => '',
            'dkim' => [
                'domain' => '',
                'selector' => '',
                'private_key_path' => '',
                'pass_phrase' => '',
            ],
            'disable_send' => false,
            'staging_redirect_to' => null,
            'suspect_subject_tag' => '[SUSPECT]',
            'upload_max_attachments' => 5,
            'debug' => [
                'enable' => false,
                'max_bytes' => 8192,
            ],
        ],
        'logging' => [
            'mode' => 'minimal',
            'level' => 0,
            'headers' => false,
            'pii' => false,
            'on_failure_canonical' => false,
            'file_max_size' => 5000000,
            'retention_days' => 30,
            'fail2ban' => [
                'enable' => false,
                'target' => 'error_log',
                'file' => null,
                'file_max_size' => 5000000,
                'retention_days' => 30,
            ],
        ],
        'privacy' => [
            'ip_mode' => 'masked',
            'ip_salt' => '',
            'client_ip_header' => '',
            'trusted_proxies' => [],
        ],
        'assets' => [
            'css_disable' => false,
        ],
        'install' => [
            'min_php' => '8.0',
            'min_wp' => '5.8',
            'uninstall' => [
                'purge_uploads' => false,
                'purge_logs' => false,
            ],
        ],
        'validation' => [
            'max_fields_per_form' => 150,
            'max_options_per_group' => 100,
            'max_items_per_multivalue' => 50,
            'textarea_html_max_bytes' => 32768,
        ],
        'uploads' => [
            'enable' => true,
            'dir' => null,
            'allowed_tokens' => ['image', 'pdf'],
            'allowed_mime' => [],
            'allowed_ext' => [],
            'max_file_bytes' => 5000000,
            'max_files' => 10,
            'total_field_bytes' => 10000000,
            'total_request_bytes' => 20000000,
            'max_email_bytes' => 10000000,
            'delete_after_send' => true,
            'retention_seconds' => 86400,
            'max_image_px' => 50000000,
            'original_maxlen' => 100,
            'transliterate' => true,
            'max_relative_path_chars' => 180,
        ],
    ];

    public const RANGE_CLAMPS = [
        'security.min_fill_seconds' => ['type' => 'int', 'min' => 0, 'max' => 60],
        'security.token_ttl_seconds' => ['type' => 'int', 'min' => 1, 'max' => 86400],
        'security.max_form_age_seconds' => ['type' => 'int', 'min' => 1, 'max' => 86400],
        'security.success_ticket_ttl_seconds' => ['type' => 'int', 'min' => 30, 'max' => 3600],
        'challenge.http_timeout_seconds' => ['type' => 'int', 'min' => 1, 'max' => 5],
        'throttle.per_ip.max_per_minute' => ['type' => 'int', 'min' => 1, 'max' => 120],
        'throttle.per_ip.cooldown_seconds' => ['type' => 'int', 'min' => 10, 'max' => 600],
        'throttle.per_ip.hard_multiplier' => ['type' => 'float', 'min' => 1.5, 'max' => 10.0],
        'logging.level' => ['type' => 'int', 'min' => 0, 'max' => 2],
        'logging.retention_days' => ['type' => 'int', 'min' => 1, 'max' => 365],
        'logging.fail2ban.retention_days' => ['type' => 'int', 'min' => 1, 'max' => 365],
        'validation.max_fields_per_form' => ['type' => 'int', 'min' => 1, 'max' => 1000],
        'validation.max_options_per_group' => ['type' => 'int', 'min' => 1, 'max' => 1000],
        'validation.max_items_per_multivalue' => ['type' => 'int', 'min' => 1, 'max' => 1000],
        'validation.textarea_html_max_bytes' => ['type' => 'int', 'min' => 1, 'max' => 1000000],
    ];

    public const ENUMS = [
        'security.origin_mode' => ['off', 'soft', 'hard'],
        'security.honeypot_response' => ['stealth_success', 'hard_fail'],
        'security.cookie_missing_policy' => ['off', 'soft', 'hard', 'challenge'],
        'challenge.mode' => ['off', 'auto', 'always'],
        'challenge.provider' => ['turnstile', 'hcaptcha', 'recaptcha'],
        'logging.mode' => ['off', 'minimal', 'jsonl'],
        'logging.fail2ban.target' => ['error_log', 'syslog', 'file'],
        'privacy.ip_mode' => ['none', 'masked', 'hash', 'full'],
    ];

    private static array $data = [];
    private static bool $bootstrapped = false;

    public static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        $defaults = self::defaults();
        $config = apply_filters('eforms_config', $defaults);
        self::$data = self::clampTypes($config, $defaults);
        self::$bootstrapped = true;
    }

    public static function get(string $path, $default = null)
    {
        $segments = explode('.', $path);
        $value = self::$data;
        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }
        return $value;
    }

    public static function resetForTests(): void
    {
        self::$data = [];
        self::$bootstrapped = false;
    }

    private static function clampTypes(array $config, array $defaults): array
    {
        $cfg = array_replace_recursive($defaults, $config);

        // security
        $sec =& $cfg['security'];
        $sec['origin_mode'] = self::sanitizeEnum(
            'security.origin_mode',
            $sec['origin_mode'],
            self::ENUMS['security.origin_mode'],
            $defaults['security']['origin_mode']
        );
        $sec['origin_missing_soft'] = (bool)$sec['origin_missing_soft'];
        $sec['origin_missing_hard'] = (bool)$sec['origin_missing_hard'];
        $sec['min_fill_seconds'] = self::clampRangeValue('security.min_fill_seconds', $sec['min_fill_seconds']);
        $sec['token_ttl_seconds'] = self::clampRangeValue('security.token_ttl_seconds', $sec['token_ttl_seconds']);
        $sec['max_form_age_seconds'] = self::clampRangeValue(
            'security.max_form_age_seconds',
            $sec['max_form_age_seconds'] ?? $sec['token_ttl_seconds']
        );
        $sec['js_hard_mode'] = (bool)$sec['js_hard_mode'];
        $sec['max_post_bytes'] = self::clampInt($sec['max_post_bytes'], 0, PHP_INT_MAX);
        $sec['ua_maxlen'] = self::clampInt($sec['ua_maxlen'], 0, 10000);
        $sec['honeypot_response'] = self::sanitizeEnum(
            'security.honeypot_response',
            $sec['honeypot_response'],
            self::ENUMS['security.honeypot_response'],
            $defaults['security']['honeypot_response']
        );
        $sec['cookie_missing_policy'] = self::sanitizeEnum(
            'security.cookie_missing_policy',
            $sec['cookie_missing_policy'],
            self::ENUMS['security.cookie_missing_policy'],
            $defaults['security']['cookie_missing_policy']
        );
        $sec['cookie_mode_slots_enabled'] = (bool)($sec['cookie_mode_slots_enabled'] ?? false);
        $sec['success_ticket_ttl_seconds'] = self::clampRangeValue(
            'security.success_ticket_ttl_seconds',
            $sec['success_ticket_ttl_seconds'] ?? $defaults['security']['success_ticket_ttl_seconds']
        );
        $slotsAllowed = $sec['cookie_mode_slots_allowed'] ?? [];
        if (!is_array($slotsAllowed)) {
            $slotsAllowed = [];
        }
        $normalizedSlots = [];
        foreach ($slotsAllowed as $slotVal) {
            if (is_int($slotVal) || ctype_digit((string) $slotVal)) {
                $slot = (int) $slotVal;
                if ($slot >= 1 && $slot <= 255 && !in_array($slot, $normalizedSlots, true)) {
                    $normalizedSlots[] = $slot;
                }
            }
        }
        sort($normalizedSlots, SORT_NUMERIC);
        $sec['cookie_mode_slots_allowed'] = $normalizedSlots;
        $sec['token_ledger']['enable'] = (bool)($sec['token_ledger']['enable'] ?? true);
        $sec['submission_token']['required'] = (bool)($sec['submission_token']['required'] ?? true);

        // challenge
        $ch =& $cfg['challenge'];
        $ch['mode'] = self::sanitizeEnum(
            'challenge.mode',
            $ch['mode'],
            self::ENUMS['challenge.mode'],
            $defaults['challenge']['mode']
        );
        $ch['provider'] = self::sanitizeEnum(
            'challenge.provider',
            $ch['provider'],
            self::ENUMS['challenge.provider'],
            $defaults['challenge']['provider']
        );
        $ch['http_timeout_seconds'] = self::clampRangeValue('challenge.http_timeout_seconds', $ch['http_timeout_seconds']);

        // email
        $em =& $cfg['email'];
        $em['policy'] = in_array($em['policy'], ['strict','autocorrect'], true) ? $em['policy'] : $defaults['email']['policy'];

        // privacy
        $prv =& $cfg['privacy'];
        $prv['ip_mode'] = self::sanitizeEnum(
            'privacy.ip_mode',
            $prv['ip_mode'],
            self::ENUMS['privacy.ip_mode'],
            $defaults['privacy']['ip_mode']
        );

        // logging
        $cfg['logging']['mode'] = self::sanitizeEnum(
            'logging.mode',
            $cfg['logging']['mode'],
            self::ENUMS['logging.mode'],
            $defaults['logging']['mode']
        );
        $cfg['logging']['level'] = self::clampRangeValue('logging.level', $cfg['logging']['level']);
        $cfg['logging']['headers'] = (bool)$cfg['logging']['headers'];
        $cfg['logging']['pii'] = (bool)$cfg['logging']['pii'];
        $cfg['logging']['on_failure_canonical'] = (bool)$cfg['logging']['on_failure_canonical'];
        $cfg['logging']['file_max_size'] = self::clampInt($cfg['logging']['file_max_size'], 0, PHP_INT_MAX);
        $cfg['logging']['retention_days'] = self::clampRangeValue('logging.retention_days', $cfg['logging']['retention_days']);
        $f2b =& $cfg['logging']['fail2ban'];
        $f2b['enable'] = (bool)($f2b['enable'] ?? false);
        $f2b['target'] = self::sanitizeEnum(
            'logging.fail2ban.target',
            $f2b['target'],
            self::ENUMS['logging.fail2ban.target'],
            $defaults['logging']['fail2ban']['target']
        );
        $f2b['file'] = is_string($f2b['file']) ? $f2b['file'] : null;
        $f2b['file_max_size'] = self::clampInt(
            $f2b['file_max_size'] ?? $cfg['logging']['file_max_size'],
            0,
            PHP_INT_MAX
        );
        $f2b['retention_days'] = self::clampRangeValue(
            'logging.fail2ban.retention_days',
            $f2b['retention_days'] ?? $cfg['logging']['retention_days']
        );

        // throttle
        $cfg['throttle']['enable'] = (bool)($cfg['throttle']['enable'] ?? false);
        $thr =& $cfg['throttle']['per_ip'];
        $thr['max_per_minute'] = self::clampRangeValue(
            'throttle.per_ip.max_per_minute',
            $thr['max_per_minute'] ?? self::DEFAULTS['throttle']['per_ip']['max_per_minute']
        );
        $thr['cooldown_seconds'] = self::clampRangeValue(
            'throttle.per_ip.cooldown_seconds',
            $thr['cooldown_seconds'] ?? self::DEFAULTS['throttle']['per_ip']['cooldown_seconds']
        );
        $thr['hard_multiplier'] = self::clampRangeValue(
            'throttle.per_ip.hard_multiplier',
            $thr['hard_multiplier'] ?? self::DEFAULTS['throttle']['per_ip']['hard_multiplier']
        );

        // validation
        $cfg['validation']['max_fields_per_form'] = self::clampRangeValue('validation.max_fields_per_form', $cfg['validation']['max_fields_per_form']);
        $cfg['validation']['max_options_per_group'] = self::clampRangeValue('validation.max_options_per_group', $cfg['validation']['max_options_per_group']);
        $cfg['validation']['max_items_per_multivalue'] = self::clampRangeValue('validation.max_items_per_multivalue', $cfg['validation']['max_items_per_multivalue']);
        $cfg['validation']['textarea_html_max_bytes'] = self::clampRangeValue('validation.textarea_html_max_bytes', $cfg['validation']['textarea_html_max_bytes']);

        return $cfg;
    }

    private static function sanitizeEnum(string $path, $value, array $allowed, $default)
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function clampRangeValue(string $path, $value)
    {
        if (!isset(self::RANGE_CLAMPS[$path])) {
            throw new \InvalidArgumentException('Unknown clamp path: ' . $path);
        }
        $meta = self::RANGE_CLAMPS[$path];
        if ($meta['type'] === 'int') {
            return self::clampInt($value, $meta['min'], $meta['max']);
        }
        if ($meta['type'] === 'float') {
            return self::clampFloat($value, $meta['min'], $meta['max']);
        }
        throw new \InvalidArgumentException('Unsupported clamp type for: ' . $path);
    }

    private static function clampInt($v, int $min, int $max): int
    {
        $n = (int)$v;
        if ($n < $min) $n = $min;
        if ($n > $max) $n = $max;
        return $n;
    }

    private static function clampFloat($v, float $min, float $max): float
    {
        $n = (float)$v;
        if ($n < $min) $n = $min;
        if ($n > $max) $n = $max;
        return $n;
    }

    private static function defaults(): array
    {
        return array_replace_recursive(
            self::DEFAULTS,
            [
                'uploads' => [
                    'dir' => self::defaultUploadsDir(),
                ],
            ]
        );
    }

    private static function defaultUploadsDir(): string
    {
        if (function_exists('wp_upload_dir')) {
            $u = wp_upload_dir();
            return rtrim($u['basedir'], '/\\') . '/eforms-private';
        }
        return '';
    }
}
