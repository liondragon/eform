<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Honeypot: non-empty should result in PRG 303 and no email
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = 'tokHP';

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instHP',
    'timestamp' => time(),
    'eforms_hp' => 'bot-foo',
    'name' => '',
    'email' => '',
    'message' => '',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();

