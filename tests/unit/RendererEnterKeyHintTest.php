<?php
declare(strict_types=1);

use EForms\Spec;
use EForms\Rendering\Renderer;

final class RendererEnterKeyHintTest extends BaseTestCase
{
    public function testEnterKeyHintAppliedToLastTextTypes(): void
    {
        $ctxBase = [
            'id' => 'fid',
            'nameAttr' => 'form[foo]',
            'labelHtml' => 'Label',
            'labelAttr' => '',
            'errAttr' => '',
            'value' => '',
            'isMulti' => false,
            'key' => 'foo',
            'formId' => 'form',
            'instanceId' => 'i',
            'lastText' => 'foo',
            'fieldErrors' => [],
            'errId' => 'err',
        ];

        $types = ['text','first_name','last_name','email','tel','tel_us','url','textarea','textarea_html'];

        foreach ($types as $type) {
            $desc = Spec::descriptorFor($type);
            $method = $desc['html']['tag'] === 'textarea'
                ? new \ReflectionMethod(Renderer::class, 'renderTextarea')
                : new \ReflectionMethod(Renderer::class, 'renderInput');
            $method->setAccessible(true);
            $html = $method->invoke(null, array_merge($ctxBase, ['desc' => $desc, 'f' => ['type' => $type, 'key' => 'foo']]));
            $this->assertStringContainsString('enterkeyhint="send"', $html, $type);
        }
    }
}
