<?php
use PHPUnit\Framework\TestCase;

class EnhancedICFFormProcessorErrorsTest extends TestCase {
    public function test_process_form_submission_returns_errors_with_keys() {
        $registry  = new FieldRegistry();
        register_template_fields_from_config( $registry, 'default' );
        $processor = new Enhanced_ICF_Form_Processor(new Logger(), $registry);

        $submitted = [
            'enhanced_icf_form_nonce' => 'valid',
            'enhanced_url'           => '',
            'enhanced_form_time'     => time() - 10,
            'enhanced_js_check'      => '1',
            'name_input'             => 'Jo',
            'email_input'            => 'not-an-email',
            'tel_input'              => '123',
            'zip_input'              => 'abcde',
            'message_input'          => 'short',
        ];

        $result = $processor->process_form_submission('default', $submitted);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertArrayHasKey('phone', $result['errors']);
        $this->assertArrayHasKey('message', $result['errors']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
    }
}
