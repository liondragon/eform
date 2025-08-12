<?php
// includes/Emailer.php

class Emailer {
    private string $ipaddress;

    public function __construct( string $ipaddress ) {
        $this->ipaddress = $ipaddress;
    }

    private function format_phone(string $digits): string {
        if (preg_match('/^(\d{3})(\d{3})(\d{4})$/', $digits, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }
        return $digits;
    }

    public function build_email_body(array $data): string {
        $ip = esc_html($this->ipaddress);
        $rows = [
            ['label' => 'Name',    'value' => esc_html($data['name'] ?? '')],
            ['label' => 'Email',   'value' => esc_html($data['email'] ?? '')],
            ['label' => 'Phone',   'value' => esc_html($this->format_phone($data['phone'] ?? ''))],
            ['label' => 'Zip',     'value' => esc_html($data['zip'] ?? '')],
            ['label' => 'Message', 'value' => nl2br(esc_html($data['message'] ?? '')), 'valign' => 'top'],
            ['label' => 'Sent from', 'value' => $ip],
        ];

        $message_rows = '';
        foreach ($rows as $row) {
            $valign = isset($row['valign']) ? " valign='{$row['valign']}'" : '';
            $message_rows .= "<tr><td{$valign}><strong>{$row['label']}:</strong></td><td>{$row['value']}</td></tr>";
        }

        return '<table cellpadding="4" cellspacing="0" border="0">' . $message_rows . '</table>';
    }

    public function dispatch_email(array $data): bool {
        $to      = get_option('admin_email');
        $subject = 'Quote Request - ' . sanitize_text_field($data['name'] ?? '');
        $message = $this->build_email_body($data);

        $noreply = 'noreply@flooringartists.com';
        $headers = [];
        $headers[] = "From: " . ($data['name'] ?? '') . " <{$noreply}>";
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = "Reply-To: " . ($data['name'] ?? '') . " <" . ($data['email'] ?? '') . ">";

        return wp_mail($to, $subject, $message, $headers);
    }
}
