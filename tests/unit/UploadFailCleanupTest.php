<?php
declare(strict_types=1);


/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class UploadFailCleanupTest extends BaseTestCase
{
    private function deleteTree(string $path): void
    {
        if ($path === '') {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (glob(rtrim($path, '/') . '/*') ?: [] as $item) {
            $this->deleteTree($item);
        }
        @rmdir($path);
    }

    public function testFilesRemovedWhenEmailFailsAndNoRetention(): void
    {
        $php = escapeshellcmd(PHP_BINARY);
        $script = escapeshellarg(__DIR__ . '/../integration/upload_fail_cleanup_runner.php');
        @unlink(__DIR__ . '/../tmp/upload_fail_cleanup.txt');
        foreach (glob(__DIR__ . '/../tmp/uploads/eforms-private/*/*') ?: [] as $f) {
            if (!str_contains($f, '/ledger/') && !str_contains($f, '/throttle/')) {
                $this->deleteTree($f);
            }
        }
        $ledgerDir = __DIR__ . '/../tmp/uploads/eforms-private/ledger';
        if (is_dir($ledgerDir)) {
            foreach (glob($ledgerDir . '/*') ?: [] as $f) {
                if (is_file($f)) @unlink($f); else @array_map('unlink', glob($f . '/*') ?: []);
                if (is_dir($f)) @rmdir($f);
            }
        }
        shell_exec("$php $script");
        $out = trim((string) @file_get_contents(__DIR__ . '/../tmp/upload_fail_cleanup.txt'));
        $this->assertSame('', $out);
    }
}
