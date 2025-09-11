<?php
declare(strict_types=1);
putenv('EFORMS_FORCE_MAIL_FAIL=1');
require __DIR__ . '/../bootstrap.php';
\EForms\Config::bootstrap();
$ref = new \ReflectionClass(\EForms\Config::class);
$prop = $ref->getProperty('data');
$prop->setAccessible(true);
$data = $prop->getValue();
$data['uploads']['retention_seconds'] = 0;
$prop->setValue(null, $data);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_upload_test'] = '00000000-0000-4000-8000-000000000008';

$tmp = __DIR__ . '/../tmp/fail_upload.pdf';
file_put_contents($tmp, "%PDF-1.4\n");

$_FILES = [
    'upload_test' => [
        'name' => ['file1' => 'doc.pdf'],
        'type' => ['file1' => 'application/pdf'],
        'tmp_name' => ['file1' => $tmp],
        'error' => ['file1' => UPLOAD_ERR_OK],
        'size' => ['file1' => filesize($tmp)],
    ],
];

$_POST = [
    'form_id' => 'upload_test',
    'instance_id' => 'instU2',
    'timestamp' => time(),
    'eforms_hp' => '',
    'upload_test' => ['name' => 'Zed'],
    'js_ok' => '1',
];

register_shutdown_function(function () use ($tmp): void {
    $files = array_filter(glob(__DIR__ . '/../tmp/uploads/eforms-private/*/*') ?: [], function ($f) {
        return !str_contains($f, '/ledger/') && !str_contains($f, '/throttle/');
    });
    file_put_contents(__DIR__ . '/../tmp/upload_fail_cleanup.txt', $files ? implode("\n", $files) : '');
    @unlink($tmp);
});

$fm = new \EForms\Rendering\FormManager();
$fm->handleSubmit();
