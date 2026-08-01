<?php
/**
 * Text-like field type descriptors and helpers.
 *
 * Educational note: descriptors are pure data; renderers/validators interpret them.
 *
 * Contract: Field types
 * Contract: display_format_tel tokens
 */

require_once __DIR__ . '/../../FormProtocol.php';

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
                'enterkeyhint' => true,
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
            $descriptor['render']['protocol_attrs'] = array(
                FormProtocol::DATA_URL_NORMALIZE => '1',
            );
            return $descriptor;
        }

        if ( $type === 'tel' || $type === 'tel_us' ) {
            $descriptor['html']['type'] = 'tel';
            $descriptor['html']['inputmode'] = 'tel';
            if ( $type === 'tel_us' ) {
                $descriptor['render']['protocol_attrs'] = array(
                    FormProtocol::DATA_PHONE_FORMAT => 'tel_us',
                );
            }
            return $descriptor;
        }

        if ( $type === 'zip_us' ) {
            $descriptor['html']['inputmode'] = 'numeric';
            $descriptor['html']['pattern'] = '\\d{5}';
            $descriptor['html']['attrs_mirror'] = array( 'maxlength', 'size' );
            $descriptor['defaults']['maxlength'] = 5;
            $descriptor['handlers']['validator_id'] = 'zip_us';
            $descriptor['handlers']['normalizer_id'] = 'zip_us';
            $descriptor['handlers']['renderer_id'] = 'zip_us';
            $descriptor['render']['protocol_attrs'] = array(
                FormProtocol::DATA_ZIP_FORMAT => 'zip_us',
            );
            return $descriptor;
        }

        if ( $type === 'zip' ) {
            $descriptor['handlers']['validator_id'] = 'zip';
            $descriptor['handlers']['normalizer_id'] = 'zip';
            $descriptor['handlers']['renderer_id'] = 'zip';
            return $descriptor;
        }

        if ( $type === 'number' ) {
            $descriptor['html']['type'] = 'text';
            $descriptor['html']['inputmode'] = 'decimal';
            $descriptor['html']['attrs_mirror'] = array( 'min', 'max', 'step' );
            $descriptor['handlers']['validator_id'] = $type;
            $descriptor['handlers']['normalizer_id'] = $type;
            $descriptor['handlers']['renderer_id'] = $type;
            $descriptor['render']['integer_format_when'] = 'non_negative_step_one';
            $descriptor['render']['unit_attr'] = true;
            return $descriptor;
        }

        if ( $type === 'range' ) {
            $descriptor['html']['type'] = 'range';
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

    public static function render_protocol_attributes( $descriptor, $field ) {
        if ( ! is_array( $descriptor ) ) {
            return array();
        }

        $attrs = isset( $descriptor['render']['protocol_attrs'] ) && is_array( $descriptor['render']['protocol_attrs'] )
            ? $descriptor['render']['protocol_attrs']
            : array();

        if ( isset( $descriptor['render']['integer_format_when'] )
            && $descriptor['render']['integer_format_when'] === 'non_negative_step_one'
            && is_array( $field )
            && isset( $field['step'], $field['min'] )
            && (string) $field['step'] === '1'
            && is_numeric( $field['min'] )
            && (float) $field['min'] >= 0
            && floor( (float) $field['min'] ) === (float) $field['min']
        ) {
            $attrs[ FormProtocol::DATA_INTEGER_FORMAT ] = '1';
        }

        if ( ! empty( $descriptor['render']['unit_attr'] ) && is_array( $field ) && isset( $field['unit'] ) && is_string( $field['unit'] ) && $field['unit'] !== '' ) {
            $attrs[ FormProtocol::DATA_INPUT_UNIT ] = $field['unit'];
        }

        return $attrs;
    }

    public static function normalize_zip_us( $value ) {
        if ( ! is_string( $value ) ) {
            return null;
        }

        $value = trim( $value );
        if ( $value === '' ) {
            return null;
        }

        if ( preg_match( '/^\d{5}$/', $value ) ) {
            return $value;
        }

        if ( preg_match( '/^(\d{5})-\d{4}$/', $value, $matches ) ) {
            return $matches[1];
        }

        return null;
    }

    public static function normalize_number( $value ) {
        if ( ! is_string( $value ) ) {
            return null;
        }

        $value = trim( $value );
        if ( $value === '' ) {
            return null;
        }

        if ( strpos( $value, ',' ) !== false ) {
            $value = str_replace( ',', '', $value );
        }

        return is_numeric( $value ) ? $value : null;
    }

    public static function normalize_url( $value ) {
        if ( ! is_string( $value ) ) {
            return null;
        }

        $value = trim( $value );
        if ( $value === '' || preg_match( '/\s/', $value ) ) {
            return null;
        }

        $candidate = $value;
        if ( strpos( $candidate, '://' ) === false && preg_match( '/^[^@\/]+\.[^@\/]+(?:\/.*)?$/', $candidate ) ) {
            $candidate = 'https://' . $candidate;
        }

        $url = filter_var( $candidate, FILTER_VALIDATE_URL );
        if ( $url === false ) {
            return null;
        }

        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
            return null;
        }

        $scheme = strtolower( $parts['scheme'] );
        return $scheme === 'http' || $scheme === 'https' ? $url : null;
    }

    public static function normalize_tel_us( $value ) {
        if ( ! is_string( $value ) ) {
            return null;
        }

        $value = trim( $value );
        if ( $value === '' ) {
            return null;
        }

        if ( preg_match( '/[^0-9\s().+-]/', $value ) ) {
            return null;
        }

        $plus_pos = strpos( $value, '+' );
        if ( $plus_pos !== false && ( $plus_pos !== 0 || substr_count( $value, '+' ) > 1 ) ) {
            return null;
        }

        $digits = preg_replace( '/\D+/', '', $value );
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
