<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// Invalid email/zip/tel should produce format errors
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_quote_request'] = '00000000-0000-4000-8000-00000000000a';

$_POST = [
    'form_id' => 'quote_request',
    'instance_id' => 'instVAL2',
    'timestamp' => time(),
    'eforms_hp' => '',
    'quote_request' => [
        'name' => 'Bob',
        'email' => 'not-an-email',
        'message' => 'Hi',
        'zip_us' => '12',
        'tel_us' => '123',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
$out = ob_get_clean();
file_put_contents(__DIR__ . '/../tmp/out_validation_formats.html', $out);

