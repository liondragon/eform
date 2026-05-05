<?php
/**
 * WordPress-facing public request controller.
 *
 * Spec: Request lifecycle POST (docs/Canonical_Spec.md#sec-request-lifecycle-post)
 * Spec: Success behavior (docs/Canonical_Spec.md#sec-success)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 */

require_once __DIR__ . '/../Errors.php';
require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../Rendering/FormRenderer.php';
require_once __DIR__ . '/../Rendering/TemplateContext.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/SubmitHandler.php';

class PublicRequestController {
    private static $captured_response = null;
    private static $local_rerender = null;
    private static $result_page = null;

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
     * Dispatch the current request if it is an eForms public request.
     *
     * @return array Controller response metadata.
     */
    public static function dispatch_current_request() {
        if ( self::request_method() === 'GET' ) {
            return self::result_page_response();
        }

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
     * Template include callback for handled public responses.
     *
     * @param string $template Existing WordPress template path.
     * @return string Internal response template path.
     */
    public static function template_include( $template ) {
        if ( self::$captured_response === null ) {
            return $template;
        }

        if ( self::$result_page !== null ) {
            $result_template = isset( self::$result_page['template'] ) && is_string( self::$result_page['template'] )
                ? self::$result_page['template']
                : '';
            return $result_template !== '' ? $result_template : $template;
        }

        $render = isset( self::$captured_response['render'] ) && is_string( self::$captured_response['render'] )
            ? self::$captured_response['render']
            : '';
        if ( $render === 'redirect' ) {
            return __DIR__ . '/empty-response-template.php';
        }

        if ( $render === 'template' ) {
            return __DIR__ . '/public-response-template.php';
        }

        return $template;
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

    public static function local_rerender_options( $form_id ) {
        if ( self::$local_rerender === null || ! is_string( $form_id ) || $form_id === '' ) {
            return null;
        }

        $stored_form_id = isset( self::$local_rerender['form_id'] ) && is_string( self::$local_rerender['form_id'] )
            ? self::$local_rerender['form_id']
            : '';
        if ( $stored_form_id !== $form_id ) {
            return null;
        }

        return isset( self::$local_rerender['options'] ) && is_array( self::$local_rerender['options'] )
            ? self::$local_rerender['options']
            : null;
    }

    public static function result_page_context() {
        return self::$result_page !== null ? self::$result_page : array();
    }

    /**
     * Test helper.
     */
    public static function reset_for_tests() {
        self::$captured_response = null;
        self::$local_rerender = null;
        self::$result_page = null;
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
            'render' => 'redirect',
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

        if ( is_array( $result ) && ! empty( $result['email_failed'] ) ) {
            $redirect = Success::redirect_email_failure(
                $form_id,
                array(
                    'current_url' => self::current_url(),
                )
            );

            if ( ! is_array( $redirect ) || empty( $redirect['ok'] ) ) {
                return self::error_response( 'EFORMS_ERR_EMAIL_SEND', 500, $result );
            }

            return array(
                'handled' => true,
                'render' => 'redirect',
                'status' => isset( $redirect['status'] ) ? (int) $redirect['status'] : 303,
                'location' => isset( $redirect['location'] ) && is_string( $redirect['location'] ) ? $redirect['location'] : '',
                'body' => '',
                'result' => $result,
            );
        }

        if ( self::can_rerender( $form_id, $result ) ) {
            self::send_status( $status );
            $options = array(
                'cacheable' => false,
                'security' => isset( $result['security'] ) ? $result['security'] : array(),
                'errors' => isset( $result['errors'] ) ? $result['errors'] : null,
                'values' => isset( $result['values'] ) ? $result['values'] : array(),
                'require_challenge' => ! empty( $result['require_challenge'] ),
            );

            return array(
                'handled' => true,
                'render' => 'local',
                'form_id' => $form_id,
                'options' => $options,
                'status' => $status,
                'location' => '',
                'body' => '',
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
            'render' => 'template',
            'status' => (int) $status,
            'location' => '',
            'body' => $body,
            'result' => $result,
        );
    }

    private static function result_page_response() {
        $request = Success::parse_result_request();
        if ( ! is_array( $request ) ) {
            return self::not_handled();
        }

        $form_id = isset( $request['form_id'] ) ? $request['form_id'] : '';
        $result_type = isset( $request['result'] ) ? $request['result'] : '';
        $context = self::load_result_context( $form_id );
        if ( $context === null ) {
            return self::error_response( 'EFORMS_ERR_SCHEMA_REQUIRED', 500 );
        }

        self::send_status( 200 );
        Success::emit_cache_headers();

        return array(
            'handled' => true,
            'render' => 'result_page',
            'status' => 200,
            'location' => '',
            'body' => '',
            'result' => array(
                'ok' => true,
                'result' => $result_type,
                'form_id' => $form_id,
            ),
            'result_page' => array(
                'type' => $result_type,
                'form_id' => $form_id,
                'context' => $context,
                'template' => self::result_page_template( $result_type ),
            ),
        );
    }

    private static function load_result_context( $form_id ) {
        $loaded = TemplateLoader::load( $form_id );
        if ( ! is_array( $loaded ) || empty( $loaded['ok'] ) ) {
            return null;
        }

        $context = TemplateContext::build( $loaded['template'], $loaded['version'] );
        if ( ! is_array( $context ) || empty( $context['ok'] ) || ! isset( $context['context'] ) || ! is_array( $context['context'] ) ) {
            return null;
        }

        return $context['context'];
    }

    private static function result_page_template( $result_type ) {
        $name = $result_type === Success::RESULT_EMAIL_FAILURE ? 'email-failure.php' : 'success.php';
        return dirname( __DIR__, 2 ) . '/templates/pages/' . $name;
    }

    private static function can_rerender( $form_id, $result ) {
        if ( ! is_string( $form_id ) || $form_id === '' || ! is_array( $result ) ) {
            return false;
        }

        return isset( $result['security'] ) && is_array( $result['security'] );
    }

    private static function capture_response( $response ) {
        self::$captured_response = $response;
        self::$local_rerender = null;
        self::$result_page = null;

        $render = isset( $response['render'] ) && is_string( $response['render'] ) ? $response['render'] : '';
        if ( $render === 'local' ) {
            self::$local_rerender = array(
                'form_id' => isset( $response['form_id'] ) && is_string( $response['form_id'] ) ? $response['form_id'] : '',
                'options' => isset( $response['options'] ) && is_array( $response['options'] ) ? $response['options'] : array(),
            );
            return;
        }

        if ( $render === 'result_page' ) {
            self::$result_page = isset( $response['result_page'] ) && is_array( $response['result_page'] )
                ? $response['result_page']
                : array();
        }

        if ( ( $render === 'redirect' || $render === 'template' || $render === 'result_page' ) && function_exists( 'add_filter' ) ) {
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

        foreach ( FormProtocol::post_detection_keys() as $key ) {
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
        $reserved = FormProtocol::reserved_field_key_map();

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

    private static function escape_attr( $value ) {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}
