<?php
declare(strict_types=1);
// Hard origin mode should block cross-origin
require __DIR__ . '/../bootstrap.php';
set_config(['security' => ['origin_mode' => 'hard']]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'http://evil.example.com';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
set_eid_cookie('contact_us', 'i-00000000-0000-4000-8000-000000000014');

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'inst1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'message' => 'Hello',
    ],
    'js_ok' => '1',
];

$fm = new \EForms\Submission\SubmitHandler();
ob_start();
$fm->handleSubmit();
$out = ob_get_clean();
file_put_contents(__DIR__ . '/../tmp/out_origin_hard.txt', $out);
