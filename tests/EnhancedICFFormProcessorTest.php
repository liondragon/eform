<?php
use PHPUnit\Framework\TestCase;

class EnhancedICFFormProcessorTest extends TestCase {
    private $processor;
    private $registry;

    protected function setUp(): void {
        $this->registry  = new FieldRegistry();
        register_template_fields_from_config( $this->registry, 'default' );
        $this->processor = new Enhanced_ICF_Form_Processor(new Logger(), $this->registry);
    }

    private function build_submission(string $template = 'default', array $overrides = []): array {
        $field_map = $this->registry->get_fields( $template );

        $form_id = $overrides['enhanced_form_id'] ?? 'form123';
        unset( $overrides['enhanced_form_id'] );

        $data = [
            'enhanced_icf_form_nonce' => $overrides['enhanced_icf_form_nonce'] ?? 'valid',
            'enhanced_url'           => $overrides['enhanced_url'] ?? '',
            'enhanced_form_time'     => $overrides['enhanced_form_time'] ?? time() - 10,
            'enhanced_js_check'      => $overrides['enhanced_js_check'] ?? '1',
            'enhanced_form_id'       => $form_id,
        ];

        $defaults = get_default_field_values( $this->registry, $template );

        $data[ $form_id ] = [];
        foreach ( $field_map as $field => $details ) {
            $value = $defaults[ $field ] ?? '';
            if ( array_key_exists( $field, $overrides ) ) {
                $value = $overrides[ $field ];
                unset( $overrides[ $field ] );
            }
            $data[ $form_id ][ $field ] = $value;
        }

        foreach ( $overrides as $key => $value ) {
            if ( 0 === strpos( $key, 'enhanced_' ) ) {
                $data[ $key ] = $value;
            } else {
                $data[ $form_id ][ $key ] = $value;
            }
        }

        return $data;
    }

    private function invoke_method(object $object, string $method, array $args = []) {
        $ref = new \ReflectionClass($object);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);
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
        $expected = $this->invoke_method($this->processor, 'build_error', ['Nonce Failed', 'Invalid submission detected.']);
        $actual   = $this->invoke_method($this->processor, 'check_nonce', [$data]);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_honeypot_failure() {
        $data = $this->build_submission(overrides: ['enhanced_url' => 'http://spam']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->processor, 'build_error', ['Bot Alert: Honeypot Filled', 'Bot test failed.']);
        $actual   = $this->invoke_method($this->processor, 'check_honeypot', [$data]);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_honeypot_array_failure() {
        $data = $this->build_submission(overrides: ['enhanced_url' => ['spam']]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->processor, 'build_error', ['Bot Alert: Honeypot Filled', 'Bot test failed.']);
        $actual   = $this->invoke_method($this->processor, 'check_honeypot', [$data]);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_submission_time_failure() {
        $data = $this->build_submission(overrides: ['enhanced_form_time' => time()]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->processor, 'build_error', ['Bot Alert: Fast Submission', 'Submission too fast. Please try again.']);
        $actual   = $this->invoke_method($this->processor, 'check_submission_time', [$data]);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_submission_time_array_failure() {
        $data = $this->build_submission(overrides: ['enhanced_form_time' => ['now']]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->processor, 'build_error', ['Bot Alert: Fast Submission', 'Submission too fast. Please try again.']);
        $actual   = $this->invoke_method($this->processor, 'check_submission_time', [$data]);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_js_check_failure() {
        $data = $this->build_submission();
        unset($data['enhanced_js_check']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->processor, 'build_error', ['Bot Alert: JS Check Missing', 'JavaScript must be enabled.']);
        $actual   = $this->invoke_method($this->processor, 'check_js_enabled', [$data]);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_field_validation_failure() {
        $data = $this->build_submission(overrides: ['message' => 'too short']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertSame(['message' => 'Message too short.'], $result['errors']);
    }

    public function test_only_configured_fields_are_validated() {
        $this->registry->register_field_from_config('partial', 'name', [
            'post_key' => 'name_input',
            'type'     => 'text',
            'required' => true,
        ]);
        $this->registry->register_field_from_config('partial', 'email', [
            'post_key' => 'email_input',
            'type'     => 'email',
            'required' => true,
        ]);
        $data = $this->build_submission('partial', overrides: [ 'email' => 'not-an-email' ]);
        $form_id = $data['enhanced_form_id'];
        $data[ $form_id ]['phone'] = '000';
        $result = $this->processor->process_form_submission('partial', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertArrayNotHasKey('phone', $result['errors']);
        $this->assertSame('Invalid email.', $result['errors']['email']);
    }

    public function test_template_without_phone_field() {
        $this->registry->register_field_from_config('no_phone', 'name', [
            'post_key' => 'name_input',
            'type'     => 'text',
            'required' => true,
        ]);
        $this->registry->register_field_from_config('no_phone', 'email', [
            'post_key' => 'email_input',
            'type'     => 'email',
            'required' => true,
        ]);
        $this->registry->register_field_from_config('no_phone', 'zip', [
            'post_key' => 'zip_input',
            'type'     => 'text',
            'required' => true,
        ]);
        $this->registry->register_field_from_config('no_phone', 'message', [
            'post_key' => 'message_input',
            'type'     => 'textarea',
            'required' => true,
        ]);
        $data   = $this->build_submission('no_phone');
        $result = $this->processor->process_form_submission('no_phone', $data);
        $this->assertTrue($result['success']);
    }

    public function test_required_phone_missing() {
        $this->registry->register_field_from_config('phone_only', 'name', [
            'post_key' => 'name_input',
            'type'     => 'text',
        ]);
        $this->registry->register_field_from_config('phone_only', 'email', [
            'post_key' => 'email_input',
            'type'     => 'email',
        ]);
        $this->registry->register_field_from_config('phone_only', 'phone', [
            'post_key' => 'tel_input',
            'type'     => 'tel',
            'required' => true,
        ]);
        $data   = $this->build_submission('phone_only', overrides: ['phone' => '']);
        $result = $this->processor->process_form_submission('phone_only', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertSame(['phone' => 'Phone is required.'], $result['errors']);
    }

    public function test_required_name_missing() {
        $data   = $this->build_submission(overrides: ['name' => '']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertSame(['name' => 'This field is required.'], $result['errors']);
    }

    public function test_optional_field_can_be_blank() {
        $registry = new FieldRegistry();
        $registry->register_field_from_config('opt', 'name', [
            'post_key' => 'name_input',
            'type'     => 'text',
        ]);
        $processor = new Enhanced_ICF_Form_Processor(new Logger(), $registry);
        $form_id = 'form123';
        $data    = [
            'enhanced_icf_form_nonce' => 'valid',
            'enhanced_url'           => '',
            'enhanced_form_time'     => time() - 10,
            'enhanced_js_check'      => '1',
            'enhanced_form_id'       => $form_id,
            $form_id                 => [ 'name' => '' ],
        ];
        $result = $processor->process_form_submission( 'opt', $data );
        $this->assertTrue($result['success']);
    }

    public function test_phone_with_leading_one_is_normalized() {
        $this->assertSame('2345678901', FieldRegistry::sanitize_digits('+1 (234) 567-8901'));
        $data   = $this->build_submission(overrides: ['phone' => '+1 (234) 567-8901']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertTrue($result['success']);
    }
}
