<?php
/**
 * Renderer helper for textarea fields.
 *
 * Educational note: this helper mirrors the textarea-specific attributes and
 * escapes content safely for HTML output.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 */

class FieldRenderers_Textarea {
    public static function render( $descriptor, $field, $value = '', $context = array() ) {
        $attrs = self::build_attributes( $descriptor, $field, $context );
        $content = $value === null || ! is_scalar( $value ) ? '' : (string) $value;

        return self::render_textarea( $attrs, $content );
    }

    public static function build_attributes( $descriptor, $field, $context = array() ) {
        $attrs = array(
            'name' => '',
            'id' => '',
        );

        if ( is_array( $field ) && isset( $field['max_length'] ) ) {
            $attrs['maxlength'] = (int) $field['max_length'];
        }

        if ( is_array( $field ) && isset( $field['required'] ) && $field['required'] === true ) {
            $attrs['required'] = 'required';
        }

        if ( is_array( $field ) && isset( $field['placeholder'] ) && is_string( $field['placeholder'] ) ) {
            $attrs['placeholder'] = $field['placeholder'];
        }

        if ( is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
            $attrs['name'] = $field['key'];

            $prefix = '';
            if ( is_array( $context ) && isset( $context['id_prefix'] ) && is_string( $context['id_prefix'] ) ) {
                $prefix = $context['id_prefix'];
            }

            $attrs['id'] = $prefix !== '' ? $prefix . '-' . $field['key'] : $field['key'];
        }

        if ( $attrs['name'] === '' ) {
            unset( $attrs['name'] );
        }

        if ( $attrs['id'] === '' ) {
            unset( $attrs['id'] );
        }

        return $attrs;
    }

    private static function render_textarea( $attrs, $content ) {
        $parts = array();

        foreach ( $attrs as $key => $value ) {
            if ( $value === null ) {
                continue;
            }

            $parts[] = $key . '="' . self::escape_attr( $value ) . '"';
        }

        return '<textarea ' . implode( ' ', $parts ) . '>' . self::escape_textarea( $content ) . '</textarea>';
    }

    private static function escape_attr( $value ) {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $value );
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
