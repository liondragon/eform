<?php
// includes/Security.php

class Security {

    private function build_error(string $type, string $message): array {
        return [
            'type'    => $type,
            'message' => $message,
        ];
    }

    public function check_nonce(array $submitted_data): array {
        $nonce = Helpers::get_first_value( $submitted_data['enhanced_icf_form_nonce'] ?? '' );
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'enhanced_icf_form_action' ) ) {
            return $this->build_error('Nonce Failed', 'Invalid submission detected.');
        }
        return [];
    }

    public function check_honeypot(array $submitted_data): array {
        $honeypot_field = $submitted_data['enhanced_url'] ?? '';
        if ( is_array( $honeypot_field ) ) {
            return $this->build_error('Bot Alert: Honeypot Filled', 'Bot test failed.');
        }
        $honeypot = Helpers::get_first_value( $honeypot_field );
        if ( ! empty( $honeypot ) ) {
            return $this->build_error('Bot Alert: Honeypot Filled', 'Bot test failed.');
        }
        return [];
    }

    public function check_submission_time(array $submitted_data): array {
        $submit_time_field = $submitted_data['enhanced_form_time'] ?? 0;
        if ( is_array( $submit_time_field ) ) {
            return $this->build_error('Bot Alert: Fast Submission', 'Submission too fast. Please try again.');
        }
        $submit_time = intval( Helpers::get_first_value( $submit_time_field ) );
        if ( time() - $submit_time < 5 ) {
            return $this->build_error('Bot Alert: Fast Submission', 'Submission too fast. Please try again.');
        }
        return [];
    }

    public function check_js_enabled(array $submitted_data): array {
        $js_check = Helpers::get_first_value( $submitted_data['enhanced_js_check'] ?? '' );
        if ( empty( $js_check ) ) {
            return $this->build_error('Bot Alert: JS Check Missing', 'JavaScript must be enabled.');
        }
        return [];
    }
}
