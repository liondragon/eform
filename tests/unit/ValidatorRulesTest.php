<?php
declare(strict_types=1);

use EForms\Config;
use EForms\Logging;
use EForms\Validation\TemplateValidator;
use EForms\Validation\Validator;

final class ValidatorRulesTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionClass(Config::class);
        $boot = $ref->getProperty('bootstrapped');
        $boot->setAccessible(true);
        $boot->setValue(false);
        $data = $ref->getProperty('data');
        $data->setAccessible(true);
        $data->setValue([]);
        $lref = new \ReflectionClass(Logging::class);
        $lin = $lref->getProperty('init');
        $lin->setAccessible(true);
        $lin->setValue(false);
        $lfile = $lref->getProperty('file');
        $lfile->setAccessible(true);
        $lfile->setValue('');
        $ldir = $lref->getProperty('dir');
        $ldir->setAccessible(true);
        $ldir->setValue('');
        putenv('EFORMS_LOG_LEVEL=1');
        putenv('EFORMS_LOG_MODE=jsonl');
        Config::bootstrap();
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
