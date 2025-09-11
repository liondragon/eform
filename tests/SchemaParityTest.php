<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use EForms\Spec;

// Load JSON schema
$schemaPath = realpath(__DIR__ . '/../src/schema/template.schema.json');
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

// Schema required keys for field object
$schemaRequired = $schema['definitions']['field']['required'] ?? [];
sort($schemaRequired);
if ($schemaRequired !== ['type']) {
    fwrite(STDERR, "schema required keys drift\n");
    exit(1);
}

// Descriptor structural shape
$expectedKeys = ['handlers','html','is_multivalue','type','validate'];
foreach ($specDescriptors as $t => $desc) {
    $keys = array_keys($desc);
    sort($keys);
    if ($keys !== $expectedKeys) {
        fwrite(STDERR, "descriptor shape drift: $t\n");
        exit(1);
    }
    if (($desc['type'] ?? '') !== $t) {
        fwrite(STDERR, "descriptor type mismatch: $t\n");
        exit(1);
    }
}

echo "OK\n";
