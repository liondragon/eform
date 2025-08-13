<?php
// includes/Renderer.php
require_once __DIR__ . '/FieldRegistry.php';

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
        echo '<div aria-live="polite" class="form-errors"></div>';
        echo '<form class="main_contact_form" id="main_contact_form" aria-label="Contact Form" method="post" action="">';
        list( $form_id, $instance_id ) = Enhanced_Internal_Contact_Form::render_hidden_fields( $template );

        foreach ( $config['fields'] ?? [] as $post_key => $field ) {
            if ( isset( $field['key'] ) ) {
                $field_key = sanitize_key( $field['key'] );
            } else {
                $field_key = sanitize_key( preg_replace( '/_input$/', '', (string) $post_key ) );
            }
            if ( 'tel' === $field_key ) {
                $field_key = 'phone';
            }
            $value     = $form->form_data[ $field_key ] ?? '';
            if ( ( $field['type'] ?? '' ) === 'tel' ) {
                $value = $form->format_phone( $value );
            }
            $required  = isset( $field['required'] ) ? ' required aria-required="true"' : '';
            $attr_str  = '';
            foreach ( $field as $attr => $val ) {
                if ( in_array( $attr, [ 'type', 'required', 'style', 'key', 'label', 'choices' ], true ) ) {
                    continue;
                }
                $attr_str .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $val ) );
            }
            echo '<div class="inputwrap" style="' . esc_attr( $field['style'] ?? '' ) . '">';
            $name      = $form_id . '[' . $field_key . ']';
            $input_id  = $form_id . '-' . $instance_id . '-' . $field_key;
            $error_id  = 'error-' . $input_id;
            $error_msg = $form->field_errors[ $field_key ] ?? '';
            $aria      = $error_msg ? sprintf( ' aria-describedby="%s" aria-invalid="true"', esc_attr( $error_id ) ) : '';

            $label = $field['label'] ?? ucwords( str_replace( '_', ' ', $field_key ) );
            $required_marker = isset( $field['required'] ) ? '<span class="required">*</span>' : '';

            $render_type = FieldRegistry::get_renderer( $field['type'] ?? 'text' );
            if ( in_array( $field['type'] ?? '', [ 'radio', 'checkbox' ], true ) && ! empty( $field['choices'] ) ) {
                echo '<fieldset>';
                echo '<legend>' . esc_html( $label ) . $required_marker . '</legend>';
                $choices = $field['choices'];
                $values  = $form->form_data[ $field_key ] ?? ( ( $field['type'] ?? '' ) === 'checkbox' ? [] : '' );
                foreach ( $choices as $choice_key => $choice ) {
                    if ( is_array( $choice ) ) {
                        $choice_value = (string) ( $choice['value'] ?? '' );
                        $choice_label = (string) ( $choice['label'] ?? $choice_value );
                    } else {
                        $choice_value = (string) $choice;
                        $choice_label = ucwords( str_replace( '_', ' ', $choice_value ) );
                    }
                    $option_id = $input_id . '-' . sanitize_key( $choice_value ) . '-' . $choice_key;
                    $checked   = '';
                    if ( ( $field['type'] ?? '' ) === 'checkbox' ) {
                        $checked = in_array( $choice_value, (array) $values, true ) ? ' checked' : '';
                    } else {
                        $checked = ( (string) $values === $choice_value ) ? ' checked' : '';
                    }
                    $option_name = $name . ( ( $field['type'] ?? '' ) === 'checkbox' ? '[]' : '' );
                    echo '<div class="choice">';
                    echo '<input id="' . esc_attr( $option_id ) . '" type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $choice_value ) . '"' . $checked . $required . $attr_str . $aria . '>';
                    echo '<label for="' . esc_attr( $option_id ) . '">' . esc_html( $choice_label ) . '</label>';
                    echo '</div>';
                }
                echo '</fieldset>';
            } elseif ( $render_type === 'textarea' ) {
                echo '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $label ) . $required_marker . '</label>';
                echo '<textarea id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $name ) . '"' . $required . $attr_str . $aria . '>' . esc_textarea( $value ) . '</textarea>';
            } else {
                $type = $field['type'] ?? 'text';
                echo '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $label ) . $required_marker . '</label>';
                echo '<input id="' . esc_attr( $input_id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $required . $attr_str . $aria . '>';
            }
            echo '<span id="' . esc_attr( $error_id ) . '" class="field-error">' . esc_html( $error_msg ) . '</span>';
            echo '</div>';
        }

        echo '<input type="hidden" name="submitted" value="1" aria-hidden="true">';
        echo '<button type="submit" name="enhanced_form_submit_' . esc_attr( $template ) . '" aria-label="Send Request" value="Send Request">Send Request</button>';
        echo '</form></div>';
    }
}
