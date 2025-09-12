<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../vendor/autoload.php';
use EForms\Validation\TemplateValidator;
use JsonSchema\Validator;
use JsonSchema\Constraints\Constraint;

$schemaFile = realpath(__DIR__ . '/../../schema/template.schema.json');
$schema = json_decode(file_get_contents($schemaFile));
$templates = glob(__DIR__ . '/../../templates/forms/*.json') ?: [];
foreach ($templates as $tplFile) {
    $data = json_decode(file_get_contents($tplFile));
    $validator = new Validator();
    $validator->validate($data, $schema, Constraint::CHECK_MODE_APPLY_DEFAULTS);
    if (!$validator->isValid()) {
        fwrite(STDERR, "schema fail: $tplFile\n");
        foreach ($validator->getErrors() as $error) {
            fwrite(STDERR, sprintf("[%s] %s\n", $error['property'], $error['message']));
        }
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
