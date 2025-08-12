<?php
// includes/render.php

/**
 * Render a form from configuration when no PHP template is available.
 *
 * @param Enhanced_Internal_Contact_Form $form     Form object containing data and helpers.
 * @param string                         $template Template slug for naming fields.
 * @param array                          $config   Template configuration containing field definitions.
 */
function eform_render_form( $form, string $template, array $config ) {

    echo '<div id="contact_form" class="contact_form">';
    echo '<form class="main_contact_form" id="main_contact_form" aria-label="Contact Form" method="post" action="">';
    Enhanced_Internal_Contact_Form::render_hidden_fields( $template );

    foreach ( $config['fields'] ?? [] as $post_key => $field ) {
        $field_key = FieldRegistry::field_key_from_post( $post_key );
        $value     = $form->form_data[ $field_key ] ?? '';
        if ( ( $field['type'] ?? '' ) === 'tel' ) {
            $value = $form->format_phone( $value );
        }

        $required   = isset( $field['required'] ) ? ' required aria-required="true"' : '';
        $attr_str   = '';
        foreach ( $field as $attr => $val ) {
            if ( in_array( $attr, [ 'type', 'required', 'style', 'id' ], true ) ) {
                continue;
            }
            $attr_str .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $val ) );
        }

        $field_id    = $field_key;
        $has_error   = ! empty( $form->field_errors[ $field_key ] ?? '' );
        $error_attrs = $has_error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $field_id . '-error' ) . '"' : '';

        echo '<div class="inputwrap" style="' . esc_attr( $field['style'] ?? '' ) . '">';
        echo '<label for="' . esc_attr( $field_id ) . '">';
        if ( ( $field['type'] ?? '' ) === 'textarea' ) {
            echo '<textarea id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $post_key ) . '"' . $required . $attr_str . $error_attrs . '>' . esc_textarea( $value ) . '</textarea>';
        } else {
            $type = $field['type'] ?? 'text';
            echo '<input id="' . esc_attr( $field_id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $post_key ) . '" value="' . esc_attr( $value ) . '"' . $required . $attr_str . $error_attrs . '>';
        }
        echo '</label>';
        eform_field_error( $form, $field_key );
        echo '</div>';
    }

    echo '<input type="hidden" name="submitted" value="1" aria-hidden="true">';
    echo '<button type="submit" name="enhanced_form_submit_' . esc_attr( $template ) . '" aria-label="Send Request" value="Send Request">Send Request</button>';
    echo '</form></div>';
}
