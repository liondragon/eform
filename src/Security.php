<?php
// includes/Security.php

class Security {
    private int $score = 0;
    private array $signals = [];
    private int $soft_threshold;

    public function __construct(?int $soft_threshold = null) {
        $this->soft_threshold = $soft_threshold ?? ( defined('EFORMS_SECURITY_SOFT_FAIL_THRESHOLD') ? (int) EFORMS_SECURITY_SOFT_FAIL_THRESHOLD : 3 );
    }

    private function record_signal(string $key, $value, int $increment = 0): void {
        $this->signals[$key] = $value;
        $this->score += $increment;
    }

    private function build_error(string $type, string $message): array {
        return [
            'type'    => $type,
            'message' => $message,
        ];
    }

    public function check_nonce(array $submitted_data): array {
        $nonce       = Helpers::get_first_value( $submitted_data['_wpnonce'] ?? '' );
        $form_id     = Helpers::get_first_value( $submitted_data['form_id'] ?? '' );
        $instance_id = Helpers::get_first_value( $submitted_data['instance_id'] ?? '' );
        $action      = "eforms_form_{$form_id}:{$instance_id}";
        if ( empty( $nonce ) || empty( $form_id ) || empty( $instance_id ) || ! wp_verify_nonce( $nonce, $action ) ) {
            $this->record_signal('nonce', 'fail');
            return $this->build_error('Nonce Failed', 'Invalid submission detected.');
        }
        $this->record_signal('nonce', 'pass');
        return [];
    }

    public function check_honeypot(array $submitted_data): array {
        $honeypot_field = $submitted_data['eforms_hp'] ?? '';
        if ( is_array( $honeypot_field ) ) {
            $this->record_signal('honeypot', 'fail');
            return $this->build_error('Bot Alert: Honeypot Filled', 'Bot test failed.');
        }
        $honeypot = Helpers::get_first_value( $honeypot_field );
        if ( ! empty( $honeypot ) ) {
            $this->record_signal('honeypot', 'fail');
            return $this->build_error('Bot Alert: Honeypot Filled', 'Bot test failed.');
        }
        $this->record_signal('honeypot', 'pass');
        return [];
    }

    public function check_submission_time(array $submitted_data): array {
        $submit_time_field = $submitted_data['timestamp'] ?? 0;
        if ( is_array( $submit_time_field ) ) {
            $this->record_signal('submission_time', 'array');
            return $this->build_error('Bot Alert: Fast Submission', 'Submission too fast. Please try again.');
        }
        $submit_time  = intval( Helpers::get_first_value( $submit_time_field ) );
        $current_time = time();
        if ( $current_time - $submit_time < 5 ) {
            $this->record_signal('submission_time', 'fast');
            return $this->build_error('Bot Alert: Fast Submission', 'Submission too fast. Please try again.');
        }
        $max_age = defined( 'EFORM_MAX_FORM_AGE' ) ? (int) EFORM_MAX_FORM_AGE : 86400;
        if ( $current_time - $submit_time > $max_age ) {
            $this->record_signal('submission_time', 'expired');
            return $this->build_error('Form Expired', 'Form has expired. Please refresh and try again.');
        }
        $this->record_signal('submission_time', 'pass');
        return [];
    }

    public function check_js_enabled(array $submitted_data, string $mode = 'hard'): array {
        $js_check = Helpers::get_first_value( $submitted_data['js_ok'] ?? '' );
        if ( 'soft' === $mode ) {
            if ( empty( $js_check ) || $js_check !== '1' ) {
                $this->record_signal('js', 'missing', 1);
            } else {
                $this->record_signal('js', 'pass');
            }
            return [];
        }
        if ( empty( $js_check ) || $js_check !== '1' ) {
            $this->record_signal('js', 'fail');
            return $this->build_error('Bot Alert: JS Check Missing', 'JavaScript must be enabled.');
        }
        $this->record_signal('js', 'pass');
        return [];
    }

    private function normalize_user_agent(string $ua): string {
        $ua = sanitize_text_field( $ua );
        return substr( $ua, 0, 255 );
    }

    private function normalize_referrer(string $ref): string {
        $ref = esc_url_raw( $ref );
        return substr( $ref, 0, 2000 );
    }

    public function get_signals(?array $server = null, ?string $policy = null): array {
        $server = $server ?? $_SERVER;
        $ua = $this->normalize_user_agent( $server['HTTP_USER_AGENT'] ?? '' );
        $this->signals['user_agent'] = $ua;
        if ( '' === $ua ) {
            $this->record_signal('ua_status', 'missing', 1);
        }

        $referrer = $this->normalize_referrer( $server['HTTP_REFERER'] ?? '' );
        $this->signals['referrer'] = $referrer;

        $policy = $policy !== null ? sanitize_key( $policy ) : ( defined('EFORMS_SECURITY_REFERRER_POLICY') ? sanitize_key( EFORMS_SECURITY_REFERRER_POLICY ) : 'none' );
        $this->signals['referrer_policy'] = $policy;

        if ( 'require' === $policy && '' === $referrer ) {
            $this->record_signal('referrer_status', 'missing', 1);
        } elseif ( 'sameorigin' === $policy ) {
            $host = isset( $server['HTTP_HOST'] ) ? strtolower( $server['HTTP_HOST'] ) : '';
            $ref_host = $referrer ? strtolower( parse_url( $referrer, PHP_URL_HOST ) ?? '' ) : '';
            if ( $host !== $ref_host ) {
                $this->record_signal('referrer_status', 'mismatch', 1);
            }
        }

        return [
            'score'     => $this->score,
            'threshold' => $this->soft_threshold,
            'soft_fail' => $this->score >= $this->soft_threshold,
            'signals'   => $this->signals,
        ];
    }
}
