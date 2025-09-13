<?php
declare(strict_types=1);
putenv('EFORMS_FORCE_MAIL_FAIL=1');
require __DIR__ . '/../bootstrap.php';
set_config(['email' => ['smtp' => ['max_retries' => 2, 'retry_backoff_seconds' => 0]]]);

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

global $TEST_ARTIFACTS;
$mails = json_decode((string)file_get_contents($TEST_ARTIFACTS['mail_file']), true);
assert(count($mails) === 3);
