<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'security' => ['submission_token' => ['required' => true]],
]);
putenv('EFORMS_FORCE_MAIL_FAIL=1');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$origInstance = 'instOP1';
$origToken = '00000000-0000-4000-8000-00000000abcd';
mint_hidden_token_record('contact_us', $origToken, time() - 60);
$_POST = [
    'form_id' => 'contact_us',
    'eforms_mode' => 'cookie',
    'instance_id' => $origInstance,
    'timestamp' => time(),
    'eforms_hp' => '',
    'eforms_token' => $origToken,
    'contact_us' => [
        'name' => 'Zed',
        'email' => 'zed@example.com',
        'message' => 'Ping',
    ],
    'js_ok' => '1',
];
ob_start();
register_shutdown_function(function () use ($origInstance, $origToken) {
    $html = ob_get_clean();
    $ok = true;
    if (strpos($html, 'Operational error.') === false) $ok = false;
    if (strpos($html, 'value="Zed"') === false) $ok = false;
    if (strpos($html, 'value="zed@example.com"') === false) $ok = false;
    if (strpos($html, '>Ping<') === false) $ok = false;
    if (!preg_match('/name="instance_id" value="([^"]+)"/', $html, $m) || $m[1] === $origInstance) $ok = false;
    if (!preg_match('/name="eforms_token" value="([^"]+)"/', $html, $t) || $t[1] === $origToken) $ok = false;
    $hash = hash('sha256', $origToken);
    $ledger = __DIR__ . '/../tmp/uploads/eforms-private/ledger/contact_us/' . substr($hash,0,2) . '/' . $origToken . '.used';
    if (!is_file($ledger)) $ok = false;
    echo $ok ? 'OK' : 'FAIL';
});
$sh = new \EForms\Submission\SubmitHandler();
$sh->handleSubmit();
