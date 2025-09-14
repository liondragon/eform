<?php
declare(strict_types=1);

final class ChallengeRerenderTest extends \PHPUnit\Framework\TestCase
{
    public function testRerenderIncludesChallengeAndScripts(): void
    {
        $script = __DIR__ . '/../integration/challenge_rerender.php';
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($script);
        $output = shell_exec($cmd);
        $this->assertIsString($output);
        $this->assertStringContainsString('<div class="cf-challenge"', $output);
        $this->assertStringContainsString('SCRIPTS:["eforms-challenge-turnstile"', $output);
    }
}
