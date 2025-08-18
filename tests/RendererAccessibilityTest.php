<?php
use PHPUnit\Framework\TestCase;

class RendererAccessibilityTest extends TestCase {
    public function test_fields_have_ids_and_error_associations() {
        $form = new FormData();
        $form->form_data = [];
        $form->field_errors = [ 'name' => 'Required' ];

        $config = [
            'fields' => [
                [ 'key' => 'name',  'type' => 'text',  'required' => true ],
                [ 'key' => 'email', 'type' => 'email' ],
            ],
        ];

        $renderer = new Renderer();

        ob_start();
        $renderer->render( $form, 'default', $config );
        $output = ob_get_clean();

        $dom = new DOMDocument();
        @$dom->loadHTML('<!DOCTYPE html><html><body>' . $output . '</body></html>');
        $xpath = new DOMXPath( $dom );

        $live = $xpath->query('//div[@aria-live="polite" and contains(@class,"form-errors")]')->item(0);
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

        $label = $xpath->query('//label[@for="' . $id . '"]')->item(0);
        $this->assertNotNull( $label );
        $this->assertSame( 'Name', trim( $label->childNodes->item(0)->textContent ) );
        $this->assertNotNull( $xpath->query('.//span[@class="required"]', $label)->item(0) );

        $valid = $xpath->query('//input[@type="email"]')->item(0);
        $this->assertFalse( $valid->hasAttribute('aria-invalid') );
        $this->assertFalse( $valid->hasAttribute('aria-describedby') );
        $valid_id = $valid->getAttribute('id');
        $valid_label = $xpath->query('//label[@for="' . $valid_id . '"]')->item(0);
        $this->assertNotNull( $valid_label );
        $this->assertSame( 'Email', trim( $valid_label->textContent ) );
        $this->assertNull( $xpath->query('.//span[@class="required"]', $valid_label)->item(0) );

        $summary_anchor_invalid = $xpath->query('//div[contains(@class,"form-errors")]//a[@href="#' . $id . '"]')->item(0);
        $this->assertNotNull( $summary_anchor_invalid );
        $summary_anchor_valid = $xpath->query('//div[contains(@class,"form-errors")]//a[@href="#' . $valid_id . '"]')->item(0);
        $this->assertNotNull( $summary_anchor_valid );
    }

    public function test_fieldset_has_id_and_summary_anchor() {
        $form = new FormData();
        $form->form_data = [];
        $form->field_errors = [ 'color' => 'Required' ];

        $config = [
            'fields' => [
                [
                    'key'     => 'color',
                    'type'    => 'radio',
                    'required'=> true,
                    'choices' => [ 'red', 'blue' ],
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

        $fieldset = $xpath->query('//fieldset')->item(0);
        $this->assertNotNull( $fieldset );
        $fieldset_id = $fieldset->getAttribute('id');
        $this->assertNotEmpty( $fieldset_id );

        $summary_anchor = $xpath->query('//div[contains(@class,"form-errors")]//a[@href="#' . $fieldset_id . '"]')->item(0);
        $this->assertNotNull( $summary_anchor );
    }
}
