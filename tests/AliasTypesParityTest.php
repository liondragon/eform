<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Spec;
use EForms\Validator;
use EForms\Renderer;

final class AliasTypesParityTest extends TestCase
{
    public function testAliasTypesShareHandlersButDifferTraits(): void
    {
        $types = ['name','first_name','last_name'];
        $callables = [];
        $autocomplete = [];
        foreach ($types as $t) {
            $desc = Spec::descriptorFor($t);
            $handlers = $desc['handlers'];
            $callables[] = [
                'validator' => Validator::resolve($handlers['validator_id'], 'validator'),
                'normalizer' => Validator::resolve($handlers['normalizer_id'], 'normalizer'),
                'renderer' => Renderer::resolve($handlers['renderer_id']),
            ];
            $autocomplete[$t] = $desc['html']['autocomplete'] ?? '';
        }
        $first = $callables[0];
        foreach ($callables as $c) {
            $this->assertSame($first['validator'], $c['validator']);
            $this->assertSame($first['normalizer'], $c['normalizer']);
            $this->assertSame($first['renderer'], $c['renderer']);
        }
        $this->assertNotSame($autocomplete['name'], $autocomplete['first_name']);
        $this->assertNotSame($autocomplete['name'], $autocomplete['last_name']);
        $this->assertNotSame($autocomplete['first_name'], $autocomplete['last_name']);
    }
}
