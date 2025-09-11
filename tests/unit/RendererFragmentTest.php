<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Rendering\Renderer;

final class RendererFragmentTest extends TestCase
{
    public function testAllowedAndDisallowedTags(): void
    {
        $ref = new \ReflectionClass(Renderer::class);
        $m = $ref->getMethod('sanitizeFragment');
        $m->setAccessible(true);
        $input = '<p><strong>ok</strong><script>alert(1)</script><em>hi</em></p>';
        $output = $m->invoke(null, $input);
        $this->assertStringContainsString('<strong>ok</strong>', $output);
        $this->assertStringContainsString('<em>hi</em>', $output);
        $this->assertStringNotContainsString('<script>', $output);
    }
}
