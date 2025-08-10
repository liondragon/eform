<?php
// includes/render.php

/**
 * Render a form from configuration when no PHP template is available.
 *
 * @param array $config Template configuration containing field definitions.
 */
function eform_render_form(array $config) {
    global $eform_form, $eform_current_template;

    echo '<div id="contact_form" class="contact_form">';
    echo '<form class="main_contact_form" id="main_contact_form" aria-label="Contact Form" method="post" action="">';
    Enhanced_Internal_Contact_Form::render_hidden_fields($eform_current_template);

    foreach ($config['fields'] ?? [] as $post_key => $field) {
        $field_key = FieldRegistry::field_key_from_post($post_key);
        $value = $eform_form->form_data[$field_key] ?? '';
        if (($field['type'] ?? '') === 'tel') {
            $value = $eform_form->format_phone($value);
        }
        $required = isset($field['required']) ? ' required aria-required="true"' : '';
        $attr_str = '';
        foreach ($field as $attr => $val) {
            if (in_array($attr, ['type','required','style'], true)) {
                continue;
            }
            $attr_str .= sprintf(' %s="%s"', esc_attr($attr), esc_attr($val));
        }
        echo '<div class="inputwrap" style="' . esc_attr($field['style'] ?? '') . '">';
        if (($field['type'] ?? '') === 'textarea') {
            echo '<textarea name="' . esc_attr($post_key) . '"' . $required . $attr_str . '>' . esc_textarea($value) . '</textarea>';
        } else {
            $type = $field['type'] ?? 'text';
            echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($post_key) . '" value="' . esc_attr($value) . '"' . $required . $attr_str . '>';
        }
        eform_field_error($eform_form, $field_key);
        echo '</div>';
    }

    echo '<input type="hidden" name="submitted" value="1" aria-hidden="true">';
    echo '<button type="submit" name="enhanced_form_submit_' . esc_attr($eform_current_template) . '" aria-label="Send Request" value="Send Request">Send Request</button>';
    echo '</form></div>';
}
