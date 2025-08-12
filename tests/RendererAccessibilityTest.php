<?php
use PHPUnit\Framework\TestCase;

class RendererAccessibilityTest extends TestCase {
    public function test_fields_have_ids_and_error_associations() {
        $form = new FormData();
        $form->form_data = [];
        $form->field_errors = [ 'name' => 'Required' ];

        $config = [
            'fields' => [
                'name_input'  => [ 'type' => 'text', 'required' => true ],
                'email_input' => [ 'type' => 'email' ],
            ],
        ];

        $renderer = new Renderer();

        ob_start();
        $renderer->render( $form, 'default', $config );
        $output = ob_get_clean();

        $dom = new DOMDocument();
        @$dom->loadHTML('<!DOCTYPE html><html><body>' . $output . '</body></html>');
        $xpath = new DOMXPath( $dom );

        $live = $xpath->query('//div[@aria-live="polite"]')->item(0);
        $this->assertNotNull( $live );

        $invalid = $xpath->query('//*[@aria-invalid="true"]')->item(0);
        $this->assertNotNull( $invalid );
        $id = $invalid->getAttribute('id');
        $this->assertNotEmpty( $id );
        $describedby = $invalid->getAttribute('aria-describedby');
        $this->assertSame( 'error-' . $id, $describedby );
        $error = $xpath->query('//span[@id="' . $describedby . '"]')->item(0);
        $this->assertNotNull( $error );
        $this->assertSame( 'Required', $error->textContent );

        $valid = $xpath->query('//input[@type="email"]')->item(0);
        $this->assertFalse( $valid->hasAttribute('aria-invalid') );
        $this->assertFalse( $valid->hasAttribute('aria-describedby') );
    }
}
