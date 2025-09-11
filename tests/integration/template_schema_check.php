<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
use EForms\Validation\TemplateValidator;

$schema = realpath(__DIR__ . '/../../schema/template.schema.json');
$templates = glob(__DIR__ . '/../../templates/*.json') ?: [];
foreach ($templates as $tplFile) {
    $cmd = 'python3 -m jsonschema ' . escapeshellarg($schema) . ' -i ' . escapeshellarg($tplFile);
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "schema fail: $tplFile\n");
        exit(1);
    }
    $tpl = json_decode(file_get_contents($tplFile), true);
    $res = TemplateValidator::preflight($tpl);
    if (!$res['ok']) {
        fwrite(STDERR, "preflight fail: $tplFile\n");
        exit(1);
    }
}
echo "OK";
