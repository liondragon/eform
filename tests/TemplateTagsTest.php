<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/template-tags.php';

class TemplateTagsTest extends TestCase {

    public function test_eform_field_error_outputs_message() {
        $form = (object) [
            'field_errors' => [ 'name' => 'Required' ],
        ];

        ob_start();
        eform_field_error( $form, 'name' );
        $output = ob_get_clean();

        $this->assertSame( '<div class="field-error">Required</div>', $output );
    }

    public function test_eform_field_error_outputs_nothing_when_no_message() {
        $form = (object) [ 'field_errors' => [] ];

        ob_start();
        eform_field_error( $form, 'email' );
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_eform_field_outputs_additional_attributes() {
        $registry          = new FieldRegistry();
        $current_template  = 'default';

        $form = new class {
            public $form_data = [];
            public function format_phone( $digits ) { return $digits; }
        };

        ob_start();
        eform_field( $registry, $form, $current_template, 'phone', [
            'pattern'   => '(?:\\(\\d{3}\\)|\\d{3})(?: |\\.|-)?\\d{3}(?: |\\.|-)?\\d{4}',
            'maxlength' => 14,
            'minlength' => 10,
            'title'     => 'U.S. phone number (10 digits)',
        ] );
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'pattern="(?:\\(\\d{3}\\)|\\d{3})(?: |\\.|-)?\\d{3}(?: |\\.|-)?\\d{4}"',
            $output
        );
        $this->assertStringContainsString( 'maxlength="14"', $output );
        $this->assertStringContainsString( 'minlength="10"', $output );
        $this->assertStringContainsString( 'title="U.S. phone number (10 digits)"', $output );
    }

    public function test_eform_field_outputs_textarea_attributes() {
        $registry         = new FieldRegistry();
        $current_template = 'default';

        $form = (object) [ 'form_data' => [] ];

        ob_start();
        eform_field( $registry, $form, $current_template, 'message', [
            'minlength' => 20,
            'maxlength' => 1000,
        ] );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'minlength="20"', $output );
        $this->assertStringContainsString( 'maxlength="1000"', $output );
    }
}

