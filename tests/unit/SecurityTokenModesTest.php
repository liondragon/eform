<?php
use EForms\Security\Security;
use EForms\Config;

class SecurityTokenModesTest extends BaseTestCase
{
    private array $origConfig;
    private string $tokenDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        Config::bootstrap();
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $this->origConfig = $prop->getValue();
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        $this->tokenDir = $base === '' ? '' : $base . '/tokens';
        $this->clearTokenDir();
    }

    protected function tearDown(): void
    {
        $this->clearTokenDir();
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
        $token = 'h-00000000-0000-4000-8000-000000000015';
        $this->persistToken($token, 'contact_us', 'hidden');
        $res = Security::token_validate('contact_us', true, $token);
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

    public function testHiddenInvalidDoesNotFallbackToCookie(): void
    {
        $cookieToken = 'c-00000000-0000-4000-8000-000000000016';
        $_COOKIE['eforms_t_contact_us'] = $cookieToken;
        $this->persistToken($cookieToken, 'contact_us', 'cookie');
        $res = Security::token_validate('contact_us', true, 'bad');
        $this->assertSame('hidden', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertTrue($res['hard_fail']);
        $this->assertSame(0, $res['soft_signal']);
        $this->assertFalse($res['require_challenge']);
        unset($_COOKIE['eforms_t_contact_us']);
    }

    public function testHiddenInvalidHonorsSubmissionRequirement(): void
    {
        $this->setConfig('security.submission_token.required', false);
        unset($_COOKIE['eforms_t_contact_us']);
        $res = Security::token_validate('contact_us', true, 'bad');
        $this->assertSame('hidden', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertFalse($res['hard_fail']);
        $this->assertSame(1, $res['soft_signal']);
        $this->assertFalse($res['require_challenge']);
    }

    public function testHiddenTokenModeMismatchHardFails(): void
    {
        $token = 'h-00000000-0000-4000-8000-000000000017';
        $this->persistToken($token, 'contact_us', 'cookie');
        $res = Security::token_validate('contact_us', true, $token);
        $this->assertSame('hidden', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertTrue($res['hard_fail']);
    }

    public function testHiddenTokenPrefixMismatchHardFails(): void
    {
        $token = 'c-00000000-0000-4000-8000-000000000018';
        $this->persistToken($token, 'contact_us', 'hidden');
        $res = Security::token_validate('contact_us', true, $token);
        $this->assertSame('hidden', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertTrue($res['hard_fail']);
    }

    public function testCookieTokenPrefixMismatchHardFails(): void
    {
        $token = 'h-00000000-0000-4000-8000-000000000019';
        $_COOKIE['eforms_t_contact_us'] = $token;
        $this->persistToken($token, 'contact_us', 'cookie');
        $res = Security::token_validate('contact_us', false, null);
        $this->assertSame('cookie', $res['mode']);
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

    private function clearTokenDir(): void
    {
        if ($this->tokenDir === '' || !is_dir($this->tokenDir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tokenDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $fs) {
            $path = $fs->getPathname();
            if ($fs->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($this->tokenDir);
    }

    private function persistToken(string $token, string $formId, string $mode): void
    {
        if ($this->tokenDir === '') {
            return;
        }
        $hash = hash('sha256', $token);
        $dir = $this->tokenDir . '/' . substr($hash, 0, 2);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $data = [
            'form_id' => $formId,
            'mode' => $mode,
            'expires' => time() + 600,
        ];
        @file_put_contents($dir . '/' . $hash . '.json', json_encode($data));
    }
}
