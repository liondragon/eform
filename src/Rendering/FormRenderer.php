<?php
/**
 * FormRenderer for GET render (hidden-mode + JS-minted markup).
 *
 * Contract: Request lifecycle GET
 * Contract: Success behavior
 * Contract: Cache-safety
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../ErrorMessages.php';
require_once __DIR__ . '/../Errors.php';
require_once __DIR__ . '/../EformsAssets.php';
require_once __DIR__ . '/../EformsMarkup.php';
require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Validation/FieldTypes/TextLike.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Rendering/TemplateContext.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Security/Challenge.php';
require_once __DIR__ . '/../Security/StorageHealth.php';
if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}

class FormRenderer {
    private static $rendered_form_ids = array();
    private static $logged_header_warning = false;
    private static $logged_input_vars_warning = false;
    private static $headers_sent_override = null;
    private static $script_settings_enqueued = false;

    /**
     * Render a form by slug (template filename stem).
     *
     * @param string $slug Template slug from shortcode/template tag.
     * @param array $opts Options (e.g., cacheable).
     * @return string HTML output.
     */
    public static function render( $slug, $opts = array() ) {
        $config = Config::get();

        $template = TemplateLoader::load( $slug );
        if ( ! is_array( $template ) || empty( $template['ok'] ) ) {
            $code = Errors::first_code( is_array( $template ) && isset( $template['errors'] ) ? $template['errors'] : null );
            return self::render_error( $code );
        }

        $context_result = TemplateContext::build( $template['template'], $template['version'] );
        if ( ! is_array( $context_result ) || empty( $context_result['ok'] ) ) {
            $code = Errors::first_code( isset( $context_result['errors'] ) ? $context_result['errors'] : null );
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

        $opts = self::apply_public_rerender_options( $form_id, $opts );
        $cacheable = self::parse_cacheable( $opts );
        $security_override = self::parse_security_override( $opts );
        $force_cache_headers = self::parse_force_cache_headers( $opts );

        if ( self::is_duplicate_form_id( $form_id ) ) {
            return self::render_error( 'EFORMS_ERR_DUPLICATE_FORM_ID' );
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
        $validated_upload_batches = self::parse_validated_upload_batches( $opts );

        self::mark_rendered( $form_id );
        self::enqueue_assets( $config, $challenge, ! empty( $context['staged_field'] ) );

        return self::render_form(
            $context,
            $mode,
            $security,
            $config,
            $errors,
            $values,
            $challenge,
            $validated_upload_batches
        );
    }

    /**
     * Test helper to reset renderer state.
     */
    public static function reset_for_tests() {
        self::$rendered_form_ids = array();
        self::$logged_header_warning = false;
        self::$logged_input_vars_warning = false;
        self::$headers_sent_override = null;
        self::$script_settings_enqueued = false;
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

    private static function parse_validated_upload_batches( $opts ) {
        if ( is_array( $opts ) && isset( $opts['validated_upload_batches'] ) && is_array( $opts['validated_upload_batches'] ) ) {
            return $opts['validated_upload_batches'];
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

        $metadata = Challenge::public_metadata( $config );
        if ( empty( $metadata ) ) {
            $render = false;
        }

        return array(
            'render' => $render,
            'metadata' => $metadata,
        );
    }

    private static function apply_public_rerender_options( $form_id, $opts ) {
        if ( ! class_exists( 'PublicRequestController' ) || ! method_exists( 'PublicRequestController', 'local_rerender_options' ) ) {
            return $opts;
        }

        $local = PublicRequestController::local_rerender_options( $form_id );
        if ( ! is_array( $local ) ) {
            return $opts;
        }

        return array_merge( is_array( $opts ) ? $opts : array(), $local );
    }

    private static function is_duplicate_form_id( $form_id ) {
        return isset( self::$rendered_form_ids[ $form_id ] );
    }

    private static function mark_rendered( $form_id ) {
        self::$rendered_form_ids[ $form_id ] = true;
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

    private static function enqueue_assets( $config, $challenge, $with_upload ) {
        EformsAssets::enqueue_form( $config, $with_upload );
        if ( function_exists( 'wp_enqueue_script' ) ) {
            self::enqueue_script_settings( $config );
        }

        $script_url = is_array( $challenge ) && ! empty( $challenge['render'] ) && isset( $challenge['metadata'][ FormProtocol::CHALLENGE_SCRIPT_URL ] ) && is_string( $challenge['metadata'][ FormProtocol::CHALLENGE_SCRIPT_URL ] )
            ? $challenge['metadata'][ FormProtocol::CHALLENGE_SCRIPT_URL ]
            : '';
        if ( $script_url !== '' && function_exists( 'wp_enqueue_script' ) ) {
            wp_enqueue_script(
                'eforms-turnstile',
                $script_url,
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

    private static function enqueue_script_settings( $config ) {
        if ( self::$script_settings_enqueued || ! function_exists( 'wp_add_inline_script' ) ) {
            return;
        }

        $endpoint_json = self::json_encode( self::mint_endpoint_url() );
        $upload_endpoint_json = self::json_encode( self::upload_batch_endpoint_url() );
        $protocol_json = self::json_encode( FormProtocol::browser_settings() );
        $preparation_mode = Config::value( $config, array( 'media', 'client_preparation' ), Config::CLIENT_PREPARATION_OFF );
        $preparation_worker_url = EformsAssets::same_origin_versioned_url( 'assets/client-image-preparer.js' );
        $preparation = null;
        if ( $preparation_mode === Config::CLIENT_PREPARATION_OPPORTUNISTIC_JPEG && $preparation_worker_url !== '' ) {
            $preparation = array(
                'workerUrl' => $preparation_worker_url,
                'recipe' => FormProtocol::client_preparation_recipe(),
            );
        }
        $preparation_json = self::json_encode( $preparation );
        if ( $endpoint_json === '' || $upload_endpoint_json === '' || $protocol_json === '' || $preparation_json === '' ) {
            return;
        }

        wp_add_inline_script(
            'eforms',
            'window.eformsSettings = window.eformsSettings || {};'
                . 'window.eformsSettings.mintEndpoint = ' . $endpoint_json . ';'
                . 'window.eformsSettings.uploadBatchEndpoint = ' . $upload_endpoint_json . ';'
                . 'window.eformsSettings.protocol = ' . $protocol_json . ';'
                . 'window.eformsSettings.clientPreparation = ' . $preparation_json . ';',
            'before'
        );
        self::$script_settings_enqueued = true;
    }

    private static function mint_endpoint_url() {
        if ( function_exists( 'rest_url' ) ) {
            $url = rest_url( 'eforms/mint' );
            if ( is_string( $url ) && $url !== '' ) {
                return $url;
            }
        }

        return '/eforms/mint';
    }

    private static function upload_batch_endpoint_url() {
        if ( function_exists( 'rest_url' ) ) {
            $url = rest_url( 'eforms/upload-batches' );
            if ( is_string( $url ) && $url !== '' ) {
                return $url;
            }
        }

        return '';
    }

    private static function json_encode( $value ) {
        if ( function_exists( 'wp_json_encode' ) ) {
            $json = wp_json_encode( $value );
        } else {
            $json = json_encode( $value );
        }

        return is_string( $json ) ? $json : '';
    }

    private static function render_form( $context, $mode, $security, $config, $errors, $values, $challenge, $validated_upload_batches ) {
        $form_id = isset( $context['id'] ) ? $context['id'] : '';
        // Educational note: expose TTL max so forms.js can cap sessionStorage reuse.
        $token_ttl_max = class_exists( 'Anchors' ) ? Anchors::get( 'TOKEN_TTL_MAX' ) : null;

        $attrs = array(
            'class' => 'eforms-form eforms-form-' . $form_id,
            'method' => 'post',
        );
        // Educational note: expose the server-selected mode so mixed-mode pages stay deterministic.
        $attrs[ FormProtocol::DATA_MODE ] = $mode;

        if ( is_int( $token_ttl_max ) && $token_ttl_max > 0 ) {
            $attrs[ FormProtocol::DATA_TOKEN_TTL_MAX ] = (string) $token_ttl_max;
        }

        if ( ! empty( $context['has_synchronous_uploads'] ) ) {
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
        $parts[] = '<form ' . EformsMarkup::attributes( $attrs ) . '>';
        $parts[] = self::render_hidden_input( FormProtocol::FIELD_MODE, $mode );
        $parts[] = self::render_hidden_input( FormProtocol::FIELD_TOKEN, $security['token'] );
        $parts[] = self::render_hidden_input( FormProtocol::FIELD_INSTANCE_ID, $security['instance_id'] );
        $parts[] = self::render_hidden_input( FormProtocol::FIELD_TIMESTAMP, $security['timestamp'] );
        $parts[] = self::render_hidden_input( FormProtocol::FIELD_JS_OK, '' );
        $parts[] = self::render_honeypot( $form_id );
        $parts[] = self::render_upload_batch_credentials( $context, $validated_upload_batches );

        $summary = self::render_error_summary( $context, $errors );
        if ( $summary !== '' ) {
            $parts[] = $summary;
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
        $parts[] = '<button type="submit">' . EformsMarkup::escape_html( $submit ) . '</button>';
        $parts[] = '</form>';

        return implode( '', $parts );
    }

    private static function render_upload_batch_credentials( $context, $batches ) {
        if ( ! is_array( $context ) || ! is_array( $batches ) ) {
            return '';
        }

        $field = isset( $context['staged_field'] ) && is_array( $context['staged_field'] ) ? $context['staged_field'] : null;
        $field_key = is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
        if ( $field_key === '' || ! isset( $batches[ $field_key ] ) || ! is_array( $batches[ $field_key ] ) ) {
            return '';
        }

        $batch = $batches[ $field_key ];
        $batch_id = isset( $batch[ FormProtocol::UPLOAD_BATCH_ID ] ) && is_string( $batch[ FormProtocol::UPLOAD_BATCH_ID ] )
            ? $batch[ FormProtocol::UPLOAD_BATCH_ID ]
            : '';
        $batch_secret = isset( $batch[ FormProtocol::UPLOAD_BATCH_SECRET ] ) && is_string( $batch[ FormProtocol::UPLOAD_BATCH_SECRET ] )
            ? $batch[ FormProtocol::UPLOAD_BATCH_SECRET ]
            : '';
        if ( $batch_id === '' || $batch_secret === '' ) {
            return '';
        }

        $prefix = FormProtocol::FIELD_UPLOAD_BATCHES . '[' . $field_key . ']';
        return self::render_hidden_input( $prefix . '[' . FormProtocol::UPLOAD_BATCH_ID . ']', $batch_id )
            . self::render_hidden_input( $prefix . '[' . FormProtocol::UPLOAD_BATCH_SECRET . ']', $batch_secret );
    }

    private static function render_fields( $context, $errors, $values ) {
        if ( ! is_array( $context ) || ! isset( $context['descriptors'], $context['fields'] ) ) {
            return null;
        }

        $descriptors = $context['descriptors'];
        $fields = $context['fields'];
        $last_enterkeyhint = self::last_enterkeyhint_index( $descriptors );
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
                    $parts[] = '<' . $tag . ' ' . EformsMarkup::attributes( array( 'class' => self::row_group_class( $field ) ) ) . '>';
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
            $field_value = array_key_exists( $field_key, $values ) ? $values[ $field_key ] : null;

            $field_type = isset( $descriptor['type'] ) ? $descriptor['type'] : '';
            if ( $field_type === 'file' || $field_type === 'files' ) {
                $field_value = null;
            }

            $label_text = ErrorMessages::field_label_text( $field, $field_key );
            $error_label_text = ErrorMessages::field_error_label_text( $field, $field_key );
            $error_message = self::field_error_message( $errors, $field_key, $error_label_text, $field_type );
            $label_class = self::field_label_class( $field );
            $label = '<label for="' . EformsMarkup::escape_attr( $field_id ) . '"';
            if ( $label_class !== '' ) {
                $label .= ' class="' . EformsMarkup::escape_attr( $label_class ) . '"';
            }
            $label .= '>' . EformsMarkup::escape_html( $label_text );
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

                $control_attrs = array_merge(
                    array(
                        FormProtocol::DATA_FIELD_KEY => $field_key,
                        FormProtocol::DATA_FIELD_CONTROL => '1',
                    ),
                    FieldTypes_TextLike::render_protocol_attributes( $descriptor, $field )
                );
                if ( $has_error ) {
                    $control_attrs['aria-invalid'] = 'true';
                    $control_attrs['aria-describedby'] = $error_id;
                }
                $control = self::render_control(
                    $descriptor,
                    $field,
                    $form_id,
                    $field_id,
                    $descriptor_index === $last_enterkeyhint,
                    $field_value,
                    $control_attrs
                );
                if ( $control === null ) {
                    return null;
                }
                $parts[] = $control;
                $parts[] = self::render_field_error_mount( $field_key, $error_id, $has_error, $error_message );
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

        $label_text = ErrorMessages::field_label_text( $field, isset( $field['key'] ) ? $field['key'] : '' );
        $label_class = self::field_label_class( $field );
        $legend = '<legend';
        if ( $label_class !== '' ) {
            $legend .= ' class="' . EformsMarkup::escape_attr( $label_class ) . '"';
        }
        $legend .= '>' . EformsMarkup::escape_html( $label_text );
        $legend .= self::render_required_marker( isset( $field['required'] ) && $field['required'] === true );
        $legend .= '</legend>';

        $parts = array();
        $parts[] = '<fieldset id="' . EformsMarkup::escape_attr( $fieldset_id ) . '">';
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
            $field_key = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
            $attrs['name'] = self::build_field_name( $form_id, $field_key, $descriptor );
            $attrs[ FormProtocol::DATA_FIELD_KEY ] = $field_key;
            $attrs[ FormProtocol::DATA_FIELD_CONTROL ] = '1';
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

            $input = '<input ' . EformsMarkup::attributes( $attrs ) . ' />';
            $parts[] = '<label>' . $input . ' ' . EformsMarkup::escape_html( $label ) . '</label>';
        }

        $parts[] = self::render_field_error_mount(
            isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '',
            $error_id,
            $has_error,
            $error_message
        );

        $parts[] = '</fieldset>';

        return implode( '', $parts );
    }

    private static function render_field_error_mount( $field_key, $error_id, $has_error, $error_message ) {
        $attrs = array(
            'id' => $error_id,
            'class' => 'eforms-error eforms-field-error',
            FormProtocol::DATA_FIELD_KEY => $field_key,
            FormProtocol::DATA_FIELD_ERROR_MOUNT => '1',
        );
        if ( ! $has_error || $error_message === '' ) {
            $attrs['hidden'] = 'hidden';
        }

        $content = '';
        if ( $has_error && $error_message !== '' ) {
            $content = EformsMarkup::escape_html( $error_message );
        }

        return '<span ' . EformsMarkup::attributes( $attrs ) . '>' . $content . '</span>';
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
            $items[] = '<li>' . EformsMarkup::escape_html( $message ) . '</li>';
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

            $label_text = ErrorMessages::field_error_label_text( $field, $field_key );
            $field_type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
            $target_id = self::field_id( $form_id, $field_key );
            if ( self::is_choice_group( array( 'type' => isset( $field['type'] ) ? $field['type'] : '' ), $field ) ) {
                $target_id = self::fieldset_id( $target_id );
            }
            $summary_text = self::field_error_message( $errors, $field_key, $label_text, $field_type );
            $items[] = '<li><a href="#' . EformsMarkup::escape_attr( $target_id ) . '">' . EformsMarkup::escape_html( $summary_text !== '' ? $summary_text : $label_text ) . '</a></li>';
        }

        if ( empty( $items ) ) {
            return '';
        }

        return '<div class="eforms-error-summary" role="alert" tabindex="-1"><ul>' . implode( '', $items ) . '</ul></div>';
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

    private static function field_error_message( $errors, $field_key, $label_text = '', $field_type = '' ) {
        if ( ! self::field_has_errors( $errors, $field_key ) ) {
            return '';
        }

        $entries = $errors[ $field_key ];
        $messages = array();
        foreach ( $entries as $entry ) {
            $message = self::field_message_from_entry( $entry, $label_text, $field_type );
            if ( $message !== '' ) {
                $messages[] = $message;
            }
        }

        return implode( ' ', $messages );
    }

    private static function field_message_from_entry( $entry, $label_text, $field_type = '' ) {
        $code = is_array( $entry ) && isset( $entry['code'] ) && is_string( $entry['code'] ) ? $entry['code'] : '';
        if ( $code === 'EFORMS_ERR_FIELD_REQUIRED' || $code === 'EFORMS_ERR_FIELD_INVALID' ) {
            return ErrorMessages::field_message( $code, $label_text, $field_type );
        }

        return self::error_message_from_entry( $entry );
    }

    private static function error_message_from_entry( $entry ) {
        if ( is_array( $entry ) && isset( $entry['message'] ) && is_string( $entry['message'] ) && $entry['message'] !== '' ) {
            return $entry['message'];
        }

        if ( is_array( $entry ) && isset( $entry['code'] ) && is_string( $entry['code'] ) && $entry['code'] !== '' ) {
            return ErrorMessages::message( $entry['code'] );
        }

        return '';
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

    private static function render_control( $descriptor, $field, $form_id, $field_id, $is_last_textlike, $value, $attributes ) {
        if ( ! is_array( $descriptor ) || ! is_array( $field ) ) {
            return null;
        }

        if ( ! isset( $descriptor['handlers']['r'] ) || ! is_callable( $descriptor['handlers']['r'] ) ) {
            return null;
        }

        $render_context = array(
            'id_prefix' => isset( $descriptor['id_prefix'] ) ? $descriptor['id_prefix'] : '',
            'id' => $field_id,
            'enterkeyhint' => $is_last_textlike,
            'attributes' => $attributes,
        );
        $field_key = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
        $field_type = isset( $descriptor['type'] ) && is_string( $descriptor['type'] ) ? $descriptor['type'] : '';
        $is_staged_upload = in_array( $field_type, array( 'file', 'files' ), true )
            && isset( $field['upload_mode'] )
            && $field['upload_mode'] === 'staged';
        if ( $field_key !== '' && ! $is_staged_upload ) {
            $render_context['name'] = self::build_field_name( $form_id, $field_key, $descriptor );
        }

        try {
            $html = call_user_func( $descriptor['handlers']['r'], $descriptor, $field, $value, $render_context );
        } catch ( RuntimeException $exception ) {
            return null;
        }

        return $html;
    }

    private static function last_enterkeyhint_index( $descriptors ) {
        if ( ! is_array( $descriptors ) ) {
            return -1;
        }

        $last = -1;
        foreach ( $descriptors as $index => $descriptor ) {
            if ( self::descriptor_accepts_enterkeyhint( $descriptor ) ) {
                $last = $index;
            }
        }

        return $last;
    }

    private static function descriptor_accepts_enterkeyhint( $descriptor ) {
        if ( ! is_array( $descriptor ) || ! isset( $descriptor['html'] ) || ! is_array( $descriptor['html'] ) ) {
            return false;
        }

        return ! empty( $descriptor['html']['enterkeyhint'] );
    }

    private static function build_field_name( $form_id, $field_key, $descriptor ) {
        $name = $form_id . '[' . $field_key . ']';
        if ( is_array( $descriptor ) && ! empty( $descriptor['is_multivalue'] ) ) {
            $name .= '[]';
        }

        return $name;
    }

    private static function render_hidden_input( $name, $value ) {
        return '<input type="hidden" name="' . EformsMarkup::escape_attr( $name ) . '" value="' . EformsMarkup::escape_attr( $value ) . '" />';
    }

    private static function render_honeypot( $form_id ) {
        $id = $form_id !== '' ? $form_id . '-' . FormProtocol::FIELD_HONEYPOT : FormProtocol::FIELD_HONEYPOT;
        $id = Helpers::cap_id( $id );
        $attrs = array(
            'type' => 'text',
            'name' => FormProtocol::FIELD_HONEYPOT,
            'id' => $id,
            'class' => 'eforms-honeypot',
            'autocomplete' => 'off',
            'tabindex' => '-1',
            'aria-hidden' => 'true',
        );

        return '<input ' . EformsMarkup::attributes( $attrs ) . ' />';
    }

    private static function render_challenge_widget( $challenge ) {
        $metadata = is_array( $challenge ) && isset( $challenge['metadata'] ) && is_array( $challenge['metadata'] )
            ? $challenge['metadata']
            : array();
        $provider = isset( $metadata[ FormProtocol::RESPONSE_CHALLENGE_PROVIDER ] ) && is_string( $metadata[ FormProtocol::RESPONSE_CHALLENGE_PROVIDER ] )
            ? $metadata[ FormProtocol::RESPONSE_CHALLENGE_PROVIDER ]
            : '';
        $mount_attrs = array(
            FormProtocol::DATA_CHALLENGE_MOUNT => $provider !== '' ? $provider : '1',
        );
        if ( ! is_array( $challenge ) || empty( $challenge['render'] ) ) {
            $mount_attrs['hidden'] = 'hidden';
            return '<div ' . EformsMarkup::attributes( $mount_attrs ) . '></div>';
        }

        $widget_attrs = Challenge::widget_attributes( $metadata );
        if ( empty( $widget_attrs ) ) {
            $mount_attrs['hidden'] = 'hidden';
            return '<div ' . EformsMarkup::attributes( $mount_attrs ) . '></div>';
        }

        $mount_attrs['class'] = 'eforms-challenge';
        return '<div ' . EformsMarkup::attributes( $mount_attrs ) . '><div '
            . EformsMarkup::attributes( $widget_attrs )
            . '></div></div>';
    }

    private static function render_error( $code ) {
        if ( function_exists( 'eforms_render_error' ) ) {
            return eforms_render_error( $code );
        }

        $message = self::error_message( $code );
        return '<div class="eforms-error" data-eforms-error="' . EformsMarkup::escape_attr( $code ) . '">' . EformsMarkup::escape_html( $message ) . '</div>';
    }

    private static function error_message( $code ) {
        return ErrorMessages::message( $code );
    }

}
