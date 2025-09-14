<?php
declare(strict_types=1);

use EForms\Config;
use EForms\Submission\SubmitHandler;
use const EForms\TEMPLATES_DIR;

final class SubmitHandlerTemplateLoadTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::bootstrap();
    }

    public function testLoadTemplateByIdReturnsTemplate(): void
    {
        $sh = new SubmitHandler();
        $ref = new \ReflectionMethod($sh, 'loadTemplateById');
        $ref->setAccessible(true);
        $res = $ref->invoke($sh, 'contact_us');
        $this->assertIsArray($res);
        $this->assertSame('contact_us', $res['tpl']['id'] ?? '');
    }

    public function testLoadTemplateByIdIgnoresUnderscore(): void
    {
        $path = TEMPLATES_DIR . '/foo_bar.json';
        $tpl = [
            'id' => 'foo_bar',
            'version' => '1',
            'title' => 'T',
            'success' => ['mode' => 'inline'],
            'email' => ['to' => 'a@example.com', 'subject' => 's'],
            'fields' => [[ 'type' => 'name', 'key' => 'name' ]],
            'submit_button_text' => 'Send',
        ];
        file_put_contents($path, json_encode($tpl));
        try {
            $sh = new SubmitHandler();
            $ref = new \ReflectionMethod($sh, 'loadTemplateById');
            $ref->setAccessible(true);
            $res = $ref->invoke($sh, 'foo_bar');
            $this->assertNull($res);
        } finally {
            @unlink($path);
        }
    }
}
