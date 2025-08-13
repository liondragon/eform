<?php
// src/class-enhanced-icf.php

class Enhanced_Internal_Contact_Form extends FormData {
    private $redirect_url='/?page_id=20'; // Set to empty string to disable redirect
    private $success_message = '<div class="form-message success">Thank you! Your message has been sent.</div>';
    private $error_message = '';
    private $form_submitted = false;
    private $load_css = false; // Flag to control CSS loading
    private $processed_template = ''; // Track which template was submitted
    private $loaded_css_templates = []; // Track templates whose CSS is loaded
    private $processor; // Default processor for backward compatibility
    private $logger;
    public $template_config = [];
    private $renderer;

    public function __construct( ?Enhanced_ICF_Form_Processor $processor = null, ?Logging $logger = null, ?Renderer $renderer = null ) {
        $this->processor = $processor;
        $this->logger    = $logger;
        $this->renderer  = $renderer ?: new Renderer();
    }

    public function set_renderer( Renderer $renderer ): void {
        $this->renderer = $renderer;
    }

    public function maybe_handle_form( Enhanced_ICF_Form_Processor $processor ) {
        $this->form_data    = [];
        $this->field_errors = [];

        $success_key      = 'enhanced_form_success';
        $success_template = isset( $_GET[ $success_key ] ) ? sanitize_key( $_GET[ $success_key ] ) : '';
        if ( $success_template ) {
            $this->processed_template = $success_template;
            $this->form_submitted     = true;
        }

        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }

        $submitted_data = wp_unslash( $_POST );

        $template   = sanitize_key( $submitted_data['enhanced_template'] ?? 'default' );
        $submit_key = 'enhanced_form_submit_' . $template;

        if ( isset( $submitted_data[ $submit_key ] ) ) {
            $this->processed_template = $template;
            $result                   = $processor->process_form_submission( $template, $submitted_data );
            if ( is_array( $result['success'] ?? null ) ) {
                $mode = $result['success']['mode'] ?? 'inline';
                if ( 'redirect' === $mode && ! empty( $result['success']['redirect_url'] ) ) {
                    wp_safe_redirect( esc_url_raw( $result['success']['redirect_url'] ) );
                    return;
                }

                // Default inline mode uses PRG to avoid resubmission.
                $url = $_SERVER['REQUEST_URI'] ?? '';
                if ( function_exists( 'remove_query_arg' ) ) {
                    $url = remove_query_arg( $success_key, $url );
                } else {
                    $url = preg_replace( '/([?&])' . $success_key . '=[^&]*/', '$1', $url );
                    $url = rtrim( $url, '?&' );
                }
                if ( function_exists( 'add_query_arg' ) ) {
                    $url = add_query_arg( $success_key, $template, $url );
                } else {
                    $sep = strpos( $url, '?' ) === false ? '?' : '&';
                    $url = $url . $sep . $success_key . '=' . rawurlencode( $template );
                }
                wp_safe_redirect( esc_url_raw( $url ) );
                return;
            } else {
                $this->form_data    = $result['form_data'] ?? [];
                $this->field_errors = $result['errors'] ?? [];

                $show_global = empty( $this->field_errors );
                if ( function_exists( 'apply_filters' ) ) {
                    $show_global = apply_filters( 'eform_show_global_error', $show_global, $result );
                }

                if ( $show_global ) {
                    $sanitized_message   = wp_kses_post( $result['message'] );
                    $this->error_message = '<div class="form-message error">' . $sanitized_message . '</div>';
                }
            }
        }
    }

    /**
     * Output hidden fields used across form templates.
     *
     * @return array{0:string,1:string} Array containing the form ID and instance ID.
     */
    public static function render_hidden_fields($template) {
        $form_id     = 'f_' . bin2hex( random_bytes( 5 ) );
        $instance_id = 'i_' . bin2hex( random_bytes( 5 ) );

        echo wp_nonce_field( 'enhanced_icf_form_action', 'enhanced_icf_form_nonce', true, false );
        echo '<input type="hidden" name="enhanced_form_time" value="' . esc_attr( time() ) . '">';
        echo '<input type="hidden" name="enhanced_template" value="' . esc_attr( $template ) . '">';
        echo '<input type="hidden" name="enhanced_js_check" class="enhanced_js_check" value="">';
        echo '<div style="display:none;"><input type="text" name="enhanced_url" value=""></div>';
        echo '<input type="hidden" name="enhanced_form_id" value="' . esc_attr( $form_id ) . '">';
        echo '<input type="hidden" name="enhanced_instance_id" value="' . esc_attr( $instance_id ) . '">';

        return [ $form_id, $instance_id ];
    }

    public function handle_shortcode( $atts, ?Enhanced_ICF_Form_Processor $processor = null ) {
        $atts = shortcode_atts( [
            'template' => 'default',
            'style'    => 'false',
        ], $atts );

        $template       = sanitize_key( $atts['template'] );
        $this->load_css = filter_var( $atts['style'], FILTER_VALIDATE_BOOLEAN );

        if ( null === $processor ) {
            if ( null !== $this->processor ) {
                $processor = $this->processor;
            } else {
                $processor = new Enhanced_ICF_Form_Processor( $this->logger );
            }
        }

        // Ensure helpers like format_phone use the correct processor.
        $this->processor = $processor;

        $this->maybe_handle_form( $processor );

        return $this->render_form( $template );
    }

    private function prepare_css( $template ) {
        if ( ! $this->load_css || in_array( $template, $this->loaded_css_templates, true ) ) {
            return;
        }

        $css_file = 'assets/forms.css';
        $css_path = plugin_dir_path( __FILE__ ) . '/../' . $css_file;

        if ( ! file_exists( $css_path ) ) {
            if ( $this->logger ) {
                $this->logger->log( sprintf( 'Enhanced ICF CSS file missing: %s', $css_path ), Logging::LEVEL_WARNING );
            }
            return;
        }

        if ( ! is_readable( $css_path ) ) {
            if ( $this->logger ) {
                $this->logger->log( sprintf( 'Enhanced ICF CSS file not readable: %s', $css_path ), Logging::LEVEL_WARNING );
            }
            return;
        }

        $handle  = 'eforms-' . $template;
        $css_url = plugins_url( $css_file, __DIR__ . '/../eform.php' );

        wp_register_style( $handle, $css_url, [], filemtime( $css_path ) );
        wp_enqueue_style( $handle );

        if ( did_action( 'wp_head' ) ) {
            add_action( 'wp_footer', function () use ( $handle ) {
                wp_print_styles( $handle );
            } );
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

    private function render_form( $template ) {
        $this->prepare_css( $template );

        // Load template configuration for this template.
        $this->template_config = eform_get_template_config( $template );

        // If we succeeded *and* have a redirect URL, bail out (we’ll redirect instead)
        if ( $this->form_submitted && ! empty( $this->redirect_url ) ) {
            return '';
        }

        ob_start();
        $this->renderer->render( $this, $template, $this->template_config );
        $form_html = ob_get_clean();

        // Inject hidden field listing keys used in this template for processing
        $fields = eform_get_template_fields( $template );
        if ( ! empty( $fields ) ) {
            $keys   = implode( ',', array_keys( $fields ) );
            $hidden = '<input type="hidden" name="enhanced_fields" value="' . esc_attr( $keys ) . '">';
            $form_html = preg_replace( '/<\/form>/', $hidden . '</form>', $form_html, 1 );
        }

        $form_html = $this->prepend_form_messages( $template, $form_html );

        return $form_html;
    }

    // Expose phone formatting for templates
    public function format_phone(string $digits): string {
        return $this->processor ? $this->processor->format_phone($digits) : $digits;
    }
}
