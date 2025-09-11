<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Uploads\Uploads;
use EForms\Config;

final class UploadsReservedNameTest extends TestCase
{
    public function testReservedWindowsNamesAreModified(): void
    {
        Config::bootstrap();
        $tpl = [
            'fields' => [
                ['type' => 'file', 'key' => 'doc', 'accept' => ['pdf']],
            ],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, "%PDF-1.4\n");
        $files = [
            'doc' => [
                'name' => 'CON.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $stored = Uploads::store($res['files']);
        $names = array_column($stored['doc'], 'original_name_safe');
        $this->assertSame(['con_.pdf'], $names);
        Uploads::deleteStored($stored);
        @unlink($tmp);
    }
}
