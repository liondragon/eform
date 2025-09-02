<?php
use PHPUnit\Framework\TestCase;

class EnhancedICFFormProcessorErrorsTest extends TestCase {
    public function test_process_form_submission_returns_errors_with_keys() {
        $processor = new Enhanced_ICF_Form_Processor(new Logging());

        $form_id  = 'default';
        $submitted = [
            '_wpnonce'   => 'valid',
            'eforms_hp'  => '',
            'timestamp'  => time() - 10,
            'js_ok'      => '1',
            'form_id'    => $form_id,
            'instance_id'=> 'i_test',
            $form_id     => [
                'name'    => 'Jo',
                'email'   => 'not-an-email',
                'tel'     => '123',
                'zip'     => 'abcde',
                'message' => 'short',
            ],
        ];

        $result = $processor->process_form_submission('default', $submitted);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertArrayHasKey('tel', $result['errors']);
        $this->assertArrayHasKey('message', $result['errors']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
    }
}
