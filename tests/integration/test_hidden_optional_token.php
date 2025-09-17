<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

set_config([
    'security' => [
        'submission_token' => ['required' => false],
        'spam' => ['soft_fail_threshold' => 5],
        'min_fill_seconds' => 0,
    ],
    'email' => [
        'disable_send' => false,
    ],
    'logging' => [
        'level' => 2,
    ],
]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_SERVER['HTTP_ORIGIN'] = 'http://hub.local';
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_SERVER['HTTP_USER_AGENT'] = 'phpunit-hidden-optional';

unset($_COOKIE['eforms_eid_contact_us']);

$_POST = [
    'form_id' => 'contact_us',
    'eforms_mode' => 'hidden',
    'instance_id' => 'instOPTH1',
    'timestamp' => time() - 30,
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'js_ok' => '1',
];

$handler = new \EForms\Submission\SubmitHandler();
$handler->handleSubmit();
