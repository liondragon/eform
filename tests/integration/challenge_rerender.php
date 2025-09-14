<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'security' => ['cookie_missing_policy' => 'challenge'],
    'challenge' => [
        'mode' => 'off',
        'provider' => 'turnstile',
        'turnstile' => ['site_key' => 'site', 'secret_key' => 'secret'],
    ],
]);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instRR1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'js_ok' => '1',
];
// ensure we can inspect scripts after exit
register_shutdown_function(function () {
    echo "\nSCRIPTS:" . json_encode($GLOBALS['wp_enqueued_scripts']);
});
$sh = new \EForms\Submission\SubmitHandler();
$sh->handleSubmit();
