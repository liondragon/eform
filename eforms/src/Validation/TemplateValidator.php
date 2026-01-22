<?php
/**
 * Template schema and envelope validation.
 *
 * Educational note: this file focuses on structural validation and unknown-key
 * rejection. Semantic checks (reserved keys, handler resolution) live elsewhere.
 *
 * Spec: Template JSON (docs/Canonical_Spec.md#sec-template-json)
 * Spec: Template validation (docs/Canonical_Spec.md#sec-template-validation)
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 */

require_once __DIR__ . '/../Errors.php';
require_once __DIR__ . '/FieldTypeRegistry.php';
require_once __DIR__ . '/ValidatorRegistry.php';
require_once __DIR__ . '/NormalizerRegistry.php';
require_once __DIR__ . '/../Rendering/RendererRegistry.php';
require_once __DIR__ . '/EmailTemplateRegistry.php';

class TemplateValidator {
    const ROOT_KEYS = array(
        'id',
        'version',
        'title',
        'success',
        'email',
        'fields',
        'submit_button_text',
        'rules',
    );

    const SUCCESS_KEYS = array(
        'mode',
        'redirect_url',
        'message',
    );

    const EMAIL_KEYS = array(
        'to',
        'subject',
        'email_template',
        'include_fields',
        'display_format_tel',
    );

    const DISPLAY_FORMAT_TEL_TOKENS = array(
        'xxx-xxx-xxxx',
        '(xxx) xxx-xxxx',
        'xxx.xxx.xxxx',
    );

    const FIELD_KEYS = array(
        'key',
        'type',
        'label',
        'placeholder',
        'required',
        'size',
        'autocomplete',
        'options',
        'class',
        'max_length',
        'min',
        'max',
        'step',
        'pattern',
        'before_html',
        'after_html',
        'accept',
        'max_file_bytes',
        'max_files',
        'email_attach',
    );

    const ROW_GROUP_KEYS = array(
        'type',
        'mode',
        'tag',
        'class',
    );

    const OPTION_KEYS = array(
        'key',
        'label',
        'disabled',
    );

    const RULE_KEYS = array(
        'rule',
        'target',
        'field',
        'fields',
        'equals',
        'equals_any',
    );

    const SUCCESS_MODES = array(
        'inline',
        'redirect',
    );

    const ROW_GROUP_MODES = array(
        'start',
        'end',
    );

    const ROW_GROUP_TAGS = array(
        'div',
        'section',
    );

    const FIELD_TYPES = array(
        'text',
        'textarea',
        'email',
        'url',
        'tel',
        'tel_us',
        'number',
        'range',
        'select',
        'radio',
        'checkbox',
        'zip_us',
        'zip',
        'file',
        'files',
        'date',
        'name',
        'first_name',
        'last_name',
        'row_group',
    );

    const RULE_TYPES = array(
        'required_if',
        'required_if_any',
        'required_unless',
        'matches',
        'one_of',
        'mutually_exclusive',
    );

    const RESERVED_KEYS = array(
        'form_id',
        'instance_id',
        'submission_id',
        'eforms_token',
        'eforms_hp',
        'eforms_mode',
        'timestamp',
        'js_ok',
        'eforms_email_retry',
        'ip',
        'submitted_at',
    );

    const INCLUDE_META_KEYS = array(
        'ip',
        'submitted_at',
        'form_id',
        'instance_id',
        'submission_id',
    );

    const FIELD_KEY_PATTERN = '/^[a-z0-9_-]{1,64}$/';

    /**
     * Validate the template envelope and structural schema.
     *
     * @param mixed $template Template data as decoded JSON.
     * @return Errors
     */
    public static function validate_template_envelope( $template ) {
        $errors = new Errors();

        if ( ! is_array( $template ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
            return $errors;
        }

        self::validate_unknown_keys( $template, self::ROOT_KEYS, $errors );
        self::require_keys( $template, array( 'id', 'title', 'success', 'email', 'fields', 'submit_button_text' ), $errors );

        self::validate_string( $template, 'id', $errors );
        self::validate_string( $template, 'version', $errors, false );
        self::validate_string( $template, 'title', $errors );
        self::validate_string( $template, 'submit_button_text', $errors );

        if ( isset( $template['success'] ) ) {
            self::validate_success_block( $template['success'], $errors );
        }

        if ( isset( $template['email'] ) ) {
            self::validate_email_block( $template['email'], $errors );
        }

        if ( isset( $template['fields'] ) ) {
            self::validate_fields( $template['fields'], $errors );
            self::validate_row_group_balance( $template['fields'], $errors );
        }

        if ( isset( $template['rules'] ) ) {
            self::validate_rules( $template['rules'], $errors );
        }

        self::validate_template_semantics( $template, $errors );

        return $errors;
    }

    private static function validate_success_block( $success, $errors ) {
        if ( ! is_array( $success ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
            return;
        }

        self::validate_unknown_keys( $success, self::SUCCESS_KEYS, $errors );
        self::require_keys( $success, array( 'mode' ), $errors );
        self::validate_enum( $success, 'mode', self::SUCCESS_MODES, $errors );
        self::validate_string( $success, 'message', $errors, false );
        self::validate_string( $success, 'redirect_url', $errors, false );

        if ( isset( $success['mode'] ) && $success['mode'] === 'redirect' ) {
            if ( ! isset( $success['redirect_url'] ) || ! is_string( $success['redirect_url'] ) || $success['redirect_url'] === '' ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_REQUIRED' );
            }
        }
    }

    private static function validate_email_block( $email, $errors ) {
        if ( ! is_array( $email ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
            return;
        }

        self::validate_unknown_keys( $email, self::EMAIL_KEYS, $errors );
        self::require_keys( $email, array( 'to', 'subject', 'email_template', 'include_fields' ), $errors );

        $targets = array();
        if ( isset( $email['to'] ) ) {
            if ( is_string( $email['to'] ) ) {
                $targets = array( $email['to'] );
            } elseif ( is_array( $email['to'] ) ) {
                foreach ( $email['to'] as $entry ) {
                    if ( ! is_string( $entry ) ) {
                        $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
                        $targets = array();
                        break;
                    }
                    $targets[] = $entry;
                }
            } else {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
            }
        }

        self::validate_string( $email, 'subject', $errors );
        self::validate_string( $email, 'email_template', $errors );

        if ( isset( $email['email_template'] ) && is_string( $email['email_template'] ) ) {
            if ( ! EmailTemplateRegistry::exists( $email['email_template'] ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_ENUM' );
            }
        }

        if ( ! empty( $targets ) ) {
            foreach ( $targets as $target ) {
                if ( ! is_email( $target ) ) {
                    $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
                    break;
                }
            }
        }

        if ( isset( $email['include_fields'] ) ) {
            if ( ! is_array( $email['include_fields'] ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
            } else {
                foreach ( $email['include_fields'] as $entry ) {
                    if ( ! is_string( $entry ) ) {
                        $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
                        break;
                    }
                }
            }
        }

        if ( array_key_exists( 'display_format_tel', $email ) ) {
            self::validate_enum( $email, 'display_format_tel', self::DISPLAY_FORMAT_TEL_TOKENS, $errors );
        }
    }

    private static function validate_fields( $fields, $errors ) {
        if ( ! is_array( $fields ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
            return;
        }

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
                continue;
            }

            if ( isset( $field['type'] ) && $field['type'] === 'row_group' ) {
                self::validate_row_group( $field, $errors );
                continue;
            }

            self::validate_unknown_keys( $field, self::FIELD_KEYS, $errors );
            self::require_keys( $field, array( 'type', 'key' ), $errors );
            self::validate_enum( $field, 'type', self::FIELD_TYPES, $errors );
            self::validate_string( $field, 'key', $errors );
            self::validate_string( $field, 'label', $errors, false );
            self::validate_string( $field, 'placeholder', $errors, false );
            self::validate_string( $field, 'class', $errors, false );
            self::validate_string( $field, 'autocomplete', $errors, false );
            self::validate_string( $field, 'pattern', $errors, false );
            self::validate_string( $field, 'before_html', $errors, false );
            self::validate_string( $field, 'after_html', $errors, false );
            self::validate_html_fragment( $field, 'before_html', $errors );
            self::validate_html_fragment( $field, 'after_html', $errors );

            self::validate_bool( $field, 'required', $errors, false );
            self::validate_bool( $field, 'email_attach', $errors, false );

            self::validate_int( $field, 'size', $errors, false );
            self::validate_int( $field, 'max_length', $errors, false );
            self::validate_number( $field, 'min', $errors, false );
            self::validate_number( $field, 'max', $errors, false );
            self::validate_number( $field, 'step', $errors, false );

            if ( isset( $field['accept'] ) ) {
                if ( ! is_array( $field['accept'] ) ) {
                    $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
                } else {
                    foreach ( $field['accept'] as $entry ) {
                        if ( ! is_string( $entry ) ) {
                            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
                            break;
                        }
                    }
                }
            }

            if ( isset( $field['options'] ) ) {
                self::validate_options( $field['options'], $errors );
            }

            self::validate_int( $field, 'max_file_bytes', $errors, false );
            self::validate_int( $field, 'max_files', $errors, false );
        }
    }

    /**
     * Semantic preflight (unique keys, reserved keys, handler resolution).
     */
    public static function validate_template_semantics( $template, $errors ) {
        if ( ! is_array( $template ) || ! isset( $template['fields'] ) || ! is_array( $template['fields'] ) ) {
            return;
        }

        $seen = array();

        foreach ( $template['fields'] as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            if ( isset( $field['type'] ) && $field['type'] === 'row_group' ) {
                continue;
            }

            $key = isset( $field['key'] ) ? $field['key'] : null;
            if ( ! is_string( $key ) ) {
                continue;
            }

            if ( preg_match( self::FIELD_KEY_PATTERN, $key ) !== 1 ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_KEY' );
            }

            if ( in_array( $key, self::RESERVED_KEYS, true ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_KEY' );
            }

            if ( isset( $seen[ $key ] ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_DUP_KEY' );
            } else {
                $seen[ $key ] = true;
            }

            if ( isset( $field['type'] ) ) {
                try {
                    $descriptor = FieldTypeRegistry::resolve( $field['type'] );
                } catch ( RuntimeException $exception ) {
                    $errors->add_global( 'EFORMS_ERR_TYPE' );
                    continue;
                }

                self::validate_descriptor_handlers( $descriptor, $errors );
            }
        }

        self::validate_include_fields( $template, $seen, $errors );
    }

    /**
     * Sanitize HTML fragments for before_html/after_html.
     *
     * @param mixed $fields
     * @return mixed
     */
    public static function sanitize_fields( $fields ) {
        if ( ! is_array( $fields ) ) {
            return $fields;
        }

        $sanitized = array();

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                $sanitized[] = $field;
                continue;
            }

            if ( isset( $field['before_html'] ) && is_string( $field['before_html'] ) ) {
                $field['before_html'] = self::sanitize_html_fragment( $field['before_html'] );
            }

            if ( isset( $field['after_html'] ) && is_string( $field['after_html'] ) ) {
                $field['after_html'] = self::sanitize_html_fragment( $field['after_html'] );
            }

            $sanitized[] = $field;
        }

        return $sanitized;
    }

    private static function validate_html_fragment( $field, $key, $errors ) {
        if ( ! is_array( $field ) || ! isset( $field[ $key ] ) || ! is_string( $field[ $key ] ) ) {
            return;
        }

        $value = $field[ $key ];
        if ( self::fragment_has_inline_style( $value ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
        }

        if ( self::fragment_crosses_row_group_boundary( $value ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
        }
    }

    private static function fragment_has_inline_style( $value ) {
        if ( ! is_string( $value ) || $value === '' ) {
            return false;
        }

        return preg_match( '/<\\s*style\\b/i', $value ) === 1
            || preg_match( '/\\sstyle\\s*=\\s*["\']?/i', $value ) === 1;
    }

    private static function fragment_crosses_row_group_boundary( $value ) {
        if ( ! is_string( $value ) || $value === '' ) {
            return false;
        }

        $matches = array();
        preg_match_all( '/<\\s*(\\/?)\\s*(div|section)\\b[^>]*>/i', $value, $matches, PREG_SET_ORDER );
        if ( empty( $matches ) ) {
            return false;
        }

        // Ensure fragments do not leak div/section wrappers across row_group boundaries.
        $stack = array();
        foreach ( $matches as $match ) {
            $is_closing = isset( $match[1] ) && $match[1] === '/';
            $tag = isset( $match[2] ) ? strtolower( $match[2] ) : '';

            if ( ! $is_closing ) {
                $stack[] = $tag;
                continue;
            }

            if ( empty( $stack ) ) {
                return true;
            }

            $last = array_pop( $stack );
            if ( $last !== $tag ) {
                return true;
            }
        }

        return ! empty( $stack );
    }

    /**
     * Resolve handler IDs to ensure registries are wired.
     */
    public static function validate_descriptor_handlers( $descriptor, $errors ) {
        if ( ! is_array( $descriptor ) || ! isset( $descriptor['handlers'] ) || ! is_array( $descriptor['handlers'] ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
            return;
        }

        $handlers = $descriptor['handlers'];
        $required = array( 'validator_id', 'normalizer_id', 'renderer_id' );

        foreach ( $required as $key ) {
            if ( ! isset( $handlers[ $key ] ) || ! is_string( $handlers[ $key ] ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
                return;
            }
        }

        try {
            ValidatorRegistry::resolve( $handlers['validator_id'] );
            NormalizerRegistry::resolve( $handlers['normalizer_id'] );
            RendererRegistry::resolve( $handlers['renderer_id'] );
        } catch ( RuntimeException $exception ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
        }
    }

    private static function validate_row_group( $field, $errors ) {
        self::validate_unknown_keys( $field, self::ROW_GROUP_KEYS, $errors );
        self::require_keys( $field, array( 'type', 'mode' ), $errors );
        self::validate_enum( $field, 'type', self::FIELD_TYPES, $errors );
        self::validate_enum( $field, 'mode', self::ROW_GROUP_MODES, $errors );

        if ( isset( $field['tag'] ) ) {
            self::validate_enum( $field, 'tag', self::ROW_GROUP_TAGS, $errors );
        }

        if ( isset( $field['key'] ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
        }
    }

    private static function validate_row_group_balance( $fields, $errors ) {
        if ( ! is_array( $fields ) ) {
            return;
        }

        $stack = array();
        $unbalanced = false;

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) || ! isset( $field['type'] ) || $field['type'] !== 'row_group' ) {
                continue;
            }

            $mode = isset( $field['mode'] ) && is_string( $field['mode'] ) ? $field['mode'] : '';
            if ( $mode === 'start' ) {
                $stack[] = true;
                continue;
            }

            if ( $mode === 'end' ) {
                if ( empty( $stack ) ) {
                    $unbalanced = true;
                } else {
                    array_pop( $stack );
                }
            }
        }

        if ( $unbalanced || ! empty( $stack ) ) {
            // Spec requires a single global error for any imbalance.
            $errors->add_global( 'EFORMS_ERR_ROW_GROUP_UNBALANCED' );
        }
    }

    private static function validate_include_fields( $template, $field_keys, $errors ) {
        if ( ! is_array( $template ) || ! isset( $template['email'] ) || ! is_array( $template['email'] ) ) {
            return;
        }

        if ( ! isset( $template['email']['include_fields'] ) || ! is_array( $template['email']['include_fields'] ) ) {
            return;
        }

        $allowed = array();
        foreach ( $field_keys as $key => $unused ) {
            $allowed[ $key ] = true;
        }

        foreach ( self::INCLUDE_META_KEYS as $meta_key ) {
            $allowed[ $meta_key ] = true;
        }

        foreach ( $template['email']['include_fields'] as $entry ) {
            if ( ! is_string( $entry ) ) {
                continue;
            }

            if ( ! isset( $allowed[ $entry ] ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY' );
                break;
            }
        }
    }

    private static function sanitize_html_fragment( $value ) {
        if ( function_exists( 'wp_kses_post' ) ) {
            return wp_kses_post( $value );
        }

        return $value;
    }

    private static function validate_options( $options, $errors ) {
        if ( ! is_array( $options ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
            return;
        }

        foreach ( $options as $option ) {
            if ( ! is_array( $option ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
                continue;
            }

            self::validate_unknown_keys( $option, self::OPTION_KEYS, $errors );
            self::require_keys( $option, array( 'key', 'label' ), $errors );
            self::validate_string( $option, 'key', $errors );
            self::validate_string( $option, 'label', $errors );
            self::validate_bool( $option, 'disabled', $errors, false );
        }
    }

    private static function validate_rules( $rules, $errors ) {
        if ( ! is_array( $rules ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
            return;
        }

        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
                continue;
            }

            self::validate_unknown_keys( $rule, self::RULE_KEYS, $errors );
            self::require_keys( $rule, array( 'rule' ), $errors );

            if ( ! isset( $rule['rule'] ) || ! is_string( $rule['rule'] ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
                continue;
            }

            if ( ! in_array( $rule['rule'], self::RULE_TYPES, true ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_ENUM' );
                continue;
            }

            self::validate_rule_shape( $rule, $errors );
        }
    }

    private static function validate_rule_shape( $rule, $errors ) {
        $type = $rule['rule'];

        if ( $type === 'required_if' || $type === 'required_unless' ) {
            self::require_keys( $rule, array( 'target', 'field', 'equals' ), $errors );
            self::validate_string( $rule, 'target', $errors );
            self::validate_string( $rule, 'field', $errors );
            self::validate_string( $rule, 'equals', $errors );
            return;
        }

        if ( $type === 'required_if_any' ) {
            self::require_keys( $rule, array( 'target', 'fields', 'equals_any' ), $errors );
            self::validate_string( $rule, 'target', $errors );
            self::validate_string_array( $rule, 'fields', $errors );
            self::validate_string_array( $rule, 'equals_any', $errors );
            return;
        }

        if ( $type === 'matches' ) {
            self::require_keys( $rule, array( 'target', 'field' ), $errors );
            self::validate_string( $rule, 'target', $errors );
            self::validate_string( $rule, 'field', $errors );
            return;
        }

        if ( $type === 'one_of' || $type === 'mutually_exclusive' ) {
            self::require_keys( $rule, array( 'fields' ), $errors );
            self::validate_string_array( $rule, 'fields', $errors );
        }
    }

    private static function validate_unknown_keys( $value, $allowed, $errors ) {
        foreach ( $value as $key => $unused ) {
            if ( ! in_array( $key, $allowed, true ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY' );
            }
        }
    }

    private static function require_keys( $value, $required, $errors ) {
        foreach ( $required as $key ) {
            if ( ! array_key_exists( $key, $value ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_REQUIRED' );
            }
        }
    }

    private static function validate_string( $value, $key, $errors, $required = true ) {
        if ( ! array_key_exists( $key, $value ) ) {
            if ( $required ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_REQUIRED' );
            }
            return;
        }

        if ( ! is_string( $value[ $key ] ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
        }
    }

    private static function validate_bool( $value, $key, $errors, $required = true ) {
        if ( ! array_key_exists( $key, $value ) ) {
            if ( $required ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_REQUIRED' );
            }
            return;
        }

        if ( ! is_bool( $value[ $key ] ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
        }
    }

    private static function validate_int( $value, $key, $errors, $required = true ) {
        if ( ! array_key_exists( $key, $value ) ) {
            if ( $required ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_REQUIRED' );
            }
            return;
        }

        if ( ! is_int( $value[ $key ] ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
        }
    }

    private static function validate_number( $value, $key, $errors, $required = true ) {
        if ( ! array_key_exists( $key, $value ) ) {
            if ( $required ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_REQUIRED' );
            }
            return;
        }

        if ( ! is_int( $value[ $key ] ) && ! is_float( $value[ $key ] ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
        }
    }

    private static function validate_string_array( $value, $key, $errors ) {
        if ( ! array_key_exists( $key, $value ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_REQUIRED' );
            return;
        }

        if ( ! is_array( $value[ $key ] ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
            return;
        }

        foreach ( $value[ $key ] as $entry ) {
            if ( ! is_string( $entry ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
                break;
            }
        }
    }

    private static function validate_enum( $value, $key, $allowed, $errors ) {
        if ( ! array_key_exists( $key, $value ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_REQUIRED' );
            return;
        }

        if ( ! is_string( $value[ $key ] ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
            return;
        }

        if ( ! in_array( $value[ $key ], $allowed, true ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_ENUM' );
        }
    }
}
