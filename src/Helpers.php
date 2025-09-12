<?php
declare(strict_types=1);

namespace EForms;

class Helpers
{
    public static function esc_html(string $v): string
    {
        return \esc_html($v);
    }

    public static function esc_attr(string $v): string
    {
        return \esc_attr($v);
    }

    public static function esc_url(string $v): string
    {
        return \esc_url($v);
    }

    public static function esc_url_raw(string $v): string
    {
        return \esc_url_raw($v);
    }

    public static function sanitize_id(string $v): string
    {
        return \sanitize_key($v);
    }

    public static function bytes_from_ini(?string $v): int
    {
        // "0"/null/"" -> PHP_INT_MAX (per spec)
        if ($v === null) return PHP_INT_MAX;
        $t = trim($v);
        if ($t === '' || $t === '0') return PHP_INT_MAX;
        // Accept forms like "128M", "1G", "512K", case-insensitive, with optional "B"
        if (!preg_match('/^(\d+(?:\.\d+)?)([KMG])?B?$/i', $t, $m)) {
            // Fallback: best-effort integer
            $n = (int)$t;
            return max(0, $n);
        }
        $num = (float)$m[1];
        $unit = isset($m[2]) ? strtolower($m[2]) : '';
        $mult = 1;
        if ($unit === 'k') $mult = 1024;
        elseif ($unit === 'm') $mult = 1024 * 1024;
        elseif ($unit === 'g') $mult = 1024 * 1024 * 1024;
        $bytes = (int) floor($num * $mult);
        return max(0, $bytes);
    }

    public static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function random_id(int $bytes = 16): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function sanitize_user_agent(string $ua): string
    {
        $ua = preg_replace('/[^\x20-\x7E]/', '', $ua) ?? '';
        $max = (int) Config::get('security.ua_maxlen', 256);
        if ($max > 0 && strlen($ua) > $max) {
            $ua = substr($ua, 0, $max);
        }
        return $ua;
    }

    public static function client_ip(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $header = (string) Config::get('privacy.client_ip_header', '');
        $proxies = (array) Config::get('privacy.trusted_proxies', []);
        $trusted = false;
        foreach ($proxies as $p) {
            $t = trim((string) $p);
            if ($t === '') continue;
            if (self::cidr_match($remote, $t)) {
                $trusted = true;
                break;
            }
        }
        if ($header !== '' && $trusted) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            $val = $_SERVER[$key] ?? '';
            if ($val !== '') {
                $parts = explode(',', $val);
                foreach ($parts as $p) {
                    $t = trim($p);
                    if ($t === '') continue;
                    if ($t[0] === '[') {
                        $end = strpos($t, ']');
                        $t = $end !== false ? substr($t, 1, $end - 1) : $t;
                    }
                    $t = preg_replace('/:\d+$/', '', $t);
                    $ip = filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
                    if ($ip !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $remote;
    }

    private static function cidr_match(string $ip, string $cidr): bool
    {
        if ($ip === '') return false;
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) return false;
        $bits = (int) $mask;
        $len = strlen($ipBin);
        if ($bits < 0 || $bits > $len * 8) return false;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) return false;
        if ($remainder === 0) return true;
        $maskByte = ~(0xFF >> $remainder) & 0xFF;
        return (ord($ipBin[$bytes]) & $maskByte) === (ord($subnetBin[$bytes]) & $maskByte);
    }

    public static function mask_ip(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $bin = @inet_pton($ip);
            if ($bin !== false && strlen($bin) === 16) {
                $bin = substr($bin, 0, 6) . str_repeat("\0", 10);
                $masked = @inet_ntop($bin);
                if ($masked !== false) {
                    return $masked;
                }
            }
            $parts = explode(':', $ip);
            $parts = array_pad($parts, 8, '0');
            for ($i = 3; $i < 8; $i++) {
                $parts[$i] = '0';
            }
            return implode(':', $parts);
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return '';
        }
        foreach ($parts as $p) {
            if (!ctype_digit($p)) {
                return '';
            }
        }
        $parts[3] = '0';
        return implode('.', $parts);
    }

    public static function ip_display(string $ip): string
    {
        $mode = Config::get('privacy.ip_mode', 'masked');
        if ($mode === 'none' || $ip === '') {
            return '';
        }
        if ($mode === 'masked') {
            return self::mask_ip($ip);
        }
        if ($mode === 'hash') {
            $salt = (string) Config::get('privacy.ip_salt', '');
            return hash('sha256', $ip . $salt);
        }
        return $ip;
    }

    public static function request_uri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') return '';
        $parts = parse_url($uri);
        $path = $parts['path'] ?? '';
        $queryOut = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $qs);
            $keep = [];
            foreach ($qs as $k => $v) {
                if (str_starts_with((string)$k, 'eforms_')) {
                    $keep[$k] = $v;
                }
            }
            if (!empty($keep)) {
                $queryOut = '?' . http_build_query($keep);
            }
        }
        return $path . $queryOut;
    }
}
