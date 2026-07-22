<?php
/**
 * Shared escaping and attribute serialization for plugin-owned HTML.
 */

class EformsMarkup {
    public static function attributes( $attributes ) {
        if ( ! is_array( $attributes ) ) {
            return '';
        }

        $parts = array();
        foreach ( $attributes as $name => $value ) {
            if ( ! is_string( $name ) || $name === '' || $value === null || $value === false ) {
                continue;
            }
            if ( $value === '' || $value === true ) {
                $parts[] = $name;
                continue;
            }
            $parts[] = $name . '="' . self::escape_attr( $value ) . '"';
        }

        return implode( ' ', $parts );
    }

    public static function apply_control_context( $attributes, $context ) {
        $attributes = is_array( $attributes ) ? $attributes : array();
        $context = is_array( $context ) ? $context : array();

        if ( isset( $context['name'] ) && is_string( $context['name'] ) && $context['name'] !== '' ) {
            $attributes['name'] = $context['name'];
        }
        if ( isset( $context['id'] ) && is_string( $context['id'] ) && $context['id'] !== '' ) {
            $attributes['id'] = $context['id'];
        }
        if ( ! empty( $context['enterkeyhint'] ) ) {
            $attributes['enterkeyhint'] = 'send';
        }
        if ( isset( $context['attributes'] ) && is_array( $context['attributes'] ) ) {
            $attributes = array_merge( $attributes, $context['attributes'] );
        }

        return $attributes;
    }

    public static function escape_attr( $value ) {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }

    public static function escape_html( $value ) {
        if ( function_exists( 'esc_html' ) ) {
            return esc_html( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }

    public static function escape_textarea( $value ) {
        if ( function_exists( 'esc_textarea' ) ) {
            return esc_textarea( $value );
        }

        return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
    }
}
