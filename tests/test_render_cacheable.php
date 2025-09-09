<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$fm = new \EForms\FormManager();
$fm->render('contact_us', ['cacheable' => true]);
