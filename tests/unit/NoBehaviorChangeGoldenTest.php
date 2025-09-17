<?php
declare(strict_types=1);

use EForms\Validation\TemplateValidator;
use EForms\Rendering\Renderer;
use EForms\Validation\Validator;

final class NoBehaviorChangeGoldenTest extends BaseTestCase
{
    public function testContactTemplateRenderingAndValidation(): void
    {
        $tpl = json_decode(file_get_contents(__DIR__ . '/../../templates/forms/contact.json'), true);
        $pre = TemplateValidator::preflight($tpl, __DIR__ . '/../../templates/forms/contact.json');
        $this->assertTrue($pre['ok'], 'preflight failed');
        $context = $pre['context'];

        $meta = [
            'form_id' => 'contact_us',
            'instance_id' => 'INSTANCE',
            'timestamp' => 1234567890,
            'cacheable' => false,
            'client_validation' => false,
            'action' => 'http://example.com/submit',
            'hidden_token' => 'TOKEN',
            'enctype' => 'application/x-www-form-urlencoded',
            'mode' => 'hidden',
        ];
        $html = Renderer::form($context, $meta, [], []);
        $html = preg_replace('/hp_[A-Za-z0-9_-]+/', 'hp_ID', $html);
        $expectedHtml = file_get_contents(__DIR__ . '/../fixtures/contact.html');
        $this->assertSame(rtrim($expectedHtml), rtrim($html));

        $post = ['name' => ' Alice ', 'message' => 'Hello', 'email' => 'BOB@Example.COM'];
        $fields = array_map(function ($f) { return ($f['type'] === 'name') ? ['type'=>'text'] + $f : $f; }, $context['fields']);
        $desc = Validator::descriptors(['fields' => $fields]);
        $values = Validator::normalize(['fields'=>$fields], $post, $desc);
        $res = Validator::validate(['rules'=>$context['rules'] ?? []], $desc, $values);
        $this->assertSame([], $res['errors']);
        $this->assertSame([
            'name' => 'Alice',
            'message' => 'Hello',
            'email' => 'BOB@example.com',
        ], $res['values']);
    }
}
