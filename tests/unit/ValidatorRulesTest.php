<?php
declare(strict_types=1);

use EForms\Config;
use EForms\Validation\TemplateValidator;
use EForms\Validation\Validator;

final class ValidatorRulesTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        set_config(['logging' => ['level' => 1, 'mode' => 'jsonl']]);
    }

    public function testUnknownRuleLogsEnum(): void
    {
        $tpl = [
            'id' => 'f1',
            'version' => '1',
            'title' => 'T',
            'success' => ['mode' => 'inline'],
            'email' => [],
            'fields' => [
                ['type' => 'text', 'key' => 'name'],
            ],
            'submit_button_text' => 'Send',
            'rules' => [
                ['rule' => 'bogus_rule', 'field' => 'name'],
            ],
        ];
        $desc = Validator::descriptors($tpl);
        $values = Validator::normalize($tpl, ['name' => 'a'], $desc);
        $logFile = Config::get('uploads.dir', sys_get_temp_dir()) . '/eforms.log';
        @unlink($logFile);
        $res = Validator::validate($tpl, $desc, $values);
        $log = file_get_contents($logFile);
        $this->assertStringContainsString(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, (string) $log);
        $this->assertArrayHasKey('_global', $res['errors']);
    }
}
