<?php
/**
 * Compatibility guards for minimum platform versions and filesystem semantics.
 *
 * Educational note: the plugin stores runtime artifacts under wp_upload_dir().
 * On multi-webhead/container deployments that directory must be a shared,
 * persistent volume that preserves atomic rename and exclusive-create semantics.
 *
 * Contract: Compatibility and updates
 * Contract: Shared lifecycle and storage contract
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Security/Entropy.php';

class Compat {
    private static $bootstrapped = false;
    private static $failure      = null;

    /**
     * Guard the plugin bootstrap and register admin UX for incompatibilities.
     *
     * @param string $plugin_file The main plugin file path.
     * @return bool True when safe to proceed with plugin bootstrap.
     */
    public static function guard( $plugin_file ) {
        if ( self::$bootstrapped ) {
            return self::$failure === null;
        }
        self::$bootstrapped = true;

        if ( ! self::is_wordpress_runtime() ) {
            return true;
        }

        $failure = self::detect_failure();
        if ( $failure === null ) {
            return true;
        }

        self::$failure = $failure;
        self::register_admin_notice();
        self::schedule_deactivate( $plugin_file );

        return false;
    }

    /**
     * Expose fixed minimum requirements (from code defaults; not user-configurable).
     */
    public static function requirements() {
        $defaults = Config::DEFAULTS;

        return array(
            'min_php_version' => isset( $defaults['install']['min_php_version'] ) ? (string) $defaults['install']['min_php_version'] : '',
            'min_wp_version'  => isset( $defaults['install']['min_wp_version'] ) ? (string) $defaults['install']['min_wp_version'] : '',
        );
    }

    /**
     * Compare two semantic versions (e.g., "5.8") using version_compare.
     */
    public static function version_meets_min( $current, $min ) {
        if ( ! is_string( $current ) || $current === '' ) {
            return false;
        }
        if ( ! is_string( $min ) || $min === '' ) {
            return true;
        }

        return version_compare( $current, $min, '>=' );
    }

    private static function is_wordpress_runtime() {
        return defined( 'ABSPATH' );
    }

    private static function detect_failure() {
        $req = self::requirements();

        $current_php = PHP_VERSION;
        if ( ! self::version_meets_min( $current_php, $req['min_php_version'] ) ) {
            return array(
                'code'        => 'EFORMS_COMPAT_PHP_VERSION',
                'current'     => $current_php,
                'minimum'     => $req['min_php_version'],
                'description' => 'This plugin requires a newer PHP version.',
            );
        }

        $current_wp = self::current_wp_version();
        if ( $current_wp !== null && ! self::version_meets_min( $current_wp, $req['min_wp_version'] ) ) {
            return array(
                'code'        => 'EFORMS_COMPAT_WP_VERSION',
                'current'     => $current_wp,
                'minimum'     => $req['min_wp_version'],
                'description' => 'This plugin requires a newer WordPress version.',
            );
        }

        return null;
    }

    private static function current_wp_version() {
        if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && $GLOBALS['wp_version'] !== '' ) {
            return $GLOBALS['wp_version'];
        }

        if ( function_exists( 'get_bloginfo' ) ) {
            $value = get_bloginfo( 'version' );
            if ( is_string( $value ) && $value !== '' ) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Return null when semantics appear supported; otherwise return a human-readable reason.
     */
    public static function probe_uploads_semantics( $uploads_dir ) {
        if ( ! is_string( $uploads_dir ) || $uploads_dir === '' ) {
            return 'Uploads directory is missing.';
        }

        if ( ! is_dir( $uploads_dir ) ) {
            return 'Uploads directory does not exist.';
        }

        if ( ! is_writable( $uploads_dir ) ) {
            return 'Uploads directory is not writable.';
        }

        $salt = self::probe_salt();
        $probe_dir = rtrim( $uploads_dir, '/\\' ) . '/.eforms-compat-probe-' . $salt;
        if ( file_exists( $probe_dir ) || is_link( $probe_dir ) || ! @mkdir( $probe_dir, 0700 ) ) {
            return 'Unable to create a probe directory under uploads.';
        }

        $tmp   = $probe_dir . '/probe-' . $salt . '.tmp';
        $final = $probe_dir . '/probe-' . $salt . '.final';

        $written = @file_put_contents( $tmp, 'eforms-compat' );
        if ( $written === false ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final ) );
            return 'Unable to write files under uploads.';
        }

        $renamed = @rename( $tmp, $final );
        if ( ! $renamed || ! file_exists( $final ) ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final ) );
            return 'Atomic rename under uploads appears unsupported.';
        }

        $xb_path = $probe_dir . '/probe-' . $salt . '.xb';
        $handle  = @fopen( $xb_path, 'xb' );
        if ( $handle === false ) {
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'Exclusive-create (fopen xb) under uploads appears unsupported.';
        }
        fclose( $handle );

        $second = @fopen( $xb_path, 'xb' );
        if ( $second !== false ) {
            fclose( $second );
            self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );
            return 'Exclusive-create semantics are not enforced under uploads.';
        }

        self::probe_cleanup( $probe_dir, array( $tmp, $final, $xb_path ) );

        return null;
    }

    private static function probe_salt() {
        $salt = Entropy::hex( 4 );
        if ( $salt !== '' ) {
            return $salt;
        }

        return (string) getmypid();
    }

    private static function probe_cleanup( $probe_dir, $paths ) {
        foreach ( $paths as $path ) {
            if ( is_string( $path ) && $path !== '' && file_exists( $path ) ) {
                @unlink( $path );
            }
        }

        if ( is_dir( $probe_dir ) ) {
            @rmdir( $probe_dir );
        }
    }

    private static function register_admin_notice() {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }

        add_action(
            'admin_notices',
            function () {
                if ( self::$failure === null ) {
                    return;
                }

                $message = self::$failure['description'];
                if ( isset( self::$failure['minimum'] ) && self::$failure['minimum'] !== '' ) {
                    $message .= ' Minimum required: ' . self::$failure['minimum'] . '.';
                }

                if ( function_exists( 'esc_html' ) ) {
                    $message = esc_html( $message );
                }

                echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
            }
        );
    }

    private static function schedule_deactivate( $plugin_file ) {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }

        add_action(
            'admin_init',
            function () use ( $plugin_file ) {
                if ( self::$failure === null ) {
                    return;
                }

                if ( ! function_exists( 'deactivate_plugins' ) ) {
                    return;
                }

                $basename = $plugin_file;
                if ( function_exists( 'plugin_basename' ) ) {
                    $basename = plugin_basename( $plugin_file );
                }

                deactivate_plugins( $basename, true );
            },
            1
        );
    }
}
