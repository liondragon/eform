<?php
declare(strict_types=1);
putenv('EFORMS_COOKIE_MISSING_POLICY=hard');
require __DIR__ . '/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instCHARD1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'name' => 'Zed',
    'email' => 'zed@example.com',
    'message' => 'Ping',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();
