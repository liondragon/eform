<?php
use EForms\Security\Security;
use EForms\Config;

class SecurityTokenModesTest extends BaseTestCase
{
    private array $origConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $this->origConfig = $prop->getValue();
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->origConfig);
        parent::tearDown();
    }

    private function setConfig(string $path, $value): void
    {
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $data = $prop->getValue();
        $segments = explode('.', $path);
        $cursor =& $data;
        $last = array_pop($segments);
        foreach ($segments as $seg) {
            if (!isset($cursor[$seg]) || !is_array($cursor[$seg])) {
                $cursor[$seg] = [];
            }
            $cursor =& $cursor[$seg];
        }
        $cursor[$last] = $value;
        $prop->setValue(null, $data);
    }

    public function testHiddenTokenMode(): void
    {
        $res = Security::token_validate('contact_us', true, '00000000-0000-4000-8000-000000000015');
        $this->assertSame('hidden', $res['mode']);
        $this->assertTrue($res['token_ok']);
        $this->assertFalse($res['hard_fail']);
    }

    public function testCookieModeSoftMissing(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'soft');
        unset($_COOKIE['eforms_t_contact_us']);
        $res = Security::token_validate('contact_us', false, null);
        $this->assertSame('cookie', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertFalse($res['hard_fail']);
        $this->assertSame(1, $res['soft_signal']);
        $this->assertFalse($res['require_challenge']);
    }

    public function testCookieModeHardMissing(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'hard');
        unset($_COOKIE['eforms_t_contact_us']);
        $res = Security::token_validate('contact_us', false, null);
        $this->assertSame('cookie', $res['mode']);
        $this->assertTrue($res['hard_fail']);
        $this->assertFalse($res['require_challenge']);
    }

    public function testCookieModeChallenge(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'challenge');
        unset($_COOKIE['eforms_t_contact_us']);
        $res = Security::token_validate('contact_us', false, null);
        $this->assertSame('cookie', $res['mode']);
        $this->assertFalse($res['hard_fail']);
        $this->assertSame(1, $res['soft_signal']);
        $this->assertTrue($res['require_challenge']);
    }

    public function testCookieModeOffPolicy(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'off');
        unset($_COOKIE['eforms_t_contact_us']);
        $res = Security::token_validate('contact_us', false, null);
        $this->assertSame('cookie', $res['mode']);
        $this->assertFalse($res['hard_fail']);
        $this->assertSame(0, $res['soft_signal']);
        $this->assertFalse($res['require_challenge']);
    }

    public function testHiddenInvalidDoesNotUseCookie(): void
    {
        $_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-000000000016';
        $res = Security::token_validate('contact_us', true, 'bad');
        $this->assertSame('hidden', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertTrue($res['hard_fail']);
        unset($_COOKIE['eforms_t_contact_us']);
    }

    public function testCookieRotation(): void
    {
        $tmpDir = __DIR__ . '/../tmp';
        if (is_dir($tmpDir)) {
            exec('rm -rf ' . escapeshellarg($tmpDir));
        }
        mkdir($tmpDir, 0777, true);
        $script = __DIR__ . '/../integration/test_cookie_rotation.php';
        $cmd = 'php ' . escapeshellarg($script);
        exec($cmd, $out, $code);
        $this->assertSame(0, $code);
        $cookie = trim((string)file_get_contents($tmpDir . '/cookie.txt'));
        $this->assertNotSame('oldCookie', $cookie);
        $this->assertNotSame('', $cookie);
    }
}
