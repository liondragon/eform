<?php
declare(strict_types=1);

namespace EForms;

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
        if ($hasHidden) {
            $tokenOk = !empty($postedToken);
            $hard = $required && !$tokenOk;
            return ['mode'=>'hidden','token_ok'=>$tokenOk,'hard_fail'=>$hard,'soft_signal'=>$tokenOk?0:1,'require_challenge'=>false];
        }
        $cookie = $_COOKIE['eforms_t_' . $formId] ?? '';
        $tokenOk = $cookie !== '';
        $hard = $required && !$tokenOk;
        return ['mode'=>'cookie','token_ok'=>$tokenOk,'hard_fail'=>$hard,'soft_signal'=>$tokenOk?0:1,'require_challenge'=>false];
    }

    public static function ledger_reserve(string $formId, string $token): array
    {
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
            return ['ok'=>false,'io'=>true];
        }
        fclose($fh);
        @chmod($file, 0600);
        return ['ok'=>true];
    }
}
