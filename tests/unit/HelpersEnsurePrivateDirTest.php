<?php
declare(strict_types=1);

use EForms\Helpers;

final class HelpersEnsurePrivateDirTest extends BaseTestCase
{
    public function testEnsurePrivateDirCreatesFilesWith0600(): void
    {
        $dir = sys_get_temp_dir() . '/eforms_priv_' . uniqid();
        @mkdir($dir, 0700, true);
        try {
            Helpers::ensure_private_dir($dir);
            $files = ['index.html', '.htaccess', 'web.config'];
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                $this->assertFileExists($path);
                $mode = fileperms($path) & 0777;
                $this->assertSame(0600, $mode, $file . ' mode is ' . decoct($mode));
            }
        } finally {
            foreach (['index.html', '.htaccess', 'web.config'] as $file) {
                @unlink($dir . '/' . $file);
            }
            @rmdir($dir);
        }
    }
}
