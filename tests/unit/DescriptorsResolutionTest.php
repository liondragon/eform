<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Spec;
use EForms\Validator;
use EForms\Renderer;
use EForms\Normalizer;

final class DescriptorsResolutionTest extends TestCase
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
        Validator::resolve('unknown');
    }
}
