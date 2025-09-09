<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = 'oldCookie';
$_POST = [
    'form_id' => 'contact_us',
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
    file_put_contents(__DIR__ . '/tmp/cookie.txt', $_COOKIE['eforms_t_contact_us'] ?? '');
});

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();
