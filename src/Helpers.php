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

    public static function sanitize_user_agent(string $ua): string
    {
        $ua = preg_replace('/[^\x20-\x7E]/', '', $ua) ?? '';
        $max = (int) Config::get('security.ua_maxlen', 256);
        if ($max > 0 && strlen($ua) > $max) {
            $ua = substr($ua, 0, $max);
        }
        return $ua;
    }
}
