<?php
declare(strict_types=1);

use EForms\Config;
use const EForms\TEMPLATES_DIR;

final class TemplateUnderscoreTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::bootstrap();
    }

    public function testUnderscoreTemplatesIgnored(): void
    {
        $path = TEMPLATES_DIR . '/foo_bar.json';
        $tpl = [
            'id' => 'foo_bar',
            'version' => '1',
            'title' => 'T',
            'success' => ['mode' => 'inline'],
            'email' => [],
            'fields' => [
                ['type' => 'name', 'key' => 'name'],
            ],
            'submit_button_text' => 'Send',
        ];
        file_put_contents($path, json_encode($tpl));
        try {
            $fm = new \EForms\Rendering\FormManager();
            $html = $fm->render('foo_bar');
            $this->assertSame('<div class="eforms-error">Form configuration error.</div>', $html);
        } finally {
            @unlink($path);
        }
    }
}
