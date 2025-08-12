<?php
// includes/Renderer.php

/**
 * Default renderer for form templates using configuration arrays.
 */
class Renderer {
    /**
     * Render a form from configuration when no PHP template is available.
     *
     * @param FormData $form     Form object containing data and helpers.
     * @param string   $template Template slug for naming fields.
     * @param array    $config   Template configuration containing field definitions.
     */
    public function render( FormData $form, string $template, array $config ) {
        echo '<div id="contact_form" class="contact_form">';
        echo '<form class="main_contact_form" id="main_contact_form" aria-label="Contact Form" method="post" action="">';
        $form_id = Enhanced_Internal_Contact_Form::render_hidden_fields( $template );

        foreach ( $config['fields'] ?? [] as $post_key => $field ) {
            $field_key = isset( $field['key'] ) ? sanitize_key( $field['key'] ) : sanitize_key( preg_replace( '/_input$/', '', $post_key ) );
            if ( 'tel' === $field_key ) {
                $field_key = 'phone';
            }
            $value     = $form->form_data[ $field_key ] ?? '';
            if ( ( $field['type'] ?? '' ) === 'tel' ) {
                $value = $form->format_phone( $value );
            }
            $required = isset( $field['required'] ) ? ' required aria-required="true"' : '';
            $attr_str = '';
            foreach ( $field as $attr => $val ) {
                if ( in_array( $attr, [ 'type', 'required', 'style', 'key', 'sanitize', 'validate' ], true ) ) {
                    continue;
                }
                $attr_str .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $val ) );
            }
            echo '<div class="inputwrap" style="' . esc_attr( $field['style'] ?? '' ) . '">';
            $name = $form_id . '[' . $field_key . ']';
            if ( ( $field['type'] ?? '' ) === 'textarea' ) {
                echo '<textarea name="' . esc_attr( $name ) . '"' . $required . $attr_str . '>' . esc_textarea( $value ) . '</textarea>';
            } else {
                $type = $field['type'] ?? 'text';
                echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $required . $attr_str . '>';
            }
            eform_field_error( $form, $field_key );
            echo '</div>';
        }

        echo '<input type="hidden" name="submitted" value="1" aria-hidden="true">';
        echo '<button type="submit" name="enhanced_form_submit_' . esc_attr( $template ) . '" aria-label="Send Request" value="Send Request">Send Request</button>';
        echo '</form></div>';
    }
}
