<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Missing required fields should produce per-field errors
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = 'tokVAL1';

$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instVAL1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'name' => '',
    'email' => '',
    'message' => '',
];

$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
$out = ob_get_clean();
file_put_contents(__DIR__ . '/tmp/out_validation_required.html', $out);

