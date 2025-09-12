<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Uploads\Uploads;

final class UploadsFinfoUnavailableTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFinfoUnavailableError(): void
    {
        if (!defined('EFORMS_FINFO_UNAVAILABLE')) {
            define('EFORMS_FINFO_UNAVAILABLE', true);
        }
        @unlink(__DIR__ . '/../tmp/uploads/eforms-private/eforms.log');

        $tpl = [
            'id' => 'contact',
            'fields' => [
                ['type' => 'file', 'key' => 'up', 'accept' => ['pdf']],
            ],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, random_bytes(10));
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
        $this->assertSame(['File uploads are unsupported on this server.'], $res['errors']['up']);

        @unlink($tmp);
    }
}
