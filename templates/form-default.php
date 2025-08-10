<?php
/**
 * Template: form-default.php
 */
global $eform_form, $eform_current_template;
$config = $eform_form->template_config;
?>
<div id="contact_form" class="contact_form">
    <form class="main_contact_form" id="main_contact_form" aria-label="Contact Form" method="post" action="">
        <?php Enhanced_Internal_Contact_Form::render_hidden_fields('default'); ?>
        <?php foreach ( $config['fields'] ?? [] as $post_key => $field ) :
            $field_key = FieldRegistry::field_key_from_post( $post_key );
            $value     = $eform_form->form_data[ $field_key ] ?? '';
            if ( ( $field['type'] ?? '' ) === 'tel' ) {
                $value = $eform_form->format_phone( $value );
            }
            $required = isset( $field['required'] ) ? ' required aria-required="true"' : '';
            $attr_str = '';
            foreach ( $field as $attr => $val ) {
                if ( in_array( $attr, [ 'type', 'required', 'style' ], true ) ) {
                    continue;
                }
                $attr_str .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $val ) );
            }
        ?>
        <div class="inputwrap" style="<?php echo esc_attr( $field['style'] ?? '' ); ?>">
            <?php if ( ( $field['type'] ?? '' ) === 'textarea' ) : ?>
                <textarea name="<?php echo esc_attr( $post_key ); ?>"<?php echo $required . $attr_str; ?>><?php echo esc_textarea( $value ); ?></textarea>
            <?php else : ?>
                <input type="<?php echo esc_attr( $field['type'] ?? 'text' ); ?>" name="<?php echo esc_attr( $post_key ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php echo $required . $attr_str; ?>>
            <?php endif; ?>
            <?php eform_field_error( $eform_form, $field_key ); ?>
        </div>
        <?php endforeach; ?>

        <input type="hidden" name="submitted" value="1" aria-hidden="true">
        <button type="submit" name="enhanced_form_submit_default" aria-label="Send Request" value="Send Request">Send Request</button>
    </form>
</div>
