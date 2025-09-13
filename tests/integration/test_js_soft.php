<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'logging' => ['level' => 1],
    'security' => ['js_hard_mode' => false],
]);

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

$fm = new \EForms\Rendering\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();

// Ensure email is still dispatched when JS disabled
global $TEST_ARTIFACTS;
$mail = json_decode((string) file_get_contents($TEST_ARTIFACTS['mail_file']), true);
if (empty($mail) || strpos($mail[0]['to'], 'alice@example.com') === false) {
    throw new RuntimeException('Email not sent to alice@example.com');
}

