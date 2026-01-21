<?php
/**
 * Text-like field type descriptors and helpers.
 *
 * Educational note: descriptors are pure data; renderers/validators interpret them.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 * Spec: display_format_tel tokens (docs/Canonical_Spec.md#sec-display-format-tel)
 */

class FieldTypes_TextLike {
    const SUPPORTED = array(
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

    const DISPLAY_FORMATS = array(
        'xxx-xxx-xxxx',
        '(xxx) xxx-xxxx',
        'xxx.xxx.xxxx',
    );

    public static function supports( $type ) {
        return is_string( $type ) && in_array( $type, self::SUPPORTED, true );
    }

    public static function descriptor( $type ) {
        $descriptor = array(
            'type' => $type,
            'alias_of' => null,
            'is_multivalue' => false,
            'html' => array(
                'tag' => 'input',
                'type' => 'text',
                'attrs_mirror' => array( 'maxlength', 'size' ),
            ),
            'validate' => array(),
            'handlers' => array(
                'validator_id' => 'text',
                'normalizer_id' => 'text',
                'renderer_id' => 'text',
            ),
            'constants' => array(),
            'defaults' => array(),
        );

        if ( $type === 'email' ) {
            $descriptor['html']['type'] = 'email';
            $descriptor['html']['inputmode'] = 'email';
            $descriptor['constants']['spellcheck'] = 'false';
            $descriptor['constants']['autocapitalize'] = 'off';
            return $descriptor;
        }

        if ( $type === 'url' ) {
            $descriptor['html']['type'] = 'url';
            $descriptor['constants']['spellcheck'] = 'false';
            $descriptor['constants']['autocapitalize'] = 'off';
            return $descriptor;
        }

        if ( $type === 'tel' || $type === 'tel_us' ) {
            $descriptor['html']['type'] = 'tel';
            $descriptor['html']['inputmode'] = 'tel';
            return $descriptor;
        }

        if ( $type === 'zip_us' ) {
            $descriptor['html']['inputmode'] = 'numeric';
            $descriptor['html']['pattern'] = '\\d{5}';
            $descriptor['handlers']['validator_id'] = 'zip_us';
            $descriptor['handlers']['normalizer_id'] = 'zip_us';
            $descriptor['handlers']['renderer_id'] = 'zip_us';
            return $descriptor;
        }

        if ( $type === 'zip' ) {
            $descriptor['handlers']['validator_id'] = 'zip';
            $descriptor['handlers']['normalizer_id'] = 'zip';
            $descriptor['handlers']['renderer_id'] = 'zip';
            return $descriptor;
        }

        if ( $type === 'number' || $type === 'range' ) {
            $descriptor['html']['type'] = $type;
            $descriptor['html']['inputmode'] = 'decimal';
            $descriptor['html']['attrs_mirror'] = array( 'min', 'max', 'step' );
            $descriptor['handlers']['validator_id'] = $type;
            $descriptor['handlers']['normalizer_id'] = $type;
            $descriptor['handlers']['renderer_id'] = $type;
            return $descriptor;
        }

        if ( $type === 'date' ) {
            $descriptor['html']['type'] = 'date';
            $descriptor['html']['attrs_mirror'] = array( 'min', 'max', 'step' );
            $descriptor['handlers']['validator_id'] = 'date';
            $descriptor['handlers']['normalizer_id'] = 'date';
            $descriptor['handlers']['renderer_id'] = 'date';
            return $descriptor;
        }

        if ( $type === 'name' ) {
            $descriptor['alias_of'] = 'text';
            $descriptor['defaults']['autocomplete'] = 'name';
            return $descriptor;
        }

        if ( $type === 'first_name' ) {
            $descriptor['alias_of'] = 'text';
            $descriptor['defaults']['autocomplete'] = 'given-name';
            return $descriptor;
        }

        if ( $type === 'last_name' ) {
            $descriptor['alias_of'] = 'text';
            $descriptor['defaults']['autocomplete'] = 'family-name';
            return $descriptor;
        }

        return $descriptor;
    }

    public static function normalize_tel_us( $value ) {
        if ( ! is_string( $value ) ) {
            return null;
        }

        $digits = preg_replace( '/\\D+/', '', $value );
        if ( $digits === '' ) {
            return null;
        }

        if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
            $digits = substr( $digits, 1 );
        }

        if ( strlen( $digits ) !== 10 ) {
            return null;
        }

        return $digits;
    }

    public static function format_tel_us( $value, $format ) {
        $digits = self::normalize_tel_us( (string) $value );
        if ( $digits === null ) {
            return trim( (string) $value );
        }

        $format = self::normalize_format( $format );

        if ( $format === '(xxx) xxx-xxxx' ) {
            return '(' . substr( $digits, 0, 3 ) . ') ' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
        }

        if ( $format === 'xxx.xxx.xxxx' ) {
            return substr( $digits, 0, 3 ) . '.' . substr( $digits, 3, 3 ) . '.' . substr( $digits, 6 );
        }

        return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
    }

    private static function normalize_format( $format ) {
        if ( ! is_string( $format ) || $format === '' ) {
            return 'xxx-xxx-xxxx';
        }

        if ( in_array( $format, self::DISPLAY_FORMATS, true ) ) {
            return $format;
        }

        return 'xxx-xxx-xxxx';
    }
}
