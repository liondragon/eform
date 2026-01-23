<?php
/**
 * Success handling for PRG (Post-Redirect-Get) flow.
 *
 * Spec: Success behavior (docs/Canonical_Spec.md#sec-success)
 * Spec: Success modes (docs/Canonical_Spec.md#sec-success-modes)
 * Spec: Inline success flow (docs/Canonical_Spec.md#sec-success-flow)
 * Spec: Redirect safety (docs/Canonical_Spec.md#sec-redirect-safety)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 */

if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}

class Success {
    private static $logged_cache_warning = false;

    /**
     * Execute PRG redirect after successful submission.
     *
     * Spec: PRG status is fixed at 303. Success responses MUST satisfy cache-safety.
     * Spec: Redirects via wp_safe_redirect; same-origin only.
     *
     * @param array $context Template context with success configuration.
     * @param array $options Optional overrides for testing.
     * @return array Result with ok, status, location.
     */
    public static function redirect( $context, $options = array() ) {
        $mode = self::get_mode( $context );
        $form_id = self::get_form_id( $context );

        if ( $mode === 'inline' ) {
            return self::redirect_inline( $form_id, $options );
        }

        if ( $mode === 'redirect' ) {
            $redirect_url = self::get_redirect_url( $context );
            return self::redirect_external( $redirect_url, $options );
        }

        return self::redirect_inline( $form_id, $options );
    }

    /**
     * Execute inline success redirect (back to same URL with query param).
     *
     * Spec: On successful POST, redirect with ?eforms_success={form_id} (303).
     *
     * @param string $form_id Form identifier.
     * @param array $options Optional overrides for testing.
     * @return array Result with ok, status, location.
     */
    public static function redirect_inline( $form_id, $options = array() ) {
        $current_url = self::get_current_url( $options );
        if ( $current_url === '' ) {
            return self::fail( 'no_current_url' );
        }

        $success_url = self::build_inline_success_url( $current_url, $form_id );
        return self::perform_redirect( $success_url, 303, $options );
    }

    /**
     * Execute redirect success (to configured URL).
     *
     * Spec: wp_safe_redirect(redirect_url, 303). Destination renders its own success UX.
     *
     * @param string $redirect_url Target URL.
     * @param array $options Optional overrides for testing.
     * @return array Result with ok, status, location.
     */
    public static function redirect_external( $redirect_url, $options = array() ) {
        if ( ! is_string( $redirect_url ) || $redirect_url === '' ) {
            return self::fail( 'no_redirect_url' );
        }

        if ( ! self::is_same_origin( $redirect_url, $options ) ) {
            return self::fail( 'cross_origin' );
        }

        return self::perform_redirect( $redirect_url, 303, $options );
    }

    /**
     * Check if current request is an inline success display request.
     *
     * @param string $form_id Form identifier to check.
     * @return bool True if ?eforms_success={form_id} is present.
     */
    public static function is_inline_success_request( $form_id ) {
        if ( ! isset( $_GET['eforms_success'] ) ) {
            return false;
        }

        $param = $_GET['eforms_success'];
        if ( ! is_string( $param ) ) {
            return false;
        }

        return $param === $form_id;
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

    /**
     * Render the inline success banner HTML.
     *
     * @param array $context Template context.
     * @return string HTML for success banner.
     */
    public static function render_banner( $context ) {
        $message = self::get_message( $context );
        $escaped = self::escape_html( $message );

        return '<div class="eforms-success-banner" role="status" aria-live="polite">' . $escaped . '</div>';
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
     * Get success mode from context.
     *
     * @param array $context Template context.
     * @return string Mode: 'inline' or 'redirect'.
     */
    private static function get_mode( $context ) {
        if ( ! is_array( $context ) || ! isset( $context['success'] ) || ! is_array( $context['success'] ) ) {
            return 'inline';
        }

        $success = $context['success'];
        if ( isset( $success['mode'] ) && is_string( $success['mode'] ) ) {
            $mode = strtolower( trim( $success['mode'] ) );
            if ( $mode === 'redirect' ) {
                return 'redirect';
            }
        }

        return 'inline';
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
     * Get redirect URL from context.
     *
     * @param array $context Template context.
     * @return string Redirect URL.
     */
    private static function get_redirect_url( $context ) {
        if ( ! is_array( $context ) || ! isset( $context['success'] ) || ! is_array( $context['success'] ) ) {
            return '';
        }

        $success = $context['success'];
        if ( isset( $success['redirect_url'] ) && is_string( $success['redirect_url'] ) ) {
            return $success['redirect_url'];
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
     * Build inline success URL with query parameter.
     *
     * @param string $base_url Current URL.
     * @param string $form_id Form identifier.
     * @return string URL with ?eforms_success={form_id}.
     */
    private static function build_inline_success_url( $base_url, $form_id ) {
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

        $params['eforms_success'] = $form_id;

        $new_query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

        $url = $scheme . '://' . $host . $port . $path;
        if ( $new_query !== '' ) {
            $url .= '?' . $new_query;
        }

        return $url;
    }

    /**
     * Check if URL is same-origin.
     *
     * Spec: wp_safe_redirect; same-origin only (scheme/host/port).
     *
     * @param string $url URL to check.
     * @param array $options Optional overrides.
     * @return bool True if same-origin.
     */
    private static function is_same_origin( $url, $options = array() ) {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }

        $has_host = isset( $parts['host'] );
        $has_scheme = isset( $parts['scheme'] );
        $has_port = isset( $parts['port'] );

        // Educational note: only relative URLs are accepted without a host.
        if ( ! $has_host ) {
            return ! $has_scheme && ! $has_port;
        }

        $current_host = self::get_host();
        if ( $current_host === '' && is_array( $options ) && isset( $options['host'] ) ) {
            $current_host = $options['host'];
        }

        if ( $current_host === '' ) {
            return false;
        }

        $target_host = strtolower( $parts['host'] );
        $current_host_lower = strtolower( $current_host );

        $target_host_only = self::strip_port( $target_host );
        $current_host_only = self::strip_port( $current_host_lower );

        if ( $target_host_only !== $current_host_only ) {
            return false;
        }

        $current_scheme = self::get_scheme();

        if ( is_array( $options ) && isset( $options['scheme'] ) ) {
            $current_scheme = $options['scheme'];
        }

        $current_scheme = strtolower( $current_scheme );
        $target_scheme = $has_scheme ? strtolower( $parts['scheme'] ) : $current_scheme;

        if ( $target_scheme !== $current_scheme ) {
            return false;
        }

        $current_port = self::get_current_port( $current_scheme, $current_host, $options );
        $target_port = self::get_target_port( $parts, $target_scheme );

        return $current_port === $target_port;
    }

    /**
     * Strip port from host string.
     *
     * @param string $host Host possibly with port.
     * @return string Host without port.
     */
    private static function strip_port( $host ) {
        $pos = strpos( $host, ':' );
        if ( $pos !== false ) {
            return substr( $host, 0, $pos );
        }
        return $host;
    }

    /**
     * Resolve current request port.
     *
     * @param string $scheme Current scheme.
     * @param string $host Current host (possibly with port).
     * @param array $options Optional overrides.
     * @return int Current port.
     */
    private static function get_current_port( $scheme, $host, $options ) {
        if ( is_array( $options ) && isset( $options['port'] ) && is_numeric( $options['port'] ) ) {
            return (int) $options['port'];
        }

        $host_port = self::port_from_host( $host );
        if ( $host_port !== null ) {
            return $host_port;
        }

        if ( isset( $_SERVER['SERVER_PORT'] ) && is_numeric( $_SERVER['SERVER_PORT'] ) ) {
            return (int) $_SERVER['SERVER_PORT'];
        }

        return self::default_port( $scheme );
    }

    /**
     * Resolve target port from URL parts.
     *
     * @param array $parts Parsed URL.
     * @param string $scheme Target scheme.
     * @return int Target port.
     */
    private static function get_target_port( $parts, $scheme ) {
        if ( isset( $parts['port'] ) && is_numeric( $parts['port'] ) ) {
            return (int) $parts['port'];
        }

        return self::default_port( $scheme );
    }

    /**
     * Extract port from host when provided as host:port.
     *
     * @param string $host Host string.
     * @return int|null Port if present.
     */
    private static function port_from_host( $host ) {
        if ( ! is_string( $host ) || $host === '' ) {
            return null;
        }

        if ( substr_count( $host, ':' ) !== 1 ) {
            return null;
        }

        if ( preg_match( '/:(\\d+)$/', $host, $matches ) ) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Default ports for HTTP schemes.
     *
     * @param string $scheme Scheme name.
     * @return int Default port.
     */
    private static function default_port( $scheme ) {
        return strtolower( $scheme ) === 'https' ? 443 : 80;
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
