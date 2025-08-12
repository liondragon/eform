<?php
require_once __DIR__ . '/../eforms.php';

use PHPUnit\Framework\TestCase;

class AssetsEnqueueTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['enqueued_scripts'] = [];
        $GLOBALS['enqueued_styles']  = [];
        $GLOBALS['registered_styles'] = [];
        $GLOBALS['printed_styles']   = [];
        $_SERVER['REQUEST_METHOD']   = 'GET';
    }

    public function test_scripts_enqueued_when_shortcode_present() {
        global $post;
        $post = new WP_Post();
        $post->post_content = '[eforms]';

        eforms_enqueue_assets();

        $this->assertContains( 'eforms-js', $GLOBALS['enqueued_scripts'] );
    }

    public function test_scripts_not_enqueued_without_shortcode() {
        global $post;
        $post = new WP_Post();
        $post->post_content = 'No shortcode here';

        eforms_enqueue_assets();

        $this->assertEmpty( $GLOBALS['enqueued_scripts'] );
    }

    public function test_css_loaded_once_per_template() {
        $logger    = new Logging();
        $processor = new Enhanced_ICF_Form_Processor( $logger );
        $form      = new Enhanced_Internal_Contact_Form( $processor, $logger );

        $form->handle_shortcode( [ 'template' => 'default', 'style' => 'true' ], $processor );
        $form->handle_shortcode( [ 'template' => 'default', 'style' => 'true' ], $processor );

        $this->assertCount( 1, $GLOBALS['enqueued_styles'] );
        $this->assertContains( 'eforms-default', $GLOBALS['enqueued_styles'] );
    }
}
