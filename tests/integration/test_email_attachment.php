<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_upload_test'] = '00000000-0000-4000-8000-000000000007';
$tmp = __DIR__ . '/../tmp/upload.pdf';
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
    'instance_id' => 'instEA1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'upload_test' => [
        'name' => 'Zed',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();
