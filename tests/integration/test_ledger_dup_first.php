<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// Clean ledger and perform first (valid) submit
$ledgerBase = __DIR__ . '/../tmp/uploads/eforms-private/ledger';
if (is_dir($ledgerBase)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ledgerBase, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($ledgerBase);
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = '00000000-0000-4000-8000-00000000000b';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instDup1',
    'timestamp' => time() - 10,
    'eforms_hp' => '',
    'contact_us' => [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'message' => 'Hello',
    ],
];

$fm = new \EForms\Submission\SubmitHandler();
$fm->handleSubmit();

