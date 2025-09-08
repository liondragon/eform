<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Config;
use EForms\Renderer;
use EForms\TemplateValidator;

final class RendererRowGroupTest extends TestCase
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

    public function testRowGroupAutoCloseAndLogging(): void
    {
        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => ['to' => 'a@example.com', 'subject' => 's', 'email_template' => '', 'include_fields' => []],
            'fields' => [
                ['type' => 'row_group', 'mode' => 'start', 'tag' => 'section', 'class' => 'custom'],
                ['type' => 'name', 'key' => 'name', 'label' => 'Name'],
            ],
            'submit_button_text' => 'Send',
            'rules' => [],
        ];
        $meta = [
            'form_id' => 'f1',
            'instance_id' => 'i1',
            'timestamp' => time(),
            'cacheable' => true,
            'client_validation' => false,
            'action' => '#',
            'hidden_token' => null,
            'enctype' => 'application/x-www-form-urlencoded',
        ];
        $logFile = Config::get('uploads.dir', sys_get_temp_dir()) . '/eforms.log';
        @unlink($logFile);
        $html = Renderer::form($tpl, $meta, [], []);
        $this->assertStringContainsString('<section class="eforms-row custom">', $html);
        $this->assertStringContainsString('</section><button', $html);
        $log = file_get_contents($logFile);
        $this->assertStringContainsString(TemplateValidator::EFORMS_ERR_ROW_GROUP_UNBALANCED, (string)$log);
    }

    public function testRowGroupStrayEndLogged(): void
    {
        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => ['to' => 'a@example.com', 'subject' => 's', 'email_template' => '', 'include_fields' => []],
            'fields' => [
                ['type' => 'row_group', 'mode' => 'end', 'tag' => 'section'],
                ['type' => 'name', 'key' => 'name', 'label' => 'Name'],
            ],
            'submit_button_text' => 'Send',
            'rules' => [],
        ];
        $meta = [
            'form_id' => 'f1',
            'instance_id' => 'i1',
            'timestamp' => time(),
            'cacheable' => true,
            'client_validation' => false,
            'action' => '#',
            'hidden_token' => null,
            'enctype' => 'application/x-www-form-urlencoded',
        ];
        $logFile = Config::get('uploads.dir', sys_get_temp_dir()) . '/eforms.log';
        @unlink($logFile);
        $html = Renderer::form($tpl, $meta, [], []);
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('<button', $html);
        $log = file_get_contents($logFile);
        $this->assertStringContainsString(TemplateValidator::EFORMS_ERR_ROW_GROUP_UNBALANCED, (string)$log);
    }
}
