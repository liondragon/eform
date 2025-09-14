<?php
declare(strict_types=1);

namespace EForms\Email;

use EForms\Config;
use EForms\Helpers;
use EForms\Logging;

class Emailer
{
    public static function send(array $tpl, array $canonical, array $meta, int $softFails = 0): array
    {
        $policy = (string) Config::get('email.policy', 'strict');
        $to = self::parseEmail($tpl['email']['to'] ?? '', $policy, 'to');
        if ($to === '') {
            return ['ok' => false, 'msg' => 'invalid_to'];
        }
        $debugEnabled = (bool) Config::get('email.debug.enable', false) && (int) Config::get('logging.level', 0) >= 1;
        $debugBuf = '';
        $failReason = '';
        $failHook = function ($wpError) use (&$failReason) {
            $failReason = $wpError->get_error_message();
        };
        add_action('wp_mail_failed', $failHook);
        $meta = self::sanitizeMeta($meta);
        if (isset($meta['ip'])) {
            $ipDisp = Helpers::ip_display((string) $meta['ip']);
            if ($ipDisp === '') {
                unset($meta['ip']);
            } else {
                $meta['ip'] = $ipDisp;
            }
        }
        $subjectRaw = $tpl['email']['subject'] ?? 'Form Submission';
        $subjectRaw = self::expandTokens($subjectRaw, $canonical, $meta);
        $subject = self::sanitizeHeader($subjectRaw);
        if ($softFails > 0) {
            $tag = (string) Config::get('email.suspect_subject_tag', '[SUSPECT]');
            if ($tag !== '') {
                $subject = self::sanitizeHeader($tag . ' ' . $subject);
            }
        }
        $site = parse_url(\home_url(), PHP_URL_HOST) ?: 'example.com';
        $site = preg_replace('/^www\./i', '', $site);
        $fromCfg = Config::get('email.from_address', '');
        if (is_string($fromCfg) && preg_match('/@' . preg_quote($site, '/') . '$/i', $fromCfg)) {
            $from = self::sanitizeHeader($fromCfg);
        } else {
            $from = 'no-reply@' . $site;
        }
        $html = (bool) Config::get('email.html', false);
        $headers = ['From: ' . $from];
        if ($softFails > 0) {
            $headers[] = 'X-EForms-Soft-Fails: ' . (int) $softFails;
            $headers[] = 'X-EForms-Suspect: 1';
        }
        if ($html) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }
        $replyField = Config::get('email.reply_to_field', '');
        if ($replyField && isset($canonical[$replyField])) {
            $reply = self::parseEmail((string) $canonical[$replyField], $policy, $replyField);
            if ($reply !== '') {
                $headers[] = 'Reply-To: ' . self::sanitizeHeader($reply);
            }
        }
        $canonicalDisplay = self::applyDisplayFormatting($tpl, $canonical);
        [$attachments, $overflow] = self::collectAttachments($tpl, $canonicalDisplay);
        $body = self::renderBody($tpl, $canonicalDisplay, $meta, $html);
        if (!empty($overflow)) {
            $note = implode(', ', $overflow);
            if ($html) {
                $body .= '<p>Omitted attachments: ' . htmlspecialchars($note, ENT_QUOTES) . '</p>';
            } else {
                $body .= "\nOmitted attachments: $note\n";
            }
        }
        $body = preg_replace('/\r\n?/', "\n", (string) $body) ?? (string) $body;
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $body) ?? $body;
        $sender = Config::get('email.envelope_sender', '');
        $sender = is_string($sender) ? self::sanitizeHeader($sender) : '';
        $disableSend = (bool) Config::get('email.disable_send', false);
        $redirect = Config::get('email.staging_redirect_to');
        if ($disableSend || $redirect) {
            $subject = self::sanitizeHeader('[STAGING] ' . $subject);
            $headers[] = 'X-EForms-Env: staging';
        }
        if ($redirect) {
            $origTo = $to;
            $list = is_array($redirect) ? $redirect : [$redirect];
            $parsed = [];
            foreach ($list as $r) {
                $addr = self::parseEmail((string) $r, $policy, 'staging_redirect');
                if ($addr !== '') {
                    $parsed[] = $addr;
                }
            }
            if (!empty($parsed)) {
                $to = implode(',', $parsed);
            }
            $headers[] = 'X-EForms-Original-To: ' . $origTo;
        }
        if ($disableSend) {
            return ['ok' => true];
        }

        $smtp = Config::get('email.smtp', []);
        $timeout = (int) ($smtp['timeout_seconds'] ?? 10);
        $maxRetries = (int) ($smtp['max_retries'] ?? 2);
        $backoff = (int) ($smtp['retry_backoff_seconds'] ?? 2);

        $dkimCfg = Config::get('email.dkim', []);
        $dkimDomain = trim((string) ($dkimCfg['domain'] ?? ''));
        $dkimSelector = trim((string) ($dkimCfg['selector'] ?? ''));
        $dkimKeyPath = trim((string) ($dkimCfg['private_key_path'] ?? ''));
        $dkimPass = (string) ($dkimCfg['pass_phrase'] ?? '');
        $dkimEnabled = false;
        if ($dkimDomain !== '' || $dkimSelector !== '' || $dkimKeyPath !== '' || $dkimPass !== '') {
            if ($dkimDomain !== '' && $dkimSelector !== '' && $dkimKeyPath !== '' && is_readable($dkimKeyPath)) {
                $dkimEnabled = true;
            } else {
                Logging::write('warn', 'EFORMS_DKIM_INVALID', [
                    'form_id' => $meta['form_id'] ?? '',
                    'instance_id' => $meta['instance_id'] ?? '',
                ]);
            }
        }

        $attempts = $maxRetries + 1;
        $ok = false;
        $host = '';
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $hook = function ($phpmailer) use ($sender, $timeout, $dkimEnabled, $dkimDomain, $dkimSelector, $dkimKeyPath, $dkimPass, $debugEnabled, &$debugBuf, &$host) {
                if ($sender !== '') {
                    $phpmailer->Sender = $sender;
                }
                $phpmailer->Timeout = $timeout;
                $phpmailer->SMTPTimeout = $timeout;
                // Some tests stub $phpmailer as stdClass without preset properties
                $host = property_exists($phpmailer, 'Host') ? (string) $phpmailer->Host : '';
                if ($dkimEnabled) {
                    $phpmailer->DKIM_domain = $dkimDomain;
                    $phpmailer->DKIM_selector = $dkimSelector;
                    $phpmailer->DKIM_private = $dkimKeyPath;
                    $phpmailer->DKIM_passphrase = $dkimPass;
                    $phpmailer->DKIM_identity = $phpmailer->From;
                }
                if ($debugEnabled) {
                    $phpmailer->SMTPDebug = 3;
                    $phpmailer->Debugoutput = function ($str, $level) use (&$debugBuf) {
                        $debugBuf .= $str;
                    };
                }
            };
            add_action('phpmailer_init', $hook);
            $ok = \wp_mail($to, $subject, $body, $headers, $attachments);
            remove_action('phpmailer_init', $hook);
            if ($ok) {
                break;
            }
            if ($attempt < $attempts - 1 && $backoff > 0) {
                sleep($backoff);
            }
        }
        remove_action('wp_mail_failed', $failHook);
        $log = [
            'host' => $host,
            'retries' => $attempt,
        ];
        if ($failReason !== '') {
            $log['error'] = $failReason;
        }
        if ($debugEnabled && $debugBuf !== '') {
            $buf = preg_replace('/[\r\n]+/', ' ', $debugBuf);
            $buf = preg_replace('/password\s*:\s*\S+/i', 'password: ***', $buf);
            $buf = preg_replace('/passphrase\s*:\s*\S+/i', 'passphrase: ***', $buf);
            $buf = preg_replace('/authorization\s*:\s*\S+/i', 'authorization: ***', $buf);
            if (!Config::get('logging.pii', false)) {
                $buf = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+/i', '***@***', $buf);
            }
            $maxB = (int) Config::get('email.debug.max_bytes', 8192);
            if ($maxB > 0 && strlen($buf) > $maxB) {
                $buf = substr($buf, -$maxB);
            }
            $log['debug'] = $buf;
        }
        if ($ok) {
            return ['ok' => true, 'log' => $log];
        }
        return ['ok' => false, 'msg' => 'send_fail', 'log' => $log];
    }

    private static function renderBody(array $tpl, array $canonical, array $meta, bool $html): string
    {
        $template = $tpl['email']['email_template'] ?? 'default';
        if (!is_string($template) || !preg_match('/^[a-z0-9_-]+$/', $template)) {
            throw new \RuntimeException('Invalid email template');
        }
        $file = __DIR__ . '/../../templates/email/' . $template . ($html ? '.html.php' : '.txt.php');
        if (!is_file($file)) {
            throw new \RuntimeException('Email template not found');
        }
        $allowedMeta = ['submitted_at','ip','form_id','instance_id'];
        $include_fields = $tpl['email']['include_fields'] ?? [];
        $include_fields = array_values(array_filter($include_fields, function ($k) use ($canonical, $meta, $allowedMeta) {
            if (isset($canonical[$k]) || isset($canonical['_uploads'][$k])) {
                return true;
            }
            return in_array($k, $allowedMeta, true) && isset($meta[$k]);
        }));
        ob_start();
        include $file;
        $out = (string) ob_get_clean();
        return self::expandTokens($out, $canonical, $meta);
    }

    private static function sanitizeMeta(array $meta): array
    {
        $allowed = ['submitted_at','ip','form_id','instance_id'];
        return array_intersect_key($meta, array_flip($allowed));
    }

    private static function sanitizeHeader(string $v): string
    {
        $v = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $v);
        return substr(trim($v), 0, 255);
    }

    private static function expandTokens(string $str, array $canonical, array $meta): string
    {
        return preg_replace_callback('/\{\{\s*(field\.[a-z0-9_-]+|submitted_at|ip|form_id)\s*\}\}/i', function ($m) use ($canonical, $meta) {
            $token = strtolower($m[1]);
            if (str_starts_with($token, 'field.')) {
                $key = substr($token, 6);
                if (isset($canonical['_uploads'][$key])) {
                    $names = array_column($canonical['_uploads'][$key], 'original_name_safe');
                    return implode(', ', $names);
                }
                return (string) ($canonical[$key] ?? '');
            }
            return (string) ($meta[$token] ?? '');
        }, $str);
    }

    private static function parseEmail(string $email, string $policy, string $context = ''): string
    {
        $email = trim($email);
        if ($policy === 'autocorrect') {
            $email = preg_replace('/\s+/', '', $email);
            if (str_contains($email, '@')) {
                [$local, $domain] = explode('@', $email, 2);
                $orig = $domain;
                $domain = strtolower($domain);
                $domain = preg_replace('/\.c0m$/i', '.com', $domain);
                $domain = preg_replace('/\.con$/i', '.com', $domain);
                $corrected = $local . '@' . $domain;
                if ($domain !== $orig) {
                    Logging::write('info', 'EFORMS_EMAIL_DOMAIN_CORRECTED', [
                        'field' => $context,
                        'from' => $local . '@' . $orig,
                        'to' => $corrected,
                    ]);
                }
                $email = $corrected;
            }
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function collectAttachments(array $tpl, array $canonical): array
    {
        $attachments = [];
        $overflow = [];
        $maxCount = (int) Config::get('email.upload_max_attachments', 5);
        $maxBytes = (int) Config::get('uploads.max_email_bytes', 10000000);
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '' || empty($canonical['_uploads'])) {
            return [$attachments, $overflow];
        }
        $count = 0;
        $total = 0;
        foreach ($tpl['fields'] as $f) {
            $type = $f['type'] ?? '';
            if (($type !== 'file' && $type !== 'files') || empty($f['email_attach'])) {
                continue;
            }
            $k = $f['key'] ?? '';
            foreach ($canonical['_uploads'][$k] ?? [] as $item) {
                $path = $base . '/' . $item['path'];
                $size = (int) ($item['size'] ?? 0);
                if ($count >= $maxCount || $total + $size > $maxBytes) {
                    $overflow[] = $item['original_name_safe'];
                    continue;
                }
                $name = preg_replace('/[\r\n]+/', '', (string)($item['original_name_safe'] ?? ''));
                $att = ['path' => $path, 'name' => $name];
                if (!Config::get('uploads.transliterate', true) && preg_match('/[^\x20-\x7E]/', $name)) {
                    $att['encoding'] = 'utf-8';
                }
                $attachments[] = $att;
                $count++;
                $total += $size;
            }
        }
        return [$attachments, $overflow];
    }

    private static function applyDisplayFormatting(array $tpl, array $canonical): array
    {
        $fmt = $tpl['email']['display_format_tel'] ?? '';
        if (!in_array($fmt, ['xxx-xxx-xxxx','(xxx) xxx-xxxx','xxx.xxx.xxxx'], true)) {
            return $canonical;
        }
        foreach ($tpl['fields'] as $f) {
            if (($f['type'] ?? '') === 'tel_us') {
                $k = $f['key'];
                $digits = preg_replace('/\D+/', '', (string)($canonical[$k] ?? ''));
                if (strlen($digits) === 10) {
                    $canonical[$k] = self::formatTel($digits, $fmt);
                }
            }
        }
        return $canonical;
    }

    private static function formatTel(string $digits, string $fmt): string
    {
        $a = substr($digits,0,3);
        $b = substr($digits,3,3);
        $c = substr($digits,6,4);
        return match($fmt) {
            'xxx-xxx-xxxx' => "$a-$b-$c",
            '(xxx) xxx-xxxx' => "($a) $b-$c",
            'xxx.xxx.xxxx' => "$a.$b.$c",
            default => $digits,
        };
    }
}
