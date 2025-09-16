<?php
declare(strict_types=1);

namespace EForms\Security;

use EForms\Config;
use EForms\Helpers;
use EForms\Logging;

class Security
{
    public static function origin_evaluate(): array
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $home = \home_url();
        $homeParts = self::originParts($home);
        $state = 'missing';
        $hard = false;
        $soft = 0;
        if ($origin === null || $origin === '') {
            $state = 'missing';
            if (Config::get('security.origin_mode', 'soft') === 'off') {
                return ['state'=>$state,'hard_fail'=>false,'soft_signal'=>0];
            }
            $hard = (bool) Config::get('security.origin_missing_hard', false);
            $soft = Config::get('security.origin_missing_soft', false) ? 1 : 0;
            return ['state'=>$state,'hard_fail'=>$hard,'soft_signal'=>$soft];
        }
        $o = self::originParts($origin);
        if (!$o || !$homeParts) {
            $state = 'unknown';
        } else {
            $state = ($o === $homeParts) ? 'same' : 'cross';
        }
        $mode = Config::get('security.origin_mode', 'soft');
        if ($mode === 'off') {
            return ['state'=>$state,'hard_fail'=>false,'soft_signal'=>0];
        }
        if ($state !== 'same') {
            if ($mode === 'hard') {
                $hard = true;
            } else {
                $soft = 1;
            }
        }
        return ['state'=>$state,'hard_fail'=>$hard,'soft_signal'=>$soft];
    }

    private static function originParts(string $url): ?array
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower($p['host']);
        $port = $p['port'] ?? null;
        if ($port === null) {
            $port = ($scheme === 'https') ? 443 : 80;
        }
        if ($scheme === 'http' && $port == 80) {
            $port = 80;
        } elseif ($scheme === 'https' && $port == 443) {
            $port = 443;
        }
        return [$scheme,$host,$port];
    }

    public static function token_validate(string $formId, bool $hasHidden, ?string $postedToken): array
    {
        $cookieName = 'eforms_t_' . $formId;
        $cookieToken = $_COOKIE[$cookieName] ?? '';

        if ($hasHidden) {
            $token = (string) $postedToken;
            $parsed = self::parseToken($token);
            $record = self::loadTokenRecord($token);

            if ($record !== null) {
                if (($record['form_id'] ?? '') !== $formId) {
                    return ['mode' => 'hidden', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
                }
                if (($record['mode'] ?? '') !== 'hidden') {
                    return ['mode' => 'hidden', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
                }
                if (!$parsed['valid'] || $parsed['prefix'] === 'cookie') {
                    return ['mode' => 'hidden', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
                }
                return ['mode' => 'hidden', 'token_ok' => true, 'hard_fail' => false, 'soft_signal' => 0, 'require_challenge' => false];
            }

            if ($parsed['prefix'] === 'cookie') {
                return ['mode' => 'hidden', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
            }

            if ($parsed['valid']) {
                return ['mode' => 'hidden', 'token_ok' => true, 'hard_fail' => false, 'soft_signal' => 0, 'require_challenge' => false];
            }

            $required = (bool) Config::get('security.submission_token.required', true);
            if ($required) {
                return ['mode' => 'hidden', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
            }
            return ['mode' => 'hidden', 'token_ok' => false, 'hard_fail' => false, 'soft_signal' => 1, 'require_challenge' => false];
        }

        if ($postedToken !== null && $postedToken !== '') {
            return ['mode' => 'cookie', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
        }

        $parsedCookie = self::parseToken($cookieToken);
        $record = self::loadTokenRecord($cookieToken);
        if ($record !== null) {
            if (($record['form_id'] ?? '') !== $formId) {
                return ['mode' => 'cookie', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
            }
            if (($record['mode'] ?? '') !== 'cookie') {
                return ['mode' => 'cookie', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
            }
            if (!$parsedCookie['valid'] || $parsedCookie['prefix'] === 'hidden') {
                return ['mode' => 'cookie', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
            }
            return ['mode' => 'cookie', 'token_ok' => true, 'hard_fail' => false, 'soft_signal' => 0, 'require_challenge' => false];
        }

        if ($parsedCookie['valid'] && $parsedCookie['prefix'] !== 'hidden') {
            return ['mode' => 'cookie', 'token_ok' => true, 'hard_fail' => false, 'soft_signal' => 0, 'require_challenge' => false];
        }

        $policy = Config::get('security.cookie_missing_policy', 'soft');
        switch ($policy) {
            case 'hard':
                return ['mode' => 'cookie', 'token_ok' => false, 'hard_fail' => true, 'soft_signal' => 0, 'require_challenge' => false];
            case 'challenge':
                return ['mode' => 'cookie', 'token_ok' => false, 'hard_fail' => false, 'soft_signal' => 1, 'require_challenge' => true];
            case 'off':
                return ['mode' => 'cookie', 'token_ok' => false, 'hard_fail' => false, 'soft_signal' => 0, 'require_challenge' => false];
            case 'soft':
            default:
                return ['mode' => 'cookie', 'token_ok' => false, 'hard_fail' => false, 'soft_signal' => 1, 'require_challenge' => false];
        }
    }

    private static function parseToken(string $token): array
    {
        $prefix = '';
        $value = trim($token);
        if ($value === '') {
            return ['valid' => false, 'prefix' => '', 'uuid' => null];
        }
        if (str_starts_with($value, 'h-')) {
            $prefix = 'hidden';
            $value = substr($value, 2);
        } elseif (str_starts_with($value, 'c-')) {
            $prefix = 'cookie';
            $value = substr($value, 2);
        }
        if (!self::isUuid($value)) {
            return ['valid' => false, 'prefix' => $prefix, 'uuid' => null];
        }
        return ['valid' => true, 'prefix' => $prefix, 'uuid' => $value];
    }

    private static function loadTokenRecord(string $token): ?array
    {
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '') {
            return null;
        }
        $hashes = [hash('sha256', $token)];
        if (str_starts_with($token, 'h-') || str_starts_with($token, 'c-')) {
            $hashes[] = hash('sha256', substr($token, 2));
        }
        foreach ($hashes as $hash) {
            $dir = $base . '/tokens/' . substr($hash, 0, 2);
            $file = $dir . '/' . $hash . '.json';
            if (!is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $mode = $data['mode'] ?? null;
            $form = $data['form_id'] ?? null;
            if (!is_string($form) || $form === '') {
                continue;
            }
            if (!is_string($mode) || !in_array($mode, ['hidden', 'cookie'], true)) {
                continue;
            }
            $expires = isset($data['expires']) ? (int) $data['expires'] : 0;
            if ($expires > 0 && $expires < time()) {
                return null;
            }
            return ['form_id' => $form, 'mode' => $mode, 'expires' => $expires];
        }
        return null;
    }

    private static function isUuid(string $v): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);
    }

    public static function ledger_reserve(string $formId, string $token): array
    {
        if (!Config::get('security.token_ledger.enable', true)) {
            $result = ['ok' => true, 'skipped' => true];
            self::logLedgerReservation($formId, $token, $result);
            return $result;
        }
        $base = rtrim(Config::get('uploads.dir', ''), '/');
        $dir = $base . '/ledger';
        $hash = sha1($formId . ':' . $token);
        $h2 = substr($hash, 0, 2);
        $pathDir = $dir . '/' . $h2;
        if (!is_dir($pathDir)) {
            @mkdir($pathDir, 0700, true);
        }
        $file = $pathDir . '/' . $hash . '.used';
        $fh = @fopen($file, 'xb');
        if ($fh === false) {
            if (file_exists($file)) {
                $result = ['ok' => false, 'duplicate' => true];
                self::logLedgerReservation($formId, $token, $result);
                return $result;
            }
            $result = ['ok' => false, 'io' => true, 'file' => $file];
            self::logLedgerReservation($formId, $token, $result);
            return $result;
        }
        fclose($fh);
        @chmod($file, 0600);
        $result = ['ok' => true];
        self::logLedgerReservation($formId, $token, $result);
        return $result;
    }

    private static function logLedgerReservation(string $formId, string $token, array $result): void
    {
        $payload = [
            'form_id' => $formId,
            'token' => $token,
            'ok' => (bool) ($result['ok'] ?? false),
            'duplicate' => !empty($result['duplicate']),
            'io' => !empty($result['io']),
            'skipped' => !empty($result['skipped']),
        ];
        if (array_key_exists('file', $result)) {
            $payload['file'] = $result['file'];
        }
        Logging::write('info', 'EFORMS_RESERVE', $payload);
    }

    public static function honeypot_check(string $formId, string $token, array $logBase = []): array
    {
        if (empty($_POST['eforms_hp'])) {
            return ['triggered' => false];
        }
        $thr = ['state' => 'ok'];
        if (Config::get('throttle.enable', false)) {
            $thr = Throttle::check(Helpers::client_ip());
        }
        $res = self::ledger_reserve($formId, $token);
        if (!$res['ok'] && !empty($res['io'])) {
            Logging::write('error', 'EFORMS_LEDGER_IO', $logBase + [
                'path' => $res['file'] ?? '',
            ]);
        }
        $mode = Config::get('security.honeypot_response', 'stealth_success');
        $stealth = ($mode === 'stealth_success');
        Logging::write('warn', 'EFORMS_ERR_HONEYPOT', $logBase + [
            'stealth' => $stealth,
            'throttle_state' => $thr['state'] ?? 'ok',
        ]);
        return ['triggered' => true, 'mode' => $mode];
    }

    public static function min_fill_check(int $timestamp, array $logBase = []): int
    {
        $now = time();
        $minFill = (int) Config::get('security.min_fill_seconds', 4);
        if ($timestamp > 0 && ($now - $timestamp) < $minFill) {
            Logging::write('warn', 'EFORMS_ERR_MIN_FILL', $logBase + [
                'delta' => $now - $timestamp,
            ]);
            return 1;
        }
        return 0;
    }

    public static function form_age_check(int $timestamp, bool $hasHidden, array $logBase = []): int
    {
        if (!$hasHidden) {
            return 0;
        }
        $now = time();
        $maxAge = (int) Config::get('security.max_form_age_seconds', Config::get('security.token_ttl_seconds', 600));
        if ($timestamp > 0 && ($now - $timestamp) > $maxAge) {
            Logging::write('warn', 'EFORMS_ERR_FORM_AGE', $logBase + [
                'age' => $now - $timestamp,
            ]);
            return 1;
        }
        return 0;
    }
}
