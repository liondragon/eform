<?php
use PHPUnit\Framework\TestCase;

class EnhancedInternalContactFormTest extends TestCase {
    public function test_maybe_handle_form_forwards_sanitized_data_and_sets_flag_on_success() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'enhanced_template' => 'default',
            'enhanced_form_submit_default' => 'send',
            'name_input' => ' <b>Jane</b> ',
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
                'name_input' => 'Jane',
            ])
            ->willReturn(['success' => true]);

        $form = new Enhanced_Internal_Contact_Form($processor, new Logger());
        $ref = new ReflectionClass($form);
        $prop = $ref->getProperty('redirect_url');
        $prop->setAccessible(true);
        $prop->setValue($form, '');

        $form->maybe_handle_form();

        $submitted = $ref->getProperty('form_submitted');
        $submitted->setAccessible(true);
        $this->assertTrue($submitted->getValue($form));
    }

    public function test_maybe_handle_form_handles_error_response() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'enhanced_template' => 'default',
            'enhanced_form_submit_default' => 'send',
            'name_input' => ' <b>Jane</b> ',
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
                'form_data' => ['name' => 'Jane']
            ]);

        $form = new Enhanced_Internal_Contact_Form($processor, new Logger());
        $ref = new ReflectionClass($form);
        $prop = $ref->getProperty('redirect_url');
        $prop->setAccessible(true);
        $prop->setValue($form, '');

        $form->maybe_handle_form();

        $submitted = $ref->getProperty('form_submitted');
        $submitted->setAccessible(true);
        $this->assertFalse($submitted->getValue($form));

        $error = $ref->getProperty('error_message');
        $error->setAccessible(true);
        $this->assertSame('<div class="form-message error">Error happened</div>', $error->getValue($form));

        $formData = $ref->getProperty('form_data');
        $formData->setAccessible(true);
        $this->assertSame(['name' => 'Jane'], $formData->getValue($form));
    }
}
