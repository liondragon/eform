<?php
// includes/template-tags.php

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
            echo '<div id="' . esc_attr( $field ) . '-error" class="field-error">' . esc_html( $error ) . '</div>';
        }
    }
}

