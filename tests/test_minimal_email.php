<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Valid submit should send mail with template body
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = 'tokMAIL1';
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
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();

