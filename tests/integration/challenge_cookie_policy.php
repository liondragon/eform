<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'security' => ['cookie_missing_policy' => 'challenge'],
    'logging' => ['level' => 1],
]);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instCCHAL1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'js_ok' => '1',
    'cf-turnstile-response' => 'dummy',
];
$sh = new \EForms\Submission\SubmitHandler();
$sh->handleSubmit();
