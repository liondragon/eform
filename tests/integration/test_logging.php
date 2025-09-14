<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// Force SMTP failure to test logging
putenv('EFORMS_FORCE_MAIL_FAIL=1');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-000000000005';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instLOG1',
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

