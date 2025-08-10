<?php
require_once __DIR__ . '/../eform.php';

use PHPUnit\Framework\TestCase;

class AssetsEnqueueTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['enqueued_scripts'] = [];
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
}
