<?php
/**
 * Minimal test harness for pure-PHP unit/smoke tests.
 *
 * Educational note: These tests intentionally do NOT load WordPress. When
 * WordPress APIs are needed, we stub the tiny subset used by the code under test.
 *
 * Spec: DRY principles (docs/Canonical_Spec.md#sec-dry-principles)
 */

if ( ! function_exists( 'eforms_test_assert' ) ) {
    function eforms_test_assert( $condition, $message ) {
        if ( ! $condition ) {
            throw new RuntimeException( $message );
        }
    }
}

if ( ! function_exists( 'eforms_test_tmp_root' ) ) {
    function eforms_test_tmp_root( $prefix ) {
        $base = rtrim( sys_get_temp_dir(), '/\\' );
        return $base . '/' . $prefix . '-' . uniqid( '', true );
    }
}

if ( ! function_exists( 'eforms_test_setup_uploads' ) ) {
    function eforms_test_setup_uploads( $prefix ) {
        $uploads_dir = eforms_test_tmp_root( $prefix );
        mkdir( $uploads_dir, 0700, true );
        $GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
        return $uploads_dir;
    }
}

if ( ! function_exists( 'eforms_test_write_basic_template' ) ) {
    function eforms_test_write_basic_template( $dir, $form_id, $title = 'Demo' ) {
        return eforms_test_write_form_template(
            $dir,
            $form_id,
            $title,
            array(
                array(
                    'key' => 'name',
                    'type' => 'text',
                    'label' => 'Name',
                    'required' => true,
                ),
            ),
            array( 'name' )
        );
    }
}

if ( ! function_exists( 'eforms_test_write_contact_template' ) ) {
    function eforms_test_write_contact_template( $dir, $form_id, $title = 'Demo' ) {
        return eforms_test_write_form_template(
            $dir,
            $form_id,
            $title,
            array(
                array(
                    'key' => 'name',
                    'type' => 'text',
                    'label' => 'Name',
                    'required' => true,
                ),
                array(
                    'key' => 'email',
                    'type' => 'email',
                    'label' => 'Email',
                    'required' => true,
                ),
            ),
            array( 'name', 'email' )
        );
    }
}

if ( ! function_exists( 'eforms_test_write_form_template' ) ) {
    function eforms_test_write_form_template( $dir, $form_id, $title, $fields, $include_fields, $email_overrides = array(), $result_pages = null ) {
        $template = array(
            'id' => $form_id,
            'version' => '1',
            'title' => $title,
            'result_pages' => array(
                'success' => array(
                    'message' => 'Thanks.',
                ),
            ),
            'email' => array(
                'to' => 'demo@example.com',
                'subject' => 'Demo',
                'email_template' => 'default',
                'include_fields' => $include_fields,
            ),
            'fields' => $fields,
            'submit_button_text' => 'Send',
        );
        if ( is_array( $email_overrides ) ) {
            foreach ( $email_overrides as $key => $value ) {
                $template['email'][ $key ] = $value;
            }
        }
        if ( is_array( $result_pages ) ) {
            $template['result_pages'] = $result_pages;
        }

        $path = rtrim( $dir, '/\\' ) . '/' . $form_id . '.json';
        file_put_contents( $path, json_encode( $template ) );
        return $path;
    }
}

if ( ! function_exists( 'eforms_test_write_file' ) ) {
    function eforms_test_write_file( $dir, $name, $bytes ) {
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0700, true );
        }

        $path = rtrim( $dir, '/\\' ) . '/' . $name;
        file_put_contents( $path, $bytes );
        return $path;
    }
}

if ( ! function_exists( 'eforms_test_remove_tree' ) ) {
    function eforms_test_remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
            return;
        }

        $items = array_diff( scandir( $path ), array( '.', '..' ) );
        foreach ( $items as $item ) {
            eforms_test_remove_tree( $path . '/' . $item );
        }
        @rmdir( $path );
    }
}

if ( ! function_exists( 'eforms_test_define_wp_content' ) ) {
    function eforms_test_define_wp_content( $prefix ) {
        if ( defined( 'ABSPATH' ) && defined( 'WP_CONTENT_DIR' ) ) {
            if ( is_string( WP_CONTENT_DIR ) && WP_CONTENT_DIR !== '' && ! is_dir( WP_CONTENT_DIR ) ) {
                mkdir( WP_CONTENT_DIR, 0700, true );
            }

            return array(
                'root'         => ABSPATH,
                'contents_dir' => WP_CONTENT_DIR,
            );
        }

        $root         = eforms_test_tmp_root( $prefix );
        $contents_dir = $root . '/wp-content';
        mkdir( $contents_dir, 0700, true );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', $root . '/' );
        }
        if ( ! defined( 'WP_CONTENT_DIR' ) ) {
            define( 'WP_CONTENT_DIR', $contents_dir );
        }

        return array(
            'root'         => $root,
            'contents_dir' => $contents_dir,
        );
    }
}

if ( ! function_exists( 'eforms_test_configure_declined_review' ) ) {
    function eforms_test_configure_declined_review( $uploads_dir, $enabled ) {
        eforms_test_set_filter(
            'eforms_config',
            function ( $config ) use ( $uploads_dir, $enabled ) {
                $config['uploads']['dir'] = $uploads_dir;
                $config['declined_review']['enable'] = (bool) $enabled;
                $config['declined_review']['retention_days'] = 30;
                $config['privacy']['ip_mode'] = 'full';
                return $config;
            }
        );

        if ( class_exists( 'Config' ) ) {
            Config::reset_for_tests();
        }
        Logging::reset_for_tests();
    }
}

if ( ! function_exists( 'is_email' ) ) {
    function is_email( $email ) {
        if ( ! is_string( $email ) || $email === '' ) {
            return false;
        }

        return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
    }
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => isset( $GLOBALS['eforms_test_uploads_dir'] ) ? $GLOBALS['eforms_test_uploads_dir'] : '',
        );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value ) {
        return json_encode( $value );
    }
}

if ( ! function_exists( 'eforms_test_reset_mail' ) ) {
    function eforms_test_reset_mail( $return = true ) {
        $GLOBALS['eforms_test_mail_calls'] = array();
        $GLOBALS['eforms_test_mail_return'] = (bool) $return;
    }
}

if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
        if ( ! isset( $GLOBALS['eforms_test_mail_calls'] ) || ! is_array( $GLOBALS['eforms_test_mail_calls'] ) ) {
            $GLOBALS['eforms_test_mail_calls'] = array();
        }

        $GLOBALS['eforms_test_mail_calls'][] = array(
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'attachments' => $attachments,
        );

        return isset( $GLOBALS['eforms_test_mail_return'] ) ? (bool) $GLOBALS['eforms_test_mail_return'] : true;
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

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) {
        return preg_replace( '/[\\x00-\\x1F\\x7F]/', '', (string) $value );
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
        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ) {
        return $capability === 'manage_options' && ! empty( $GLOBALS['eforms_test_can_manage'] );
    }
}

if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '' ) {
        throw new RuntimeException( (string) $message );
    }
}

if ( ! function_exists( 'eforms_test_reset_options' ) ) {
    function eforms_test_reset_options() {
        $GLOBALS['eforms_test_options'] = array();
        $GLOBALS['eforms_test_option_autoload'] = array();
    }
}

if ( ! isset( $GLOBALS['eforms_test_options'] ) || ! is_array( $GLOBALS['eforms_test_options'] ) ) {
    eforms_test_reset_options();
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) {
        if ( $name === 'admin_email' ) {
            return 'admin@example.com';
        }

        if ( isset( $GLOBALS['eforms_test_options'] ) && array_key_exists( $name, $GLOBALS['eforms_test_options'] ) ) {
            return $GLOBALS['eforms_test_options'][ $name ];
        }

        return $default;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
        if ( ! isset( $GLOBALS['eforms_test_options'] ) || ! is_array( $GLOBALS['eforms_test_options'] ) ) {
            eforms_test_reset_options();
        }

        if ( array_key_exists( $name, $GLOBALS['eforms_test_options'] ) ) {
            return false;
        }

        $GLOBALS['eforms_test_options'][ $name ] = $value;
        $GLOBALS['eforms_test_option_autoload'][ $name ] = $autoload;
        return true;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $name, $value, $autoload = null ) {
        if ( ! isset( $GLOBALS['eforms_test_options'] ) || ! is_array( $GLOBALS['eforms_test_options'] ) ) {
            eforms_test_reset_options();
        }

        $GLOBALS['eforms_test_options'][ $name ] = $value;
        if ( $autoload !== null ) {
            $GLOBALS['eforms_test_option_autoload'][ $name ] = $autoload;
        }
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $name ) {
        if ( isset( $GLOBALS['eforms_test_options'] ) && is_array( $GLOBALS['eforms_test_options'] ) ) {
            unset( $GLOBALS['eforms_test_options'][ $name ] );
        }
        if ( isset( $GLOBALS['eforms_test_option_autoload'] ) && is_array( $GLOBALS['eforms_test_option_autoload'] ) ) {
            unset( $GLOBALS['eforms_test_option_autoload'][ $name ] );
        }
        return true;
    }
}

if ( ! function_exists( 'add_management_page' ) ) {
    function add_management_page( $page_title, $menu_title, $capability, $menu_slug, $callback ) {
        $GLOBALS['eforms_test_management_pages'][] = array(
            'page_title'  => $page_title,
            'menu_title'  => $menu_title,
            'capability'  => $capability,
            'menu_slug'   => $menu_slug,
            'callback'    => $callback,
        );
        return $menu_slug;
    }
}

if ( ! function_exists( 'add_options_page' ) ) {
    function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback ) {
        $GLOBALS['eforms_test_options_pages'][] = array(
            'page_title'  => $page_title,
            'menu_title'  => $menu_title,
            'capability'  => $capability,
            'menu_slug'   => $menu_slug,
            'callback'    => $callback,
        );
        return $menu_slug;
    }
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1, $name = '_wpnonce' ) {
        $nonce = isset( $GLOBALS['eforms_test_nonce'] ) ? (string) $GLOBALS['eforms_test_nonce'] : 'valid-nonce';
        echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $nonce ) . '" />';
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ) {
        $expected = isset( $GLOBALS['eforms_test_nonce'] ) ? (string) $GLOBALS['eforms_test_nonce'] : 'valid-nonce';
        return (string) $nonce === $expected && (string) $action !== '';
    }
}

if ( ! function_exists( 'submit_button' ) ) {
    function submit_button( $text = 'Save Changes' ) {
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html( $text ) . '</button></p>';
    }
}

// ---- Minimal WordPress hook/filter stubs (only when not running under WP) ----

if ( ! isset( $GLOBALS['eforms_test_hooks'] ) || ! is_array( $GLOBALS['eforms_test_hooks'] ) ) {
    $GLOBALS['eforms_test_hooks'] = array(
        'action'    => array(),
        'shortcode' => array(),
        'filter'    => array(),
        'rewrite'   => array(),
        'rest'      => array(),
    );
}

if ( ! isset( $GLOBALS['eforms_test_filters'] ) || ! is_array( $GLOBALS['eforms_test_filters'] ) ) {
    $GLOBALS['eforms_test_filters'] = array();
}

if ( ! function_exists( 'eforms_test_set_filter' ) ) {
    function eforms_test_set_filter( $tag, $callable ) {
        if ( ! is_string( $tag ) || $tag === '' ) {
            return;
        }

        if ( $callable === null ) {
            unset( $GLOBALS['eforms_test_filters'][ $tag ] );
            return;
        }

        $GLOBALS['eforms_test_filters'][ $tag ] = $callable;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        if ( isset( $GLOBALS['eforms_test_filters'][ $tag ] ) && is_callable( $GLOBALS['eforms_test_filters'][ $tag ] ) ) {
            return call_user_func( $GLOBALS['eforms_test_filters'][ $tag ], $value );
        }
        return $value;
    }
}

if ( ! function_exists( 'has_filter' ) ) {
    function has_filter( $tag ) {
        return isset( $GLOBALS['eforms_test_filters'][ $tag ] ) && is_callable( $GLOBALS['eforms_test_filters'][ $tag ] );
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
        if ( ! isset( $GLOBALS['eforms_test_hooks']['action'][ $hook ] ) ) {
            $GLOBALS['eforms_test_hooks']['action'][ $hook ] = array();
        }
        $GLOBALS['eforms_test_hooks']['action'][ $hook ][] = $callback;
        return true;
    }
}

if ( ! function_exists( 'add_shortcode' ) ) {
    function add_shortcode( $tag, $callback ) {
        $GLOBALS['eforms_test_hooks']['shortcode'][ $tag ] = $callback;
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
        if ( ! isset( $GLOBALS['eforms_test_hooks']['filter'][ $hook ] ) ) {
            $GLOBALS['eforms_test_hooks']['filter'][ $hook ] = array();
        }
        $GLOBALS['eforms_test_hooks']['filter'][ $hook ][] = $callback;
        return true;
    }
}

if ( ! function_exists( 'add_rewrite_rule' ) ) {
    function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
        $GLOBALS['eforms_test_hooks']['rewrite'][] = array( $regex, $query, $after );
        return true;
    }
}

if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
        $GLOBALS['eforms_test_hooks']['rest'][] = array( $namespace, $route, $args );
        return true;
    }
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
    function rest_ensure_response( $response ) {
        return $response;
    }
}

// ---- Default Logging stub (prevents noisy error_log output in tests) ----

if ( ! class_exists( 'Logging' ) ) {
    class Logging {
        public static $events = array();
        private static $request_id = '';

        public static function reset_for_tests() {
            self::$events = array();
            self::$request_id = '';
        }

        public static function event( $severity, $code, $meta, $request = null ) {
            self::$events[] = array(
                'severity' => $severity,
                'code'     => $code,
                'meta'     => $meta,
            );
        }

        public static function request_id( $request = null ) {
            if ( is_array( $request ) && isset( $request['request_id'] ) && is_string( $request['request_id'] ) ) {
                return self::clean_request_id( $request['request_id'] );
            }

            foreach ( array( 'X-Eforms-Request-Id', 'X-Request-Id', 'X-Correlation-Id' ) as $name ) {
                if ( isset( $request['headers'] ) && is_array( $request['headers'] ) && isset( $request['headers'][ $name ] ) && is_string( $request['headers'][ $name ] ) ) {
                    return self::clean_request_id( $request['headers'][ $name ] );
                }
            }

            if ( self::$request_id === '' ) {
                self::$request_id = 'test-request-id';
            }
            return self::$request_id;
        }

        public static function remember_descriptors( $descriptors ) {}

        private static function clean_request_id( $value ) {
            $value = preg_replace( '/[\\x00-\\x1F\\x7F]/', ' ', trim( $value ) );
            $value = preg_replace( '/\\s+/', ' ', $value );
            return is_string( $value ) ? substr( trim( $value ), 0, 128 ) : '';
        }
    }
}
