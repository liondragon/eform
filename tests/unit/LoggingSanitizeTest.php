<?php
declare(strict_types=1);

use EForms\Logging;

final class LoggingSanitizeTest extends BaseTestCase
{
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/eforms-logtest-' . uniqid('', true);
        @mkdir($dir, 0700, true);
        return $dir;
    }

    public function testHeadersUserAgentSanitized(): void
    {
        $dir = $this->tempDir();
        set_config([
            'uploads' => ['dir' => $dir],
            'logging' => [
                'mode' => 'jsonl',
                'level' => 1,
                'headers' => true,
            ],
        ]);
        $_SERVER['HTTP_USER_AGENT'] = "Good\x01Bad\nUA";
        Logging::write('error', 'CODE');
        $logFile = $dir . '/eforms.log';
        $line = trim((string) file_get_contents($logFile));
        $data = json_decode($line, true);
        $ua = $data['meta']['headers']['user_agent'] ?? '';
        $this->assertSame('GoodBadUA', $ua);
        @unlink($logFile);
        @rmdir($dir);
    }

    public function testEmailRedactedWhenPiiDisabled(): void
    {
        $dir = $this->tempDir();
        set_config([
            'uploads' => ['dir' => $dir],
            'logging' => [
                'mode' => 'jsonl',
                'level' => 1,
                'pii' => false,
            ],
        ]);
        Logging::write('error', 'CODE', ['email' => 'user@example.com']);
        $logFile = $dir . '/eforms.log';
        $line = trim((string) file_get_contents($logFile));
        $data = json_decode($line, true);
        $email = $data['meta']['email'] ?? '';
        $this->assertSame('u***@example.com', $email);
        @unlink($logFile);
        @rmdir($dir);
    }

    public function testEmailPreservedWhenPiiEnabled(): void
    {
        $dir = $this->tempDir();
        set_config([
            'uploads' => ['dir' => $dir],
            'logging' => [
                'mode' => 'jsonl',
                'level' => 1,
                'pii' => true,
            ],
        ]);
        Logging::write('error', 'CODE', ['email' => 'user@example.com']);
        $logFile = $dir . '/eforms.log';
        $line = trim((string) file_get_contents($logFile));
        $data = json_decode($line, true);
        $email = $data['meta']['email'] ?? '';
        $this->assertSame('user@example.com', $email);
        @unlink($logFile);
        @rmdir($dir);
    }
}
