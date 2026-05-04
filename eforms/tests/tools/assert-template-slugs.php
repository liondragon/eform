<?php
/**
 * Guard that shipped template ids match their filename stems.
 *
 * Spec: Template JSON (docs/Canonical_Spec.md#sec-template-json)
 */

$base_dir = dirname( __DIR__, 2 ) . '/templates/forms';
$files = glob( $base_dir . '/*.json' );
if ( $files === false ) {
    fwrite( STDERR, "Unable to read template directory.\n" );
    exit( 1 );
}

sort( $files );
$failures = array();

foreach ( $files as $path ) {
    $slug = basename( $path, '.json' );
    $raw = file_get_contents( $path );
    $decoded = $raw === false ? null : json_decode( $raw, true );

    if ( ! is_array( $decoded ) ) {
        $failures[] = $slug . ': JSON did not decode to an object.';
        continue;
    }

    $id = isset( $decoded['id'] ) ? $decoded['id'] : null;
    if ( ! is_string( $id ) || $id !== $slug ) {
        $display = is_scalar( $id ) ? (string) $id : '<missing/non-string>';
        $failures[] = $slug . ': expected id "' . $slug . '", found "' . $display . '".';
    }
}

if ( ! empty( $failures ) ) {
    foreach ( $failures as $failure ) {
        fwrite( STDERR, $failure . "\n" );
    }
    exit( 1 );
}

echo "Template slug guard passed.\n";
