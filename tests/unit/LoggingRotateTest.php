<?php
declare(strict_types=1);

use EForms\Config;
use EForms\Logging;

final class LoggingRotateTest extends BaseTestCase
{
    public function testRotationAndPrune(): void
    {
        $dir = sys_get_temp_dir() . '/eforms-logtest-' . uniqid('', true);
        @mkdir($dir, 0700, true);

        set_config([
            'uploads' => ['dir' => $dir],
            'logging' => [
                'file_max_size' => 10,
                'retention_days' => 1,
            ],
        ]);

        $logFile = $dir . '/eforms.log';
        @unlink($logFile);
        foreach (glob($dir . '/eforms-*.log') ?: [] as $f) {
            @unlink($f);
        }

        $old = $dir . '/eforms-20000101-000000.log';
        file_put_contents($old, 'old');
        touch($old, time() - 86400 * 2);

        Logging::write('error', 'CODE1');
        Logging::write('error', 'CODE2');

        $rotated = glob($dir . '/eforms-*.log');
        $this->assertCount(1, $rotated);
        $rotFile = $rotated[0];
        $this->assertMatchesRegularExpression('~eforms-\d{8}-\d{6}\.log$~', basename($rotFile));
        $this->assertStringContainsString('CODE1', (string) file_get_contents($rotFile));
        $this->assertStringContainsString('CODE2', (string) file_get_contents($logFile));
        $this->assertFalse(file_exists($old));

        @unlink($logFile);
        foreach ($rotated as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
