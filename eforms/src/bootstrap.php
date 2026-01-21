<?php
/**
 * eForms bootstrap helpers.
 *
 * Educational note: this file wires the public entry points without
 * depending on WordPress internals so early smoke tests can load it.
 */

if ( ! function_exists( 'eforms_register_autoloader' ) ) {
    /**
     * Register a minimal autoloader for src/ classes.
     */
    function eforms_register_autoloader() {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;

        $base_dir = __DIR__;
        spl_autoload_register(
            function ( $class ) use ( $base_dir ) {
                $relative = ltrim( str_replace( '\\', '/', $class ), '/' );
                $path     = $base_dir . '/' . $relative . '.php';
                if ( is_readable( $path ) ) {
                    require_once $path;
                }
            }
        );
    }
}

if ( ! function_exists( 'eforms_error_message' ) ) {
    /**
     * Resolve a stable error message for a known error code.
     */
    function eforms_error_message( $code ) {
        if ( $code === 'EFORMS_ERR_STORAGE_UNAVAILABLE' ) {
            return 'Form configuration error: server storage is unavailable.';
        }

        return 'Form configuration error.';
    }
}

if ( ! function_exists( 'eforms_render_error' ) ) {
    /**
     * Render a deterministic error payload for public surfaces.
     */
    function eforms_render_error( $code ) {
        $message = eforms_error_message( $code );
        if ( function_exists( 'esc_html' ) ) {
            $message = esc_html( $message );
        }

        $attr_code = $code;
        if ( function_exists( 'esc_attr' ) ) {
            $attr_code = esc_attr( $code );
        }

        return '<div class="eforms-error" data-eforms-error="' . $attr_code . '">' . $message . '</div>';
    }
}

if ( ! function_exists( 'eform_render' ) ) {
    /**
     * Template tag stub for rendering forms.
     */
    function eform_render( $slug, $opts = array() ) {
        if ( ! class_exists( 'FormRenderer' ) ) {
            require_once __DIR__ . '/Rendering/FormRenderer.php';
        }

        return FormRenderer::render( $slug, $opts );
    }
}

if ( ! function_exists( 'eforms_shortcode' ) ) {
    /**
     * Shortcode stub for [eform].
     */
    function eforms_shortcode( $atts = array(), $content = '', $tag = '' ) {
        $slug = '';
        if ( is_array( $atts ) && isset( $atts['id'] ) ) {
            $slug = (string) $atts['id'];
        }

        return eform_render( $slug, is_array( $atts ) ? $atts : array() );
    }
}

if ( ! function_exists( 'eforms_register_rewrite_rule' ) ) {
    /**
     * Register the /eforms/mint path to the REST route (requires permalinks).
     */
    function eforms_register_rewrite_rule() {
        if ( ! function_exists( 'add_rewrite_rule' ) ) {
            return;
        }

        add_rewrite_rule( '^eforms/mint/?$', 'index.php?rest_route=/eforms/mint', 'top' );
    }
}

if ( ! function_exists( 'eforms_rest_response' ) ) {
    /**
     * Build a REST response with required cache-safety headers.
     */
    function eforms_rest_response( $body, $status, $extra_headers ) {
        $response = $body;
        if ( function_exists( 'rest_ensure_response' ) ) {
            $response = rest_ensure_response( $body );
        }

        if ( is_object( $response ) && method_exists( $response, 'set_status' ) ) {
            $response->set_status( $status );
            $response->header( 'Cache-Control', 'no-store, max-age=0' );
            foreach ( $extra_headers as $name => $value ) {
                $response->header( $name, $value );
            }
            return $response;
        }

        $headers                   = $extra_headers;
        $headers['Cache-Control']  = 'no-store, max-age=0';

        return array(
            'status'  => $status,
            'headers' => $headers,
            'body'    => $body,
        );
    }
}

if ( ! function_exists( 'eforms_rest_mint_stub' ) ) {
    /**
     * Stub handler for POST /eforms/mint.
     */
    function eforms_rest_mint_stub( $request ) {
        if ( ! class_exists( 'MintEndpoint' ) ) {
            require_once __DIR__ . '/Security/MintEndpoint.php';
        }

        $result = MintEndpoint::handle( $request );
        $status = isset( $result['status'] ) ? (int) $result['status'] : 500;
        $headers = isset( $result['headers'] ) && is_array( $result['headers'] ) ? $result['headers'] : array();
        $body = isset( $result['body'] ) ? $result['body'] : array( 'error' => 'EFORMS_ERR_MINT_FAILED' );

        return eforms_rest_response( $body, $status, $headers );
    }
}

if ( ! function_exists( 'eforms_rest_allow_public' ) ) {
    /**
     * Permission callback for public REST endpoints.
     */
    function eforms_rest_allow_public() {
        return true;
    }
}

if ( ! function_exists( 'eforms_register_rest_routes' ) ) {
    /**
     * Register REST routes for public surfaces.
     */
    function eforms_register_rest_routes() {
        if ( ! function_exists( 'register_rest_route' ) ) {
            return;
        }

        $methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' );
        if ( class_exists( 'WP_REST_Server' ) ) {
            $methods = WP_REST_Server::ALLMETHODS;
        }

        register_rest_route(
            'eforms',
            '/mint',
            array(
                'methods'             => $methods,
                'callback'            => 'eforms_rest_mint_stub',
                'permission_callback' => 'eforms_rest_allow_public',
            )
        );
    }
}

if ( ! function_exists( 'eforms_register_cli' ) ) {
    /**
     * Register the wp-cli stub command.
     */
    function eforms_register_cli() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
            return;
        }

        WP_CLI::add_command( 'eforms gc', 'eforms_cli_gc' );
    }
}

if ( ! function_exists( 'eforms_cli_gc' ) ) {
    /**
     * Stub handler for wp eforms gc.
     */
    function eforms_cli_gc() {
        if ( class_exists( 'WP_CLI' ) ) {
            WP_CLI::warning( 'eForms GC is not implemented yet.' );
        }
    }
}

if ( ! function_exists( 'eforms_register_hooks' ) ) {
    /**
     * Hook public entry points when WordPress is available.
     */
    function eforms_register_hooks() {
        if ( function_exists( 'add_shortcode' ) ) {
            add_shortcode( 'eform', 'eforms_shortcode' );
        }

        if ( function_exists( 'add_action' ) ) {
            add_action( 'init', 'eforms_register_rewrite_rule' );
            add_action( 'rest_api_init', 'eforms_register_rest_routes' );
            add_action( 'init', 'eforms_register_cli', 20 );
        }
    }
}

if ( ! function_exists( 'eforms_bootstrap' ) ) {
    /**
     * Initialize the plugin wiring once per request.
     */
    function eforms_bootstrap() {
        static $booted = false;
        if ( $booted ) {
            return;
        }
        $booted = true;

        eforms_register_autoloader();
        eforms_register_hooks();
    }
}
