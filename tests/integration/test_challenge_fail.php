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
$origTs = time() - 5;
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instCHFAIL',
    'timestamp' => $origTs,
    'eforms_hp' => '',
    'js_ok' => '1',
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'cf-turnstile-response' => 'fail',
];
ob_start();
register_shutdown_function(function () use ($origTs) {
    $html = ob_get_clean();
    $ok = true;
    if (strpos($html, 'Security challenge failed.') === false) $ok = false;
    if (!preg_match('/name="timestamp" value="([^\"]+)"/', $html, $m) || (int)$m[1] !== $origTs) $ok = false;
    echo $ok ? 'OK' : 'FAIL';
});
$fm = new \EForms\Submission\SubmitHandler();
$fm->handleSubmit();
