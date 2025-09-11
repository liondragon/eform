<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$_SERVER['HTTP_USER_AGENT'] = str_repeat('A', 300) . "\x01\x02B";
\EForms\Logging::write('error', 'EFORMS_TEST', []);

$logfile = __DIR__ . '/../tmp/uploads/eforms-private/eforms.log';
$line = trim((string) file_get_contents($logfile));
$data = json_decode($line, true);
$ua = $data['meta']['headers']['user_agent'] ?? '';
file_put_contents(__DIR__ . '/../tmp/ua.txt', $ua);
