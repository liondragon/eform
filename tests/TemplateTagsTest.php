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
}

