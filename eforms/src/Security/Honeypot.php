<?php
/**
 * Honeypot evaluation and response helpers.
 *
 * Educational note: honeypot handling short-circuits the submission
 * pipeline to avoid running validation or side effects on spam input.
 *
 * Spec: Honeypot (docs/Canonical_Spec.md#sec-honeypot)
 * Spec: Spam decision (docs/Canonical_Spec.md#sec-spam-decision)
 */

require_once __DIR__ . '/../Config.php';

class Honeypot {
    const RESPONSE_HARD_FAIL = 'hard_fail';
    const RESPONSE_STEALTH_SUCCESS = 'stealth_success';

    /**
     * Return structured honeypot evaluation.
     *
     * @param array $post
     * @param array $config
     * @return array{triggered: bool, response: string}
     */
    public static function evaluate( $post, $config ) {
        $triggered = self::triggered( $post );
        $response = self::response_mode( $config );

        return array(
            'triggered' => $triggered,
            'response' => $response,
        );
    }

    /**
     * Remove any uploaded temp files from the request.
     *
     * @param array $files
     * @return void
     */
    public static function cleanup_uploads( $files ) {
        if ( ! is_array( $files ) ) {
            return;
        }

        $paths = array();
        self::collect_tmp_names( $files, $paths );

        foreach ( $paths as $path ) {
            if ( is_string( $path ) && $path !== '' && is_file( $path ) ) {
                @unlink( $path );
            }
        }
    }

    /**
     * Emit a honeypot event when logging is available.
     *
     * @param string $form_id
     * @param array $security
     * @param string $response
     * @param mixed $request
     * @return void
     */
    public static function log_event( $form_id, $security, $response, $request = null ) {
        if ( ! class_exists( 'Logging' ) ) {
            return;
        }

        $meta = array(
            'form_id' => is_string( $form_id ) ? $form_id : '',
            'submission_id' => isset( $security['submission_id'] ) && is_string( $security['submission_id'] ) ? $security['submission_id'] : '',
            'mode' => isset( $security['mode'] ) && is_string( $security['mode'] ) ? $security['mode'] : '',
            'honeypot' => true,
            'stealth' => $response === self::RESPONSE_STEALTH_SUCCESS,
        );

        Logging::event( 'warning', 'EFORMS_ERR_HONEYPOT', $meta, $request );
    }

    private static function triggered( $post ) {
        if ( ! is_array( $post ) || ! array_key_exists( 'eforms_hp', $post ) ) {
            return false;
        }

        return self::value_present( $post['eforms_hp'] );
    }

    private static function response_mode( $config ) {
        if ( is_array( $config ) && isset( $config['security'] ) && is_array( $config['security'] ) ) {
            if ( isset( $config['security']['honeypot_response'] ) && is_string( $config['security']['honeypot_response'] ) ) {
                $value = $config['security']['honeypot_response'];
                if ( in_array( $value, array( self::RESPONSE_HARD_FAIL, self::RESPONSE_STEALTH_SUCCESS ), true ) ) {
                    return $value;
                }
            }
        }

        return self::RESPONSE_STEALTH_SUCCESS;
    }

    private static function value_present( $value ) {
        if ( $value === null ) {
            return false;
        }

        if ( is_string( $value ) ) {
            return trim( $value ) !== '';
        }

        if ( is_scalar( $value ) ) {
            return (string) $value !== '';
        }

        if ( is_array( $value ) ) {
            return ! empty( $value );
        }

        return true;
    }

    private static function collect_tmp_names( $node, &$paths ) {
        if ( is_array( $node ) ) {
            foreach ( $node as $key => $value ) {
                if ( $key === 'tmp_name' ) {
                    if ( is_string( $value ) ) {
                        $paths[] = $value;
                    } elseif ( is_array( $value ) ) {
                        foreach ( $value as $entry ) {
                            if ( is_string( $entry ) ) {
                                $paths[] = $entry;
                            }
                        }
                    }
                    continue;
                }
                self::collect_tmp_names( $value, $paths );
            }
        }
    }
}
