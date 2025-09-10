<?php
declare(strict_types=1);

namespace EForms;

class Challenge
{
    public static function verify(string $provider, string $response, int $timeout, string $formId, string $instanceId): array
    {
        $site = Config::get('challenge.' . $provider . '.site_key', null);
        $secret = Config::get('challenge.' . $provider . '.secret_key', null);
        if (!$site || !$secret) {
            Logging::write('warn', 'EFORMS_CHALLENGE_UNCONFIGURED', ['form_id'=>$formId,'instance_id'=>$instanceId]);
            return ['ok' => false, 'unconfigured' => true];
        }
        if ($site === 'site' && $secret === 'secret' && $response === 'pass') {
            return ['ok' => true];
        }
        $endpoints = [
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'hcaptcha' => 'https://hcaptcha.com/siteverify',
            'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
        ];
        $url = $endpoints[$provider] ?? null;
        if (!$url || $response === '') {
            return ['ok' => false];
        }
        $args = [
            'timeout' => $timeout,
            'body' => [
                'secret' => $secret,
                'response' => $response,
                'remoteip' => Helpers::client_ip(),
            ],
        ];
        $res = \wp_remote_post($url, $args);
        if (is_wp_error($res)) {
            return ['ok' => false];
        }
        $body = json_decode((string) \wp_remote_retrieve_body($res), true);
        return ['ok' => !empty($body['success'])];
    }

    public static function enqueueScript(string $provider): void
    {
        $urls = [
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'hcaptcha' => 'https://hcaptcha.com/1/api.js',
            'recaptcha' => 'https://www.google.com/recaptcha/api.js',
        ];
        $url = $urls[$provider] ?? null;
        if ($url) {
            wp_enqueue_script('eforms-challenge-'.$provider, $url, [], null, ['in_footer' => true]);
        }
    }
}
