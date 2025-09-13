<?php
use PHPUnit\Framework\TestCase;
use EForms\Security\Security;
use EForms\Config;

class SecurityOriginTest extends TestCase
{
    private function resetConfig(): void
    {
        $ref = new ReflectionClass(Config::class);
        $p = $ref->getProperty('bootstrapped');
        $p->setAccessible(true);
        $p->setValue(false);
        $p = $ref->getProperty('data');
        $p->setAccessible(true);
        $p->setValue([]);
        Config::bootstrap();
    }

    public function testSameOrigin(): void
    {
        putenv('EFORMS_ORIGIN_MODE');
        putenv('EFORMS_ORIGIN_MISSING_HARD');
        $this->resetConfig();
        $_SERVER['HTTP_ORIGIN'] = 'http://hub.local';
        $res = Security::origin_evaluate();
        $this->assertEquals('same', $res['state']);
        $this->assertFalse($res['hard_fail']);
    }

    public function testCrossSoft(): void
    {
        putenv('EFORMS_ORIGIN_MODE=soft');
        putenv('EFORMS_ORIGIN_MISSING_HARD');
        $this->resetConfig();
        $_SERVER['HTTP_ORIGIN'] = 'http://evil.local';
        $res = Security::origin_evaluate();
        $this->assertEquals('cross', $res['state']);
        $this->assertEquals(1, $res['soft_signal']);
        $this->assertFalse($res['hard_fail']);
    }

    public function testCrossHard(): void
    {
        putenv('EFORMS_ORIGIN_MODE=hard');
        putenv('EFORMS_ORIGIN_MISSING_HARD');
        $this->resetConfig();
        $_SERVER['HTTP_ORIGIN'] = 'http://evil.local';
        $res = Security::origin_evaluate();
        $this->assertEquals('cross', $res['state']);
        $this->assertTrue($res['hard_fail']);
    }

    public function testUnknownHard(): void
    {
        putenv('EFORMS_ORIGIN_MODE=hard');
        putenv('EFORMS_ORIGIN_MISSING_HARD');
        $this->resetConfig();
        $_SERVER['HTTP_ORIGIN'] = 'file://foo';
        $res = Security::origin_evaluate();
        $this->assertEquals('unknown', $res['state']);
        $this->assertTrue($res['hard_fail']);
    }

    public function testMissingHard(): void
    {
        putenv('EFORMS_ORIGIN_MODE=soft');
        putenv('EFORMS_ORIGIN_MISSING_HARD=1');
        $this->resetConfig();
        unset($_SERVER['HTTP_ORIGIN']);
        $res = Security::origin_evaluate();
        $this->assertEquals('missing', $res['state']);
        $this->assertTrue($res['hard_fail']);
    }

    protected function tearDown(): void
    {
        putenv('EFORMS_ORIGIN_MODE');
        putenv('EFORMS_ORIGIN_MISSING_HARD');
    }
}
