<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Spec;
use EForms\Validator;
use EForms\Renderer;

final class DescriptorsResolutionTest extends TestCase
{
    public function testAllHandlerIdsResolve(): void
    {
        $all = Spec::typeDescriptors();
        foreach ($all as $type => $desc) {
            $handlers = $desc['handlers'];
            $v = Validator::resolve($handlers['validator_id'] ?? '', 'validator');
            $n = Validator::resolve($handlers['normalizer_id'] ?? '', 'normalizer');
            $r = Renderer::resolve($handlers['renderer_id'] ?? '');
            $this->assertTrue(is_callable($v), "$type validator");
            $this->assertTrue(is_callable($n), "$type normalizer");
            $this->assertNotNull($r, "$type renderer");
        }
    }

    public function testUnknownHandlerIdFails(): void
    {
        $this->expectException(\RuntimeException::class);
        Validator::resolve('unknown', 'validator');
    }
}
