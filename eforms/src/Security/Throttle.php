<?php
/**
 * File-based per-IP throttling helpers.
 *
 * Spec: Throttling (docs/Canonical_Spec.md#sec-throttling)
 * Spec: Security (docs/Canonical_Spec.md#sec-security)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';
if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}

class Throttle {
    const WINDOW_SECONDS = 60;
    const THROTTLE_DIR = 'throttle';
    const TALLY_SUFFIX = '.tally';
    const COOLDOWN_SUFFIX = '.cooldown';

    /**
     * Evaluate a throttle check for the resolved client IP.
     *
     * @param mixed $request Optional request object/array.
     * @param array|null $config Optional config snapshot override.
     * @param string|null $uploads_dir Optional uploads dir override (tests).
     * @return array { ok, code, retry_after?, reason? }
     */
    public static function check( $request = null, $config = null, $uploads_dir = null ) {
        $config = is_array( $config ) ? $config : Config::get();
        if ( ! self::config_bool( $config, array( 'throttle', 'enable' ), false ) ) {
            return array( 'ok' => true, 'code' => 'disabled' );
        }

        $ip_hash = self::resolve_ip_hash( $request, $config );
        if ( $ip_hash === '' ) {
            // Spec gate: run throttle only when a key is available.
            return array( 'ok' => true, 'code' => 'key_unavailable' );
        }

        $uploads_dir = self::resolve_uploads_dir( $uploads_dir, $config );
        if ( $uploads_dir === '' || ! is_dir( $uploads_dir ) || ! is_writable( $uploads_dir ) ) {
            return array( 'ok' => false, 'code' => 'storage', 'reason' => 'uploads_dir_unavailable' );
        }

        $private = PrivateDir::ensure( $uploads_dir );
        if ( ! is_array( $private ) || empty( $private['ok'] ) ) {
            return array( 'ok' => false, 'code' => 'storage', 'reason' => 'private_dir_unavailable' );
        }

        $max_per_minute = self::config_int( $config, array( 'throttle', 'per_ip', 'max_per_minute' ), 0 );
        if ( $max_per_minute <= 0 ) {
            return array( 'ok' => true, 'code' => 'disabled' );
        }

        $cooldown_seconds = self::config_int( $config, array( 'throttle', 'per_ip', 'cooldown_seconds' ), 0 );
        if ( $cooldown_seconds < 0 ) {
            $cooldown_seconds = 0;
        }

        $now = time();
        $window_start = (int) ( floor( $now / self::WINDOW_SECONDS ) * self::WINDOW_SECONDS );
        $window_end = $window_start + self::WINDOW_SECONDS;

        $throttle_dir = rtrim( $private['path'], '/\\' ) . '/' . self::THROTTLE_DIR;
        if ( ! self::ensure_dir( $throttle_dir ) ) {
            return array( 'ok' => false, 'code' => 'storage', 'reason' => 'throttle_dir_unavailable' );
        }

        $shard_dir = $throttle_dir . '/' . Helpers::h2( $ip_hash );
        if ( ! self::ensure_dir( $shard_dir ) ) {
            return array( 'ok' => false, 'code' => 'storage', 'reason' => 'throttle_shard_unavailable' );
        }

        $cooldown_path = $shard_dir . '/' . $ip_hash . self::COOLDOWN_SUFFIX;
        if ( $cooldown_seconds > 0 ) {
            $sentinel_mtime = self::cooldown_mtime( $cooldown_path );
            if ( $sentinel_mtime !== null && $sentinel_mtime > ( $now - $cooldown_seconds ) ) {
                $cooldown_remaining = max( 0, ( $sentinel_mtime + $cooldown_seconds ) - $now );
                $retry_after = self::retry_after( $window_end, $now, $cooldown_remaining );
                self::log_throttled( $request, 'cooldown_fast_path', $retry_after );
                return array( 'ok' => false, 'code' => 'throttled', 'retry_after' => $retry_after );
            }
        }

        $tally_path = $shard_dir . '/' . $ip_hash . self::TALLY_SUFFIX;
        $handle = @fopen( $tally_path, 'c+b' );
        if ( $handle === false ) {
            return array( 'ok' => false, 'code' => 'storage', 'reason' => 'tally_open_failed' );
        }

        if ( ! flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            self::log_lock_failure( $request );
            return array( 'ok' => true, 'code' => 'lock_failed' );
        }

        $stats = fstat( $handle );
        $size = is_array( $stats ) && isset( $stats['size'] ) ? (int) $stats['size'] : 0;
        $mtime = is_array( $stats ) && isset( $stats['mtime'] ) ? (int) $stats['mtime'] : 0;

        if ( $mtime < $window_start ) {
            if ( ! ftruncate( $handle, 0 ) ) {
                flock( $handle, LOCK_UN );
                fclose( $handle );
                return array( 'ok' => false, 'code' => 'storage', 'reason' => 'tally_reset_failed' );
            }
            $size = 0;
        }

        if ( $size >= $max_per_minute ) {
            if ( $cooldown_seconds > 0 ) {
                @touch( $cooldown_path );
                @chmod( $cooldown_path, 0600 );
            }
            flock( $handle, LOCK_UN );
            fclose( $handle );
            $cooldown_remaining = self::cooldown_remaining( $cooldown_path, $cooldown_seconds, $now );
            $retry_after = self::retry_after( $window_end, $now, $cooldown_remaining );
            self::log_throttled( $request, 'window_limit', $retry_after );
            return array( 'ok' => false, 'code' => 'throttled', 'retry_after' => $retry_after );
        }

        if ( fseek( $handle, 0, SEEK_END ) !== 0 ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );
            return array( 'ok' => false, 'code' => 'storage', 'reason' => 'tally_seek_failed' );
        }

        $written = @fwrite( $handle, '1' );
        if ( $written !== 1 ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );
            return array( 'ok' => false, 'code' => 'storage', 'reason' => 'tally_write_failed' );
        }

        if ( function_exists( 'fflush' ) ) {
            @fflush( $handle );
        }
        @chmod( $tally_path, 0600 );

        flock( $handle, LOCK_UN );
        fclose( $handle );

        return array( 'ok' => true, 'code' => 'allowed' );
    }

    private static function retry_after( $window_end, $now, $cooldown_remaining ) {
        $window_remaining = (int) $window_end - (int) $now;
        return max( 1, $window_remaining, (int) $cooldown_remaining );
    }

    private static function cooldown_remaining( $cooldown_path, $cooldown_seconds, $now ) {
        if ( $cooldown_seconds <= 0 ) {
            return 0;
        }

        $mtime = self::cooldown_mtime( $cooldown_path );
        if ( $mtime === null ) {
            return 0;
        }

        return max( 0, ( $mtime + $cooldown_seconds ) - $now );
    }

    private static function cooldown_mtime( $cooldown_path ) {
        clearstatcache( true, $cooldown_path );
        if ( ! file_exists( $cooldown_path ) ) {
            return null;
        }

        $mtime = @filemtime( $cooldown_path );
        if ( ! is_int( $mtime ) ) {
            return null;
        }

        return $mtime;
    }

    private static function resolve_ip_hash( $request, $config ) {
        try {
            $key = Helpers::throttle_key( $request, $config );
        } catch ( Throwable $e ) {
            $key = '';
        }

        if ( ! is_string( $key ) || $key === '' ) {
            $fallback_ip = '';
            if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
                $fallback_ip = trim( $_SERVER['REMOTE_ADDR'] );
            }

            if ( $fallback_ip !== '' ) {
                try {
                    $key = Helpers::throttle_key( $fallback_ip, $config );
                } catch ( Throwable $e ) {
                    $key = '';
                }
            }
        }

        if ( ! is_string( $key ) || $key === '' ) {
            return '';
        }

        return $key;
    }

    private static function resolve_uploads_dir( $uploads_dir, $config ) {
        if ( is_string( $uploads_dir ) && $uploads_dir !== '' ) {
            return rtrim( $uploads_dir, '/\\' );
        }

        if ( is_array( $config ) && isset( $config['uploads'] ) && is_array( $config['uploads'] ) ) {
            if ( isset( $config['uploads']['dir'] ) && is_string( $config['uploads']['dir'] ) ) {
                return rtrim( $config['uploads']['dir'], '/\\' );
            }
        }

        return '';
    }

    private static function ensure_dir( $path ) {
        if ( is_dir( $path ) ) {
            return @chmod( $path, 0700 );
        }

        $created = @mkdir( $path, 0700, true );
        if ( ! $created && ! is_dir( $path ) ) {
            return false;
        }

        return @chmod( $path, 0700 );
    }

    private static function log_lock_failure( $request ) {
        if ( ! class_exists( 'Logging' ) ) {
            return;
        }

        Logging::event( 'warning', 'EFORMS_ERR_THROTTLED', array( 'reason' => 'throttle_lock_failed' ), $request );
    }

    private static function log_throttled( $request, $reason, $retry_after ) {
        if ( ! class_exists( 'Logging' ) ) {
            return;
        }

        Logging::event(
            'warning',
            'EFORMS_ERR_THROTTLED',
            array(
                'reason' => (string) $reason,
                'retry_after' => (int) $retry_after,
            ),
            $request
        );
    }

    private static function config_int( $config, $path, $default ) {
        $value = self::config_value( $config, $path );
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }

        return $default;
    }

    private static function config_bool( $config, $path, $default ) {
        $value = self::config_value( $config, $path );
        if ( is_bool( $value ) ) {
            return $value;
        }

        return $default;
    }

    private static function config_value( $config, $path ) {
        if ( ! is_array( $path ) ) {
            return null;
        }

        $cursor = $config;
        foreach ( $path as $segment ) {
            if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
                return null;
            }
            $cursor = $cursor[ $segment ];
        }

        return $cursor;
    }
}
