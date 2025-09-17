<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
set_config([
    'security' => ['token_ledger' => ['enable' => false]],
]);

use EForms\Security\Security;

$res = Security::ledger_reserve('contact_us', 'tok123');
$base = __DIR__ . '/../tmp/uploads/eforms-private/ledger';
$hash = hash('sha256', 'tok123');
$path = $base . '/contact_us/' . substr($hash, 0, 2) . '/tok123.used';
echo file_exists($path) ? 'exists' : 'missing';
