<?php
use PHPUnit\Framework\TestCase;

class EnhancedICFFormProcessorErrorsTest extends TestCase {
    public function test_process_form_submission_returns_errors_with_keys() {
        $processor = new Enhanced_ICF_Form_Processor(new Logger());

        $form_id  = 'form123';
        $submitted = [
            'enhanced_icf_form_nonce' => 'valid',
            'enhanced_url'           => '',
            'enhanced_form_time'     => time() - 10,
            'enhanced_js_check'      => '1',
            'enhanced_form_id'       => $form_id,
            $form_id                 => [
                'name'    => 'Jo',
                'email'   => 'not-an-email',
                'phone'   => '123',
                'zip'     => 'abcde',
                'message' => 'short',
            ],
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
