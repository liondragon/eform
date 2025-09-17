<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config(['logging' => ['level' => 1]]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
mint_hidden_token_record('contact_us', '00000000-0000-4000-8000-000000000000', time() - 1000, 3600);

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instOld',
    'timestamp' => time() - 1000,
    'eforms_token' => '00000000-0000-4000-8000-000000000000',
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Hi',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
ob_end_clean();

// Verify email is still sent to recipient
global $TEST_ARTIFACTS;
$mail = json_decode((string) file_get_contents($TEST_ARTIFACTS['mail_file']), true);
if (empty($mail) || strpos($mail[0]['to'], 'zed@example.com') === false) {
    throw new RuntimeException('Email not sent to zed@example.com');
}
