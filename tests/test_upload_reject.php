<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_upload_test'] = 'tokU2';
$tmp = __DIR__ . '/tmp/upload_big.pdf';
file_put_contents($tmp, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" . str_repeat('B', 200));
$_FILES = [
    'file1' => [
        'name' => 'bigfile.pdf',
        'type' => 'application/pdf',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
    ],
];
$_POST = [
    'form_id' => 'upload_test',
    'instance_id' => 'instU2',
    'timestamp' => time(),
    'eforms_hp' => '',
    'name' => 'Zed',
];
register_shutdown_function(function () {
    $files = glob(__DIR__ . '/tmp/uploads/eforms-private/*/*');
    file_put_contents(__DIR__ . '/tmp/uploaded.txt', $files ? implode("\n", $files) : '');
});
$fm = new \EForms\FormManager();
$fm->handleSubmit();
