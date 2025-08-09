<?php
// templates/form-custom.php
?>
<div id="contact_form" class="contact_form">
    <form class="general_contact_form" id="general_contact_form" aria-label="Contact Form" method="post" action="">
        <?php Enhanced_Internal_Contact_Form::render_hidden_fields('custom'); ?>
        <div><?php eform_field('message', [ 'required' => true, 'placeholder' => 'Write your message...', 'rows' => 6 ]); ?>
            <?php eform_field_error('message'); ?></div>
        <div><?php eform_field('email', [ 'required' => true, 'placeholder' => 'Enter Your Email*' ]); ?>
            <?php eform_field_error('email'); ?></div>
        <div><button type="submit" name="enhanced_form_submit_custom">Click to Send</button></div>
    </form>
</div>
