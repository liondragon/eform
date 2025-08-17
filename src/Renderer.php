<?php
// includes/Renderer.php
require_once __DIR__ . '/FieldRegistry.php';

class Renderer {
    /** @var array<int,string> */
    private array $row_stack = [];

    private function sanitize_fragment( string $html ): string {
        if ( $html === '' ) {
            return '';
        }

        $allowed = [
            'div'   => [ 'class' => true ],
            'span'  => [ 'class' => true ],
            'p'     => [ 'class' => true ],
            'br'    => [],
            'strong'=> [ 'class' => true ],
            'em'    => [ 'class' => true ],
            'h1'    => [ 'class' => true ],
            'h2'    => [ 'class' => true ],
            'h3'    => [ 'class' => true ],
            'h4'    => [ 'class' => true ],
            'h5'    => [ 'class' => true ],
            'h6'    => [ 'class' => true ],
            'ul'    => [ 'class' => true ],
            'ol'    => [ 'class' => true ],
            'li'    => [ 'class' => true ],
        ];

        return wp_kses( $html, $allowed );
    }

    public function render( FormData $form, string $template, array $config ) {
        echo '<div id="contact_form" class="contact_form">';
        echo '<div aria-live="polite" class="form-errors" role="alert"></div>';
        echo '<form class="main_contact_form" id="main_contact_form" aria-label="Contact Form" method="post" action="">';
        list( $form_id, $instance_id ) = Enhanced_Internal_Contact_Form::render_hidden_fields( $template );

        $this->row_stack = [];
        foreach ( $config['fields'] ?? [] as $field ) {
            if ( ( $field['type'] ?? '' ) === 'row_group' ) {
                $mode = $field['mode'] ?? '';
                if ( $mode === 'start' ) {
                    $tag   = in_array( $field['tag'] ?? 'div', ['div','section'], true ) ? $field['tag'] : 'div';
                    $class = 'eforms-row';
                    if ( ! empty( $field['class'] ) ) {
                        $class .= ' ' . sanitize_key( $field['class'] );
                    }
                    echo '<' . $tag . ' class="' . esc_attr( $class ) . '">';
                    $this->row_stack[] = $tag;
                } elseif ( $mode === 'end' ) {
                    $tag = array_pop( $this->row_stack );
                    if ( $tag ) {
                        echo '</' . $tag . '>';
                    }
                }
                continue;
            }

            if ( ! isset( $field['key'] ) ) {
                continue;
            }
            $field_key = sanitize_key( $field['key'] );
            $value     = $form->form_data[ $field_key ] ?? '';
            if ( ( $field['type'] ?? '' ) === 'tel' ) {
                $value = $form->format_phone( $value );
            }
            $required = ! empty( $field['required'] ) ? ' required' : '';
            $attr_str = '';
            foreach ( $field as $attr => $val ) {
                if ( in_array( $attr, ['type','required','key','label','choices','options','before_html','after_html','class'], true ) ) {
                    continue;
                }
                $attr_str .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $val ) );
            }

            $before = $this->sanitize_fragment( $field['before_html'] ?? '' );
            $after  = $this->sanitize_fragment( $field['after_html'] ?? '' );
            if ( $before ) {
                echo $before;
            }

            echo '<div class="inputwrap">';
            $name        = $form_id . '[' . $field_key . ']';
            $render_type = FieldRegistry::get_renderer( $field['type'] ?? 'text' );
            $input_id    = $form_id . '-' . $field_key . '-' . $instance_id;
            $error_id    = 'error-' . $input_id;
            $error_msg   = $form->field_errors[ $field_key ] ?? '';
            $aria        = $error_msg ? sprintf( ' aria-describedby="%s" aria-invalid="true"', esc_attr( $error_id ) ) : '';

            $label = $field['label'] ?? ucwords( str_replace( '_', ' ', $field_key ) );
            $required_marker = ! empty( $field['required'] ) ? '<span class="required">*</span>' : '';

            if ( in_array( $field['type'] ?? '', ['radio','checkbox'], true ) && ! empty( $field['choices'] ) ) {
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
                    $checked = '';
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
            echo '<span id="' . esc_attr( $error_id ) . '" class="eforms-error">' . esc_html( $error_msg ) . '</span>';
            echo '</div>';

            if ( $after ) {
                echo $after;
            }
        }

        while ( $tag = array_pop( $this->row_stack ) ) {
            echo '</' . $tag . '>';
        }

        echo '<input type="hidden" name="submitted" value="1" aria-hidden="true">';
        $button_text = $config['submit_button_text'] ?? 'Send';
        echo '<button type="submit" name="eforms_submit" aria-label="Send Request" value="' . esc_attr( $button_text ) . '">' . esc_html( $button_text ) . '</button>';
        echo '</form></div>';
    }
}

