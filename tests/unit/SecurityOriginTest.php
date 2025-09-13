<?php
use EForms\Security\Security;
use EForms\Config;

class SecurityOriginTest extends BaseTestCase
{
    private function resetConfig(array $overrides = []): void
    {
        set_config(array_replace_recursive([
            'security' => ['origin_mode' => 'soft', 'origin_missing_hard' => false],
        ], $overrides));
    }

    public function testSameOrigin(): void
    {
        $this->resetConfig();
        $_SERVER['HTTP_ORIGIN'] = 'http://hub.local';
        $res = Security::origin_evaluate();
        $this->assertEquals('same', $res['state']);
        $this->assertFalse($res['hard_fail']);
    }

    public function testCrossSoft(): void
    {
        $this->resetConfig([
            'security' => ['origin_mode' => 'soft', 'origin_missing_hard' => false],
        ]);
        $_SERVER['HTTP_ORIGIN'] = 'http://evil.local';
        $res = Security::origin_evaluate();
        $this->assertEquals('cross', $res['state']);
        $this->assertEquals(1, $res['soft_signal']);
        $this->assertFalse($res['hard_fail']);
    }

    public function testCrossHard(): void
    {
        $this->resetConfig([
            'security' => ['origin_mode' => 'hard', 'origin_missing_hard' => false],
        ]);
        $_SERVER['HTTP_ORIGIN'] = 'http://evil.local';
        $res = Security::origin_evaluate();
        $this->assertEquals('cross', $res['state']);
        $this->assertTrue($res['hard_fail']);
    }

    public function testUnknownHard(): void
    {
        $this->resetConfig([
            'security' => ['origin_mode' => 'hard', 'origin_missing_hard' => false],
        ]);
        $_SERVER['HTTP_ORIGIN'] = 'file://foo';
        $res = Security::origin_evaluate();
        $this->assertEquals('unknown', $res['state']);
        $this->assertTrue($res['hard_fail']);
    }

    public function testMissingHard(): void
    {
        $this->resetConfig([
            'security' => ['origin_mode' => 'soft', 'origin_missing_hard' => true],
        ]);
        unset($_SERVER['HTTP_ORIGIN']);
        $res = Security::origin_evaluate();
        $this->assertEquals('missing', $res['state']);
        $this->assertTrue($res['hard_fail']);
    }
}
