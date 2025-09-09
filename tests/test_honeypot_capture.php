<?php
declare(strict_types=1);
putenv('EFORMS_LOG_LEVEL=1');
require __DIR__ . '/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = 'tokHP';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instHP',
    'timestamp' => time(),
    'eforms_hp' => 'bot',
    'contact_us' => [
        'name' => '',
        'email' => '',
        'message' => '',
    ],
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();
