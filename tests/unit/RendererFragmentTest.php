<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Validation\TemplateValidator;
use EForms\Rendering\Renderer;

final class RendererFragmentTest extends TestCase
{
    public function testFragmentsAreSanitizedOnce(): void
    {
        $tpl = [
            'id' => 'f1',
            'version' => '1',
            'title' => 'T',
            'success' => ['mode' => 'inline'],
            'email' => [],
            'fields' => [
                [
                    'type' => 'name',
                    'key' => 'name',
                    'before_html' => '<p><strong>ok</strong><script>alert(1)</script></p>',
                    'after_html' => '<span><em>end</em><script></script></span>',
                ],
            ],
            'submit_button_text' => 'Send',
        ];

        $pre = TemplateValidator::preflight($tpl);
        $this->assertTrue($pre['ok']);
        $ctx = $pre['context'];

        $meta = [
            'form_id' => 'f1',
            'instance_id' => 'i1',
            'timestamp' => 0,
            'cacheable' => true,
            'client_validation' => false,
            'action' => 'http://example.com',
            'hidden_token' => 'tok',
            'enctype' => 'application/x-www-form-urlencoded',
        ];

        $html = Renderer::form($ctx, $meta, [], []);
        $this->assertStringContainsString('<p><strong>ok</strong>alert(1)</p>', $html);
        $this->assertStringContainsString('<span><em>end</em></span>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
