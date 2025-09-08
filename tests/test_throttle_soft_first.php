<?php
declare(strict_types=1);
putenv('EFORMS_LOG_LEVEL=1');
putenv('EFORMS_THROTTLE_ENABLE=1');
putenv('EFORMS_THROTTLE_MAX_PER_MINUTE=1');
require __DIR__ . '/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_COOKIE['eforms_t_contact_us'] = 'tokS1';

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instS1',
    'timestamp' => time() - 10,
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'message' => 'Hello',
    'js_ok' => '1',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();
