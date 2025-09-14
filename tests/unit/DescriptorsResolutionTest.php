<?php
declare(strict_types=1);

use EForms\Spec;
use EForms\Validation\Validator;
use EForms\Rendering\Renderer;
use EForms\Validation\Normalizer;

final class DescriptorsResolutionTest extends BaseTestCase
{
    public function testAllHandlerIdsResolve(): void
    {
        $all = Spec::typeDescriptors();
        foreach ($all as $desc) {
            $this->assertArrayHasKey('type', $desc);
            $type = $desc['type'];
            $handlers = $desc['handlers'];
            $v = Validator::resolve($handlers['validator_id'] ?? '');
            $n = Normalizer::resolve($handlers['normalizer_id'] ?? '');
            $r = Renderer::resolve($handlers['renderer_id'] ?? '');
            $this->assertTrue(is_callable($v), "$type validator");
            $this->assertTrue(is_callable($n), "$type normalizer");
            $this->assertNotNull($r, "$type renderer");
        }
    }

    public function testDescriptorForIncludesType(): void
    {
        $d = Spec::descriptorFor('text');
        $this->assertSame('text', $d['type']);

        $u = Spec::descriptorFor('unknown');
        $this->assertSame('unknown', $u['type']);
    }

    public function testUnknownHandlerIdFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fields.foo.validator');
        Validator::resolve('unknown', 'fields.foo.validator');
    }

    public function testAliasHandlersMatchTargets(): void
    {
        $all = Spec::typeDescriptors();
        foreach ($all as $desc) {
            if (!isset($desc['alias_of'])) {
                continue;
            }
            $target = $desc['alias_of'];
            $this->assertArrayHasKey($target, $all);
            $this->assertSame($all[$target]['handlers'], $desc['handlers'], $desc['type'] . ' handlers');
        }
    }
}
