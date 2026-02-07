<?php
/**
 * FormRenderer for GET render (hidden-mode + JS-minted markup).
 *
 * Spec: Request lifecycle GET (docs/Canonical_Spec.md#sec-request-lifecycle-get)
 * Spec: Success behavior (docs/Canonical_Spec.md#sec-success)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Errors.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Rendering/TemplateContext.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Security/StorageHealth.php';
require_once __DIR__ . '/../Submission/Success.php';
if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}

class FormRenderer {
    private static $rendered_form_ids = array();
    private static $success_banner_shown = array();
    private static $logged_header_warning = false;
    private static $logged_input_vars_warning = false;
    private static $headers_sent_override = null;

    /**
     * Render a form by slug (template filename stem).
     *
     * @param string $slug Template slug from shortcode/template tag.
     * @param array $opts Options (e.g., cacheable).
     * @return string HTML output.
     */
    public static function render( $slug, $opts = array() ) {
        $config = Config::get();
        $cacheable = self::parse_cacheable( $opts );
        $security_override = self::parse_security_override( $opts );
        $force_cache_headers = self::parse_force_cache_headers( $opts );

        $template = TemplateLoader::load( $slug );
        if ( ! is_array( $template ) || empty( $template['ok'] ) ) {
            $code = self::error_code_from_result( $template );
            return self::render_error( $code );
        }

        $context_result = TemplateContext::build( $template['template'], $template['version'] );
        if ( ! is_array( $context_result ) || empty( $context_result['ok'] ) ) {
            $code = self::error_code_from_errors( isset( $context_result['errors'] ) ? $context_result['errors'] : null );
            return self::render_error( $code );
        }

        $context = $context_result['context'];
        if ( class_exists( 'Logging' ) && method_exists( 'Logging', 'remember_descriptors' ) ) {
            $descriptors = isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ? $context['descriptors'] : array();
            Logging::remember_descriptors( $descriptors );
        }
        if ( is_array( $template['template'] ) && isset( $template['template']['submit_button_text'] ) && is_string( $template['template']['submit_button_text'] ) ) {
            $context['submit_button_text'] = $template['template']['submit_button_text'];
        }
        $form_id = isset( $context['id'] ) && is_string( $context['id'] ) ? $context['id'] : '';
        if ( $form_id === '' ) {
            return self::render_error( 'EFORMS_ERR_SCHEMA_REQUIRED' );
        }

        if ( self::is_duplicate_form_id( $form_id ) ) {
            return self::render_error( 'EFORMS_ERR_DUPLICATE_FORM_ID' );
        }

        if ( $cacheable && self::is_inline_success( $context ) ) {
            return self::render_error( 'EFORMS_ERR_INLINE_SUCCESS_REQUIRES_NONCACHEABLE' );
        }

        if ( self::should_show_success_banner( $form_id, $context ) ) {
            self::mark_success_banner_shown( $form_id );
            self::mark_rendered( $form_id );
            self::ensure_cache_headers( true );
            return Success::render_banner( $context );
        }

        $mode = $cacheable ? 'js' : 'hidden';
        $override_mode = '';
        if ( is_array( $security_override ) && isset( $security_override['mode'] ) && is_string( $security_override['mode'] ) ) {
            $override_mode = $security_override['mode'];
        }
        if ( $override_mode !== '' ) {
            if ( $override_mode !== 'hidden' && $override_mode !== 'js' ) {
                // Educational note: keep mode selection server-owned by rejecting invalid overrides.
                return self::render_error( 'EFORMS_ERR_SCHEMA_OBJECT' );
            }
            $mode = $override_mode;
        }

        $needs_cache_headers = self::needs_cache_headers( $mode );
        if ( $force_cache_headers ) {
            $needs_cache_headers = true;
        }
        $headers_ok = self::ensure_cache_headers( $needs_cache_headers );
        if ( $mode === 'hidden' && ! $headers_ok ) {
            return self::render_error( 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }

        self::maybe_log_input_vars( $context, $config, $form_id );

        $security = self::normalize_security_override( $security_override );

        if ( $mode === 'hidden' && ! self::has_security_override( $security_override ) ) {
            $uploads_dir = self::uploads_dir( $config );
            $health = StorageHealth::check( $uploads_dir );
            if ( ! is_array( $health ) || empty( $health['ok'] ) ) {
                return self::render_error( 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
            }

            $mint = Security::mint_hidden_record( $form_id, $uploads_dir );
            if ( ! is_array( $mint ) || empty( $mint['ok'] ) ) {
                $code = is_array( $mint ) && isset( $mint['code'] ) ? $mint['code'] : 'EFORMS_ERR_STORAGE_UNAVAILABLE';
                return self::render_error( $code );
            }

            $security['token'] = $mint['token'];
            $security['instance_id'] = $mint['instance_id'];
            $security['timestamp'] = (string) $mint['issued_at'];
        }

        $errors = self::parse_errors( $opts );
        $values = self::parse_values( $opts );
        $require_challenge = self::parse_require_challenge( $opts );
        $challenge = self::resolve_challenge( $opts, $errors, $config, $require_challenge );
        $email_retry = self::parse_email_retry( $opts );
        $email_failure_summary = self::parse_email_failure_summary( $opts );
        $email_failure_remint = self::parse_email_failure_remint( $opts );

        self::mark_rendered( $form_id );
        self::enqueue_assets( $config, ! empty( $challenge['render'] ) );

        return self::render_form(
            $context,
            $mode,
            $security,
            $config,
            $errors,
            $values,
            $challenge,
            array(
                'email_retry' => $email_retry,
                'email_failure_summary' => $email_failure_summary,
                'email_failure_remint' => $email_failure_remint,
            )
        );
    }

    /**
     * Test helper to reset renderer state.
     */
    public static function reset_for_tests() {
        self::$rendered_form_ids = array();
        self::$success_banner_shown = array();
        self::$logged_header_warning = false;
        self::$logged_input_vars_warning = false;
        self::$headers_sent_override = null;
    }

    /**
     * Test helper to override header-sent detection.
     *
     * @param bool|null $value Use null to disable override.
     */
    public static function set_headers_sent_override( $value ) {
        if ( $value === null ) {
            self::$headers_sent_override = null;
            return;
        }

        self::$headers_sent_override = (bool) $value;
    }

    private static function parse_cacheable( $opts ) {
        $raw = null;
        if ( is_array( $opts ) && array_key_exists( 'cacheable', $opts ) ) {
            $raw = $opts['cacheable'];
        }

        if ( is_bool( $raw ) ) {
            return $raw;
        }

        if ( is_numeric( $raw ) ) {
            return (int) $raw === 1;
        }

        if ( is_string( $raw ) ) {
            $value = strtolower( trim( $raw ) );
            if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
                return true;
            }
            if ( in_array( $value, array( '0', 'false', 'no', 'off' ), true ) ) {
                return false;
            }
        }

        return false;
    }

    private static function parse_security_override( $opts ) {
        if ( is_array( $opts ) && isset( $opts['security'] ) && is_array( $opts['security'] ) ) {
            return $opts['security'];
        }

        return null;
    }

    private static function normalize_security_override( $security ) {
        return array(
            'mode' => is_array( $security ) && isset( $security['mode'] ) ? $security['mode'] : '',
            'token' => is_array( $security ) && isset( $security['token'] ) && is_string( $security['token'] ) ? $security['token'] : '',
            'instance_id' => is_array( $security ) && isset( $security['instance_id'] ) && is_string( $security['instance_id'] ) ? $security['instance_id'] : '',
            'timestamp' => is_array( $security ) && isset( $security['timestamp'] ) && is_string( $security['timestamp'] ) ? $security['timestamp'] : '',
        );
    }

    private static function has_security_override( $security ) {
        return is_array( $security );
    }

    private static function parse_force_cache_headers( $opts ) {
        if ( is_array( $opts ) && isset( $opts['force_cache_headers'] ) ) {
            return (bool) $opts['force_cache_headers'];
        }

        return false;
    }

    private static function parse_values( $opts ) {
        if ( is_array( $opts ) && isset( $opts['values'] ) && is_array( $opts['values'] ) ) {
            return $opts['values'];
        }

        return array();
    }

    private static function parse_require_challenge( $opts ) {
        if ( is_array( $opts ) && isset( $opts['require_challenge'] ) ) {
            return (bool) $opts['require_challenge'];
        }

        return false;
    }

    private static function resolve_challenge( $opts, $errors, $config, $require_challenge ) {
        $render = (bool) $require_challenge;

        if ( ! $render && self::has_error_code( $errors, 'EFORMS_ERR_CHALLENGE_FAILED' ) ) {
            $render = true;
        }

        if ( is_array( $opts ) && isset( $opts['challenge'] ) && is_array( $opts['challenge'] ) ) {
            if ( isset( $opts['challenge']['render'] ) ) {
                $render = (bool) $opts['challenge']['render'];
            }
        }

        $provider = 'turnstile';
        $site_key = '';
        if ( is_array( $config ) && isset( $config['challenge'] ) && is_array( $config['challenge'] ) ) {
            if ( isset( $config['challenge']['provider'] ) && is_string( $config['challenge']['provider'] ) && $config['challenge']['provider'] !== '' ) {
                $provider = $config['challenge']['provider'];
            }
            if ( isset( $config['challenge']['site_key'] ) && is_string( $config['challenge']['site_key'] ) ) {
                $site_key = trim( $config['challenge']['site_key'] );
            }
        }

        if ( $provider !== 'turnstile' || $site_key === '' ) {
            $render = false;
        }

        return array(
            'render' => $render,
            'provider' => $provider,
            'site_key' => $site_key,
        );
    }

    private static function parse_email_retry( $opts ) {
        if ( is_array( $opts ) && isset( $opts['email_retry'] ) ) {
            return (bool) $opts['email_retry'];
        }

        return false;
    }

    private static function parse_email_failure_summary( $opts ) {
        if ( is_array( $opts ) && isset( $opts['email_failure_summary'] ) && is_string( $opts['email_failure_summary'] ) ) {
            return $opts['email_failure_summary'];
        }

        return '';
    }

    private static function parse_email_failure_remint( $opts ) {
        if ( is_array( $opts ) && isset( $opts['email_failure_remint'] ) ) {
            return (bool) $opts['email_failure_remint'];
        }

        return false;
    }

    private static function is_duplicate_form_id( $form_id ) {
        return isset( self::$rendered_form_ids[ $form_id ] );
    }

    private static function mark_rendered( $form_id ) {
        self::$rendered_form_ids[ $form_id ] = true;
    }

    /**
     * Check if success banner should be shown for this form.
     *
     * Spec: Show banner only in the first instance in source order; suppress subsequent duplicates.
     *
     * @param string $form_id Form identifier.
     * @param array $context Template context.
     * @return bool True if success banner should be shown.
     */
    private static function should_show_success_banner( $form_id, $context ) {
        if ( isset( self::$success_banner_shown[ $form_id ] ) ) {
            return false;
        }

        if ( ! self::is_inline_success( $context ) ) {
            return false;
        }

        return Success::is_inline_success_request( $form_id );
    }

    /**
     * Mark success banner as shown for form.
     *
     * @param string $form_id Form identifier.
     */
    private static function mark_success_banner_shown( $form_id ) {
        self::$success_banner_shown[ $form_id ] = true;
    }

    private static function is_inline_success( $context ) {
        if ( ! is_array( $context ) || ! isset( $context['success'] ) || ! is_array( $context['success'] ) ) {
            return false;
        }

        return isset( $context['success']['mode'] ) && $context['success']['mode'] === 'inline';
    }

    private static function needs_cache_headers( $mode ) {
        if ( $mode === 'hidden' ) {
            return true;
        }

        return self::has_eforms_query();
    }

    private static function has_eforms_query() {
        if ( ! isset( $_GET ) || ! is_array( $_GET ) ) {
            return false;
        }

        foreach ( $_GET as $key => $value ) {
            if ( is_string( $key ) && strncmp( $key, 'eforms_', 7 ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    private static function ensure_cache_headers( $needs_cache_headers ) {
        if ( ! $needs_cache_headers ) {
            return true;
        }

        if ( self::headers_sent() ) {
            self::log_header_warning_once();
            return false;
        }

        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }

        header( 'Cache-Control: private, no-store, max-age=0' );
        return true;
    }

    private static function headers_sent() {
        if ( self::$headers_sent_override !== null ) {
            return self::$headers_sent_override;
        }

        return headers_sent();
    }

    private static function log_header_warning_once() {
        if ( self::$logged_header_warning ) {
            return;
        }

        self::$logged_header_warning = true;

        if ( class_exists( 'Logging' ) && method_exists( 'Logging', 'event' ) ) {
            Logging::event( 'warning', 'EFORMS_ERR_STORAGE_UNAVAILABLE', array( 'reason' => 'headers_sent' ) );
        }
    }

    private static function maybe_log_input_vars( $context, $config, $form_id ) {
        if ( self::$logged_input_vars_warning ) {
            return;
        }

        if ( ! is_array( $context ) || ! isset( $context['max_input_vars_estimate'] ) ) {
            return;
        }

        $estimate = (int) $context['max_input_vars_estimate'];
        if ( $estimate <= 0 ) {
            return;
        }

        $max = self::ini_int( 'max_input_vars' );
        if ( $max <= 0 || $estimate < $max ) {
            return;
        }

        self::$logged_input_vars_warning = true;

        if ( class_exists( 'Logging' ) && method_exists( 'Logging', 'event' ) ) {
            Logging::event(
                'warning',
                'EFORMS_CONFIG_CLAMPED',
                array(
                    'reason' => 'max_input_vars',
                    'estimate' => $estimate,
                    'max_input_vars' => $max,
                    'form_id' => $form_id,
                )
            );
        }
    }

    private static function ini_int( $key ) {
        if ( ! is_string( $key ) || $key === '' ) {
            return 0;
        }

        $raw = ini_get( $key );
        if ( $raw === false ) {
            return 0;
        }

        return (int) $raw;
    }

    private static function uploads_dir( $config ) {
        if ( is_array( $config ) && isset( $config['uploads'] ) && is_array( $config['uploads'] ) ) {
            if ( isset( $config['uploads']['dir'] ) && is_string( $config['uploads']['dir'] ) && $config['uploads']['dir'] !== '' ) {
                return rtrim( $config['uploads']['dir'], '/\\' );
            }
        }

        return '';
    }

    private static function enqueue_assets( $config, $challenge_rendered ) {
        $css_disabled = false;
        if ( is_array( $config ) && isset( $config['assets'] ) && is_array( $config['assets'] ) ) {
            if ( isset( $config['assets']['css_disable'] ) ) {
                $css_disabled = (bool) $config['assets']['css_disable'];
            }
        }

        $css_path = dirname( __DIR__, 2 ) . '/assets/forms.css';
        $js_path = dirname( __DIR__, 2 ) . '/assets/forms.js';

        if ( ! $css_disabled && function_exists( 'wp_enqueue_style' ) && is_file( $css_path ) ) {
            $url = self::asset_url( 'assets/forms.css' );
            $ver = filemtime( $css_path );
            wp_enqueue_style( 'eforms', $url, array(), $ver );
        }

        if ( function_exists( 'wp_enqueue_script' ) && is_file( $js_path ) ) {
            $url = self::asset_url( 'assets/forms.js' );
            $ver = filemtime( $js_path );
            wp_enqueue_script( 'eforms', $url, array(), $ver, true );
        }

        if ( $challenge_rendered && function_exists( 'wp_enqueue_script' ) ) {
            wp_enqueue_script(
                'eforms-turnstile',
                'https://challenges.cloudflare.com/turnstile/v0/api.js',
                array(),
                null,
                true
            );

            if ( function_exists( 'wp_script_add_data' ) ) {
                wp_script_add_data( 'eforms-turnstile', 'defer', true );
                wp_script_add_data( 'eforms-turnstile', 'crossorigin', 'anonymous' );
            }
        }
    }

    private static function asset_url( $relative ) {
        $plugin_file = dirname( __DIR__, 2 ) . '/eforms.php';
        if ( function_exists( 'plugins_url' ) ) {
            return plugins_url( $relative, $plugin_file );
        }

        return $relative;
    }

    private static function render_form( $context, $mode, $security, $config, $errors, $values, $challenge, $email_failure ) {
        $form_id = isset( $context['id'] ) ? $context['id'] : '';
        $email_retry = is_array( $email_failure ) && ! empty( $email_failure['email_retry'] );
        $email_failure_summary = is_array( $email_failure ) && isset( $email_failure['email_failure_summary'] )
            ? $email_failure['email_failure_summary']
            : '';
        $email_failure_remint = is_array( $email_failure ) && ! empty( $email_failure['email_failure_remint'] );
        // Educational note: expose TTL max so forms.js can cap sessionStorage reuse.
        $token_ttl_max = class_exists( 'Anchors' ) ? Anchors::get( 'TOKEN_TTL_MAX' ) : null;

        $attrs = array(
            'class' => 'eforms-form eforms-form-' . $form_id,
            'method' => 'post',
        );
        // Educational note: expose the server-selected mode so mixed-mode pages stay deterministic.
        $attrs['data-eforms-mode'] = $mode;

        if ( $email_failure_remint ) {
            $attrs['data-eforms-remint'] = '1';
        }
        if ( is_int( $token_ttl_max ) && $token_ttl_max > 0 ) {
            $attrs['data-eforms-token-ttl-max'] = (string) $token_ttl_max;
        }

        if ( ! empty( $context['has_uploads'] ) ) {
            $attrs['enctype'] = 'multipart/form-data';
        }

        $client_validation = true;
        if ( is_array( $config ) && isset( $config['html5'] ) && is_array( $config['html5'] ) ) {
            if ( isset( $config['html5']['client_validation'] ) ) {
                $client_validation = (bool) $config['html5']['client_validation'];
            }
        }

        if ( ! $client_validation ) {
            $attrs['novalidate'] = 'novalidate';
        }

        $parts = array();
        $parts[] = '<form ' . self::attrs_to_string( $attrs ) . '>';
        $parts[] = self::render_hidden_input( 'eforms_mode', $mode );
        $parts[] = self::render_hidden_input( 'eforms_token', $security['token'] );
        $parts[] = self::render_hidden_input( 'instance_id', $security['instance_id'] );
        $parts[] = self::render_hidden_input( 'timestamp', $security['timestamp'] );
        $parts[] = self::render_hidden_input( 'js_ok', '' );
        if ( $email_retry ) {
            $parts[] = self::render_hidden_input( 'eforms_email_retry', '1' );
        }
        $parts[] = self::render_honeypot( $form_id );

        $summary = self::render_error_summary( $context, $errors );
        if ( $summary !== '' ) {
            $parts[] = $summary;
        }

        if ( is_string( $email_failure_summary ) && $email_failure_summary !== '' ) {
            $parts[] = self::render_email_failure_copy( $email_failure_summary );
        }

        $fields_html = self::render_fields( $context, $errors, $values );
        if ( $fields_html === null ) {
            return self::render_error( 'EFORMS_ERR_SCHEMA_OBJECT' );
        }
        $parts[] = $fields_html;

        $challenge_html = self::render_challenge_widget( $challenge );
        if ( $challenge_html !== '' ) {
            $parts[] = $challenge_html;
        }

        $submit = isset( $context['submit_button_text'] ) && is_string( $context['submit_button_text'] )
            ? $context['submit_button_text']
            : 'Submit';
        $parts[] = '<button type="submit">' . self::escape_html( $submit ) . '</button>';
        $parts[] = '</form>';

        return implode( '', $parts );
    }

    private static function render_fields( $context, $errors, $values ) {
        if ( ! is_array( $context ) || ! isset( $context['descriptors'], $context['fields'] ) ) {
            return null;
        }

        $descriptors = $context['descriptors'];
        $fields = $context['fields'];
        $last_textlike = self::last_textlike_index( $descriptors );
        $parts = array();
        $form_id = isset( $context['id'] ) ? $context['id'] : '';
        $stack = array();
        $descriptor_index = 0;
        $errors = self::normalize_errors( $errors );
        $values = is_array( $values ) ? $values : array();

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            if ( isset( $field['type'] ) && $field['type'] === 'row_group' ) {
                $mode = isset( $field['mode'] ) && is_string( $field['mode'] ) ? $field['mode'] : '';
                if ( $mode === 'start' ) {
                    $tag = self::row_group_tag( $field );
                    $parts[] = '<' . $tag . ' ' . self::attrs_to_string( array( 'class' => self::row_group_class( $field ) ) ) . '>';
                    $stack[] = $tag;
                } elseif ( $mode === 'end' ) {
                    if ( empty( $stack ) ) {
                        return null;
                    }
                    $tag = array_pop( $stack );
                    $parts[] = '</' . $tag . '>';
                }
                continue;
            }

            if ( ! isset( $descriptors[ $descriptor_index ] ) ) {
                return null;
            }

            $descriptor = $descriptors[ $descriptor_index ];
            if ( ! is_array( $field ) ) {
                continue;
            }

            $field_key = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
            $field_id = self::field_id( $form_id, $field_key );
            $fieldset_id = self::fieldset_id( $field_id );
            $error_id = self::error_id( $field_id );
            $has_error = self::field_has_errors( $errors, $field_key );
            $error_message = self::field_error_message( $errors, $field_key );
            $field_value = array_key_exists( $field_key, $values ) ? $values[ $field_key ] : null;

            $field_type = isset( $descriptor['type'] ) ? $descriptor['type'] : '';
            if ( $field_type === 'file' || $field_type === 'files' ) {
                $field_value = null;
            }

            $label_text = self::field_label_text( $field, $field_key );
            $label_class = self::field_label_class( $field );
            $label = '<label for="' . self::escape_attr( $field_id ) . '"';
            if ( $label_class !== '' ) {
                $label .= ' class="' . self::escape_attr( $label_class ) . '"';
            }
            $label .= '>' . self::escape_html( $label_text );
            $label .= self::render_required_marker( isset( $field['required'] ) && $field['required'] === true );
            $label .= '</label>';

            $before = isset( $field['before_html'] ) && is_string( $field['before_html'] ) ? $field['before_html'] : '';
            if ( $before !== '' ) {
                $parts[] = $before;
            }

            if ( self::is_choice_group( $descriptor, $field ) ) {
                $group = self::render_choice_group(
                    $descriptor,
                    $field,
                    $form_id,
                    $fieldset_id,
                    $error_id,
                    $has_error,
                    $error_message,
                    $field_value
                );
                if ( $group === null ) {
                    return null;
                }
                $parts[] = $group;
            } else {
                $parts[] = $label;

                $control = self::render_control( $descriptor, $field, $form_id, $descriptor_index === $last_textlike, $field_value );
                if ( $control === null ) {
                    return null;
                }

                if ( $has_error ) {
                    $control = self::inject_attributes(
                        $control,
                        array(
                            'aria-invalid' => 'true',
                            'aria-describedby' => $error_id,
                        )
                    );
                }

                $parts[] = $control;

                if ( $has_error && $error_message !== '' ) {
                    $parts[] = '<span id="' . self::escape_attr( $error_id ) . '" class="eforms-error">'
                        . self::escape_html( $error_message ) . '</span>';
                }
            }

            $after = isset( $field['after_html'] ) && is_string( $field['after_html'] ) ? $field['after_html'] : '';
            if ( $after !== '' ) {
                $parts[] = $after;
            }

            $descriptor_index += 1;
        }

        if ( $descriptor_index !== count( $descriptors ) ) {
            return null;
        }

        if ( ! empty( $stack ) ) {
            return null;
        }

        return implode( '', $parts );
    }

    private static function is_choice_group( $descriptor, $field ) {
        if ( ! is_array( $descriptor ) || ! isset( $descriptor['type'] ) ) {
            return false;
        }

        if ( ! in_array( $descriptor['type'], array( 'radio', 'checkbox' ), true ) ) {
            return false;
        }

        return is_array( $field ) && isset( $field['options'] ) && is_array( $field['options'] );
    }

    private static function render_choice_group( $descriptor, $field, $form_id, $fieldset_id, $error_id, $has_error, $error_message, $value ) {
        if ( ! is_array( $field ) ) {
            return null;
        }

        $label_text = self::field_label_text( $field, isset( $field['key'] ) ? $field['key'] : '' );
        $label_class = self::field_label_class( $field );
        $legend = '<legend';
        if ( $label_class !== '' ) {
            $legend .= ' class="' . self::escape_attr( $label_class ) . '"';
        }
        $legend .= '>' . self::escape_html( $label_text );
        $legend .= self::render_required_marker( isset( $field['required'] ) && $field['required'] === true );
        $legend .= '</legend>';

        $parts = array();
        $parts[] = '<fieldset id="' . self::escape_attr( $fieldset_id ) . '">';
        $parts[] = $legend;

        $options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
        foreach ( $options as $option ) {
            if ( ! is_array( $option ) ) {
                continue;
            }

            $attrs = FieldRenderers_Choice::build_choice_input_attributes(
                $descriptor,
                $field,
                $option,
                array( 'id_prefix' => $form_id ),
                $value
            );
            $attrs['name'] = self::build_field_name( $form_id, isset( $field['key'] ) ? $field['key'] : '', $descriptor );
            if ( isset( $attrs['id'] ) && is_string( $attrs['id'] ) ) {
                $attrs['id'] = Helpers::cap_id( $attrs['id'] );
            }

            if ( $has_error ) {
                $attrs['aria-invalid'] = 'true';
                $attrs['aria-describedby'] = $error_id;
            }

            $label = isset( $option['label'] ) && is_string( $option['label'] ) ? $option['label'] : '';
            if ( $label === '' && isset( $option['key'] ) && is_string( $option['key'] ) ) {
                $label = $option['key'];
            }

            $input = '<input ' . self::attrs_to_string( $attrs ) . ' />';
            $parts[] = '<label>' . $input . ' ' . self::escape_html( $label ) . '</label>';
        }

        if ( $has_error && $error_message !== '' ) {
            $parts[] = '<span id="' . self::escape_attr( $error_id ) . '" class="eforms-error">'
                . self::escape_html( $error_message ) . '</span>';
        }

        $parts[] = '</fieldset>';

        return implode( '', $parts );
    }

    private static function render_error_summary( $context, $errors ) {
        $errors = self::normalize_errors( $errors );
        if ( ! self::has_any_errors( $errors ) ) {
            return '';
        }

        $items = array();
        $global = isset( $errors['_global'] ) && is_array( $errors['_global'] ) ? $errors['_global'] : array();
        foreach ( $global as $entry ) {
            $message = self::error_message_from_entry( $entry );
            if ( $message === '' ) {
                $message = 'Error';
            }
            $items[] = '<li>' . self::escape_html( $message ) . '</li>';
        }

        $fields = isset( $context['fields'] ) && is_array( $context['fields'] ) ? $context['fields'] : array();
        $form_id = isset( $context['id'] ) ? $context['id'] : '';

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) || isset( $field['type'] ) && $field['type'] === 'row_group' ) {
                continue;
            }

            $field_key = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
            if ( ! self::field_has_errors( $errors, $field_key ) ) {
                continue;
            }

            $label_text = self::field_label_text( $field, $field_key );
            $target_id = self::field_id( $form_id, $field_key );
            if ( self::is_choice_group( array( 'type' => isset( $field['type'] ) ? $field['type'] : '' ), $field ) ) {
                $target_id = self::fieldset_id( $target_id );
            }
            $items[] = '<li><a href="#' . self::escape_attr( $target_id ) . '">' . self::escape_html( $label_text ) . '</a></li>';
        }

        if ( empty( $items ) ) {
            return '';
        }

        return '<div class="eforms-error-summary" role="alert" tabindex="-1"><ul>' . implode( '', $items ) . '</ul></div>';
    }

    private static function render_email_failure_copy( $summary ) {
        $attrs = array(
            'class' => 'eforms-email-failure-copy',
            'readonly' => 'readonly',
        );

        return '<textarea ' . self::attrs_to_string( $attrs ) . '>' . self::escape_textarea( $summary ) . '</textarea>';
    }

    private static function parse_errors( $opts ) {
        if ( is_array( $opts ) && array_key_exists( 'errors', $opts ) ) {
            return $opts['errors'];
        }

        return null;
    }

    private static function normalize_errors( $errors ) {
        if ( $errors instanceof Errors ) {
            return $errors->to_array();
        }

        if ( is_array( $errors ) ) {
            return $errors;
        }

        return array();
    }

    private static function has_error_code( $errors, $code ) {
        $errors = self::normalize_errors( $errors );
        if ( ! is_array( $errors ) || ! is_string( $code ) || $code === '' ) {
            return false;
        }

        foreach ( $errors as $entries ) {
            if ( ! is_array( $entries ) ) {
                continue;
            }

            foreach ( $entries as $entry ) {
                if ( is_array( $entry ) && isset( $entry['code'] ) && $entry['code'] === $code ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function has_any_errors( $errors ) {
        if ( ! is_array( $errors ) ) {
            return false;
        }

        if ( isset( $errors['_global'] ) && is_array( $errors['_global'] ) && ! empty( $errors['_global'] ) ) {
            return true;
        }

        foreach ( $errors as $key => $entries ) {
            if ( $key === '_global' ) {
                continue;
            }

            if ( is_array( $entries ) && ! empty( $entries ) ) {
                return true;
            }
        }

        return false;
    }

    private static function field_has_errors( $errors, $field_key ) {
        if ( ! is_array( $errors ) || ! is_string( $field_key ) || $field_key === '' ) {
            return false;
        }

        return isset( $errors[ $field_key ] ) && is_array( $errors[ $field_key ] ) && ! empty( $errors[ $field_key ] );
    }

    private static function field_error_message( $errors, $field_key ) {
        if ( ! self::field_has_errors( $errors, $field_key ) ) {
            return '';
        }

        $entries = $errors[ $field_key ];
        $messages = array();
        foreach ( $entries as $entry ) {
            $message = self::error_message_from_entry( $entry );
            if ( $message !== '' ) {
                $messages[] = $message;
            }
        }

        return implode( ' ', $messages );
    }

    private static function error_message_from_entry( $entry ) {
        if ( is_array( $entry ) && isset( $entry['message'] ) && is_string( $entry['message'] ) && $entry['message'] !== '' ) {
            return $entry['message'];
        }

        if ( is_array( $entry ) && isset( $entry['code'] ) && is_string( $entry['code'] ) && $entry['code'] !== '' ) {
            return $entry['code'];
        }

        return '';
    }

    private static function field_label_text( $field, $field_key ) {
        if ( is_array( $field ) && isset( $field['label'] ) && is_string( $field['label'] ) && $field['label'] !== '' ) {
            return $field['label'];
        }

        if ( ! is_string( $field_key ) || $field_key === '' ) {
            return 'Field';
        }

        $label = str_replace( array( '_', '-' ), ' ', $field_key );
        $label = preg_replace( '/\\s+/', ' ', $label );
        $label = trim( $label );

        return ucwords( $label );
    }

    private static function field_label_class( $field ) {
        if ( is_array( $field ) && isset( $field['label'] ) && is_string( $field['label'] ) && $field['label'] !== '' ) {
            return '';
        }

        return 'screen-reader-text';
    }

    private static function field_id( $form_id, $field_key ) {
        $id = $field_key;
        if ( is_string( $form_id ) && $form_id !== '' ) {
            $id = $form_id . '-' . $field_key;
        }

        return Helpers::cap_id( $id );
    }

    private static function fieldset_id( $field_id ) {
        $id = $field_id . '-group';
        return Helpers::cap_id( $id );
    }

    private static function error_id( $field_id ) {
        $id = 'error-' . $field_id;
        return Helpers::cap_id( $id );
    }

    private static function render_required_marker( $required ) {
        if ( ! $required ) {
            return '';
        }

        return '<span class="eforms-required" aria-hidden="true">*</span>';
    }

    private static function inject_attributes( $html, $attrs ) {
        if ( ! is_string( $html ) || $html === '' || ! is_array( $attrs ) ) {
            return $html;
        }

        $parts = array();
        foreach ( $attrs as $key => $value ) {
            if ( $value === null || $value === '' ) {
                continue;
            }
            $parts[] = $key . '="' . self::escape_attr( $value ) . '"';
        }

        if ( empty( $parts ) ) {
            return $html;
        }

        $extra = ' ' . implode( ' ', $parts );

        return preg_replace( '/<([a-z]+)([^>]*?)(\\s*\\/?)>/', '<$1$2' . $extra . '$3>', $html, 1 );
    }

    private static function row_group_tag( $field ) {
        $tag = 'div';
        if ( is_array( $field ) && isset( $field['tag'] ) && is_string( $field['tag'] ) && $field['tag'] !== '' ) {
            $candidate = strtolower( $field['tag'] );
            if ( in_array( $candidate, array( 'div', 'section' ), true ) ) {
                $tag = $candidate;
            }
        }

        return $tag;
    }

    private static function row_group_class( $field ) {
        $class = 'eforms-row';
        if ( is_array( $field ) && isset( $field['class'] ) && is_string( $field['class'] ) && $field['class'] !== '' ) {
            $class .= ' ' . $field['class'];
        }

        return $class;
    }

    private static function render_control( $descriptor, $field, $form_id, $is_last_textlike, $value ) {
        if ( ! is_array( $descriptor ) || ! is_array( $field ) ) {
            return null;
        }

        if ( ! isset( $descriptor['handlers']['r'] ) || ! is_callable( $descriptor['handlers']['r'] ) ) {
            return null;
        }

        $render_context = array(
            'id_prefix' => isset( $descriptor['id_prefix'] ) ? $descriptor['id_prefix'] : '',
        );

        try {
            $html = call_user_func( $descriptor['handlers']['r'], $descriptor, $field, $value, $render_context );
        } catch ( RuntimeException $exception ) {
            return null;
        }

        $field_key = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
        if ( $field_key !== '' ) {
            $name = self::build_field_name( $form_id, $field_key, $descriptor );
            $html = self::replace_name_attribute( $html, $field_key, $name );
        }

        if ( $is_last_textlike ) {
            $html = self::inject_enterkeyhint( $html );
        }

        return self::cap_id_attribute( $html );
    }

    private static function last_textlike_index( $descriptors ) {
        if ( ! is_array( $descriptors ) ) {
            return -1;
        }

        $last = -1;
        foreach ( $descriptors as $index => $descriptor ) {
            if ( self::is_textlike_descriptor( $descriptor ) ) {
                $last = $index;
            }
        }

        return $last;
    }

    private static function is_textlike_descriptor( $descriptor ) {
        if ( ! is_array( $descriptor ) || ! isset( $descriptor['type'] ) ) {
            return false;
        }

        $type = $descriptor['type'];
        if ( $type === 'textarea' ) {
            return true;
        }

        $textlike = array(
            'text',
            'email',
            'url',
            'tel',
            'tel_us',
            'zip_us',
            'zip',
            'number',
            'range',
            'date',
            'name',
            'first_name',
            'last_name',
        );

        return in_array( $type, $textlike, true );
    }

    private static function build_field_name( $form_id, $field_key, $descriptor ) {
        $name = $form_id . '[' . $field_key . ']';
        if ( is_array( $descriptor ) && ! empty( $descriptor['is_multivalue'] ) ) {
            $name .= '[]';
        }

        return $name;
    }

    private static function replace_name_attribute( $html, $field_key, $name ) {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        $search = 'name="' . self::escape_attr( $field_key ) . '"';
        $replace = 'name="' . self::escape_attr( $name ) . '"';

        return str_replace( $search, $replace, $html );
    }

    private static function inject_enterkeyhint( $html ) {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        if ( strpos( $html, 'enterkeyhint=' ) !== false ) {
            return $html;
        }

        return preg_replace( '/<([a-z]+)\\s+/', '<$1 enterkeyhint="send" ', $html, 1 );
    }

    private static function cap_id_attribute( $html ) {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        return preg_replace_callback(
            '/\\bid="([^"]+)"/',
            function ( $matches ) {
                if ( ! isset( $matches[1] ) ) {
                    return $matches[0];
                }

                $capped = Helpers::cap_id( $matches[1] );
                return 'id="' . self::escape_attr( $capped ) . '"';
            },
            $html
        );
    }

    private static function render_hidden_input( $name, $value ) {
        return '<input type="hidden" name="' . self::escape_attr( $name ) . '" value="' . self::escape_attr( $value ) . '" />';
    }

    private static function render_honeypot( $form_id ) {
        $id = $form_id !== '' ? $form_id . '-eforms_hp' : 'eforms_hp';
        $id = Helpers::cap_id( $id );
        $attrs = array(
            'type' => 'text',
            'name' => 'eforms_hp',
            'id' => $id,
            'autocomplete' => 'off',
            'tabindex' => '-1',
            'aria-hidden' => 'true',
        );

        return '<input ' . self::attrs_to_string( $attrs ) . ' />';
    }

    private static function render_challenge_widget( $challenge ) {
        if ( ! is_array( $challenge ) || empty( $challenge['render'] ) ) {
            return '';
        }

        $provider = isset( $challenge['provider'] ) && is_string( $challenge['provider'] ) ? $challenge['provider'] : '';
        $site_key = isset( $challenge['site_key'] ) && is_string( $challenge['site_key'] ) ? $challenge['site_key'] : '';
        if ( $provider !== 'turnstile' || $site_key === '' ) {
            return '';
        }

        $attrs = array(
            'class' => 'cf-turnstile',
            'data-sitekey' => $site_key,
        );

        return '<div class="eforms-challenge" data-eforms-challenge="turnstile"><div '
            . self::attrs_to_string( $attrs )
            . '></div></div>';
    }

    private static function attrs_to_string( $attrs ) {
        $parts = array();
        foreach ( $attrs as $key => $value ) {
            if ( $value === null || $value === '' ) {
                $parts[] = $key;
                continue;
            }

            $parts[] = $key . '="' . self::escape_attr( $value ) . '"';
        }

        return implode( ' ', $parts );
    }

    private static function error_code_from_result( $result ) {
        if ( is_array( $result ) && isset( $result['errors'] ) ) {
            return self::error_code_from_errors( $result['errors'] );
        }

        return 'EFORMS_ERR_SCHEMA_OBJECT';
    }

    private static function error_code_from_errors( $errors ) {
        if ( $errors instanceof Errors ) {
            $data = $errors->to_array();
            if ( isset( $data['_global'] ) && is_array( $data['_global'] ) ) {
                foreach ( $data['_global'] as $entry ) {
                    if ( is_array( $entry ) && isset( $entry['code'] ) && is_string( $entry['code'] ) ) {
                        return $entry['code'];
                    }
                }
            }
        }

        return 'EFORMS_ERR_SCHEMA_OBJECT';
    }

    private static function render_error( $code ) {
        if ( function_exists( 'eforms_render_error' ) ) {
            return eforms_render_error( $code );
        }

        $message = self::error_message( $code );
        return '<div class="eforms-error" data-eforms-error="' . self::escape_attr( $code ) . '">' . self::escape_html( $message ) . '</div>';
    }

    private static function error_message( $code ) {
        if ( $code === 'EFORMS_ERR_STORAGE_UNAVAILABLE' ) {
            return 'Form configuration error: server storage is unavailable.';
        }

        if ( $code === 'EFORMS_ERR_DUPLICATE_FORM_ID' ) {
            return 'Form configuration error: duplicate form id on page.';
        }

        if ( $code === 'EFORMS_ERR_THROTTLED' ) {
            return 'Please wait a moment and try again.';
        }

        return 'Form configuration error.';
    }

    private static function escape_attr( $value ) {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }

    private static function escape_html( $value ) {
        if ( function_exists( 'esc_html' ) ) {
            return esc_html( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }

    private static function escape_textarea( $value ) {
        if ( function_exists( 'esc_textarea' ) ) {
            return esc_textarea( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}
