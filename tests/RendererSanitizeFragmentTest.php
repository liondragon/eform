<?php
use PHPUnit\Framework\TestCase;

class RendererSanitizeFragmentTest extends TestCase {
    public function test_before_and_after_html_are_sanitized() {
        $form = new FormData();
        $form->form_data = [];
        $form->field_errors = [];

        $config = [
            'fields' => [
                [
                    'key' => 'name',
                    'type' => 'text',
                    'before_html' => '<div id="bad" class="good" onclick="evil()"><em>Hi</em></div><script>alert(1)</script>',
                    'after_html'  => '<span data-test="nope" class="after">Bye</span><unknown>bad</unknown>',
                ],
            ],
        ];

        $renderer = new Renderer();
        ob_start();
        $renderer->render( $form, 'default', $config );
        $output = ob_get_clean();

        $dom = new DOMDocument();
        @$dom->loadHTML('<!DOCTYPE html><html><body>' . $output . '</body></html>');
        $xpath = new DOMXPath( $dom );

        $before = $xpath->query('//div[@class="good"]')->item(0);
        $this->assertNotNull( $before );
        $this->assertFalse( $before->hasAttribute('id') );
        $this->assertFalse( $before->hasAttribute('onclick') );
        $this->assertNotNull( $xpath->query('.//em', $before)->item(0) );
        $this->assertNull( $xpath->query('//script')->item(0) );

        $after = $xpath->query('//span[@class="after"]')->item(0);
        $this->assertNotNull( $after );
        $this->assertFalse( $after->hasAttribute('data-test') );
    }
}
