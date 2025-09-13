<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'email' => ['staging_redirect_to' => 'stage@example.com'],
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
