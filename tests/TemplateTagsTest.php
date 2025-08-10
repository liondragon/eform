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

}

