<?php
// includes/FormManager.php

/**
 * Coordinates form processing and template rendering.
 */
class FormManager {
    private Enhanced_Internal_Contact_Form $form;
    private Renderer $renderer;

    public function __construct( Enhanced_Internal_Contact_Form $form, Renderer $renderer ) {
        $this->form     = $form;
        $this->renderer = $renderer;
        // Ensure the form uses our renderer instance.
        if ( method_exists( $this->form, 'set_renderer' ) ) {
            $this->form->set_renderer( $renderer );
        }
    }

    /**
     * Handle shortcode rendering for a form instance.
     *
     * @param array                     $atts      Shortcode attributes.
     * @param FieldRegistry|null        $registry  Field registry for this instance.
     * @param Enhanced_ICF_Form_Processor|null $processor Processor handling submissions.
     *
     * @return string Rendered form HTML.
     */
    public function handle_shortcode( $atts = [], ?FieldRegistry $registry = null, ?Enhanced_ICF_Form_Processor $processor = null ) {
        return $this->form->handle_shortcode( $atts, $registry, $processor );
    }
}
