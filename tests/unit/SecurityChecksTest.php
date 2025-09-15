<?php
declare(strict_types=1);

use EForms\Security\Security;

final class SecurityChecksTest extends BaseTestCase
{
    private string $logFile = __DIR__ . '/../tmp/uploads/eforms-private/eforms.log';

    public function testHoneypotNotTriggered(): void
    {
        set_config(['logging' => ['level' => 2]]);
        @unlink($this->logFile);
        unset($_POST['eforms_hp']);
        $res = Security::honeypot_check('contact_us', '00000000-0000-4000-8000-000000000001', []);
        $this->assertFalse($res['triggered']);
        $log = @file_get_contents($this->logFile) ?: '';
        $this->assertStringNotContainsString('EFORMS_ERR_HONEYPOT', $log);
    }

    public function testHoneypotTriggered(): void
    {
        set_config(['logging' => ['level' => 2]]);
        @unlink($this->logFile);
        $_POST['eforms_hp'] = 'bot';
        $token = '00000000-0000-4000-8000-000000000093';
        $res = Security::honeypot_check('contact_us', $token, []);
        $this->assertTrue($res['triggered']);
        $this->assertSame('stealth_success', $res['mode']);
        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('EFORMS_ERR_HONEYPOT', $log);
        $hash = sha1('contact_us:' . $token);
        $ledger = __DIR__ . '/../tmp/uploads/eforms-private/ledger/' . substr($hash, 0, 2) . '/' . $hash . '.used';
        $this->assertFileExists($ledger);
    }

    public function testMinFillPassAndFail(): void
    {
        set_config(['logging' => ['level' => 2]]);
        @unlink($this->logFile);
        $oldTs = time() - 10;
        $failTs = time();
        $ok = Security::min_fill_check($oldTs, []);
        $this->assertSame(0, $ok);
        $fail = Security::min_fill_check($failTs, []);
        $this->assertSame(1, $fail);
        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('EFORMS_ERR_MIN_FILL', $log);
    }

    public function testFormAgePassAndFail(): void
    {
        set_config(['logging' => ['level' => 2], 'security' => ['max_form_age_seconds' => 5]]);
        @unlink($this->logFile);
        $recent = time();
        $old = time() - 10;
        $ok = Security::form_age_check($recent, true, []);
        $this->assertSame(0, $ok);
        $fail = Security::form_age_check($old, true, []);
        $this->assertSame(1, $fail);
        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('EFORMS_ERR_FORM_AGE', $log);
    }
}

