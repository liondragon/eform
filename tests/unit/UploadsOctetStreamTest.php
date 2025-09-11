<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Uploads\Uploads;

final class UploadsOctetStreamTest extends TestCase
{
    public function testOctetStreamAllowedWhenExtensionMatches(): void
    {
        $tpl = [
            'fields' => [
                ['type' => 'file', 'key' => 'up', 'accept' => ['pdf']],
            ],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, random_bytes(100));
        $files = [
            'up' => [
                'name' => 'a.pdf',
                'type' => 'application/octet-stream',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $this->assertArrayNotHasKey('up', $res['errors']);
        @unlink($tmp);
    }
}
