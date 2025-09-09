<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Second submit with same token should be rejected
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = 'dupTok';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instDup2',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'message' => 'Hello again',
    ],
];

$fm = new \EForms\FormManager();
$fm->handleSubmit();

