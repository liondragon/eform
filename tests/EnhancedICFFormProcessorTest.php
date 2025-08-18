<?php
use PHPUnit\Framework\TestCase;

class EnhancedICFFormProcessorTest extends TestCase {
    private $processor;
    private $security;

    protected function setUp(): void {
        $this->processor = new Enhanced_ICF_Form_Processor(new Logging());
        $this->security  = new Security();
    }

    private function build_submission(string $template = 'default', array $overrides = []): array {
        $field_map = eform_get_template_fields( $template );

        $form_id = $overrides['form_id'] ?? $template;
        unset( $overrides['form_id'] );
        $instance = $overrides['instance_id'] ?? 'i_test';
        unset( $overrides['instance_id'] );

        $data = [
            '_wpnonce'   => $overrides['_wpnonce'] ?? 'valid',
            'eforms_hp'  => $overrides['eforms_hp'] ?? '',
            'timestamp'  => $overrides['timestamp'] ?? time() - 10,
            'js_ok'      => $overrides['js_ok'] ?? '1',
            'form_id'    => $form_id,
            'instance_id'=> $instance,
        ];

        $defaults = get_default_field_values( $template );

        $data[ $form_id ] = [];
        foreach ( $field_map as $field => $details ) {
            $value = $defaults[ $field ] ?? '';
            if ( array_key_exists( $field, $overrides ) ) {
                $value = $overrides[ $field ];
                unset( $overrides[ $field ] );
            }
            $data[ $form_id ][ $field ] = $value;
        }

        $root_keys = ['_wpnonce', 'eforms_hp', 'timestamp', 'js_ok', 'form_id', 'instance_id'];
        foreach ( $overrides as $key => $value ) {
            if ( in_array( $key, $root_keys, true ) ) {
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
        $data   = $this->build_submission();
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertSame('inline', $result['success']['mode']);
    }

    public function test_success_redirect_mode() {
        $template = 'redir';
        $config   = json_decode( file_get_contents( __DIR__ . '/../templates/default.json' ), true );
        $config['success'] = [ 'mode' => 'redirect', 'redirect_url' => 'https://example.com/thanks' ];
        $path = __DIR__ . '/../templates/' . $template . '.json';
        file_put_contents( $path, json_encode( $config ) );

        $data   = $this->build_submission( $template );
        $result = $this->processor->process_form_submission( $template, $data );
        $this->assertSame('redirect', $result['success']['mode']);
        $this->assertSame('https://example.com/thanks', $result['success']['redirect_url']);

        unlink( $path );
    }

    public function test_nonce_failure() {
        $data = $this->build_submission(overrides: ['_wpnonce' => 'invalid']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->security, 'build_error', ['Nonce Failed', 'Invalid submission detected.']);
        $actual   = $this->security->check_nonce($data);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_honeypot_failure() {
        $data = $this->build_submission(overrides: ['eforms_hp' => 'http://spam']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->security, 'build_error', ['Bot Alert: Honeypot Filled', 'Bot test failed.']);
        $actual   = $this->security->check_honeypot($data);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_honeypot_array_failure() {
        $data = $this->build_submission(overrides: ['eforms_hp' => ['spam']]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->security, 'build_error', ['Bot Alert: Honeypot Filled', 'Bot test failed.']);
        $actual   = $this->security->check_honeypot($data);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_submission_time_failure() {
        $data = $this->build_submission(overrides: ['timestamp' => time()]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->security, 'build_error', ['Bot Alert: Fast Submission', 'Submission too fast. Please try again.']);
        $actual   = $this->security->check_submission_time($data);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_submission_time_array_failure() {
        $data = $this->build_submission(overrides: ['timestamp' => ['now']]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->security, 'build_error', ['Bot Alert: Fast Submission', 'Submission too fast. Please try again.']);
        $actual   = $this->security->check_submission_time($data);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_max_form_age_failure() {
        if ( ! defined( 'EFORMS_MAX_FILL_TIME' ) ) {
            define( 'EFORMS_MAX_FILL_TIME', 1000 );
        }
        $data = $this->build_submission(overrides: ['timestamp' => time() - 1001]);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->security, 'build_error', ['Form Expired', 'Form has expired. Please refresh and try again.']);
        $actual   = $this->security->check_submission_time($data);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_post_size_cap_failure() {
        if ( ! defined( 'EFORMS_MAX_POST_BYTES' ) ) {
            define( 'EFORMS_MAX_POST_BYTES', 10 );
        }
        $_SERVER['CONTENT_LENGTH'] = 20;
        $data = $this->build_submission();
        $result = $this->processor->process_form_submission( 'default', $data );
        $this->assertFalse( $result['success'] );
        $expected = $this->invoke_method( $this->security, 'build_error', ['POST Size Exceeded', 'Submission too large.'] );
        $actual   = $this->security->check_post_size();
        $this->assertSame( $expected, $actual );
        $this->assertSame( $expected['message'], $result['message'] );
        unset( $_SERVER['CONTENT_LENGTH'] );
    }

    public function test_js_check_failure() {
        $data = $this->build_submission();
        unset($data['js_ok']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $expected = $this->invoke_method($this->security, 'build_error', ['Bot Alert: JS Check Missing', 'JavaScript must be enabled.']);
        $actual   = $this->security->check_js_enabled($data);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected['message'], $result['message']);
    }

    public function test_js_check_soft_template() {
        $template = 'softjs';
        $config   = json_decode( file_get_contents( __DIR__ . '/../templates/default.json' ), true );
        $config['js_check'] = 'soft';
        $path = __DIR__ . '/../templates/' . $template . '.json';
        file_put_contents( $path, json_encode( $config ) );

        $data = $this->build_submission( $template );
        unset( $data['js_ok'] );
        $result = $this->processor->process_form_submission( $template, $data );
        $this->assertSame('inline', $result['success']['mode']);

        unlink( $path );
    }

    public function test_field_validation_failure() {
        $data = $this->build_submission(overrides: ['message' => 'too short']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertSame(['message' => 'Message too short.'], $result['errors']);
    }

    public function test_only_configured_fields_are_validated() {
        $template = 'partial';
        $config   = [
            'id'      => $template,
            'version' => 1,
            'title'   => 'Partial',
            'email'   => [],
            'success' => [ 'mode' => 'inline', 'redirect_url' => '' ],
            'fields'  => [
                [ 'key' => 'name',  'type' => 'text',  'required' => true ],
                [ 'key' => 'email', 'type' => 'email', 'required' => true ],
            ],
        ];
        $path = __DIR__ . '/../templates/' . $template . '.json';
        file_put_contents( $path, json_encode( $config ) );

        $data = $this->build_submission('partial', overrides: [ 'email' => 'not-an-email' ]);
        $form_id = $data['form_id'];
        $data[ $form_id ]['phone'] = '000';
        $result = $this->processor->process_form_submission('partial', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertArrayNotHasKey('phone', $result['errors']);
        $this->assertSame('Invalid email.', $result['errors']['email']);

        unlink( $path );
    }

    public function test_template_without_phone_field() {
        $template = 'no_phone';
        $config   = [
            'id'      => $template,
            'version' => 1,
            'title'   => 'No Phone',
            'email'   => [],
            'success' => [ 'mode' => 'inline', 'redirect_url' => '' ],
            'fields'  => [
                [ 'key' => 'name',    'type' => 'text',     'required' => true ],
                [ 'key' => 'email',   'type' => 'email',    'required' => true ],
                [ 'key' => 'zip',     'type' => 'text',     'required' => true ],
                [ 'key' => 'message', 'type' => 'textarea', 'required' => true ],
            ],
        ];
        $path = __DIR__ . '/../templates/' . $template . '.json';
        file_put_contents( $path, json_encode( $config ) );

        $data   = $this->build_submission('no_phone');
        $result = $this->processor->process_form_submission('no_phone', $data);
        $this->assertSame('inline', $result['success']['mode']);

        unlink( $path );
    }

    public function test_required_phone_missing() {
        $template = 'phone_only';
        $config   = [
            'id'      => $template,
            'version' => 1,
            'title'   => 'Phone Only',
            'email'   => [],
            'success' => [ 'mode' => 'inline', 'redirect_url' => '' ],
            'fields'  => [
                [ 'key' => 'name',  'type' => 'text' ],
                [ 'key' => 'email', 'type' => 'email' ],
                [ 'key' => 'phone', 'type' => 'tel', 'required' => true ],
            ],
        ];
        $path = __DIR__ . '/../templates/' . $template . '.json';
        file_put_contents( $path, json_encode( $config ) );

        $data   = $this->build_submission('phone_only', overrides: ['phone' => '']);
        $result = $this->processor->process_form_submission('phone_only', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertSame(['phone' => 'Phone is required.'], $result['errors']);

        unlink( $path );
    }

    public function test_required_name_missing() {
        $data   = $this->build_submission(overrides: ['name' => '']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the highlighted fields', $result['message']);
        $this->assertSame(['name' => 'This field is required.'], $result['errors']);
    }

    public function test_optional_field_can_be_blank() {
        $template = 'opt';
        $config   = [
            'id'      => $template,
            'version' => 1,
            'title'   => 'Opt',
            'email'   => [],
            'success' => [ 'mode' => 'inline', 'redirect_url' => '' ],
            'fields'  => [ [ 'key' => 'name', 'type' => 'text' ] ],
        ];
        $path = __DIR__ . '/../templates/' . $template . '.json';
        file_put_contents( $path, json_encode( $config ) );

        $processor = new Enhanced_ICF_Form_Processor(new Logging());
        $data    = $this->build_submission('opt', overrides: ['name' => '']);
        $result = $processor->process_form_submission( 'opt', $data );
        $this->assertSame('inline', $result['success']['mode']);

        unlink( $path );
    }

    public function test_phone_with_leading_one_is_normalized() {
        $this->assertSame('2345678901', Validator::sanitize_digits('+1 (234) 567-8901'));
        $data   = $this->build_submission(overrides: ['phone' => '+1 (234) 567-8901']);
        $result = $this->processor->process_form_submission('default', $data);
        $this->assertSame('inline', $result['success']['mode']);
    }

    public function test_checkbox_field_processed() {
        $template = 'checkbox';
        $config   = [
            'id'      => $template,
            'version' => 1,
            'title'   => 'Checkbox',
            'email'   => [],
            'success' => [ 'mode' => 'inline', 'redirect_url' => '' ],
            'fields'  => [
                [ 'key' => 'name', 'type' => 'text', 'required' => true ],
                [ 'key' => 'opts', 'type' => 'checkbox', 'choices' => ['a','b'], 'required' => true ],
            ],
        ];
        $path = __DIR__ . '/../templates/' . $template . '.json';
        file_put_contents( $path, json_encode( $config ) );

        $data = $this->build_submission('checkbox', overrides: ['opts' => ['a']]);
        $result = $this->processor->process_form_submission('checkbox', $data);
        $this->assertSame('inline', $result['success']['mode']);

        $data = $this->build_submission('checkbox', overrides: ['opts' => ['c']]);
        $result = $this->processor->process_form_submission('checkbox', $data);
        $this->assertFalse($result['success']);
        $this->assertSame(['opts' => 'Invalid selection.'], $result['errors']);

        unlink( $path );
    }

    public function test_js_check_soft_constant() {
        if ( ! defined( 'EFORM_JS_CHECK' ) ) {
            define( 'EFORM_JS_CHECK', 'soft' );
        }
        $data = $this->build_submission();
        unset( $data['js_ok'] );
        $result = $this->processor->process_form_submission( 'default', $data );
        $this->assertSame( 'inline', $result['success']['mode'] );
    }
}
