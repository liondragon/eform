<?php
declare(strict_types=1);

use EForms\Rendering\Renderer;
use EForms\Validation\TemplateValidator;

final class RendererStepTest extends BaseTestCase
{
    public function testStepAttributeMirrored(): void
    {
        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => ['to' => 'a@example.com', 'subject' => 's', 'email_template' => 'default', 'include_fields' => []],
            'fields' => [
                ['type' => 'name', 'key' => 'age', 'label' => 'Age', 'step' => 5],
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
        $this->assertStringContainsString('step="5"', $html);
    }
}
