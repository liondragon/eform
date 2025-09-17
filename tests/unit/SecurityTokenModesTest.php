<?php
use EForms\Security\Security;
use EForms\Config;

class SecurityTokenModesTest extends BaseTestCase
{
    private array $origConfig;
    private string $tokenDir = '';
    private string $eidDir = '';

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
        $this->eidDir = $base === '' ? '' : $base . '/eid_minted';
        $this->clearTokenDir();
        $this->clearEidDir();
    }

    protected function tearDown(): void
    {
        $this->clearTokenDir();
        $this->clearEidDir();
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
        $token = '00000000-0000-4000-8000-000000000015';
        $this->persistToken($token, 'contact_us', 'hidden');
        $res = $this->validateToken([
            'mode_claim' => 'hidden',
            'token_field_present' => true,
            'posted_token' => $token,
            'cookie_token' => '',
        ]);
        $this->assertSame('hidden', $res['mode']);
        $this->assertTrue($res['token_ok']);
        $this->assertFalse($res['hard_fail']);
    }

    public function testCookieModeSoftMissing(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'soft');
        unset($_COOKIE['eforms_eid_contact_us']);
        $res = $this->validateToken();
        $this->assertSame('cookie', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertFalse($res['hard_fail']);
        $this->assertSame(1, $res['soft_signal']);
        $this->assertFalse($res['require_challenge']);
    }

    public function testCookieModeHardMissing(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'hard');
        unset($_COOKIE['eforms_eid_contact_us']);
        $res = $this->validateToken();
        $this->assertSame('cookie', $res['mode']);
        $this->assertTrue($res['hard_fail']);
        $this->assertFalse($res['require_challenge']);
    }

    public function testCookieModeChallenge(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'challenge');
        unset($_COOKIE['eforms_eid_contact_us']);
        $res = $this->validateToken();
        $this->assertSame('cookie', $res['mode']);
        $this->assertFalse($res['hard_fail']);
        $this->assertSame(1, $res['soft_signal']);
        $this->assertTrue($res['require_challenge']);
    }

    public function testCookieModeOffPolicy(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'off');
        unset($_COOKIE['eforms_eid_contact_us']);
        $res = $this->validateToken();
        $this->assertSame('cookie', $res['mode']);
        $this->assertFalse($res['hard_fail']);
        $this->assertSame(0, $res['soft_signal']);
        $this->assertFalse($res['require_challenge']);
    }

    public function testHiddenInvalidDoesNotFallbackToCookie(): void
    {
        $cookieToken = 'i-00000000-0000-4000-8000-000000000016';
        set_eid_cookie('contact_us', $cookieToken);
        $this->persistToken($cookieToken, 'contact_us', 'cookie');
        $res = $this->validateToken([
            'mode_claim' => 'hidden',
            'token_field_present' => true,
            'posted_token' => 'bad',
            'cookie_token' => $cookieToken,
        ]);
        $this->assertSame('cookie', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertTrue($res['hard_fail']);
        $this->assertSame(0, $res['soft_signal']);
        $this->assertFalse($res['require_challenge']);
        $this->assertSame('hidden_token_posted', $res['reason'] ?? '');
        unset($_COOKIE['eforms_eid_contact_us']);
    }

    public function testHiddenInvalidSoftWhenSubmissionRequirementDisabled(): void
    {
        $this->setConfig('security.submission_token.required', false);
        unset($_COOKIE['eforms_eid_contact_us']);
        $res = $this->validateToken([
            'mode_claim' => 'hidden',
            'token_field_present' => true,
            'posted_token' => 'bad',
        ]);
        $this->assertSame('hidden', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertFalse($res['hard_fail']);
        $this->assertSame(1, $res['soft_signal']);
        $this->assertFalse($res['require_challenge']);
    }

    public function testHiddenMissingTokenSoftSignalWhenOptional(): void
    {
        $this->setConfig('security.submission_token.required', false);
        unset($_COOKIE['eforms_eid_contact_us']);
        $res = $this->validateToken([
            'mode_claim' => 'hidden',
            'token_field_present' => false,
            'posted_token' => '',
        ]);
        $this->assertSame('hidden', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertFalse($res['hard_fail']);
        $this->assertSame(1, $res['soft_signal']);
        $this->assertFalse($res['require_challenge']);
        $this->assertSame('', $res['submission_id']);
    }

    public function testHiddenTokenModeMismatchHardFails(): void
    {
        $token = '00000000-0000-4000-8000-000000000017';
        $this->persistToken($token, 'contact_us', 'cookie');
        $res = $this->validateToken([
            'mode_claim' => 'hidden',
            'token_field_present' => true,
            'posted_token' => $token,
        ]);
        $this->assertSame('hidden', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertTrue($res['hard_fail']);
    }

    public function testHiddenTokenPrefixMismatchHardFails(): void
    {
        $token = 'i-00000000-0000-4000-8000-000000000018';
        $this->persistToken($token, 'contact_us', 'hidden');
        $res = $this->validateToken([
            'mode_claim' => 'hidden',
            'token_field_present' => true,
            'posted_token' => $token,
        ]);
        $this->assertSame('hidden', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertTrue($res['hard_fail']);
    }

    public function testCookieTokenPrefixMismatchHardFails(): void
    {
        $token = 'h-00000000-0000-4000-8000-000000000019';
        set_eid_cookie('contact_us', $token);
        $this->persistToken($token, 'contact_us', 'cookie');
        $res = $this->validateToken();
        $this->assertSame('cookie', $res['mode']);
        $this->assertFalse($res['token_ok']);
        $this->assertTrue($res['hard_fail']);
        unset($_COOKIE['eforms_eid_contact_us']);
    }

    public function testCookieSlotBinding(): void
    {
        $eid = 'i-00000000-0000-4000-8000-0000000000aa';
        $this->setConfig('security.cookie_mode_slots_enabled', true);
        $this->setConfig('security.cookie_mode_slots_allowed', [1, 2]);
        set_eid_cookie('contact_us', $eid);
        mint_eid_record('contact_us', $eid, time(), 600, [1, 2]);

        $valid = $this->validateToken([
            'slot' => 2,
        ]);
        $this->assertTrue($valid['token_ok']);
        $this->assertSame('cookie', $valid['mode']);

        $invalid = $this->validateToken([
            'slot' => 3,
        ]);
        $this->assertFalse($invalid['token_ok']);
        $this->assertTrue($invalid['hard_fail']);
        $this->assertSame('slot_not_allowed', $invalid['reason'] ?? '');

        unset($_COOKIE['eforms_eid_contact_us']);
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
        $this->assertSame('i-00000000-0000-4000-8000-0000000c0fee', $cookie);
    }

    public function testHiddenSubmissionOptionalTokenFlow(): void
    {
        $tmpDir = __DIR__ . '/../tmp';
        if (is_dir($tmpDir)) {
            exec('rm -rf ' . escapeshellarg($tmpDir));
        }
        mkdir($tmpDir, 0777, true);
        $script = __DIR__ . '/../integration/test_hidden_optional_token.php';
        $cmd = 'php ' . escapeshellarg($script);
        exec($cmd, $out, $code);
        $this->assertSame(0, $code);

        $mail = json_decode((string) file_get_contents($tmpDir . '/mail.json'), true);
        $this->assertIsArray($mail);
        $this->assertNotEmpty($mail);
        $entry = $mail[0];
        $this->assertStringStartsWith('[SUSPECT] ', (string) ($entry['subject'] ?? ''));
        $headers = $entry['headers'] ?? [];
        $this->assertIsArray($headers);
        $this->assertContains('X-EForms-Suspect: 1', $headers);

        $redirect = json_decode((string) file_get_contents($tmpDir . '/redirect.txt'), true);
        $this->assertSame('http://hub.local/form-test/?eforms_success=contact_us', $redirect['location'] ?? '');
        $this->assertSame(303, $redirect['status'] ?? 0);
    }

    private function clearTokenDir(): void
    {
        $this->clearDir($this->tokenDir);
    }

    private function clearEidDir(): void
    {
        $this->clearDir($this->eidDir);
    }

    private function clearDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
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
        @rmdir($dir);
    }

    private function validateToken(array $context = []): array
    {
        $defaults = [
            'mode_claim' => 'cookie',
            'token_field_present' => false,
            'posted_token' => '',
            'cookie_token' => (string) ($_COOKIE['eforms_eid_contact_us'] ?? ''),
            'slot' => 1,
        ];
        return Security::token_validate('contact_us', array_merge($defaults, $context));
    }

    private function persistToken(string $token, string $formId, string $mode): void
    {
        if ($mode === 'cookie') {
            mint_eid_record($formId, $token, time(), 600);
            return;
        }
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
            'issued_at' => time(),
            'expires' => time() + 600,
        ];
        @file_put_contents($dir . '/' . $hash . '.json', json_encode($data));
    }
}
