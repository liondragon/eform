<?php
declare(strict_types=1);
putenv('EFORMS_FORCE_MAIL_FAIL=1');
putenv('EFORMS_EMAIL_SMTP_MAX_RETRIES=2');
putenv('EFORMS_EMAIL_SMTP_RETRY_BACKOFF_SECONDS=0');
require __DIR__ . '/../bootstrap.php';

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
