<?php
// includes/template-tags.php

if ( ! function_exists( 'eform_field' ) ) {
    /**
     * Render a form field and register it for processing.
     *
     * @param string $field Field key (name, email, phone, zip, message).
     * @param array  $args  Optional arguments.
     *                     - required (bool)  Whether the field is required.
     *                     - placeholder (string) Placeholder text.
     *                     - rows (int)  Rows for textarea fields.
     *                     - cols (int)  Cols for textarea fields.
     */
    function eform_field( string $field, array $args = [] ) {
        global $eform_registry, $eform_current_template, $eform_form;

        if ( empty( $eform_registry ) || empty( $eform_current_template ) ) {
            return;
        }

        $defaults = [
            'required'    => false,
            'placeholder' => '',
            'rows'        => 5,
            'cols'        => 21,
        ];
        $args = array_merge( $defaults, $args );

        $required_attr = $args['required'] ? ' required aria-required="true"' : '';

        // Record field presence with registry.
        $eform_registry->register_field( $eform_current_template, $field, [ 'required' => $args['required'] ] );

        $value      = $eform_form->form_data[ $field ] ?? '';
        $error      = $eform_form->field_errors[ $field ] ?? '';
        $class      = 'form_field' . ( $error ? ' has-error' : '' );
        $error_html = $error ? '<span class="field-error">' . esc_html( $error ) . '</span>' : '';

        switch ( $field ) {
            case 'name':
                $placeholder = $args['placeholder'] ?: 'Your Name';
                echo '<input class="' . esc_attr( $class ) . '" type="text" name="name_input" autocomplete="name"' .
                    $required_attr . ' aria-label="Your Name" placeholder="' . esc_attr( $placeholder ) .
                    '" value="' . esc_attr( $value ) . '">' . $error_html;
                break;

            case 'email':
                $placeholder = $args['placeholder'] ?: 'Your eMail';
                echo '<input class="' . esc_attr( $class ) . '" type="email" name="email_input" autocomplete="email"' .
                    $required_attr . ' aria-label="Your Email" placeholder="' . esc_attr( $placeholder ) .
                    '" value="' . esc_attr( $value ) . '">' . $error_html;
                break;

            case 'phone':
                $placeholder = $args['placeholder'] ?: 'Phone';
                $formatted   = $eform_form->format_phone( $value );
                echo '<input class="' . esc_attr( $class ) . '" type="tel" name="tel_input" autocomplete="tel"' .
                    $required_attr . ' aria-label="Phone" placeholder="' . esc_attr( $placeholder ) .
                    '" value="' . esc_attr( $formatted ) . '">' . $error_html;
                break;

            case 'zip':
                $placeholder = $args['placeholder'] ?: 'Project Zip Code';
                echo '<input class="' . esc_attr( $class ) . '" type="text" name="zip_input" autocomplete="postal-code"' .
                    $required_attr . ' aria-label="Project Zip Code" placeholder="' . esc_attr( $placeholder ) .
                    '" value="' . esc_attr( $value ) . '">' . $error_html;
                break;

            case 'message':
                $placeholder = $args['placeholder'] ?: 'Please describe your project and let us know if there is any urgency';
                echo '<textarea class="' . esc_attr( $class ) . '" name="message_input" cols="' . intval( $args['cols'] ) . '" rows="' . intval( $args['rows'] ) . '"'
                    . $required_attr . ' aria-label="Message" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $value ) . '</textarea>' . $error_html;
                break;
        }
    }
}
