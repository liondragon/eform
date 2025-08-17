<?php
use PHPUnit\Framework\TestCase;

class RendererInstanceIdTest extends TestCase {
    public function test_ids_include_instance_id(): void {
        $form = new FormData();
        $config = [
            'fields' => [ [ 'key' => 'name', 'type' => 'text' ] ],
        ];

        $renderer = new Renderer();
        ob_start();
        $renderer->render( $form, 'default', $config );
        $output = ob_get_clean();

        $dom = new DOMDocument();
        @$dom->loadHTML('<!DOCTYPE html><html><body>' . $output . '</body></html>');
        $xpath = new DOMXPath( $dom );

        $instance = $xpath->query('//input[@name="instance_id"]')->item(0);
        $this->assertNotNull( $instance );
        $instance_id = $instance->getAttribute('value');
        $input = $xpath->query('//input[@name="default[name]"]')->item(0);
        $this->assertNotNull( $input );
        $this->assertStringContainsString( $instance_id, $input->getAttribute('id') );
    }
}
