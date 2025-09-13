<?php
declare(strict_types=1);

use EForms\Rendering\Renderer;
use EForms\Validation\TemplateValidator;

final class RendererLabelTest extends BaseTestCase
{
    public function testRequiredAndHiddenLabels(): void
    {
        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => ['to' => 'a@example.com', 'subject' => 's', 'email_template' => 'default', 'include_fields' => []],
            'fields' => [
                ['type' => 'name', 'key' => 'name', 'label' => 'Your Name', 'required' => true],
                ['type' => 'email', 'key' => 'email', 'label' => null, 'required' => true],
                ['type' => 'radio', 'key' => 'color', 'label' => 'Color', 'required' => true, 'options' => [
                    ['key' => 'r', 'label' => 'Red'],
                ]],
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
        $this->assertStringContainsString('<form class="eforms-form eforms-form-t1" method="post"', $html);
        $this->assertStringContainsString('<label for="f1-name-i1">Your Name<span class="required">*</span></label>', $html);
        $this->assertStringContainsString('<label for="f1-email-i1" class="visually-hidden">Email<span class="required">*</span></label>', $html);
        $this->assertStringContainsString('<legend id="f1-color-i1-legend">Color<span class="required">*</span></legend>', $html);
    }
}
