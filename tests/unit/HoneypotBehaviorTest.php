<?php
use PHPUnit\Framework\TestCase;

class HoneypotBehaviorTest extends TestCase
{
    public function testStealthHeaderAndTokenBurn(): void
    {
        $tmpDir = __DIR__ . '/../tmp';
        if (is_dir($tmpDir)) {
            exec('rm -rf ' . escapeshellarg($tmpDir));
        }
        mkdir($tmpDir, 0777, true);
        $script = __DIR__ . '/../integration/test_honeypot_capture.php';
        $cmd = 'php-cgi ' . escapeshellarg($script);
        $out = [];
        exec($cmd, $out, $code);
        $this->assertSame(0, $code);

        $headers = [];
        foreach ($out as $line) {
            $line = rtrim($line, "\r\n");
            if ($line === '') break;
            $headers[] = $line;
        }
        $this->assertContains('X-EForms-Stealth: 1', $headers);

        $log = (string)file_get_contents($tmpDir . '/uploads/eforms-private/eforms.log');
        $this->assertStringContainsString('EFORMS_ERR_HONEYPOT', $log);
        $this->assertStringContainsString('"stealth":true', $log);

        $hash = sha1('contact_us:00000000-0000-4000-8000-000000000013');
        $ledgerFile = $tmpDir . '/uploads/eforms-private/ledger/' . substr($hash,0,2) . '/' . $hash . '.used';
        $this->assertFileExists($ledgerFile);
    }
}
