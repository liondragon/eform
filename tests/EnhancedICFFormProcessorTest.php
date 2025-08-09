<?php
use PHPUnit\Framework\TestCase;

class EnhancedICFFormProcessorTest extends TestCase {
    private $processor;
    private $registry;

    protected function setUp(): void {
        $this->registry  = new FieldRegistry();
        $this->processor = new Enhanced_ICF_Form_Processor(new Logger(), $this->registry);
    }

    private function build_submission(string $template = 'default', array $overrides = []): array {
        $field_map = $this->registry->get_fields($template);

        $data = [
            'enhanced_icf_form_nonce' => 'valid',
            'enhanced_url'           => '',
            'enhanced_form_time'     => time() - 10,
            'enhanced_js_check'      => '1',
        ];

        $defaults = get_default_field_values( $this->registry, $template );

        foreach ($field_map as $field => $details) {
            $value = $defaults[$field] ?? '';
            if (array_key_exists($field, $overrides)) {
                $value = $overrides[$field];
                unset($overrides[$field]);
            }
            $data[$details['post_key']] = $value;
        }

        foreach ($overrides as $key => $value) {
            $data[$key] = $value;
        }

        return $data;
    }

    public function test_successful_submission() {
        $data = $this->build_submission();
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertTrue($result['success']);
    }

    public function test_nonce_failure() {
        $data = $this->build_submission(overrides: ['enhanced_icf_form_nonce' => 'invalid']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Invalid submission detected.', $result['message']);
    }

    public function test_honeypot_failure() {
        $data = $this->build_submission(overrides: ['enhanced_url' => 'http://spam']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Bot test failed.', $result['message']);
    }

    public function test_honeypot_array_failure() {
        $data = $this->build_submission(overrides: ['enhanced_url' => ['spam']]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Bot test failed.', $result['message']);
    }

    public function test_submission_time_failure() {
        $data = $this->build_submission(overrides: ['enhanced_form_time' => time()]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Submission too fast. Please try again.', $result['message']);
    }

    public function test_submission_time_array_failure() {
        $data = $this->build_submission(overrides: ['enhanced_form_time' => ['now']]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Submission too fast. Please try again.', $result['message']);
    }

    public function test_js_check_failure() {
        $data = $this->build_submission();
        unset($data['enhanced_js_check']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('JavaScript must be enabled.', $result['message']);
    }

    public function test_field_validation_failure() {
        $data = $this->build_submission(overrides: ['name' => 'Jo']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertSame(['name' => 'Name too short.'], $result['errors']);
    }

    public function test_validation_errors_follow_field_map() {
        $data = $this->build_submission(overrides: [
            'email'            => 'not-an-email',
            'phone'            => '000',
            'enhanced_fields'  => 'name,email',
        ]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertArrayNotHasKey('phone', $result['errors']);
        $this->assertSame('Invalid email.', $result['errors']['email']);
    }

    public function test_template_without_phone_field() {
        foreach (['name', 'email', 'zip', 'message'] as $field) {
            $this->registry->register_field('no_phone', $field, [ 'required' => true ]);
        }
        $data   = $this->build_submission('no_phone');
        $result = $this->processor->process_form_submission('no_phone', $data);
        $this->assertTrue($result['success']);
    }

    public function test_required_phone_missing() {
        foreach (['name', 'email', 'phone'] as $field) {
            $this->registry->register_field('phone_only', $field, [ 'required' => $field === 'phone' ]);
        }
        $data   = $this->build_submission('phone_only', overrides: ['phone' => '']);
        $result = $this->processor->process_form_submission('phone_only', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertSame(['phone' => 'Phone is required.'], $result['errors']);
    }
}
