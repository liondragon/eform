<?php
declare(strict_types=1);

namespace EForms;

class FormManager
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
        if (Uploads::enabled()) {
            Uploads::gc();
        }
        if (Config::get('throttle.enable', false)) {
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
            'hidden_token' => $cacheable ? null : (function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : Helpers::uuid4()),
            'enctype' => $hasUploads ? 'multipart/form-data' : 'application/x-www-form-urlencoded',
        ];
        $challengeMode = Config::get('challenge.mode', 'off');
        $cookiePolicy = Config::get('security.cookie_missing_policy', 'soft');
        if ($challengeMode !== 'off' || $cookiePolicy === 'challenge') {
            $prov = Config::get('challenge.provider', 'turnstile');
            if ($challengeMode === 'always') {
                $site = Config::get('challenge.' . $prov . '.site_key', '');
                $meta['challenge'] = ['provider' => $prov, 'site_key' => $site];
            }
            Challenge::enqueueScript($prov);
        }
        $this->enqueueAssetsIfNeeded();
        $html = Renderer::form($tpl, $meta, [], []);
        $estimate = (int) ($tpl['max_input_vars_estimate'] ?? 0);
        $max = (int) ini_get('max_input_vars');
        if ($max <= 0) $max = 1000;
        $comment = '';
        if ($estimate >= (int) ceil(0.9 * $max)) {
            Logging::write('warn', 'EFORMS_MAX_INPUT_VARS_NEAR_LIMIT', ['estimate'=>$estimate,'max_input_vars'=>$max]);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $comment = "<!-- eforms: max_input_vars advisory — estimate=$estimate, max_input_vars=$max -->";
            }
        }
        return $html . $comment;
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
        $tplInfo = $this->loadTemplateById($formId);
        if (!$tplInfo) {
            $this->renderErrorAndExit(['id'=>$formId,'title'=>''], $formId, 'Form configuration error.');
        }
        $pre = TemplateValidator::preflight($tplInfo['tpl'], $tplInfo['path']);
        if (!$pre['ok']) {
            $this->renderErrorAndExit($tplInfo['tpl'], $formId, 'Form configuration error.');
        }
        $tpl = $tplInfo['tpl'];
        if (Uploads::enabled()) {
            Uploads::gc();
        }
        if (Config::get('throttle.enable', false)) {
            Throttle::gc();
        }
        // security gates
        $origin = Security::origin_evaluate();
        Logging::write('info', 'EFORMS_ORIGIN_STATE', [
            'form_id' => $formId,
            'instance_id' => $_POST['instance_id'] ?? '',
            'spam' => ['origin_state' => $origin['state']]
        ]);
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
            Logging::write('warn', 'EFORMS_ERR_TOKEN', [
                'form_id' => $formId,
                'instance_id' => $_POST['instance_id'] ?? '',
                'ip' => Helpers::client_ip(),
            ]);
            $this->renderErrorAndExit($tpl, $formId, 'This form was already submitted or has expired – please reload the page.');
        }
        $softFailCount += $tokenInfo['soft_signal'];
        $ua = Helpers::sanitize_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            $softFailCount++;
        }
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
                $softFailCount++;
                Logging::write('warn', 'EFORMS_ERR_CHALLENGE_FAILED', [
                    'form_id' => $formId,
                    'instance_id' => $_POST['instance_id'] ?? '',
                ]);
                $this->renderErrorAndExit($tpl, $formId, 'Security challenge failed.');
            } else {
                $softFailCount++;
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
        $throttleState = 'ok';
        if (Config::get('throttle.enable', false)) {
            $ip = Helpers::client_ip();
            $thr = Throttle::check($ip);
            $throttleState = $thr['state'] ?? 'ok';
            if ($throttleState !== 'ok') {
                Logging::write('warn', 'EFORMS_THROTTLE', [
                    'form_id' => $formId,
                    'instance_id' => $_POST['instance_id'] ?? '',
                    'ip' => $ip,
                    'state' => $throttleState,
                ]);
                if (!headers_sent()) {
                    $retry = (int)($thr['retry_after'] ?? 0);
                    if ($retry > 0) {
                        \header('Retry-After: ' . $retry);
                    }
                }
                if ($throttleState === 'hard') {
                    $this->renderErrorAndExit($tpl, $formId, 'Security check failed.');
                }
                $softFailCount++;
            }
        }
        $threshold = (int) Config::get('spam.soft_fail_threshold', 2);
        if ($softFailCount >= $threshold) {
            Logging::write('warn', 'EFORMS_ERR_SPAM_THRESHOLD', [
                'form_id' => $formId,
                'instance_id' => $_POST['instance_id'] ?? '',
                'spam' => [
                    'soft_fail_count' => $softFailCount,
                    'origin_state' => $origin['state'],
                    'throttle_state' => $throttleState,
                    'honeypot' => false,
                ],
            ]);
            $this->renderErrorAndExit($tpl, $formId, 'Security check failed.');
        }
        $suspect = $softFailCount > 0;
        $postedFields = $_POST[$formId] ?? [];
        $values = Validator::normalize($tpl, $postedFields);
        $desc = Validator::descriptors($tpl);
        $val = Validator::validate($tpl, $desc, $values);
        $uploadsData = [];
        $uploadErrors = [];
        $hasUploads = Uploads::enabled() && Uploads::hasUploadFields($tpl);
        if ($hasUploads) {
            $filesRoot = $_FILES[$formId] ?? [];
            $files = [];
            foreach ($filesRoot as $attr => $arr) {
                foreach ($arr as $k => $v) {
                    $files[$k][$attr] = $v;
                }
            }
            $u = Uploads::normalizeAndValidate($tpl, $files);
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
            if ($requireChallenge) {
                $prov = Config::get('challenge.provider', 'turnstile');
                $site = Config::get('challenge.' . $prov . '.site_key', '');
                $meta['challenge'] = ['provider'=>$prov,'site_key'=>$site];
                Challenge::enqueueScript($prov);
            }
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
            'ip' => Helpers::client_ip(),
        ];
        $email = Emailer::send($tpl, $canonical, $metaInfo, $softFailCount);
        if (($email['ok'] ?? false) && !empty($canonical['_uploads']) && Config::get('uploads.delete_after_send', true)) {
            Uploads::deleteStored($canonical['_uploads']);
        }
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
                'hidden_token' => $hasHidden ? (function_exists('\wp_generate_uuid4') ? \wp_generate_uuid4() : Helpers::uuid4()) : null,
                'enctype' => $hasUploads ? 'multipart/form-data' : 'application/x-www-form-urlencoded',
            ];
            if ($requireChallenge) {
                $prov = Config::get('challenge.provider', 'turnstile');
                $site = Config::get('challenge.' . $prov . '.site_key', '');
                $meta['challenge'] = ['provider'=>$prov,'site_key'=>$site];
                Challenge::enqueueScript($prov);
            }
            $errors = ['_global' => ['Operational error. Please try again later.']];
            $this->enqueueAssetsIfNeeded();
            $html = Renderer::form($tpl, $meta, $errors, $values);
            echo $html;
            exit;
        }
        if ($suspect && !headers_sent()) {
            \header('X-EForms-Soft-Fails: ' . $softFailCount);
            \header('X-EForms-Suspect: 1');
        }
        if ($suspect) {
            Logging::write('info', 'EFORMS_SUSPECT', [
                'form_id' => $formId,
                'instance_id' => $metaInfo['instance_id'],
                'spam' => [
                    'soft_fail_count' => $softFailCount,
                    'origin_state' => $origin['state'],
                    'throttle_state' => $throttleState,
                    'honeypot' => false,
                ],
            ]);
        }
        $this->successAndRedirect($tpl, $formId, $metaInfo['instance_id']);
    }

    /**
     * @return array{tpl:array,path:string}|null
     */
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
                return ['tpl' => $json, 'path' => $file];
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
        \header('Cache-Control: private, no-store, max-age=0');
        if (function_exists('eforms_header')) {
            eforms_header('Cache-Control: private, no-store, max-age=0');
        }
        if (($tpl['success']['mode'] ?? '') === 'redirect') {
            $url = $tpl['success']['redirect_url'] ?? \home_url('/');
            \wp_safe_redirect($url, 303);
            exit;
        }
        $ref = \wp_get_referer();
        if (!$ref) {
            $ref = \home_url('/');
        }
        $path = parse_url($ref, PHP_URL_PATH) ?: '/';
        $cookie = 'eforms_s_' . $formId;
        $value = $formId . ':' . $instanceId;
        \setcookie($cookie, $value, [
            'expires' => time() + 300,
            'path' => $path,
            'secure' => \is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
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
