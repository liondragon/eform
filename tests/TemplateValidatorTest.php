<?php
use PHPUnit\Framework\TestCase;
use EForms\TemplateValidator;

class TemplateValidatorTest extends TestCase
{
    private function baseTpl(): array
    {
        return [
            'id' => 'f1',
            'version' => '1',
            'title' => 'T',
            'success' => ['mode' => 'inline'],
            'email' => [],
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

    public function testUnbalancedRowGroups(): void
    {
        $tpl = $this->baseTpl();
        array_unshift($tpl['fields'], ['type' => 'row_group', 'mode' => 'start', 'tag' => 'div']);
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains(TemplateValidator::EFORMS_ERR_ROW_GROUP_UNBALANCED, $codes);
    }

    public function testRowGroupNotCountedForInputEstimate(): void
    {
        $tpl = $this->baseTpl();
        array_unshift($tpl['fields'], ['type' => 'row_group', 'mode' => 'start', 'tag' => 'div']);
        $tpl['fields'][] = ['type' => 'row_group', 'mode' => 'end', 'tag' => 'div'];
        $res = TemplateValidator::preflight($tpl);
        $this->assertTrue($res['ok']);
        $this->assertSame(6, $res['context']['max_input_vars_estimate']);
        $this->assertCount(4, $res['context']['fields']);
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
        $tpl['fields'][0]['size'] = 'big';
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_TYPE, $codes);
        $this->assertContains('fields[0].size', $paths);

        $tpl = $this->baseTpl();
        $tpl['fields'][0]['size'] = 0;
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $paths = array_column($res['errors'], 'path');
        $this->assertContains(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, $codes);
        $this->assertContains('fields[0].size', $paths);
        $this->assertNull($res['context']['fields'][0]['size']);
    }

    public function testFileLimitsAndStepAllowed(): void
    {
        $tpl = $this->baseTpl();
        $tpl['fields'][0]['step'] = 5;
        $tpl['fields'][] = ['type' => 'file', 'key' => 'up1', 'max_file_bytes' => 100];
        $tpl['fields'][] = ['type' => 'files', 'key' => 'up2', 'max_files' => 2];
        $res = TemplateValidator::preflight($tpl);
        $codes = array_column($res['errors'], 'code');
        $this->assertNotContains(TemplateValidator::EFORMS_ERR_SCHEMA_UNKNOWN_KEY, $codes);
        $this->assertTrue($res['ok']);
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
}
