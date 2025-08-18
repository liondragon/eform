<?php
// templates/form-virtual-quote.php
global $eform_registry, $eform_form, $eform_current_template;
?>
<div id="contact_form" class="contact_form">
    <form class="virtual_quote_form" id="virtual_quote_form" aria-label="Virtual Quote Form" method="post" action="">
        <?php Enhanced_Internal_Contact_Form::render_hidden_fields('virtual-quote'); ?>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'first_name', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'first_name' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'last_name', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'last_name' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'email', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'email' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'phone', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'phone' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'street_address', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'street_address' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'city', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'city' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'state', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'state' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'zip', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'zip' ); ?>
        </div>
        <div class="inputwrap">
            <?php eform_field( $eform_registry, $eform_form, $eform_current_template, 'floor_size', [ 'required' => true ] ); ?>
            <?php eform_field_error( $eform_form, 'floor_size' ); ?>
        </div>
        <?php $eform_registry->register_field( $eform_current_template, 'steps', [ 'required' => true ] ); ?>
        <div class="inputwrap">
            <span>Are there any steps?</span>
            <label><input type="radio" name="steps_input" value="yes" <?php echo ( $eform_form->form_data['steps'] ?? '' ) === 'yes' ? 'checked' : ''; ?> required> Yes</label>
            <label><input type="radio" name="steps_input" value="no" <?php echo ( $eform_form->form_data['steps'] ?? '' ) === 'no' ? 'checked' : ''; ?> required> No</label>
            <?php eform_field_error( $eform_form, 'steps' ); ?>
        </div>
        <?php $eform_registry->register_field( $eform_current_template, 'railings', [ 'required' => true ] ); ?>
        <div class="inputwrap">
            <span>Are there any railings sitting directly on the floor?</span>
            <label><input type="radio" name="railings_input" value="yes" <?php echo ( $eform_form->form_data['railings'] ?? '' ) === 'yes' ? 'checked' : ''; ?> required> Yes</label>
            <label><input type="radio" name="railings_input" value="no" <?php echo ( $eform_form->form_data['railings'] ?? '' ) === 'no' ? 'checked' : ''; ?> required> No</label>
            <?php eform_field_error( $eform_form, 'railings' ); ?>
        </div>
        <input type="hidden" name="submitted" value="1" aria-hidden="true">
        <button type="submit" name="enhanced_form_submit_virtual-quote" aria-label="Send Request" value="Send Request">Send Request</button>
    </form>
</div>

