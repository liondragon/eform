<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_upload_test'] = 'tokEA1';
$tmp = __DIR__ . '/tmp/upload.pdf';
file_put_contents($tmp, "%PDF-1.4\n");
$_FILES = [
    'file1' => [
        'name' => 'doc.pdf',
        'type' => 'application/pdf',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
    ],
];
$_POST = [
    'form_id' => 'upload_test',
    'instance_id' => 'instEA1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'name' => 'Zed',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();
