<?php
// includes/class-enhanced-icf.php

class Enhanced_Internal_Contact_Form {
    private $form_errors = [];
    private $ipaddress;
    private $redirect_url='/?page_id=20'; // Set to empty string to disable redirect
    private $success_message = '<div class="form-message success">Thank you! Your message has been sent.</div>';
    private $error_message = '';
    private $form_submitted = false;
    private $submission_response = '';      // ← hold HTML from process_form_submission()
    private $inline_css = ''; // New property to hold inline CSS

    public function __construct() {
        $this->ipaddress = enhanced_icf_get_ip();

        // Process form early
        add_action('init',              [$this, 'maybe_handle_form']);
        // Perform redirect at template stage
        add_action('template_redirect', [$this, 'maybe_do_redirect'], 1);
        // Register shortcode to render form
        add_shortcode('enhanced_icf_shortcode', [$this, 'handle_shortcode']);
    }
    public function maybe_handle_form() {
        if ('POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }

        $template = sanitize_key($_POST['enhanced_template'] ?? 'default');
        $submit_key = 'enhanced_form_submit_' . $template;

        if (isset($_POST[$submit_key])) {
            // Process and store response; sets $this->form_submitted on success
            $this->submission_response = $this->process_form_submission($template);
        }
    }
    /**
     * Redirect after successful submission, before rendering template.
     */
    public function maybe_do_redirect() {
        if ($this->form_submitted && ! empty($this->redirect_url)) {
            wp_safe_redirect( esc_url_raw($this->redirect_url) );
            exit;
        }
    }

    // Method hooked to wp_head when inline CSS is needed
    public function print_inline_css() {
        if (!empty($this->inline_css)) {
            echo '<style id="enhanced-icf-inline-style">' . $this->inline_css . '</style>';
        }
    }

    /**
     * Output hidden fields used across form templates.
     */
    public static function render_hidden_fields($template) {
        echo wp_nonce_field('enhanced_icf_form_action', 'enhanced_icf_form_nonce', true, false);
        echo '<input type="hidden" name="enhanced_form_time" value="' . time() . '">';
        echo '<input type="hidden" name="enhanced_template" value="' . esc_attr($template) . '">';
        echo '<input type="hidden" name="enhanced_js_check" class="enhanced_js_check" value="">';
        echo '<div style="display:none;"><input type="text" name="enhanced_url" value=""></div>';
    }

    public function handle_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'template' => 'default',
        'style'    => 'false',
    ], $atts );

    $template = sanitize_key( $atts['template'] );
    $load_css = filter_var( $atts['style'], FILTER_VALIDATE_BOOLEAN );

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

     // If we succeeded *and* have a redirect URL, bail out (we’ll redirect instead)
    if ( $this->form_submitted && ! empty( $this->redirect_url ) ) {
        return '';
    }

    // Capture the form HTML
    $template_path = plugin_dir_path( __FILE__ ) . "../templates/form-{$template}.php";
    ob_start();
    if ( file_exists( $template_path ) ) {
        include $template_path;
    } else {
        echo '<p>Form template not found.</p>';
    }
    $form_html = ob_get_clean();

    // 1) If there was an error or notice from process_form_submission(), show it
    if ( $this->submission_response ) {
        // submission_response is the HTML you returned from process_form_submission()
        return $this->submission_response . $form_html;
    }

    // 2) If the mail went out but redirect is disabled, show success
    if ( $this->form_submitted ) {
        return $this->success_message . $form_html;
    }

    // 3) Otherwise just show the blank form
    return $form_html;
}

    /**
     * Helper to standardize logging and user-facing error responses.
     */
    private function log_and_message($type, $details = [], $user_msg = '') {
        $form_data = $details['form_data'] ?? null;
        if (isset($details['form_data'])) {
            unset($details['form_data']);
        }
        enhanced_icf_log($type, [
            'type'    => $type,
            'details' => $details,
        ], $form_data);

        return '<div class="form-message error">' . $user_msg . '</div>';
    }

    private function process_form_submission($template) {
        if (empty($_POST)) {
            return $this->log_and_message('Form Left Empty', [], 'No data submitted.');
        }

        if (!isset($_POST['enhanced_icf_form_nonce']) || !wp_verify_nonce($_POST['enhanced_icf_form_nonce'], 'enhanced_icf_form_action')) {
            return $this->log_and_message('Nonce Failed', [], 'Invalid submission detected.');
        }

        if (!empty($_POST['enhanced_url'])) {
            return $this->log_and_message('Bot Alert: Honeypot Filled', [], 'Bot test failed.');
        }

        $submit_time = $_POST['enhanced_form_time'] ?? 0;
        if (time() - intval($submit_time) < 5) {
            return $this->log_and_message('Bot Alert: Fast Submission', [], 'Submission too fast. Please try again.');
        }

        if (empty($_POST['enhanced_js_check'])) {
            return $this->log_and_message('Bot Alert: JS Check Missing', [], 'JavaScript must be enabled.');
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
            return $this->log_and_message('Validation errors', [
                'errors'    => $errors,
                'form_data' => $data,
            ], implode('<br>', $errors));
        }

        return $this->send_email($data);
    }

    private function validate_form($data) {
        $this->form_errors = [];
        if (strlen($data['name']) < 3) {
            $this->form_errors[] = 'Name too short.';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->form_errors[] = 'Invalid email.';
        }
        if (!preg_match('/^\d{5}$/', $data['zip'])) {
            $this->form_errors[] = 'Zip must be 5 digits.';
        }
        if (strlen($data['message']) < 10) {
            $this->form_errors[] = 'Message too short.';
        }
        return $this->form_errors;
    }

    private function send_email($data) {
        $to = get_option('admin_email');
        $subject = 'Quote Request - ' . sanitize_text_field( $data['name'] );
        $ip = esc_html($this->ipaddress);

        $rows = [
            ['label' => 'Name',       'value' => esc_html($data['name'])],
            ['label' => 'Email',      'value' => esc_html($data['email'])],
            ['label' => 'Phone',      'value' => esc_html($data['phone'])],
            ['label' => 'Zip',        'value' => esc_html($data['zip'])],
            ['label' => 'Message',    'value' => nl2br(esc_html($data['message'])), 'valign' => 'top'],
            ['label' => 'Sent from',  'value' => $ip],
        ];

        $message_rows = '';
        foreach ($rows as $row) {
            $valign = isset($row['valign']) ? " valign='{$row['valign']}'" : '';
            $message_rows .= "<tr><td{$valign}><strong>{$row['label']}:</strong></td><td>{$row['value']}</td></tr>";
        }

        $message = '<table cellpadding="4" cellspacing="0" border="0">' . $message_rows . '</table>';

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
            // Additional SMTP error logging (if available)
            global $phpmailer;
            if (defined('DEBUG_LEVEL') && DEBUG_LEVEL === 3 && isset($phpmailer)) {
                $smtpErrorMsg = $phpmailer->ErrorInfo ?? 'No detailed error';
                enhanced_icf_log('Detailed SMTP Error', ['error' => $smtpErrorMsg]);
            }

            return $this->log_and_message('Email Sending Failure', [
                'to'             => $to,
                'subject'        => $subject,
                'headers'        => $headers,
                'visitor_message'=> $message,
            ], 'Something went wrong. Please try again later.');
        }
    }
}
