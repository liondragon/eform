<?php
declare(strict_types=1);

use EForms\Uploads\Uploads;
use EForms\Config;

final class UploadsUtf8NameTest extends BaseTestCase
{
    public function testUtf8NamesPreservedWhenNotTransliterated(): void
    {
        Config::bootstrap();
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $data = $prop->getValue();
        $data['uploads']['transliterate'] = false;
        $prop->setValue($data);

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
}
