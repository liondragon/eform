<?php
/**
 * REST handler for POST /eforms/mint.
 *
 * Spec: JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode)
 * Spec: Throttling (docs/Canonical_Spec.md#sec-throttling)
 * Spec: Security (docs/Canonical_Spec.md#sec-security)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Helpers.php';
if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';
require_once __DIR__ . '/OriginPolicy.php';
require_once __DIR__ . '/PostSize.php';
require_once __DIR__ . '/Security.php';

class MintEndpoint {
    const THROTTLE_WINDOW_SECONDS = 60;

    /**
     * Handle a request and return a response payload.
     *
     * @param mixed $request Request object or array.
     * @return array { status, headers, body }
     */
    public static function handle( $request ) {
        $headers = self::base_headers();

        $method = self::request_method( $request );
        if ( $method !== 'POST' ) {
            $headers['Allow'] = 'POST';
            return self::result( 405, $headers, array( 'error' => 'EFORMS_ERR_METHOD_NOT_ALLOWED' ) );
        }

        $content_type = self::header_value( $request, 'Content-Type' );
        if ( ! self::is_form_urlencoded( $content_type ) ) {
            return self::result( 400, $headers, array( 'error' => 'EFORMS_ERR_TYPE' ) );
        }

        $config = Config::get();
        $cap = PostSize::effective_cap( $content_type, $config );
        $length = self::content_length();
        if ( $length !== null && $length > $cap ) {
            return self::result( 400, $headers, array( 'error' => 'EFORMS_ERR_MINT_FAILED' ) );
        }

        $origin_eval = OriginPolicy::evaluate( $request, $config );
        if ( ! is_array( $origin_eval ) || ! isset( $origin_eval['state'] ) || $origin_eval['state'] !== 'same' ) {
            return self::result( 403, $headers, array( 'error' => 'EFORMS_ERR_ORIGIN_FORBIDDEN' ) );
        }

        $throttle = self::enforce_throttle( $request, $config );
        if ( ! $throttle['ok'] ) {
            if ( isset( $throttle['code'] ) && $throttle['code'] === 'throttled' ) {
                $headers['Retry-After'] = (string) $throttle['retry_after'];
                return self::result( 429, $headers, array( 'error' => 'EFORMS_ERR_THROTTLED' ) );
            }
            return self::result( 500, $headers, array( 'error' => 'EFORMS_ERR_MINT_FAILED' ) );
        }

        $form_id = self::param_value( $request, 'f' );
        if ( ! self::is_valid_form_id( $form_id ) ) {
            return self::result( 400, $headers, array( 'error' => 'EFORMS_ERR_INVALID_FORM_ID' ) );
        }

        $template = TemplateLoader::load( $form_id );
        if ( ! is_array( $template ) || empty( $template['ok'] ) ) {
            return self::result( 400, $headers, array( 'error' => 'EFORMS_ERR_INVALID_FORM_ID' ) );
        }

        $mint = Security::mint_js_record( $form_id );
        if ( ! is_array( $mint ) || empty( $mint['ok'] ) ) {
            $code = is_array( $mint ) && isset( $mint['code'] ) ? $mint['code'] : 'EFORMS_ERR_MINT_FAILED';
            if ( $code === 'EFORMS_ERR_INVALID_FORM_ID' ) {
                return self::result( 400, $headers, array( 'error' => $code ) );
            }
            return self::result( 500, $headers, array( 'error' => 'EFORMS_ERR_MINT_FAILED' ) );
        }

        return self::result(
            200,
            $headers,
            array(
                'token' => $mint['token'],
                'instance_id' => $mint['instance_id'],
                'timestamp' => $mint['issued_at'],
                'expires' => $mint['expires'],
            )
        );
    }

    private static function base_headers() {
        return array(
            'Cache-Control' => 'no-store, max-age=0',
            'Content-Type' => 'application/json; charset=utf-8',
        );
    }

    private static function request_method( $request ) {
        if ( is_object( $request ) && method_exists( $request, 'get_method' ) ) {
            $value = $request->get_method();
            if ( is_string( $value ) && $value !== '' ) {
                return strtoupper( $value );
            }
        }

        if ( is_array( $request ) && isset( $request['method'] ) && is_string( $request['method'] ) ) {
            return strtoupper( $request['method'] );
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ) {
            return strtoupper( $_SERVER['REQUEST_METHOD'] );
        }

        return '';
    }

    private static function content_length() {
        if ( isset( $_SERVER['CONTENT_LENGTH'] ) && is_numeric( $_SERVER['CONTENT_LENGTH'] ) ) {
            return (int) $_SERVER['CONTENT_LENGTH'];
        }

        if ( isset( $_SERVER['HTTP_CONTENT_LENGTH'] ) && is_numeric( $_SERVER['HTTP_CONTENT_LENGTH'] ) ) {
            return (int) $_SERVER['HTTP_CONTENT_LENGTH'];
        }

        return null;
    }

    private static function header_value( $request, $name ) {
        if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
            $value = $request->get_header( $name );
            if ( is_string( $value ) ) {
                return trim( $value );
            }
        }

        if ( is_array( $request ) && isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
            foreach ( $request['headers'] as $key => $value ) {
                if ( is_string( $key ) && strcasecmp( $key, $name ) === 0 && is_string( $value ) ) {
                    return trim( $value );
                }
            }
        }

        $server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        if ( isset( $_SERVER[ $server_key ] ) && is_string( $_SERVER[ $server_key ] ) ) {
            return trim( $_SERVER[ $server_key ] );
        }

        return '';
    }

    private static function param_value( $request, $name ) {
        if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
            $value = $request->get_param( $name );
            if ( is_string( $value ) ) {
                return $value;
            }
        }

        if ( is_array( $request ) && isset( $request['params'] ) && is_array( $request['params'] ) ) {
            if ( isset( $request['params'][ $name ] ) && is_string( $request['params'][ $name ] ) ) {
                return $request['params'][ $name ];
            }
        }

        if ( isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ) {
            return $_POST[ $name ];
        }

        return '';
    }

    private static function is_form_urlencoded( $content_type ) {
        if ( ! is_string( $content_type ) ) {
            return false;
        }

        $content_type = strtolower( trim( $content_type ) );
        if ( $content_type === '' ) {
            return false;
        }

        $semi = strpos( $content_type, ';' );
        if ( $semi !== false ) {
            $content_type = trim( substr( $content_type, 0, $semi ) );
        }

        if ( $content_type === 'application/json' || substr( $content_type, -5 ) === '+json' ) {
            return false;
        }

        return $content_type === 'application/x-www-form-urlencoded';
    }

    private static function is_valid_form_id( $form_id ) {
        return is_string( $form_id ) && $form_id !== '';
    }

    private static function enforce_throttle( $request, $config ) {
        if ( ! self::config_bool( $config, array( 'throttle', 'enable' ), false ) ) {
            return array( 'ok' => true );
        }

        $client_ip = self::resolve_client_ip( $request );
        if ( $client_ip === '' ) {
            Logging::event( 'warning', 'EFORMS_ERR_THROTTLED', array( 'reason' => 'client_ip_missing' ), $request );
            return array( 'ok' => true );
        }

        $uploads_dir = self::uploads_dir( $config );
        if ( $uploads_dir === '' || ! is_dir( $uploads_dir ) || ! is_writable( $uploads_dir ) ) {
            return array( 'ok' => false, 'code' => 'storage' );
        }

        $private = PrivateDir::ensure( $uploads_dir );
        if ( ! is_array( $private ) || empty( $private['ok'] ) ) {
            return array( 'ok' => false, 'code' => 'storage' );
        }

        $now = time();
        $window_start = (int) ( floor( $now / self::THROTTLE_WINDOW_SECONDS ) * self::THROTTLE_WINDOW_SECONDS );
        $window_end = $window_start + self::THROTTLE_WINDOW_SECONDS;

        $ip_hash = Helpers::throttle_key( $client_ip );
        $shard = Helpers::h2( $ip_hash );

        $throttle_dir = rtrim( $private['path'], '/\\' ) . '/throttle';
        if ( ! self::ensure_dir( $throttle_dir ) ) {
            return array( 'ok' => false, 'code' => 'storage' );
        }

        $shard_dir = $throttle_dir . '/' . $shard;
        if ( ! self::ensure_dir( $shard_dir ) ) {
            return array( 'ok' => false, 'code' => 'storage' );
        }

        $max_per_minute = self::config_int( $config, array( 'throttle', 'per_ip', 'max_per_minute' ), 0 );
        if ( $max_per_minute <= 0 ) {
            return array( 'ok' => true );
        }

        $cooldown_seconds = self::config_int( $config, array( 'throttle', 'per_ip', 'cooldown_seconds' ), 0 );
        $cooldown_path = $shard_dir . '/' . $ip_hash . '.cooldown';

        if ( $cooldown_seconds > 0 && file_exists( $cooldown_path ) ) {
            clearstatcache( true, $cooldown_path );
            $mtime = @filemtime( $cooldown_path );
            if ( $mtime !== false && $mtime > ( $now - $cooldown_seconds ) ) {
                $cooldown_remaining = max( 0, ( $mtime + $cooldown_seconds ) - $now );
                $retry_after = self::retry_after( $window_end, $now, $cooldown_remaining );
                return array( 'ok' => false, 'code' => 'throttled', 'retry_after' => $retry_after );
            }
        }

        $tally_path = $shard_dir . '/' . $ip_hash . '.tally';
        $handle = @fopen( $tally_path, 'c+b' );
        if ( $handle === false ) {
            return array( 'ok' => false, 'code' => 'storage' );
        }

        if ( ! flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            Logging::event( 'warning', 'EFORMS_ERR_THROTTLED', array( 'reason' => 'throttle_lock_failed' ), $request );
            return array( 'ok' => true );
        }

        $stats = fstat( $handle );
        $size = is_array( $stats ) && isset( $stats['size'] ) ? (int) $stats['size'] : 0;
        $mtime = is_array( $stats ) && isset( $stats['mtime'] ) ? (int) $stats['mtime'] : 0;

        if ( $mtime < $window_start ) {
            ftruncate( $handle, 0 );
            $size = 0;
        }

        if ( $size >= $max_per_minute ) {
            if ( $cooldown_seconds > 0 ) {
                @touch( $cooldown_path );
                @chmod( $cooldown_path, 0600 );
            }
            flock( $handle, LOCK_UN );
            fclose( $handle );
            $cooldown_remaining = 0;
            if ( $cooldown_seconds > 0 && file_exists( $cooldown_path ) ) {
                clearstatcache( true, $cooldown_path );
                $cooldown_mtime = @filemtime( $cooldown_path );
                if ( $cooldown_mtime !== false ) {
                    $cooldown_remaining = max( 0, ( $cooldown_mtime + $cooldown_seconds ) - $now );
                }
            }
            $retry_after = self::retry_after( $window_end, $now, $cooldown_remaining );
            return array( 'ok' => false, 'code' => 'throttled', 'retry_after' => $retry_after );
        }

        fwrite( $handle, '1' );
        fflush( $handle );
        @chmod( $tally_path, 0600 );
        flock( $handle, LOCK_UN );
        fclose( $handle );

        return array( 'ok' => true );
    }

    private static function retry_after( $window_end, $now, $cooldown_remaining ) {
        $window_remaining = $window_end - $now;
        $retry_after = max( 1, (int) $window_remaining, (int) $cooldown_remaining );
        return $retry_after;
    }

    private static function resolve_client_ip( $request ) {
        if ( is_array( $request ) && isset( $request['client_ip'] ) && is_string( $request['client_ip'] ) ) {
            return trim( $request['client_ip'] );
        }

        if ( is_object( $request ) ) {
            if ( isset( $request->client_ip ) && is_string( $request->client_ip ) ) {
                return trim( $request->client_ip );
            }
            if ( method_exists( $request, 'get_client_ip' ) ) {
                $value = $request->get_client_ip();
                if ( is_string( $value ) ) {
                    return trim( $value );
                }
            }
        }

        if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
            return trim( $_SERVER['REMOTE_ADDR'] );
        }

        return '';
    }

    private static function uploads_dir( $config ) {
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

    private static function result( $status, $headers, $body ) {
        return array(
            'status' => (int) $status,
            'headers' => $headers,
            'body' => $body,
        );
    }
}
