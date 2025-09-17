<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// Second submit with same token should be rejected
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
set_eid_cookie('contact_us', 'i-00000000-0000-4000-8000-00000000000b');
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instDup2',
    'timestamp' => time() - 10,
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'message' => 'Hello again',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
$out = ob_get_clean();
echo $out;

