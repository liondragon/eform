<?php
declare(strict_types=1);

use EForms\Uploads\Uploads;
use EForms\Config;

final class UploadsUtf8NameTest extends BaseTestCase
{
    public function testUtf8NamesPreservedWhenNotTransliterated(): void
    {
        set_config(['uploads' => ['transliterate' => false]]);

        $tpl = [
            'fields' => [
                ['type' => 'file', 'key' => 'up', 'accept' => ['pdf']],
            ],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, "%PDF-1.4\n");
        $files = [
            'up' => [
                'name' => ['résumé.pdf'],
                'type' => ['application/pdf'],
                'tmp_name' => [$tmp],
                'error' => [UPLOAD_ERR_OK],
                'size' => [filesize($tmp)],
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $stored = Uploads::store($res['files']);
        $names = array_column($stored['up'], 'original_name_safe');
        $this->assertSame(['résumé.pdf'], $names);
        Uploads::deleteStored($stored);
        @unlink($tmp);
    }

    public function testUtf8NamesTransliteratedWhenEnabled(): void
    {
        Config::bootstrap();
        $tpl = [
            'fields' => [
                ['type' => 'file', 'key' => 'up', 'accept' => ['pdf']],
            ],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, "%PDF-1.4\n");
        $files = [
            'up' => [
                'name' => ['résumé.pdf'],
                'type' => ['application/pdf'],
                'tmp_name' => [$tmp],
                'error' => [UPLOAD_ERR_OK],
                'size' => [filesize($tmp)],
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $stored = Uploads::store($res['files']);
        $names = array_column($stored['up'], 'original_name_safe');
        $this->assertSame(['resume.pdf'], $names);
        Uploads::deleteStored($stored);
        @unlink($tmp);
    }
}
