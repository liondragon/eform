<?php
use PHPUnit\Framework\TestCase;

class EnhancedInternalContactFormTest extends TestCase {
    public function test_maybe_handle_form_forwards_raw_data_and_redirects_on_success() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/contact';
        $time = time() - 10;
        $_POST = [
            '_wpnonce' => 'valid',
            'timestamp' => $time,
            'form_id' => 'default',
            'instance_id' => 'i_test',
            'js_ok' => '1',
            'eforms_hp' => '',
            'default' => [
                'name' => ' <b>Jane</b> ',
            ],
        ];

        $processor = $this->getMockBuilder(Enhanced_ICF_Form_Processor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['process_form_submission'])
            ->getMock();
        $expected = $_POST;
        $processor->expects($this->once())
            ->method('process_form_submission')
            ->with('default', $expected)
            ->willReturn(['success' => ['mode' => 'inline']]);

        $form = new Enhanced_Internal_Contact_Form($processor, new Logging());
        $ref = new ReflectionClass($form);
        $prop = $ref->getProperty('redirect_url');
        $prop->setAccessible(true);
        $prop->setValue($form, '');

        $form->maybe_handle_form( $processor );

        $this->assertSame('/contact?eforms_success=default', $GLOBALS['redirected_to']);
    }

    public function test_maybe_handle_form_handles_error_response() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $time = time() - 10;
        $_POST = [
            '_wpnonce' => 'valid',
            'timestamp' => $time,
            'form_id' => 'default',
            'instance_id' => 'i_test',
            'js_ok' => '1',
            'eforms_hp' => '',
            'default' => [
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

        $form = new Enhanced_Internal_Contact_Form($processor, new Logging());
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
        $time = time() - 10;
        $_POST = [
            '_wpnonce' => 'valid',
            'eforms_hp' => '',
            'timestamp' => $time,
            'js_ok' => '1',
            'form_id' => 'default',
            'instance_id' => 'i_test',
            'default' => [
                'name'    => [ ' <b>Jane</b> ', 'Doe' ],
                'email'   => [ 'jane@example.com', 'evil@example.com' ],
                'tel'     => [ '123-456-7890', '000' ],
                'zip'     => [ '12345', '' ],
                'message' => [ 'short' ],
            ],
        ];

        $processor = new Enhanced_ICF_Form_Processor(new Logging());
        $form      = new Enhanced_Internal_Contact_Form($processor, new Logging());
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
            '<div class="form-message error">Invalid array input for field(s): name, email, tel, zip, message.</div>',
            $error->getValue($form)
        );
    }

    public function test_render_form_renders_json_template() {
        $template = 'jsononly';
        $config   = [
            'id'      => $template,
            'version' => 1,
            'title'   => 'JSON Only',
            'email'   => [],
            'success' => [ 'mode' => 'inline', 'redirect_url' => '' ],
            'fields'  => [
                [
                    'key'         => 'name',
                    'type'        => 'text',
                    'placeholder' => 'JSON Name',
                ],
            ],
        ];
        $path = dirname(__DIR__) . "/templates/{$template}.json";
        file_put_contents( $path, json_encode( $config ) );

        $processor = new Enhanced_ICF_Form_Processor( new Logging() );
        $form      = new Enhanced_Internal_Contact_Form( $processor, new Logging() );

        $ref    = new ReflectionClass( $form );
        $method = $ref->getMethod( 'render_form' );
        $method->setAccessible( true );
        $html = $method->invoke( $form, $template );

        $this->assertMatchesRegularExpression( '/name="[^"]*\[name\]"/', $html );
        $this->assertStringContainsString( 'placeholder="JSON Name"', $html );

        unlink( $path );
    }

    public function test_calling_template_method_is_not_supported() {
        $form = new Enhanced_Internal_Contact_Form(
            new Enhanced_ICF_Form_Processor( new Logging() ),
            new Logging()
        );

        $this->expectException( \Error::class );
        $form->default();
    }
}
