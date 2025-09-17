<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
set_eid_cookie('contact_us', 'i-00000000-0000-4000-8000-0000000c0fee');
$_POST = [
    'form_id' => 'contact_us',
    'eforms_mode' => 'cookie',
    'instance_id' => 'instROT',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
];

register_shutdown_function(function () {
    file_put_contents(__DIR__ . '/../tmp/cookie.txt', $_COOKIE['eforms_eid_contact_us'] ?? '');
});

$sh = new \EForms\Submission\SubmitHandler();
ob_start();
$sh->handleSubmit();
ob_end_clean();
