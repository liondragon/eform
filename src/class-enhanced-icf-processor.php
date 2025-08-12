<?php
// src/class-enhanced-icf-processor.php

class Enhanced_ICF_Form_Processor {
    private $ipaddress;
    private $logger;
    private $security;
    private $validator;
    private $emailer;
    private array $array_field_types;

    public function __construct(Logging $logger, ?Security $security = null, ?Validator $validator = null, ?Emailer $emailer = null, array $array_field_types = ['checkbox']) {
        $this->logger           = $logger;
        $this->ipaddress        = $logger->get_ip();
        $this->security         = $security  ?? new Security();
        $this->validator        = $validator ?? new Validator();
        $this->emailer          = $emailer   ?? new Emailer( $this->ipaddress );
        $this->array_field_types = $array_field_types;
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
        if ( empty( $submitted_data ) ) {
            return $this->error_response( 'Form Left Empty', [], 'No data submitted.' );
        }

        $validators = [
            'check_nonce',
            'check_honeypot',
            'check_submission_time',
            'check_js_enabled',
        ];

        foreach ( $validators as $validator ) {
            if ( $error = $this->security->$validator( $submitted_data ) ) {
                return $this->error_response( $error['type'], [], $error['message'] );
            }
        }

        $field_map = eform_get_template_fields( $template );

        $form_id    = $this->get_first_value( $submitted_data['enhanced_form_id'] ?? '' );
        $form_scope = [];
        if ( $form_id && isset( $submitted_data[ $form_id ] ) && is_array( $submitted_data[ $form_id ] ) ) {
            $form_scope = $submitted_data[ $form_id ];
        }

        $field_list = $this->get_first_value( $form_scope['enhanced_fields'] ?? '' );
        if ( ! empty( $field_list ) ) {
            $keys      = array_filter( array_map( 'sanitize_key', explode( ',', $field_list ) ) );
            $field_map = array_intersect_key( $field_map, array_flip( $keys ) );
        }

        
        $normalized = $this->validator->normalize_submission( $field_map, $form_scope, $this->array_field_types );
        $data       = $normalized['data'];
        if ( ! empty( $normalized['invalid_fields'] ) ) {
            $details  = [ 'invalid_fields' => $normalized['invalid_fields'] ];
            $user_msg = 'Invalid array input for field(s): ' . implode( ', ', $normalized['invalid_fields'] ) . '.';
            return $this->error_response( 'Invalid form input', $details, $user_msg );
        }

        $validated = $this->validator->validate_submission( $field_map, $data, $this->array_field_types );
        $data      = $validated['data'];
        if ( ! empty( $validated['errors'] ) ) {
            $details = [
                'errors'    => $validated['errors'],
                'form_data' => $data,
            ];
            $user_msg = 'Please correct the highlighted fields';
            return $this->error_response( 'Validation errors', $details, $user_msg );
        }

        $data = $this->validator->coerce_submission( $field_map, $data );

        if ( ! $this->emailer->dispatch_email( $data ) ) {
            $details = [ 'form_data' => $data ];
            return $this->error_response( 'Email Sending Failure', $details, 'Something went wrong. Please try again later.' );
        }

        $this->log_success( $template, $data );

        $config  = eform_get_template_config( $template );
        $success = $config['success'] ?? [];
        $mode    = isset( $success['mode'] ) ? sanitize_key( $success['mode'] ) : 'inline';
        $resp    = [ 'success' => [ 'mode' => $mode ] ];

        if ( ! empty( $success['redirect_url'] ) ) {
            $resp['success']['redirect_url'] = $success['redirect_url'];
        }

        return $resp;
    }

    public function format_phone(string $digits): string {
        if (preg_match('/^(\\d{3})(\\d{3})(\\d{4})$/', $digits, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }
        return $digits;
    }

    private function log_success( string $template, array $data ): void {
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
        if ( $this->logger ) {
            $this->logger->log( 'Form submission sent', Logging::LEVEL_INFO, [ 'form_data' => $safe_data, 'template' => $template ] );
        }
    }

    private function log_and_message(string $type, array $details = [], string $user_msg = ''): string {
        $form_data = $details['form_data'] ?? null;
        if (isset($details['form_data'])) {
            unset($details['form_data']);
        }
        if ( $this->logger ) {
            $this->logger->log($type, Logging::LEVEL_ERROR, [
                'type'    => $type,
                'details' => $details,
            ], $form_data);
        }

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

