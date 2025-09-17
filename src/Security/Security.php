<?php
declare(strict_types=1);

namespace EForms\Security;

use EForms\Config;
use EForms\Helpers;
use EForms\Logging;

class Security
{
    /** @var array<string, array{form_id:string,mode:string,expires:int,issued_at:int}> */
    private static array $hiddenTokenCache = [];
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

    public static function token_validate(string $formId, array $context): array
    {
        $modeClaim = is_string($context['mode_claim'] ?? null) ? $context['mode_claim'] : '';
        $tokenFieldPresent = (bool) ($context['token_field_present'] ?? false);
        $postedToken = (string) ($context['posted_token'] ?? '');
        $cookieToken = (string) ($context['cookie_token'] ?? '');
        $slot = (int) ($context['slot'] ?? 1);
        if ($slot < 1) {
            $slot = 1;
        }

        $hiddenRecord = null;
        if ($postedToken !== '' && self::isHiddenToken($postedToken)) {
            $hiddenRecord = self::hiddenTokenRecord($postedToken);
        }

        $cookieRecord = null;
        if ($cookieToken !== '' && self::isEid($cookieToken)) {
            $cookieRecord = self::loadMintedRecord($formId, $cookieToken);
        }

        $mode = '';
        if ($hiddenRecord !== null) {
            $mode = 'hidden';
        } elseif ($cookieRecord !== null) {
            $mode = 'cookie';
        } elseif ($tokenFieldPresent || $modeClaim === 'hidden') {
            $mode = 'hidden';
        } else {
            $mode = 'cookie';
        }

        if ($mode === 'hidden') {
            $tokenRequired = (bool) Config::get('security.submission_token.required', true);
            if (!$tokenFieldPresent || $postedToken === '') {
                return self::hiddenTokenFailureResult($postedToken, $tokenRequired, 'missing_hidden_token');
            }
            if (!self::isHiddenToken($postedToken)) {
                return self::hiddenTokenFailureResult($postedToken, $tokenRequired, 'invalid_hidden_token');
            }
            if ($hiddenRecord === null) {
                return self::hiddenTokenFailureResult($postedToken, $tokenRequired, 'hidden_record_missing');
            }
            if (($hiddenRecord['form_id'] ?? '') !== $formId) {
                return self::hiddenTokenFailureResult($postedToken, $tokenRequired, 'form_mismatch');
            }
            if (($hiddenRecord['mode'] ?? '') !== 'hidden') {
                return self::hiddenTokenFailureResult($postedToken, $tokenRequired, 'mode_mismatch');
            }
            $expires = isset($hiddenRecord['expires']) ? (int) $hiddenRecord['expires'] : 0;
            if ($expires > 0 && $expires < time()) {
                return self::hiddenTokenFailureResult(
                    $postedToken,
                    $tokenRequired,
                    'expired',
                    (int) ($hiddenRecord['issued_at'] ?? 0),
                    $expires
                );
            }

            return [
                'mode' => 'hidden',
                'submission_id' => $postedToken,
                'token_ok' => true,
                'hard_fail' => false,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => (int) ($hiddenRecord['issued_at'] ?? 0),
                'expires' => $expires,
                'slot' => 1,
            ];
        }

        if ($postedToken !== '') {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => 0,
                'expires' => 0,
                'slot' => $slot,
                'reason' => 'hidden_token_posted',
            ];
        }

        $slotsEnabled = (bool) Config::get('security.cookie_mode_slots_enabled', false);
        $allowedSlots = Config::get('security.cookie_mode_slots_allowed', []);
        if (!is_array($allowedSlots)) {
            $allowedSlots = [];
        }
        if (!$slotsEnabled && $slot > 1) {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => 0,
                'expires' => 0,
                'slot' => $slot,
                'reason' => 'slot_not_allowed',
            ];
        }
        if ($slotsEnabled && !in_array($slot, $allowedSlots, true)) {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => 0,
                'expires' => 0,
                'slot' => $slot,
                'reason' => 'slot_not_allowed',
            ];
        }

        if ($cookieToken === '') {
            return self::cookieMissingPolicyResult('', $slot);
        }

        if (!self::isEid($cookieToken)) {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => 0,
                'expires' => 0,
                'slot' => $slot,
                'reason' => 'invalid_cookie_token',
            ];
        }

        if ($cookieRecord === null) {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => 0,
                'expires' => 0,
                'slot' => $slot,
                'reason' => 'minted_record_missing',
            ];
        }
        if (($cookieRecord['form_id'] ?? '') !== $formId) {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => 0,
                'expires' => 0,
                'slot' => $slot,
                'reason' => 'form_mismatch',
            ];
        }
        if (($cookieRecord['mode'] ?? '') !== 'cookie') {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => 0,
                'expires' => 0,
                'slot' => $slot,
                'reason' => 'mode_mismatch',
            ];
        }
        if (($cookieRecord['eid'] ?? '') !== $cookieToken) {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => 0,
                'expires' => 0,
                'slot' => $slot,
                'reason' => 'eid_mismatch',
            ];
        }
        $recordSlots = $cookieRecord['slots_allowed'] ?? [];
        if (is_array($recordSlots) && !empty($recordSlots) && !in_array($slot, $recordSlots, true)) {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => (int) ($cookieRecord['issued_at'] ?? 0),
                'expires' => (int) ($cookieRecord['expires'] ?? 0),
                'slot' => $slot,
                'reason' => 'slot_not_minted',
            ];
        }
        $expires = isset($cookieRecord['expires']) ? (int) $cookieRecord['expires'] : 0;
        if ($expires > 0 && $expires < time()) {
            return [
                'mode' => 'cookie',
                'submission_id' => $cookieToken,
                'token_ok' => false,
                'hard_fail' => true,
                'soft_signal' => 0,
                'require_challenge' => false,
                'issued_at' => (int) ($cookieRecord['issued_at'] ?? 0),
                'expires' => $expires,
                'slot' => $slot,
                'reason' => 'expired',
            ];
        }

        $submissionId = $cookieToken;
        if ($slot > 1) {
            $submissionId .= ':s' . $slot;
        }

        return [
            'mode' => 'cookie',
            'submission_id' => $submissionId,
            'token_ok' => true,
            'hard_fail' => false,
            'soft_signal' => 0,
            'require_challenge' => false,
            'issued_at' => (int) ($cookieRecord['issued_at'] ?? 0),
            'expires' => $expires,
            'slot' => $slot,
        ];
    }

    public static function hiddenTokenRecord(string $token): ?array
    {
        $token = (string) $token;
        if ($token === '') {
            return null;
        }
        if (array_key_exists($token, self::$hiddenTokenCache)) {
            return self::$hiddenTokenCache[$token];
        }
        $record = self::loadTokenRecord($token);
        if ($record === null || ($record['mode'] ?? '') !== 'hidden') {
            return null;
        }
        self::$hiddenTokenCache[$token] = $record;
        return $record;
    }

    private static function hiddenTokenFailureResult(
        string $token,
        bool $required,
        string $reason,
        int $issuedAt = 0,
        int $expires = 0
    ): array
    {
        return [
            'mode' => 'hidden',
            'submission_id' => $token,
            'token_ok' => false,
            'hard_fail' => $required,
            'soft_signal' => $required ? 0 : 1,
            'require_challenge' => false,
            'issued_at' => $issuedAt,
            'expires' => $expires,
            'slot' => 1,
            'reason' => $reason,
        ];
    }

    private static function cookieMissingPolicyResult(
        string $submissionId,
        int $slot,
        int $issuedAt = 0,
        int $expires = 0,
        string $reason = 'cookie_missing'
    ): array
    {
        $policy = Config::get('security.cookie_missing_policy', 'soft');
        switch ($policy) {
            case 'hard':
                return [
                    'mode' => 'cookie',
                    'submission_id' => $submissionId,
                    'token_ok' => false,
                    'hard_fail' => true,
                    'soft_signal' => 0,
                    'require_challenge' => false,
                    'issued_at' => $issuedAt,
                    'expires' => $expires,
                    'slot' => $slot,
                    'reason' => $reason,
                ];
            case 'challenge':
                return [
                    'mode' => 'cookie',
                    'submission_id' => $submissionId,
                    'token_ok' => false,
                    'hard_fail' => false,
                    'soft_signal' => 1,
                    'require_challenge' => true,
                    'issued_at' => $issuedAt,
                    'expires' => $expires,
                    'slot' => $slot,
                    'reason' => $reason,
                ];
            case 'off':
                return [
                    'mode' => 'cookie',
                    'submission_id' => $submissionId,
                    'token_ok' => false,
                    'hard_fail' => false,
                    'soft_signal' => 0,
                    'require_challenge' => false,
                    'issued_at' => $issuedAt,
                    'expires' => $expires,
                    'slot' => $slot,
                    'reason' => $reason,
                ];
            case 'soft':
            default:
                return [
                    'mode' => 'cookie',
                    'submission_id' => $submissionId,
                    'token_ok' => false,
                    'hard_fail' => false,
                    'soft_signal' => 1,
                    'require_challenge' => false,
                    'issued_at' => $issuedAt,
                    'expires' => $expires,
                    'slot' => $slot,
                    'reason' => $reason,
                ];
        }
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
            $issuedAt = isset($data['issued_at']) ? (int) $data['issued_at'] : 0;
            return ['form_id' => $form, 'mode' => $mode, 'expires' => $expires, 'issued_at' => $issuedAt];
        }
        return null;
    }

    private static function loadMintedRecord(string $formId, string $eid): ?array
    {
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '' || $eid === '' || $formId === '') {
            return null;
        }
        $hash = hash('sha256', $eid);
        $dir = $base . '/eid_minted/' . $formId . '/' . substr($hash, 0, 2);
        $file = $dir . '/' . $eid . '.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $mode = $data['mode'] ?? null;
        $form = $data['form_id'] ?? null;
        if (!is_string($form) || $form === '') {
            return null;
        }
        if (!is_string($mode) || $mode === '') {
            return null;
        }
        $issuedAt = isset($data['issued_at']) ? (int) $data['issued_at'] : 0;
        $expires = isset($data['expires']) ? (int) $data['expires'] : 0;
        $eidValue = isset($data['eid']) ? (string) $data['eid'] : '';
        $slotsAllowed = [];
        if (isset($data['slots_allowed']) && is_array($data['slots_allowed'])) {
            foreach ($data['slots_allowed'] as $slotVal) {
                if (is_int($slotVal) || ctype_digit((string) $slotVal)) {
                    $slot = (int) $slotVal;
                    if ($slot >= 1 && $slot <= 255 && !in_array($slot, $slotsAllowed, true)) {
                        $slotsAllowed[] = $slot;
                    }
                }
            }
            sort($slotsAllowed, SORT_NUMERIC);
        }
        return [
            'form_id' => $form,
            'mode' => $mode,
            'eid' => $eidValue,
            'issued_at' => $issuedAt,
            'expires' => $expires,
            'slots_allowed' => $slotsAllowed,
        ];
    }

    private static function isHiddenToken(string $token): bool
    {
        return $token !== '' && preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $token) === 1;
    }

    private static function isEid(string $token): bool
    {
        return $token !== '' && preg_match('/^i-[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $token) === 1;
    }

    public static function ledger_reserve(string $formId, string $submissionId): array
    {
        if (!Config::get('security.token_ledger.enable', true)) {
            $result = ['ok' => true, 'skipped' => true];
            self::logLedgerReservation($formId, $submissionId, $result);
            return $result;
        }
        $base = rtrim(Config::get('uploads.dir', ''), '/');
        $root = $base . '/ledger/' . $formId;
        $file = self::submissionFilePath($root, $submissionId, '.used');
        $fh = @fopen($file, 'xb');
        if ($fh === false) {
            if (file_exists($file)) {
                $result = ['ok' => false, 'duplicate' => true];
                self::logLedgerReservation($formId, $submissionId, $result);
                return $result;
            }
            $result = ['ok' => false, 'io' => true, 'file' => $file];
            self::logLedgerReservation($formId, $submissionId, $result);
            return $result;
        }
        fclose($fh);
        @chmod($file, 0600);
        $result = ['ok' => true];
        self::logLedgerReservation($formId, $submissionId, $result);
        return $result;
    }

    private static function logLedgerReservation(string $formId, string $submissionId, array $result): void
    {
        $payload = [
            'form_id' => $formId,
            'submission_id' => $submissionId,
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

    public static function honeypot_check(string $formId, string $submissionId, array $logBase = []): array
    {
        if (empty($_POST['eforms_hp'])) {
            return ['triggered' => false];
        }
        $thr = ['state' => 'ok'];
        if (Config::get('throttle.enable', false)) {
            $thr = Throttle::check(Helpers::client_ip());
        }
        $res = self::ledger_reserve($formId, $submissionId);
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

    private static function sanitizeLedgerSegment(string $segment): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', $segment) ?? '';
        return trim($clean, '/');
    }

    private static function submissionFilePath(string $root, string $submissionId, string $extension): string
    {
        $hash = hash('sha256', $submissionId);
        $h2 = substr($hash, 0, 2);
        $dir = $root . '/' . $h2;
        self::ensureDir($dir);
        $segments = self::submissionIdSegments($submissionId);
        foreach ($segments['dirs'] as $segment) {
            $dir .= '/' . $segment;
            self::ensureDir($dir);
        }
        $file = $segments['file'] === '' ? substr($hash, 2, 20) : $segments['file'];
        return $dir . '/' . $file . $extension;
    }

    /**
     * @return array{dirs: array<int, string>, file: string}
     */
    private static function submissionIdSegments(string $submissionId): array
    {
        if (str_contains($submissionId, ':')) {
            [$base, $slot] = explode(':', $submissionId, 2);
            $baseClean = self::sanitizeLedgerSegment($base);
            $slotClean = self::sanitizeLedgerSegment($slot);
            if ($baseClean === '') {
                $baseClean = '_';
            }
            return [
                'dirs' => [$baseClean],
                'file' => $slotClean === '' ? '_' : $slotClean,
            ];
        }
        $file = self::sanitizeLedgerSegment($submissionId);
        if ($file === '') {
            $file = '_';
        }
        return ['dirs' => [], 'file' => $file];
    }

    private static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        @mkdir($dir, 0700, true);
        @chmod($dir, 0700);
    }

    public static function successTicketStore(string $formId, string $submissionId): bool
    {
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '' || $formId === '' || $submissionId === '') {
            return false;
        }
        $root = $base . '/success/' . $formId;
        $path = self::submissionFilePath($root, $submissionId, '.json');
        $now = time();
        $ttl = (int) Config::get('security.success_ticket_ttl_seconds', 300);
        $expires = $ttl > 0 ? $now + $ttl : $now + 300;
        $payload = json_encode([
            'form_id' => $formId,
            'submission_id' => $submissionId,
            'issued_at' => $now,
            'expires' => $expires,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }
        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            return false;
        }
        @chmod($path, 0600);
        return true;
    }

    public static function successTicketExists(string $formId, string $submissionId): bool
    {
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '' || $formId === '' || $submissionId === '') {
            return false;
        }
        $root = $base . '/success/' . $formId;
        $path = self::submissionFilePath($root, $submissionId, '.json');
        if (!is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return false;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        $expires = isset($data['expires']) ? (int) $data['expires'] : 0;
        if ($expires > 0 && $expires < time()) {
            return false;
        }
        return true;
    }

    public static function successTicketConsume(string $formId, string $submissionId): array
    {
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '' || $formId === '' || $submissionId === '') {
            return ['ok' => false, 'reason' => 'unconfigured'];
        }
        $root = $base . '/success/' . $formId;
        $path = self::submissionFilePath($root, $submissionId, '.json');
        if (!is_file($path)) {
            return ['ok' => false, 'reason' => 'missing'];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['ok' => false, 'reason' => 'io'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            @unlink($path);
            return ['ok' => false, 'reason' => 'corrupt'];
        }
        $expires = isset($data['expires']) ? (int) $data['expires'] : 0;
        if ($expires > 0 && $expires < time()) {
            @unlink($path);
            return ['ok' => false, 'reason' => 'expired'];
        }
        @unlink($path);
        return ['ok' => true, 'data' => $data];
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
