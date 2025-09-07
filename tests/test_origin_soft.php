<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Valid submit under soft origin mode with cross-origin should proceed (not blocked)
putenv('EFORMS_ORIGIN_MODE=soft');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'http://evil.example.com';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';

$_COOKIE['eforms_t_contact_us'] = 'tok123';

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'inst1',
    'timestamp' => time(),
    // honeypot empty
    'eforms_hp' => '',
    // required fields
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'message' => 'Hello',
];

// Call handler directly (router checks already passed)
$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();

