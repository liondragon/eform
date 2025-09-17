<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'logging' => ['level' => 1],
    'throttle' => [
        'enable' => true,
        'per_ip' => ['max_per_minute' => 1],
    ],
]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
set_eid_cookie('contact_us', 'i-00000000-0000-4000-8000-00000000000d');

$_POST = [
    'form_id' => 'contact_us',
    'eforms_mode' => 'cookie',
    'instance_id' => 'instS1',
    'timestamp' => time() - 10,
    'contact_us' => [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'message' => 'Hello',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
ob_end_clean();
