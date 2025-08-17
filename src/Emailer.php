<?php
// includes/Emailer.php

class Emailer {
    private ?string $ipaddress;

    public function __construct( ?string $ipaddress ) {
        $this->ipaddress = $ipaddress;
    }

    private function format_phone(string $digits): string {
        if (preg_match('/^(\d{3})(\d{3})(\d{4})$/', $digits, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }
        return $digits;
    }


    public function build_email_body(array $data, array $config = [], bool $use_html = false): string {
        $include = $config['email']['include_fields'] ?? array_keys($data);
        $display_format_tel = !empty($config['display_format_tel']);

        $rows = [];
        foreach ($include as $field) {
            if (!isset($data[$field])) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', $field));
            $value = $data[$field];
            if (is_array($value)) {
                $value = implode(', ', array_map('sanitize_text_field', $value));
            }
            if ('phone' === $field && $display_format_tel) {
                $value = $this->format_phone($value);
            }
            if ($use_html) {
                if ('message' === $field) {
                    $value = nl2br(esc_html($value));
                } else {
                    $value = esc_html($value);
                }
            } else {
                $value = sanitize_textarea_field($value);
            }
            $rows[$label] = $value;
        }

        if (in_array('ip', $include, true) && null !== $this->ipaddress) {
            $rows['IP'] = $use_html ? esc_html($this->ipaddress) : sanitize_text_field($this->ipaddress);
        }

        if ($use_html) {
            $message_rows = '';
            foreach ($rows as $label => $value) {
                $message_rows .= "<tr><td><strong>{$label}:</strong></td><td>{$value}</td></tr>";
            }
            return '<table cellpadding="4" cellspacing="0" border="0">' . $message_rows . '</table>';
        }

        $lines = [];
        foreach ($rows as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        return implode("\n", $lines);
    }

    public function dispatch_email(array $data, array $config = []): bool {
        $to      = $config['email']['to'] ?? get_option('admin_email');
        $subject = $config['email']['subject'] ?? 'Quote Request - ' . sanitize_text_field($data['name'] ?? '');

        $use_html = defined('EFORM_ALLOW_HTML_EMAIL') ? (bool)EFORM_ALLOW_HTML_EMAIL : false;
        $message  = $this->build_email_body($data, $config, $use_html);

        $user   = defined('EFORMS_FROM_USER') ? sanitize_key(EFORMS_FROM_USER) : 'noreply';
        $home   = function_exists('home_url') ? home_url() : '';
        $domain = defined('EFORMS_FROM_DOMAIN') ? sanitize_text_field(EFORMS_FROM_DOMAIN) : (parse_url($home, PHP_URL_HOST) ?: 'localhost');
        $noreply = $user . '@' . $domain;
        $headers = [];

        $name  = sanitize_text_field($data['name'] ?? '');
        $email = sanitize_email($data['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        $headers[] = 'From: ' . ($name ? $name . ' ' : '') . "<{$noreply}>";
        if ($email) {
            $headers[] = 'Reply-To: ' . ($name ? $name . ' ' : '') . "<{$email}>";
        }
        $headers[] = 'Content-Type: ' . ($use_html ? 'text/html' : 'text/plain') . '; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        if (defined('EFORMS_STAGING_REDIRECT')) {
            $headers[] = 'X-Staging-Redirect: ' . sanitize_text_field(EFORMS_STAGING_REDIRECT);
        }
        if (!empty($config['email']['suspect'])) {
            $tag = defined('EFORMS_SUSPECT_TAG') ? EFORMS_SUSPECT_TAG : 'suspect';
            $headers[] = 'X-Tag: ' . sanitize_text_field($tag);
        }

        return wp_mail($to, $subject, $message, $headers);
    }
}
