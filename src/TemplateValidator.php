<?php
// src/TemplateValidator.php

class TemplateValidator {
    public const ERR_UNKNOWN_KEY = 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY';
    public const ERR_ENUM = 'EFORMS_ERR_SCHEMA_ENUM';
    public const ERR_REQUIRED_COMBO = 'EFORMS_ERR_SCHEMA_REQUIRED_COMBO';
    public const ERR_ROW_GROUP_SHAPE = 'EFORMS_ERR_SCHEMA_ROW_GROUP_SHAPE';
    public const ERR_ACCEPT_INTERSECTION = 'EFORMS_ERR_SCHEMA_ACCEPT_INTERSECTION';

    /**
     * Keys allowed on individual field definitions.
     *
     * This is intentionally permissive and covers all keys currently used by
     * the plugin. Unknown keys are considered a schema violation.
     */
    private const ALLOWED_FIELD_KEYS = [
        'key',
        'type',
        'label',
        'required',
        'placeholder',
        'options',
        'class',
        'mode',
        'tag',
        'cols',
        'rows',
        'aria-label',
        'aria-required',
        'style',
        'autocomplete',
        'before_html',
        'choices',
        'post_key',
        'pattern',
        'min',
        'max',
        'step',
        'matches',
        'required_if',
        'required_with',
        'required_without',
        'accept',
    ];

    /** Allowed field types. */
    private const ALLOWED_TYPES = [
        'text',
        'name',
        'email',
        'tel',
        'zip',
        'textarea',
        'row_group',
        'checkbox',
        'select',
        'number',
        'url',
        'message',
        'file',
    ];

    /** Allowed row_group modes. */
    private const ROW_GROUP_MODES = ['start', 'end'];

    /**
     * Allowed MIME types/extensions for file inputs. The accept[] property must
     * intersect with this list.
     */
    private const ALLOWED_ACCEPTS = [
        'image/jpeg',
        'image/png',
        'application/pdf',
    ];

    /**
     * Validate a template configuration.
     *
     * @param array $config Parsed template configuration.
     * @return array{valid:bool,code:string,errors:array}
     */
    public function validate( array $config ): array {
        $errors = [];
        $fields = $config['fields'] ?? [];
        $depth  = 0;

        foreach ( $fields as $index => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            // Unknown keys.
            $unknown = array_diff( array_keys( $field ), self::ALLOWED_FIELD_KEYS );
            if ( $unknown ) {
                $errors[] = [
                    'code'    => self::ERR_UNKNOWN_KEY,
                    'index'   => $index,
                    'unknown' => array_values( $unknown ),
                ];
            }

            // Enumerated type.
            if ( isset( $field['type'] ) && ! in_array( $field['type'], self::ALLOWED_TYPES, true ) ) {
                $errors[] = [
                    'code'  => self::ERR_ENUM,
                    'index' => $index,
                    'field' => 'type',
                    'value' => $field['type'],
                ];
            }

            // Row group handling.
            if ( ( $field['type'] ?? '' ) === 'row_group' ) {
                $mode = $field['mode'] ?? '';
                if ( ! in_array( $mode, self::ROW_GROUP_MODES, true ) ) {
                    $errors[] = [
                        'code'  => self::ERR_ENUM,
                        'index' => $index,
                        'field' => 'mode',
                        'value' => $mode,
                    ];
                }
                if ( $mode === 'start' ) {
                    $depth++;
                    if ( empty( $field['tag'] ) ) {
                        $errors[] = [
                            'code'   => self::ERR_REQUIRED_COMBO,
                            'index'  => $index,
                            'fields' => [ 'mode', 'tag' ],
                        ];
                    }
                } elseif ( $mode === 'end' ) {
                    $depth--;
                    if ( $depth < 0 ) {
                        $errors[] = [
                            'code'  => self::ERR_ROW_GROUP_SHAPE,
                            'index' => $index,
                        ];
                        $depth = 0;
                    }
                }
            }

            // accept[] intersection for file inputs.
            if ( isset( $field['accept'] ) ) {
                $accept = $field['accept'];
                if ( ! is_array( $accept ) ) {
                    $accept = [ $accept ];
                }
                $intersection = array_intersect( $accept, self::ALLOWED_ACCEPTS );
                if ( empty( $intersection ) ) {
                    $errors[] = [
                        'code'  => self::ERR_ACCEPT_INTERSECTION,
                        'index' => $index,
                    ];
                }
            }
        }

        if ( $depth !== 0 ) {
            $errors[] = [ 'code' => self::ERR_ROW_GROUP_SHAPE ];
        }

        if ( ! empty( $errors ) ) {
            return [ 'valid' => false, 'code' => $errors[0]['code'], 'errors' => $errors ];
        }

        return [ 'valid' => true, 'code' => '', 'errors' => [] ];
    }
}
