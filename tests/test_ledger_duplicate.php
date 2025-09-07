<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Ensure clean ledger dir
$ledgerBase = __DIR__ . '/tmp/uploads/eforms-private/ledger';
if (is_dir($ledgerBase)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ledgerBase, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($ledgerBase);
}

// First submit (should succeed -> redirect 303)
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = 'dupTok';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instDup1',
    'timestamp' => time(),
    'eforms_hp' => '',
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'message' => 'Hello',
];
$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
ob_end_clean();

// Second submit with the same token (should render token error)
require __DIR__ . '/bootstrap.php'; // rebootstrap fresh hooks and artifacts
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'http://hub.local/form-test/';
$_COOKIE['eforms_t_contact_us'] = 'dupTok';
$_POST = [
    'form_id' => 'contact_us',
    'instance_id' => 'instDup2',
    'timestamp' => time(),
    'eforms_hp' => '',
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'message' => 'Hello again',
];
$fm = new \EForms\FormManager();
ob_start();
$fm->handleSubmit();
$out2 = ob_get_clean();
file_put_contents(__DIR__ . '/tmp/out_ledger_duplicate.html', $out2);

