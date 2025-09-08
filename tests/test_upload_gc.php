<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$base = __DIR__ . '/tmp/uploads/eforms-private';
$dir = $base . '/20200101';
@mkdir($dir, 0777, true);
$file = $dir . '/old.txt';
file_put_contents($file, 'x');
touch($file, time() - 10);
\EForms\Uploads::gc();
$files = glob($dir . '/*');
file_put_contents(__DIR__ . '/tmp/gc.txt', $files ? 'notempty' : 'empty');
