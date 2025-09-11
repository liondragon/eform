<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$tpl = [
    'email' => [
        'to' => 'a@Example.c0m ',
        'subject' => 'Hi',
        'email_template' => 'default',
        'include_fields' => [],
    ],
    'fields' => [],
];
\EForms\Emailer::send($tpl, [], []);
