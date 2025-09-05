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

        $defaults = [
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

        $config = apply_filters('eforms_config', $defaults);
        self::$data = self::clampTypes($config);
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

    private static function clampTypes(array $config): array
    {
        // TODO: implement type validation/clamping
        return $config;
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
