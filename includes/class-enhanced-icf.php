<?php
// includes/class-enhanced-icf.php

class Enhanced_Internal_Contact_Form {
    private $redirect_url='/?page_id=20'; // Set to empty string to disable redirect
    private $success_message = '<div class="form-message success">Thank you! Your message has been sent.</div>';
    private $error_message = '';
    private $form_submitted = false;
    private $inline_css = ''; // Holds aggregated inline CSS
    public $form_data = [];
    public $field_errors = [];
    private $load_css = false; // Flag to control CSS loading
    private $use_inline_css = true; // Use inline CSS or enqueue stylesheet
    private $processed_template = ''; // Track which template was submitted
    private $loaded_css_templates = []; // Track templates whose CSS is loaded
    private $css_printed = false; // Ensure CSS only printed once
    private $processor;
    private $logger;

    public function __construct( Enhanced_ICF_Form_Processor $processor, Logger $logger ) {
        $this->processor = $processor;
        $this->logger    = $logger;

        // Process submissions before rendering any template
        add_action('template_redirect', [$this, 'maybe_handle_form'], 1);
        // Register shortcode to render form
        add_shortcode('enhanced_icf_shortcode', [$this, 'handle_shortcode']);
    }

    public function maybe_handle_form() {
        $this->form_data    = [];
        $this->field_errors = [];

        if ('POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }

        $submitted_data = wp_unslash( $_POST );

        $template   = sanitize_key( $submitted_data['enhanced_template'] ?? 'default' );
        $submit_key = 'enhanced_form_submit_' . $template;

        if ( isset( $submitted_data[ $submit_key ] ) ) {
            $this->processed_template = $template;
            $result                   = $this->processor->process_form_submission( $template, $submitted_data );
            if ($result['success']) {
                $this->form_submitted = true;
                if ( ! empty( $this->redirect_url ) ) {
                    wp_safe_redirect( esc_url_raw( $this->redirect_url ) );
                    exit;
                }
            } else {
                $sanitized_message   = wp_kses_post( $result['message'] );
                $this->error_message = '<div class="form-message error">' . $sanitized_message . '</div>';
                $this->form_data     = $result['form_data'] ?? [];
                $this->field_errors  = $result['errors'] ?? [];
            }
        }
    }

    // Method hooked to wp_head or wp_footer when inline CSS is needed
    public function print_inline_css() {
        if ( ! empty( $this->inline_css ) && ! $this->css_printed ) {
            $handle = 'enhanced-icf-inline-style';

            if ( ! wp_style_is( $handle, 'registered' ) ) {
                wp_register_style( $handle, false );
            }

            wp_enqueue_style( $handle );
            wp_add_inline_style( $handle, $this->inline_css );
            wp_print_styles( $handle );

            $this->css_printed = true;
        }
    }

    /**
     * Output hidden fields used across form templates.
     */
    public static function render_hidden_fields($template) {
        echo wp_nonce_field('enhanced_icf_form_action', 'enhanced_icf_form_nonce', true, false);
        echo '<input type="hidden" name="enhanced_form_time" value="' . esc_attr( time() ) . '">';
        echo '<input type="hidden" name="enhanced_template" value="' . esc_attr($template) . '">';
        echo '<input type="hidden" name="enhanced_js_check" class="enhanced_js_check" value="">';
        echo '<div style="display:none;"><input type="text" name="enhanced_url" value=""></div>';
    }

    public function handle_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'template'     => 'default',
            'style'        => 'false',
            'useinlinecss' => null,
        ], $atts );

        $template       = sanitize_key( $atts['template'] );
        $this->load_css = filter_var( $atts['style'], FILTER_VALIDATE_BOOLEAN );

        // Only override the inline CSS preference if explicitly provided
        if ( null !== $atts['useinlinecss'] ) {
            $this->use_inline_css = filter_var( $atts['useinlinecss'], FILTER_VALIDATE_BOOLEAN );
        }

        return $this->render_form( $template );
    }

    private function prepare_css( $template ) {
        if ( ! $this->load_css || in_array( $template, $this->loaded_css_templates, true ) ) {
            return;
        }

        $css_file = "assets/{$template}.css";
        $css_path = plugin_dir_path( __FILE__ ) . '/../' . $css_file;

        if ( ! file_exists( $css_path ) ) {
            $this->logger->log( sprintf( 'Enhanced ICF CSS file missing: %s', $css_path ), 'warning' );
            return;
        }

        if ( ! is_readable( $css_path ) ) {
            $this->logger->log( sprintf( 'Enhanced ICF CSS file not readable: %s', $css_path ), 'warning' );
            return;
        }

        if ( $this->use_inline_css ) {
            $css = file_get_contents( $css_path );

            if ( false === $css ) {
                $this->logger->log( sprintf( 'Failed to read Enhanced ICF CSS file: %s', $css_path ), 'warning' );
                return;
            }

            $this->inline_css .= $css . "\n";

            if ( did_action( 'wp_head' ) ) {
                if ( ! has_action( 'wp_footer', [ $this, 'print_inline_css' ] ) ) {
                    add_action( 'wp_footer', [ $this, 'print_inline_css' ] );
                }
            } elseif ( ! has_action( 'wp_head', [ $this, 'print_inline_css' ] ) ) {
                add_action( 'wp_head', [ $this, 'print_inline_css' ] );
            }
        } else {
            $handle  = 'enhanced-icf-' . $template;
            $css_url = plugins_url( $css_file, __DIR__ . '/../eform.php' );

            wp_register_style( $handle, $css_url, [], filemtime( $css_path ) );
            wp_enqueue_style( $handle );

            if ( did_action( 'wp_head' ) ) {
                add_action( 'wp_footer', function() use ( $handle ) {
                    wp_print_styles( $handle );
                } );
            }
        }

        $this->loaded_css_templates[] = $template;
    }

    private function prepend_form_messages( $template, $form_html ) {
        if ( $template === $this->processed_template ) {
            if ( $this->error_message ) {
                $form_html = $this->error_message . $form_html;
            } elseif ( $this->form_submitted ) {
                $form_html = $this->success_message . $form_html;
            }
        }

        return $form_html;
    }

    /**
     * Load a form template and return its HTML.
     *
     * Logs an error and returns a fallback message when the template is
     * missing.
     *
     * @param string $template Template slug.
     *
     * @return string Rendered template HTML or fallback message.
     */
    private function include_template( $template ) {
        $template      = sanitize_key( $template );
        $template_path = plugin_dir_path( __FILE__ ) . "../templates/form-{$template}.php";

        if ( file_exists( $template_path ) ) {
            global $eform_current_template, $eform_form;
            $eform_current_template = $template;
            $eform_form            = $this;
            ob_start();
            include $template_path;
            $output = ob_get_clean();
            unset( $GLOBALS['eform_current_template'], $GLOBALS['eform_form'] );
            return $output;
        }

        $this->logger->log( sprintf( 'Enhanced ICF template missing: %s', $template_path ), 'error' );

        return '<p>Form template not found.</p>';
    }

    private function render_form( $template ) {
        $this->prepare_css( $template );

        // If we succeeded *and* have a redirect URL, bail out (we’ll redirect instead)
        if ( $this->form_submitted && ! empty( $this->redirect_url ) ) {
            return '';
        }

        $form_html = $this->include_template( $template );

        // Inject hidden field listing keys used in this template for processing
        global $eform_registry;
        if ( isset( $eform_registry ) ) {
            $fields = $eform_registry->get_fields( $template );
            if ( ! empty( $fields ) ) {
                $keys   = implode( ',', array_keys( $fields ) );
                $hidden = '<input type="hidden" name="enhanced_fields" value="' . esc_attr( $keys ) . '">';
                $form_html = preg_replace( '/<\/form>/', $hidden . '</form>', $form_html, 1 );
            }
        }

        $form_html = $this->prepend_form_messages( $template, $form_html );

        return $form_html;
    }

    /**
     * Magic method to handle dynamic template rendering.
     *
     * Allows calling methods named after templates, e.g. `$form->contact()`,
     * to render `form-contact.php`. If the template file is missing, a warning
     * is logged and a fallback message is returned.
     *
     * @param string $name      The called method name.
     * @param array  $arguments Unused.
     *
     * @return string Rendered template HTML or fallback message on failure.
     */
    public function __call( $name, $arguments ) {
        $template = sanitize_key( $name );

        return $this->include_template( $template );
    }

    // Expose phone formatting for templates
    public function format_phone(string $digits): string {
        return $this->processor->format_phone($digits);
    }
}
