<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$renderer = new \EForms\Rendering\FormRenderer();
echo $renderer->render('contact_us', ['cacheable' => false]);
