<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/template-tags.php';

class TemplateTagsTest extends TestCase {
    protected function tearDown(): void {
        // Clean up global form object between tests.
        unset( $GLOBALS['eform_form'] );
    }

    public function test_eform_field_error_outputs_message() {
        global $eform_form;
        $eform_form = (object) [
            'field_errors' => [ 'name' => 'Required' ],
        ];

        ob_start();
        eform_field_error( 'name' );
        $output = ob_get_clean();

        $this->assertSame( '<div class="field-error">Required</div>', $output );
    }

    public function test_eform_field_error_outputs_nothing_when_no_message() {
        global $eform_form;
        $eform_form = (object) [ 'field_errors' => [] ];

        ob_start();
        eform_field_error( 'email' );
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_eform_field_outputs_additional_attributes() {
        global $eform_form, $eform_registry, $eform_current_template;

        $eform_registry        = new FieldRegistry();
        $eform_current_template = 'default';

        $eform_form = new class {
            public $form_data = [];
            public function format_phone( $digits ) { return $digits; }
        };

        ob_start();
        eform_field( 'phone', [
            'pattern'   => '(?:\\(\\d{3}\\)|\\d{3})[ .-]?\\d{3}[ .-]?\\d{4}',
            'maxlength' => 14,
            'minlength' => 10,
            'title'     => 'U.S. phone number (10 digits)',
        ] );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'pattern="(?:\\(\\d{3}\\)|\\d{3})[ .-]?\\d{3}[ .-]?\\d{4}"', $output );
        $this->assertStringContainsString( 'maxlength="14"', $output );
        $this->assertStringContainsString( 'minlength="10"', $output );
        $this->assertStringContainsString( 'title="U.S. phone number (10 digits)"', $output );
    }

    public function test_eform_field_outputs_textarea_attributes() {
        global $eform_form, $eform_registry, $eform_current_template;

        $eform_registry        = new FieldRegistry();
        $eform_current_template = 'default';

        $eform_form = (object) [ 'form_data' => [] ];

        ob_start();
        eform_field( 'message', [
            'minlength' => 20,
            'maxlength' => 1000,
        ] );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'minlength="20"', $output );
        $this->assertStringContainsString( 'maxlength="1000"', $output );
    }
}

