<?php
/**
 * eForms bootstrap helpers.
 *
 * Educational note: this file wires the public entry points without
 * depending on WordPress internals so early smoke tests can load it.
 */

require_once __DIR__ . '/ErrorMessages.php';
require_once __DIR__ . '/FormProtocol.php';
require_once __DIR__ . '/Compat.php';
require_once __DIR__ . '/Uploads/PrivateDir.php';

if ( ! defined( 'EFORMS_REWRITE_RULES_OPTION' ) ) {
    define( 'EFORMS_REWRITE_RULES_OPTION', 'eforms_rewrite_rules_version' );
}
if ( ! defined( 'EFORMS_REWRITE_RULES_VERSION' ) ) {
    define( 'EFORMS_REWRITE_RULES_VERSION', 1 );
}

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

if ( ! function_exists( 'eforms_define_finfo_guard' ) ) {
    /**
     * Define a deterministic flag when fileinfo is unavailable.
     */
    function eforms_define_finfo_guard() {
        if ( defined( 'EFORMS_FINFO_UNAVAILABLE' ) ) {
            return;
        }

        if ( ! function_exists( 'finfo_open' ) ) {
            define( 'EFORMS_FINFO_UNAVAILABLE', true );
        }
    }
}

if ( ! function_exists( 'eforms_error_message' ) ) {
    /**
     * Resolve a stable error message for a known error code.
     */
    function eforms_error_message( $code ) {
        return ErrorMessages::message( $code );
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
     * Register public eForms paths (requires permalinks).
     */
    function eforms_register_rewrite_rule() {
        if ( ! function_exists( 'add_rewrite_rule' ) ) {
            return;
        }

        add_rewrite_rule( '^eforms/mint/?$', 'index.php?rest_route=/eforms/mint', 'top' );
        if ( ! class_exists( 'ReviewController' ) ) {
            require_once __DIR__ . '/Uploads/ReviewController.php';
        }
        ReviewController::register_rewrite_rule();
    }
}

if ( ! function_exists( 'eforms_activate' ) ) {
    /**
     * Reopen managed storage after reinstall.
     */
    function eforms_activate() {
        if ( class_exists( 'Config' ) && class_exists( 'PrivateDir' ) ) {
            Config::bootstrap();
            $config = Config::get();
            $configured_uploads_dir = Config::value( $config, array( 'uploads', 'dir' ), '' );
            $uploads_dir = is_string( $configured_uploads_dir ) ? rtrim( $configured_uploads_dir, '/\\' ) : '';
            if ( $uploads_dir !== '' ) {
                $storage_failure = Compat::probe_uploads_semantics( $uploads_dir );
                if ( $storage_failure !== null || ! PrivateDir::resume_after_install( $uploads_dir ) ) {
                    $message = $storage_failure !== null
                        ? 'eForms managed storage is incompatible: ' . $storage_failure
                        : 'eForms could not reopen its managed storage. The plugin was not activated.';
                    if ( function_exists( 'deactivate_plugins' ) && defined( 'EFORMS_PLUGIN_FILE' ) ) {
                        $plugin = function_exists( 'plugin_basename' ) ? plugin_basename( EFORMS_PLUGIN_FILE ) : EFORMS_PLUGIN_FILE;
                        deactivate_plugins( $plugin, true );
                    }
                    if ( function_exists( 'wp_die' ) ) {
                        wp_die( $message );
                    }
                    throw new RuntimeException( $message );
                }
            }
        }
        eforms_refresh_rewrite_rules( true );
        return true;
    }
}

if ( ! function_exists( 'eforms_refresh_rewrite_rules' ) ) {
    /**
     * Persist new public routes once after an in-place plugin update.
     */
    function eforms_refresh_rewrite_rules( $force = false ) {
        if ( ! function_exists( 'flush_rewrite_rules' ) ) {
            return false;
        }
        if ( ! $force ) {
            if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
                return false;
            }
            if ( (int) get_option( EFORMS_REWRITE_RULES_OPTION, 0 ) >= EFORMS_REWRITE_RULES_VERSION ) {
                return false;
            }
        }

        eforms_register_rewrite_rule();
        flush_rewrite_rules( false );
        if ( function_exists( 'update_option' ) ) {
            update_option( EFORMS_REWRITE_RULES_OPTION, EFORMS_REWRITE_RULES_VERSION, false );
        }
        return true;
    }
}

if ( ! function_exists( 'eforms_deactivate' ) ) {
    /**
     * Invalidate persisted routes so WordPress regenerates them without eForms.
     */
    function eforms_deactivate() {
        if ( function_exists( 'delete_option' ) ) {
            delete_option( EFORMS_REWRITE_RULES_OPTION );
            delete_option( 'rewrite_rules' );
        }
        return true;
    }
}

if ( ! function_exists( 'eforms_rest_route_request' ) ) {
    function eforms_rest_route_request( $request ) {
        if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
            return false;
        }
        $route = $request->get_route();
        return is_string( $route ) && preg_match( '#^/eforms(?:/|$)#', $route ) === 1;
    }
}

if ( ! function_exists( 'eforms_rest_pre_dispatch' ) ) {
    function eforms_rest_pre_dispatch( $result, $server, $request ) {
        if ( $result !== null || ! eforms_rest_route_request( $request ) || ! method_exists( $request, 'get_method' ) || strtoupper( $request->get_method() ) !== 'OPTIONS' ) {
            return $result;
        }
        if ( ! class_exists( 'OriginPolicy' ) ) {
            require_once __DIR__ . '/Security/OriginPolicy.php';
        }
        $origin = OriginPolicy::evaluate( $request, Config::get() );
        if ( ! is_array( $origin ) || ! isset( $origin['state'] ) || $origin['state'] !== 'same' ) {
            return new WP_Error(
                'EFORMS_ERR_ORIGIN_FORBIDDEN',
                'Origin forbidden.',
                array( 'status' => 403 )
            );
        }
        return $result;
    }
}

if ( ! function_exists( 'eforms_rest_strip_cors_headers' ) ) {
    function eforms_rest_strip_cors_headers( $served, $result, $request ) {
        if ( ! eforms_rest_route_request( $request ) ) {
            return $served;
        }
        $cors_headers = array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Allow-Credentials', 'Access-Control-Expose-Headers', 'Access-Control-Max-Age' );
        if ( is_object( $result ) && method_exists( $result, 'get_headers' ) && method_exists( $result, 'set_headers' ) ) {
            $response_headers = $result->get_headers();
            if ( is_array( $response_headers ) ) {
                foreach ( array_keys( $response_headers ) as $header ) {
                    foreach ( $cors_headers as $cors_header ) {
                        if ( strcasecmp( $header, $cors_header ) === 0 ) {
                            unset( $response_headers[ $header ] );
                            break;
                        }
                    }
                }
                $result->set_headers( $response_headers );
            }
        }
        foreach ( $cors_headers as $header ) {
            if ( function_exists( 'header_remove' ) && ! headers_sent() ) {
                header_remove( $header );
            }
        }
        return $served;
    }
}

if ( ! function_exists( 'eforms_rest_upload_batch_response' ) ) {
    function eforms_rest_upload_batch_response( $request, $action ) {
        if ( ! class_exists( 'UploadBatchEndpoint' ) ) {
            require_once __DIR__ . '/Uploads/UploadBatchEndpoint.php';
        }
        $result = call_user_func( array( 'UploadBatchEndpoint', $action ), $request );
        $body = isset( $result['body'] ) ? $result['body'] : array( 'error' => 'EFORMS_ERR_STORAGE_UNAVAILABLE' );

        return eforms_rest_response(
            $body,
            isset( $result['status'] ) ? (int) $result['status'] : 503,
            isset( $result['headers'] ) && is_array( $result['headers'] ) ? $result['headers'] : array()
        );
    }
}

if ( ! function_exists( 'eforms_rest_upload_batch_create' ) ) {
    function eforms_rest_upload_batch_create( $request ) {
        return eforms_rest_upload_batch_response( $request, 'create' );
    }
}

if ( ! function_exists( 'eforms_rest_upload_batch_status' ) ) {
    function eforms_rest_upload_batch_status( $request ) {
        return eforms_rest_upload_batch_response( $request, 'status' );
    }
}

if ( ! function_exists( 'eforms_rest_upload_batch_item' ) ) {
    function eforms_rest_upload_batch_item( $request ) {
        $action = '';
        if ( is_object( $request ) && method_exists( $request, 'get_method' ) ) {
            $action = strtoupper( (string) $request->get_method() ) === 'DELETE' ? 'delete' : 'upload';
        } elseif ( is_array( $request ) && isset( $request['method'] ) ) {
            $action = strtoupper( (string) $request['method'] ) === 'DELETE' ? 'delete' : 'upload';
        }
        return eforms_rest_upload_batch_response( $request, $action === 'delete' ? 'delete' : 'upload' );
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
        register_rest_route(
            'eforms',
            '/upload-batches',
            array(
                'methods'             => $methods,
                'callback'            => 'eforms_rest_upload_batch_create',
                'permission_callback' => 'eforms_rest_allow_public',
            )
        );
        register_rest_route(
            'eforms',
            '/upload-batches/(?P<' . FormProtocol::UPLOAD_BATCH_PARAM . '>' . FormProtocol::upload_batch_id_pattern( false ) . ')',
            array(
                'methods'             => $methods,
                'callback'            => 'eforms_rest_upload_batch_status',
                'permission_callback' => 'eforms_rest_allow_public',
            )
        );
        register_rest_route(
            'eforms',
            '/upload-batches/(?P<' . FormProtocol::UPLOAD_BATCH_PARAM . '>' . FormProtocol::upload_batch_id_pattern( false ) . ')/items/(?P<' . FormProtocol::UPLOAD_ITEM_PARAM . '>' . FormProtocol::managed_id_pattern( false ) . ')',
            array(
                'methods'             => $methods,
                'callback'            => 'eforms_rest_upload_batch_item',
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
        WP_CLI::add_command( 'eforms spam-smoke', 'eforms_cli_spam_smoke' );
        WP_CLI::add_command( 'eforms doctor', 'eforms_cli_doctor' );
    }
}

if ( ! function_exists( 'eforms_register_admin' ) ) {
    /**
     * Register wp-admin surfaces.
     */
    function eforms_register_admin() {
        if ( ! class_exists( 'SettingsAdmin' ) ) {
            require_once __DIR__ . '/Admin/SettingsAdmin.php';
        }

        SettingsAdmin::register();

        if ( ! class_exists( 'SubmissionsAdmin' ) ) {
            require_once __DIR__ . '/Admin/SubmissionsAdmin.php';
        }

        SubmissionsAdmin::register();

        if ( Config::bool( Config::get(), array( 'declined_review', 'enable' ), false ) ) {
            if ( ! class_exists( 'DeclinedReviewAdmin' ) ) {
                require_once __DIR__ . '/Admin/DeclinedReviewAdmin.php';
            }

            DeclinedReviewAdmin::register();
        }
    }
}

if ( ! function_exists( 'eforms_cli_gc' ) ) {
    /**
     * Handler for `wp eforms gc`.
     */
    function eforms_cli_gc( $args = array(), $assoc_args = array() ) {
        if ( ! class_exists( 'GcCommand' ) ) {
            require_once __DIR__ . '/Cli/GcCommand.php';
        }

        return GcCommand::invoke( $args, $assoc_args );
    }
}

if ( ! function_exists( 'eforms_cli_spam_smoke' ) ) {
    /**
     * Handler for `wp eforms spam-smoke`.
     */
    function eforms_cli_spam_smoke( $args = array(), $assoc_args = array() ) {
        if ( ! class_exists( 'SpamSmokeCommand' ) ) {
            require_once __DIR__ . '/Cli/SpamSmokeCommand.php';
        }

        return SpamSmokeCommand::invoke( $args, $assoc_args );
    }
}

if ( ! function_exists( 'eforms_cli_doctor' ) ) {
    /**
     * Handler for `wp eforms doctor`.
     */
    function eforms_cli_doctor( $args = array(), $assoc_args = array() ) {
        if ( ! class_exists( 'RuntimeHealthCommand' ) ) {
            require_once __DIR__ . '/Cli/RuntimeHealthCommand.php';
        }

        return RuntimeHealthCommand::invoke( $args, $assoc_args );
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
            add_action( 'init', 'eforms_refresh_rewrite_rules', 20 );
            add_action( 'rest_api_init', 'eforms_register_rest_routes' );
            if ( ! class_exists( 'PublicRequestController' ) ) {
                require_once __DIR__ . '/Submission/PublicRequestController.php';
            }
            add_action( 'template_redirect', array( 'PublicRequestController', 'handle_template_redirect' ), 0 );
            add_action( 'init', 'eforms_register_cli', 20 );
            eforms_register_admin();
        }

        if ( function_exists( 'add_filter' ) ) {
            add_filter( 'rest_pre_dispatch', 'eforms_rest_pre_dispatch', 5, 3 );
            add_filter( 'rest_pre_serve_request', 'eforms_rest_strip_cors_headers', 15, 3 );
            add_filter( 'redirect_canonical', array( 'ReviewController', 'prevent_canonical_redirect' ), 10, 2 );
            add_filter( 'lbwps_enabled', array( 'ReviewController', 'enable_lightbox_for_current_review' ), 99, 2 );
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
        eforms_define_finfo_guard();
        eforms_register_hooks();
    }
}
