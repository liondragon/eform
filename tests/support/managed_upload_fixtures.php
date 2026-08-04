<?php
/**
 * Shared construction helpers for managed-upload integration fixtures.
 *
 * Keep assertions in the owning behavior tests; these helpers only construct
 * canonical inputs already owned by Anchors and UploadBatchStore manifests.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';

function eforms_test_managed_batch_secret( $seed ) {
    return rtrim(
        strtr(
            base64_encode( str_repeat( $seed, Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ),
            '+/',
            '-_'
        ),
        '='
    );
}

function eforms_test_worker_stored_receipt( $manifest, $upload_id, $object_version, $etag, $overrides = array() ) {
    $intent = $manifest['intents'][ $upload_id ];
    return array_merge(
        array(
            'intent_id' => $intent['intent_id'],
            'batch_id' => $manifest['batch_id'],
            'upload_id' => $upload_id,
            'ordinal' => $intent['ordinal'],
            'storage_identity' => $intent['storage_identity'],
            'validation_contract_version' => $intent['validation_contract_version'],
            'object_key' => $intent['object_key'],
            'object_version' => $object_version,
            'etag' => $etag,
            'bytes' => $intent['declared_bytes'],
            'policy_fingerprint' => $intent['policy_fingerprint'],
            'expires_at' => $intent['accept_until'],
        ),
        $overrides
    );
}

function eforms_test_capacity_health( $uploads_dir ) {
    $lease = PrivateDir::acquire_write_lease( $uploads_dir );
    eforms_test_assert( $lease instanceof PrivateDirLease, 'Capacity health fixtures should acquire the lifecycle lease.' );
    try {
        return UploadBatchStore::capacity_health( $uploads_dir, $lease );
    } finally {
        $lease->release();
    }
}
