<?php
declare(strict_types=1);

namespace EForms;

class Emailer
{
    public static function send(array $tpl, array $canonical, array $meta): array
    {
        $to = $tpl['email']['to'] ?? '';
        $subject = $tpl['email']['subject'] ?? 'Form Submission';
        $subject = substr(preg_replace("/[\r\n]+/", ' ', $subject), 0, 255);
        $site = parse_url(\home_url(), PHP_URL_HOST) ?: 'example.com';
        $fromCfg = Config::get('email.from_address', '');
        if (is_string($fromCfg) && preg_match('/@' . preg_quote($site, '/') . '$/i', $fromCfg)) {
            $from = $fromCfg;
        } else {
            $from = 'no-reply@' . $site;
        }
        $headers = ['From: ' . $from];
        $replyField = Config::get('email.reply_to_field', '');
        if ($replyField && isset($canonical[$replyField]) && \is_email($canonical[$replyField])) {
            $headers[] = 'Reply-To: ' . $canonical[$replyField];
        }
        $body = self::renderBody($tpl, $canonical, $meta);
        $ok = \wp_mail($to, $subject, $body, $headers);
        if ($ok) {
            return ['ok'=>true];
        }
        return ['ok'=>false,'msg'=>'send_fail'];
    }

    private static function renderBody(array $tpl, array $canonical, array $meta): string
    {
        $file = __DIR__ . '/../templates/email/default.txt.php';
        ob_start();
        $include_fields = $tpl['email']['include_fields'] ?? [];
        include $file;
        return (string) ob_get_clean();
    }
}
