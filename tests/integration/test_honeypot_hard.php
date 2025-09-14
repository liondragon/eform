<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'security' => ['honeypot_response' => 'hard_fail'],
    'logging' => ['level' => 1],
]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-000000000011';

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instHPH',
    'timestamp' => time(),
    'eforms_hp' => 'bot-hard',
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
