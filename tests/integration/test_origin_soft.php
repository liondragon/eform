<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// Valid submit under soft origin mode with cross-origin should proceed (not blocked)
set_config(['security' => ['origin_mode' => 'soft']]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'http://evil.example.com';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';

$_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-000000000001';

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'inst1',
    'timestamp' => time() - 10,
    // honeypot empty
    'eforms_hp' => '',
    // required fields
    'contact_us' => [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'message' => 'Hello',
    ],
    'js_ok' => '1',
];

// Call handler directly (router checks already passed)
$fm = new \EForms\Rendering\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();

// Ensure email was dispatched to the expected recipient
global $TEST_ARTIFACTS;
$mail = json_decode((string) file_get_contents($TEST_ARTIFACTS['mail_file']), true);
if (empty($mail) || strpos($mail[0]['to'], 'alice@example.com') === false) {
    throw new RuntimeException('Email not sent to alice@example.com');
}

