<?php
declare(strict_types=1);

namespace EForms\Security;

use EForms\Config;

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
        $host = strtolower($p['host']);
        $port = $p['port'] ?? null;
        if ($port === null) {
            $port = ($scheme === 'https') ? 443 : (($scheme === 'http') ? 80 : null);
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
        $required = (bool) Config::get('security.submission_token.required', true);
        $cookie = $_COOKIE['eforms_t_' . $formId] ?? '';
        $cookieOk = self::isUuid($cookie);
        if ($hasHidden) {
            $tokenOk = self::isUuid((string) $postedToken);
            if ($tokenOk) {
                return ['mode' => 'hidden', 'token_ok' => true, 'hard_fail' => false, 'soft_signal' => 0, 'require_challenge' => false];
            }
            $hard = $required;
            return ['mode' => 'hidden', 'token_ok' => false, 'hard_fail' => $hard, 'soft_signal' => $hard ? 0 : 1, 'require_challenge' => false];
        }
        if ($cookieOk) {
            return ['mode' => 'cookie', 'token_ok' => true, 'hard_fail' => false, 'soft_signal' => 0, 'require_challenge' => false];
        }
        $policy = Config::get('security.cookie_missing_policy', 'soft');
        switch ($policy) {
            case 'hard':
                return ['mode'=>'cookie','token_ok'=>false,'hard_fail'=>true,'soft_signal'=>0,'require_challenge'=>false];
            case 'challenge':
                return ['mode'=>'cookie','token_ok'=>false,'hard_fail'=>false,'soft_signal'=>1,'require_challenge'=>true];
            case 'off':
                return ['mode'=>'cookie','token_ok'=>false,'hard_fail'=>false,'soft_signal'=>0,'require_challenge'=>false];
            case 'soft':
            default:
                return ['mode'=>'cookie','token_ok'=>false,'hard_fail'=>false,'soft_signal'=>1,'require_challenge'=>false];
        }
    }

    private static function isUuid(string $v): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);
    }

    public static function ledger_reserve(string $formId, string $token): array
    {
        if (!Config::get('security.token_ledger.enable', true)) {
            return ['ok' => true, 'skipped' => true];
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
                return ['ok'=>false,'duplicate'=>true];
            }
            return ['ok'=>false,'io'=>true,'file'=>$file];
        }
        fclose($fh);
        @chmod($file, 0600);
        return ['ok'=>true];
    }
}
