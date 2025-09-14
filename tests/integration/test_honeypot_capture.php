<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config(['logging' => ['level' => 1]]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-000000000013';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instHP',
    'timestamp' => time(),
    'eforms_hp' => 'bot',
    'contact_us' => [
        'name' => '',
        'email' => '',
        'message' => '',
    ],
];

$sh = new \EForms\Submission\SubmitHandler();
ob_start();
$sh->handleSubmit();
ob_end_clean();
