<?php
/**
 * Renderer helper for choice fields (select/radio/checkbox).
 *
 * Educational note: this helper builds deterministic attributes only. Higher
 * level layout (fieldset/legend) is handled by FormRenderer.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 * Spec: Template options (docs/Canonical_Spec.md#sec-template-options)
 */

class FieldRenderers_Choice {
    public static function render( $descriptor, $field, $value = null, $context = array() ) {
        $type = isset( $descriptor['type'] ) ? $descriptor['type'] : '';

        if ( $type === 'select' ) {
            $attrs = self::build_select_attributes( $descriptor, $field, $context );
            $options = self::options_for_select( $field, $value, $descriptor );
            return self::render_select( $attrs, $options );
        }

        $attrs = self::build_choice_input_attributes( $descriptor, $field, null, $context, $value );
        return self::render_input( $attrs );
    }

    public static function build_select_attributes( $descriptor, $field, $context = array() ) {
        $attrs = array();

        if ( is_array( $field ) && isset( $field['required'] ) && $field['required'] === true ) {
            $attrs['required'] = 'required';
        }

        if ( isset( $descriptor['is_multivalue'] ) && $descriptor['is_multivalue'] === true ) {
            $attrs['multiple'] = 'multiple';
        }

        if ( is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
            $attrs['name'] = $field['key'];

            $prefix = '';
            if ( is_array( $context ) && isset( $context['id_prefix'] ) && is_string( $context['id_prefix'] ) ) {
                $prefix = $context['id_prefix'];
            }

            $attrs['id'] = $prefix !== '' ? $prefix . '-' . $field['key'] : $field['key'];
        }

        return $attrs;
    }

    public static function build_choice_input_attributes( $descriptor, $field, $option, $context = array(), $value = null ) {
        $attrs = array();

        if ( isset( $descriptor['html']['type'] ) ) {
            $attrs['type'] = $descriptor['html']['type'];
        }

        if ( is_array( $field ) && isset( $field['required'] ) && $field['required'] === true ) {
            $attrs['required'] = 'required';
        }

        if ( is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
            $attrs['name'] = $field['key'];

            $prefix = '';
            if ( is_array( $context ) && isset( $context['id_prefix'] ) && is_string( $context['id_prefix'] ) ) {
                $prefix = $context['id_prefix'];
            }

            $suffix = '';
            if ( is_array( $option ) && isset( $option['key'] ) && is_string( $option['key'] ) ) {
                $suffix = '-' . $option['key'];
                $attrs['value'] = $option['key'];
            }

            $attrs['id'] = $prefix !== '' ? $prefix . '-' . $field['key'] . $suffix : $field['key'] . $suffix;
        }

        if ( is_array( $option ) && isset( $option['disabled'] ) && $option['disabled'] === true ) {
            $attrs['disabled'] = 'disabled';
        }

        if ( self::option_selected( $option, $value, $descriptor ) ) {
            $attrs['checked'] = 'checked';
        }

        return $attrs;
    }

    public static function build_option_attributes( $option, $selected = false ) {
        $attrs = array();

        if ( is_array( $option ) && isset( $option['key'] ) && is_string( $option['key'] ) ) {
            $attrs['value'] = $option['key'];
        }

        if ( is_array( $option ) && isset( $option['disabled'] ) && $option['disabled'] === true ) {
            $attrs['disabled'] = 'disabled';
        }

        if ( $selected ) {
            $attrs['selected'] = 'selected';
        }

        return $attrs;
    }

    private static function options_for_select( $field, $value, $descriptor ) {
        if ( ! is_array( $field ) || ! isset( $field['options'] ) || ! is_array( $field['options'] ) ) {
            return array();
        }

        $values = self::normalize_value_list( $value );
        $is_multi = is_array( $descriptor ) && ! empty( $descriptor['is_multivalue'] );

        $out = array();
        foreach ( $field['options'] as $option ) {
            $selected = false;
            if ( is_array( $option ) && isset( $option['key'] ) ) {
                $option_key = (string) $option['key'];
                if ( $is_multi ) {
                    $selected = in_array( $option_key, $values, true );
                } elseif ( ! empty( $values ) ) {
                    $selected = $values[0] === $option_key;
                }
            }

            $out[] = array(
                'attrs' => self::build_option_attributes( $option, $selected ),
                'label' => is_array( $option ) && isset( $option['label'] ) ? $option['label'] : '',
            );
        }

        return $out;
    }

    private static function option_selected( $option, $value, $descriptor ) {
        if ( ! is_array( $option ) || ! isset( $option['key'] ) ) {
            return false;
        }

        $option_key = (string) $option['key'];
        $values = self::normalize_value_list( $value );
        $is_multi = is_array( $descriptor ) && ! empty( $descriptor['is_multivalue'] );

        if ( $is_multi ) {
            return in_array( $option_key, $values, true );
        }

        return ! empty( $values ) && $values[0] === $option_key;
    }

    private static function normalize_value_list( $value ) {
        if ( $value === null ) {
            return array();
        }

        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $entry ) {
                if ( is_scalar( $entry ) ) {
                    $out[] = (string) $entry;
                }
            }
            return $out;
        }

        if ( is_scalar( $value ) ) {
            return array( (string) $value );
        }

        return array();
    }

    private static function render_select( $attrs, $options ) {
        $parts = array();
        foreach ( $attrs as $key => $value ) {
            $parts[] = $key . '="' . self::escape_attr( $value ) . '"';
        }

        $html = '<select ' . implode( ' ', $parts ) . '>';

        foreach ( $options as $option ) {
            $opt_attrs = array();
            foreach ( $option['attrs'] as $key => $value ) {
                $opt_attrs[] = $key . '="' . self::escape_attr( $value ) . '"';
            }
            $label = self::escape_text( $option['label'] );
            $html .= '<option ' . implode( ' ', $opt_attrs ) . '>' . $label . '</option>';
        }

        return $html . '</select>';
    }

    private static function render_input( $attrs ) {
        $parts = array();
        foreach ( $attrs as $key => $value ) {
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

    private static function escape_text( $value ) {
        if ( function_exists( 'esc_html' ) ) {
            return esc_html( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}
