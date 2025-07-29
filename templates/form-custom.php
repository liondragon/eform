<?php
// templates/form-custom.php
?>
<form method="post" action="">
    <?php echo wp_nonce_field('enhanced_icf_form_action', 'enhanced_icf_form_nonce', true, false); ?>
    <input type="hidden" name="enhanced_form_time" value="<?php echo time(); ?>">
    <input type="hidden" name="enhanced_js_check" class="enhanced_js_check" value="">
    <div style="display:none;">
        <input type="text" name="enhanced_url" value="">
    </div>
    <div><textarea name="message_input" placeholder="Write your message..." rows="6" required></textarea></div>
    <div><input type="email" name="email_input" placeholder="Enter Your Email*" required></div>
    <div><button type="submit" name="enhanced_form_submit_custom">Click to Send</button></div>
</form>
