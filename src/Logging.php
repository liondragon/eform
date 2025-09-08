<?php
declare(strict_types=1);

namespace EForms;

class Logging
{
    private static string $file = '';
    private static bool $init = false;

    private static function init(): void
    {
        if (self::$init) return;
        $dir = rtrim((string) Config::get('uploads.dir', sys_get_temp_dir()), '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        self::$file = $dir . '/eforms.log';
        self::$init = true;
    }

    private static function prune(string $dir, string $base, int $days): void
    {
        if ($days <= 0) return;
        $cutoff = time() - ($days * 86400);
        foreach (glob($dir . '/' . $base . '-*.log') ?: [] as $f) {
            if (@filemtime($f) !== false && filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }

    private static function rotate(string $file, int $maxBytes, int $retention): void
    {
        if ($maxBytes > 0 && file_exists($file) && filesize($file) > $maxBytes) {
            $dir = dirname($file);
            $base = basename($file, '.log');
            $ts = gmdate('Ymd-His');
            $rot = $dir . '/' . $base . '-' . $ts . '.log';
            @rename($file, $rot);
            self::prune($dir, $base, $retention);
        }
    }

    private static function logLine(string $line): void
    {
        self::init();
        $max = (int) Config::get('logging.file_max_size', 5000000);
        $ret = (int) Config::get('logging.retention_days', 30);
        self::rotate(self::$file, $max, $ret);
        file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function write(string $severity, string $code, array $ctx = []): void
    {
        if (Config::get('logging.mode', 'minimal') === 'off') {
            if (Config::get('logging.fail2ban.enable', false)) {
                self::emitFail2ban($code, $ctx);
            }
            return;
        }
        $level = (int) Config::get('logging.level', 0);
        $sevLevel = 0;
        if ($severity === 'warn') $sevLevel = 1;
        elseif ($severity === 'info') $sevLevel = 2;
        if ($sevLevel > $level) {
            return;
        }
        $data = [
            'severity' => $severity,
            'code' => $code,
            'form_id' => $ctx['form_id'] ?? '',
            'instance_id' => $ctx['instance_id'] ?? '',
            'msg' => $ctx['msg'] ?? '',
        ];
        $meta = $ctx;
        unset($meta['form_id'], $meta['instance_id'], $meta['msg']);
        if (!empty($meta)) {
            if (!Config::get('logging.pii', false)) {
                if (isset($meta['ip'])) {
                    $meta['ip'] = preg_replace('/\d+\.\d+\.\d+\.\d+/', 'x.x.x.x', (string) $meta['ip']);
                }
                if (isset($meta['email'])) {
                    $parts = explode('@', (string) $meta['email']);
                    $meta['email'] = ($parts[0] ?? '') !== '' ? substr($parts[0],0,1) . '***@' . ($parts[1] ?? '') : '';
                }
            }
            $data['meta'] = $meta;
        }
        if (Config::get('logging.headers', false)) {
            $headers = [];
            if (!empty($_SERVER['HTTP_USER_AGENT'])) {
                $headers['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
            }
            $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
            if ($origin !== '') {
                if (preg_match('~^([a-z]+://[^/]+)~i', $origin, $m)) {
                    $headers['origin'] = $m[1];
                } else {
                    $headers['origin'] = $origin;
                }
            }
            if (!empty($headers)) {
                $data['headers'] = $headers;
            }
        }
        self::logLine(json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
        if (Config::get('logging.fail2ban.enable', false)) {
            self::emitFail2ban($code, $ctx);
        }
    }

    private static function emitFail2ban(string $code, array $ctx): void
    {
        $ip = $ctx['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        $line = sprintf('eforms-fail2ban code=%s ip=%s', $code, $ip);
        error_log($line);
    }
}
