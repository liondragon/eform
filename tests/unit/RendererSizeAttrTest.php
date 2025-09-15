<?php
declare(strict_types=1);

use EForms\Spec;
use EForms\Rendering\Renderer;

final class RendererSizeAttrTest extends BaseTestCase
{
    public function testTextInputRendersSize(): void
    {
        $desc = Spec::descriptorFor('text');
        $field = ['type' => 'text', 'key' => 'foo', 'size' => 12];
        $ctx = [
            'desc' => $desc,
            'f' => $field,
            'id' => 'fid',
            'nameAttr' => 'form[foo]',
            'labelHtml' => 'Label',
            'labelAttr' => '',
            'errAttr' => '',
            'value' => '',
            'key' => 'foo',
            'lastText' => 'foo',
        ];
        $ref = new \ReflectionClass(Renderer::class);
        $method = $ref->getMethod('renderInput');
        $method->setAccessible(true);
        $html = $method->invoke(null, $ctx);
        $this->assertStringContainsString('size="12"', $html);
    }

    public function testTextareaIgnoresSize(): void
    {
        $desc = Spec::descriptorFor('textarea');
        $field = ['type' => 'textarea', 'key' => 'foo', 'size' => 12];
        $ctx = [
            'desc' => $desc,
            'f' => $field,
            'id' => 'tid',
            'nameAttr' => 'form[foo]',
            'labelHtml' => 'Label',
            'labelAttr' => '',
            'errAttr' => '',
            'value' => '',
            'key' => 'foo',
            'lastText' => 'foo',
        ];
        $ref = new \ReflectionClass(Renderer::class);
        $method = $ref->getMethod('renderTextarea');
        $method->setAccessible(true);
        $html = $method->invoke(null, $ctx);
        $this->assertStringNotContainsString('size=', $html);
    }

    public function testSelectIgnoresSize(): void
    {
        $desc = Spec::descriptorFor('select');
        $field = [
            'type' => 'select',
            'key' => 'foo',
            'size' => 5,
            'options' => [
                ['key' => 'a', 'label' => 'A'],
            ],
        ];
        $ctx = [
            'desc' => $desc,
            'f' => $field,
            'id' => 'sid',
            'nameAttr' => 'form[foo]',
            'labelHtml' => 'Label',
            'labelAttr' => '',
            'errAttr' => '',
            'value' => '',
            'isMulti' => false,
        ];
        $ref = new \ReflectionClass(Renderer::class);
        $method = $ref->getMethod('renderSelect');
        $method->setAccessible(true);
        $html = $method->invoke(null, $ctx);
        $this->assertStringNotContainsString('size=', $html);
    }
}
