<?php
// templates/form-custom.php
?>
<form method="post" action="">
    <?php Enhanced_Internal_Contact_Form::render_hidden_fields('default'); ?>
    <div><textarea name="message_input" placeholder="Write your message..." rows="6" required><?php echo esc_textarea($this->form_data['message'] ?? ''); ?></textarea></div>
    <div><input type="email" name="email_input" placeholder="Enter Your Email*" required value="<?php echo esc_attr($this->form_data['email'] ?? ''); ?>"></div>
    <div><button type="submit" name="enhanced_form_submit_custom">Click to Send</button></div>
</form>
