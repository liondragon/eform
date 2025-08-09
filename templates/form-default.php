<?php
/**
 * Template: form-default.php
 */
global $eform_registry, $eform_form, $eform_current_template;
?>
<div id="contact_form" class="contact_form">
    <form class="main_contact_form" id="main_contact_form" aria-label="Contact Form" method="post" action="">
        <?php Enhanced_Internal_Contact_Form::render_hidden_fields('default'); ?>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'name', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'name' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'email', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'email' ); ?>
        </div>
        <div class="columns_nomargins inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'phone', [
                'required' => true,
                // Use explicit alternation instead of a character class so the pattern
                // is compatible with JavaScript's upcoming `v` flag syntax.
                'pattern'  => '(?:\\(\\d{3}\\)|\\d{3})(?: |\\.|-)?\\d{3}(?: |\\.|-)?\\d{4}',
                'title'    => 'U.S. phone number (10 digits)',
            ] ); ?>
            <?php eform_field_error( $eform_form, 'phone' ); ?>
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'zip', [
                'required'  => true,
                'pattern'   => '\\d{5}',
                'maxlength' => 5,
                'minlength' => 5,
            ] ); ?>
            <?php eform_field_error( $eform_form, 'zip' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'message', [
                'required'  => true,
                'minlength' => 20,
                'maxlength' => 1000,
            ] ); ?>
            <?php eform_field_error( $eform_form, 'message' ); ?>
        </div>

        <input type="hidden" name="submitted" value="1" aria-hidden="true">
        <button type="submit" name="enhanced_form_submit_default" aria-label="Send Request" value="Send Request">Send Request</button>
    </form>
</div>
