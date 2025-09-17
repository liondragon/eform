<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config(['security' => ['cookie_missing_policy' => 'challenge']]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_POST = [
    'form_id' => 'contact_us',
    'eforms_mode' => 'cookie',
    'instance_id' => 'instCCHAL1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
ob_end_clean();
