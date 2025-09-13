<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-000000000099';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instPROTECT',
    'timestamp' => time(),
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'js_ok' => '1',
];

register_shutdown_function(function () {
    $dir = __DIR__ . '/../tmp/uploads/eforms-private';
    $files = ['index.html', '.htaccess', 'web.config'];
    $ok = true;
    foreach ($files as $f) {
        if (!file_exists($dir . '/' . $f)) {
            $ok = false;
        }
    }
    file_put_contents(__DIR__ . '/../tmp/protect.txt', $ok ? 'OK' : 'FAIL');
});

$fm = new \EForms\Rendering\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();
