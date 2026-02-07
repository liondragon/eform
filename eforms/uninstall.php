<?php
/**
 * Uninstall cleanup entrypoint.
 *
 * Spec: Architecture and file layout (docs/Canonical_Spec.md#sec-architecture)
 * Spec: Configuration (docs/Canonical_Spec.md#sec-configuration)
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Uploads/PrivateDir.php';

if ( ! function_exists( 'eforms_uninstall_get_bool' ) ) {
    /**
     * Read a nested boolean-ish value from config.
     *
     * @param array $config
     * @param array $segments
     * @return bool
     */
    function eforms_uninstall_get_bool( $config, $segments ) {
        if ( ! is_array( $config ) || ! is_array( $segments ) ) {
            return false;
        }

        $cursor = $config;
        foreach ( $segments as $segment ) {
            if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
                return false;
            }
            $cursor = $cursor[ $segment ];
        }

        if ( is_bool( $cursor ) ) {
            return $cursor;
        }
        if ( is_numeric( $cursor ) ) {
            return (int) $cursor !== 0;
        }
        if ( is_string( $cursor ) ) {
            $value = strtolower( trim( $cursor ) );
            if ( $value === '' ) {
                return false;
            }

            return ! in_array( $value, array( '0', 'false', 'off', 'no' ), true );
        }

        return false;
    }
}

if ( ! function_exists( 'eforms_uninstall_remove_tree' ) ) {
    /**
     * Remove a file/dir tree recursively.
     *
     * @param string $path
     * @return void
     */
    function eforms_uninstall_remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
            return;
        }

        $entries = @scandir( $path );
        if ( ! is_array( $entries ) ) {
            return;
        }

        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            eforms_uninstall_remove_tree( rtrim( $path, '/\\' ) . '/' . $entry );
        }

        @rmdir( $path );
    }
}

if ( ! function_exists( 'eforms_uninstall_try_remove_empty_chain' ) ) {
    /**
     * Remove empty directories up to, but not including, the stop path.
     *
     * @param string $start
     * @param string $stop
     * @return void
     */
    function eforms_uninstall_try_remove_empty_chain( $start, $stop ) {
        if ( ! is_string( $start ) || $start === '' || ! is_string( $stop ) || $stop === '' ) {
            return;
        }

        $current = rtrim( $start, '/\\' );
        $stop = rtrim( $stop, '/\\' );

        while ( $current !== '' && $current !== $stop && is_dir( $current ) ) {
            $entries = @scandir( $current );
            if ( ! is_array( $entries ) ) {
                break;
            }

            $children = array_diff( $entries, array( '.', '..' ) );
            if ( ! empty( $children ) ) {
                break;
            }

            if ( ! @rmdir( $current ) ) {
                break;
            }

            $parent = dirname( $current );
            if ( ! is_string( $parent ) || $parent === $current ) {
                break;
            }
            $current = rtrim( $parent, '/\\' );
        }
    }
}

if ( ! function_exists( 'eforms_uninstall_is_absolute_path' ) ) {
    /**
     * Check whether the provided path is absolute.
     *
     * @param string $path
     * @return bool
     */
    function eforms_uninstall_is_absolute_path( $path ) {
        if ( ! is_string( $path ) || $path === '' ) {
            return false;
        }

        if ( $path[0] === '/' || $path[0] === '\\' ) {
            return true;
        }

        return preg_match( '/^[A-Za-z]:[\\\\\\/]/', $path ) === 1;
    }
}

if ( ! function_exists( 'eforms_uninstall_fail2ban_path' ) ) {
    /**
     * Resolve fail2ban file path from config.
     *
     * @param array $config
     * @param string $uploads_dir
     * @return string
     */
    function eforms_uninstall_fail2ban_path( $config, $uploads_dir ) {
        if ( ! is_array( $config ) ) {
            return '';
        }

        $file = '';
        if ( isset( $config['logging']['fail2ban']['file'] ) && is_string( $config['logging']['fail2ban']['file'] ) ) {
            $file = trim( $config['logging']['fail2ban']['file'] );
        }

        if ( $file === '' ) {
            return '';
        }

        if ( eforms_uninstall_is_absolute_path( $file ) ) {
            return $file;
        }

        if ( ! is_string( $uploads_dir ) || $uploads_dir === '' ) {
            return '';
        }

        return rtrim( $uploads_dir, '/\\' ) . '/' . ltrim( $file, '/\\' );
    }
}

if ( ! function_exists( 'eforms_uninstall_remove_fail2ban_family' ) ) {
    /**
     * Remove fail2ban file and its rotated siblings.
     *
     * @param string $file_path
     * @param string $uploads_dir
     * @return void
     */
    function eforms_uninstall_remove_fail2ban_family( $file_path, $uploads_dir ) {
        if ( ! is_string( $file_path ) || $file_path === '' ) {
            return;
        }

        $dir = dirname( $file_path );
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $base = basename( $file_path );
        $entries = @scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return;
        }

        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            if ( $entry !== $base && strpos( $entry, $base . '.' ) !== 0 ) {
                continue;
            }

            $candidate = rtrim( $dir, '/\\' ) . '/' . $entry;
            if ( is_file( $candidate ) || is_link( $candidate ) ) {
                @unlink( $candidate );
            }
        }

        eforms_uninstall_try_remove_empty_chain( $dir, rtrim( (string) $uploads_dir, '/\\' ) );
    }
}

if ( ! function_exists( 'eforms_uninstall_ensure_wp_upload_dir' ) ) {
    /**
     * Ensure wp_upload_dir() is callable in uninstall context.
     *
     * @return bool
     */
    function eforms_uninstall_ensure_wp_upload_dir() {
        if ( function_exists( 'wp_upload_dir' ) ) {
            return true;
        }

        if ( defined( 'ABSPATH' ) ) {
            $file_api = rtrim( (string) ABSPATH, '/\\' ) . '/wp-admin/includes/file.php';
            if ( is_readable( $file_api ) ) {
                require_once $file_api;
            }
        }

        return function_exists( 'wp_upload_dir' );
    }
}

if ( ! function_exists( 'eforms_uninstall_run' ) ) {
    /**
     * Execute uninstall cleanup respecting purge flags.
     *
     * @return void
     */
    function eforms_uninstall_run() {
        if ( ! eforms_uninstall_ensure_wp_upload_dir() ) {
            return;
        }

        Config::bootstrap();
        $config = Config::get();

        $purge_logs = eforms_uninstall_get_bool( $config, array( 'install', 'uninstall', 'purge_logs' ) );
        $purge_uploads = eforms_uninstall_get_bool( $config, array( 'install', 'uninstall', 'purge_uploads' ) );
        if ( ! $purge_logs && ! $purge_uploads ) {
            return;
        }

        $uploads_dir = '';
        if ( isset( $config['uploads']['dir'] ) && is_string( $config['uploads']['dir'] ) ) {
            $uploads_dir = rtrim( $config['uploads']['dir'], '/\\' );
        }
        if ( $uploads_dir === '' ) {
            $uploads = wp_upload_dir();
            if ( is_array( $uploads ) && isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ) {
                $uploads_dir = rtrim( $uploads['basedir'], '/\\' );
            }
        }

        if ( $uploads_dir === '' || ! is_dir( $uploads_dir ) ) {
            return;
        }

        $private_dir = PrivateDir::path( $uploads_dir );
        $private_exists = $private_dir !== '' && is_dir( $private_dir );

        if ( $purge_uploads && $private_exists ) {
            eforms_uninstall_remove_tree( $private_dir . '/tokens' );
            eforms_uninstall_remove_tree( $private_dir . '/ledger' );
            eforms_uninstall_remove_tree( $private_dir . '/uploads' );
            eforms_uninstall_remove_tree( $private_dir . '/throttle' );
        }

        if ( $purge_logs ) {
            if ( $private_exists ) {
                eforms_uninstall_remove_tree( $private_dir . '/logs' );
                eforms_uninstall_remove_tree( $private_dir . '/f2b' );
            }

            $fail2ban_file = eforms_uninstall_fail2ban_path( $config, $uploads_dir );
            eforms_uninstall_remove_fail2ban_family( $fail2ban_file, $uploads_dir );
        }

        if ( $private_exists ) {
            eforms_uninstall_try_remove_empty_chain( $private_dir, $uploads_dir );
        }
    }
}

eforms_uninstall_run();
