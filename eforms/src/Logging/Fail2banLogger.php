<?php
/**
 * Fail2ban emission sink with rotation and retention.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

class Fail2banLogger {
    const DEFAULT_MAX_BYTES = 1048576; // Internal cap; not user-configurable.

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
        if ( ! self::ensure_dir( $dir ) ) {
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

        return self::append_with_rotation( $path, $line, self::max_bytes() );
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
        $target = self::config_value( $config, array( 'logging', 'fail2ban', 'target' ), '' );
        $file = self::config_value( $config, array( 'logging', 'fail2ban', 'file' ), '' );
        return is_string( $target ) && $target === 'file' && is_string( $file ) && trim( $file ) !== '';
    }

    private static function target_path( $config ) {
        $file = self::config_value( $config, array( 'logging', 'fail2ban', 'file' ), '' );
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

        $uploads_dir = self::config_value( $config, array( 'uploads', 'dir' ), '' );
        if ( ! is_string( $uploads_dir ) || trim( $uploads_dir ) === '' ) {
            return '';
        }

        return rtrim( $uploads_dir, '/\\' ) . '/' . ltrim( $file, '/\\' );
    }

    private static function retention_days( $config ) {
        $value = self::config_value( $config, array( 'logging', 'fail2ban', 'retention_days' ), 1 );
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

        return self::DEFAULT_MAX_BYTES;
    }

    private static function append_with_rotation( $path, $line, $max_bytes ) {
        $active = self::active_path( $path, $max_bytes );
        if ( $active === '' ) {
            return false;
        }

        $handle = @fopen( $active, 'ab' );
        if ( $handle === false ) {
            return false;
        }

        if ( ! flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            return false;
        }

        clearstatcache( true, $active );
        $size = @filesize( $active );
        $size = is_numeric( $size ) ? (int) $size : 0;
        if ( $size >= $max_bytes ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );

            $active = self::next_rotated_path( $path );
            if ( $active === '' ) {
                return false;
            }

            return self::append_with_rotation( $active, $line, $max_bytes );
        }

        $written = @fwrite( $handle, $line );
        if ( function_exists( 'fflush' ) ) {
            @fflush( $handle );
        }
        @chmod( $active, 0600 );
        flock( $handle, LOCK_UN );
        fclose( $handle );

        return is_int( $written ) && $written === strlen( $line );
    }

    private static function active_path( $path, $max_bytes ) {
        if ( ! file_exists( $path ) ) {
            return $path;
        }

        $size = @filesize( $path );
        if ( is_numeric( $size ) && (int) $size < $max_bytes ) {
            return $path;
        }

        return self::next_rotated_path( $path );
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
        $entries = @scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return;
        }

        $cutoff = time() - ( (int) $retention_days * 86400 );
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            if ( $entry !== $base && strpos( $entry, $base . '.' ) !== 0 ) {
                continue;
            }

            $candidate = rtrim( $dir, '/\\' ) . '/' . $entry;
            if ( ! is_file( $candidate ) ) {
                continue;
            }

            $mtime = @filemtime( $candidate );
            if ( ! is_int( $mtime ) ) {
                continue;
            }

            if ( $mtime < $cutoff ) {
                @unlink( $candidate );
            }
        }
    }

    private static function ensure_dir( $dir ) {
        if ( is_dir( $dir ) ) {
            return @chmod( $dir, 0700 );
        }

        $created = @mkdir( $dir, 0700, true );
        if ( ! $created && ! is_dir( $dir ) ) {
            return false;
        }

        return @chmod( $dir, 0700 );
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

    private static function config_value( $config, $path, $fallback ) {
        if ( ! is_array( $config ) || ! is_array( $path ) ) {
            return $fallback;
        }

        $cursor = $config;
        foreach ( $path as $segment ) {
            if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
                return $fallback;
            }
            $cursor = $cursor[ $segment ];
        }

        return $cursor;
    }
}
