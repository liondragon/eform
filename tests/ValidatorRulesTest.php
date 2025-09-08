<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Config;
use EForms\TemplateValidator;
use EForms\Validator;

final class ValidatorRulesTest extends TestCase
{
    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Config::class);
        $boot = $ref->getProperty('bootstrapped');
        $boot->setAccessible(true);
        $boot->setValue(false);
        $data = $ref->getProperty('data');
        $data->setAccessible(true);
        $data->setValue([]);
        putenv('EFORMS_LOG_LEVEL=1');
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
                ['type' => 'name', 'key' => 'name'],
            ],
            'submit_button_text' => 'Send',
            'rules' => [
                ['rule' => 'bogus_rule', 'field' => 'name'],
            ],
        ];
        $desc = Validator::descriptors($tpl);
        $values = Validator::normalize($tpl, ['name' => 'a']);
        $logFile = Config::get('uploads.dir', sys_get_temp_dir()) . '/eforms.log';
        @unlink($logFile);
        Validator::validate($tpl, $desc, $values);
        $log = file_get_contents($logFile);
        $this->assertStringContainsString(TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, (string) $log);
    }
}
