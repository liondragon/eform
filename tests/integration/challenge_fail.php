<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'security' => ['cookie_missing_policy' => 'challenge'],
    'challenge' => [
        'mode' => 'auto',
        'provider' => 'turnstile',
        'turnstile' => ['site_key' => 'site', 'secret_key' => 'secret'],
    ],
]);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instCHFAIL',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'cf-turnstile-response' => 'fail',
    'js_ok' => '1',
];
$fm = new \EForms\Rendering\FormManager();
$fm->handleSubmit();
