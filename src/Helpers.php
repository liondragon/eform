<?php
// src/Helpers.php

if ( ! class_exists( 'Helpers' ) ) {
    class Helpers {
        public static function get_first_value( $value ) {
            if ( is_array( $value ) ) {
                return null;
            }
            return $value;
        }
    }
}

if ( ! function_exists( 'eform_get_safe_fields' ) ) {
    /**
     * Retrieve fields considered safe for logging.
     *
     * Allows overriding via the `eform_log_safe_fields` option or filter.
     *
     * @param array|null $form_data Optional form data for filter context.
     * @return array List of safe field keys.
     */
    function eform_get_safe_fields( $form_data = null ) {
        $safe_fields = [ 'name', 'zip' ];
        if ( function_exists( 'get_option' ) ) {
            $option_fields = get_option( 'eform_log_safe_fields', [] );
            if ( ! empty( $option_fields ) && is_array( $option_fields ) ) {
                $safe_fields = $option_fields;
            }
        }
        if ( function_exists( 'apply_filters' ) ) {
            $safe_fields = apply_filters( 'eform_log_safe_fields', $safe_fields, $form_data );
        }
        return $safe_fields;
    }
}

if ( ! function_exists( 'eform_field_error' ) ) {
    /**
     * Output a field-specific error message when available.
     *
     * @param object $form  Form instance containing validation errors.
     * @param string $field Field key.
     */
    function eform_field_error( $form, string $field ) {
        if ( empty( $form ) ) {
            return;
        }

        $error = $form->field_errors[ $field ] ?? '';

        if ( $error ) {
            echo '<div class="field-error">' . esc_html( $error ) . '</div>';
        }
    }
}

