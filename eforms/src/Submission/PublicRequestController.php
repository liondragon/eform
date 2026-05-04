<?php
/**
 * WordPress-facing public POST controller.
 *
 * Spec: Request lifecycle POST (docs/Canonical_Spec.md#sec-request-lifecycle-post)
 * Spec: Success behavior (docs/Canonical_Spec.md#sec-success)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 */

require_once __DIR__ . '/../Errors.php';
require_once __DIR__ . '/../Rendering/FormRenderer.php';
require_once __DIR__ . '/SubmitHandler.php';

class PublicRequestController {
    private static $captured_response = null;

    /**
     * WordPress template_redirect callback.
     *
     * @return array Controller response metadata.
     */
    public static function handle_template_redirect() {
        $response = self::dispatch_current_request();
        if ( ! empty( $response['handled'] ) ) {
            self::capture_response( $response );
        }

        return $response;
    }

    /**
     * Dispatch the current request if it is an eForms public POST.
     *
     * @return array Controller response metadata.
     */
    public static function dispatch_current_request() {
        if ( self::request_method() !== 'POST' ) {
            return self::not_handled();
        }

        $post = self::post_payload();
        if ( ! self::looks_like_eforms_post( $post ) ) {
            return self::not_handled();
        }

        $form_id = self::extract_form_id( $post );
        $request = array(
            'post' => $post,
            'files' => self::files_payload(),
            'headers' => self::headers_payload(),
            'content_length' => self::content_length(),
        );

        $result = SubmitHandler::handle( $form_id, $request );
        if ( ! empty( $result['ok'] ) ) {
            return self::success_response( $result );
        }

        return self::failure_response( $form_id, $result );
    }

    /**
     * Template include callback for handled public POST responses.
     *
     * @param string $template Existing WordPress template path.
     * @return string Internal response template path.
     */
    public static function template_include( $template ) {
        if ( self::$captured_response === null ) {
            return $template;
        }

        return __DIR__ . '/public-response-template.php';
    }

    /**
     * Echo the captured response body from the internal response template.
     */
    public static function render_captured_response() {
        if ( self::$captured_response === null ) {
            return;
        }

        $body = isset( self::$captured_response['body'] ) && is_string( self::$captured_response['body'] )
            ? self::$captured_response['body']
            : '';
        echo $body;
    }

    /**
     * Test helper.
     */
    public static function last_response() {
        return self::$captured_response;
    }

    /**
     * Test helper.
     */
    public static function reset_for_tests() {
        self::$captured_response = null;
    }

    private static function success_response( $result ) {
        $redirect = SubmitHandler::do_success_redirect(
            $result,
            array(
                'current_url' => self::current_url(),
            )
        );

        if ( ! is_array( $redirect ) || empty( $redirect['ok'] ) ) {
            return self::error_response( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 500 );
        }

        return array(
            'handled' => true,
            'status' => isset( $redirect['status'] ) ? (int) $redirect['status'] : 303,
            'location' => isset( $redirect['location'] ) && is_string( $redirect['location'] ) ? $redirect['location'] : '',
            'body' => '',
            'result' => $result,
        );
    }

    private static function failure_response( $form_id, $result ) {
        $status = self::result_status( $result );
        $form_id = is_string( $form_id ) ? $form_id : '';
        self::emit_result_headers( $result );

        if ( self::can_rerender( $form_id, $result ) ) {
            self::send_status( $status );
            $body = FormRenderer::render(
                $form_id,
                array(
                    'cacheable' => false,
                    'security' => isset( $result['security'] ) ? $result['security'] : array(),
                    'errors' => isset( $result['errors'] ) ? $result['errors'] : null,
                    'values' => isset( $result['values'] ) ? $result['values'] : array(),
                    'require_challenge' => ! empty( $result['require_challenge'] ),
                    'email_retry' => ! empty( $result['email_retry'] ),
                    'email_failure_summary' => isset( $result['email_failure_summary'] ) ? $result['email_failure_summary'] : '',
                    'email_failure_remint' => ! empty( $result['email_failure_remint'] ),
                )
            );

            return array(
                'handled' => true,
                'status' => $status,
                'location' => '',
                'body' => $body,
                'result' => $result,
            );
        }

        $code = isset( $result['error_code'] ) && is_string( $result['error_code'] ) && $result['error_code'] !== ''
            ? $result['error_code']
            : 'EFORMS_ERR_STORAGE_UNAVAILABLE';

        return self::error_response( $code, $status, $result );
    }

    private static function error_response( $code, $status, $result = null ) {
        self::send_status( $status );
        self::emit_cache_headers();

        if ( function_exists( 'eforms_render_error' ) ) {
            $body = eforms_render_error( $code );
        } else {
            $body = '<div class="eforms-error" data-eforms-error="' . self::escape_attr( $code ) . '">Form configuration error.</div>';
        }

        return array(
            'handled' => true,
            'status' => (int) $status,
            'location' => '',
            'body' => $body,
            'result' => $result,
        );
    }

    private static function can_rerender( $form_id, $result ) {
        if ( ! is_string( $form_id ) || $form_id === '' || ! is_array( $result ) ) {
            return false;
        }

        return isset( $result['security'] ) && is_array( $result['security'] );
    }

    private static function capture_response( $response ) {
        self::$captured_response = $response;
        if ( function_exists( 'add_filter' ) ) {
            add_filter( 'template_include', array( 'PublicRequestController', 'template_include' ), 0 );
        }
    }

    private static function not_handled() {
        return array(
            'handled' => false,
            'status' => 0,
            'location' => '',
            'body' => '',
            'result' => null,
        );
    }

    private static function looks_like_eforms_post( $post ) {
        if ( ! is_array( $post ) ) {
            return false;
        }

        foreach ( array( 'eforms_token', 'instance_id', 'eforms_mode', 'eforms_hp' ) as $key ) {
            if ( array_key_exists( $key, $post ) ) {
                return true;
            }
        }

        return false;
    }

    private static function extract_form_id( $post ) {
        if ( ! is_array( $post ) ) {
            return '';
        }

        $candidates = array();
        $reserved = self::reserved_keys();

        foreach ( $post as $key => $value ) {
            if ( ! is_string( $key ) || $key === '' || isset( $reserved[ $key ] ) ) {
                continue;
            }

            if ( is_array( $value ) ) {
                $candidates[] = $key;
            }
        }

        if ( count( $candidates ) === 1 ) {
            return $candidates[0];
        }

        return '';
    }

    private static function request_method() {
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ) {
            return strtoupper( $_SERVER['REQUEST_METHOD'] );
        }

        return '';
    }

    private static function post_payload() {
        return isset( $_POST ) && is_array( $_POST ) ? $_POST : array();
    }

    private static function files_payload() {
        return isset( $_FILES ) && is_array( $_FILES ) ? $_FILES : array();
    }

    private static function headers_payload() {
        $headers = array();
        $map = array(
            'CONTENT_TYPE' => 'Content-Type',
            'HTTP_CONTENT_TYPE' => 'Content-Type',
            'HTTP_ORIGIN' => 'Origin',
            'HTTP_REFERER' => 'Referer',
            'HTTP_USER_AGENT' => 'User-Agent',
        );

        foreach ( $map as $server_key => $header_name ) {
            if ( isset( $_SERVER[ $server_key ] ) && is_string( $_SERVER[ $server_key ] ) && $_SERVER[ $server_key ] !== '' ) {
                $headers[ $header_name ] = $_SERVER[ $server_key ];
            }
        }

        return $headers;
    }

    private static function content_length() {
        if ( isset( $_SERVER['CONTENT_LENGTH'] ) && is_numeric( $_SERVER['CONTENT_LENGTH'] ) ) {
            return (int) $_SERVER['CONTENT_LENGTH'];
        }

        if ( isset( $_SERVER['HTTP_CONTENT_LENGTH'] ) && is_numeric( $_SERVER['HTTP_CONTENT_LENGTH'] ) ) {
            return (int) $_SERVER['HTTP_CONTENT_LENGTH'];
        }

        return null;
    }

    private static function result_status( $result ) {
        if ( is_array( $result ) && isset( $result['status'] ) && is_numeric( $result['status'] ) ) {
            return (int) $result['status'];
        }

        return 500;
    }

    private static function current_url() {
        $scheme = 'http';
        if ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) {
            $scheme = 'https';
        } elseif ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) {
            $scheme = 'https';
        }

        $host = '';
        if ( isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ) {
            $host = $_SERVER['HTTP_HOST'];
        } elseif ( isset( $_SERVER['SERVER_NAME'] ) && is_string( $_SERVER['SERVER_NAME'] ) ) {
            $host = $_SERVER['SERVER_NAME'];
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
        if ( $host === '' ) {
            return '';
        }

        return $scheme . '://' . $host . $uri;
    }

    private static function send_status( $status ) {
        $status = (int) $status;
        if ( $status <= 0 ) {
            return;
        }

        if ( function_exists( 'status_header' ) ) {
            status_header( $status );
            return;
        }

        if ( ! headers_sent() ) {
            http_response_code( $status );
        }
    }

    private static function emit_cache_headers() {
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }

        if ( ! headers_sent() ) {
            header( 'Cache-Control: private, no-store, max-age=0' );
        }
    }

    private static function emit_result_headers( $result ) {
        if ( ! is_array( $result ) || ! isset( $result['headers'] ) || ! is_array( $result['headers'] ) ) {
            return;
        }

        if ( headers_sent() ) {
            return;
        }

        foreach ( $result['headers'] as $name => $value ) {
            if ( ! is_string( $name ) || $name === '' || ! is_scalar( $value ) ) {
                continue;
            }

            header( $name . ': ' . (string) $value );
        }
    }

    private static function reserved_keys() {
        return array(
            'form_id' => true,
            'instance_id' => true,
            'submission_id' => true,
            'eforms_token' => true,
            'eforms_hp' => true,
            'eforms_mode' => true,
            'timestamp' => true,
            'js_ok' => true,
            'eforms_email_retry' => true,
            'ip' => true,
            'submitted_at' => true,
        );
    }

    private static function escape_attr( $value ) {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}
