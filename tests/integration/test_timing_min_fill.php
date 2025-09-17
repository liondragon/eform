<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config(['logging' => ['level' => 1]]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
set_eid_cookie('contact_us', 'i-00000000-0000-4000-8000-000000000002', time());

$_POST = [
    'form_id' => 'contact_us',
    'eforms_mode' => 'cookie',
    'instance_id' => 'instTS',
    'timestamp' => time(),
    'contact_us' => [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'message' => 'Hi',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
ob_end_clean();

// Verify mail dispatch despite soft fail
global $TEST_ARTIFACTS;
$mail = json_decode((string) file_get_contents($TEST_ARTIFACTS['mail_file']), true);
if (empty($mail) || strpos($mail[0]['to'], 'alice@example.com') === false) {
    throw new RuntimeException('Email not sent to alice@example.com');
}
