<?php
declare(strict_types=1);

use EForms\Config;
use EForms\Logging;

final class ConfigClampTest extends BaseTestCase
{
    protected function boot(array $overrides): void
    {
        Config::resetForTests();
        Logging::resetForTests();
        global $TEST_FILTERS;
        $TEST_FILTERS = [];
        register_test_env_filter();
        add_filter('eforms_config', function ($defaults) use ($overrides) {
            return array_replace_recursive($defaults, $overrides);
        });
        Config::bootstrap();
    }

    protected function tearDown(): void
    {
        Config::resetForTests();
        Logging::resetForTests();
        global $TEST_FILTERS;
        $TEST_FILTERS = [];
        register_test_env_filter();
    }

    public function testSecurityClampAndDefaults(): void
    {
        $this->boot([
            'security' => [
                'origin_mode' => 'invalid',
                'origin_missing_soft' => '1',
                'origin_missing_hard' => '',
                'min_fill_seconds' => -10,
                'token_ttl_seconds' => 999999,
                'max_form_age_seconds' => -1,
                'js_hard_mode' => 'yes',
                'max_post_bytes' => -1,
                'ua_maxlen' => 20000,
                'honeypot_response' => 'bad',
                'cookie_missing_policy' => 'nope',
                'token_ledger' => ['enable' => '0'],
                // submission_token.required omitted to use default
            ],
        ]);

        $this->assertSame('soft', Config::get('security.origin_mode'));
        $this->assertTrue(Config::get('security.origin_missing_soft'));
        $this->assertFalse(Config::get('security.origin_missing_hard'));
        $this->assertSame(0, Config::get('security.min_fill_seconds'));
        $this->assertSame(86400, Config::get('security.token_ttl_seconds'));
        $this->assertSame(1, Config::get('security.max_form_age_seconds'));
        $this->assertTrue(Config::get('security.js_hard_mode'));
        $this->assertSame(0, Config::get('security.max_post_bytes'));
        $this->assertSame(10000, Config::get('security.ua_maxlen'));
        $this->assertSame('stealth_success', Config::get('security.honeypot_response'));
        $this->assertSame('soft', Config::get('security.cookie_missing_policy'));
        $this->assertFalse(Config::get('security.token_ledger.enable'));
        $this->assertTrue(Config::get('security.submission_token.required'));
    }

    public function testLoggingClamp(): void
    {
        $this->boot([
            'logging' => [
                'mode' => 'invalid',
                'level' => 99,
                'headers' => '1',
                'pii' => '0',
                'on_failure_canonical' => '',
                'file_max_size' => -1,
                'retention_days' => 9999,
                'fail2ban' => [
                    'enable' => '1',
                    'target' => 'nope',
                    'file' => 123,
                    'file_max_size' => -2,
                    'retention_days' => 9999,
                ],
            ],
            'challenge' => [
                'http_timeout_seconds' => 99,
            ],
        ]);

        $this->assertSame('minimal', Config::get('logging.mode'));
        $this->assertSame(2, Config::get('logging.level'));
        $this->assertTrue(Config::get('logging.headers'));
        $this->assertFalse(Config::get('logging.pii'));
        $this->assertFalse(Config::get('logging.on_failure_canonical'));
        $this->assertSame(0, Config::get('logging.file_max_size'));
        $this->assertSame(365, Config::get('logging.retention_days'));
        $this->assertTrue(Config::get('logging.fail2ban.enable'));
        $this->assertSame('error_log', Config::get('logging.fail2ban.target'));
        $this->assertNull(Config::get('logging.fail2ban.file'));
        $this->assertSame(0, Config::get('logging.fail2ban.file_max_size'));
        $this->assertSame(365, Config::get('logging.fail2ban.retention_days'));

        $this->assertSame(5, Config::get('challenge.http_timeout_seconds'));
    }

    public function testThrottleClamp(): void
    {
        $this->boot([
            'throttle' => [
                'enable' => '1',
                'per_ip' => [
                    'max_per_minute' => 9999,
                    'cooldown_seconds' => 1,
                    'hard_multiplier' => 0.5,
                ],
            ],
        ]);

        $this->assertTrue(Config::get('throttle.enable'));
        $this->assertSame(120, Config::get('throttle.per_ip.max_per_minute'));
        $this->assertSame(10, Config::get('throttle.per_ip.cooldown_seconds'));
        $this->assertSame(1.5, Config::get('throttle.per_ip.hard_multiplier'));
    }

    public function testValidationClamp(): void
    {
        $this->boot([
            'validation' => [
                'max_fields_per_form' => 9999,
                'max_options_per_group' => 0,
                'max_items_per_multivalue' => -5,
                'textarea_html_max_bytes' => 1000000000,
            ],
        ]);

        $this->assertSame(1000, Config::get('validation.max_fields_per_form'));
        $this->assertSame(1, Config::get('validation.max_options_per_group'));
        $this->assertSame(1, Config::get('validation.max_items_per_multivalue'));
        $this->assertSame(1000000, Config::get('validation.textarea_html_max_bytes'));
    }

    public function testChallengeEmailPrivacyClamp(): void
    {
        $this->boot([
            'challenge' => [
                'mode' => 'invalid',
                'provider' => 'nope',
            ],
            'email' => [
                'policy' => 'weird',
            ],
            'privacy' => [
                'ip_mode' => 'bad',
            ],
        ]);

        $this->assertSame('off', Config::get('challenge.mode'));
        $this->assertSame('turnstile', Config::get('challenge.provider'));
        $this->assertSame('strict', Config::get('email.policy'));
        $this->assertSame('masked', Config::get('privacy.ip_mode'));
    }
}
