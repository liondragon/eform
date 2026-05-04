<?php
/**
 * Renderer helper for upload fields.
 *
 * Educational note: upload rendering only emits browser hints; server-side
 * upload validation and storage remain authoritative.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 */

require_once __DIR__ . '/../../Uploads/UploadPolicy.php';

class FieldRenderers_Upload {
    public static function render( $descriptor, $field, $value = null, $context = array() ) {
        $attrs = self::build_attributes( $descriptor, $field, $context );
        return self::render_input( $attrs );
    }

    public static function build_attributes( $descriptor, $field, $context = array() ) {
        $attrs = array(
            'type' => 'file',
        );

        if ( is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
            $attrs['name'] = $field['key'];

            $prefix = '';
            if ( is_array( $context ) && isset( $context['id_prefix'] ) && is_string( $context['id_prefix'] ) ) {
                $prefix = $context['id_prefix'];
            }

            $attrs['id'] = $prefix !== '' ? $prefix . '-' . $field['key'] : $field['key'];
        }

        if ( is_array( $field ) && isset( $field['required'] ) && $field['required'] === true ) {
            $attrs['required'] = 'required';
        }

        $accept = self::accept_attribute( $field );
        if ( $accept !== '' ) {
            $attrs['accept'] = $accept;
        }

        if ( is_array( $descriptor ) && ! empty( $descriptor['is_multivalue'] ) ) {
            $attrs['multiple'] = 'multiple';
        }

        return $attrs;
    }

    private static function accept_attribute( $field ) {
        $accept_defined = is_array( $field ) && array_key_exists( 'accept', $field );
        $accept_value = $accept_defined ? $field['accept'] : array();
        $tokens = UploadPolicy::resolve_tokens( $accept_value, ! $accept_defined );
        if ( empty( $tokens ) ) {
            return '';
        }

        $policy = UploadPolicy::policy_for_tokens( $tokens );
        $entries = array();

        if ( isset( $policy['mimes'] ) && is_array( $policy['mimes'] ) ) {
            foreach ( $policy['mimes'] as $mime ) {
                if ( is_string( $mime ) && $mime !== '' ) {
                    $entries[] = $mime;
                }
            }
        }

        if ( isset( $policy['extensions'] ) && is_array( $policy['extensions'] ) ) {
            foreach ( $policy['extensions'] as $extension ) {
                if ( is_string( $extension ) && $extension !== '' ) {
                    $entries[] = '.' . ltrim( $extension, '.' );
                }
            }
        }

        return implode( ',', array_values( array_unique( $entries ) ) );
    }

    private static function render_input( $attrs ) {
        $parts = array();

        foreach ( $attrs as $key => $value ) {
            if ( $value === null ) {
                continue;
            }

            $parts[] = $key . '="' . self::escape_attr( $value ) . '"';
        }

        return '<input ' . implode( ' ', $parts ) . ' />';
    }

    private static function escape_attr( $value ) {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}
