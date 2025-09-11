<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Uploads;
use EForms\Config;

final class UploadsLimitTest extends TestCase
{
    private function rebootstrap(array $overrides): void
    {
        global $TEST_FILTERS;
        $TEST_FILTERS = [];
        $ref = new \ReflectionClass(Config::class);
        $boot = $ref->getProperty('bootstrapped');
        $boot->setAccessible(true);
        $boot->setValue(false);
        $data = $ref->getProperty('data');
        $data->setAccessible(true);
        $data->setValue([]);
        add_filter('eforms_config', function ($defaults) use ($overrides) {
            $defaults['uploads'] = array_replace($defaults['uploads'], $overrides);
            return $defaults;
        });
        Config::bootstrap();
    }

    public function testMaxFileBytesPerField(): void
    {
        $tpl = [
            'fields' => [
                ['type' => 'file', 'key' => 'up', 'max_file_bytes' => 50, 'accept' => ['pdf']],
            ],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" . str_repeat('A', 100)); // >50 bytes
        $files = [
            'up' => [
                'name' => 'a.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $this->assertArrayHasKey('up', $res['errors']);
        $this->assertSame('This file exceeds the size limit.', $res['errors']['up'][0]);
        @unlink($tmp);
    }

    public function testMaxFilesPerField(): void
    {
        $tpl = [
            'fields' => [
                ['type' => 'files', 'key' => 'docs', 'max_files' => 1, 'accept' => ['pdf']],
            ],
        ];
        $tmp1 = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp1, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
        $tmp2 = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp2, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
        $files = [
            'docs' => [
                'name' => ['a.pdf', 'b.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'tmp_name' => [$tmp1, $tmp2],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [filesize($tmp1), filesize($tmp2)],
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $this->assertArrayHasKey('docs', $res['errors']);
        $this->assertSame('Too many files.', $res['errors']['docs'][0]);
        @unlink($tmp1);
        @unlink($tmp2);
    }

    public function testMaxFieldTotalBytes(): void
    {
        $this->rebootstrap(['total_field_bytes' => 100]);
        $tpl = [
            'fields' => [
                ['type' => 'files', 'key' => 'docs', 'accept' => ['pdf']],
            ],
        ];
        $tmp1 = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp1, "%PDF-1.4\n" . str_repeat('A', 60));
        $tmp2 = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp2, "%PDF-1.4\n" . str_repeat('A', 60));
        $files = [
            'docs' => [
                'name' => ['a.pdf', 'b.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'tmp_name' => [$tmp1, $tmp2],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [filesize($tmp1), filesize($tmp2)],
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $this->assertArrayHasKey('docs', $res['errors']);
        $this->assertSame('This file exceeds the size limit.', $res['errors']['docs'][0]);
        @unlink($tmp1);
        @unlink($tmp2);
    }

    public function testMaxTotalRequestBytes(): void
    {
        $this->rebootstrap(['total_request_bytes' => 100]);
        $tpl = [
            'fields' => [
                ['type' => 'file', 'key' => 'a', 'accept' => ['pdf']],
                ['type' => 'file', 'key' => 'b', 'accept' => ['pdf']],
            ],
        ];
        $tmp1 = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp1, "%PDF-1.4\n" . str_repeat('A', 60));
        $tmp2 = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp2, "%PDF-1.4\n" . str_repeat('A', 60));
        $files = [
            'a' => [
                'name' => 'a.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmp1,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp1),
            ],
            'b' => [
                'name' => 'b.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmp2,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp2),
            ],
        ];
        $res = Uploads::normalizeAndValidate($tpl, $files);
        $this->assertArrayHasKey('_global', $res['errors']);
        $this->assertSame('File upload failed. Please try again.', $res['errors']['_global'][0]);
        @unlink($tmp1);
        @unlink($tmp2);
    }
}
