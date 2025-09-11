<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$fm = new EForms\Rendering\FormManager();
echo $fm->render($argv[1] ?? 'contact_us');

