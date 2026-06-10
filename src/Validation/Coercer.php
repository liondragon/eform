<?php
/**
 * Coerce stage (post-validate canonicalization).
 *
 * Educational note: Coerce is pure and deterministic; it only canonicalizes
 * validated values without rejecting or performing side effects.
 *
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 */

require_once __DIR__ . '/FieldTypes/TextLike.php';

class Coercer {
    /**
     * Canonicalize validated values for downstream side effects.
     *
     * @param array $context TemplateContext array.
     * @param array $validated Result from Validator::validate() (or the values map).
     * @return array{values: array}
     */
    public static function coerce( $context, $validated ) {
        $values = self::extract_values( $validated );

        $descriptors = array();
        if ( is_array( $context ) && isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ) {
            $descriptors = $context['descriptors'];
        }

        $out = array();

        foreach ( $descriptors as $descriptor ) {
            if ( ! is_array( $descriptor ) ) {
                continue;
            }

            $key = isset( $descriptor['key'] ) && is_string( $descriptor['key'] ) ? $descriptor['key'] : '';
            if ( $key === '' ) {
                continue;
            }

            $value = array_key_exists( $key, $values ) ? $values[ $key ] : null;
            $out[ $key ] = self::coerce_value( $value, $descriptor );
        }

        return array(
            'values' => $out,
        );
    }

    private static function extract_values( $validated ) {
        if ( is_array( $validated ) && isset( $validated['values'] ) && is_array( $validated['values'] ) ) {
            return $validated['values'];
        }

        return is_array( $validated ) ? $validated : array();
    }

    private static function coerce_value( $value, $descriptor ) {
        if ( $value === null ) {
            return null;
        }

        $type = isset( $descriptor['type'] ) ? $descriptor['type'] : '';
        $is_multivalue = ! empty( $descriptor['is_multivalue'] );

        if ( $is_multivalue ) {
            if ( ! is_array( $value ) ) {
                return $value;
            }

            $out = array();
            foreach ( $value as $entry ) {
                $out[] = self::coerce_scalar( $entry, $type, $descriptor );
            }
            return $out;
        }

        return self::coerce_scalar( $value, $type, $descriptor );
    }

    private static function coerce_scalar( $value, $type, $descriptor ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        if ( $type === 'email' ) {
            return self::lowercase_email_domain( $value );
        }

        if ( $type === 'tel_us' ) {
            $digits = FieldTypes_TextLike::normalize_tel_us( $value );
            return $digits !== null ? $digits : $value;
        }

        if ( self::should_collapse_whitespace( $type, $descriptor ) ) {
            return self::collapse_whitespace( $value );
        }

        return $value;
    }

    private static function should_collapse_whitespace( $type, $descriptor ) {
        if ( is_array( $descriptor )
            && isset( $descriptor['validate'] )
            && is_array( $descriptor['validate'] )
            && ! empty( $descriptor['validate']['canonicalize'] )
        ) {
            return true;
        }

        return $type === 'name' || $type === 'first_name' || $type === 'last_name';
    }

    private static function collapse_whitespace( $value ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        $value = trim( $value );
        $value = preg_replace( '/\\s+/u', ' ', $value );

        return $value;
    }

    private static function lowercase_email_domain( $value ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        $at = strrpos( $value, '@' );
        if ( $at === false ) {
            return $value;
        }

        $local = substr( $value, 0, $at );
        $domain = substr( $value, $at + 1 );

        if ( $domain === '' ) {
            return $value;
        }

        return $local . '@' . strtolower( $domain );
    }
}
