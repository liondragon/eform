<?php
declare(strict_types=1);

namespace EForms\Security;

use EForms\Config;
use EForms\Helpers;

class Throttle
{
    public static function check(string $ip): array
    {
        if (!Config::get('throttle.enable', false)) {
            return ['state' => 'ok'];
        }
        $key = self::keyFromIp($ip);
        if ($key === null) {
            return ['state' => 'ok'];
        }
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '') {
            return ['state' => 'ok'];
        }
        $dir = $base . '/throttle';
        $h2 = substr($key, 0, 2);
        $pathDir = $dir . '/' . $h2;
        if (!is_dir($pathDir)) {
            @mkdir($pathDir, 0700, true);
        }
        $file = $pathDir . '/' . $key . '.json';
        $now = time();
        $data = ['window_start' => $now, 'count' => 0, 'cooldown_until' => 0];
        $fh = @fopen($file, 'c+');
        if ($fh) {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            if ($raw !== false && $raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $data = array_merge($data, $json);
                }
            }
            if (($now - (int) $data['window_start']) >= 60) {
                $data['window_start'] = $now;
                $data['count'] = 0;
            }
            $data['count']++;
            $max = (int) Config::get('throttle.per_ip.max_per_minute', 5);
            $cool = (int) Config::get('throttle.per_ip.cooldown_seconds', 60);
            $mult = (float) Config::get('throttle.per_ip.hard_multiplier', 3.0);
            $state = 'ok';
            if ($now < (int) $data['cooldown_until']) {
                $state = 'over';
            }
            if ($data['count'] > $max * $mult) {
                $state = 'hard';
            } elseif ($data['count'] > $max || $state === 'over') {
                $state = 'over';
                if ($now >= (int) $data['cooldown_until']) {
                    $data['cooldown_until'] = $now + $cool;
                }
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($data));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            @chmod($file, 0600);
            return ['state' => $state, 'count' => $data['count'], 'retry_after' => max(0, (int)$data['cooldown_until'] - $now)];
        }
        return ['state' => 'ok', 'retry_after' => 0];
    }

    public static function gc(): void
    {
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '') return;
        $dir = $base . '/throttle';
        if (!is_dir($dir)) return;
        $cutoff = time() - 172800; // 2 days
        foreach (glob($dir . '/*/*') ?: [] as $f) {
            if (@filemtime($f) !== false && filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (count(glob($sub . '/*') ?: []) === 0) {
                @rmdir($sub);
            }
        }
    }

    private static function keyFromIp(string $ip): ?string
    {
        $mode = Config::get('privacy.ip_mode', 'masked');
        if ($mode === 'none' || $ip === '') {
            return null;
        }
        if ($mode === 'full') {
            return $ip;
        }
        if ($mode === 'masked') {
            $ip = Helpers::mask_ip($ip);
        }
        $salt = (string) Config::get('privacy.ip_salt', '');
        return hash('sha256', $ip . $salt);
    }
}
