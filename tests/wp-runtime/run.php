<?php
/**
 * WordPress-runtime public hidden-mode smoke harness.
 *
 * This is a faithful fixture for the public surfaces used by the clear-win
 * slice: hooks, shortcode render, uploads, mail, cache headers, and PRG.
 *
 * Contract: Public surfaces index; Request lifecycle GET/POST; Success behavior.
 */

$root_dir = dirname( __DIR__, 2 );
$tmp_root = rtrim( sys_get_temp_dir(), '/\\' ) . '/eforms-wp-runtime-' . getmypid() . '-' . str_replace( '.', '', uniqid( '', true ) );
$uploads_dir = $tmp_root . '/uploads';
$content_dir = $tmp_root . '/wp-content';

if ( ! mkdir( $uploads_dir, 0700, true ) && ! is_dir( $uploads_dir ) ) {
    fwrite( STDERR, "Unable to create uploads directory.\n" );
    exit( 1 );
}
if ( ! mkdir( $content_dir, 0700, true ) && ! is_dir( $content_dir ) ) {
    fwrite( STDERR, "Unable to create content directory.\n" );
    exit( 1 );
}

register_shutdown_function(
    function () use ( $tmp_root ) {
        eforms_wp_runtime_remove_tree( $tmp_root );
    }
);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $tmp_root . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', $content_dir );
}

$GLOBALS['wp_version'] = '6.4';
$GLOBALS['eforms_wp_runtime_uploads_dir'] = $uploads_dir;
$GLOBALS['eforms_wp_runtime_hooks'] = array(
    'action' => array(),
    'filter' => array(),
    'shortcode' => array(),
    'rewrite' => array(),
    'rest' => array(),
);
$GLOBALS['eforms_wp_runtime_mail'] = array();
$GLOBALS['eforms_wp_runtime_redirects'] = array();
$GLOBALS['eforms_wp_runtime_nocache'] = 0;
$GLOBALS['eforms_wp_runtime_assets'] = array();
$GLOBALS['eforms_wp_runtime_status'] = 200;
$GLOBALS['eforms_wp_runtime_mail_should_fail'] = false;
$GLOBALS['eforms_wp_runtime_last_template'] = '';
$GLOBALS['eforms_wp_runtime_management_pages'] = array();
$GLOBALS['eforms_wp_runtime_options_pages'] = array();

if ( ! function_exists( 'eforms_wp_runtime_assert' ) ) {
    function eforms_wp_runtime_assert( $condition, $message ) {
        if ( ! $condition ) {
            throw new RuntimeException( $message );
        }
    }
}

if ( ! function_exists( 'eforms_wp_runtime_remove_tree' ) ) {
    function eforms_wp_runtime_remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
            return;
        }

        $items = scandir( $path );
        if ( $items === false ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            eforms_wp_runtime_remove_tree( $path . '/' . $item );
        }
        @rmdir( $path );
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
        if ( ! isset( $GLOBALS['eforms_wp_runtime_hooks']['action'][ $hook ] ) ) {
            $GLOBALS['eforms_wp_runtime_hooks']['action'][ $hook ] = array();
        }
        $GLOBALS['eforms_wp_runtime_hooks']['action'][ $hook ][] = array(
            'callback' => $callback,
            'priority' => (int) $priority,
            'args' => (int) $args,
        );
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
        if ( ! isset( $GLOBALS['eforms_wp_runtime_hooks']['filter'][ $hook ] ) ) {
            $GLOBALS['eforms_wp_runtime_hooks']['filter'][ $hook ] = array();
        }
        $GLOBALS['eforms_wp_runtime_hooks']['filter'][ $hook ][] = array(
            'callback' => $callback,
            'priority' => (int) $priority,
            'args' => (int) $args,
        );
        return true;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) {
        $callbacks = isset( $GLOBALS['eforms_wp_runtime_hooks']['filter'][ $hook ] )
            ? $GLOBALS['eforms_wp_runtime_hooks']['filter'][ $hook ]
            : array();
        usort(
            $callbacks,
            function ( $a, $b ) {
                return $a['priority'] <=> $b['priority'];
            }
        );
        foreach ( $callbacks as $entry ) {
            if ( is_callable( $entry['callback'] ) ) {
                $value = call_user_func( $entry['callback'], $value );
            }
        }
        return $value;
    }
}

if ( ! function_exists( 'eforms_wp_runtime_set_filter' ) ) {
    function eforms_wp_runtime_set_filter( $hook, $callback ) {
        if ( ! is_string( $hook ) || $hook === '' ) {
            return;
        }

        if ( $callback === null ) {
            unset( $GLOBALS['eforms_wp_runtime_hooks']['filter'][ $hook ] );
            return;
        }

        $GLOBALS['eforms_wp_runtime_hooks']['filter'][ $hook ] = array(
            array(
                'callback' => $callback,
                'priority' => 10,
                'args' => 1,
            ),
        );
    }
}

if ( ! function_exists( 'add_shortcode' ) ) {
    function add_shortcode( $tag, $callback ) {
        $GLOBALS['eforms_wp_runtime_hooks']['shortcode'][ $tag ] = $callback;
        return true;
    }
}

if ( ! function_exists( 'add_management_page' ) ) {
    function add_management_page( $page_title, $menu_title, $capability, $menu_slug, $callback ) {
        $GLOBALS['eforms_wp_runtime_management_pages'][] = array(
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
            'callback' => $callback,
        );
        return $menu_slug;
    }
}

if ( ! function_exists( 'add_options_page' ) ) {
    function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback ) {
        $GLOBALS['eforms_wp_runtime_options_pages'][] = array(
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
            'callback' => $callback,
        );
        return $menu_slug;
    }
}

if ( ! function_exists( 'add_rewrite_rule' ) ) {
    function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
        $GLOBALS['eforms_wp_runtime_hooks']['rewrite'][] = array( $regex, $query, $after );
        return true;
    }
}

if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
        $GLOBALS['eforms_wp_runtime_hooks']['rest'][] = array( $namespace, $route, $args, $override );
        return true;
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '' ) {
        return $show === 'version' ? $GLOBALS['wp_version'] : '';
    }
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => $GLOBALS['eforms_wp_runtime_uploads_dir'],
            'baseurl' => 'https://example.test/wp-content/uploads',
            'error' => false,
        );
    }
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src, $deps = array(), $ver = false ) {
        $GLOBALS['eforms_wp_runtime_assets'][] = array( 'style', $handle, $src );
        return true;
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
        $GLOBALS['eforms_wp_runtime_assets'][] = array( 'script', $handle, $src );
        return true;
    }
}

if ( ! function_exists( 'wp_script_add_data' ) ) {
    function wp_script_add_data( $handle, $key, $value ) {
        return true;
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return 'https://example.test/wp-content/plugins/eforms/' . ltrim( (string) $path, '/' );
    }
}

if ( ! function_exists( 'nocache_headers' ) ) {
    function nocache_headers() {
        $GLOBALS['eforms_wp_runtime_nocache']++;
        return true;
    }
}

if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
        $GLOBALS['eforms_wp_runtime_mail'][] = array(
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'attachments' => $attachments,
        );
        return empty( $GLOBALS['eforms_wp_runtime_mail_should_fail'] );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) {
        if ( $name === 'admin_email' ) {
            return 'admin@example.test';
        }

        return $default;
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return 'https://example.test';
    }
}

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) {
        return 'eforms-wp-runtime-' . (string) $scheme . '-salt';
    }
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( $location, $status = 302 ) {
        $GLOBALS['eforms_wp_runtime_redirects'][] = array(
            'location' => $location,
            'status' => (int) $status,
        );
        return true;
    }
}

if ( ! function_exists( 'status_header' ) ) {
    function status_header( $status ) {
        $GLOBALS['eforms_wp_runtime_status'] = (int) $status;
        return true;
    }
}

if ( ! function_exists( 'is_email' ) ) {
    function is_email( $email ) {
        return is_string( $email ) && filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $entry ) {
                $out[ $key ] = wp_unslash( $entry );
            }
            return $out;
        }
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $value ) {
        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $value ) {
        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $value ) {
        return filter_var( (string) $value, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( 'get_header' ) ) {
    function get_header() {
        $classes = apply_filters( 'body_class', array( 'home', 'front-page', 'page' ) );
        $classes = is_array( $classes ) ? array_values( array_filter( $classes, 'is_string' ) ) : array();
        echo '<header class="' . esc_attr( implode( ' ', $classes ) ) . '">Theme Header</header>';
    }
}

if ( ! function_exists( 'get_footer' ) ) {
    function get_footer() {
        echo '<footer>Theme Footer</footer>';
    }
}

require_once $root_dir . '/eforms.php';

if ( ! function_exists( 'eforms_wp_runtime_do_action' ) ) {
    function eforms_wp_runtime_do_action( $hook ) {
        $callbacks = isset( $GLOBALS['eforms_wp_runtime_hooks']['action'][ $hook ] )
            ? $GLOBALS['eforms_wp_runtime_hooks']['action'][ $hook ]
            : array();
        usort(
            $callbacks,
            function ( $a, $b ) {
                return $a['priority'] <=> $b['priority'];
            }
        );
        foreach ( $callbacks as $entry ) {
            if ( is_callable( $entry['callback'] ) ) {
                call_user_func( $entry['callback'] );
            }
        }
    }
}

if ( ! function_exists( 'eforms_wp_runtime_reset_request' ) ) {
    function eforms_wp_runtime_reset_request( $get = array() ) {
        $_GET = is_array( $get ) ? $get : array();
        $_POST = array();
        $_FILES = array();
        $_SERVER = array(
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.test',
            'HTTPS' => 'on',
            'REQUEST_URI' => '/contact/',
        );
        unset( $GLOBALS['eforms_wp_runtime_hooks']['filter']['body_class'] );
        unset( $GLOBALS['eforms_wp_runtime_hooks']['filter']['template_include'] );

        if ( class_exists( 'Config' ) ) {
            Config::reset_for_tests();
        }
        if ( class_exists( 'StorageHealth' ) ) {
            StorageHealth::reset_for_tests();
        }
        if ( class_exists( 'FormRenderer' ) ) {
            FormRenderer::reset_for_tests();
        }
        if ( class_exists( 'PublicRequestController' ) ) {
            PublicRequestController::reset_for_tests();
        }
        Logging::reset_for_tests();
        $GLOBALS['eforms_wp_runtime_status'] = 200;
        if ( function_exists( 'header_remove' ) ) {
            header_remove();
        }
    }
}

if ( ! function_exists( 'eforms_wp_runtime_shortcode' ) ) {
    function eforms_wp_runtime_shortcode( $slug, $cacheable = false, $opts = array() ) {
        $atts = array_merge(
            array(
                'id' => $slug,
                'cacheable' => $cacheable ? 'true' : 'false',
            ),
            is_array( $opts ) ? $opts : array()
        );
        $callback = isset( $GLOBALS['eforms_wp_runtime_hooks']['shortcode']['eform'] )
            ? $GLOBALS['eforms_wp_runtime_hooks']['shortcode']['eform']
            : null;
        eforms_wp_runtime_assert( is_callable( $callback ), 'Shortcode [eform] should be registered.' );
        return call_user_func( $callback, $atts, '', 'eform' );
    }
}

if ( ! function_exists( 'eforms_wp_runtime_hidden_value' ) ) {
    function eforms_wp_runtime_hidden_value( $html, $name ) {
        $pattern = '/name="' . preg_quote( $name, '/' ) . '" value="([^"]*)"/';
        if ( preg_match( $pattern, $html, $matches ) !== 1 ) {
            return '';
        }
        return html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'eforms_wp_runtime_ledger_count' ) ) {
    function eforms_wp_runtime_ledger_count( $form_id ) {
        $dir = $GLOBALS['eforms_wp_runtime_uploads_dir'] . '/eforms-private/ledger/' . $form_id;
        if ( ! is_dir( $dir ) ) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() && substr( $file->getFilename(), -5 ) === '.used' ) {
                $count++;
            }
        }
        return $count;
    }
}

if ( ! function_exists( 'eforms_wp_runtime_staged_secret' ) ) {
    function eforms_wp_runtime_staged_secret( $byte ) {
        return rtrim( strtr( base64_encode( str_repeat( $byte, Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
    }
}

if ( ! function_exists( 'eforms_wp_runtime_staged_field' ) ) {
    function eforms_wp_runtime_staged_field( $form_id, $field_key ) {
        $loaded = TemplateLoader::load( $form_id );
        if ( empty( $loaded['ok'] ) || ! isset( $loaded['template']['fields'] ) || ! is_array( $loaded['template']['fields'] ) ) {
            return array();
        }

        foreach ( $loaded['template']['fields'] as $field ) {
            if ( is_array( $field ) && isset( $field['key'] ) && $field['key'] === $field_key ) {
                return $field;
            }
        }

        return array();
    }
}

if ( ! function_exists( 'eforms_wp_runtime_render_controller_response' ) ) {
    function eforms_wp_runtime_render_controller_response() {
        $template = apply_filters( 'template_include', '' );
        $GLOBALS['eforms_wp_runtime_last_template'] = is_string( $template ) ? $template : '';
        if ( ! is_string( $template ) || $template === '' || ! is_readable( $template ) ) {
            return '';
        }

        ob_start();
        include $template;
        return ob_get_clean();
    }
}

if ( ! function_exists( 'eforms_wp_runtime_public_hidden_post' ) ) {
    function eforms_wp_runtime_public_hidden_post( $post, $headers = array() ) {
        $_SERVER = array_merge( array(
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'example.test',
            'HTTPS' => 'on',
            'REQUEST_URI' => '/contact/',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => strlen( http_build_query( $post ) ),
        ), is_array( $headers ) ? $headers : array() );
        $_POST = $post;
        $_FILES = array();

        eforms_wp_runtime_do_action( 'template_redirect' );
        $response = PublicRequestController::last_response();
        eforms_wp_runtime_assert( is_array( $response ), 'PublicRequestController should capture handled POST responses.' );
        $body = eforms_wp_runtime_render_controller_response();
        if ( $body === '' && isset( $response['render'] ) && $response['render'] === 'local' ) {
            $body = eforms_wp_runtime_shortcode( isset( $response['form_id'] ) ? $response['form_id'] : 'contact', false );
        }

        return array(
            'status' => isset( $response['status'] ) ? (int) $response['status'] : 0,
            'location' => isset( $response['location'] ) ? $response['location'] : '',
            'result' => isset( $response['result'] ) ? $response['result'] : null,
            'body' => $body,
            'template' => isset( $GLOBALS['eforms_wp_runtime_last_template'] ) ? $GLOBALS['eforms_wp_runtime_last_template'] : '',
        );
    }
}

if ( ! function_exists( 'eforms_wp_runtime_result_get' ) ) {
    function eforms_wp_runtime_result_get( $get ) {
        eforms_wp_runtime_reset_request( $get );
        eforms_wp_runtime_do_action( 'template_redirect' );
        $response = PublicRequestController::last_response();
        eforms_wp_runtime_assert( is_array( $response ), 'PublicRequestController should capture handled result GET responses.' );
        $body = eforms_wp_runtime_render_controller_response();

        return array(
            'status' => isset( $response['status'] ) ? (int) $response['status'] : 0,
            'location' => isset( $response['location'] ) ? $response['location'] : '',
            'result' => isset( $response['result'] ) ? $response['result'] : null,
            'body' => $body,
            'template' => isset( $GLOBALS['eforms_wp_runtime_last_template'] ) ? $GLOBALS['eforms_wp_runtime_last_template'] : '',
        );
    }
}

if ( ! function_exists( 'eforms_wp_runtime_review_get' ) ) {
    function eforms_wp_runtime_review_get( $url ) {
        $query = array();
        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
        eforms_wp_runtime_reset_request( $query );
        $path = parse_url( $url, PHP_URL_PATH );
        $query_string = parse_url( $url, PHP_URL_QUERY );
        $_SERVER['REQUEST_URI'] = ( is_string( $path ) ? $path : '/' ) . ( is_string( $query_string ) && $query_string !== '' ? '?' . $query_string : '' );
        eforms_wp_runtime_do_action( 'template_redirect' );
        $response = PublicRequestController::last_response();
        eforms_wp_runtime_assert( is_array( $response ), 'PublicRequestController should capture handled review GET responses.' );
        $body = eforms_wp_runtime_render_controller_response();

        return array(
            'status' => isset( $response['status'] ) ? (int) $response['status'] : 0,
            'body' => $body,
            'response' => $response,
            'template' => isset( $GLOBALS['eforms_wp_runtime_last_template'] ) ? $GLOBALS['eforms_wp_runtime_last_template'] : '',
        );
    }
}

try {
    eforms_wp_runtime_do_action( 'init' );
    eforms_wp_runtime_do_action( 'rest_api_init' );
    eforms_wp_runtime_do_action( 'admin_menu' );

    eforms_wp_runtime_assert( isset( $GLOBALS['eforms_wp_runtime_hooks']['shortcode']['eform'] ), 'Shortcode [eform] should be registered.' );
    eforms_wp_runtime_assert( ! empty( $GLOBALS['eforms_wp_runtime_hooks']['action']['template_redirect'] ), 'template_redirect hook should be registered.' );
    $template_redirect = $GLOBALS['eforms_wp_runtime_hooks']['action']['template_redirect'][0];
    eforms_wp_runtime_assert( $template_redirect['priority'] === 0, 'Public POST controller should run at template_redirect priority 0.' );
    eforms_wp_runtime_assert( $template_redirect['callback'] === array( 'PublicRequestController', 'handle_template_redirect' ), 'template_redirect should register PublicRequestController only.' );
    eforms_wp_runtime_assert( ! empty( $GLOBALS['eforms_wp_runtime_hooks']['rewrite'] ), 'Rewrite rules should be registered through init.' );
    eforms_wp_runtime_assert( ! empty( $GLOBALS['eforms_wp_runtime_hooks']['rest'] ), 'REST routes should be registered through rest_api_init.' );
    $submissions_pages = array_filter(
        $GLOBALS['eforms_wp_runtime_management_pages'],
        function ( $page ) {
            return is_array( $page )
                && isset( $page['menu_slug'], $page['capability'], $page['callback'] )
                && $page['menu_slug'] === 'eforms-submissions'
                && $page['capability'] === 'manage_options'
                && $page['callback'] === array( 'SubmissionsAdmin', 'render_page' );
        }
    );
    eforms_wp_runtime_assert( count( $submissions_pages ) === 1, 'Tools -> eForms Submissions should register as a manage_options admin page.' );

    eforms_wp_runtime_reset_request();
    $html = eforms_wp_runtime_shortcode( 'contact', false );
    eforms_wp_runtime_assert( strpos( $html, 'class="eforms-form eforms-form-contact"' ) !== false, 'Shortcode should render the contact form.' );
    eforms_wp_runtime_assert( strpos( $html, 'data-eforms-mode="hidden"' ) !== false, 'Shortcode should render hidden mode.' );
    eforms_wp_runtime_assert( strpos( $html, 'name="contact[name]"' ) !== false, 'Rendered fields should use the canonical form namespace.' );
    eforms_wp_runtime_assert( $GLOBALS['eforms_wp_runtime_nocache'] > 0, 'Hidden-mode render should request nocache headers.' );

    $token = eforms_wp_runtime_hidden_value( $html, 'eforms_token' );
    $instance_id = eforms_wp_runtime_hidden_value( $html, 'instance_id' );
    $timestamp = eforms_wp_runtime_hidden_value( $html, 'timestamp' );
    eforms_wp_runtime_assert( $token !== '', 'Hidden-mode render should include a token.' );
    eforms_wp_runtime_assert( $instance_id !== '', 'Hidden-mode render should include an instance id.' );
    eforms_wp_runtime_assert( $timestamp !== '', 'Hidden-mode render should include a timestamp.' );

    $invalid_post = array(
        'eforms_mode' => 'hidden',
        'eforms_token' => $token,
        'instance_id' => $instance_id,
        'timestamp' => $timestamp,
        'js_ok' => '1',
        'eforms_hp' => '',
        'contact' => array(
            'name' => '',
            'email' => 'ada@example.test',
            'message' => 'Hello from the wp-runtime harness.',
        ),
    );

    eforms_wp_runtime_reset_request();
    $ledger_before_invalid = eforms_wp_runtime_ledger_count( 'contact' );
    $invalid_response = eforms_wp_runtime_public_hidden_post( $invalid_post );
    eforms_wp_runtime_assert( $invalid_response['status'] === 200, 'Validation errors should rerender with HTTP 200.' );
    eforms_wp_runtime_assert( strpos( $invalid_response['body'], 'eforms-error-summary' ) !== false, 'Validation rerender should include the error summary.' );
    eforms_wp_runtime_assert( strpos( $invalid_response['body'], 'href="#contact-name"' ) !== false, 'Validation rerender should point at the invalid field.' );
    eforms_wp_runtime_assert( preg_match( '/<input(?=[^>]*\\bname="contact\\[email\\]")(?=[^>]*\\bvalue="ada@example\\.test")[^>]*>/', $invalid_response['body'] ) === 1, 'Validation rerender should retain safe non-file values through the public controller path.' );
    eforms_wp_runtime_assert( eforms_wp_runtime_hidden_value( $invalid_response['body'], 'eforms_token' ) === $token, 'Validation rerender should reuse the submitted token.' );
    eforms_wp_runtime_assert( eforms_wp_runtime_ledger_count( 'contact' ) === $ledger_before_invalid, 'Validation failure should not reserve the ledger.' );

    eforms_wp_runtime_reset_request();
    $enhanced_invalid = eforms_wp_runtime_public_hidden_post(
        $invalid_post,
        array( 'HTTP_X_EFORMS_RESPONSE' => FormProtocol::ENHANCED_RESPONSE_JSON )
    );
    $enhanced_invalid_body = json_decode( $enhanced_invalid['body'], true );
    eforms_wp_runtime_assert( $enhanced_invalid['status'] === 422, 'An exact enhanced response request should convert a correctable outcome to HTTP 422.' );
    eforms_wp_runtime_assert(
        array_keys( $enhanced_invalid_body ) === array( 'ok', 'errors', 'upload_recovery', 'challenge' )
            && $enhanced_invalid_body['ok'] === false
            && array_keys( $enhanced_invalid_body['errors'] ) === array( 'global', 'fields' )
            && array_keys( $enhanced_invalid_body['errors']['fields'] ) === array( 'name' )
            && $enhanced_invalid_body['upload_recovery'] === null
            && $enhanced_invalid_body['challenge'] === null,
        'Enhanced correctable responses should contain only the contracted safe fields in template order.'
    );
    eforms_wp_runtime_assert( strpos( $enhanced_invalid['body'], 'ada@example.test' ) === false && strpos( $enhanced_invalid['body'], 'Hello from the wp-runtime harness.' ) === false, 'Enhanced correctable JSON must not expose submitted values.' );

    eforms_wp_runtime_reset_request();
    $html = eforms_wp_runtime_shortcode( 'contact', false );
    $token = eforms_wp_runtime_hidden_value( $html, 'eforms_token' );
    $instance_id = eforms_wp_runtime_hidden_value( $html, 'instance_id' );
    $timestamp = eforms_wp_runtime_hidden_value( $html, 'timestamp' );
    $valid_post = array(
        'eforms_mode' => 'hidden',
        'eforms_token' => $token,
        'instance_id' => $instance_id,
        'timestamp' => $timestamp,
        'js_ok' => '1',
        'eforms_hp' => '',
        'contact' => array(
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'message' => 'Please contact me.',
        ),
    );

    eforms_wp_runtime_reset_request();
    $ledger_before_success = eforms_wp_runtime_ledger_count( 'contact' );
    $success_response = eforms_wp_runtime_public_hidden_post( $valid_post );
    eforms_wp_runtime_assert( $success_response['status'] === 303, 'Successful POST should produce a PRG 303 response.' );
    eforms_wp_runtime_assert( basename( $success_response['template'] ) === 'empty-response-template.php', 'Successful redirect response should use the empty internal template.' );
    eforms_wp_runtime_assert( $success_response['body'] === '', 'Successful redirect response should not emit a body.' );
    eforms_wp_runtime_assert( strpos( $success_response['body'], 'Theme Header' ) === false, 'Successful redirect response should not render the theme header.' );
    eforms_wp_runtime_assert( strpos( $success_response['body'], '<form' ) === false, 'Successful redirect response should not render the form.' );
    eforms_wp_runtime_assert( strpos( $success_response['body'], 'eforms_token' ) === false, 'Successful redirect response should not mint or emit form tokens.' );
    eforms_wp_runtime_assert( strpos( $success_response['location'], 'eforms_result=success' ) !== false, 'PRG location should include the success result parameter.' );
    eforms_wp_runtime_assert( strpos( $success_response['location'], 'eforms_form=contact' ) !== false, 'PRG location should include the form parameter.' );
    eforms_wp_runtime_assert( count( $GLOBALS['eforms_wp_runtime_mail'] ) === 1, 'Successful POST should send one email through wp_mail().' );
    eforms_wp_runtime_assert( eforms_wp_runtime_ledger_count( 'contact' ) === $ledger_before_success + 1, 'Successful POST should reserve exactly one ledger marker.' );

    eforms_wp_runtime_reset_request();
    $enhanced_html = eforms_wp_runtime_shortcode( 'contact', false );
    $enhanced_valid_post = $valid_post;
    $enhanced_valid_post['eforms_token'] = eforms_wp_runtime_hidden_value( $enhanced_html, 'eforms_token' );
    $enhanced_valid_post['instance_id'] = eforms_wp_runtime_hidden_value( $enhanced_html, 'instance_id' );
    $enhanced_valid_post['timestamp'] = eforms_wp_runtime_hidden_value( $enhanced_html, 'timestamp' );
    $enhanced_success = eforms_wp_runtime_public_hidden_post(
        $enhanced_valid_post,
        array( 'HTTP_X_EFORMS_RESPONSE' => FormProtocol::ENHANCED_RESPONSE_JSON )
    );
    $enhanced_success_body = json_decode( $enhanced_success['body'], true );
    eforms_wp_runtime_assert(
        $enhanced_success['status'] === 200
            && array_keys( $enhanced_success_body ) === array( 'ok', 'location' )
            && $enhanced_success_body['ok'] === true
            && strpos( $enhanced_success_body['location'], 'eforms_result=success' ) !== false
            && $enhanced_success['location'] === '',
        'Enhanced accepted submissions should return the Success-owned location without redirecting.'
    );
    eforms_wp_runtime_assert( strpos( $enhanced_success['body'], 'Ada Lovelace' ) === false && strpos( $enhanced_success['body'], 'ada@example.test' ) === false, 'Enhanced accepted JSON must not disclose submitted values.' );

    eforms_wp_runtime_reset_request();
    $mail_before_duplicate = count( $GLOBALS['eforms_wp_runtime_mail'] );
    $ledger_after_success = eforms_wp_runtime_ledger_count( 'contact' );
    $duplicate_response = eforms_wp_runtime_public_hidden_post( $valid_post );
    eforms_wp_runtime_assert( $duplicate_response['status'] === 400, 'Duplicate replay should return HTTP 400.' );
    eforms_wp_runtime_assert( is_array( $duplicate_response['result'] ), 'Duplicate replay should return a structured result.' );
    eforms_wp_runtime_assert( $duplicate_response['result']['error_code'] === 'EFORMS_ERR_TOKEN', 'Duplicate replay should be rejected as a token error.' );
    eforms_wp_runtime_assert(
        strpos( $duplicate_response['body'], 'This form was already submitted or has expired - please reload the page.' ) !== false,
        'Duplicate replay should show the public token-expired message.'
    );
    eforms_wp_runtime_assert(
        strpos( $duplicate_response['body'], 'Form configuration error.' ) === false,
        'Duplicate replay must not look like a configuration failure.'
    );
    eforms_wp_runtime_assert( eforms_wp_runtime_ledger_count( 'contact' ) === $ledger_after_success, 'Duplicate replay should not reserve another ledger marker.' );
    eforms_wp_runtime_assert( count( $GLOBALS['eforms_wp_runtime_mail'] ) === $mail_before_duplicate, 'Duplicate replay should not send another email.' );

    // A real staged aggregate survives an ordinary validation rerender, then
    // freezes, finalizes, and sends exactly once through the public POST path.
    eforms_wp_runtime_reset_request();
    $staged_html = eforms_wp_runtime_shortcode( 'upload-test', false );
    $staged_token = eforms_wp_runtime_hidden_value( $staged_html, 'eforms_token' );
    $staged_instance = eforms_wp_runtime_hidden_value( $staged_html, 'instance_id' );
    $staged_timestamp = eforms_wp_runtime_hidden_value( $staged_html, 'timestamp' );
    $staged_validation = Security::validate_managed_token( $staged_token, $staged_instance, 'upload-test', $uploads_dir );
    $staged_field = eforms_wp_runtime_staged_field( 'upload-test', 'photos' );
    eforms_wp_runtime_assert( ! empty( $staged_validation['ok'] ), 'The staged runtime fixture should mint a valid managed token.' );
    eforms_wp_runtime_assert( ! empty( $staged_field ), 'The staged runtime fixture should load the photos field policy.' );

    $staged_secret = eforms_wp_runtime_staged_secret( "\x51" );
    $staged_binding = array(
        'raw_token' => $staged_token,
        'form_id' => 'upload-test',
        'instance_id' => $staged_instance,
        'field_key' => 'photos',
        'accept_until' => $staged_validation['expires'],
    );
    $staged_batch = UploadBatchStore::create_batch( $staged_binding, $staged_secret, $staged_field, $uploads_dir );
    eforms_wp_runtime_assert( ! empty( $staged_batch['ok'] ), 'The staged runtime fixture should create an authenticated aggregate.' );
    $staged_source = $tmp_root . '/runtime-photo.png';
    $staged_png = base64_decode( trim( file_get_contents( $root_dir . '/tests/fixtures/staged-landscape.png.b64' ) ), true );
    eforms_wp_runtime_assert( file_put_contents( $staged_source, $staged_png ) !== false, 'The staged runtime fixture should write its source image.' );
    $staged_put = UploadBatchStore::put_item(
        $staged_batch['batch']['batch_id'],
        $staged_secret,
        'runtime_photo',
        0,
        array(
            'tmp_name' => $staged_source,
            'original_name' => 'Runtime Photo.png',
            'size' => filesize( $staged_source ),
            'error' => UPLOAD_ERR_OK,
        ),
        $uploads_dir
    );
    eforms_wp_runtime_assert( ! empty( $staged_put['ok'] ), 'The staged runtime fixture should commit one processed image.' );

    $staged_post = array(
        'eforms_mode' => 'hidden',
        'eforms_token' => $staged_token,
        'instance_id' => $staged_instance,
        'timestamp' => $staged_timestamp,
        'js_ok' => '1',
        'eforms_hp' => '',
        'eforms_upload_batches' => array(
            'photos' => array(
                'batch_id' => $staged_batch['batch']['batch_id'],
                'batch_secret' => $staged_secret,
            ),
        ),
        'upload-test' => array( 'name' => array( 'invalid-type' ) ),
    );
    eforms_wp_runtime_reset_request();
    $staged_invalid = eforms_wp_runtime_public_hidden_post( $staged_post );
    eforms_wp_runtime_assert( $staged_invalid['status'] === 200, 'An ordinary staged-form validation error should rerender locally.' );
    eforms_wp_runtime_assert(
        eforms_wp_runtime_hidden_value( $staged_invalid['body'], 'eforms_upload_batches[photos][batch_id]' ) === $staged_batch['batch']['batch_id'],
        'A local rerender should re-emit only the validated batch id.'
    );
    eforms_wp_runtime_assert(
        eforms_wp_runtime_hidden_value( $staged_invalid['body'], 'eforms_upload_batches[photos][batch_secret]' ) === $staged_secret,
        'A local rerender should re-emit only the validated batch secret.'
    );
    $staged_open = UploadBatchStore::status( $staged_batch['batch']['batch_id'], $staged_secret, $uploads_dir );
    eforms_wp_runtime_assert( ! empty( $staged_open['ok'] ) && $staged_open['batch']['state'] === 'open', 'Validation failure should leave the staged aggregate open.' );

    $staged_post['upload-test']['name'] = 'Ada Lovelace';
    eforms_wp_runtime_reset_request();
    $mail_before_staged = count( $GLOBALS['eforms_wp_runtime_mail'] );
    $staged_success = eforms_wp_runtime_public_hidden_post( $staged_post );
    eforms_wp_runtime_assert( $staged_success['status'] === 303, 'A corrected staged-form POST should complete with PRG.' );
    eforms_wp_runtime_assert( count( $GLOBALS['eforms_wp_runtime_mail'] ) === $mail_before_staged + 1, 'A finalized staged submission should invoke wp_mail exactly once.' );
    $staged_submission = UploadBatchStore::submission( $staged_token, $uploads_dir );
    eforms_wp_runtime_assert( ! empty( $staged_submission['ok'] ), 'The staged aggregate should be available under the submission id after finalization.' );
    eforms_wp_runtime_assert( $staged_submission['submission']['email_attempted_at'] !== null, 'The durable email-attempt marker should precede the staged mail call.' );
    $staged_mail = $GLOBALS['eforms_wp_runtime_mail'][ $mail_before_staged ];
    eforms_wp_runtime_assert( substr_count( $staged_mail['message'], 'eforms_review=' . $staged_token ) === 1 && strpos( $staged_mail['message'], 'expires' . '=' ) === false, 'The staged runtime email should contain exactly one expiration-free signed gallery URL under plain permalinks.' );
    eforms_wp_runtime_assert( $staged_mail['attachments'] === array() && strpos( $staged_mail['message'], 'eforms_review_upload=' ) === false, 'The staged runtime email should contain neither managed attachments nor individual file links.' );
    $staged_former_path = UploadBatchStore::status( $staged_batch['batch']['batch_id'], $staged_secret, $uploads_dir );
    eforms_wp_runtime_assert( empty( $staged_former_path['ok'] ) && ! empty( $staged_former_path['gone'] ), 'The former batch endpoint path should return its generic terminal state after rename.' );

    $review_url = ReviewController::gallery_url( $staged_token );
    eforms_wp_runtime_assert( $review_url !== '', 'The finalized runtime submission should produce one signed gallery URL.' );
    $review_page = eforms_wp_runtime_review_get( $review_url );
    eforms_wp_runtime_assert( $review_page['status'] === 200, 'A valid signed gallery GET should render HTTP 200.' );
    eforms_wp_runtime_assert( basename( $review_page['template'] ) === 'review-gallery.php', 'A valid gallery should use the private review page template.' );
    eforms_wp_runtime_assert( strpos( $review_page['body'], 'Theme Header' ) !== false && strpos( $review_page['body'], 'Theme Footer' ) !== false, 'The review gallery should use the canonical theme shell.' );
    eforms_wp_runtime_assert( strpos( $review_page['body'], '<h1 class="page-title">Submitted Photos</h1>' ) !== false, 'The review gallery should show its stable title.' );
    eforms_wp_runtime_assert( strpos( $review_page['body'], 'eforms-review-actions' ) === false && strpos( $review_page['body'], '1 photo' ) === false && strpos( $review_page['body'], 'Available until ' ) === false && strpos( $review_page['body'], 'eforms-review-submitted' ) === false, 'Anonymous review galleries should omit operator management metadata.' );
    eforms_wp_runtime_assert( strpos( $review_page['body'], '<img' ) === false && strpos( $review_page['body'], 'Preview unavailable' ) !== false, 'A local no-preview gallery should not embed authoritative artifacts.' );
    eforms_wp_runtime_assert( strpos( $review_page['body'], 'eforms-review-download-overlay' ) !== false && substr_count( $review_page['body'], 'eforms_review_upload=' ) === 1, 'The no-preview card should expose exactly one signed authoritative-artifact download control.' );
    eforms_wp_runtime_assert( strpos( $review_page['body'], '>Download submitted image</a>' ) === false, 'The review gallery should not duplicate the icon download with a caption text action.' );
    eforms_wp_runtime_assert( strpos( $review_page['body'], 'data-eforms-review-delete-open' ) === false, 'Anonymous review galleries should not expose operator deletion controls.' );
    eforms_wp_runtime_assert( strpos( $review_page['body'], $uploads_dir ) === false && strpos( $review_page['body'], $staged_secret ) === false, 'The review gallery must not disclose private paths or batch credentials.' );
    $review_items = $review_page['response']['review_page']['items'];
    $review_download = eforms_wp_runtime_review_get( $review_items[0]['download_url'] );
    $review_download_path = $tmp_root . '/review-submitted-image.png';
    eforms_wp_runtime_assert( file_put_contents( $review_download_path, $review_download['body'] ) !== false, 'The runtime review test should capture its artifact response.' );
    eforms_wp_runtime_assert( $review_download['status'] === 200 && UploadPolicy::detect_mime( $review_download_path ) === 'image/png', 'The public controller should stream the exact signed authoritative artifact (status ' . $review_download['status'] . ', template ' . basename( $review_download['template'] ) . ', render ' . $review_download['response']['render'] . ', mime ' . UploadPolicy::detect_mime( $review_download_path ) . ', bytes ' . strlen( $review_download['body'] ) . ', prefix ' . bin2hex( substr( $review_download['body'], 0, 16 ) ) . ').' );

    $invalid_review_url = preg_replace( '/signature=[A-Za-z0-9_-]{43}/', 'signature=' . str_repeat( 'A', 43 ), $review_url );
    $invalid_review = eforms_wp_runtime_review_get( $invalid_review_url );
    eforms_wp_runtime_assert( $invalid_review['status'] === 404 && strpos( $invalid_review['body'], 'Review unavailable.' ) !== false, 'Invalid gallery grants should render the generic private not-found response.' );
    eforms_wp_runtime_assert( strpos( $invalid_review['body'], $staged_token ) === false && strpos( $invalid_review['body'], $uploads_dir ) === false, 'Invalid gallery output should reveal no submission or path facts.' );

    $success_page = eforms_wp_runtime_result_get( array( 'eforms_result' => 'success', 'eforms_form' => 'contact' ) );
    eforms_wp_runtime_assert( $success_page['status'] === 200, 'Follow-up success GET should render HTTP 200.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], 'Theme Header' ) !== false, 'Success result page should include the theme header.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], 'Theme Footer' ) !== false, 'Success result page should include the theme footer.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], '<header class="page">Theme Header</header>' ) !== false, 'Success result page should filter body classes before the theme header.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], 'eforms-result-page-success' ) !== false, 'Follow-up GET should show the success result page.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], '<h1 class="page-title">Your Message Was Sent!</h1>' ) !== false, 'Success result page should show the template title.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], 'class="inner article-body-wrap"' ) !== false, 'Success result page should use the theme page scaffold.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], 'Thank you for getting in touch! We will get back to you at the earliest convenience.' ) !== false, 'Success page should use the template message.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], '<form' ) === false, 'Follow-up success display should not render the form.' );

    $quote_success_page = eforms_wp_runtime_result_get( array( 'eforms_result' => 'success', 'eforms_form' => 'quote-request' ) );
    eforms_wp_runtime_assert( $quote_success_page['status'] === 200, 'Quote request success GET should render HTTP 200.' );
    eforms_wp_runtime_assert( strpos( $quote_success_page['body'], '<h1 class="page-title">Your Message Was Sent!</h1>' ) !== false, 'Quote request success result should show the template title.' );
    eforms_wp_runtime_assert( strpos( $quote_success_page['body'], 'Thank you for getting in touch! We will get back to you at the earliest convenience.' ) !== false, 'Quote request success page should use the restored template message.' );
    eforms_wp_runtime_assert( strpos( $quote_success_page['body'], 'Thanks! We got your request.' ) === false, 'Quote request success page should not use the old short message.' );

    eforms_wp_runtime_reset_request();
    $html = eforms_wp_runtime_shortcode( 'contact', false );
    $token = eforms_wp_runtime_hidden_value( $html, 'eforms_token' );
    $instance_id = eforms_wp_runtime_hidden_value( $html, 'instance_id' );
    $timestamp = eforms_wp_runtime_hidden_value( $html, 'timestamp' );
    $email_failure_post = array(
        'eforms_mode' => 'hidden',
        'eforms_token' => $token,
        'instance_id' => $instance_id,
        'timestamp' => $timestamp,
        'js_ok' => '1',
        'eforms_hp' => '',
        'contact' => array(
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'message' => 'Please contact me.',
        ),
    );

    eforms_wp_runtime_reset_request();
    $GLOBALS['eforms_wp_runtime_mail_should_fail'] = true;
    $mail_before_email_failure = count( $GLOBALS['eforms_wp_runtime_mail'] );
    $ledger_before_email_failure = eforms_wp_runtime_ledger_count( 'contact' );
    $email_failure_response = eforms_wp_runtime_public_hidden_post( $email_failure_post );
    $GLOBALS['eforms_wp_runtime_mail_should_fail'] = false;
    eforms_wp_runtime_assert( $email_failure_response['status'] === 303, 'Email failure should PRG to a result page.' );
    eforms_wp_runtime_assert( basename( $email_failure_response['template'] ) === 'empty-response-template.php', 'Email-failure redirect response should use the empty internal template.' );
    eforms_wp_runtime_assert( $email_failure_response['body'] === '', 'Email-failure redirect response should not emit a body.' );
    eforms_wp_runtime_assert( strpos( $email_failure_response['body'], 'Theme Header' ) === false, 'Email-failure redirect response should not render the theme header.' );
    eforms_wp_runtime_assert( strpos( $email_failure_response['body'], '<form' ) === false, 'Email-failure redirect response should not render the form.' );
    eforms_wp_runtime_assert( strpos( $email_failure_response['body'], 'Ada Lovelace' ) === false, 'Email-failure redirect response should not include submitted values.' );
    eforms_wp_runtime_assert( strpos( $email_failure_response['location'], 'eforms_result=email_failure' ) !== false, 'Email failure should redirect to the email-failure result page.' );
    eforms_wp_runtime_assert( strpos( $email_failure_response['location'], 'eforms_form=contact' ) !== false, 'Email failure result URL should include form id.' );
    eforms_wp_runtime_assert( is_array( $email_failure_response['result'] ), 'Email failure should return a structured result.' );
    eforms_wp_runtime_assert( $email_failure_response['result']['error_code'] === 'EFORMS_ERR_EMAIL_SEND', 'Email failure should use EFORMS_ERR_EMAIL_SEND.' );
    eforms_wp_runtime_assert( count( $GLOBALS['eforms_wp_runtime_mail'] ) === $mail_before_email_failure + 2, 'Email failure should attempt the original send and one admin notification.' );
    eforms_wp_runtime_assert( eforms_wp_runtime_ledger_count( 'contact' ) === $ledger_before_email_failure + 1, 'Email failure should keep the original ledger reservation committed.' );
    eforms_wp_runtime_assert( $GLOBALS['eforms_wp_runtime_mail'][ $mail_before_email_failure + 1 ]['to'] === 'admin@example.test', 'Email failure should notify the WordPress admin email.' );
    eforms_wp_runtime_assert( strpos( $GLOBALS['eforms_wp_runtime_mail'][ $mail_before_email_failure + 1 ]['message'], 'Ada Lovelace' ) === false, 'Admin notification should not include submitted field values.' );
    $email_failure_page = eforms_wp_runtime_result_get( array( 'eforms_result' => 'email_failure', 'eforms_form' => 'contact' ) );
    eforms_wp_runtime_assert( $email_failure_page['status'] === 200, 'Follow-up email-failure GET should render HTTP 200.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'Theme Header' ) !== false, 'Email failure page should include the theme header.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'Theme Footer' ) !== false, 'Email failure page should include the theme footer.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], '<header class="page">Theme Header</header>' ) !== false, 'Email failure page should filter body classes before the theme header.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'eforms-result-page-email-failure' ) !== false, 'Follow-up GET should show the email-failure result page.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], '<h1 class="page-title">Request Not Sent</h1>' ) !== false, 'Email failure page should show the theme page title.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'class="inner article-body-wrap"' ) !== false, 'Email failure page should use the theme page scaffold.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'We couldn&#039;t send your request right now, so it may not have reached us. Please try again in a few minutes. If the issue keeps happening, call 720.900.5278 or message us directly.' ) !== false, 'Email failure page should show the friendly email failure message.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'Ada Lovelace' ) === false, 'Email failure page should not include submitted values.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], '<form' ) === false, 'Email failure page should not render the form.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'eforms-email-failure-copy' ) === false, 'Email failure page should not include a copy summary.' );

    eforms_wp_runtime_reset_request();
    $enhanced_email_html = eforms_wp_runtime_shortcode( 'contact', false );
    $enhanced_email_failure_post = $email_failure_post;
    $enhanced_email_failure_post['eforms_token'] = eforms_wp_runtime_hidden_value( $enhanced_email_html, 'eforms_token' );
    $enhanced_email_failure_post['instance_id'] = eforms_wp_runtime_hidden_value( $enhanced_email_html, 'instance_id' );
    $enhanced_email_failure_post['timestamp'] = eforms_wp_runtime_hidden_value( $enhanced_email_html, 'timestamp' );
    $GLOBALS['eforms_wp_runtime_mail_should_fail'] = true;
    $enhanced_email_failure = eforms_wp_runtime_public_hidden_post(
        $enhanced_email_failure_post,
        array( 'HTTP_X_EFORMS_RESPONSE' => FormProtocol::ENHANCED_RESPONSE_JSON )
    );
    $GLOBALS['eforms_wp_runtime_mail_should_fail'] = false;
    $enhanced_email_failure_body = json_decode( $enhanced_email_failure['body'], true );
    eforms_wp_runtime_assert(
        $enhanced_email_failure['status'] === 200
            && array_keys( $enhanced_email_failure_body ) === array( 'ok', 'location' )
            && $enhanced_email_failure_body['ok'] === true
            && strpos( $enhanced_email_failure_body['location'], 'eforms_result=email_failure' ) !== false
            && $enhanced_email_failure['location'] === '',
        'Enhanced email failures should return only the safe Success-owned navigation envelope without redirecting.'
    );
    eforms_wp_runtime_assert( strpos( $enhanced_email_failure['body'], 'Ada Lovelace' ) === false && strpos( $enhanced_email_failure['body'], 'ada@example.test' ) === false, 'Enhanced email-failure JSON must not disclose submitted values.' );

    eforms_wp_runtime_set_filter(
        'eforms_config',
        function ( $config ) {
            $config['security']['origin_mode'] = 'off';
            $config['challenge']['mode'] = 'always_post';
            $config['challenge']['provider'] = 'turnstile';
            $config['challenge']['site_key'] = 'site-key-123';
            $config['challenge']['secret_key'] = 'secret-key-123';
            $config['challenge']['http_timeout_seconds'] = 2;
            return $config;
        }
    );
    eforms_wp_runtime_reset_request();
    $html = eforms_wp_runtime_shortcode( 'contact', false );
    eforms_wp_runtime_assert( strpos( $html, 'cf-turnstile' ) === false, 'Initial GET should not render the challenge widget.' );
    eforms_wp_runtime_assert( strpos( $html, 'challenges.cloudflare.com' ) === false, 'Initial GET should not expose the provider script URL.' );
    eforms_wp_runtime_assert( strpos( $html, 'data-eforms-challenge-script-url' ) === false, 'Initial GET should not render a challenge script URL data attribute.' );
    $token = eforms_wp_runtime_hidden_value( $html, 'eforms_token' );
    $instance_id = eforms_wp_runtime_hidden_value( $html, 'instance_id' );
    $timestamp = eforms_wp_runtime_hidden_value( $html, 'timestamp' );
    $challenge_post = array(
        'eforms_mode' => 'hidden',
        'eforms_token' => $token,
        'instance_id' => $instance_id,
        'timestamp' => $timestamp,
        'js_ok' => '1',
        'eforms_hp' => '',
        'contact' => array(
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'message' => 'Please contact me.',
        ),
    );

    eforms_wp_runtime_reset_request();
    $mail_before_challenge = count( $GLOBALS['eforms_wp_runtime_mail'] );
    $ledger_before_challenge = eforms_wp_runtime_ledger_count( 'contact' );
    $challenge_response = eforms_wp_runtime_public_hidden_post( $challenge_post );
    eforms_wp_runtime_assert( $challenge_response['status'] === 200, 'Missing challenge response should rerender with HTTP 200.' );
    eforms_wp_runtime_assert( is_array( $challenge_response['result'] ), 'Challenge failure should return a structured result.' );
    eforms_wp_runtime_assert( $challenge_response['result']['error_code'] === 'EFORMS_ERR_CHALLENGE_FAILED', 'Missing challenge response should use challenge failure code.' );
    eforms_wp_runtime_assert( ! empty( $challenge_response['result']['require_challenge'] ), 'Challenge failure should require challenge on rerender.' );
    eforms_wp_runtime_assert( substr_count( $challenge_response['body'], 'class="cf-turnstile"' ) === 1, 'Challenge rerender should include exactly one challenge widget.' );
    $turnstile_scripts = array_filter(
        $GLOBALS['eforms_wp_runtime_assets'],
        function ( $asset ) {
            return is_array( $asset ) && isset( $asset[0], $asset[1] ) && $asset[0] === 'script' && $asset[1] === 'eforms-turnstile';
        }
    );
    eforms_wp_runtime_assert( count( $turnstile_scripts ) === 1, 'Challenge rerender should enqueue exactly one provider script.' );
    eforms_wp_runtime_assert( eforms_wp_runtime_ledger_count( 'contact' ) === $ledger_before_challenge, 'Challenge failure should not reserve the ledger.' );
    eforms_wp_runtime_assert( count( $GLOBALS['eforms_wp_runtime_mail'] ) === $mail_before_challenge, 'Challenge failure should not send email.' );

    eforms_wp_runtime_reset_request();
    $enhanced_challenge_response = eforms_wp_runtime_public_hidden_post(
        $challenge_post,
        array( 'HTTP_X_EFORMS_RESPONSE' => FormProtocol::ENHANCED_RESPONSE_JSON )
    );
    $enhanced_challenge_body = json_decode( $enhanced_challenge_response['body'], true );
    eforms_wp_runtime_assert(
        $enhanced_challenge_response['status'] === 422
            && $enhanced_challenge_body['challenge'] === array( 'provider' => 'turnstile', 'site_key' => 'site-key-123' ),
        'Enhanced challenge corrections should expose only Challenge-owned public provider metadata.'
    );
    eforms_wp_runtime_assert(
        strpos( $enhanced_challenge_response['body'], 'secret-key-123' ) === false
            && strpos( $enhanced_challenge_response['body'], 'challenges.cloudflare.com' ) === false,
        'Enhanced challenge metadata must not disclose a provider secret or script URL.'
    );
    eforms_wp_runtime_set_filter( 'eforms_config', null );

    echo "WordPress runtime hidden-mode smoke passed.\n";
} catch ( Throwable $exception ) {
    fwrite( STDERR, $exception->getMessage() . "\n" );
    exit( 1 );
}
