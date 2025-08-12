<?php
require_once __DIR__ . '/../eform.php';

use PHPUnit\Framework\TestCase;

class AssetsEnqueueTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['enqueued_scripts'] = [];
        $GLOBALS['enqueued_styles']  = [];
        $GLOBALS['inline_styles']    = [];
        $GLOBALS['registered_styles'] = [];
        $GLOBALS['printed_styles']   = [];
        $_SERVER['REQUEST_METHOD']   = 'GET';
    }

    public function test_scripts_enqueued_when_shortcode_present() {
        global $post;
        $post = new WP_Post();
        $post->post_content = '[enhanced_icf_shortcode]';

        enhanced_icf_enqueue_scripts();

        $this->assertContains( 'enhanced-icf-js', $GLOBALS['enqueued_scripts'] );
    }

    public function test_scripts_not_enqueued_without_shortcode() {
        global $post;
        $post = new WP_Post();
        $post->post_content = 'No shortcode here';

        enhanced_icf_enqueue_scripts();

        $this->assertEmpty( $GLOBALS['enqueued_scripts'] );
    }

    public function test_css_loaded_once_per_template() {
        $logger    = new Logger();
        $processor = new Enhanced_ICF_Form_Processor( $logger );
        $form      = new Enhanced_Internal_Contact_Form( $processor, $logger );

        $form->handle_shortcode( [ 'template' => 'default', 'style' => 'true' ], $processor );
        $form->handle_shortcode( [ 'template' => 'default', 'style' => 'true' ], $processor );

        $this->assertCount( 1, $GLOBALS['enqueued_styles'] );
        $this->assertArrayHasKey( 'enhanced-icf-inline-default', $GLOBALS['inline_styles'] );
    }
}
