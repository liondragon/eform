<?php
use PHPUnit\Framework\TestCase;

class EnhancedICFFormProcessorTest extends TestCase {
    private $processor;

    protected function setUp(): void {
        $this->processor = new Enhanced_ICF_Form_Processor(new Logger(), new FieldRegistry());
    }

    private function valid_submission(): array {
        return [
            'enhanced_icf_form_nonce' => 'valid',
            'enhanced_url' => '',
            'enhanced_form_time' => time() - 10,
            'enhanced_js_check' => '1',
            'name_input' => 'John Doe',
            'email_input' => 'john@example.com',
            'tel_input' => '1234567890',
            'zip_input' => '12345',
            'message_input' => str_repeat('a', 25),
        ];
    }

    public function test_successful_submission() {
        $data = $this->valid_submission();
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertTrue($result['success']);
    }

    public function test_nonce_failure() {
        $data = $this->valid_submission();
        $data['enhanced_icf_form_nonce'] = 'invalid';
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Invalid submission detected.', $result['message']);
    }

    public function test_honeypot_failure() {
        $data = $this->valid_submission();
        $data['enhanced_url'] = 'http://spam';
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Bot test failed.', $result['message']);
    }

    public function test_honeypot_array_failure() {
        $data = $this->valid_submission();
        $data['enhanced_url'] = ['spam'];
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Bot test failed.', $result['message']);
    }

    public function test_submission_time_failure() {
        $data = $this->valid_submission();
        $data['enhanced_form_time'] = time();
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Submission too fast. Please try again.', $result['message']);
    }

    public function test_submission_time_array_failure() {
        $data = $this->valid_submission();
        $data['enhanced_form_time'] = ['now'];
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Submission too fast. Please try again.', $result['message']);
    }

    public function test_js_check_failure() {
        $data = $this->valid_submission();
        unset($data['enhanced_js_check']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('JavaScript must be enabled.', $result['message']);
    }

    public function test_field_validation_failure() {
        $data = $this->valid_submission();
        $data['name_input'] = 'Jo';
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Name too short.', $result['message']);
    }

    public function test_only_registered_fields_validated() {
        $data = $this->valid_submission();
        unset($data['tel_input'], $data['zip_input']);
        $data['enhanced_fields'] = 'name,email,message';
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertTrue($result['success']);
    }
}
