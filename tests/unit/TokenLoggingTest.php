<?php
use PHPUnit\Framework\TestCase;

class TokenLoggingTest extends TestCase
{
    private function runScript(string $script): array
    {
        $tmpDir = __DIR__ . '/../tmp';
        if (is_dir($tmpDir)) {
            exec('rm -rf ' . escapeshellarg($tmpDir));
        }
        mkdir($tmpDir, 0777, true);
        $cmd = 'php-cgi ' . escapeshellarg(__DIR__ . '/../integration/' . $script);
        $out = [];
        exec($cmd, $out, $code);
        return [$code, $out, $tmpDir];
    }

    public function testTokenHardFailureLogged(): void
    {
        [$code, $out, $dir] = $this->runScript('token_hard_fail.php');
        $this->assertSame(0, $code);
        $log = @file_get_contents($dir . '/uploads/eforms-private/eforms.log');
        $this->assertStringContainsString('EFORMS_ERR_TOKEN', (string)$log);
    }
}
