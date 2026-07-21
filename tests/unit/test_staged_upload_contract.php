<?php
/**
 * Executable fixtures for the staged-upload contract before runtime owners land.
 *
 * Retained Proof Owner: staged-upload fixed protocol and numeric fixtures.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Anchors.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';

$fixture_path = __DIR__ . '/../fixtures/staged_upload_contract.json';
$fixture = json_decode( file_get_contents( $fixture_path ), true );
eforms_test_assert( is_array( $fixture ), 'Staged-upload contract fixture should decode.' );

$batch = $fixture['batch_id'];
eforms_test_assert( hash( 'sha256', $batch['raw_token'] ) === $batch['token_digest'], 'Token digest fixture should be canonical SHA-256.' );
eforms_test_assert( hash( 'sha256', $batch['policy_json'] ) === $batch['policy_fingerprint'], 'Policy fingerprint fixture should hash canonical JSON.' );

eforms_test_assert( UploadBatchStore::capacity_platform_supported( 8 ) === true, 'Managed-capacity accounting should accept 64-bit PHP integer width.' );
eforms_test_assert( UploadBatchStore::capacity_platform_supported( 4 ) === false, 'Managed-capacity accounting should reject 32-bit PHP integer width before state mutation.' );
eforms_test_assert(
    UploadBatchStore::derive_batch_id(
        $batch['raw_token'],
        $batch['form_id'],
        $batch['instance_id'],
        $batch['field_key'],
        $batch['policy_fingerprint']
    ) === $batch['expected'],
    'UploadBatchStore should own the canonical deterministic batch ID implementation.'
);
eforms_test_assert( strlen( $batch['expected'] ) === Anchors::get( 'MANAGED_BATCH_ID_CHARS' ), 'A full 256-bit unpadded base64url batch ID should match the canonical encoded length.' );
eforms_test_assert( preg_match( FormProtocol::upload_batch_id_pattern(), $batch['expected'] ) === 1, 'FormProtocol should own the exact batch-ID shape.' );
$secret_fixture = rtrim( strtr( base64_encode( str_repeat( "\x73", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
eforms_test_assert( preg_match( FormProtocol::upload_batch_secret_pattern(), $secret_fixture ) === 1, 'FormProtocol should own the batch-secret shape derived from its byte bound.' );
eforms_test_assert( preg_match( FormProtocol::managed_id_pattern(), str_repeat( 'a', Anchors::get( 'MANAGED_ID_MAX_CHARS' ) ) ) === 1, 'FormProtocol should own the bounded managed-ID shape.' );

foreach ( $fixture['anchors'] as $name => $expected ) {
    eforms_test_assert( Anchors::get( $name ) === $expected, 'Staged-upload Anchor should match its reviewed fixture: ' . $name );
}

$credentials = $fixture['credentials'];
eforms_test_assert( $credentials === array(
    'header' => 'X-EForms-Batch-Secret',
    'hidden_root' => 'eforms_upload_batches',
    'batch_id' => 'batch_id',
    'batch_secret' => 'batch_secret',
), 'Credential transport names should remain field-scoped and distinct by entrypoint.' );

$attempts = array();
for ( $index = 0; $index < Anchors::get( 'STAGED_PREVIEW_MAX_ATTEMPTS' ); $index++ ) {
    $attempts[] = array(
        'edge' => Anchors::get( 'STAGED_PREVIEW_MAX_EDGE' ) - ( $index * Anchors::get( 'STAGED_PREVIEW_EDGE_STEP' ) ),
        'quality' => Anchors::get( 'STAGED_PREVIEW_JPEG_QUALITY_INITIAL' ) - ( $index * Anchors::get( 'STAGED_PREVIEW_JPEG_QUALITY_STEP' ) ),
    );
}
eforms_test_assert( $attempts === $fixture['preview_attempts'], 'Preview attempts should derive only from fixed Anchors.' );
eforms_test_assert( $fixture['production_readiness']['throttle_enable'] === true, 'Staged production readiness should require the existing throttle.' );

$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/forms.css' );
eforms_test_assert( is_string( $css ), 'Managed uploader CSS should be readable.' );
preg_match_all( '/--eforms-upload-[a-z-]+(?=\s*:)/', $css, $variable_matches );
$variables = array_values( array_unique( isset( $variable_matches[0] ) ? $variable_matches[0] : array() ) );
sort( $variables, SORT_STRING );
$expected_variables = array(
    '--eforms-upload-accent',
    '--eforms-upload-border',
    '--eforms-upload-card-bg',
    '--eforms-upload-error',
    '--eforms-upload-track',
);
sort( $expected_variables, SORT_STRING );
eforms_test_assert( $variables === $expected_variables, 'Managed uploader CSS should expose exactly the five approved theme variables.' );
