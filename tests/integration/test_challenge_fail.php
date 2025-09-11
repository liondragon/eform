<?php
declare(strict_types=1);
putenv('EFORMS_COOKIE_MISSING_POLICY=challenge');
putenv('EFORMS_CHALLENGE_MODE=auto');
putenv('EFORMS_CHALLENGE_PROVIDER=turnstile');
putenv('EFORMS_TURNSTILE_SITE_KEY=site');
putenv('EFORMS_TURNSTILE_SECRET_KEY=secret');
putenv('EFORMS_LOG_LEVEL=1');
require __DIR__ . '/../bootstrap.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instCHFAIL',
    'timestamp' => time() - 5,
    'eforms_hp' => '',
    'js_ok' => '1',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'cf-turnstile-response' => 'fail',
];
$fm = new \EForms\FormManager();
$fm->handleSubmit();
