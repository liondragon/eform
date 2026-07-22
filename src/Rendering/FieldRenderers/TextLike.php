<?php
/**
 * Renderer helper for text-like inputs.
 *
 * Educational note: the renderer focuses on attribute assembly; higher-level
 * layout concerns live in FormRenderer.
 *
 * Contract: Field types
 */

require_once __DIR__ . '/../../EformsMarkup.php';

class FieldRenderers_TextLike {
    public static function render( $descriptor, $field, $value = '', $context = array() ) {
        $attrs = self::build_attributes( $descriptor, $field, $context );

        if ( ! isset( $attrs['value'] ) && $value !== null && is_scalar( $value ) ) {
            $attrs['value'] = (string) $value;
        }

        return self::render_input( $attrs );
    }

    public static function build_attributes( $descriptor, $field, $context = array() ) {
        $attrs = array();

        if ( isset( $descriptor['html']['type'] ) ) {
            $attrs['type'] = $descriptor['html']['type'];
        }

        if ( isset( $descriptor['html']['inputmode'] ) ) {
            $attrs['inputmode'] = $descriptor['html']['inputmode'];
        }

        if ( isset( $descriptor['html']['pattern'] ) ) {
            $attrs['pattern'] = $descriptor['html']['pattern'];
        }

        if ( isset( $descriptor['constants'] ) && is_array( $descriptor['constants'] ) ) {
            foreach ( $descriptor['constants'] as $key => $value ) {
                $attrs[ $key ] = $value;
            }
        }

        $autocomplete = '';
        if ( is_array( $field ) && isset( $field['autocomplete'] ) && is_string( $field['autocomplete'] ) ) {
            $autocomplete = $field['autocomplete'];
        } elseif ( isset( $descriptor['defaults']['autocomplete'] ) ) {
            $autocomplete = $descriptor['defaults']['autocomplete'];
        }

        if ( $autocomplete !== '' ) {
            $attrs['autocomplete'] = $autocomplete;
        }

        if ( isset( $descriptor['defaults'] ) && is_array( $descriptor['defaults'] ) ) {
            foreach ( $descriptor['defaults'] as $key => $value ) {
                if ( ! array_key_exists( $key, $attrs ) ) {
                    $attrs[ $key ] = $value;
                }
            }
        }

        if ( is_array( $field ) ) {
            if ( isset( $field['placeholder'] ) && is_string( $field['placeholder'] ) ) {
                $attrs['placeholder'] = $field['placeholder'];
            }

            if ( isset( $field['size'] ) ) {
                $attrs['size'] = (int) $field['size'];
            }

            if ( isset( $field['max_length'] ) ) {
                $attrs['maxlength'] = (int) $field['max_length'];
            }

            if ( isset( $field['required'] ) && $field['required'] === true ) {
                $attrs['required'] = 'required';
            }
        }

        if ( is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
            $attrs['name'] = $field['key'];

            $prefix = '';
            if ( is_array( $context ) && isset( $context['id_prefix'] ) && is_string( $context['id_prefix'] ) ) {
                $prefix = $context['id_prefix'];
            }

            $attrs['id'] = $prefix !== '' ? $prefix . '-' . $field['key'] : $field['key'];
        }

        return EformsMarkup::apply_control_context( $attrs, $context );
    }

    private static function render_input( $attrs ) {
        return '<input ' . EformsMarkup::attributes( $attrs ) . ' />';
    }
}
