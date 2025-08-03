<?php
/**
 * Template: form-default.php
 */
?>
<div id="maincontactform" class="contactform">
    <form class="contactform" id="maincontactform" aria-label="Contact Form" method="post" action="">
        <?php echo wp_nonce_field('enhanced_icf_form_action', 'enhanced_icf_form_nonce', true, false); ?>
        <input type="hidden" name="enhanced_form_time" value="<?php echo time(); ?>">
        <input type="hidden" name="enhanced_template" value="default">
        <input type="hidden" name="enhanced_js_check" class="enhanced_js_check" value="">
        <div style="display:none;">
            <input type="text" name="enhanced_url" value="">
        </div>

        <div class="inputwrap">
            <input class="form_field" type="text" name="name_input" autocomplete="name" required aria-required="true" aria-label="Your Name" placeholder="Your Name" value="">
        </div>
        <div class="inputwrap">
            <input class="form_field" type="email" name="email_input" autocomplete="email" required aria-required="true" aria-label="Your Email" placeholder="Your eMail" value="">
        </div>
        <div class="columns_nomargins inputwrap">
            <input class="form_field" type="tel" name="tel_input" autocomplete="tel" required aria-required="true" aria-label="Phone" placeholder="Phone" value="">
            <input class="form_field" type="text" name="zip_input" autocomplete="postal-code" required aria-required="true" aria-label="Project Zip Code" placeholder="Project Zip Code" value="">
        </div>
        <div class="inputwrap">
            <textarea cols="21" rows="5" name="message_input" required aria-required="true" aria-label="Message" placeholder="Please describe your project and let us know if there is any urgency"></textarea>
        </div>

        <input type="hidden" name="submitted" value="1" aria-hidden="true">
        <button type="submit" name="enhanced_form_submit_default" aria-label="Send Request" value="Send Request">Send Request</button>
    </form>
</div>