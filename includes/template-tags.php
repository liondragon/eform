<?php
// includes/template-tags.php

if ( ! function_exists( 'eform_field' ) ) {
    /**
     * Render a form field using registry configuration.
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
            'autocomplete'=> '',
        ];
        $args = array_merge( $defaults, $args );

        $fields = $registry->get_fields( $template );
        if ( ! isset( $fields[ $field ] ) ) {
            return;
        }

        $config    = $fields[ $field ];
        $overrides = array_filter(
            $args,
            static function ( $value ) {
                return $value !== '' && false !== $value;
            }
        );
        $config = array_merge( $config, $overrides );

        $required_attr = ! empty( $config['required'] ) ? ' required aria-required="true"' : '';

        $extra_attrs = '';
        foreach ( [ 'pattern', 'maxlength', 'minlength', 'title', 'placeholder', 'autocomplete' ] as $attr ) {
            if ( isset( $config[ $attr ] ) && $config[ $attr ] !== '' ) {
                $extra_attrs .= ' ' . $attr . '="' . esc_attr( $config[ $attr ] ) . '"';
            }
        }

        $attrs   = $required_attr . $extra_attrs;
        $post_key = $config['post_key'] ?? $field;
        $value   = $form->form_data[ $field ] ?? '';

        if ( isset( $config['value_callback'] ) && is_callable( [ $form, $config['value_callback'] ] ) ) {
            $value = $form->{ $config['value_callback'] }( $value );
        }

        if ( 'textarea' === ( $config['type'] ?? '' ) ) {
            $cols = intval( $config['cols'] ?? $args['cols'] );
            $rows = intval( $config['rows'] ?? $args['rows'] );
            printf(
                '<textarea name="%1$s" cols="%2$d" rows="%3$d"%4$s>%5$s</textarea>',
                esc_attr( $post_key ),
                $cols,
                $rows,
                $attrs,
                esc_textarea( $value )
            );
        } else {
            $type = $config['type'] ?? 'text';
            printf(
                '<input type="%1$s" name="%2$s" value="%3$s"%4$s>',
                esc_attr( $type ),
                esc_attr( $post_key ),
                esc_attr( $value ),
                $attrs
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

