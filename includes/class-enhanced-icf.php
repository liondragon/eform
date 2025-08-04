<?php
// includes/class-enhanced-icf.php

class Enhanced_Internal_Contact_Form {
    private $redirect_url='/?page_id=20'; // Set to empty string to disable redirect
    private $success_message = '<div class="form-message success">Thank you! Your message has been sent.</div>';
    private $error_message = '';
    private $form_submitted = false;
    private $inline_css = ''; // Holds aggregated inline CSS
    private $form_data = [];
    private $load_css = false; // Flag to control CSS loading
    private $use_inline_css = true; // Use inline CSS or enqueue stylesheet
    private $processed_template = ''; // Track which template was submitted
    private $loaded_css_templates = []; // Track templates whose CSS is loaded
    private $css_printed = false; // Ensure CSS only printed once
    private $processor;

    public function __construct( Enhanced_ICF_Form_Processor $processor ) {
        $this->processor = $processor;

        // Process form early
        add_action('init',              [$this, 'maybe_handle_form']);
        // Perform redirect at template stage
        add_action('template_redirect', [$this, 'maybe_do_redirect'], 1);
        // Register shortcode to render form
        add_shortcode('enhanced_icf_shortcode', [$this, 'handle_shortcode']);
    }

    public function maybe_handle_form() {
        if ('POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }

        $template = sanitize_key($_POST['enhanced_template'] ?? 'default');
        $submit_key = 'enhanced_form_submit_' . $template;

        if (isset($_POST[$submit_key])) {
            $this->processed_template = $template;
            $result = $this->processor->process_form_submission($template);
            if ($result['success']) {
                $this->form_submitted = true;
            } else {
                $this->error_message = '<div class="form-message error">' . $result['message'] . '</div>';
                $this->form_data     = $result['form_data'] ?? [];
            }
        }
    }

    /**
     * Redirect after successful submission, before rendering template.
     */
    public function maybe_do_redirect() {
        if ($this->form_submitted && ! empty($this->redirect_url)) {
            wp_safe_redirect( esc_url_raw($this->redirect_url) );
            exit;
        }
    }

    // Method hooked to wp_head or wp_footer when inline CSS is needed
    public function print_inline_css() {
        if (!empty($this->inline_css) && ! $this->css_printed) {
            echo '<style id="enhanced-icf-inline-style">' . $this->inline_css . '</style>';
            $this->css_printed = true;
        }
    }

    /**
     * Output hidden fields used across form templates.
     */
    public static function render_hidden_fields($template) {
        echo wp_nonce_field('enhanced_icf_form_action', 'enhanced_icf_form_nonce', true, false);
        echo '<input type="hidden" name="enhanced_form_time" value="' . time() . '">';
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

    private function render_form( $template ) {
        // Load template-specific CSS if style="true"
        if ( $this->load_css && ! in_array( $template, $this->loaded_css_templates, true ) ) {
            $css_file = "assets/{$template}.css";
            $css_path = plugin_dir_path( __FILE__ ) . '/../' . $css_file;

            if ( file_exists( $css_path ) ) {
                if ( $this->use_inline_css ) {
                    $this->inline_css .= file_get_contents( $css_path ) . "\n";
                    $this->loaded_css_templates[] = $template;

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
                    $this->loaded_css_templates[] = $template;
                }
            }
        }

        // If we succeeded *and* have a redirect URL, bail out (we’ll redirect instead)
        if ( $this->form_submitted && ! empty( $this->redirect_url ) ) {
            return '';
        }

        // Capture the form HTML
        $template_path = plugin_dir_path( __FILE__ ) . "../templates/form-{$template}.php";
        ob_start();
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            echo '<p>Form template not found.</p>';
        }
        $form_html = ob_get_clean();

        // Prepend any error or success messages to the form HTML for the processed template only
        if ( $template === $this->processed_template ) {
            if ( $this->error_message ) {
                $form_html = $this->error_message . $form_html;
            } elseif ( $this->form_submitted ) {
                $form_html = $this->success_message . $form_html;
            }
        }

        return $form_html;
    }

    // Expose phone formatting for templates
    public function format_phone($digits) {
        return $this->processor->format_phone($digits);
    }
}
