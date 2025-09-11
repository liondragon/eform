<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$fm = new \EForms\Rendering\FormManager();
$fm->render('contact_us', ['cacheable' => false]);
