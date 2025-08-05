<?php
// includes/class-enhanced-icf-processor.php

class Enhanced_ICF_Form_Processor {
    private $ipaddress;
    private $logger;

    public function __construct(Logger $logger) {
        $this->logger    = $logger;
        $this->ipaddress = $logger->get_ip();
    }

    public function process_form_submission($template) {
        if (empty($_POST)) {
            return $this->error_response('Form Left Empty', [], 'No data submitted.');
        }

        $validators = [
            'check_nonce',
            'check_honeypot',
            'check_submission_time',
            'check_js_enabled',
        ];

        foreach ($validators as $validator) {
            if ($error = $this->$validator()) {
                return $this->error_response($error['type'], [], $error['message']);
            }
        }

        $data = [
            'name'    => sanitize_text_field($_POST['name_input'] ?? ''),
            'email'   => sanitize_email($_POST['email_input'] ?? ''),
            'phone'   => preg_replace('/\\D/', '', $_POST['tel_input'] ?? ''),
            'zip'     => sanitize_text_field($_POST['zip_input'] ?? ''),
            'message' => sanitize_textarea_field($_POST['message_input'] ?? ''),
        ];

        $errors = $this->validate_form($data);
        if ($errors) {
            $details  = [
                'errors'    => $errors,
                'form_data' => $data,
            ];
            $user_msg = implode('<br>', $errors);
            return $this->error_response('Validation errors', $details, $user_msg);
        }

        if ($this->send_email($data)) {
            $should_log = true;
            if (defined('DEBUG_LEVEL') && DEBUG_LEVEL < 1) {
                $should_log = false;
            }
            if (function_exists('apply_filters')) {
                $should_log = apply_filters('eform_log_successful_submission', $should_log, $data);
            }
            if ($should_log) {
                $safe_fields = ['name', 'zip'];
                if (function_exists('get_option')) {
                    $option_fields = get_option('eform_log_safe_fields', []);
                    if (!empty($option_fields) && is_array($option_fields)) {
                        $safe_fields = $option_fields;
                    }
                }
                if (function_exists('apply_filters')) {
                    $safe_fields = apply_filters('eform_log_safe_fields', $safe_fields, $data);
                }
                $safe_data = array_intersect_key($data, array_flip($safe_fields));
                $this->logger->log('Form submission sent', ['form_data' => $safe_data]);
            }
            return [ 'success' => true ];
        }

        $details = [ 'form_data' => $data ];
        return $this->error_response('Email Sending Failure', $details, 'Something went wrong. Please try again later.');
    }

    public function format_phone($digits) {
        if (preg_match('/^(\\d{3})(\\d{3})(\\d{4})$/', $digits, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }
        return $digits;
    }

    private function check_nonce() {
        if (!isset($_POST['enhanced_icf_form_nonce']) || !wp_verify_nonce($_POST['enhanced_icf_form_nonce'], 'enhanced_icf_form_action')) {
            return [
                'type'    => 'Nonce Failed',
                'message' => 'Invalid submission detected.',
            ];
        }
        return [];
    }

    private function check_honeypot() {
        if (!empty($_POST['enhanced_url'])) {
            return [
                'type'    => 'Bot Alert: Honeypot Filled',
                'message' => 'Bot test failed.',
            ];
        }
        return [];
    }

    private function check_submission_time() {
        $submit_time = $_POST['enhanced_form_time'] ?? 0;
        if (time() - intval($submit_time) < 5) {
            return [
                'type'    => 'Bot Alert: Fast Submission',
                'message' => 'Submission too fast. Please try again.',
            ];
        }
        return [];
    }

    private function check_js_enabled() {
        if (empty($_POST['enhanced_js_check'])) {
            return [
                'type'    => 'Bot Alert: JS Check Missing',
                'message' => 'JavaScript must be enabled.',
            ];
        }
        return [];
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
        $this->logger->log($type, [
            'type'    => $type,
            'details' => $details,
        ], $form_data);

        return $user_msg;
    }

    private function error_response($type, $details = [], $user_msg = '') {
        $user_msg = $this->log_and_message($type, $details, $user_msg);
        return [
            'success'   => false,
            'message'   => $user_msg,
            'form_data' => $details['form_data'] ?? [],
        ];
    }
}

