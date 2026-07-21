<?php
/**
 * Renderer helper for upload fields.
 *
 * Educational note: upload rendering only emits browser hints; server-side
 * upload validation and storage remain authoritative.
 *
 * Contract: Field types
 */

require_once __DIR__ . '/../../Uploads/UploadPolicy.php';
require_once __DIR__ . '/../../FormProtocol.php';
require_once __DIR__ . '/../../Helpers.php';

class FieldRenderers_Upload {
    public static function render( $descriptor, $field, $value = null, $context = array() ) {
        $attrs = self::build_attributes( $descriptor, $field, $context );
        if ( self::is_staged( $field ) && isset( $attrs['id'] ) && is_string( $attrs['id'] ) ) {
            $attrs['id'] = Helpers::cap_id( $attrs['id'] );
        }
        $input = self::render_input( $attrs );
        if ( ! self::is_staged( $field ) ) {
            return $input;
        }

        return $input . self::render_staged_mount( $field, $attrs )
            . '<noscript><p>Photo upload requires JavaScript. Reload this form in a browser with JavaScript enabled.</p></noscript>';
    }

    public static function build_attributes( $descriptor, $field, $context = array() ) {
        $attrs = array(
            'type' => 'file',
        );

        if ( is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
            if ( ! self::is_staged( $field ) ) {
                $attrs['name'] = $field['key'];
            }

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

        if ( self::is_staged( $field ) ) {
            $attrs['disabled'] = 'disabled';
            $attrs[ FormProtocol::DATA_UPLOAD_PICKER ] = '1';
        }

        return $attrs;
    }

    private static function accept_attribute( $field ) {
        $accept_defined = is_array( $field ) && array_key_exists( 'accept', $field );
        $accept_value = $accept_defined ? $field['accept'] : array();
        $mode = self::is_staged( $field ) ? 'staged' : 'synchronous';
        $tokens = UploadPolicy::resolve_tokens( $accept_value, ! $accept_defined, $mode );
        if ( empty( $tokens ) ) {
            return '';
        }

        $policy = UploadPolicy::policy_for_tokens( $tokens, $mode );
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

    private static function is_staged( $field ) {
        return is_array( $field ) && isset( $field['upload_mode'] ) && $field['upload_mode'] === 'staged';
    }

    private static function render_staged_mount( $field, $input_attrs ) {
        $attrs = array(
            'class' => 'eforms-upload',
            FormProtocol::DATA_UPLOAD_MOUNT => '1',
            FormProtocol::DATA_UPLOAD_PICKER_ID => isset( $input_attrs['id'] ) ? $input_attrs['id'] : '',
            FormProtocol::DATA_UPLOAD_FIELD => isset( $field['key'] ) ? $field['key'] : '',
            FormProtocol::DATA_UPLOAD_ACCEPT => isset( $input_attrs['accept'] ) ? $input_attrs['accept'] : '',
            FormProtocol::DATA_UPLOAD_MAX_FILES => isset( $field['max_files'] ) ? (string) $field['max_files'] : '',
            FormProtocol::DATA_UPLOAD_MAX_FILE_BYTES => isset( $field['max_file_bytes'] ) ? (string) $field['max_file_bytes'] : '',
            FormProtocol::DATA_UPLOAD_MAX_TOTAL_BYTES => isset( $field['max_total_bytes'] ) ? (string) $field['max_total_bytes'] : '',
        );

        return '<div ' . self::attrs_to_string( $attrs ) . '></div>';
    }

    private static function attrs_to_string( $attrs ) {
        $parts = array();
        foreach ( $attrs as $key => $value ) {
            if ( $value === null || $value === '' ) {
                continue;
            }
            $parts[] = $key . '="' . self::escape_attr( $value ) . '"';
        }
        return implode( ' ', $parts );
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
