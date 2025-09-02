<?php
use PHPUnit\Framework\TestCase;

class RendererGroupFieldsetTest extends TestCase {
    public function test_radio_and_checkbox_groups_use_fieldset_and_legend() {
        $form = new FormData();
        $form->form_data = [];
        $form->field_errors = [];

        $config = [
            'fields' => [
                [
                    'key' => 'preferred',
                    'type' => 'radio',
                    'label' => 'Preferred',
                    'choices' => ['yes','no'],
                    'required' => true,
                ],
                [
                    'key' => 'options',
                    'type' => 'checkbox',
                    'label' => 'Options',
                    'choices' => ['a','b'],
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

        $radio_fieldset = $xpath->query('//fieldset[legend[contains(text(),"Preferred")]]')->item(0);
        $this->assertNotNull( $radio_fieldset );
        $legend = $xpath->query('./legend', $radio_fieldset)->item(0);
        $this->assertNotNull( $legend );
        $this->assertNotNull( $xpath->query('.//span[@class="required"]', $legend)->item(0) );
        $radio_input = $xpath->query('.//input[@type="radio"]', $radio_fieldset)->item(0);
        $this->assertNotNull( $radio_input );
        $radio_id = $radio_input->getAttribute('id');
        $this->assertNotEmpty( $radio_id );
        $this->assertNotNull( $xpath->query('//label[@for="' . $radio_id . '"]')->item(0) );

        $checkbox_fieldset = $xpath->query('//fieldset[legend[contains(text(),"Options")]]')->item(0);
        $this->assertNotNull( $checkbox_fieldset );
        $checkbox_input = $xpath->query('.//input[@type="checkbox"]', $checkbox_fieldset)->item(0);
        $this->assertNotNull( $checkbox_input );
        $checkbox_id = $checkbox_input->getAttribute('id');
        $this->assertNotEmpty( $checkbox_id );
        $this->assertNotNull( $xpath->query('//label[@for="' . $checkbox_id . '"]')->item(0) );
    }
}
