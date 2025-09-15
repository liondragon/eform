<?php
declare(strict_types=1);

namespace EForms;

class Logging
{
    private static string $file = '';
    private static bool $init = false;
    private static string $dir = '';

    public static function resetForTests(): void
    {
        self::$file = '';
        self::$init = false;
        self::$dir = '';
    }

    private static function init(): void
    {
        \EForms\Config::bootstrap();
        $dir = rtrim((string) Config::get('uploads.dir', sys_get_temp_dir()), '/');

        if (self::$dir !== $dir) {
            self::$dir = $dir;
            self::$file = '';
            self::$init = false;
        }

        if (self::$init && self::$file !== '' && is_dir(dirname(self::$file))) {
            return;
        }

        Helpers::ensure_private_dir(self::$dir);
        self::$file = self::$dir . '/eforms.log';
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

    /**
     * Write a line of data to the log file.
     *
     * Accepts either a pre-formatted string or an array which will be encoded
     * using WordPress' JSON encoder. If encoding fails, falls back to PHP's
     * json_encode and ultimately writes an empty line if both fail.
     */
    private static function logLine(array|string $line): void
    {
        self::init();
        $max = (int) Config::get('logging.file_max_size', 5000000);
        $ret = (int) Config::get('logging.retention_days', 30);
        self::rotate(self::$file, $max, $ret);

        if (is_array($line)) {
            $json = \wp_json_encode($line, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = json_encode($line, JSON_UNESCAPED_SLASHES);
            }
            $line = ($json === false) ? '' : $json;
            if ($line !== '') {
                $line .= "\n";
            }
        }

        file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function write(string $severity, string $code, array $ctx = []): void
    {
        $mode = (string) Config::get('logging.mode', 'minimal');
        if ($mode === 'off') {
            if (Config::get('logging.fail2ban.enable', false)) {
                self::emitFail2ban($code, $ctx);
            }
            return;
        }
        if ($mode === 'jsonl') {
            self::init();
        }
        $level = (int) Config::get('logging.level', 0);
        $sevLevel = 0;
        if ($severity === 'warn') $sevLevel = 1;
        elseif ($severity === 'info') $sevLevel = 2;
        if ($sevLevel > $level) {
            return;
        }
        $ip = $ctx['ip'] ?? Helpers::client_ip();
        $ipDisp = Helpers::ip_display((string) $ip);
        $data = [
            'ts' => gmdate('c'),
            'severity' => $severity,
            'code' => $code,
            'form_id' => $ctx['form_id'] ?? '',
            'instance_id' => $ctx['instance_id'] ?? '',
            'uri' => Helpers::request_uri(),
            'ip' => $ipDisp,
        ];
        if (!empty($ctx['msg'])) {
            $data['msg'] = $ctx['msg'];
        }
        $spam = [];
        if (isset($ctx['spam']) && is_array($ctx['spam'])) {
            $spam = $ctx['spam'];
            if (!empty($spam)) {
                $data['spam'] = $spam;
            }
        }
        $email = [];
        if (isset($ctx['email']) && is_array($ctx['email'])) {
            $email = $ctx['email'];
            if (!empty($email)) {
                $data['email'] = $email;
            }
        }
        $meta = $ctx;
        unset($meta['form_id'], $meta['instance_id'], $meta['msg'], $meta['ip'], $meta['spam']);
        if (array_key_exists('token_mode', $ctx)) {
            $meta['token_mode'] = $ctx['token_mode'];
        }
        if (isset($meta['email']) && is_array($meta['email'])) {
            unset($meta['email']);
        }
        if (Config::get('logging.headers', false)) {
            $headers = [];
            if (!empty($_SERVER['HTTP_USER_AGENT'])) {
                $ua = Helpers::sanitize_user_agent((string) $_SERVER['HTTP_USER_AGENT']);
                if ($ua !== '') {
                    $headers['user_agent'] = $ua;
                }
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
                $meta['headers'] = $headers;
            }
        }
        if (!empty($meta)) {
            if (!Config::get('logging.pii', false) && isset($meta['email'])) {
                $parts = explode('@', (string) $meta['email']);
                $meta['email'] = ($parts[0] ?? '') !== '' ? substr($parts[0], 0, 1) . '***@' . ($parts[1] ?? '') : '';
            }
            $data['meta'] = $meta;
        }
        if ($mode === 'jsonl') {
            self::logLine($data);
        } else {
            $parts = [];
            $parts[] = 'severity=' . $severity;
            $parts[] = 'code=' . $code;
            if ($data['form_id'] !== '') {
                $parts[] = 'form=' . $data['form_id'];
            }
            if ($data['instance_id'] !== '') {
                $parts[] = 'inst=' . $data['instance_id'];
            }
            if ($data['ip'] !== '') {
                $parts[] = 'ip=' . $data['ip'];
            }
            if ($data['uri'] !== '') {
                $parts[] = 'uri="' . $data['uri'] . '"';
            }
            if (!empty($data['msg'])) {
                $parts[] = 'msg="' . preg_replace('/\s+/', ' ', (string) $data['msg']) . '"';
            }
            if (!empty($spam)) {
                $parts[] = 'spam=' . substr(json_encode($spam, JSON_UNESCAPED_SLASHES), 0, 200);
            }
            if (!empty($email)) {
                $parts[] = 'email=' . substr(json_encode($email, JSON_UNESCAPED_SLASHES), 0, 200);
            }
            if (!empty($meta)) {
                $parts[] = 'meta=' . substr(json_encode($meta, JSON_UNESCAPED_SLASHES), 0, 200);
            }
            error_log('eforms ' . implode(' ', $parts));
        }
        if (Config::get('logging.fail2ban.enable', false)) {
            self::emitFail2ban($code, $ctx);
        }
    }

    private static function emitFail2ban(string $code, array $ctx): void
    {
        $ip = $ctx['ip'] ?? Helpers::client_ip();
        $form = $ctx['form_id'] ?? '';
        $line = sprintf(
            'eforms[f2b] ts=%d code=%s ip=%s form=%s',
            time(),
            $code,
            $ip,
            $form
        );
        $target = Config::get('logging.fail2ban.target', 'error_log');
        if ($target === 'syslog') {
            syslog(LOG_INFO, $line);
            return;
        }
        if ($target === 'file') {
            $file = (string) Config::get('logging.fail2ban.file', '');
            if ($file === '') {
                error_log($line);
                return;
            }
            if ($file[0] !== '/') {
                $base = rtrim((string) Config::get('uploads.dir', sys_get_temp_dir()), '/');
                $file = $base . '/' . ltrim($file, '/');
            }
            $dir = dirname($file);
            Helpers::ensure_private_dir($dir);
            $max = (int) Config::get(
                'logging.fail2ban.file_max_size',
                (int) Config::get('logging.file_max_size', 5000000)
            );
            $ret = (int) Config::get(
                'logging.fail2ban.retention_days',
                (int) Config::get('logging.retention_days', 30)
            );
            self::rotate($file, $max, $ret);
            $isNew = !file_exists($file);
            $ok = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            if ($ok === false) {
                error_log($line);
                $mode = (string) Config::get('logging.mode', 'minimal');
                if ($mode === 'jsonl') {
                    self::logLine(['severity' => 'warn', 'code' => 'EFORMS_FAIL2BAN_IO']);
                } elseif ($mode !== 'off') {
                    error_log('eforms severity=warn code=EFORMS_FAIL2BAN_IO');
                }
            } else {
                if ($isNew) {
                    @chmod($file, 0600);
                }
            }
            return;
        }
        error_log($line);
    }
}
