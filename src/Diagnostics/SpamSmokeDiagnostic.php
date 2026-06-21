<?php
/**
 * Shared spam protection diagnostic for operator-facing surfaces.
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Security/MintEndpoint.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Submission/SubmitHandler.php';

class SpamSmokeDiagnostic {
    const FORM_ID = 'contact';
    const REQUEST_PREFIX = 'spam-smoke-';
    const SUBMIT_IP = '198.51.100.40';
    const THROTTLE_IP = '198.51.100.44';
    const OVERSIZE_IP = '198.51.100.45';
    const NO_ORIGIN_IP = '198.51.100.46';

    public static function run() {
        $preflight = self::preflight();
        if ( empty( $preflight['ok'] ) ) {
            return self::result( array(), isset( $preflight['error'] ) ? $preflight['error'] : 'preflight_failed' );
        }

        $checks = array();
        foreach ( self::scenarios() as $scenario ) {
            $checks[] = self::run_scenario( $scenario );
        }

        return self::result( $checks, '' );
    }

    public static function preflight_error( $result ) {
        return self::error_text( $result );
    }

    public static function summary_line( $result ) {
        $summary = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();
        $passed = isset( $summary['passed'] ) ? (int) $summary['passed'] : 0;
        $failed = isset( $summary['failed'] ) ? (int) $summary['failed'] : 0;
        return sprintf( '%d passed, %d failed', $passed, $failed );
    }

    public static function rows( $result ) {
        $checks = isset( $result['checks'] ) && is_array( $result['checks'] ) ? $result['checks'] : array();
        $rows = array();
        foreach ( $checks as $check ) {
            $rows[] = array(
                'name' => isset( $check['name'] ) ? (string) $check['name'] : '',
                'result' => ! empty( $check['ok'] ) ? 'PASS' : 'FAIL',
                'observed' => isset( $check['observed'] ) ? (string) $check['observed'] : '',
                'expected' => isset( $check['expected'] ) ? (string) $check['expected'] : '',
                'config_scope' => isset( $check['config_scope'] ) ? (string) $check['config_scope'] : '',
                'notes' => isset( $check['notes'] ) ? (string) $check['notes'] : '',
            );
        }

        return $rows;
    }

    private static function scenarios() {
        $limit = self::throttle_limit();

        return array(
            self::submit_scenario(
                'baseline',
                self::submission_config(),
                array( 'js_ok' => true, 'honeypot' => false ),
                array( 'code' => 'ok', 'commit_calls' => 1 ),
                'challenge off; throttle off; email suppressed at commit override',
                'real email suppressed'
            ),
            self::submit_scenario(
                'honeypot',
                self::submission_config(),
                array( 'js_ok' => true, 'honeypot' => true ),
                array( 'code' => 'EFORMS_ERR_HONEYPOT', 'commit_calls' => 0, 'burn_calls' => 1 ),
                'honeypot hard-fail mode; throttle off; challenge off',
                ''
            ),
            self::submit_scenario(
                'missing-js',
                self::strict_spam_config( array( 'security' => array( 'min_fill_seconds' => 0 ) ) ),
                array( 'js_ok' => false, 'honeypot' => false ),
                array( 'code' => 'EFORMS_ERR_SPAM', 'soft_reasons' => array( 'js_missing' ), 'commit_calls' => 0 ),
                'temporary strict spam threshold; min fill disabled',
                'strict temporary threshold'
            ),
            self::submit_scenario(
                'missing-honeypot',
                self::strict_spam_config( array( 'security' => array( 'min_fill_seconds' => 0 ) ) ),
                array( 'js_ok' => true, 'honeypot' => false, 'omit_honeypot' => true ),
                array( 'code' => 'EFORMS_ERR_SPAM', 'soft_reasons' => array( 'honeypot_missing' ), 'commit_calls' => 0 ),
                'temporary strict spam threshold; honeypot field intentionally omitted',
                'direct-post soft signal'
            ),
            self::submit_scenario(
                'too-fast',
                self::strict_spam_config( array( 'security' => array( 'min_fill_seconds' => self::stable_min_fill_seconds() ) ) ),
                array( 'js_ok' => true, 'honeypot' => false ),
                array( 'code' => 'EFORMS_ERR_SPAM', 'soft_reasons' => array( 'min_fill_time' ), 'commit_calls' => 0 ),
                'temporary strict spam threshold; positive min fill window',
                'strict temporary threshold'
            ),
            self::submit_scenario(
                'combined-soft',
                self::strict_spam_config( array( 'security' => array( 'min_fill_seconds' => self::stable_min_fill_seconds() ) ) ),
                array( 'js_ok' => false, 'honeypot' => false ),
                array( 'code' => 'EFORMS_ERR_SPAM', 'soft_reasons' => array( 'min_fill_time', 'js_missing' ), 'commit_calls' => 0 ),
                'temporary strict spam threshold; missing JS plus positive min fill window',
                'multiple soft reasons'
            ),
            self::challenge_auto_scenario(),
            self::mint_scenario(
                'throttle',
                array_replace_recursive( self::mint_config(), array( 'throttle' => array( 'enable' => true, 'per_ip' => array( 'max_per_minute' => $limit, 'cooldown_seconds' => 0 ) ) ) ),
                array( 'ip' => self::THROTTLE_IP, 'origin' => true, 'attempts' => $limit + 1 ),
                array( 'status' => 429, 'code' => 'EFORMS_ERR_THROTTLED', 'retry_after' => true ),
                'temporary throttle enabled; synthetic IP ' . self::THROTTLE_IP,
                'synthetic IP ' . self::THROTTLE_IP
            ),
            self::mint_scenario(
                'mint-oversized',
                array_replace_recursive( self::mint_config(), array( 'security' => array( 'max_post_bytes' => 1 ) ) ),
                array( 'ip' => self::OVERSIZE_IP, 'origin' => true, 'content_length' => 2 ),
                array( 'status' => 400, 'code' => 'EFORMS_ERR_MINT_FAILED' ),
                'temporary max post bytes cap; synthetic CONTENT_LENGTH',
                ''
            ),
            self::mint_scenario(
                'mint-no-origin',
                self::mint_config(),
                array( 'ip' => self::NO_ORIGIN_IP, 'origin' => false ),
                array( 'status' => 403, 'code' => 'EFORMS_ERR_ORIGIN_FORBIDDEN' ),
                'origin hard mode; request intentionally omits Origin',
                ''
            ),
        );
    }

    private static function challenge_auto_scenario() {
        return array(
            'kind' => 'challenge-auto',
            'name' => 'challenge-auto',
            'config' => self::submission_config(
                array(
                    'challenge' => array( 'mode' => 'auto' ),
                    'security' => array( 'min_fill_seconds' => 0, 'js_hard_mode' => false ),
                )
            ),
            'expect' => array( 'require_challenge' => true, 'soft_reasons' => array( 'js_missing', 'honeypot_missing' ) ),
            'config_scope' => 'temporary auto challenge; missing JS and omitted honeypot soft signals',
            'notes' => 'provider not contacted',
        );
    }

    private static function submit_scenario( $name, $config, $input, $expect, $config_scope, $notes ) {
        return array(
            'kind' => 'submit',
            'name' => $name,
            'config' => $config,
            'input' => $input,
            'expect' => $expect,
            'config_scope' => $config_scope,
            'notes' => $notes,
        );
    }

    private static function mint_scenario( $name, $config, $input, $expect, $config_scope, $notes ) {
        return array(
            'kind' => 'mint',
            'name' => $name,
            'config' => $config,
            'input' => $input,
            'expect' => $expect,
            'config_scope' => $config_scope,
            'notes' => $notes,
        );
    }

    private static function run_scenario( $scenario ) {
        try {
            if ( isset( $scenario['kind'] ) && $scenario['kind'] === 'mint' ) {
                $observed = self::run_mint_scenario( $scenario );
            } elseif ( isset( $scenario['kind'] ) && $scenario['kind'] === 'challenge-auto' ) {
                $observed = self::run_challenge_auto_scenario( $scenario );
            } else {
                $observed = self::run_submit_scenario( $scenario );
            }
        } catch ( Throwable $e ) {
            $observed = array( 'ok' => false, 'observed' => 'exception', 'notes' => $e->getMessage() );
        }

        return array(
            'name' => isset( $scenario['name'] ) ? (string) $scenario['name'] : '',
            'ok' => ! empty( $observed['ok'] ),
            'observed' => isset( $observed['observed'] ) ? (string) $observed['observed'] : '',
            'expected' => self::expected_text( $scenario ),
            'config_scope' => isset( $scenario['config_scope'] ) ? (string) $scenario['config_scope'] : '',
            'notes' => isset( $observed['notes'] ) ? (string) $observed['notes'] : '',
            'error' => empty( $observed['ok'] ) ? 'unexpected_result' : '',
        );
    }

    private static function run_challenge_auto_scenario( $scenario ) {
        return self::with_config(
            $scenario['config'],
            function () use ( $scenario ) {
                $expect = isset( $scenario['expect'] ) && is_array( $scenario['expect'] ) ? $scenario['expect'] : array();
                $mint = Security::mint_hidden_record(
                    self::FORM_ID,
                    null,
                    array( 'client_ip' => self::SUBMIT_IP, 'request_id' => self::request_id() )
                );
                if ( ! is_array( $mint ) || empty( $mint['ok'] ) ) {
                    return array(
                        'ok' => false,
                        'observed' => isset( $mint['code'] ) ? (string) $mint['code'] : 'mint_failed',
                        'notes' => 'could not mint synthetic token',
                    );
                }

                $post = array(
                    FormProtocol::FIELD_TOKEN => $mint['token'],
                    FormProtocol::FIELD_INSTANCE_ID => $mint['instance_id'],
                    FormProtocol::FIELD_TIMESTAMP => (string) $mint['issued_at'],
                );
                $result = Security::token_validate(
                    $post,
                    self::FORM_ID,
                    array( 'client_ip' => self::SUBMIT_IP, 'request_id' => self::request_id() )
                );
                $soft_reasons = isset( $result['soft_reasons'] ) && is_array( $result['soft_reasons'] ) ? $result['soft_reasons'] : array();
                $requires_challenge = ! empty( $result['require_challenge'] );
                $ok = $requires_challenge === ! empty( $expect['require_challenge'] );
                foreach ( self::expect_list( $expect, 'soft_reasons' ) as $soft_reason ) {
                    $ok = $ok && in_array( $soft_reason, $soft_reasons, true );
                }

                return array(
                    'ok' => $ok,
                    'observed' => ( $requires_challenge ? 'required ' : 'not required ' ) . implode( ',', $soft_reasons ),
                    'notes' => isset( $scenario['notes'] ) ? (string) $scenario['notes'] : '',
                );
            }
        );
    }

    private static function run_submit_scenario( $scenario ) {
        return self::with_config(
            $scenario['config'],
            function () use ( $scenario ) {
                $input = isset( $scenario['input'] ) && is_array( $scenario['input'] ) ? $scenario['input'] : array();
                $expect = isset( $scenario['expect'] ) && is_array( $scenario['expect'] ) ? $scenario['expect'] : array();
                $calls = array( 'burn' => 0, 'commit' => 0 );
                $result = self::submit(
                    ! empty( $input['js_ok'] ),
                    ! empty( $input['honeypot'] ),
                    ! empty( $input['omit_honeypot'] ),
                    array(
                        'ledger_reserve' => function () {
                            return array( 'ok' => true );
                        },
                        'honeypot_burn' => function () use ( &$calls ) {
                            $calls['burn'] += 1;
                            return array( 'ok' => true );
                        },
                        'commit' => function () use ( &$calls ) {
                            $calls['commit'] += 1;
                            return array( 'ok' => true, 'status' => 200 );
                        },
                    )
                );

                $code = is_array( $result ) && isset( $result['error_code'] ) && is_string( $result['error_code'] )
                    ? $result['error_code']
                    : ( is_array( $result ) && ! empty( $result['ok'] ) ? 'ok' : 'unknown' );
                $soft_reasons = is_array( $result ) && isset( $result['soft_reasons'] ) && is_array( $result['soft_reasons'] )
                    ? $result['soft_reasons']
                    : array();
                $ok = $code === self::expect_string( $expect, 'code', '' )
                    && $calls['commit'] === self::expect_int( $expect, 'commit_calls', 0 );
                if ( array_key_exists( 'burn_calls', $expect ) ) {
                    $ok = $ok && $calls['burn'] === self::expect_int( $expect, 'burn_calls', 0 );
                }
                foreach ( self::expect_list( $expect, 'soft_reasons' ) as $soft_reason ) {
                    $ok = $ok && in_array( $soft_reason, $soft_reasons, true );
                }

                $observed = $code === 'ok'
                    ? 'reached commit override; real email suppressed'
                    : trim( $code . ' ' . implode( ',', $soft_reasons ) );
                $notes = isset( $scenario['notes'] ) && $scenario['notes'] !== '' ? $scenario['notes'] : 'commit calls=' . $calls['commit'];

                return array( 'ok' => $ok, 'observed' => $observed, 'notes' => $notes );
            }
        );
    }

    private static function run_mint_scenario( $scenario ) {
        return self::with_config(
            $scenario['config'],
            function () use ( $scenario ) {
                $input = isset( $scenario['input'] ) && is_array( $scenario['input'] ) ? $scenario['input'] : array();
                $expect = isset( $scenario['expect'] ) && is_array( $scenario['expect'] ) ? $scenario['expect'] : array();
                $attempts = self::expect_int( $input, 'attempts', 1 );
                $run = function () use ( $input, $attempts ) {
                    $response = array();
                    for ( $i = 0; $i < $attempts; $i++ ) {
                        $response = MintEndpoint::handle(
                            self::mint_request(
                                self::expect_string( $input, 'ip', self::NO_ORIGIN_IP ),
                                ! empty( $input['origin'] )
                            )
                        );
                    }
                    return $response;
                };

                $content_length = array_key_exists( 'content_length', $input ) ? (int) $input['content_length'] : null;
                $response = $content_length === null
                    ? $run()
                    : self::with_content_length( $content_length, $run );

                $body = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
                $status = isset( $response['status'] ) ? (int) $response['status'] : 0;
                $code = isset( $body['error'] ) ? (string) $body['error'] : '';
                $retry_ok = empty( $expect['retry_after'] )
                    || ( isset( $response['headers']['Retry-After'] ) && (int) $response['headers']['Retry-After'] >= 1 );

                return array(
                    'ok' => $status === self::expect_int( $expect, 'status', 0 )
                        && $code === self::expect_string( $expect, 'code', '' )
                        && $retry_ok,
                    'observed' => (string) $status,
                    'notes' => isset( $scenario['notes'] ) && $scenario['notes'] !== '' ? $scenario['notes'] : $code,
                );
            }
        );
    }

    private static function preflight() {
        Config::reset_snapshot();

        $template = TemplateLoader::load( self::FORM_ID );
        if ( ! is_array( $template ) || empty( $template['ok'] ) ) {
            return array( 'ok' => false, 'error' => 'contact_template_unavailable' );
        }

        $uploads_dir = Config::value( Config::get(), array( 'uploads', 'dir' ), '' );
        if ( ! is_string( $uploads_dir ) || $uploads_dir === '' || ! is_dir( $uploads_dir ) || ! is_writable( $uploads_dir ) ) {
            return array( 'ok' => false, 'error' => 'uploads_dir_unavailable' );
        }

        return array( 'ok' => true );
    }

    private static function submit( $js_ok, $honeypot, $omit_honeypot, $overrides ) {
        $mint = Security::mint_hidden_record(
            self::FORM_ID,
            null,
            array( 'client_ip' => self::SUBMIT_IP, 'request_id' => self::request_id() )
        );
        if ( ! is_array( $mint ) || empty( $mint['ok'] ) ) {
            return array(
                'ok' => false,
                'status' => 500,
                'error_code' => isset( $mint['code'] ) ? $mint['code'] : 'EFORMS_ERR_STORAGE_UNAVAILABLE',
                'soft_reasons' => array(),
            );
        }

        $post = array(
            self::FORM_ID => array(
                'name' => 'Smoke Test',
                'email' => 'smoke@example.test',
                'message' => 'Smoke test submission.',
            ),
        );
        if ( $js_ok ) {
            $post[ FormProtocol::FIELD_JS_OK ] = '1';
        }
        if ( $honeypot ) {
            $post[ FormProtocol::FIELD_HONEYPOT ] = 'bot';
        } elseif ( ! $omit_honeypot ) {
            $post[ FormProtocol::FIELD_HONEYPOT ] = '';
        }
        $post[ FormProtocol::FIELD_TOKEN ] = $mint['token'];
        $post[ FormProtocol::FIELD_INSTANCE_ID ] = $mint['instance_id'];
        $post[ FormProtocol::FIELD_TIMESTAMP ] = (string) $mint['issued_at'];

        return SubmitHandler::handle(
            self::FORM_ID,
            array(
                'post' => $post,
                'files' => array(),
                'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
                'client_ip' => self::SUBMIT_IP,
                'request_id' => self::request_id(),
            ),
            array_merge( array( 'trace' => true ), $overrides )
        );
    }

    private static function mint_request( $ip, $with_origin ) {
        $headers = array( 'Content-Type' => 'application/x-www-form-urlencoded' );
        if ( $with_origin ) {
            $headers['Origin'] = self::site_origin();
        }

        return array(
            'method' => 'POST',
            'headers' => $headers,
            'params' => array( FormProtocol::MINT_FORM_PARAM => self::FORM_ID ),
            'client_ip' => $ip,
            'request_id' => self::request_id(),
        );
    }

    private static function submission_config( $extra = array() ) {
        return array_replace_recursive(
            array(
                'security' => array(
                    'origin_mode' => 'off',
                    'origin_missing_hard' => false,
                    'honeypot_response' => 'hard_fail',
                    'min_fill_seconds' => 0,
                    'js_hard_mode' => false,
                ),
                'spam' => array( 'soft_fail_threshold' => 99 ),
                'challenge' => array( 'mode' => 'off' ),
                'throttle' => array( 'enable' => false ),
                'declined_review' => array( 'enable' => false ),
            ),
            $extra
        );
    }

    private static function strict_spam_config( $extra = array() ) {
        return array_replace_recursive(
            self::submission_config( array( 'spam' => array( 'soft_fail_threshold' => 1 ) ) ),
            $extra
        );
    }

    private static function mint_config() {
        return array(
            'security' => array(
                'origin_mode' => 'hard',
                'origin_missing_hard' => true,
                'max_post_bytes' => PHP_INT_MAX,
            ),
            'throttle' => array( 'enable' => false ),
            'challenge' => array( 'mode' => 'off' ),
        );
    }

    private static function request_id() {
        return self::REQUEST_PREFIX . str_replace( '-', '_', uniqid( '', true ) );
    }

    private static function stable_min_fill_seconds() {
        $value = (int) Anchors::get( 'MIN_FILL_SECONDS_MAX' );
        return $value > 1 ? $value : 60;
    }

    private static function throttle_limit() {
        $value = (int) Anchors::get( 'THROTTLE_MAX_PER_MIN_MIN' );
        return $value > 0 ? $value : 1;
    }

    private static function site_origin() {
        if ( function_exists( 'home_url' ) ) {
            $parts = parse_url( home_url() );
            if ( is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) ) {
                return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
            }
        }

        $scheme = isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) && $_SERVER['HTTP_HOST'] !== ''
            ? $_SERVER['HTTP_HOST']
            : 'example.com';
        return $scheme . '://' . $host;
    }

    private static function with_config( $overrides, $callback ) {
        $filter = function ( $config ) use ( $overrides ) {
            return array_replace_recursive( is_array( $config ) ? $config : array(), $overrides );
        };

        self::install_config_filter( $filter );
        Config::reset_snapshot();

        try {
            return call_user_func( $callback );
        } finally {
            self::remove_config_filter( $filter );
            Config::reset_snapshot();
        }
    }

    private static function install_config_filter( $filter ) {
        if ( function_exists( 'eforms_test_set_filter' ) ) {
            eforms_test_set_filter( 'eforms_config', $filter );
            return;
        }
        if ( function_exists( 'add_filter' ) ) {
            add_filter( 'eforms_config', $filter, PHP_INT_MAX, 1 );
        }
    }

    private static function remove_config_filter( $filter ) {
        if ( function_exists( 'eforms_test_set_filter' ) ) {
            eforms_test_set_filter( 'eforms_config', null );
            return;
        }
        if ( function_exists( 'remove_filter' ) ) {
            remove_filter( 'eforms_config', $filter, PHP_INT_MAX );
        }
    }

    private static function with_content_length( $length, $callback ) {
        $had_content_length = array_key_exists( 'CONTENT_LENGTH', $_SERVER );
        $old_content_length = $had_content_length ? $_SERVER['CONTENT_LENGTH'] : null;
        $_SERVER['CONTENT_LENGTH'] = (string) $length;

        try {
            return call_user_func( $callback );
        } finally {
            if ( $had_content_length ) {
                $_SERVER['CONTENT_LENGTH'] = $old_content_length;
            } else {
                unset( $_SERVER['CONTENT_LENGTH'] );
            }
        }
    }

    private static function result( $checks, $error ) {
        $failed = 0;
        foreach ( $checks as $check ) {
            $failed += empty( $check['ok'] ) ? 1 : 0;
        }
        $exit_code = $error !== '' && empty( $checks ) ? 2 : ( $failed === 0 ? 0 : 1 );

        return array(
            'ok' => $exit_code === 0,
            'exit_code' => $exit_code,
            'checks' => $checks,
            'error' => (string) $error,
            'summary' => array(
                'passed' => count( $checks ) - $failed,
                'failed' => $failed,
            ),
        );
    }

    private static function error_text( $result ) {
        return isset( $result['error'] ) && (string) $result['error'] !== '' ? (string) $result['error'] : 'unknown';
    }

    private static function expected_text( $scenario ) {
        $expect = isset( $scenario['expect'] ) && is_array( $scenario['expect'] ) ? $scenario['expect'] : array();
        if ( isset( $scenario['kind'] ) && $scenario['kind'] === 'mint' ) {
            return self::expect_int( $expect, 'status', 0 ) . ' ' . self::expect_string( $expect, 'code', '' );
        }
        if ( isset( $scenario['kind'] ) && $scenario['kind'] === 'challenge-auto' ) {
            $required = ! empty( $expect['require_challenge'] ) ? 'required' : 'not required';
            return trim( $required . ' ' . implode( ',', self::expect_list( $expect, 'soft_reasons' ) ) );
        }

        $code = self::expect_string( $expect, 'code', '' );
        $soft_reasons = self::expect_list( $expect, 'soft_reasons' );
        return empty( $soft_reasons ) ? $code : trim( $code . ' ' . implode( ',', $soft_reasons ) );
    }

    private static function expect_string( $values, $key, $fallback ) {
        return is_array( $values ) && isset( $values[ $key ] ) ? (string) $values[ $key ] : $fallback;
    }

    private static function expect_int( $values, $key, $fallback ) {
        return is_array( $values ) && isset( $values[ $key ] ) ? (int) $values[ $key ] : $fallback;
    }

    private static function expect_list( $values, $key ) {
        if ( ! is_array( $values ) || ! isset( $values[ $key ] ) || ! is_array( $values[ $key ] ) ) {
            return array();
        }

        $out = array();
        foreach ( $values[ $key ] as $value ) {
            $out[] = (string) $value;
        }
        return $out;
    }
}
