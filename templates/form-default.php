<?php
/**
 * Template: form-default.php
 */
?>
<div id="contact_form" class="contact_form">
    <form class="main_contact_form" id="main_contact_form" aria-label="Contact Form" method="post" action="">
        <?php Enhanced_Internal_Contact_Form::render_hidden_fields('default'); ?>
        <div class="inputwrap">
            <?php eform_field('name', [ 'required' => true ]); ?>
            <?php eform_field_error('name'); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field('email', [ 'required' => true ]); ?>
            <?php eform_field_error('email'); ?>
        </div>
        <div class="columns_nomargins inputwrap">
            <?php eform_field('phone', [
                'required' => true,
                'pattern'  => '\\s*(?:\\(\\d{3}\\)|\\d{3})[-.\\s]?\\d{3}[-.\\s]?\\d{4}\\s*',
                'title'    => 'U.S. phone number (10 digits)',
            ]); ?>
            <?php eform_field_error('phone'); ?>
            <?php eform_field('zip', [
                'required'  => true,
                'pattern'   => '\\d{5}',
                'maxlength' => 5,
                'minlength' => 5,
            ]); ?>
            <?php eform_field_error('zip'); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field('message', [
                'required'  => true,
                'minlength' => 20,
                'maxlength' => 1000,
            ]); ?>
            <?php eform_field_error('message'); ?>
        </div>

        <input type="hidden" name="submitted" value="1" aria-hidden="true">
        <button type="submit" name="enhanced_form_submit_default" aria-label="Send Request" value="Send Request">Send Request</button>
    </form>
</div>
