<?php
// includes/class-enhanced-icf-processor.php

class Enhanced_ICF_Form_Processor {
    private $ipaddress;
    private $logger;
    private $registry;

    public function __construct(Logger $logger, FieldRegistry $registry) {
        $this->logger    = $logger;
        $this->ipaddress = $logger->get_ip();
        $this->registry  = $registry;
    }

    private function get_first_value( $value ) {
        if ( is_array( $value ) ) {
            return null;
        }
        return $value;
    }

    /**
     * Process a submitted contact form.
     *
     * Validates the request and, if successful, sends an email with the
     * sanitized form data.
     *
     * @param string $template       Template slug.
     * @param array  $submitted_data Associative array of raw form values. Keys
     *                               include `name_input`, `email_input`,
     *                               `tel_input`, `zip_input`, and
     *                               `message_input`.
     *
     * @return array {
     *     @type bool   $success   Whether the submission was processed.
     *     @type string $message   Status or error message.
     *     @type array  $form_data Sanitized form values on failure.
     * }
     */
    public function process_form_submission(string $template, array $submitted_data): array {
        if (empty($submitted_data)) {
            return $this->error_response('Form Left Empty', [], 'No data submitted.');
        }

        $validators = [
            'check_nonce',
            'check_honeypot',
            'check_submission_time',
            'check_js_enabled',
        ];

        foreach ($validators as $validator) {
            if ($error = $this->$validator($submitted_data)) {
                return $this->error_response($error['type'], [], $error['message']);
            }
        }

        $field_map = $this->registry->get_fields( $template );

        $field_list = $this->get_first_value( $submitted_data['enhanced_fields'] ?? '' );
        if ( ! empty( $field_list ) ) {
            $keys      = array_filter( array_map( 'sanitize_key', explode( ',', $field_list ) ) );
            $field_map = array_intersect_key( $field_map, array_flip( $keys ) );
        }

        $result = $this->sanitize_submission( $field_map, $submitted_data );
        $data   = $result['data'];
        if ( ! empty( $result['invalid_fields'] ) ) {
            $details  = [ 'invalid_fields' => $result['invalid_fields'] ];
            $user_msg = 'Invalid array input for field(s): ' . implode( ', ', $result['invalid_fields'] ) . '.';
            return $this->error_response( 'Invalid form input', $details, $user_msg );
        }

        $errors = $this->validate_submission( $field_map, $data );
        if ( $errors ) {
            $details = [
                'errors'    => $errors,
                'form_data' => $data,
            ];
            $user_msg = implode( '<br>', array_values( $errors ) );
            return $this->error_response( 'Validation errors', $details, $user_msg );
        }

        if ( ! $this->dispatch_email( $data ) ) {
            $details = [ 'form_data' => $data ];
            return $this->error_response( 'Email Sending Failure', $details, 'Something went wrong. Please try again later.' );
        }

        $this->log_success( $data );
        return [ 'success' => true ];
    }

    public function format_phone(string $digits): string {
        if (preg_match('/^(\\d{3})(\\d{3})(\\d{4})$/', $digits, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }
        return $digits;
    }

    private function check_nonce(array $submitted_data): array {
        $nonce = $this->get_first_value( $submitted_data['enhanced_icf_form_nonce'] ?? '' );
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'enhanced_icf_form_action' ) ) {
            return [
                'type'    => 'Nonce Failed',
                'message' => 'Invalid submission detected.',
            ];
        }
        return [];
    }

    private function check_honeypot(array $submitted_data): array {
        $honeypot_field = $submitted_data['enhanced_url'] ?? '';
        if ( is_array( $honeypot_field ) ) {
            return [
                'type'    => 'Bot Alert: Honeypot Filled',
                'message' => 'Bot test failed.',
            ];
        }
        $honeypot = $this->get_first_value( $honeypot_field );
        if ( ! empty( $honeypot ) ) {
            return [
                'type'    => 'Bot Alert: Honeypot Filled',
                'message' => 'Bot test failed.',
            ];
        }
        return [];
    }

    private function check_submission_time(array $submitted_data): array {
        $submit_time_field = $submitted_data['enhanced_form_time'] ?? 0;
        if ( is_array( $submit_time_field ) ) {
            return [
                'type'    => 'Bot Alert: Fast Submission',
                'message' => 'Submission too fast. Please try again.',
            ];
        }
        $submit_time = intval( $this->get_first_value( $submit_time_field ) );
        if ( time() - $submit_time < 5 ) {
            return [
                'type'    => 'Bot Alert: Fast Submission',
                'message' => 'Submission too fast. Please try again.',
            ];
        }
        return [];
    }

    private function check_js_enabled(array $submitted_data): array {
        $js_check = $this->get_first_value( $submitted_data['enhanced_js_check'] ?? '' );
        if ( empty( $js_check ) ) {
            return [
                'type'    => 'Bot Alert: JS Check Missing',
                'message' => 'JavaScript must be enabled.',
            ];
        }
        return [];
    }

    private function sanitize_submission( array $field_map, array $submitted_data ): array {
        $data           = [];
        $invalid_fields = [];

        foreach ( $field_map as $field => $details ) {
            $value = $this->get_first_value( $submitted_data[ $details['post_key'] ] ?? '' );
            if ( null === $value ) {
                $invalid_fields[] = $field;
                continue;
            }

            $data[ $field ] = call_user_func( $details['sanitize_cb'], $value );
        }

        return [
            'data'           => $data,
            'invalid_fields' => $invalid_fields,
        ];
    }

    private function validate_submission( array $field_map, array $data ): array {
        $errors = [];

        foreach ( $field_map as $field => $details ) {
            $error = call_user_func( $details['validate_cb'], $data[ $field ] ?? '', $details );
            if ( $error ) {
                $errors[ $field ] = $error;
            }
        }

        return $errors;
    }

    private function log_success( array $data ): void {
        $should_log = true;
        if ( defined( 'DEBUG_LEVEL' ) && DEBUG_LEVEL < 1 ) {
            $should_log = false;
        }
        if ( function_exists( 'apply_filters' ) ) {
            $should_log = apply_filters( 'eform_log_successful_submission', $should_log, $data );
        }
        if ( ! $should_log ) {
            return;
        }

        $safe_fields = eform_get_safe_fields( $data );
        $safe_data   = array_intersect_key( $data, array_flip( $safe_fields ) );
        $this->logger->log( 'Form submission sent', Logger::LEVEL_INFO, [ 'form_data' => $safe_data ] );
    }

    private function build_email_body(array $data): string {
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

    private function dispatch_email(array $data): bool {
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

    private function log_and_message(string $type, array $details = [], string $user_msg = ''): string {
        $form_data = $details['form_data'] ?? null;
        if (isset($details['form_data'])) {
            unset($details['form_data']);
        }
        $this->logger->log($type, Logger::LEVEL_ERROR, [
            'type'    => $type,
            'details' => $details,
        ], $form_data);

        return $user_msg;
    }

    private function error_response(string $type, array $details = [], string $user_msg = ''): array {
        $user_msg = $this->log_and_message($type, $details, $user_msg);
        return [
            'success'   => false,
            'message'   => $user_msg,
            'form_data' => $details['form_data'] ?? [],
            'errors'    => $details['errors'] ?? [],
        ];
    }
}

