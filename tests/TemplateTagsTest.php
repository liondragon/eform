<?php
use PHPUnit\Framework\TestCase;

class TemplateTagsTest extends TestCase {
    public function test_eform_field_outputs_value_and_error() {
        global $eform_registry, $eform_current_template, $eform_form;

        $eform_registry = new FieldRegistry();

        $processor = $this->getMockBuilder(Enhanced_ICF_Form_Processor::class)
            ->disableOriginalConstructor()
            ->getMock();

        $eform_form = new Enhanced_Internal_Contact_Form($processor, new Logger());

        $ref = new ReflectionClass($eform_form);
        $formDataProp = $ref->getProperty('form_data');
        $formDataProp->setAccessible(true);
        $formDataProp->setValue($eform_form, ['name' => 'Jane']);

        $fieldErrorsProp = $ref->getProperty('field_errors');
        $fieldErrorsProp->setAccessible(true);
        $fieldErrorsProp->setValue($eform_form, ['name' => 'Required']);

        $eform_current_template = 'default';

        ob_start();
        eform_field('name', [ 'required' => true ]);
        $html = ob_get_clean();

        $this->assertStringContainsString('value="Jane"', $html);
        $this->assertStringContainsString('Required', $html);

        unset($GLOBALS['eform_registry'], $GLOBALS['eform_current_template'], $GLOBALS['eform_form']);
    }
}
