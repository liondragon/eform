<?php
use PHPUnit\Framework\TestCase;

class ChallengeVerifierTest extends TestCase
{
    private function runScript(string $script): array
    {
        $tmpDir = __DIR__ . '/../tmp';
        if (is_dir($tmpDir)) {
            exec('rm -rf ' . escapeshellarg($tmpDir));
        }
        mkdir($tmpDir, 0777, true);
        $cmd = 'php-cgi ' . escapeshellarg(__DIR__ . '/' . $script);
        $out = [];
        exec($cmd, $out, $code);
        return [$code, $out, $tmpDir];
    }

    public function testCookiePolicyChallengeLogsUnconfigured(): void
    {
        [$code, $out, $dir] = $this->runScript('challenge_cookie_policy.php');
        $this->assertSame(0, $code);
        $log = @file_get_contents($dir . '/uploads/eforms-private/eforms.log');
        $this->assertStringContainsString('EFORMS_CHALLENGE_UNCONFIGURED', (string)$log);
    }

    public function testVerificationSuccessRedirects(): void
    {
        [$code, $out, $dir] = $this->runScript('challenge_success.php');
        $this->assertSame(0, $code);
        $redir = json_decode((string)file_get_contents($dir . '/redirect.txt'), true);
        $this->assertSame(303, $redir['status'] ?? null);
    }

    public function testVerificationFailureOutputsError(): void
    {
        [$code, $out, $dir] = $this->runScript('challenge_fail.php');
        $this->assertSame(0, $code);
        $body = implode("\n", $out);
        $this->assertStringContainsString('Security challenge failed.', $body);
    }
}
