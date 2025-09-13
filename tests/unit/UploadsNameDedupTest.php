<?php
declare(strict_types=1);

use EForms\Uploads\Uploads;
use EForms\Config;

final class UploadsNameDedupTest extends BaseTestCase
{
    public function testDuplicateNamesAreUniquified(): void
    {
        Config::bootstrap();
        $tpl = [
            'fields' => [
                ['type' => 'files', 'key' => 'docs', 'accept' => ['pdf']],
            ],
        ];
        $tmp1 = tempnam(sys_get_temp_dir(), 'up');
        $tmp2 = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp1, "%PDF-1.4\n");
        file_put_contents($tmp2, "%PDF-1.4\n");
        $files = [
            'docs' => [
                'name' => ['report.pdf', 'report.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'tmp_name' => [$tmp1, $tmp2],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [filesize($tmp1), filesize($tmp2)],
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $stored = Uploads::store($res['files']);
        $names = array_column($stored['docs'], 'original_name_safe');
        $this->assertSame(['report.pdf', 'report (2).pdf'], $names);
        Uploads::deleteStored($stored);
        @unlink($tmp1);
        @unlink($tmp2);
    }
}
