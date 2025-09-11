<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Spec;
use EForms\Renderer;

final class RendererParityTest extends TestCase
{
    public function testSharedAttributesMirrored(): void
    {
        $descInput = Spec::descriptorFor('text');
        $descTextarea = Spec::descriptorFor('textarea');
        $this->assertSame('text', $descInput['type']);
        $this->assertSame('textarea', $descTextarea['type']);

        $field = [
            'type' => 'text',
            'key' => 'foo',
            'required' => true,
            'placeholder' => 'hi',
            'autocomplete' => 'name',
            'max_length' => 10,
            'min_length' => 2,
        ];
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
        $ref = new \ReflectionClass(Renderer::class);
        $mi = $ref->getMethod('renderInput');
        $mi->setAccessible(true);
        $htmlInput = $mi->invoke(null, array_merge($ctxBase, ['desc'=>$descInput,'f'=>$field]));

        $mt = $ref->getMethod('renderTextarea');
        $mt->setAccessible(true);
        $field['type'] = 'textarea';
        $htmlTextarea = $mt->invoke(null, array_merge($ctxBase, ['desc'=>$descTextarea,'f'=>$field]));

        foreach (['required','placeholder="hi"','autocomplete="name"','maxlength="10"','minlength="2"','enterkeyhint="send"'] as $frag) {
            $this->assertStringContainsString($frag, $htmlInput);
            $this->assertStringContainsString($frag, $htmlTextarea);
        }
    }
}
