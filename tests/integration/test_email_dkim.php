<?php
declare(strict_types=1);
$pk = __DIR__ . '/../tmp/dkim.key';
file_put_contents($pk, 'key');

putenv('EFORMS_EMAIL_DKIM_DOMAIN=example.com');
putenv('EFORMS_EMAIL_DKIM_SELECTOR=sel');
putenv('EFORMS_EMAIL_DKIM_PRIVATE_KEY_PATH=' . $pk);
require __DIR__ . '/../bootstrap.php';

$captured = null;
$hook = function($phpmailer) use (&$captured) { $captured = clone $phpmailer; };
add_action('phpmailer_init', $hook, 20);

$tpl = [
    'email' => [
        'to' => 'a@example.com',
        'subject' => 'Hi',
        'email_template' => 'default',
        'include_fields' => [],
    ],
    'fields' => [],
];
\EForms\Emailer::send($tpl, [], []);
remove_action('phpmailer_init', $hook, 20);

assert($captured !== null);
assert($captured->DKIM_domain === 'example.com');
assert($captured->DKIM_selector === 'sel');
assert($captured->DKIM_private === $pk);
