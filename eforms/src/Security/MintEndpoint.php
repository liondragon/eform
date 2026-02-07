<?php
/**
 * REST handler for POST /eforms/mint.
 *
 * Spec: JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode)
 * Spec: Throttling (docs/Canonical_Spec.md#sec-throttling)
 * Spec: Security (docs/Canonical_Spec.md#sec-security)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/OriginPolicy.php';
require_once __DIR__ . '/PostSize.php';
require_once __DIR__ . '/Security.php';

class MintEndpoint {
    /**
     * Handle a request and return a response payload.
     *
     * @param mixed $request Request object or array.
     * @return array { status, headers, body }
     */
    public static function handle( $request ) {
        $headers = self::base_headers();

        $method = self::request_method( $request );
        if ( $method !== 'POST' ) {
            $headers['Allow'] = 'POST';
            return self::result( 405, $headers, array( 'error' => 'EFORMS_ERR_METHOD_NOT_ALLOWED' ) );
        }

        $content_type = self::header_value( $request, 'Content-Type' );
        if ( ! self::is_form_urlencoded( $content_type ) ) {
            return self::result( 400, $headers, array( 'error' => 'EFORMS_ERR_TYPE' ) );
        }

        $config = Config::get();
        $cap = PostSize::effective_cap( $content_type, $config );
        $length = self::content_length();
        if ( $length !== null && $length > $cap ) {
            return self::result( 400, $headers, array( 'error' => 'EFORMS_ERR_MINT_FAILED' ) );
        }

        $origin_eval = OriginPolicy::evaluate( $request, $config );
        if ( ! is_array( $origin_eval ) || ! isset( $origin_eval['state'] ) || $origin_eval['state'] !== 'same' ) {
            return self::result( 403, $headers, array( 'error' => 'EFORMS_ERR_ORIGIN_FORBIDDEN' ) );
        }

        $form_id = self::param_value( $request, 'f' );
        if ( ! self::is_valid_form_id( $form_id ) ) {
            return self::result( 400, $headers, array( 'error' => 'EFORMS_ERR_INVALID_FORM_ID' ) );
        }

        $template = TemplateLoader::load( $form_id );
        if ( ! is_array( $template ) || empty( $template['ok'] ) ) {
            return self::result( 400, $headers, array( 'error' => 'EFORMS_ERR_INVALID_FORM_ID' ) );
        }

        $mint = Security::mint_js_record( $form_id, null, $request );
        if ( ! is_array( $mint ) || empty( $mint['ok'] ) ) {
            $code = is_array( $mint ) && isset( $mint['code'] ) ? $mint['code'] : 'EFORMS_ERR_MINT_FAILED';
            if ( $code === 'EFORMS_ERR_THROTTLED' ) {
                $retry_after = 1;
                if ( is_array( $mint ) && isset( $mint['retry_after'] ) && is_numeric( $mint['retry_after'] ) ) {
                    $retry_after = max( 1, (int) $mint['retry_after'] );
                }
                $headers['Retry-After'] = (string) $retry_after;
                return self::result( 429, $headers, array( 'error' => 'EFORMS_ERR_THROTTLED' ) );
            }
            if ( $code === 'EFORMS_ERR_INVALID_FORM_ID' ) {
                return self::result( 400, $headers, array( 'error' => $code ) );
            }
            return self::result( 500, $headers, array( 'error' => 'EFORMS_ERR_MINT_FAILED' ) );
        }

        return self::result(
            200,
            $headers,
            array(
                'token' => $mint['token'],
                'instance_id' => $mint['instance_id'],
                'timestamp' => $mint['issued_at'],
                'expires' => $mint['expires'],
            )
        );
    }

    private static function base_headers() {
        return array(
            'Cache-Control' => 'no-store, max-age=0',
            'Content-Type' => 'application/json; charset=utf-8',
        );
    }

    private static function request_method( $request ) {
        if ( is_object( $request ) && method_exists( $request, 'get_method' ) ) {
            $value = $request->get_method();
            if ( is_string( $value ) && $value !== '' ) {
                return strtoupper( $value );
            }
        }

        if ( is_array( $request ) && isset( $request['method'] ) && is_string( $request['method'] ) ) {
            return strtoupper( $request['method'] );
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ) {
            return strtoupper( $_SERVER['REQUEST_METHOD'] );
        }

        return '';
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

    private static function header_value( $request, $name ) {
        if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
            $value = $request->get_header( $name );
            if ( is_string( $value ) ) {
                return trim( $value );
            }
        }

        if ( is_array( $request ) && isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
            foreach ( $request['headers'] as $key => $value ) {
                if ( is_string( $key ) && strcasecmp( $key, $name ) === 0 && is_string( $value ) ) {
                    return trim( $value );
                }
            }
        }

        $server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        if ( isset( $_SERVER[ $server_key ] ) && is_string( $_SERVER[ $server_key ] ) ) {
            return trim( $_SERVER[ $server_key ] );
        }

        return '';
    }

    private static function param_value( $request, $name ) {
        if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
            $value = $request->get_param( $name );
            if ( is_string( $value ) ) {
                return $value;
            }
        }

        if ( is_array( $request ) && isset( $request['params'] ) && is_array( $request['params'] ) ) {
            if ( isset( $request['params'][ $name ] ) && is_string( $request['params'][ $name ] ) ) {
                return $request['params'][ $name ];
            }
        }

        if ( isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ) {
            return $_POST[ $name ];
        }

        return '';
    }

    private static function is_form_urlencoded( $content_type ) {
        if ( ! is_string( $content_type ) ) {
            return false;
        }

        $content_type = strtolower( trim( $content_type ) );
        if ( $content_type === '' ) {
            return false;
        }

        $semi = strpos( $content_type, ';' );
        if ( $semi !== false ) {
            $content_type = trim( substr( $content_type, 0, $semi ) );
        }

        if ( $content_type === 'application/json' || substr( $content_type, -5 ) === '+json' ) {
            return false;
        }

        return $content_type === 'application/x-www-form-urlencoded';
    }

    private static function is_valid_form_id( $form_id ) {
        return is_string( $form_id ) && $form_id !== '';
    }

    private static function result( $status, $headers, $body ) {
        return array(
            'status' => (int) $status,
            'headers' => $headers,
            'body' => $body,
        );
    }
}
