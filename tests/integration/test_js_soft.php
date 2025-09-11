<?php
declare(strict_types=1);
putenv('EFORMS_LOG_LEVEL=1');
putenv('EFORMS_JS_HARD_MODE=0');
require __DIR__ . '/../bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-000000000003';

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instJS1',
    'timestamp' => time() - 10,
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'message' => 'Hi',
    ],
    'js_ok' => '0',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();

