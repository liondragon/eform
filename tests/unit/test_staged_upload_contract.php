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

eforms_test_assert( $fixture['production_readiness']['throttle_enable'] === true, 'Staged production readiness should require the existing throttle.' );
eforms_test_assert( $fixture['production_readiness']['staged_backend'] === 'authoritative-artifact', 'Staged acceptance should require authoritative artifact inspection rather than an image-processing backend.' );

$managed_manifest = $fixture['managed_manifest'];
eforms_test_assert( $managed_manifest['version'] === UploadBatchStore::MANIFEST_VERSION, 'The authoritative-artifact fixture should track the code-owned schema version.' );
eforms_test_assert( $managed_manifest['artifact_store_field'] === 'artifact_store', 'Each aggregate should persist the immutable owner used by review and cleanup.' );
eforms_test_assert( $managed_manifest['artifact_store_identity_field'] === 'artifact_store_identity', 'Worker aggregates should persist the exact deployment identity used by review and cleanup.' );
eforms_test_assert( $managed_manifest['aggregate_byte_fields'] === array( 'artifact_bytes' ), 'Aggregate accounting should total only committed authoritative artifacts.' );
eforms_test_assert(
    in_array( 'object_key', $managed_manifest['item_fields'], true )
        && in_array( 'object_version', $managed_manifest['item_fields'], true )
        && in_array( 'accepted_at', $managed_manifest['item_fields'], true )
        && in_array( 'intent_id', $managed_manifest['intent_fields'], true ),
    'The manifest fixture should distinguish durable intents from committed immutable artifact facts.'
);

$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/upload.css' );
eforms_test_assert( is_string( $css ), 'Managed uploader CSS should be readable.' );
preg_match_all( '/--eforms-upload-[a-z-]+(?=\s*:)/', $css, $variable_matches );
$variables = array_values( array_unique( isset( $variable_matches[0] ) ? $variable_matches[0] : array() ) );
sort( $variables, SORT_STRING );
$expected_variables = array(
    '--eforms-upload-accent',
    '--eforms-upload-border',
    '--eforms-upload-card-bg',
    '--eforms-upload-error',
    '--eforms-upload-text',
    '--eforms-upload-track',
);
sort( $expected_variables, SORT_STRING );
eforms_test_assert( $variables === $expected_variables, 'Managed uploader CSS should expose exactly the six approved theme variables.' );
