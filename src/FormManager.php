<?php
declare(strict_types=1);

namespace EForms;

class FormManager
{
    public function render(string $formId, array $opts = []): string
    {
        $formId = \sanitize_key($formId);
        $tpl = $this->loadTemplateById($formId);
        if (!$tpl) {
            return '<div class="eforms-error">Form configuration error.</div>';
        }
        $pre = TemplateValidator::preflight($tpl);
        if (!$pre['ok']) {
            return '<div class="eforms-error">Form configuration error.</div>';
        }
        if (Uploads::enabled()) {
            Uploads::gc();
        }
        $cacheable = (bool) ($opts['cacheable'] ?? true);
        $instanceId = bin2hex(random_bytes(16));
        $timestamp = time();
        $hasUploads = Uploads::enabled() && Uploads::hasUploadFields($tpl);
        $meta = [
            'form_id' => $formId,
            'instance_id' => $instanceId,
            'timestamp' => $timestamp,
            'cacheable' => $cacheable,
            'client_validation' => (bool) Config::get('html5.client_validation', false),
            'action' => \home_url('/eforms/submit'),
            'hidden_token' => $cacheable ? null : (function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : $instanceId),
            'enctype' => $hasUploads ? 'multipart/form-data' : 'application/x-www-form-urlencoded',
        ];
        $this->enqueueAssetsIfNeeded();
        return Renderer::form($tpl, $meta, [], []);
    }

    public function handleSubmit(): void
    {
        // runtime cap
        $configCap = (int) Config::get('security.max_post_bytes', 25000000);
        $postMax = Helpers::bytes_from_ini(ini_get('post_max_size'));
        $mem = Helpers::bytes_from_ini(ini_get('memory_limit'));
        $uploadMax = Helpers::bytes_from_ini(ini_get('upload_max_filesize'));
        $cap = min($configCap, $postMax, $mem, $uploadMax);
        $cl = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($cl !== null && (int)$cl > $cap) {
            \status_header(413);
            exit;
        }
        $formId = \sanitize_key($_POST['form_id'] ?? '');
        $tpl = $this->loadTemplateById($formId);
        if (!$tpl) {
            $this->renderErrorAndExit(['id'=>$formId,'title'=>''], $formId, 'Form configuration error.');
        }
        $pre = TemplateValidator::preflight($tpl);
        if (!$pre['ok']) {
            $this->renderErrorAndExit($tpl, $formId, 'Form configuration error.');
        }
        if (Uploads::enabled()) {
            Uploads::gc();
        }
        // security gates
        $origin = Security::origin_evaluate();
        if ($origin['hard_fail']) {
            $this->renderErrorAndExit($tpl, $formId, 'Security check failed.');
        }
        $softFailCount = $origin['soft_signal'];
        $hasHidden = isset($_POST['eforms_token']) && $_POST['eforms_token'] !== '';
        $postedToken = $_POST['eforms_token'] ?? null;
        $cookieName = 'eforms_t_' . $formId;
        $cookieToken = $_COOKIE[$cookieName] ?? '';
        $tokenInfo = Security::token_validate($formId, $hasHidden, $postedToken);
        if ($tokenInfo['mode'] === 'cookie') {
            $ttl = (int) Config::get('security.token_ttl_seconds', 600);
            $newToken = function_exists('\wp_generate_uuid4') ? \wp_generate_uuid4() : bin2hex(random_bytes(16));
            \setcookie($cookieName, $newToken, [
                'expires' => time() + $ttl,
                'path' => '/',
                'secure' => \is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[$cookieName] = $newToken;
        }
        if ($tokenInfo['hard_fail']) {
            $this->renderErrorAndExit($tpl, $formId, 'Security token error.');
        }
        $softFailCount += $tokenInfo['soft_signal'];
        $challengeMode = Config::get('challenge.mode', 'off');
        $requireChallenge = $tokenInfo['require_challenge'];
        if ($challengeMode === 'always' || ($challengeMode === 'auto' && $softFailCount > 0)) {
            $requireChallenge = true;
        }
        if ($requireChallenge) {
            $provider = Config::get('challenge.provider', 'turnstile');
            $resp = $_POST['cf-turnstile-response'] ?? ($_POST['h-captcha-response'] ?? ($_POST['g-recaptcha-response'] ?? ''));
            $timeout = (int) Config::get('challenge.http_timeout_seconds', 2);
            $ver = Challenge::verify($provider, $resp, $timeout, $formId, $_POST['instance_id'] ?? '');
            if ($ver['ok'] ?? false) {
                $softFailCount = 0;
            } elseif (!($ver['unconfigured'] ?? false)) {
                $this->renderErrorAndExit($tpl, $formId, 'Security challenge failed.');
            }
        }
        // Honeypot
        $token = $hasHidden ? (string)$postedToken : $cookieToken;
        if (!empty($_POST['eforms_hp'])) {
            Security::ledger_reserve($formId, $token);
            $mode = Config::get('security.honeypot_response', 'stealth_success');
            $stealth = ($mode === 'stealth_success');
            Logging::write('warn', 'EFORMS_ERR_HONEYPOT', [
                'form_id' => $formId,
                'instance_id' => $_POST['instance_id'] ?? '',
                'stealth' => $stealth,
            ]);
            \header('X-EForms-Stealth: 1');
            if ($mode === 'hard_fail') {
                $this->renderErrorAndExit($tpl, $formId, 'Security check failed.');
            }
            $this->successAndRedirect($tpl, $formId, $_POST['instance_id'] ?? '');
            return;
        }
        $timestamp = (int) ($_POST['timestamp'] ?? 0);
        $now = time();
        $minFill = (int) Config::get('security.min_fill_seconds', 4);
        if ($timestamp > 0 && ($now - $timestamp) < $minFill) {
            $softFailCount++;
            Logging::write('warn', 'EFORMS_ERR_MIN_FILL', [
                'form_id' => $formId,
                'instance_id' => $_POST['instance_id'] ?? '',
                'delta' => $now - $timestamp,
            ]);
        }
        if ($hasHidden) {
            $maxAge = (int) Config::get('security.max_form_age_seconds', Config::get('security.token_ttl_seconds', 600));
            if ($timestamp > 0 && ($now - $timestamp) > $maxAge) {
                $softFailCount++;
                Logging::write('warn', 'EFORMS_ERR_FORM_AGE', [
                    'form_id' => $formId,
                    'instance_id' => $_POST['instance_id'] ?? '',
                    'age' => $now - $timestamp,
                ]);
            }
        }
        $jsOk = $_POST['js_ok'] ?? '';
        if ($jsOk !== '1') {
            $meta = [
                'form_id' => $formId,
                'instance_id' => $_POST['instance_id'] ?? '',
            ];
            if (Config::get('security.js_hard_mode', false)) {
                Logging::write('warn', 'EFORMS_ERR_JS_DISABLED', $meta);
                $this->renderErrorAndExit($tpl, $formId, 'Security check failed.');
            } else {
                $softFailCount++;
                Logging::write('warn', 'EFORMS_ERR_JS_DISABLED', $meta);
            }
        }
        $values = Validator::normalize($tpl, $_POST);
        $desc = Validator::descriptors($tpl);
        $val = Validator::validate($tpl, $desc, $values);
        $uploadsData = [];
        $uploadErrors = [];
        $hasUploads = Uploads::enabled() && Uploads::hasUploadFields($tpl);
        if ($hasUploads) {
            $u = Uploads::normalizeAndValidate($tpl, $_FILES);
            $uploadsData = $u['files'];
            $uploadErrors = $u['errors'];
        }
        $errors = $val['errors'];
        foreach ($uploadErrors as $k => $msgs) {
            if (isset($errors[$k])) {
                $errors[$k] = array_merge($errors[$k], $msgs);
            } else {
                $errors[$k] = $msgs;
            }
        }
        if (!empty($errors)) {
            $meta = [
                'form_id' => $formId,
                'instance_id' => $_POST['instance_id'] ?? '',
                'timestamp' => $timestamp,
                'cacheable' => !$hasHidden,
                'client_validation' => (bool) Config::get('html5.client_validation', false),
                'action' => \home_url('/eforms/submit'),
                'hidden_token' => $hasHidden ? $postedToken : null,
                'enctype' => $hasUploads ? 'multipart/form-data' : 'application/x-www-form-urlencoded',
            ];
            $this->enqueueAssetsIfNeeded();
            $html = Renderer::form($tpl, $meta, $errors, $values);
            echo $html;
            exit;
        }
        $canonical = Validator::coerce($tpl, $desc, $val['values']);
        if ($hasUploads) {
            $stored = Uploads::store($uploadsData);
            if (!empty($stored)) {
                $canonical['_uploads'] = $stored;
            }
        }
        $token = $hasHidden ? (string)$postedToken : $cookieToken;
        $reserve = Security::ledger_reserve($formId, $token);
        if (!$reserve['ok']) {
            $this->renderErrorAndExit($tpl, $formId, 'Already submitted or expired.');
        }
        $metaInfo = [
            'form_id' => $formId,
            'instance_id' => $_POST['instance_id'] ?? '',
            'submitted_at' => \gmdate('c'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        $email = Emailer::send($tpl, $canonical, $metaInfo);
        if (!$email['ok']) {
            Logging::write('error', 'EFORMS_EMAIL_FAIL', ['form_id'=>$formId,'instance_id'=>$metaInfo['instance_id'],'msg'=>'send_fail']);
            $hasUploads = Uploads::enabled() && Uploads::hasUploadFields($tpl);
            $meta = [
                'form_id' => $formId,
                'instance_id' => bin2hex(random_bytes(16)),
                'timestamp' => time(),
                'cacheable' => !$hasHidden,
                'client_validation' => (bool) Config::get('html5.client_validation', false),
                'action' => \home_url('/eforms/submit'),
                'hidden_token' => $hasHidden ? (function_exists('\wp_generate_uuid4') ? \wp_generate_uuid4() : $postedToken) : null,
                'enctype' => $hasUploads ? 'multipart/form-data' : 'application/x-www-form-urlencoded',
            ];
            $errors = ['_global' => ['Operational error. Please try again later.']];
            $this->enqueueAssetsIfNeeded();
            $html = Renderer::form($tpl, $meta, $errors, $values);
            echo $html;
            exit;
        }
        $this->successAndRedirect($tpl, $formId, $metaInfo['instance_id']);
    }

    private function loadTemplateById(string $formId): ?array
    {
        if ($formId === '') return null;
        $dir = rtrim(TEMPLATES_DIR, '/');
        $files = glob($dir . '/*.json');
        foreach ($files as $file) {
            if (!preg_match('~/[a-z0-9_-]+\.json$~', $file)) {
                continue;
            }
            $json = json_decode((string) file_get_contents($file), true);
            if (is_array($json) && ($json['id'] ?? '') === $formId) {
                return $json;
            }
        }
        return null;
    }

    private function renderErrorAndExit(array $tpl, string $formId, string $msg): void
    {
        $meta = [
            'form_id' => $formId,
            'instance_id' => $_POST['instance_id'] ?? bin2hex(random_bytes(16)),
            'timestamp' => time(),
            'cacheable' => true,
            'client_validation' => (bool) Config::get('html5.client_validation', false),
            'action' => \home_url('/eforms/submit'),
            'hidden_token' => null,
        ];
        $errors = ['_global' => [$msg]];
        $this->enqueueAssetsIfNeeded();
        $html = Renderer::form($tpl, $meta, $errors, []);
        echo $html;
        exit;
    }

    private function successAndRedirect(array $tpl, string $formId, string $instanceId): void
    {
        \nocache_headers();
        if (($tpl['success']['mode'] ?? '') === 'redirect') {
            $url = $tpl['success']['redirect_url'] ?? \home_url('/');
            \wp_safe_redirect($url, 303);
            exit;
        }
        $cookie = 'eforms_s_' . $formId;
        $value = $formId . ':' . $instanceId;
        \setcookie($cookie, $value, [
            'expires' => time() + 60,
            'path' => '/',
            'secure' => \is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $ref = \wp_get_referer();
        if (!$ref) {
            $ref = \home_url('/');
        }
        $ref = \add_query_arg('eforms_success', $formId, $ref);
        \header('Vary: Cookie');
        \wp_safe_redirect($ref, 303);
        exit;
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
