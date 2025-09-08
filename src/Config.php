<?php
declare(strict_types=1);

namespace EForms;

class Config
{
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

    private static function clampTypes(array $config, array $defaults): array
    {
        $cfg = array_replace_recursive($defaults, $config);

        // security
        $sec =& $cfg['security'];
        $sec['origin_mode'] = in_array($sec['origin_mode'], ['off','soft','hard'], true) ? $sec['origin_mode'] : $defaults['security']['origin_mode'];
        $sec['origin_missing_soft'] = (bool)$sec['origin_missing_soft'];
        $sec['origin_missing_hard'] = (bool)$sec['origin_missing_hard'];
        $sec['min_fill_seconds'] = self::clampInt($sec['min_fill_seconds'], 0, 60);
        $sec['token_ttl_seconds'] = self::clampInt($sec['token_ttl_seconds'], 1, 86400);
        $sec['max_form_age_seconds'] = self::clampInt($sec['max_form_age_seconds'] ?? $sec['token_ttl_seconds'], 1, 86400);
        $sec['js_hard_mode'] = (bool)$sec['js_hard_mode'];
        $sec['max_post_bytes'] = self::clampInt($sec['max_post_bytes'], 0, PHP_INT_MAX);
        $sec['ua_maxlen'] = self::clampInt($sec['ua_maxlen'], 0, 10000);
        $sec['honeypot_response'] = in_array($sec['honeypot_response'], ['stealth_success','error','redirect'], true) ? $sec['honeypot_response'] : $defaults['security']['honeypot_response'];
        $sec['cookie_missing_policy'] = in_array($sec['cookie_missing_policy'], ['soft','hard','off'], true) ? $sec['cookie_missing_policy'] : $defaults['security']['cookie_missing_policy'];
        $sec['token_ledger']['enable'] = (bool)($sec['token_ledger']['enable'] ?? true);
        $sec['submission_token']['required'] = (bool)($sec['submission_token']['required'] ?? true);

        // challenge
        $cfg['challenge']['http_timeout_seconds'] = self::clampInt($cfg['challenge']['http_timeout_seconds'], 1, 5);

        // logging
        $cfg['logging']['mode'] = in_array($cfg['logging']['mode'], ['off','minimal','jsonl'], true) ? $cfg['logging']['mode'] : $defaults['logging']['mode'];
        $cfg['logging']['level'] = self::clampInt($cfg['logging']['level'], 0, 2);
        $cfg['logging']['headers'] = (bool)$cfg['logging']['headers'];
        $cfg['logging']['pii'] = (bool)$cfg['logging']['pii'];
        $cfg['logging']['on_failure_canonical'] = (bool)$cfg['logging']['on_failure_canonical'];
        $cfg['logging']['file_max_size'] = self::clampInt($cfg['logging']['file_max_size'], 0, PHP_INT_MAX);
        $cfg['logging']['retention_days'] = self::clampInt($cfg['logging']['retention_days'], 1, 365);

        // validation
        $cfg['validation']['max_fields_per_form'] = self::clampInt($cfg['validation']['max_fields_per_form'], 1, 1000);
        $cfg['validation']['max_options_per_group'] = self::clampInt($cfg['validation']['max_options_per_group'], 1, 1000);
        $cfg['validation']['max_items_per_multivalue'] = self::clampInt($cfg['validation']['max_items_per_multivalue'], 1, 1000);
        $cfg['validation']['textarea_html_max_bytes'] = self::clampInt($cfg['validation']['textarea_html_max_bytes'], 1, 1000000);

        return $cfg;
    }

    private static function clampInt($v, int $min, int $max): int
    {
        $n = (int)$v;
        if ($n < $min) $n = $min;
        if ($n > $max) $n = $max;
        return $n;
    }

    private static function defaults(): array
    {
        return [
            'security' => [
                'token_ledger' => ['enable' => true],
                'token_ttl_seconds' => 600,
                'submission_token' => ['required' => true],
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
                'dir' => self::defaultUploadsDir(),
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
