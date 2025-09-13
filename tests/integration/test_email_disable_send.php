<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'email' => ['disable_send' => true],
]);

$tpl = [
    'email' => [
        'to' => 'a@example.com',
        'subject' => 'Hi',
        'email_template' => 'default',
        'include_fields' => [],
    ],
    'fields' => [],
];
\EForms\Email\Emailer::send($tpl, [], []);
