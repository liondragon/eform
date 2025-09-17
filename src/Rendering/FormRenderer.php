<?php
declare(strict_types=1);

namespace EForms\Rendering;

use EForms\Config;
use EForms\Helpers;
use EForms\Logging;
use EForms\Security\Challenge;
use EForms\Security\Security;
use EForms\Security\Throttle;
use EForms\Uploads\Uploads;
use EForms\Validation\TemplateValidator;
use const EForms\{TEMPLATES_DIR, PLUGIN_DIR, ASSETS_DIR, VERSION};

class FormRenderer
{
    public function render(string $formId, array $opts = []): string
    {
        $formId = \sanitize_key($formId);
        $tplInfo = $this->loadTemplateById($formId);
        if (!$tplInfo) {
            return '<div class="eforms-error">Form configuration error.</div>';
        }
        $pre = TemplateValidator::preflight($tplInfo['tpl'], $tplInfo['path']);
        if (!$pre['ok']) {
            return '<div class="eforms-error">Form configuration error.</div>';
        }
        $tpl = $pre['context'];
        $instanceId = Helpers::random_id(16);
        $logBase = ['form_id' => $formId, 'instance_id' => $instanceId];
        if ((int) Config::get('logging.level', 0) >= 2) {
            $logBase['desc_sha1'] = sha1(json_encode($tpl['descriptors'] ?? [], JSON_UNESCAPED_SLASHES));
        }
        if (Uploads::enabled() && Uploads::hasUploadFields($tpl)) {
            Uploads::gc();
        }
        if (Config::get('throttle.enable', false)) {
            require_once __DIR__ . '/../Security/Throttle.php';
            Throttle::gc();
        }
        $cacheable = (bool) ($opts['cacheable'] ?? true);
        if (!$cacheable) {
            \nocache_headers();
            \header('Cache-Control: private, no-store, max-age=0');
            if (function_exists('eforms_header')) {
                eforms_header('Cache-Control: private, no-store, max-age=0');
            }
        }
        $timestamp = time();
        $hasUploads = Uploads::enabled() && Uploads::hasUploadFields($tpl);
        $meta = [
            'form_id' => $formId,
            'instance_id' => $instanceId,
            'timestamp' => $timestamp,
            'cacheable' => $cacheable,
            'client_validation' => (bool) Config::get('html5.client_validation', false),
            'action' => \home_url('/eforms/submit'),
            'hidden_token' => $cacheable ? null : (function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : Helpers::uuid4()),
            'enctype' => $hasUploads ? 'multipart/form-data' : 'application/x-www-form-urlencoded',
        ];
        if (!$cacheable && ($meta['hidden_token'] ?? '') !== '') {
            $record = Security::hiddenTokenRecord((string) $meta['hidden_token']);
            if ($record === null) {
                $base = rtrim((string) Config::get('uploads.dir', ''), '/');
                if ($base !== '') {
                    $hash = hash('sha256', (string) $meta['hidden_token']);
                    $dir = $base . '/tokens/' . substr($hash, 0, 2);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0700, true);
                    }
                    if (is_dir($dir)) {
                        $ttl = (int) Config::get('security.token_ttl_seconds', 600);
                        $expires = $ttl > 0 ? $timestamp + $ttl : 0;
                        $payload = json_encode([
                            'mode' => 'hidden',
                            'form_id' => $formId,
                            'issued_at' => $timestamp,
                            'expires' => $expires,
                        ], JSON_UNESCAPED_SLASHES);
                        if ($payload !== false) {
                            $path = $dir . '/' . $hash . '.json';
                            @file_put_contents($path, $payload);
                            Security::hiddenTokenRecord((string) $meta['hidden_token']);
                        }
                    }
                }
            }
        }
        $challengeMode = Config::get('challenge.mode', 'off');
        $needChallenge = false;
        if ($challengeMode !== 'off') {
            $needChallenge = true;
        } elseif (Config::get('security.cookie_missing_policy', 'soft') === 'challenge') {
            $tInfo = Security::token_validate($formId, false, null);
            if (!$tInfo['token_ok']) {
                $needChallenge = true;
            }
        }
        if ($needChallenge) {
            require_once __DIR__ . '/../Security/Challenge.php';
            $prov = Config::get('challenge.provider', 'turnstile');
            $site = Config::get('challenge.' . $prov . '.site_key', '');
            $meta['challenge'] = ['provider' => $prov, 'site_key' => $site];
            Challenge::enqueueScript($prov);
        }
        $this->enqueueAssetsIfNeeded();
        $html = Renderer::form($tpl, $meta, [], []);
        $estimate = (int) ($tpl['max_input_vars_estimate'] ?? 0);
        if (!$cacheable) {
            $estimate++;
        }
        $max = (int) ini_get('max_input_vars');
        if ($max <= 0) $max = 1000;
        $comment = '';
        if ($estimate >= (int) ceil(0.9 * $max)) {
            Logging::write('warn', 'EFORMS_MAX_INPUT_VARS_NEAR_LIMIT', $logBase + ['estimate'=>$estimate,'max_input_vars'=>$max]);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $comment = "<!-- eforms: max_input_vars advisory — estimate=$estimate, max_input_vars=$max -->";
            }
        }
        return $html . $comment;
    }

    private function loadTemplateById(string $formId): ?array
    {
        if ($formId === '') return null;
        $dir = rtrim(TEMPLATES_DIR, '/');
        $files = glob($dir . '/*.json');
        foreach ($files as $file) {
            if (!preg_match('~/[a-z0-9-]+\.json$~', $file)) {
                continue;
            }
            $json = json_decode((string) file_get_contents($file), true);
            if (is_array($json) && ($json['id'] ?? '') === $formId) {
                return ['tpl' => $json, 'path' => $file];
            }
        }
        return null;
    }

    public function enqueueAssetsIfNeeded(): void
    {
        wp_register_style(
            'eforms-forms',
            plugins_url('assets/forms.css', PLUGIN_DIR . '/eforms.php'),
            [],
            @filemtime(ASSETS_DIR . '/forms.css') ?: VERSION
        );
        wp_register_script(
            'eforms-forms',
            plugins_url('assets/forms.js', PLUGIN_DIR . '/eforms.php'),
            [],
            @filemtime(ASSETS_DIR . '/forms.js') ?: VERSION,
            ['in_footer' => true]
        );
        if (!Config::get('assets.css_disable', false)) {
            wp_enqueue_style('eforms-forms');
        }
        wp_enqueue_script('eforms-forms');
    }
}
