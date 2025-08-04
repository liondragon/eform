<?php
// includes/class-enhanced-icf-processor.php

class Enhanced_ICF_Form_Processor {
    private $ipaddress;

    public function __construct($ipaddress) {
        $this->ipaddress = $ipaddress;
    }

    public function process_form_submission($template) {
        $error_type = '';
        $details    = [];
        $user_msg   = '';

        if (empty($_POST)) {
            $error_type = 'Form Left Empty';
            $user_msg   = 'No data submitted.';
        } elseif (!isset($_POST['enhanced_icf_form_nonce']) || !wp_verify_nonce($_POST['enhanced_icf_form_nonce'], 'enhanced_icf_form_action')) {
            $error_type = 'Nonce Failed';
            $user_msg   = 'Invalid submission detected.';
        } elseif (!empty($_POST['enhanced_url'])) {
            $error_type = 'Bot Alert: Honeypot Filled';
            $user_msg   = 'Bot test failed.';
        } else {
            $submit_time = $_POST['enhanced_form_time'] ?? 0;
            if (time() - intval($submit_time) < 5) {
                $error_type = 'Bot Alert: Fast Submission';
                $user_msg   = 'Submission too fast. Please try again.';
            } elseif (empty($_POST['enhanced_js_check'])) {
                $error_type = 'Bot Alert: JS Check Missing';
                $user_msg   = 'JavaScript must be enabled.';
            } else {
                $data = [
                    'name'    => sanitize_text_field($_POST['name_input'] ?? ''),
                    'email'   => sanitize_email($_POST['email_input'] ?? ''),
                    'phone'   => preg_replace('/\D/', '', $_POST['tel_input'] ?? ''),
                    'zip'     => sanitize_text_field($_POST['zip_input'] ?? ''),
                    'message' => sanitize_textarea_field($_POST['message_input'] ?? ''),
                ];

                $errors = $this->validate_form($data);
                if ($errors) {
                    $error_type = 'Validation errors';
                    $details    = [
                        'errors'    => $errors,
                        'form_data' => $data,
                    ];
                    $user_msg = implode('<br>', $errors);
                } else {
                    $sent = $this->send_email($data);
                    if ($sent) {
                        return [ 'success' => true ];
                    }
                    $error_type = 'Email Sending Failure';
                    $details    = [
                        'form_data' => $data,
                    ];
                    $user_msg   = 'Something went wrong. Please try again later.';
                }
            }
        }

        $user_msg = $this->log_and_message($error_type, $details, $user_msg);

        return [
            'success'   => false,
            'message'   => $user_msg,
            'form_data' => $details['form_data'] ?? [],
        ];
    }

    public function format_phone($digits) {
        if (preg_match('/^(\d{3})(\d{3})(\d{4})$/', $digits, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }
        return $digits;
    }

    private function validate_form($data) {
        $errors = [];
        if (strlen($data['name']) < 3) {
            $errors[] = 'Name too short.';
        }
        if (!preg_match("/^[\\p{L}\\s.'-]+$/u", $data['name'])) {
            $errors[] = 'Invalid characters in name.';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email.';
        }
        if (empty($data['phone'])) {
            $errors[] = 'Phone is required.';
        } elseif (!preg_match('/^\\d{10}$/', $data['phone'])) {
            $errors[] = 'Invalid phone number.';
        }
        if (!preg_match('/^\\d{5}$/', $data['zip'])) {
            $errors[] = 'Zip must be 5 digits.';
        }
        $plain = wp_strip_all_tags($data['message']);
        if (strlen($plain) < 20) {
            $errors[] = 'Message too short.';
        }
        return $errors;
    }

    private function build_email_body($data) {
        $ip = esc_html($this->ipaddress);
        $rows = [
            ['label' => 'Name',    'value' => esc_html($data['name'])],
            ['label' => 'Email',   'value' => esc_html($data['email'])],
            ['label' => 'Phone',   'value' => esc_html($this->format_phone($data['phone']))],
            ['label' => 'Zip',     'value' => esc_html($data['zip'])],
            ['label' => 'Message', 'value' => nl2br(esc_html($data['message'])), 'valign' => 'top'],
            ['label' => 'Sent from', 'value' => $ip],
        ];

        $message_rows = '';
        foreach ($rows as $row) {
            $valign = isset($row['valign']) ? " valign='{$row['valign']}'" : '';
            $message_rows .= "<tr><td{$valign}><strong>{$row['label']}:</strong></td><td>{$row['value']}</td></tr>";
        }

        return '<table cellpadding="4" cellspacing="0" border="0">' . $message_rows . '</table>';
    }

    private function send_email($data) {
        $to = get_option('admin_email');
        $subject = 'Quote Request - ' . sanitize_text_field($data['name']);
        $message = $this->build_email_body($data);

        $noreply = 'noreply@flooringartists.com';
        $headers = [];
        $headers[] = "From: {$data['name']} <{$noreply}>";
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = "Reply-To: {$data['name']} <{$data['email']}>";

        return wp_mail($to, $subject, $message, $headers);
    }

    private function log_and_message($type, $details = [], $user_msg = '') {
        $form_data = $details['form_data'] ?? null;
        if (isset($details['form_data'])) {
            unset($details['form_data']);
        }
        enhanced_icf_log($type, [
            'type'    => $type,
            'details' => $details,
        ], $form_data);

        return $user_msg;
    }
}
