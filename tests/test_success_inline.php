<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Simulate that a success cookie is present and renderer should show success message
$_GET['eforms_success'] = 'contact_us';
$_COOKIE['eforms_s_contact_us'] = 'contact_us:instOK';

$fm = new \EForms\FormManager();
$html = $fm->render('contact_us');
file_put_contents(__DIR__ . '/tmp/out_success_inline.html', $html);

