<?php
// includes/Security.php

class Security {
    private int $score = 0;
    private array $signals = [];
    private int $soft_threshold;

    public function __construct(?int $soft_threshold = null) {
        $this->soft_threshold = $soft_threshold ?? ( defined('EFORMS_SOFT_FAIL_THRESHOLD') ? (int) EFORMS_SOFT_FAIL_THRESHOLD : 3 );
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
        $ttl         = defined( 'EFORMS_NONCE_LIFETIME' ) ? (int) EFORMS_NONCE_LIFETIME : 86400;
        if ( empty( $nonce ) || empty( $form_id ) || empty( $instance_id ) || ! wp_verify_nonce( $nonce, $action, $ttl ) ) {
            $this->record_signal( 'nonce_ok', false );
            return $this->build_error( 'Nonce Failed', 'Invalid submission detected.' );
        }
        $this->record_signal( 'nonce_ok', true );
        return [];
    }

    public function check_honeypot(array $submitted_data): array {
        $honeypot_field = $submitted_data['eforms_hp'] ?? '';
        if ( is_array( $honeypot_field ) ) {
            $this->record_signal( 'honeypot_empty', false );
            return $this->build_error( 'Bot Alert: Honeypot Filled', 'Bot test failed.' );
        }
        $honeypot = Helpers::get_first_value( $honeypot_field );
        if ( ! empty( $honeypot ) ) {
            $this->record_signal( 'honeypot_empty', false );
            return $this->build_error( 'Bot Alert: Honeypot Filled', 'Bot test failed.' );
        }
        $this->record_signal( 'honeypot_empty', true );
        return [];
    }

    public function check_submission_time(array $submitted_data): array {
        $submit_time_field = $submitted_data['timestamp'] ?? 0;
        if ( is_array( $submit_time_field ) ) {
            $this->record_signal( 'fill_time_ok', false );
            return $this->build_error('Bot Alert: Fast Submission', 'Submission too fast. Please try again.');
        }
        $submit_time  = intval( Helpers::get_first_value( $submit_time_field ) );
        $current_time = time();
        $min_time     = defined( 'EFORMS_MIN_FILL_TIME' ) ? (int) EFORMS_MIN_FILL_TIME : 5;
        if ( $current_time - $submit_time < $min_time ) {
            $this->record_signal( 'fill_time_ok', false );
            return $this->build_error('Bot Alert: Fast Submission', 'Submission too fast. Please try again.');
        }
        $max_time = defined( 'EFORMS_MAX_FILL_TIME' ) ? (int) EFORMS_MAX_FILL_TIME : 86400;
        if ( $current_time - $submit_time > $max_time ) {
            $this->record_signal( 'fill_time_ok', false );
            return $this->build_error('Form Expired', 'Form has expired. Please refresh and try again.');
        }
        $this->record_signal( 'fill_time_ok', true );
        return [];
    }

    public function check_js_enabled(array $submitted_data, string $mode = 'hard'): array {
        $js_check = Helpers::get_first_value( $submitted_data['js_ok'] ?? '' );
        if ( 'soft' === $mode ) {
            if ( empty( $js_check ) || $js_check !== '1' ) {
                $this->record_signal( 'js_ok', false, 1 );
            } else {
                $this->record_signal( 'js_ok', true );
            }
            return [];
        }
        if ( empty( $js_check ) || $js_check !== '1' ) {
            $this->record_signal( 'js_ok', false );
            return $this->build_error('Bot Alert: JS Check Missing', 'JavaScript must be enabled.');
        }
        $this->record_signal( 'js_ok', true );
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

    public function check_post_size( ?array $server = null ): array {
        $server    = $server ?? $_SERVER;
        $max_bytes = defined( 'EFORMS_MAX_POST_BYTES' ) ? (int) EFORMS_MAX_POST_BYTES : PHP_INT_MAX;
        $size      = isset( $server['CONTENT_LENGTH'] ) ? (int) $server['CONTENT_LENGTH'] : 0;
        if ( $size > $max_bytes ) {
            $this->record_signal( 'post_size_ok', false, 1 );
            return $this->build_error( 'POST Size Exceeded', 'Submission too large.' );
        }
        $this->record_signal( 'post_size_ok', true );
        return [];
    }

    public function check_referrer( ?array $server = null, ?string $policy = null ): array {
        $server = $server ?? $_SERVER;
        $ref    = $this->normalize_referrer( $server['HTTP_REFERER'] ?? '' );
        $this->signals['referrer'] = $ref;

        $policy = $policy !== null ? sanitize_key( $policy ) : ( defined( 'EFORMS_REFERRER_POLICY' ) ? sanitize_key( EFORMS_REFERRER_POLICY ) : 'off' );
        $this->signals['referrer_policy'] = $policy;

        if ( 'off' === $policy ) {
            $this->record_signal( 'referrer_ok', true );
            return [];
        }

        $host     = isset( $server['HTTP_HOST'] ) ? strtolower( $server['HTTP_HOST'] ) : '';
        $ref_host = $ref ? strtolower( parse_url( $ref, PHP_URL_HOST ) ?? '' ) : '';
        $ref_path = $ref ? ( parse_url( $ref, PHP_URL_PATH ) ?? '' ) : '';
        $req_path = isset( $server['REQUEST_URI'] ) ? ( parse_url( $server['REQUEST_URI'], PHP_URL_PATH ) ?? '' ) : '';

        $same_host = ( '' !== $ref && $host === $ref_host );
        $same_path = ( $same_host && $ref_path === $req_path );

        if ( 'soft' === $policy ) {
            $ok = '' !== $ref;
            $this->record_signal( 'referrer_ok', $ok, $ok ? 0 : 1 );
            return [];
        }

        if ( 'soft_path' === $policy ) {
            $ok = $same_path;
            $this->record_signal( 'referrer_ok', $ok, $ok ? 0 : 1 );
            return [];
        }

        // hard
        $ok = $same_path;
        $this->record_signal( 'referrer_ok', $ok, $ok ? 0 : 1 );
        if ( ! $ok ) {
            return $this->build_error( 'Referrer Check Failed', 'Invalid submission detected.' );
        }
        return [];
    }

    public function get_signals( ?array $server = null ): array {
        $server = $server ?? $_SERVER;
        $ua     = $this->normalize_user_agent( $server['HTTP_USER_AGENT'] ?? '' );
        $this->signals['user_agent'] = $ua;
        if ( '' === $ua ) {
            $this->record_signal( 'ua_missing', true, 1 );
        } else {
            $this->record_signal( 'ua_missing', false );
        }

        if ( ! isset( $this->signals['referrer'] ) ) {
            $this->signals['referrer'] = $this->normalize_referrer( $server['HTTP_REFERER'] ?? '' );
        }

        return [
            'score'     => $this->score,
            'threshold' => $this->soft_threshold,
            'soft_fail' => $this->score >= $this->soft_threshold,
            'signals'   => $this->signals,
        ];
    }
}
