<?php
/**
 * Fail2ban emission sink with rotation and retention.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/FileSink.php';

class Fail2banLogger {
    private static $max_bytes_override = null;

    /**
     * Emit one fail2ban line when enabled and applicable.
     *
     * @param string $code
     * @param array $meta
     * @param string $raw_ip
     * @param array $config
     * @return bool
     */
    public static function emit( $code, $meta, $raw_ip, $config ) {
        if ( ! self::enabled( $config ) ) {
            return false;
        }

        if ( ! is_string( $code ) || strpos( $code, 'EFORMS_ERR_' ) !== 0 ) {
            return false;
        }

        $ip = self::normalize_ip( $raw_ip );
        if ( $ip === '' ) {
            return false;
        }

        $path = self::target_path( $config );
        if ( $path === '' ) {
            return false;
        }

        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
            return false;
        }
        if ( ! @chmod( $dir, 0700 ) ) {
            return false;
        }

        self::prune_old_files( $path, self::retention_days( $config ) );

        $form_id = '';
        if ( is_array( $meta ) && isset( $meta['form_id'] ) && is_scalar( $meta['form_id'] ) ) {
            $form_id = self::safe_token( (string) $meta['form_id'] );
        }

        $line = sprintf(
            "eforms[f2b] ts=%d code=%s ip=%s form=%s\n",
            time(),
            self::safe_token( $code ),
            $ip,
            $form_id
        );

        return FileSink::append_with_rotation(
            $path,
            $line,
            self::max_bytes(),
            function ( $current ) use ( $path ) {
                return self::next_rotated_path( $path );
            }
        );
    }

    /**
     * Test helper to make rotation deterministic.
     *
     * @param int|null $bytes
     * @return void
     */
    public static function set_max_bytes_for_tests( $bytes ) {
        if ( $bytes === null ) {
            self::$max_bytes_override = null;
            return;
        }

        $value = (int) $bytes;
        self::$max_bytes_override = $value > 0 ? $value : 1;
    }

    public static function reset_for_tests() {
        self::$max_bytes_override = null;
    }

    private static function enabled( $config ) {
        $target = Config::value( $config, array( 'logging', 'fail2ban', 'target' ), '' );
        $file = Config::value( $config, array( 'logging', 'fail2ban', 'file' ), '' );
        return is_string( $target ) && $target === 'file' && is_string( $file ) && trim( $file ) !== '';
    }

    private static function target_path( $config ) {
        $file = Config::value( $config, array( 'logging', 'fail2ban', 'file' ), '' );
        if ( ! is_string( $file ) ) {
            return '';
        }

        $file = trim( $file );
        if ( $file === '' ) {
            return '';
        }

        if ( self::is_absolute_path( $file ) ) {
            return $file;
        }

        $uploads_dir = Config::value( $config, array( 'uploads', 'dir' ), '' );
        if ( ! is_string( $uploads_dir ) || trim( $uploads_dir ) === '' ) {
            return '';
        }

        return rtrim( $uploads_dir, '/\\' ) . '/' . ltrim( $file, '/\\' );
    }

    private static function retention_days( $config ) {
        $value = Config::value( $config, array( 'logging', 'fail2ban', 'retention_days' ), 1 );
        if ( ! is_numeric( $value ) ) {
            return 1;
        }

        $days = (int) $value;
        return $days > 0 ? $days : 1;
    }

    private static function max_bytes() {
        if ( is_int( self::$max_bytes_override ) && self::$max_bytes_override > 0 ) {
            return self::$max_bytes_override;
        }

        return FileSink::DEFAULT_MAX_BYTES;
    }

    private static function next_rotated_path( $path ) {
        $index = 1;
        while ( $index < 10000 ) {
            $candidate = $path . '.' . $index;
            if ( ! file_exists( $candidate ) ) {
                return $candidate;
            }

            $index++;
        }

        return '';
    }

    private static function prune_old_files( $path, $retention_days ) {
        $dir = dirname( $path );
        $base = basename( $path );
        FileSink::prune_old_files(
            $dir,
            $retention_days,
            function ( $entry ) use ( $base ) {
                return is_string( $entry ) && ( $entry === $base || strpos( $entry, $base . '.' ) === 0 );
            }
        );
    }

    private static function normalize_ip( $ip ) {
        if ( ! is_string( $ip ) ) {
            return '';
        }

        $ip = trim( $ip );
        if ( $ip === '' ) {
            return '';
        }

        return filter_var( $ip, FILTER_VALIDATE_IP ) !== false ? $ip : '';
    }

    private static function safe_token( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = preg_replace( '/[^A-Za-z0-9_.:-]/', '_', $value );
        return is_string( $value ) ? $value : '';
    }

    private static function is_absolute_path( $path ) {
        if ( $path === '' ) {
            return false;
        }

        if ( $path[0] === '/' || $path[0] === '\\' ) {
            return true;
        }

        return preg_match( '/^[A-Za-z]:[\\\\\\/]/', $path ) === 1;
    }

}
