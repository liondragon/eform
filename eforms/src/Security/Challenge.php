<?php
/**
 * Adaptive challenge verification helpers (Turnstile v1).
 *
 * Spec: Adaptive challenge (docs/Canonical_Spec.md#sec-adaptive-challenge)
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 */

require_once __DIR__ . '/../Config.php';

class Challenge {
    const TURNSTILE_RESPONSE_FIELD = 'cf-turnstile-response';
    const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * True when the posted payload includes a provider response token.
     *
     * @param array $post POST payload.
     * @return bool
     */
    public static function has_provider_response( $post ) {
        return self::provider_response( $post ) !== '';
    }

    /**
     * Verify challenge response when required (or when provider response is present).
     *
     * @param array $post POST payload.
     * @param mixed $request Optional request object/array.
     * @param array|null $config Optional config snapshot.
     * @param array $security Security gate result from Security::token_validate().
     * @return array { ok, required, error_code, soft_reasons }
     */
    public static function verify( $post, $request = null, $config = null, $security = array() ) {
        $post = is_array( $post ) ? $post : array();
        $security = is_array( $security ) ? $security : array();
        $config = is_array( $config ) ? $config : Config::get();

        $required = ! empty( $security['require_challenge'] );
        $response = self::provider_response( $post );
        $response_present = $response !== '';

        if ( ! $required && ! $response_present ) {
            return array(
                'ok' => true,
                'required' => false,
                'error_code' => '',
                'soft_reasons' => self::security_soft_reasons( $security ),
            );
        }

        if ( ! self::is_configured( $config ) ) {
            return array(
                'ok' => false,
                'required' => false,
                'error_code' => 'EFORMS_CHALLENGE_UNCONFIGURED',
                'soft_reasons' => self::security_soft_reasons( $security ),
            );
        }

        if ( $response === '' ) {
            return array(
                'ok' => false,
                'required' => true,
                'error_code' => 'EFORMS_ERR_CHALLENGE_FAILED',
                'soft_reasons' => self::security_soft_reasons( $security ),
            );
        }

        $verify = self::verify_turnstile(
            $response,
            self::challenge_secret_key( $config ),
            self::http_timeout( $config ),
            self::request_client_ip( $request )
        );

        if ( ! is_array( $verify ) || empty( $verify['ok'] ) ) {
            return array(
                'ok' => false,
                'required' => true,
                'error_code' => 'EFORMS_ERR_CHALLENGE_FAILED',
                'soft_reasons' => self::security_soft_reasons( $security ),
            );
        }

        return array(
            'ok' => true,
            'required' => false,
            'error_code' => '',
            // Challenge pass clears challenge-driving soft labels for downstream spam labeling.
            'soft_reasons' => array(),
        );
    }

    private static function verify_turnstile( $response_token, $secret_key, $timeout_seconds, $client_ip ) {
        if ( ! function_exists( 'wp_remote_post' ) ) {
            return array( 'ok' => false, 'reason' => 'transport_unavailable' );
        }

        $body = array(
            'secret' => $secret_key,
            'response' => $response_token,
        );

        if ( $client_ip !== '' ) {
            $body['remoteip'] = $client_ip;
        }

        $http = wp_remote_post(
            self::TURNSTILE_VERIFY_URL,
            array(
                'timeout' => max( 1, (int) $timeout_seconds ),
                'body' => $body,
            )
        );

        if ( function_exists( 'is_wp_error' ) && is_wp_error( $http ) ) {
            return array( 'ok' => false, 'reason' => 'http_error' );
        }

        $status = self::remote_status( $http );
        if ( $status < 200 || $status >= 300 ) {
            return array( 'ok' => false, 'reason' => 'http_status' );
        }

        $raw = self::remote_body( $http );
        if ( $raw === '' ) {
            return array( 'ok' => false, 'reason' => 'empty_body' );
        }

        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            return array( 'ok' => false, 'reason' => 'decode_failed' );
        }

        if ( empty( $decoded['success'] ) ) {
            return array( 'ok' => false, 'reason' => 'provider_rejected' );
        }

        return array( 'ok' => true );
    }

    private static function remote_status( $response ) {
        if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
            $code = wp_remote_retrieve_response_code( $response );
            if ( is_numeric( $code ) ) {
                return (int) $code;
            }
        }

        if ( is_array( $response )
            && isset( $response['response'] )
            && is_array( $response['response'] )
            && isset( $response['response']['code'] )
            && is_numeric( $response['response']['code'] ) ) {
            return (int) $response['response']['code'];
        }

        return 0;
    }

    private static function remote_body( $response ) {
        if ( function_exists( 'wp_remote_retrieve_body' ) ) {
            $body = wp_remote_retrieve_body( $response );
            if ( is_string( $body ) ) {
                return $body;
            }
        }

        if ( is_array( $response ) && isset( $response['body'] ) && is_string( $response['body'] ) ) {
            return $response['body'];
        }

        return '';
    }

    private static function is_configured( $config ) {
        $provider = self::challenge_provider( $config );
        if ( $provider !== 'turnstile' ) {
            return false;
        }

        return self::challenge_site_key( $config ) !== '' && self::challenge_secret_key( $config ) !== '';
    }

    private static function challenge_provider( $config ) {
        if ( is_array( $config )
            && isset( $config['challenge'] )
            && is_array( $config['challenge'] )
            && isset( $config['challenge']['provider'] )
            && is_string( $config['challenge']['provider'] )
            && $config['challenge']['provider'] !== '' ) {
            return $config['challenge']['provider'];
        }

        return 'turnstile';
    }

    private static function challenge_site_key( $config ) {
        if ( is_array( $config )
            && isset( $config['challenge'] )
            && is_array( $config['challenge'] )
            && isset( $config['challenge']['site_key'] )
            && is_string( $config['challenge']['site_key'] ) ) {
            return trim( $config['challenge']['site_key'] );
        }

        return '';
    }

    private static function challenge_secret_key( $config ) {
        if ( is_array( $config )
            && isset( $config['challenge'] )
            && is_array( $config['challenge'] )
            && isset( $config['challenge']['secret_key'] )
            && is_string( $config['challenge']['secret_key'] ) ) {
            return trim( $config['challenge']['secret_key'] );
        }

        return '';
    }

    private static function http_timeout( $config ) {
        if ( is_array( $config )
            && isset( $config['challenge'] )
            && is_array( $config['challenge'] )
            && isset( $config['challenge']['http_timeout_seconds'] )
            && is_numeric( $config['challenge']['http_timeout_seconds'] ) ) {
            return max( 1, (int) $config['challenge']['http_timeout_seconds'] );
        }

        return 3;
    }

    private static function provider_response( $post ) {
        if ( ! is_array( $post )
            || ! isset( $post[ self::TURNSTILE_RESPONSE_FIELD ] )
            || ! is_scalar( $post[ self::TURNSTILE_RESPONSE_FIELD ] ) ) {
            return '';
        }

        return trim( (string) $post[ self::TURNSTILE_RESPONSE_FIELD ] );
    }

    private static function request_client_ip( $request ) {
        if ( is_array( $request ) && isset( $request['client_ip'] ) && is_string( $request['client_ip'] ) ) {
            return trim( $request['client_ip'] );
        }

        if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
            return trim( $_SERVER['REMOTE_ADDR'] );
        }

        return '';
    }

    private static function security_soft_reasons( $security ) {
        if ( is_array( $security ) && isset( $security['soft_reasons'] ) && is_array( $security['soft_reasons'] ) ) {
            return $security['soft_reasons'];
        }

        return array();
    }
}
