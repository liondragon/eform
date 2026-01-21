<?php
/**
 * FormRenderer for GET render (hidden-mode + JS-minted markup).
 *
 * Spec: Request lifecycle GET (docs/Canonical_Spec.md#sec-request-lifecycle-get)
 * Spec: Success behavior (docs/Canonical_Spec.md#sec-success)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Errors.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Rendering/TemplateContext.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Security/StorageHealth.php';
if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}

class FormRenderer {
    private static $rendered_form_ids = array();
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

        $mode = $cacheable ? 'js' : 'hidden';
        $needs_cache_headers = self::needs_cache_headers( $mode );
        $headers_ok = self::ensure_cache_headers( $needs_cache_headers );
        if ( $mode === 'hidden' && ! $headers_ok ) {
            return self::render_error( 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }

        self::maybe_log_input_vars( $context, $config, $form_id );

        $security = array(
            'token' => '',
            'instance_id' => '',
            'timestamp' => '',
        );

        if ( $mode === 'hidden' ) {
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

        self::mark_rendered( $form_id );
        self::enqueue_assets();

        return self::render_form( $context, $mode, $security, $config );
    }

    /**
     * Test helper to reset renderer state.
     */
    public static function reset_for_tests() {
        self::$rendered_form_ids = array();
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

    private static function is_duplicate_form_id( $form_id ) {
        return isset( self::$rendered_form_ids[ $form_id ] );
    }

    private static function mark_rendered( $form_id ) {
        self::$rendered_form_ids[ $form_id ] = true;
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

    private static function enqueue_assets() {
        $css_path = dirname( __DIR__, 2 ) . '/assets/forms.css';
        $js_path = dirname( __DIR__, 2 ) . '/assets/forms.js';

        if ( function_exists( 'wp_enqueue_style' ) && is_file( $css_path ) ) {
            $url = self::asset_url( 'assets/forms.css' );
            $ver = filemtime( $css_path );
            wp_enqueue_style( 'eforms', $url, array(), $ver );
        }

        if ( function_exists( 'wp_enqueue_script' ) && is_file( $js_path ) ) {
            $url = self::asset_url( 'assets/forms.js' );
            $ver = filemtime( $js_path );
            wp_enqueue_script( 'eforms', $url, array(), $ver, true );
        }
    }

    private static function asset_url( $relative ) {
        $plugin_file = dirname( __DIR__, 2 ) . '/eforms.php';
        if ( function_exists( 'plugins_url' ) ) {
            return plugins_url( $relative, $plugin_file );
        }

        return $relative;
    }

    private static function render_form( $context, $mode, $security, $config ) {
        $form_id = isset( $context['id'] ) ? $context['id'] : '';

        $attrs = array(
            'class' => 'eforms-form eforms-form-' . $form_id,
            'method' => 'post',
        );

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
        $parts[] = self::render_honeypot( $form_id );

        $fields_html = self::render_fields( $context );
        if ( $fields_html === null ) {
            return self::render_error( 'EFORMS_ERR_SCHEMA_OBJECT' );
        }
        $parts[] = $fields_html;

        $submit = isset( $context['submit_button_text'] ) && is_string( $context['submit_button_text'] )
            ? $context['submit_button_text']
            : 'Submit';
        $parts[] = '<button type="submit">' . self::escape_html( $submit ) . '</button>';
        $parts[] = '</form>';

        return implode( '', $parts );
    }

    private static function render_fields( $context ) {
        if ( ! is_array( $context ) || ! isset( $context['descriptors'], $context['fields'] ) ) {
            return null;
        }

        $descriptors = $context['descriptors'];
        $fields = $context['fields'];
        $render_fields = array();

        foreach ( $fields as $field ) {
            if ( is_array( $field ) && isset( $field['type'] ) && $field['type'] === 'row_group' ) {
                continue;
            }
            $render_fields[] = $field;
        }

        if ( count( $descriptors ) !== count( $render_fields ) ) {
            return null;
        }

        $last_textlike = self::last_textlike_index( $descriptors );
        $parts = array();
        $form_id = isset( $context['id'] ) ? $context['id'] : '';

        foreach ( $descriptors as $index => $descriptor ) {
            $field = $render_fields[ $index ];
            if ( ! is_array( $field ) ) {
                continue;
            }

            $before = isset( $field['before_html'] ) && is_string( $field['before_html'] ) ? $field['before_html'] : '';
            if ( $before !== '' ) {
                $parts[] = $before;
            }

            $control = self::render_control( $descriptor, $field, $form_id, $index === $last_textlike );
            if ( $control === null ) {
                return null;
            }
            $parts[] = $control;

            $after = isset( $field['after_html'] ) && is_string( $field['after_html'] ) ? $field['after_html'] : '';
            if ( $after !== '' ) {
                $parts[] = $after;
            }
        }

        return implode( '', $parts );
    }

    private static function render_control( $descriptor, $field, $form_id, $is_last_textlike ) {
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
            $html = call_user_func( $descriptor['handlers']['r'], $descriptor, $field, '', $render_context );
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
}
