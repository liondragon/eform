<?php
declare(strict_types=1);

namespace EForms\Submission;

use EForms\Config;
use EForms\Email\Emailer;
use EForms\Helpers;
use EForms\Logging;
use EForms\Rendering\Renderer;
use EForms\Security\Challenge;
use EForms\Security\Security;
use EForms\Security\Throttle;
use EForms\Uploads\Uploads;
use EForms\Validation\TemplateValidator;
use EForms\Validation\Validator;
use const EForms\{TEMPLATES_DIR, PLUGIN_DIR, ASSETS_DIR, VERSION};

class SubmitHandler
{
    public function handleSubmit(): void
    {
        // runtime cap
        $appCap = (int) Config::get('security.max_post_bytes', 25000000);
        $iniPost = Helpers::bytes_from_ini(ini_get('post_max_size'));
        $iniUpload = Helpers::bytes_from_ini(ini_get('upload_max_filesize'));
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        $uploadsEnabled = Uploads::enabled();
        $hasUpload = $uploadsEnabled && strpos($contentType, 'multipart/form-data') !== false;
        $cap = $hasUpload ? min($appCap, $iniPost, $iniUpload) : min($appCap, $iniPost);
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
        // Use normalized template context from preflight so that field descriptors
        // include resolved handler identifiers and other defaults.
        $tpl = $pre['context'];
        $instanceId = (string) ($_POST['instance_id'] ?? '');
        $baseMeta = ['form_id' => $formId, 'instance_id' => $instanceId];
        $logBase = $baseMeta;
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
        // security gates
        $origin = Security::origin_evaluate();
        Logging::write('info', 'EFORMS_ORIGIN_STATE', $logBase + [
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
            $newToken = function_exists('\wp_generate_uuid4') ? \wp_generate_uuid4() : Helpers::random_id(16);
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
            Logging::write('warn', 'EFORMS_ERR_TOKEN', $logBase + [
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
            require_once __DIR__ . '/../Security/Challenge.php';
            $provider = Config::get('challenge.provider', 'turnstile');
            $resp = $_POST['cf-turnstile-response'] ?? ($_POST['h-captcha-response'] ?? ($_POST['g-recaptcha-response'] ?? ''));
            if ($resp === '') {
                $this->renderErrorAndExit($tpl, $formId, 'Security check failed.', true);
            }
            $timeout = (int) Config::get('challenge.http_timeout_seconds', 2);
            $ver = Challenge::verify($provider, $resp, $timeout, $formId, $instanceId);
            if ($ver['ok'] ?? false) {
                $softFailCount = 0;
            } elseif (!($ver['unconfigured'] ?? false)) {
                $softFailCount++;
                Logging::write('warn', 'EFORMS_ERR_CHALLENGE_FAILED', $logBase);
                $this->renderErrorAndExit($tpl, $formId, 'Security challenge failed.', true);
            } else {
                $softFailCount++;
            }
        }
        // Honeypot
        $token = $hasHidden ? (string)$postedToken : $cookieToken;
        $hp = Security::honeypot_check($formId, $token, $logBase);
        if ($hp['triggered']) {
            \header('X-EForms-Stealth: 1');
            Uploads::unlinkTemps($_FILES);
            if (($hp['mode'] ?? '') === 'hard_fail') {
                $this->renderErrorAndExit($tpl, $formId, 'Form submission failed.');
            }
            $this->successAndRedirect($tpl, $formId, $instanceId);
            return;
        }
        $timestamp = (int) ($_POST['timestamp'] ?? 0);
        $softFailCount += Security::min_fill_check($timestamp, $logBase);
        $softFailCount += Security::form_age_check($timestamp, $hasHidden, $logBase);
        $jsOk = $_POST['js_ok'] ?? '';
        if ($jsOk !== '1') {
            $meta = $baseMeta;
            if (Config::get('security.js_hard_mode', false)) {
                Logging::write('warn', 'EFORMS_ERR_JS_DISABLED', $logBase);
                $this->renderErrorAndExit($tpl, $formId, 'Security check failed.');
            } else {
                $softFailCount++;
                Logging::write('warn', 'EFORMS_ERR_JS_DISABLED', $logBase);
            }
        }
        $throttleState = 'ok';
        if (Config::get('throttle.enable', false)) {
            $ip = Helpers::client_ip();
            $thr = Throttle::check($ip);
            $throttleState = $thr['state'] ?? 'ok';
            if ($throttleState !== 'ok') {
                Logging::write('warn', 'EFORMS_ERR_THROTTLED', $logBase + [
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
                    $this->renderErrorAndExit($tpl, $formId, 'Please wait a moment and try again.');
                }
                $softFailCount++;
            }
        }
        $threshold = (int) Config::get('spam.soft_fail_threshold', 2);
        if ($softFailCount >= $threshold) {
            Logging::write('warn', 'EFORMS_ERR_SPAM_THRESHOLD', $logBase + [
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
        $desc = Validator::descriptors($tpl);
        $values = Validator::normalize($tpl, $postedFields, $desc);
        $val = Validator::validate($tpl, $desc, $values);
        $uploadsData = [];
        $uploadErrors = [];
        $rawFiles = [];
        $hasUploads = Uploads::enabled() && Uploads::hasUploadFields($tpl);
        if ($hasUploads) {
            $filesRoot = $_FILES[$formId] ?? [];
            $files = [];
            foreach ($filesRoot as $attr => $arr) {
                foreach ($arr as $k => $v) {
                    $files[$k][$attr] = $v;
                }
            }
            $rawFiles = $files;
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
            $logCtx = $logBase;
            if (Config::get('logging.on_failure_canonical', false)) {
                $canon = [];
                foreach ($errors as $ek => $msgs) {
                    if ($ek === '_global') continue;
                    $valCanon = $val['values'][$ek] ?? null;
                    if (is_array($valCanon)) {
                        $canon[$ek] = array_values(array_map('strval', $valCanon));
                    } elseif ($valCanon !== null) {
                        $canon[$ek] = (string)$valCanon;
                    }
                }
                if (!empty($canon)) {
                    $logCtx['canonical'] = $canon;
                }
            }
            Logging::write('info', 'EFORMS_ERR_VALIDATION', $logCtx);
            $meta = $baseMeta + [
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
        $token = $hasHidden ? (string)$postedToken : $cookieToken;
        $reserve = Security::ledger_reserve($formId, $token);
        if (!$reserve['ok']) {
            if (!empty($reserve['io'])) {
                Logging::write('error', 'EFORMS_LEDGER_IO', $logBase + [
                    'path' => $reserve['file'] ?? '',
                ]);
            }
            if ($hasUploads) {
                Uploads::unlinkTemps($rawFiles);
            }
            $this->renderErrorAndExit($tpl, $formId, 'Already submitted or expired.');
        }
        if ($hasUploads) {
            $stored = Uploads::store($uploadsData);
            $expected = 0;
            foreach ($uploadsData as $list) {
                $expected += count($list);
            }
            $storedCount = 0;
            foreach ($stored as $list) {
                $storedCount += count($list);
            }
            if ($storedCount !== $expected) {
                Uploads::deleteStored($stored);
                Uploads::unlinkTemps($rawFiles);
                Logging::write('error', 'EFORMS_UPLOAD_STORE_FAIL', $logBase);
                $newInstance = Helpers::random_id(16);
                if ($tokenInfo['mode'] === 'cookie') {
                    $ttl = (int) Config::get('security.token_ttl_seconds', 600);
                    $newToken = function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : Helpers::random_id(16);
                    \setcookie($cookieName, $newToken, [
                        'expires' => time() + $ttl,
                        'path' => '/',
                        'secure' => \is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    $_COOKIE[$cookieName] = $newToken;
                }
                $meta = [
                    'form_id' => $formId,
                    'instance_id' => $newInstance,
                    'timestamp' => $timestamp > 0 ? $timestamp : time(),
                    'cacheable' => !$hasHidden,
                    'client_validation' => (bool) Config::get('html5.client_validation', false),
                    'action' => \home_url('/eforms/submit'),
                    'hidden_token' => $hasHidden ? (function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : Helpers::uuid4()) : null,
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
                $html = Renderer::form($tpl, $meta, $errors, $canonical);
                echo $html;
                exit;
            }
            if (!empty($stored)) {
                $canonical['_uploads'] = $stored;
            }
        }
        $metaInfo = $baseMeta + [
            'submitted_at' => \gmdate('c'),
            'ip' => Helpers::client_ip(),
        ];
        $email = Emailer::send($tpl, $canonical, $metaInfo, $softFailCount);
        if (($email['ok'] ?? false) && !empty($canonical['_uploads']) && Config::get('uploads.delete_after_send', true)) {
            Uploads::deleteStored($canonical['_uploads']);
        }
        if (!$email['ok']) {
            $storedUploads = $canonical['_uploads'] ?? [];
            if (!empty($storedUploads) && (int) Config::get('uploads.retention_seconds', 86400) === 0) {
                Uploads::deleteStored($storedUploads);
            }
            $ctx = $logBase + [
                'msg' => $email['msg'] ?? 'send_fail',
            ];
            if (!empty($email['log'])) {
                $ctx['email'] = $email['log'];
            }
            if (!empty($storedUploads)) {
                $files = [];
                foreach ($storedUploads as $list) {
                    foreach ($list as $item) {
                        $files[] = ['path'=>$item['path'] ?? '', 'sha256'=>$item['sha256'] ?? ''];
                    }
                }
                if (!empty($files)) {
                    $ctx['uploads'] = $files;
                }
            }
            Logging::write('error', 'EFORMS_EMAIL_FAIL', $ctx);
            unset($canonical['_uploads']);
            $newInstance = Helpers::random_id(16);
            if ($tokenInfo['mode'] === 'cookie') {
                $ttl = (int) Config::get('security.token_ttl_seconds', 600);
                $newToken = function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : Helpers::random_id(16);
                \setcookie($cookieName, $newToken, [
                    'expires' => time() + $ttl,
                    'path' => '/',
                    'secure' => \is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                $_COOKIE[$cookieName] = $newToken;
            }
            $hasUploads = Uploads::enabled() && Uploads::hasUploadFields($tpl);
            $meta = [
                'form_id' => $formId,
                'instance_id' => $newInstance,
                'timestamp' => $timestamp > 0 ? $timestamp : time(),
                'cacheable' => !$hasHidden,
                'client_validation' => (bool) Config::get('html5.client_validation', false),
                'action' => \home_url('/eforms/submit'),
                'hidden_token' => $hasHidden ? (function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : Helpers::uuid4()) : null,
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
            $html = Renderer::form($tpl, $meta, $errors, $canonical);
            echo $html;
            exit;
        }
        if ($suspect && !headers_sent()) {
            \header('X-EForms-Soft-Fails: ' . $softFailCount);
            \header('X-EForms-Suspect: 1');
        }
        if ($suspect) {
            Logging::write('info', 'EFORMS_SUSPECT', $logBase + [
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

    private function renderErrorAndExit(array $tpl, string $formId, string $msg, bool $includeChallenge = false): void
    {
        $ts = isset($_POST['timestamp']) ? (int) $_POST['timestamp'] : time();
        $meta = [
            'form_id' => $formId,
            'instance_id' => $_POST['instance_id'] ?? Helpers::random_id(16),
            'timestamp' => $ts,
            'cacheable' => true,
            'client_validation' => (bool) Config::get('html5.client_validation', false),
            'action' => \home_url('/eforms/submit'),
            'hidden_token' => null,
        ];
        if ($includeChallenge) {
            $prov = Config::get('challenge.provider', 'turnstile');
            $site = Config::get('challenge.' . $prov . '.site_key', '');
            $meta['challenge'] = ['provider'=>$prov,'site_key'=>$site];
            Challenge::enqueueScript($prov);
        }
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
