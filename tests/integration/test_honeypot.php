<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config(['logging' => ['level' => 1]]);

// Honeypot: non-empty should result in PRG 303 and no email
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
set_eid_cookie('contact_us', 'i-00000000-0000-4000-8000-000000000012');

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instHP',
    'timestamp' => time(),
    'eforms_hp' => 'bot-foo',
    'contact_us' => [
        'name' => '',
        'email' => '',
        'message' => '',
    ],
];

$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
ob_end_clean();

