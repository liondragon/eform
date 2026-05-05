<?php
/**
 * Success handling for PRG (Post-Redirect-Get) flow.
 *
 * Spec: Success behavior (docs/Canonical_Spec.md#sec-success)
 * Spec: Success modes (docs/Canonical_Spec.md#sec-success-modes)
 * Spec: Result page success flow (docs/Canonical_Spec.md#sec-success-flow)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 */

if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}

class Success {
    const RESULT_PARAM = 'eforms_result';
    const FORM_PARAM = 'eforms_form';
    const RESULT_SUCCESS = 'success';
    const RESULT_EMAIL_FAILURE = 'email_failure';

    private static $logged_cache_warning = false;

    /**
     * Execute PRG redirect after successful submission.
     *
     * Spec: PRG status is fixed at 303. Success responses MUST satisfy cache-safety.
     * Spec: Redirects via wp_safe_redirect to the plugin-owned result page.
     *
     * @param array $context Template context with success configuration.
     * @param array $options Optional overrides for testing.
     * @return array Result with ok, status, location.
     */
    public static function redirect( $context, $options = array() ) {
        $form_id = self::get_form_id( $context );

        return self::redirect_result( self::RESULT_SUCCESS, $form_id, $options );
    }

    /**
     * Execute email-failure redirect to the plugin-owned result page.
     *
     * @param string $form_id Form identifier.
     * @param array $options Optional overrides for testing.
     * @return array Result with ok, status, location.
     */
    public static function redirect_email_failure( $form_id, $options = array() ) {
        return self::redirect_result( self::RESULT_EMAIL_FAILURE, $form_id, $options );
    }

    /**
     * Get the success message from template context.
     *
     * @param array $context Template context.
     * @return string Success message (plain text, already escaped for HTML).
     */
    public static function get_message( $context ) {
        if ( ! is_array( $context ) || ! isset( $context['success'] ) || ! is_array( $context['success'] ) ) {
            return 'Thank you for your submission.';
        }

        $success = $context['success'];
        if ( isset( $success['message'] ) && is_string( $success['message'] ) && $success['message'] !== '' ) {
            return $success['message'];
        }

        return 'Thank you for your submission.';
    }

    public static function redirect_result( $result_type, $form_id, $options = array() ) {
        if ( ! self::is_result_type( $result_type ) ) {
            return self::fail( 'invalid_result' );
        }

        if ( ! is_string( $form_id ) || $form_id === '' ) {
            return self::fail( 'no_form_id' );
        }

        $current_url = self::get_current_url( $options );
        if ( $current_url === '' ) {
            return self::fail( 'no_current_url' );
        }

        $result_url = self::build_result_url( $current_url, $form_id, $result_type );
        return self::perform_redirect( $result_url, 303, $options );
    }

    public static function parse_result_request( $query = null ) {
        $source = is_array( $query ) ? $query : ( isset( $_GET ) && is_array( $_GET ) ? $_GET : array() );

        $result_type = isset( $source[ self::RESULT_PARAM ] ) && is_string( $source[ self::RESULT_PARAM ] )
            ? $source[ self::RESULT_PARAM ]
            : '';
        $form_id = isset( $source[ self::FORM_PARAM ] ) && is_string( $source[ self::FORM_PARAM ] )
            ? $source[ self::FORM_PARAM ]
            : '';

        if ( ! self::is_result_type( $result_type ) || $form_id === '' ) {
            return null;
        }

        return array(
            'result' => $result_type,
            'form_id' => $form_id,
        );
    }

    public static function is_result_type( $result_type ) {
        return $result_type === self::RESULT_SUCCESS || $result_type === self::RESULT_EMAIL_FAILURE;
    }

    public static function get_result_message( $result_type, $context ) {
        if ( $result_type === self::RESULT_EMAIL_FAILURE ) {
            if ( function_exists( 'eforms_error_message' ) ) {
                return eforms_error_message( 'EFORMS_ERR_EMAIL_SEND' );
            }

            return 'We couldn\'t send your request right now. Please try again in a few minutes.';
        }

        return self::get_message( $context );
    }

    /**
     * Emit cache-safety headers for success responses.
     *
     * Spec: Success responses MUST satisfy cache-safety (Cache-Control: private, no-store, max-age=0).
     *
     * @return bool True if headers were sent successfully.
     */
    public static function emit_cache_headers() {
        if ( headers_sent() ) {
            self::log_cache_header_warning_once();
            return false;
        }

        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }

        header( 'Cache-Control: private, no-store, max-age=0' );
        return true;
    }

    /**
     * Get form ID from context.
     *
     * @param array $context Template context.
     * @return string Form ID.
     */
    private static function get_form_id( $context ) {
        if ( is_array( $context ) && isset( $context['id'] ) && is_string( $context['id'] ) ) {
            return $context['id'];
        }

        return '';
    }

    /**
     * Get current request URL.
     *
     * @param array $options Optional overrides.
     * @return string Current URL.
     */
    private static function get_current_url( $options ) {
        if ( is_array( $options ) && isset( $options['current_url'] ) && is_string( $options['current_url'] ) ) {
            return $options['current_url'];
        }

        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return '';
        }

        $scheme = self::get_scheme();
        $host = self::get_host();
        if ( $host === '' ) {
            return '';
        }

        $uri = $_SERVER['REQUEST_URI'];
        if ( ! is_string( $uri ) ) {
            return '';
        }

        return $scheme . '://' . $host . $uri;
    }

    /**
     * Get current request scheme.
     *
     * @return string 'https' or 'http'.
     */
    private static function get_scheme() {
        if ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) {
            return 'https';
        }

        if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) {
            return 'https';
        }

        return 'http';
    }

    /**
     * Get current request host.
     *
     * @return string Host or empty string.
     */
    private static function get_host() {
        if ( isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ) {
            return $_SERVER['HTTP_HOST'];
        }

        if ( isset( $_SERVER['SERVER_NAME'] ) && is_string( $_SERVER['SERVER_NAME'] ) ) {
            return $_SERVER['SERVER_NAME'];
        }

        return '';
    }

    /**
     * Build plugin-owned result URL with query parameters.
     *
     * @param string $base_url Current URL.
     * @param string $form_id Form identifier.
     * @param string $result_type Result page type.
     * @return string URL with result query parameters.
     */
    private static function build_result_url( $base_url, $form_id, $result_type ) {
        $parts = parse_url( $base_url );
        if ( ! is_array( $parts ) ) {
            return $base_url;
        }

        $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
        $host = isset( $parts['host'] ) ? $parts['host'] : '';
        $port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
        $path = isset( $parts['path'] ) ? $parts['path'] : '/';
        $query = isset( $parts['query'] ) ? $parts['query'] : '';

        parse_str( $query, $params );

        unset( $params['eforms_email_retry'] );
        unset( $params['eforms_success'] );
        unset( $params[ self::RESULT_PARAM ] );
        unset( $params[ self::FORM_PARAM ] );

        $params[ self::RESULT_PARAM ] = $result_type;
        $params[ self::FORM_PARAM ] = $form_id;

        $new_query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

        $url = $scheme . '://' . $host . $port . $path;
        if ( $new_query !== '' ) {
            $url .= '?' . $new_query;
        }

        return $url;
    }

    /**
     * Log cache header warning once per request.
     */
    private static function log_cache_header_warning_once() {
        if ( self::$logged_cache_warning ) {
            return;
        }

        self::$logged_cache_warning = true;

        if ( class_exists( 'Logging' ) && method_exists( 'Logging', 'event' ) ) {
            Logging::event( 'warning', 'EFORMS_ERR_STORAGE_UNAVAILABLE', array( 'reason' => 'headers_sent', 'context' => 'success_cache_headers' ) );
        }
    }

    /**
     * Perform the redirect.
     *
     * @param string $url Target URL.
     * @param int $status HTTP status code (303).
     * @param array $options Optional overrides.
     * @return array Result with ok, status, location.
     */
    private static function perform_redirect( $url, $status, $options ) {
        $dry_run = is_array( $options ) && ! empty( $options['dry_run'] );

        self::emit_cache_headers();

        if ( $dry_run ) {
            return array(
                'ok' => true,
                'status' => $status,
                'location' => $url,
                'dry_run' => true,
            );
        }

        if ( function_exists( 'wp_safe_redirect' ) ) {
            wp_safe_redirect( $url, $status );
            return array(
                'ok' => true,
                'status' => $status,
                'location' => $url,
            );
        }

        if ( ! headers_sent() ) {
            header( 'Location: ' . $url, true, $status );
            return array(
                'ok' => true,
                'status' => $status,
                'location' => $url,
            );
        }

        return self::fail( 'headers_sent' );
    }

    /**
     * Return failure result.
     *
     * @param string $reason Failure reason.
     * @return array Failure result.
     */
    private static function fail( $reason ) {
        return array(
            'ok' => false,
            'reason' => $reason,
        );
    }

    /**
     * Escape HTML entities.
     *
     * @param string $value Value to escape.
     * @return string Escaped value.
     */
    private static function escape_html( $value ) {
        if ( function_exists( 'esc_html' ) ) {
            return esc_html( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}
