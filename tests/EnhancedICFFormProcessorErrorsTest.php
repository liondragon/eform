<?php
use PHPUnit\Framework\TestCase;

class EnhancedICFFormProcessorErrorsTest extends TestCase {
    public function test_process_form_submission_returns_errors_with_keys() {
        $registry  = new FieldRegistry();
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
        $this->assertArrayHasKey('name', $result['errors']);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
    }
}
