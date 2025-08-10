<?php
// includes/template-tags.php

if ( ! function_exists( 'eform_field' ) ) {
    /**
     * Render a form field and register it for processing.
     *
     * @param FieldRegistry $registry Field registry instance.
     * @param object        $form     Form instance providing data and helpers.
     * @param string        $template Template slug.
     * @param string        $field    Field key (name, email, phone, zip, message).
     * @param array         $args     Optional arguments.
     *                               - required (bool)  Whether the field is required.
     *                               - placeholder (string) Placeholder text.
     *                               - rows (int)  Rows for textarea fields.
     *                               - cols (int)  Cols for textarea fields.
     *                               - pattern (string) Regex pattern for input validation.
     *                               - maxlength (int) Maximum allowed length.
     *                               - minlength (int) Minimum required length.
     *                               - title (string)   Accessible description.
     */
    function eform_field( FieldRegistry $registry, $form, string $template, string $field, array $args = [] ) {
        if ( empty( $template ) ) {
            return;
        }

        $defaults = [
            'required'    => false,
            'placeholder' => '',
            'rows'        => 5,
            'cols'        => 21,
            'pattern'     => '',
            'maxlength'   => '',
            'minlength'   => '',
            'title'       => '',
        ];
        $args = array_merge( $defaults, $args );

        $required_attr = $args['required'] ? ' required aria-required="true"' : '';

        $extra_attrs = '';
        foreach ( [ 'pattern', 'maxlength', 'minlength', 'title' ] as $attr ) {
            if ( ! empty( $args[ $attr ] ) ) {
                $extra_attrs .= ' ' . $attr . '="' . esc_attr( $args[ $attr ] ) . '"';
            }
        }

        $attrs = $required_attr . $extra_attrs;

        // Record field presence with registry.
        $registry->register_field( $template, $field, [ 'required' => $args['required'] ] );

        $field_map = [
            'name'    => [
                'template'    => '<input class="form_field" type="text" name="name_input" autocomplete="name"%1$s aria-label="Your Name" placeholder="%2$s" value="%3$s">',
                'placeholder' => 'Your Name',
            ],
            'email'   => [
                'template'    => '<input class="form_field" type="email" name="email_input" autocomplete="email"%1$s aria-label="Your Email" placeholder="%2$s" value="%3$s">',
                'placeholder' => 'Your eMail',
            ],
            'phone'   => [
                'template'      => '<input class="form_field" type="tel" name="tel_input" autocomplete="tel"%1$s aria-label="Phone" placeholder="%2$s" value="%3$s">',
                'placeholder'   => 'Phone',
                'value_callback'=> 'format_phone',
            ],
            'zip'     => [
                'template'    => '<input class="form_field" type="text" name="zip_input" autocomplete="postal-code"%1$s aria-label="Project Zip Code" placeholder="%2$s" value="%3$s">',
                'placeholder' => 'Project Zip Code',
            ],
            'message' => [
                'template'    => '<textarea name="message_input" cols="%4$d" rows="%5$d"%1$s aria-label="Message" placeholder="%2$s">%3$s</textarea>',
                'placeholder' => 'Please describe your project and let us know if there is any urgency',
                'is_textarea' => true,
            ],
        ];

        if ( ! isset( $field_map[ $field ] ) ) {
            return;
        }

        $config      = $field_map[ $field ];
        $placeholder = $args['placeholder'] ?: $config['placeholder'];
        $value       = $form->form_data[ $field ] ?? '';

        if ( isset( $config['value_callback'] ) && is_callable( [ $form, $config['value_callback'] ] ) ) {
            $value = $form->{ $config['value_callback'] }( $value );
        }

        if ( ! empty( $config['is_textarea'] ) ) {
            printf(
                $config['template'],
                $attrs,
                esc_attr( $placeholder ),
                esc_textarea( $value ),
                intval( $args['cols'] ),
                intval( $args['rows'] )
            );
        } else {
            printf(
                $config['template'],
                $attrs,
                esc_attr( $placeholder ),
                esc_attr( $value )
            );
        }
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

