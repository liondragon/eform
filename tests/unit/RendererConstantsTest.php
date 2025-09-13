<?php
declare(strict_types=1);

use EForms\Rendering\Renderer;
use EForms\Validation\TemplateValidator;

final class RendererConstantsTest extends BaseTestCase
{
    public function testEmailConstantsRendered(): void
    {
        $tpl = [
            'id' => 'f1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => [],
            'fields' => [
                ['type' => 'email', 'key' => 'email'],
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
        $tpl = TemplateValidator::preflight($tpl)['context'];
        $html = Renderer::form($tpl, $meta, [], []);
        $this->assertStringContainsString('spellcheck="false"', $html);
        $this->assertStringContainsString('autocapitalize="off"', $html);
    }
}
