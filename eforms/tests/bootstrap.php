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

if ( ! function_exists( 'is_email' ) ) {
    function is_email( $email ) {
        if ( ! is_string( $email ) || $email === '' ) {
            return false;
        }

        return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
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

        public static function reset() {
            self::$events = array();
        }

        public static function event( $severity, $code, $meta ) {
            self::$events[] = array(
                'severity' => $severity,
                'code'     => $code,
                'meta'     => $meta,
            );
        }
    }
}
