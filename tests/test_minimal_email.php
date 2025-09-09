<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Valid submit should send mail with template body
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-000000000006';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instMAIL1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();

