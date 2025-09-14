<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$renderer = new EForms\Rendering\FormRenderer();
echo $renderer->render($argv[1] ?? 'contact_us', []);

