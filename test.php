<?php
// includes/class-enhanced-icf.php

class Enhanced_Internal_Contact_Form {
    private $form_errors = [];
    private $ipaddress;
    private $redirect_url; // Set to empty string to disable redirect
    private $success_message = '<div class="form-message success">Thank you! Your message has been sent.</div>';
    private $form_submitted = false;
    private $inline_css = ''; // New property to hold inline CSS

    public function __construct() {
        $this->ipaddress = enhanced_icf_get_ip();

        // ✅ Handle form submission early to allow redirect
        add_action('init', [$this, 'maybe_handle_form']);

        // ✅ Register shortcode to render form
        add_shortcode('enhanced_icf_shortcode', [$this, 'handle_shortcode']);
    }
    public function maybe_handle_form() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $template = sanitize_key($_POST['enhanced_template'] ?? 'default');

            if (isset($_POST['enhanced_form_submit_' . $template])) {
                $result = $this->process_form_submission($template);

                if ($this->form_submitted && !headers_sent() && !empty($this->redirect_url)) {
                    // ✅ Bypass WordPress safety filter to avoid login redirect trap
                    header('Location: ' . esc_url_raw($this->redirect_url));
                    exit;
                }
            }
        }
    }

    // Method hooked to wp_head when inline CSS is needed
    public function print_inline_css() {
        if (!empty($this->inline_css)) {
            echo '<style id="enhanced-icf-inline-style">' . $this->inline_css . '</style>';
        }
    }

    public function handle_shortcode($atts) {
        $atts = shortcode_atts([
            'template' => 'default',
            'style' => 'false'
        ], $atts);

        $template = sanitize_key($atts['template']);
        $load_css = filter_var($atts['style'], FILTER_VALIDATE_BOOLEAN);

        // Load template-specific CSS if style="true" as inline
        if ($load_css) {
            $css_file = "assets/{$template}.css";
            $css_path = plugin_dir_path(__FILE__) . '/../' . $css_file;

            if (file_exists($css_path)) {
                $this->inline_css = file_get_contents($css_path);

                if (did_action('wp_head')) {
                    $this->print_inline_css();
                } elseif (!has_action('wp_head', [$this, 'print_inline_css'])) {
                    add_action('wp_head', [$this, 'print_inline_css']);
                }
            }
        }

        $response = '';
        if ($this->form_submitted && !empty($this->redirect_url)) {
            return ''; // Form was submitted and we redirected, don't show anything
        }

        $template_path = plugin_dir_path(__FILE__) . "../templates/form-$template.php";
        ob_start();
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<p>Form template not found.</p>';
        }
        $form_html = ob_get_clean();
        $response = $this->form_submitted ? $this->success_message : '';
        return $response . $form_html;

    }

    private function process_form_submission($template) {
        if (empty($_POST)) {
            enhanced_icf_log('Form Left Empty');
            return '<div class="form-message">No data submitted.</div>';
        }

        if (!isset($_POST['enhanced_icf_form_nonce']) || !wp_verify_nonce($_POST['enhanced_icf_form_nonce'], 'enhanced_icf_form_action')) {
            enhanced_icf_log('Nonce Failed');
            return '<div class="form-message">Invalid submission detected.</div>';
        }

        if (!empty($_POST['enhanced_url'])) {
            enhanced_icf_log('Bot Alert: Honeypot Filled');
            return '<div class="form-message">Bot test failed.</div>';
        }

        $submit_time = $_POST['enhanced_form_time'] ?? 0;
        if (time() - intval($submit_time) < 5) {
            enhanced_icf_log('Bot Alert: Fast Submission');
            return '<div class="form-message">Submission too fast. Please try again.</div>';
        }

        if (empty($_POST['enhanced_js_check'])) {
            enhanced_icf_log('Bot Alert: JS Check Missing');
            return '<div class="form-message">JavaScript must be enabled.</div>';
        }

        $data = [
            'name' => sanitize_text_field($_POST['name_input'] ?? ''),
            'email' => sanitize_email($_POST['email_input'] ?? ''),
            'phone' => sanitize_text_field($_POST['tel_input'] ?? ''),
            'zip' => sanitize_text_field($_POST['zip_input'] ?? ''),
            'message' => sanitize_textarea_field($_POST['message_input'] ?? '')
        ];

        $errors = $this->validate_form($data);
        if ($errors) {
            enhanced_icf_log('Validation errors', ['errors' => $errors], $data);
            return '<div class="form-message">' . implode('<br>', $errors) . '</div>';
        }

        return $this->send_email($data);
    }

    private function validate_form($data) {
        $errors = [];
        if (strlen($data['name']) < 3) {
            $errors[] = 'Name too short.';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email.';
        }
        if (!preg_match('/^\d{5}$/', $data['zip'])) {
            $errors[] = 'Zip must be 5 digits.';
        }
        if (strlen($data['message']) < 10) {
            $errors[] = 'Message too short.';
        }
        return $errors;
    }

    private function send_email($data) {
        $to = get_option('admin_email');
        $subject = 'Quote Request - ' . $data['name'];
        $ip = esc_html($this->ipaddress);

        $message = '<table cellpadding="4" cellspacing="0" border="0">'
                 . "<tr><td><strong>Name:</strong></td><td>{$data['name']}</td></tr>"
                 . "<tr><td><strong>Email:</strong></td><td>{$data['email']}</td></tr>"
                 . "<tr><td><strong>Phone:</strong></td><td>{$data['phone']}</td></tr>"
                 . "<tr><td><strong>Zip:</strong></td><td>{$data['zip']}</td></tr>"
                 . "<tr><td valign='top'><strong>Message:</strong></td><td>" . nl2br($data['message']) . "</td></tr>"
                 . "<tr><td><strong>Sent from:</strong></td><td>{$ip}</td></tr>"
                 . '</table>';

        $noreply = 'noreply@flooringartists.com';
        $headers = [];
        $headers[] = "From: {$data['name']} <{$noreply}>";
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = "Reply-To: {$data['name']} <{$data['email']}>";

        $sent = wp_mail($to, $subject, $message, $headers);

        if ($sent) {
            $this->form_submitted = true;
             // success_message is already defined at class level, no need to reassign
        } else {
            // Log error with detailed context
            enhanced_icf_log('Error sending email', [
                'ip' => $this->ipaddress,
                'type' => 'Email Sending Failure',
                'details' => [
                    'to' => $to,
                    'subject' => $subject,
                    'headers' => $headers,
                    'visitor_message' => $message
                ]
            ]);

            // Additional SMTP error logging (if available)
            global $phpmailer;
            if (defined('DEBUG_LEVEL') && DEBUG_LEVEL === 3 && isset($phpmailer)) {
                $smtpErrorMsg = $phpmailer->ErrorInfo ?? 'No detailed error';
                enhanced_icf_log('Detailed SMTP Error', ['error' => $smtpErrorMsg]);
            }

            return '<div class="form-message error">Something went wrong. Please try again later.</div>';
        }
    }
}
