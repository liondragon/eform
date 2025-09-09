<?php
declare(strict_types=1);
putenv('EFORMS_COOKIE_MISSING_POLICY=hard');
putenv('EFORMS_LOG_LEVEL=1');
require __DIR__ . '/bootstrap.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instTOKH1',
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
$fm->handleSubmit();
