<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
set_eid_cookie('upload_test', 'i-00000000-0000-4000-8000-00000000000c');
$tmp = __DIR__ . '/../tmp/upload.pdf';
file_put_contents($tmp, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
$_FILES = [
    'upload_test' => [
        'name' => ['file1' => 'Tést File.pdf'],
        'type' => ['file1' => 'application/pdf'],
        'tmp_name' => ['file1' => $tmp],
        'error' => ['file1' => UPLOAD_ERR_OK],
        'size' => ['file1' => filesize($tmp)],
    ],
];
$_POST = [
    'form_id' => 'upload_test',
    'instance_id' => 'instU1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'upload_test' => [
        'name' => 'Zed',
    ],
];
register_shutdown_function(function () {
    $files = array_filter(glob(__DIR__ . '/../tmp/uploads/eforms-private/*/*') ?: [], function ($f) {
        return !str_contains($f, '/ledger/') && !str_contains($f, '/throttle/') && !str_contains($f, '/eid_minted/');
    });
    file_put_contents(__DIR__ . '/../tmp/uploaded.txt', $files[0] ?? '');
});
$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
ob_end_clean();
