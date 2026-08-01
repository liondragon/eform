<?php
/**
 * Canonical managed-artifact key ownership.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/ManagedArtifactKey.php';

$batch_id = eforms_test_digest( 'managed-artifact-batch' );
$intent_id = eforms_test_digest( 'managed-artifact-intent' );
$expected_prefix = 'artifacts/' . Helpers::h2( $batch_id ) . '/' . $batch_id . '/';

$mime_extensions = array(
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
);
foreach ( $mime_extensions as $mime => $extension ) {
    $key = ManagedArtifactKey::create( $batch_id, 2, $intent_id, $mime );
    eforms_test_assert(
        $key === $expected_prefix . '2-' . $intent_id . '.' . $extension,
        'Managed keys should retain the canonical validated-MIME extension.'
    );
    eforms_test_assert( ManagedArtifactKey::valid( $key ), 'A canonical managed key should validate.' );
    eforms_test_assert( ManagedArtifactKey::matches( $key, $batch_id, 2, $mime ), 'A canonical managed key should match its owning upload intent.' );
}

$first = ManagedArtifactKey::create( $batch_id, 0, eforms_test_digest( 'managed-artifact-first' ), 'image/jpeg' );
$second = ManagedArtifactKey::create( $batch_id, 1, eforms_test_digest( 'managed-artifact-second' ), 'image/png' );
eforms_test_assert(
    strpos( $first, $expected_prefix ) === 0 && strpos( $second, $expected_prefix ) === 0,
    'All upload intents in one batch should share one storage namespace.'
);
eforms_test_assert( substr( $first, -4 ) === '.jpg', 'JPEG keys should use the canonical .jpg extension.' );
$heic_key = ManagedArtifactKey::create( $batch_id, 2, $intent_id, 'image/heic' );
$heif_key = ManagedArtifactKey::create( $batch_id, 2, $intent_id, 'image/heif' );
eforms_test_assert(
    ManagedArtifactKey::matches( $heic_key, $batch_id, 2, 'image/heif' )
        && ManagedArtifactKey::matches( $heif_key, $batch_id, 2, 'image/heic' ),
    'HEIC and HEIF detection aliases should preserve the authorized canonical key extension.'
);

$old_opaque_identity = hash( 'sha256', $batch_id . "\0" . $intent_id );
$invalid = array(
    str_replace( 'artifacts/' . Helpers::h2( $batch_id ) . '/', 'artifacts/00/', $first ),
    preg_replace( '/\.jpg$/', '.jpeg', $first ),
    $expected_prefix . '02-' . $intent_id . '.png',
    $expected_prefix . '2-' . substr( $intent_id, 1 ) . '.png',
    'artifacts/' . Helpers::h2( $old_opaque_identity ) . '/' . $old_opaque_identity,
);
foreach ( $invalid as $key ) {
    eforms_test_assert( ! ManagedArtifactKey::valid( $key ), 'Non-canonical managed keys should fail closed.' );
}
eforms_test_assert( ManagedArtifactKey::create( $batch_id, 0, $intent_id, 'image/gif' ) === '', 'Unsupported MIME values should not produce keys.' );
eforms_test_assert( ! ManagedArtifactKey::matches( $second, $batch_id, 1, 'image/jpeg' ), 'A MIME/extension mismatch should fail closed.' );

echo "Managed artifact key tests passed.\n";
