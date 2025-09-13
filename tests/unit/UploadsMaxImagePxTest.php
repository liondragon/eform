<?php
declare(strict_types=1);

use EForms\Uploads\Uploads;
use EForms\Config;

final class UploadsMaxImagePxTest extends BaseTestCase
{
    private array $origConfig;

    protected function setUp(): void
    {
        parent::setUp();

        Config::bootstrap();
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $this->origConfig = $prop->getValue();
        $data = $this->origConfig;
        $data['uploads']['max_image_px'] = 100;
        $prop->setValue(null, $data);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->origConfig);
        parent::tearDown();
    }

    public function testRejectsOversizedImages(): void
    {
        $tpl = [
            'fields' => [
                ['type' => 'file', 'key' => 'pic', 'accept' => ['image']],
            ],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQ4jWNgGAWjYBSMgqELAATEAAEeAgbGAAAAAElFTkSuQmCC');
        file_put_contents($tmp, $png);
        $files = [
            'pic' => [
                'name' => 'test.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $this->assertArrayHasKey('pic', $res['errors']);
        @unlink($tmp);
    }
}
