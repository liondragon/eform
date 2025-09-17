<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'security' => ['cookie_missing_policy' => 'challenge'],
    'challenge' => [
        'mode' => 'auto',
        'provider' => 'turnstile',
        'turnstile' => ['site_key' => 'site', 'secret_key' => 'secret'],
    ],
    'logging' => ['level' => 1],
]);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_POST = [
    'form_id' => 'contact_us',
    'eforms_mode' => 'cookie',
    'instance_id' => 'instCHPASS',
    'timestamp' => time() - 5,
    'eforms_hp' => '',
    'js_ok' => '1',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'cf-turnstile-response' => 'pass',
];
$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
ob_end_clean();

// Ensure challenge success still dispatches email
global $TEST_ARTIFACTS;
$mail = json_decode((string) file_get_contents($TEST_ARTIFACTS['mail_file']), true);
if (empty($mail) || strpos($mail[0]['to'], 'zed@example.com') === false) {
    throw new RuntimeException('Email not sent to zed@example.com');
}
