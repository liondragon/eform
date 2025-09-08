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
}
