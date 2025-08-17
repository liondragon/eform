<?php
// includes/FormManager.php

/**
 * Coordinates form processing and template rendering.
 */
class FormManager {
    private Enhanced_Internal_Contact_Form $form;
    private Renderer $renderer;
    /** @var bool Tracks if assets have been enqueued. */
    private static bool $assets_enqueued = false;

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
     * @param Enhanced_ICF_Form_Processor|null $processor Processor handling submissions.
     *
     * @return string Rendered form HTML.
     */
    public function handle_shortcode( $atts = [], ?Enhanced_ICF_Form_Processor $processor = null ) {
        // Opportunistic cleanup of stale uploads before rendering any form.
        if ( class_exists( 'Uploads' ) ) {
            Uploads::maybe_gc();
        }

        self::enqueue_assets();
        return $this->form->handle_shortcode( $atts, $processor );
    }

    /**
     * Enqueue form assets only once when a form is rendered.
     */
    private static function enqueue_assets(): void {
        if ( self::$assets_enqueued ) {
            return;
        }

        $base    = __DIR__ . '/../eforms.php';
        $css_file = 'assets/forms.css';
        $js_file  = 'assets/forms.js';

        $css_path = plugin_dir_path( $base ) . $css_file;
        $js_path  = plugin_dir_path( $base ) . $js_file;

        if ( file_exists( $css_path ) ) {
            $css_url = plugins_url( $css_file, $base );
            wp_enqueue_style( 'eforms-css', $css_url, [], filemtime( $css_path ) );
        }

        if ( file_exists( $js_path ) ) {
            $js_url = plugins_url( $js_file, $base );
            wp_enqueue_script( 'eforms-js', $js_url, [], filemtime( $js_path ), true );
        }

        self::$assets_enqueued = true;
    }
}
