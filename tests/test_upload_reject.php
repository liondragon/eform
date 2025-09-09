<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_upload_test'] = 'tokU2';
$tmp = __DIR__ . '/tmp/upload_big.pdf';
file_put_contents($tmp, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" . str_repeat('B', 200));
$_FILES = [
    'upload_test' => [
        'name' => ['file1' => 'bigfile.pdf'],
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
    'upload_test' => [
        'name' => 'Zed',
    ],
];
register_shutdown_function(function () {
    $files = array_filter(glob(__DIR__ . '/tmp/uploads/eforms-private/*/*') ?: [], function ($f) {
        return !str_contains($f, '/ledger/') && !str_contains($f, '/throttle/');
    });
    file_put_contents(__DIR__ . '/tmp/uploaded.txt', $files ? implode("\n", $files) : '');
});
$fm = new \EForms\FormManager();
$fm->handleSubmit();
