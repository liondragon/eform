<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// Missing required fields should produce per-field errors
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-000000000009';

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instVAL1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => '',
        'email' => '',
        'message' => '',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
$out = ob_get_clean();
file_put_contents(__DIR__ . '/../tmp/out_validation_required.html', $out);

