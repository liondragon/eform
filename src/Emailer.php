<?php
declare(strict_types=1);

namespace EForms;

class Emailer
{
    public static function send(array $tpl, array $canonical, array $meta): array
    {
        $to = $tpl['email']['to'] ?? '';
        $meta = self::sanitizeMeta($meta);
        $subjectRaw = $tpl['email']['subject'] ?? 'Form Submission';
        $subjectRaw = self::expandTokens($subjectRaw, $canonical, $meta);
        $subject = substr(preg_replace("/[\r\n]+/", ' ', $subjectRaw), 0, 255);
        $site = parse_url(\home_url(), PHP_URL_HOST) ?: 'example.com';
        $fromCfg = Config::get('email.from_address', '');
        if (is_string($fromCfg) && preg_match('/@' . preg_quote($site, '/') . '$/i', $fromCfg)) {
            $from = $fromCfg;
        } else {
            $from = 'no-reply@' . $site;
        }
        $html = (bool) Config::get('email.html', false);
        $headers = ['From: ' . $from];
        if ($html) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }
        $replyField = Config::get('email.reply_to_field', '');
        if ($replyField && isset($canonical[$replyField]) && \is_email($canonical[$replyField])) {
            $headers[] = 'Reply-To: ' . $canonical[$replyField];
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
        $ok = \wp_mail($to, $subject, $body, $headers, $attachments);
        if ($ok) {
            return ['ok'=>true];
        }
        return ['ok'=>false,'msg'=>'send_fail'];
    }

    private static function renderBody(array $tpl, array $canonical, array $meta, bool $html): string
    {
        $file = __DIR__ . '/../templates/email/' . ($html ? 'default.html.php' : 'default.txt.php');
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
                $attachments[] = $path;
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
