<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use EForms\Spec;
use EForms\TemplateSpec;

// Load JSON schema
$schemaPath = realpath(__DIR__ . '/../../schema/template.schema.json');
if (!is_string($schemaPath)) {
    fwrite(STDERR, "schema not found\n");
    exit(1);
}
$schema = json_decode(file_get_contents($schemaPath), true);
if (!is_array($schema)) {
    fwrite(STDERR, "schema parse fail\n");
    exit(1);
}

// Compare field type enum
$schemaTypes = $schema['definitions']['field']['properties']['type']['enum'] ?? [];
sort($schemaTypes);

$specDescriptors = Spec::typeDescriptors();
$specTypes = array_keys($specDescriptors);
$specTypes[] = 'row_group';
sort($specTypes);

if ($schemaTypes !== $specTypes) {
    fwrite(STDERR, "field type enum mismatch\n");
    exit(1);
}

$tplTypes = TemplateSpec::fieldAllowedTypes();
sort($tplTypes);
if ($tplTypes !== $specTypes) {
    fwrite(STDERR, "TemplateSpec field types drift\n");
    exit(1);
}

// Schema required keys for field object
$schemaRequired = $schema['definitions']['field']['required'] ?? [];
sort($schemaRequired);
if ($schemaRequired !== ['type']) {
    fwrite(STDERR, "schema required keys drift\n");
    exit(1);
}

// Root keys parity
$schemaRoot = array_keys($schema['properties'] ?? []);
sort($schemaRoot);
$specRoot = TemplateSpec::rootAllowed();
sort($specRoot);
if ($schemaRoot !== $specRoot) {
    fwrite(STDERR, "root keys drift\n");
    exit(1);
}

$schemaRootReq = $schema['required'] ?? [];
sort($schemaRootReq);
$specRootReq = array_keys(TemplateSpec::rootRequired());
sort($specRootReq);
if ($schemaRootReq !== $specRootReq) {
    fwrite(STDERR, "root required drift\n");
    exit(1);
}

// Success mode enum
$schemaSuccess = $schema['properties']['success']['properties']['mode']['enum'] ?? [];
sort($schemaSuccess);
$specSuccess = TemplateSpec::successSpec()['enums']['mode'];
sort($specSuccess);
if ($schemaSuccess !== $specSuccess) {
    fwrite(STDERR, "success mode drift\n");
    exit(1);
}

// Email display_format_tel enum
$schemaTel = $schema['properties']['email']['properties']['display_format_tel']['enum'] ?? [];
sort($schemaTel);
$specTel = TemplateSpec::emailSpec()['enums']['display_format_tel'];
sort($specTel);
if ($schemaTel !== $specTel) {
    fwrite(STDERR, "display_format_tel drift\n");
    exit(1);
}

// Rule enum
$schemaRule = $schema['definitions']['rule']['properties']['rule']['enum'] ?? [];
sort($schemaRule);
$specRule = TemplateSpec::ruleTypes();
sort($specRule);
if ($schemaRule !== $specRule) {
    fwrite(STDERR, "rule enum drift\n");
    exit(1);
}

// Descriptor structural shape & alias parity
$baseKeys = ['constants','handlers','html','is_multivalue','type','validate'];
foreach ($specDescriptors as $t => $desc) {
    $expected = $baseKeys;
    if (isset($desc['alias_of'])) {
        $expected[] = 'alias_of';
        $target = $desc['alias_of'];
        if (!isset($specDescriptors[$target])) {
            fwrite(STDERR, "alias target missing: $t\n");
            exit(1);
        }
        if (($specDescriptors[$target]['handlers'] ?? []) !== ($desc['handlers'] ?? [])) {
            fwrite(STDERR, "alias handler mismatch: $t\n");
            exit(1);
        }
    }
    $keys = array_keys($desc);
    sort($keys);
    sort($expected);
    if ($keys !== $expected) {
        fwrite(STDERR, "descriptor shape drift: $t\n");
        exit(1);
    }
    if (($desc['type'] ?? '') !== $t) {
        fwrite(STDERR, "descriptor type mismatch: $t\n");
        exit(1);
    }
}

echo "OK\n";
