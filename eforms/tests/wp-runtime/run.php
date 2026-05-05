<?php
/**
 * WordPress-runtime public hidden-mode smoke harness.
 *
 * This is a faithful fixture for the public surfaces used by the clear-win
 * slice: hooks, shortcode render, uploads, mail, cache headers, and PRG.
 *
 * Spec: Public surfaces index; Request lifecycle GET/POST; Success behavior.
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
        echo '<header>Theme Header</header>';
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
        if ( class_exists( 'Logging' ) && method_exists( 'Logging', 'reset' ) ) {
            Logging::reset();
        }
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
    function eforms_wp_runtime_public_hidden_post( $post ) {
        $_SERVER = array(
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'example.test',
            'HTTPS' => 'on',
            'REQUEST_URI' => '/contact/',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => strlen( http_build_query( $post ) ),
        );
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

try {
    eforms_wp_runtime_do_action( 'init' );
    eforms_wp_runtime_do_action( 'rest_api_init' );

    eforms_wp_runtime_assert( isset( $GLOBALS['eforms_wp_runtime_hooks']['shortcode']['eform'] ), 'Shortcode [eform] should be registered.' );
    eforms_wp_runtime_assert( ! empty( $GLOBALS['eforms_wp_runtime_hooks']['action']['template_redirect'] ), 'template_redirect hook should be registered.' );
    $template_redirect = $GLOBALS['eforms_wp_runtime_hooks']['action']['template_redirect'][0];
    eforms_wp_runtime_assert( $template_redirect['priority'] === 0, 'Public POST controller should run at template_redirect priority 0.' );
    eforms_wp_runtime_assert( $template_redirect['callback'] === array( 'PublicRequestController', 'handle_template_redirect' ), 'template_redirect should register PublicRequestController only.' );
    eforms_wp_runtime_assert( ! empty( $GLOBALS['eforms_wp_runtime_hooks']['rewrite'] ), 'Rewrite rules should be registered through init.' );
    eforms_wp_runtime_assert( ! empty( $GLOBALS['eforms_wp_runtime_hooks']['rest'] ), 'REST routes should be registered through rest_api_init.' );

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
    eforms_wp_runtime_assert( eforms_wp_runtime_hidden_value( $invalid_response['body'], 'eforms_token' ) === $token, 'Validation rerender should reuse the submitted token.' );
    eforms_wp_runtime_assert( eforms_wp_runtime_ledger_count( 'contact' ) === $ledger_before_invalid, 'Validation failure should not reserve the ledger.' );

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

    $success_page = eforms_wp_runtime_result_get( array( 'eforms_result' => 'success', 'eforms_form' => 'contact' ) );
    eforms_wp_runtime_assert( $success_page['status'] === 200, 'Follow-up success GET should render HTTP 200.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], 'Theme Header' ) !== false, 'Success result page should include the theme header.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], 'Theme Footer' ) !== false, 'Success result page should include the theme footer.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], 'eforms-result-page-success' ) !== false, 'Follow-up GET should show the success result page.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], 'Thanks! We got your message.' ) !== false, 'Success page should use the template message.' );
    eforms_wp_runtime_assert( strpos( $success_page['body'], '<form' ) === false, 'Follow-up success display should not render the form.' );

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
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'eforms-result-page-email-failure' ) !== false, 'Follow-up GET should show the email-failure result page.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'We couldn&#039;t send your request right now. Please try again in a few minutes.' ) !== false, 'Email failure page should show the friendly email failure message.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'Ada Lovelace' ) === false, 'Email failure page should not include submitted values.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], '<form' ) === false, 'Email failure page should not render the form.' );
    eforms_wp_runtime_assert( strpos( $email_failure_page['body'], 'eforms-email-failure-copy' ) === false, 'Email failure page should not include a copy summary.' );

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
    eforms_wp_runtime_set_filter( 'eforms_config', null );
    eforms_wp_runtime_assert( $challenge_response['status'] === 200, 'Missing challenge response should rerender with HTTP 200.' );
    eforms_wp_runtime_assert( is_array( $challenge_response['result'] ), 'Challenge failure should return a structured result.' );
    eforms_wp_runtime_assert( $challenge_response['result']['error_code'] === 'EFORMS_ERR_CHALLENGE_FAILED', 'Missing challenge response should use challenge failure code.' );
    eforms_wp_runtime_assert( ! empty( $challenge_response['result']['require_challenge'] ), 'Challenge failure should require challenge on rerender.' );
    eforms_wp_runtime_assert( strpos( $challenge_response['body'], 'cf-turnstile' ) !== false, 'Challenge rerender should include the challenge widget.' );
    eforms_wp_runtime_assert( eforms_wp_runtime_ledger_count( 'contact' ) === $ledger_before_challenge, 'Challenge failure should not reserve the ledger.' );
    eforms_wp_runtime_assert( count( $GLOBALS['eforms_wp_runtime_mail'] ) === $mail_before_challenge, 'Challenge failure should not send email.' );

    echo "WordPress runtime hidden-mode smoke passed.\n";
} catch ( Throwable $exception ) {
    fwrite( STDERR, $exception->getMessage() . "\n" );
    exit( 1 );
}
