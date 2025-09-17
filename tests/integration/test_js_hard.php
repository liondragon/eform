<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'logging' => ['level' => 1],
    'security' => ['js_hard_mode' => true],
]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
set_eid_cookie('contact_us', 'i-00000000-0000-4000-8000-000000000004');

$_POST = [
    'form_id' => 'contact_us',
    'eforms_mode' => 'cookie',
    'instance_id' => 'instJH1',
    'timestamp' => time() - 10,
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'message' => 'Hi',
    ],
    'js_ok' => '0',
];

$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
ob_end_clean();

