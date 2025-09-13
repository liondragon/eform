<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'email' => ['policy' => 'autocorrect'],
]);

$tpl = [
    'email' => [
        'to' => 'a@Example.c0m ',
        'subject' => 'Hi',
        'email_template' => 'default',
        'include_fields' => [],
    ],
    'fields' => [],
];
\EForms\Email\Emailer::send($tpl, [], []);
