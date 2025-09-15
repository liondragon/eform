<?php
use EForms\Validation\TemplateValidator;

class TemplateValidatorTest extends BaseTestCase
{
    private function baseTpl(): array
    {
        return [
            'id' => 'f1',
            'version' => '1',
            'title' => 'T',
            'success' => ['mode' => 'inline'],
            'email' => ['to' => 'a@example.com', 'subject' => 's'],
            'fields' => [
                ['type' => 'name', 'key' => 'name'],
                ['type' => 'email', 'key' => 'email'],
            ],
            'submit_button_text' => 'Send',
        ];
    }

    public function testGoodTemplatePasses(): void
    {
        $tpl = $this->baseTpl();
        $res = TemplateValidator::preflight($tpl);
        $this->assertTrue($res['ok']);
    }

    public function testDescriptorIncludesConstants(): void
    {
        $tpl = $this->baseTpl();
        $res = TemplateValidator::preflight($tpl);
        $this->assertTrue($res['ok']);
        $desc = $res['context']['descriptors']['email'] ?? [];
        $this->assertSame([
            'spellcheck' => 'false',
            'autocapitalize' => 'off',
        ], $desc['constants']);
    }

    public function testTitleIsRequired(): void
    {
        $tpl = $this->baseTpl();
        unset($tpl['title']);
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_REQUIRED, $codes);
    }

    public function testUnknownRootKey(): void
    {
        $tpl = $this->baseTpl();
        $tpl['foo'] = 1;
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_UNKNOWN_KEY, $codes);
    }

    public function testUnsupportedFieldType(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['type'] = 'bogus';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_TYPE, $codes);
    }

    public function testDuplicateFieldKey(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][] = ['type' => 'email', 'key' => 'email'];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_DUP_KEY, $codes);
    }

    public function testBadEnum(): void
    {
        $tpl = $this->baseTpl();
        $tpl['success']['mode'] = 'bogus';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
    }

    public function testDisplayFormatTelBadEnum(): void
    {
        $tpl = $this->baseTpl();
        $tpl['email']['display_format_tel'] = 'bogus';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
    }

    public function testDisplayFormatTelEnumAllowed(): void
    {
        $formats = ['xxx-xxx-xxxx', '(xxx) xxx-xxxx', 'xxx.xxx.xxxx'];
        foreach ($formats as $fmt) {
            $tpl = $this->baseTpl();
            $tpl['email']['display_format_tel'] = $fmt;
            $res = TemplateValidator::preflight($tpl);
            $codes = array_column($res['errors'], 'code');
            $this->assertNotContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
            $this->assertTrue($res['ok']);
        }
    }

    public function testUnknownEmailTemplate(): void
    {
        $tpl = $this->baseTpl();
        $tpl['email']['email_template'] = 'bogus';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('email.email_template', $paths);
    }

    public function testEmptyAcceptIntersection(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][] = ['type' => 'file', 'key' => 'up', 'accept' => ['foo']];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_ACCEPT_EMPTY, $codes);
    }

    public function testAcceptRequiredForUploads(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][] = ['type' => 'file', 'key' => 'up'];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_ACCEPT_EMPTY, $codes);
    }

    public function testUnbalancedRowGroups(): void
    {
        $tpl = $this->baseTpl();
        array_unshift($tpl['fields'], ['type' => 'row_group', 'mode' => 'start', 'tag' => 'div']);
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_ROW_GROUP_UNBALANCED, $codes);
    }

    public function testStrayEndIgnored(): void
    {
        $tpl = $this->baseTpl();
        array_unshift($tpl['fields'], ['type' => 'row_group', 'mode' => 'end', 'tag' => 'div']);
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertNotContains(TemplateValidator::EFORMS_ERR_ROW_GROUP_UNBALANCED, $codes);
    }

    public function testRowGroupNotCountedForInputEstimate(): void
    {
        $tpl = $this->baseTpl();
        array_unshift($tpl['fields'], ['type' => 'row_group', 'mode' => 'start', 'tag' => 'div']);
        $tpl['fields'][] = ['type' => 'row_group', 'mode' => 'end', 'tag' => 'div'];
        $res = TemplateValidator::preflight($tpl);
        $this->assertTrue($res['ok']);
        $this->assertSame(7, $res['context']['max_input_vars_estimate']);
        $this->assertCount(4, $res['context']['fields']);
    }

    public function testFragmentsMustBeBalanced(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['before_html'] = '<div>';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_FRAGMENT_UNBALANCED, $codes);
        $this->assertContains('fields[0].before_html', $paths);

        $tpl = $this->baseTpl();
        $tpl['fields'][0]['after_html'] = '</div>';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_FRAGMENT_UNBALANCED, $codes);
        $this->assertContains('fields[0].after_html', $paths);
    }

    public function testFragmentsSanitizedAndPreserved(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['before_html'] = '<p><strong>ok</strong><script>alert(1)</script></p>';
        $tpl['fields'][0]['after_html'] = '<span><em>end</em><script></script></span>';
        $res = TemplateValidator::preflight($tpl);
        $this->assertTrue($res['ok']);
        $field = $res['context']['fields'][0];
        $this->assertSame('<p><strong>ok</strong>alert(1)</p>', $field['before_html']);
        $this->assertSame('<span><em>end</em></span>', $field['after_html']);
    }

    public function testFragmentsCannotContainRowTags(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['before_html'] = '<div></div>';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_FRAGMENT_ROW_TAG, $codes);

        $tpl = $this->baseTpl();
        $tpl['fields'][0]['after_html'] = '<section></section>';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_FRAGMENT_ROW_TAG, $codes);
    }

    public function testFragmentsCannotContainStyleAttributes(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['before_html'] = '<span style="color:red"></span>';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_FRAGMENT_STYLE_ATTR, $codes);
        $this->assertContains('fields[0].before_html', $paths);

        $tpl = $this->baseTpl();
        $tpl['fields'][0]['after_html'] = '<span style="color:blue"></span>';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_FRAGMENT_STYLE_ATTR, $codes);
        $this->assertContains('fields[0].after_html', $paths);
    }

    public function testUnknownValidationRule(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'bogus_rule', 'field' => 'name'],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('rules[0].rule', $paths);
    }

    public function testRuleMissingRequiredKey(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'matches', 'target' => 'name'],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_REQUIRED, $codes);
        $this->assertContains('rules[0].field', $paths);
    }

    public function testRuleUnknownKey(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'matches', 'target' => 'name', 'field' => 'email', 'bogus' => 1],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_UNKNOWN_KEY, $codes);
        $this->assertContains('rules[0].bogus', $paths);
    }

    public function testRequiredIfRuleWithUnknownTargetIsOmitted(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'required_if', 'target' => 'missing', 'field' => 'name', 'equals' => 'yes'],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('rules[0].target', $paths);
        $this->assertEmpty($res['context']['rules']);
    }

    public function testRequiredUnlessRuleWithUnknownFieldIsOmitted(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'required_unless', 'target' => 'name', 'field' => 'missing', 'equals' => 'no'],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('rules[0].field', $paths);
        $this->assertEmpty($res['context']['rules']);
    }

    public function testMatchesRuleWithUnknownFieldIsOmitted(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'matches', 'target' => 'name', 'field' => 'missing'],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('rules[0].field', $paths);
        $this->assertEmpty($res['context']['rules']);
    }

    public function testRequiredIfAnyRuleWithUnknownTargetIsOmitted(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'required_if_any', 'target' => 'missing', 'fields' => ['name'], 'equals_any' => ['yes']],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('rules[0].target', $paths);
        $this->assertEmpty($res['context']['rules']);
    }

    public function testRequiredIfAnyRuleWithInvalidFieldEntryIsOmitted(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'required_if_any', 'target' => 'name', 'fields' => ['email', 'missing'], 'equals_any' => ['yes']],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('rules[0].fields[1]', $paths);
        $this->assertEmpty($res['context']['rules']);
    }

    public function testOneOfRuleWithInvalidFieldEntryIsOmitted(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'one_of', 'fields' => ['name', 'missing']],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('rules[0].fields[1]', $paths);
        $this->assertEmpty($res['context']['rules']);
    }

    public function testMutuallyExclusiveRuleWithInvalidFieldEntryIsOmitted(): void
    {
        $tpl = $this->baseTpl();
        $tpl['rules'] = [
            ['rule' => 'mutually_exclusive', 'fields' => ['email', 'missing']],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('rules[0].fields[1]', $paths);
        $this->assertEmpty($res['context']['rules']);
    }

    public function testMaxLengthValidation(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['max_length'] = '10';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);

        $tpl = $this->baseTpl();
        $tpl['fields'][0]['max_length'] = 0;
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
    }

    public function testMinMaxPatternValidation(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['min'] = 'a';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);

        $tpl = $this->baseTpl();
        $tpl['fields'][0]['min'] = 5;
        $tpl['fields'][0]['max'] = 3;
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);

        $tpl = $this->baseTpl();
        $tpl['fields'][0]['pattern'] = 123;
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);

        $tpl = $this->baseTpl();
        $tpl['fields'][0]['pattern'] = '[';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
    }

    public function testAutocompleteInvalid(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['autocomplete'] = 'not-a-token';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('fields[0].autocomplete', $paths);
        $this->assertNull($res['context']['fields'][0]['autocomplete']);
    }

    public function testSizeValidation(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][1]['size'] = 'big';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);
        $this->assertContains('fields[1].size', $paths);

        $tpl = $this->baseTpl();
        $tpl['fields'][1]['size'] = 0;
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('fields[1].size', $paths);
        $this->assertNull($res['context']['fields'][1]['size']);

        $tpl = $this->baseTpl();
        $tpl['fields'][1]['size'] = 20;
        $res = TemplateValidator::preflight($tpl);
        $paths = array_column($res['errors'], 'path');
        $this->assertNotContains('fields[1].size', $paths);
        $this->assertSame(20, $res['context']['fields'][1]['size']);
    }

    public function testSizeDisallowedOnNonTextFields(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][] = [
            'type' => 'number',
            'key' => 'qty',
            'size' => 10,
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('fields[2].size', $paths);
        $this->assertNull($res['context']['fields'][2]['size']);
    }

    public function testFileLimitsAndStepAllowed(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['step'] = 5;
        $tpl['fields'][] = ['type' => 'file', 'key' => 'up1', 'max_file_bytes' => 100, 'accept' => ['pdf']];
        $tpl['fields'][] = ['type' => 'files', 'key' => 'up2', 'max_files' => 2, 'accept' => ['pdf']];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertNotContains(TemplateValidator::EFORMS_ERR_SCHEMA_UNKNOWN_KEY, $codes);
        $this->assertTrue($res['ok']);
    }

    public function testEmailToAndSubjectRequired(): void
    {
        $tpl = $this->baseTpl();
        unset($tpl['email']['to']);
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_REQUIRED, $codes);
        $this->assertContains('email.to', $paths);

        $tpl = $this->baseTpl();
        unset($tpl['email']['subject']);
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_REQUIRED, $codes);
        $this->assertContains('email.subject', $paths);
    }

    public function testEmailToAndSubjectMustBeStrings(): void
    {
        $tpl = $this->baseTpl();
        $tpl['email']['to'] = ['x'];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);
        $this->assertContains('email.to', $paths);

        $tpl = $this->baseTpl();
        $tpl['email']['subject'] = '';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_REQUIRED, $codes);
        $this->assertContains('email.subject', $paths);
    }

    public function testEmailAttachOnlyForFileFields(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['email_attach'] = true;
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);
        $this->assertContains('fields[0].email_attach', $paths);
    }

    public function testIncludeFieldsValidation(): void
    {
        $tpl = $this->baseTpl();
        $tpl['email']['include_fields'] = ['name','email','ip','bogus',123];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('email.include_fields[3]', $paths);
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);
        $this->assertContains('email.include_fields[4]', $paths);
        $this->assertSame(['name','email','ip'], $res['context']['email']['include_fields']);

        $tpl = $this->baseTpl();
        $tpl['email']['include_fields'] = 'name';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);
        $this->assertContains('email.include_fields', $paths);
    }

    public function testMaxFieldsPerForm(): void
    {
        $tpl = $this->baseTpl();
        for ($i = 0; $i < 149; $i++) {
            $tpl['fields'][] = ['type' => 'text', 'key' => 'f'.$i];
        }
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('fields', $paths);
    }

    public function testMaxOptionsPerGroup(): void
    {
        $tpl = $this->baseTpl();
        $opts = [];
        for ($i = 0; $i < 101; $i++) {
            $opts[] = ['key' => 'o'.$i, 'label' => 'L'.$i];
        }
        $tpl['fields'][] = ['type' => 'select', 'key' => 'sel', 'options' => $opts];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('fields[2].options', $paths);
    }

    public function testOptionLabelAndDisabledTypes(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][] = [
            'type' => 'select',
            'key' => 'sel',
            'options' => [
                ['key' => 'a'],
            ],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_REQUIRED, $codes);
        $this->assertContains('fields[2].options[0].label', $paths);

        $tpl = $this->baseTpl();
        $tpl['fields'][] = [
            'type' => 'select',
            'key' => 'sel',
            'options' => [
                ['key' => 'a', 'label' => 'A', 'disabled' => 'no'],
            ],
        ];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);
        $this->assertContains('fields[2].options[0].disabled', $paths);

        $tpl = $this->baseTpl();
        $tpl['fields'][] = [
            'type' => 'select',
            'key' => 'sel',
            'options' => [
                ['key' => 'a', 'label' => 'A', 'disabled' => false],
            ],
        ];
        $res = TemplateValidator::preflight($tpl);
        $this->assertTrue($res['ok']);
    }

    public function testEmptyVersionReplacedWithMtime(): void
    {
        $tpl = $this->baseTpl();
        $tpl['version'] = '';
        $tmp = tempnam(sys_get_temp_dir(), 'tpl');
        touch($tmp, 123);
        $res = TemplateValidator::preflight($tpl, $tmp);
        $this->assertTrue($res['ok']);
        $this->assertSame('123', $res['context']['version']);
        unlink($tmp);
    }

    public function testClassTokenTruncateAndDedup(): void
    {
        $tpl = $this->baseTpl();
        $longA1 = str_repeat('a', 40);
        $longA2 = str_repeat('a', 35);
        $longB = str_repeat('b', 33);
        $tpl['fields'][0]['class'] = $longA1 . ' ' . $longA2 . ' ' . $longB;
        $res = TemplateValidator::preflight($tpl);
        $this->assertTrue($res['ok']);
        $field = $res['context']['fields'][0] ?? [];
        $expected = str_repeat('a', 32) . ' ' . str_repeat('b', 32);
        $this->assertSame($expected, $field['class']);
    }

    public function testClassTokenTruncateNotDropped(): void
    {
        $tpl = $this->baseTpl();
        $long = str_repeat('x', 40);
        $tpl['fields'][0]['class'] = $long;
        $res = TemplateValidator::preflight($tpl);
        $this->assertTrue($res['ok']);
        $field = $res['context']['fields'][0] ?? [];
        $this->assertSame(str_repeat('x', 32), $field['class']);
    }

    public function testClassTokenDedupAndMaxLength(): void
    {
        $tpl = $this->baseTpl();
        $longA1 = str_repeat('a', 40);
        $longA2 = str_repeat('a', 35);
        $tokens = [$longA1, $longA2];
        foreach (['b', 'c', 'd', 'e'] as $ch) {
            $tokens[] = str_repeat($ch, 33);
        }
        $tpl['fields'][0]['class'] = implode(' ', $tokens);
        $res = TemplateValidator::preflight($tpl);
        $this->assertTrue($res['ok']);
        $field = $res['context']['fields'][0] ?? [];
        $expected = str_repeat('a', 32) . ' ' . str_repeat('b', 32) . ' ' .
            str_repeat('c', 32) . ' ' . str_repeat('d', 29);
        $this->assertSame($expected, $field['class']);
        $this->assertSame(128, strlen($field['class']));
    }
}
