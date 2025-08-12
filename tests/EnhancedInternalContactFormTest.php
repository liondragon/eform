<?php
use PHPUnit\Framework\TestCase;

class EnhancedInternalContactFormTest extends TestCase {
    public function test_maybe_handle_form_forwards_raw_data_and_sets_flag_on_success() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $form_id = 'form123';
        $_POST = [
            'enhanced_template' => 'default',
            'enhanced_form_submit_default' => 'send',
            'enhanced_form_id' => $form_id,
            $form_id => [
                'name' => ' <b>Jane</b> ',
            ],
        ];

        $processor = $this->getMockBuilder(Enhanced_ICF_Form_Processor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['process_form_submission'])
            ->getMock();
        $processor->expects($this->once())
            ->method('process_form_submission')
            ->with('default', [
                'enhanced_template' => 'default',
                'enhanced_form_submit_default' => 'send',
                'enhanced_form_id' => $form_id,
                $form_id => [
                    'name' => ' <b>Jane</b> ',
                ],
            ])
            ->willReturn(['success' => true]);

        $form = new Enhanced_Internal_Contact_Form($processor, new Logger());
        $ref = new ReflectionClass($form);
        $prop = $ref->getProperty('redirect_url');
        $prop->setAccessible(true);
        $prop->setValue($form, '');

        $form->maybe_handle_form( $processor );

        $submitted = $ref->getProperty('form_submitted');
        $submitted->setAccessible(true);
        $this->assertTrue($submitted->getValue($form));
    }

    public function test_maybe_handle_form_handles_error_response() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $form_id = 'form123';
        $_POST = [
            'enhanced_template' => 'default',
            'enhanced_form_submit_default' => 'send',
            'enhanced_form_id' => $form_id,
            $form_id => [
                'name' => ' <b>Jane</b> ',
            ],
        ];

        $processor = $this->getMockBuilder(Enhanced_ICF_Form_Processor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['process_form_submission'])
            ->getMock();
        $processor->expects($this->once())
            ->method('process_form_submission')
            ->willReturn([
                'success' => false,
                'message' => 'Error happened',
                'form_data' => ['name' => 'Jane'],
                'errors'    => ['name' => 'Required'],
            ]);

        $form = new Enhanced_Internal_Contact_Form($processor, new Logger());
        $ref = new ReflectionClass($form);
        $prop = $ref->getProperty('redirect_url');
        $prop->setAccessible(true);
        $prop->setValue($form, '');

        $form->maybe_handle_form( $processor );

        $submitted = $ref->getProperty('form_submitted');
        $submitted->setAccessible(true);
        $this->assertFalse($submitted->getValue($form));

        $error = $ref->getProperty('error_message');
        $error->setAccessible(true);
        $this->assertSame('', $error->getValue($form));

        $this->assertSame(['name' => 'Jane'], $form->form_data);
        $this->assertSame(['name' => 'Required'], $form->field_errors);
    }

    public function test_maybe_handle_form_rejects_array_fields() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $form_id = 'form123';
        $_POST = [
            'enhanced_template' => 'default',
            'enhanced_form_submit_default' => 'send',
            'enhanced_icf_form_nonce' => 'valid',
            'enhanced_url' => '',
            'enhanced_form_time' => time() - 10,
            'enhanced_js_check' => '1',
            'enhanced_form_id' => $form_id,
            $form_id => [
                'name'    => [ ' <b>Jane</b> ', 'Doe' ],
                'email'   => [ 'jane@example.com', 'evil@example.com' ],
                'phone'   => [ '123-456-7890', '000' ],
                'zip'     => [ '12345', '' ],
                'message' => [ 'short' ],
            ],
        ];

        $processor = new Enhanced_ICF_Form_Processor(new Logger());
        $form      = new Enhanced_Internal_Contact_Form($processor, new Logger());
        $ref = new ReflectionClass($form);
        $prop = $ref->getProperty('redirect_url');
        $prop->setAccessible(true);
        $prop->setValue($form, '');

        $form->maybe_handle_form( $processor );

        $submitted = $ref->getProperty('form_submitted');
        $submitted->setAccessible(true);
        $this->assertFalse($submitted->getValue($form));

        $this->assertSame([], $form->form_data);
        $this->assertSame([], $form->field_errors);

        $error = $ref->getProperty('error_message');
        $error->setAccessible(true);
        $this->assertSame(
            '<div class="form-message error">Invalid array input for field(s): name, email, phone, zip, message.</div>',
            $error->getValue($form)
        );
    }

    public function test_render_form_renders_json_template() {
        $template = 'jsononly';
        $config = [
            'fields' => [
                'name_input' => [
                    'type' => 'text',
                    'placeholder' => 'JSON Name'
                ],
            ],
        ];
        $path = dirname(__DIR__) . "/templates/{$template}.json";
        file_put_contents( $path, json_encode( $config ) );

        $processor = new Enhanced_ICF_Form_Processor( new Logger() );
        $form      = new Enhanced_Internal_Contact_Form( $processor, new Logger() );

        $ref    = new ReflectionClass( $form );
        $method = $ref->getMethod( 'render_form' );
        $method->setAccessible( true );
        $html = $method->invoke( $form, $template );

        $this->assertMatchesRegularExpression( '/name="[^"]*\[name\]"/', $html );
        $this->assertStringContainsString( 'placeholder="JSON Name"', $html );

        unlink( $path );
    }
}
