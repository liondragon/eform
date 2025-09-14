<?php
declare(strict_types=1);

use EForms\Spec;
use EForms\Rendering\Renderer;

final class RendererEmailAttrTest extends BaseTestCase
{
    public function testLengthAttributesMirrored(): void
    {
        $desc = Spec::descriptorFor('email');
        $this->assertSame('email', $desc['type']);

        $field = [
            'type' => 'email',
            'key' => 'foo',
            'max_length' => 20,
            'min_length' => 3,
        ];
        $ctx = [
            'id' => 'fid',
            'nameAttr' => 'form[email]',
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
            'desc' => $desc,
            'f' => $field,
        ];
        $ref = new \ReflectionClass(Renderer::class);
        $mi = $ref->getMethod('renderInput');
        $mi->setAccessible(true);
        $html = $mi->invoke(null, $ctx);

        $this->assertStringContainsString('maxlength="20"', $html);
        $this->assertStringContainsString('minlength="3"', $html);
    }
}
