import assert from 'node:assert/strict';
import { createHash, createHmac } from 'node:crypto';
import test from 'node:test';
import {
  decodeIntegrationKey,
  keyConfiguration,
  workerGalleryItemsSha256,
  workerGalleryStatusRequestBodyBytes,
  workerGalleryStatusRequestClaimsMatchItems,
  workerGalleryStatusResultBodyBytes,
  workerGalleryStatusResultClaimsMatchStatuses,
  workerGalleryStatusesSha256,
  normalizeWorkerGalleryItems,
  normalizeWorkerGalleryStatuses,
  normalizeWorkerQueueJob,
  normalizeWorkerResultReference,
  normalizeWorkerTerminalResult,
  signWorkerGalleryStatusRequest,
  signWorkerGalleryStatusResult,
  signWorkerObjectRequest,
  signWorkerObjectResult,
  signWorkerReviewGrant,
  signWorkerStoredReceipt,
  signWorkerUploadGrant,
  signHealthResult,
  verifyWorkerGalleryStatusRequest,
  verifyWorkerGalleryStatusResult,
  verifyWorkerObjectRequest,
  verifyWorkerObjectResult,
  verifyWorkerReviewGrant,
  verifyWorkerStoredReceipt,
  verifyWorkerUploadGrant,
  verifyHealthRequest,
} from '../src/protocol.js';
import {
  MANAGED_ID_MAX_CHARS,
  MANAGED_STAGED_MAX_FILES,
  WORKER_CLOCK_SKEW_SECONDS,
  WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES,
  WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES,
  WORKER_OPAQUE_MAX_CHARS,
  WORKER_QUEUE_JOB_MAX_BYTES,
  WORKER_TERMINAL_RESULT_MAX_BYTES,
} from '../src/anchors.js';
import { createManagedArtifactKey } from '../src/managed-artifact-key.js';
import { detectedMime, extensionForMime, mimeMatches, supportedExtension, supportedMime } from '../src/media-policy.js';
import { workerTokens, fixture, fixtureToken, tokens } from './fixture.js';

const secret = decodeIntegrationKey(fixture.active_key_b64);
const keys = { [fixture.active_key_id]: secret };

test('Worker media policy owns format aliases, accepted MIME values, and download extensions', () => {
  assert.equal(detectedMime('jpg'), 'image/jpeg');
  assert.equal(detectedMime('image/heif'), 'image/heif');
  assert.equal(supportedMime('image/webp'), true);
  assert.equal(supportedMime('image/gif'), false);
  assert.equal(mimeMatches('image/heif', 'image/heic'), true);
  assert.equal(mimeMatches('image/png', 'image/jpeg'), false);
  assert.equal(extensionForMime('image/jpeg'), 'jpg');
  assert.equal(extensionForMime('image/heif'), 'heif');
  assert.equal(supportedExtension('heif'), true);
  assert.equal(supportedExtension('gif'), false);
});

test('Worker consumes and produces the canonical cross-language vectors', async () => {
  const health = await verifyHealthRequest(tokens.health_request, keys, fixture.environment, fixture.verification_now);
  assert.deepEqual(health.claims, fixture.claims.health_request);
  assert.equal(
    await signHealthResult(fixture.claims.health_result, fixture.active_key_id, secret, fixture.environment),
    tokens.health_result,
  );
});

test('Worker consumes and produces v3 Worker protocol fixtures', async () => {
  assert.equal(
    await signWorkerUploadGrant(fixture.worker_claims.upload_grant, fixture.active_key_id, secret, fixture.environment),
    workerTokens.upload_grant,
  );
  assert.deepEqual(
    await verifyWorkerUploadGrant(workerTokens.upload_grant, keys, fixture.environment, fixture.verification_now),
    { ok: true, key_id: fixture.active_key_id, claims: fixture.worker_claims.upload_grant },
  );
  assert.equal(
    await signWorkerStoredReceipt(fixture.worker_claims.stored_receipt, fixture.active_key_id, secret, fixture.environment),
    workerTokens.stored_receipt,
  );
  const receipt = await verifyWorkerStoredReceipt(workerTokens.stored_receipt, keys, fixture.environment, fixture.verification_now);
  assert.deepEqual(receipt, { ok: true, key_id: fixture.active_key_id, claims: fixture.worker_claims.stored_receipt });
  assert.equal('mime' in receipt.claims, false);
  assert.equal('width' in receipt.claims, false);
  assert.equal('height' in receipt.claims, false);
  assert.deepEqual(await normalizeWorkerQueueJob(fixture.worker_claims.queue_job), fixture.worker_claims.queue_job);
  assert.deepEqual(
    await normalizeWorkerTerminalResult(fixture.worker_claims.terminal_result_accepted),
    fixture.worker_claims.terminal_result_accepted,
  );
  assert.deepEqual(
    await normalizeWorkerTerminalResult(fixture.worker_claims.terminal_result_rejected),
    fixture.worker_claims.terminal_result_rejected,
  );
  assert.equal(
    await signWorkerGalleryStatusRequest(fixture.worker_claims.gallery_status_request, fixture.active_key_id, secret, fixture.environment),
    workerTokens.gallery_status_request,
  );
  assert.equal(
    await signWorkerGalleryStatusResult(fixture.worker_claims.gallery_status_result, fixture.active_key_id, secret, fixture.environment),
    workerTokens.gallery_status_result,
  );
  assert.equal(
    await signWorkerReviewGrant(fixture.worker_claims.review_grant, fixture.active_key_id, secret, fixture.environment),
    workerTokens.review_grant,
  );
  assert.equal(
    await signWorkerObjectRequest(fixture.worker_claims.object_request_known_delete, fixture.active_key_id, secret, fixture.environment),
    workerTokens.object_request_known_delete,
  );
  assert.equal(
    await signWorkerObjectRequest(fixture.worker_claims.object_request_unknown_delete, fixture.active_key_id, secret, fixture.environment),
    workerTokens.object_request_unknown_delete,
  );
  assert.equal(
    await signWorkerObjectRequest(fixture.worker_claims.object_request_known_inspect, fixture.active_key_id, secret, fixture.environment),
    workerTokens.object_request_known_inspect,
  );
  assert.equal(
    await signWorkerObjectResult(fixture.worker_claims.object_result, fixture.active_key_id, secret, fixture.environment),
    workerTokens.object_result,
  );
  assert.deepEqual(
    await verifyWorkerGalleryStatusRequest(workerTokens.gallery_status_request, keys, fixture.environment, fixture.verification_now),
    { ok: true, key_id: fixture.active_key_id, claims: fixture.worker_claims.gallery_status_request },
  );
  assert.deepEqual(
    await verifyWorkerGalleryStatusResult(workerTokens.gallery_status_result, keys, fixture.environment, fixture.verification_now),
    { ok: true, key_id: fixture.active_key_id, claims: fixture.worker_claims.gallery_status_result },
  );
  assert.equal(
    (await verifyWorkerGalleryStatusRequest(
      workerTokens.gallery_status_request,
      keys,
      fixture.environment,
      fixture.worker_claims.gallery_status_request.expires_at - 1,
    )).ok,
    true,
  );
  assert.equal(
    (await verifyWorkerGalleryStatusRequest(
      workerTokens.gallery_status_request,
      keys,
      fixture.environment,
      fixture.worker_claims.gallery_status_request.expires_at,
    )).ok,
    false,
  );
  assert.equal(
    (await verifyWorkerGalleryStatusResult(
      workerTokens.gallery_status_result,
      keys,
      fixture.environment,
      fixture.worker_claims.gallery_status_result.expires_at - 1,
    )).ok,
    true,
  );
  assert.equal(
    (await verifyWorkerGalleryStatusResult(
      workerTokens.gallery_status_result,
      keys,
      fixture.environment,
      fixture.worker_claims.gallery_status_result.expires_at,
    )).ok,
    false,
  );
  assert.deepEqual(
    await verifyWorkerReviewGrant(workerTokens.review_grant, keys, fixture.environment, fixture.verification_now),
    { ok: true, key_id: fixture.active_key_id, claims: fixture.worker_claims.review_grant },
  );
  assert.equal(
    (await verifyWorkerReviewGrant(workerTokens.review_grant, keys, fixture.environment, fixture.worker_claims.review_grant.expires_at - 1)).ok,
    true,
  );
  assert.equal(
    (await verifyWorkerReviewGrant(workerTokens.review_grant, keys, fixture.environment, fixture.worker_claims.review_grant.expires_at)).ok,
    false,
  );
  assert.deepEqual(
    await verifyWorkerObjectRequest(workerTokens.object_request_known_delete, keys, fixture.environment, fixture.verification_now),
    { ok: true, key_id: fixture.active_key_id, claims: fixture.worker_claims.object_request_known_delete },
  );
  assert.deepEqual(
    await verifyWorkerObjectRequest(workerTokens.object_request_unknown_delete, keys, fixture.environment, fixture.verification_now),
    { ok: true, key_id: fixture.active_key_id, claims: fixture.worker_claims.object_request_unknown_delete },
  );
  assert.deepEqual(
    await verifyWorkerObjectRequest(workerTokens.object_request_known_inspect, keys, fixture.environment, fixture.verification_now),
    { ok: true, key_id: fixture.active_key_id, claims: fixture.worker_claims.object_request_known_inspect },
  );
  assert.deepEqual(
    await verifyWorkerObjectResult(workerTokens.object_result, keys, fixture.environment, fixture.verification_now),
    { ok: true, key_id: fixture.active_key_id, claims: fixture.worker_claims.object_result },
  );
  assert.equal(
    (await verifyWorkerObjectRequest(
      workerTokens.object_request_known_delete,
      keys,
      fixture.environment,
      fixture.worker_claims.object_request_known_delete.expires_at - 1,
    )).ok,
    true,
  );
  assert.equal(
    (await verifyWorkerObjectRequest(
      workerTokens.object_request_known_delete,
      keys,
      fixture.environment,
      fixture.worker_claims.object_request_known_delete.expires_at,
    )).ok,
    false,
  );
  assert.equal(
    (await verifyWorkerObjectResult(
      workerTokens.object_result,
      keys,
      fixture.environment,
      fixture.worker_claims.object_result.expires_at - 1,
    )).ok,
    true,
  );
  assert.equal(
    (await verifyWorkerObjectResult(
      workerTokens.object_result,
      keys,
      fixture.environment,
      fixture.worker_claims.object_result.expires_at,
    )).ok,
    false,
  );
  assert.deepEqual(await normalizeWorkerGalleryItems([]), []);
  assert.deepEqual(await normalizeWorkerGalleryStatuses([]), []);
  assert.deepEqual(await normalizeWorkerGalleryItems(fixture.worker_claims.gallery_items), fixture.worker_claims.gallery_items);
  assert.deepEqual(
    await normalizeWorkerGalleryStatuses(fixture.worker_claims.gallery_statuses, fixture.worker_claims.gallery_items),
    fixture.worker_claims.gallery_statuses,
  );
  assert.notEqual(
    await normalizeWorkerGalleryStatuses([
      fixture.worker_claims.gallery_status_pending,
      fixture.worker_claims.gallery_status_unavailable,
    ]),
    null,
  );
  assert.equal(
    await workerGalleryItemsSha256(fixture.worker_claims.gallery_items),
    fixture.worker_claims.gallery_hashes.items_sha256,
  );
  assert.equal(
    await workerGalleryStatusesSha256(fixture.worker_claims.gallery_statuses, fixture.worker_claims.gallery_items),
    fixture.worker_claims.gallery_hashes.statuses_sha256,
  );
  assert.equal(
    await workerGalleryStatusRequestClaimsMatchItems(
      fixture.worker_claims.gallery_status_request,
      fixture.worker_claims.gallery_items,
    ),
    true,
  );
  assert.equal(
    await workerGalleryStatusResultClaimsMatchStatuses(
      fixture.worker_claims.gallery_status_result,
      fixture.worker_claims.gallery_statuses,
      fixture.worker_claims.gallery_items,
    ),
    true,
  );
  assert.notEqual(
    await workerGalleryStatusRequestBodyBytes(workerTokens.gallery_status_request, fixture.worker_claims.gallery_items),
    null,
  );
  assert.notEqual(
    await workerGalleryStatusResultBodyBytes(
      workerTokens.gallery_status_result,
      fixture.worker_claims.gallery_statuses,
      fixture.worker_claims.gallery_items,
    ),
    null,
  );
  const maxGalleryItems = await workerGalleryItems(MANAGED_STAGED_MAX_FILES);
  const maxGalleryStatuses = workerGalleryStatuses(maxGalleryItems);
  assert.equal((await normalizeWorkerGalleryItems(maxGalleryItems)).length, MANAGED_STAGED_MAX_FILES);
  assert.equal((await normalizeWorkerGalleryStatuses(maxGalleryStatuses, maxGalleryItems)).length, MANAGED_STAGED_MAX_FILES);
  const maxGalleryRequestClaims = {
    ...fixture.worker_claims.gallery_status_request,
    items_sha256: await workerGalleryItemsSha256(maxGalleryItems),
    item_count: MANAGED_STAGED_MAX_FILES,
  };
  const maxGalleryResultClaims = {
    ...fixture.worker_claims.gallery_status_result,
    items_sha256: maxGalleryRequestClaims.items_sha256,
    statuses_sha256: await workerGalleryStatusesSha256(maxGalleryStatuses, maxGalleryItems),
    item_count: MANAGED_STAGED_MAX_FILES,
  };
  const maxGalleryRequest = await signWorkerGalleryStatusRequest(
    maxGalleryRequestClaims,
    fixture.active_key_id,
    secret,
    fixture.environment,
  );
  const maxGalleryResult = await signWorkerGalleryStatusResult(
    maxGalleryResultClaims,
    fixture.active_key_id,
    secret,
    fixture.environment,
  );
  const maxRequestBytes = (await workerGalleryStatusRequestBodyBytes(maxGalleryRequest, maxGalleryItems)).byteLength;
  const maxResultBytes = (await workerGalleryStatusResultBodyBytes(maxGalleryResult, maxGalleryStatuses, maxGalleryItems)).byteLength;
  assert.ok(maxRequestBytes > 0 && maxRequestBytes <= WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES);
  assert.ok(maxResultBytes > 0 && maxResultBytes <= WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES);
  assert.equal(await normalizeWorkerGalleryItems(await workerGalleryItems(MANAGED_STAGED_MAX_FILES + 1)), null);
  assert.equal(await normalizeWorkerGalleryStatuses(workerGalleryStatuses(await workerGalleryItems(MANAGED_STAGED_MAX_FILES + 1))), null);
  assert.ok(new TextEncoder().encode(JSON.stringify(fixture.worker_claims.queue_job)).byteLength < WORKER_QUEUE_JOB_MAX_BYTES);
  assert.ok(
    new TextEncoder().encode(JSON.stringify(fixture.worker_claims.terminal_result_accepted)).byteLength
      < WORKER_TERMINAL_RESULT_MAX_BYTES,
  );
});


test('Worker rejects malformed v3 Worker protocol shapes', async () => {
  assert.equal(
    await signWorkerUploadGrant(
      { ...fixture.worker_claims.upload_grant, grant_expires_at: fixture.worker_claims.upload_grant.upload_until + 1 },
      fixture.active_key_id,
      secret,
      fixture.environment,
    ),
    '',
  );
  assert.equal(
    await signWorkerStoredReceipt(
      { ...fixture.worker_claims.stored_receipt, mime: 'image/png' },
      fixture.active_key_id,
      secret,
      fixture.environment,
    ),
    '',
  );
  const oldReceiptClaims = {
    intent_id: fixture.worker_claims.stored_receipt.intent_id,
    batch_id: fixture.worker_claims.stored_receipt.batch_id,
    upload_id: fixture.worker_claims.stored_receipt.upload_id,
    ordinal: fixture.worker_claims.stored_receipt.ordinal,
    object_key: fixture.worker_claims.stored_receipt.object_key,
    object_version: fixture.worker_claims.stored_receipt.object_version,
    etag: fixture.worker_claims.stored_receipt.etag,
    bytes: fixture.worker_claims.stored_receipt.bytes,
    mime: 'image/png',
    width: 32,
    height: 24,
    policy_fingerprint: fixture.worker_claims.stored_receipt.policy_fingerprint,
    expires_at: fixture.worker_claims.stored_receipt.expires_at,
  };
  const oldReceiptAsWorker = signedParts([
    'eforms-worker-stored-receipt',
    '3',
    fixture.active_key_id,
    fixture.environment,
    ...Object.values(oldReceiptClaims).map(String),
  ]);
  assert.equal((await verifyWorkerStoredReceipt(oldReceiptAsWorker, keys, fixture.environment, fixture.verification_now)).ok, false);
  const mixedVersionReceipt = signedParts([
    'eforms-worker-stored-receipt',
    '2',
    fixture.active_key_id,
    fixture.environment,
    ...Object.values(fixture.worker_claims.stored_receipt).map(String),
  ]);
  assert.equal((await verifyWorkerStoredReceipt(mixedVersionReceipt, keys, fixture.environment, fixture.verification_now)).ok, false);
  assert.equal(
    (await verifyWorkerGalleryStatusRequest(
      workerTokens.gallery_status_request,
      keys,
      'wrong-environment',
      fixture.verification_now,
    )).ok,
    false,
  );
  assert.equal(
    (await verifyWorkerGalleryStatusResult(
      workerTokens.gallery_status_result,
      keys,
      fixture.environment,
      fixture.worker_claims.gallery_status_result.expires_at + WORKER_CLOCK_SKEW_SECONDS + 1,
    )).ok,
    false,
  );
  assert.equal(
    (await verifyWorkerReviewGrant(
      workerTokens.review_grant,
      keys,
      fixture.environment,
      fixture.worker_claims.review_grant.expires_at + WORKER_CLOCK_SKEW_SECONDS + 1,
    )).ok,
    false,
  );
  assert.equal(
    (await verifyWorkerGalleryStatusResult(workerTokens.gallery_status_request, keys, fixture.environment, fixture.verification_now)).ok,
    false,
  );
  assert.equal(
    (await verifyWorkerGalleryStatusRequest(
      signedParts([
        'eforms-worker-gallery-status-result',
        '3',
        fixture.active_key_id,
        fixture.environment,
        ...Object.values(fixture.worker_claims.gallery_status_request).map(String),
      ]),
      keys,
      fixture.environment,
      fixture.verification_now,
    )).ok,
    false,
  );
  assert.equal(
    (await verifyWorkerGalleryStatusRequest(
      signedParts([
        'eforms-worker-gallery-status-request',
        '2',
        fixture.active_key_id,
        fixture.environment,
        ...Object.values(fixture.worker_claims.gallery_status_request).map(String),
      ]),
      keys,
      fixture.environment,
      fixture.verification_now,
    )).ok,
    false,
  );
  const oldReviewGrant = {
    submission_id: fixture.worker_claims.review_grant.submission_id,
    upload_id: fixture.worker_claims.review_grant.upload_id,
    object_key: fixture.worker_claims.review_grant.object_key,
    object_version: fixture.worker_claims.review_grant.object_version,
    action: fixture.worker_claims.review_grant.action,
    recipe_version: fixture.worker_claims.review_grant.recipe_version,
    expires_at: fixture.worker_claims.review_grant.expires_at,
  };
  assert.equal(
    (await verifyWorkerReviewGrant(
      signedParts([
        'eforms-worker-review-grant',
        '3',
        fixture.active_key_id,
        fixture.environment,
        ...Object.values(oldReviewGrant).map(String),
      ]),
      keys,
      fixture.environment,
      fixture.verification_now,
    )).ok,
    false,
  );
  assert.equal(await normalizeWorkerQueueJob({ ...fixture.worker_claims.queue_job, protocol_version: 2 }), null);
  assert.equal(await normalizeWorkerQueueJob({ ...fixture.worker_claims.queue_job, bytes: String(fixture.worker_claims.queue_job.bytes) }), null);
  assert.equal(await normalizeWorkerQueueJob({ ...fixture.worker_claims.queue_job, filename: 'customer.png' }), null);
  assert.equal(
    await normalizeWorkerQueueJob({
      ...fixture.worker_claims.queue_job,
      validation_contract_version: 'v'.repeat(WORKER_QUEUE_JOB_MAX_BYTES),
    }),
    null,
  );
  assert.equal(
    await normalizeWorkerTerminalResult({ ...fixture.worker_claims.terminal_result_accepted, reason: 'unsupported_media' }),
    null,
  );
  assert.equal(
    await normalizeWorkerTerminalResult({ ...fixture.worker_claims.terminal_result_rejected, mime: 'image/png' }),
    null,
  );
  assert.equal(
    await normalizeWorkerTerminalResult({
      ...fixture.worker_claims.terminal_result_accepted,
      validation_contract_version: 'v'.repeat(WORKER_TERMINAL_RESULT_MAX_BYTES),
    }),
    null,
  );
  assert.equal(await normalizeWorkerGalleryItems([{ ...fixture.worker_claims.gallery_items[0], bytes: String(fixture.worker_claims.gallery_items[0].bytes) }]), null);
  assert.equal(await normalizeWorkerGalleryItems([{ ...fixture.worker_claims.gallery_items[0], filename: 'customer.png' }]), null);
  assert.equal(
    await normalizeWorkerGalleryItems([{ ...fixture.worker_claims.gallery_items[0], upload_id: 'u'.repeat(MANAGED_ID_MAX_CHARS + 1) }]),
    null,
  );
  const maxGalleryItems = await workerGalleryItems(MANAGED_STAGED_MAX_FILES);
  assert.equal(await normalizeWorkerGalleryItems([...maxGalleryItems].reverse()), null);
  assert.equal(
    await normalizeWorkerGalleryItems([fixture.worker_claims.gallery_items[0], fixture.worker_claims.gallery_items[0]]),
    null,
  );
  assert.equal(
    await normalizeWorkerGalleryItems([
      maxGalleryItems[0],
      { ...maxGalleryItems[1], upload_id: maxGalleryItems[0].upload_id },
    ]),
    null,
  );
  assert.equal(
    await normalizeWorkerGalleryItems([
      maxGalleryItems[0],
      { ...maxGalleryItems[1], ordinal: maxGalleryItems[0].ordinal, object_key: maxGalleryItems[0].object_key },
    ]),
    null,
  );
  assert.equal(
    await normalizeWorkerGalleryItems([{ ...fixture.worker_claims.gallery_items[0], ordinal: fixture.worker_claims.gallery_items[0].ordinal + 1 }]),
    null,
  );
  assert.equal(
    await normalizeWorkerGalleryItems([
      maxGalleryItems[0],
      {
        ...maxGalleryItems[1],
        object_key: await createManagedArtifactKey(
          digest('mixed-gallery-namespace'),
          maxGalleryItems[1].ordinal,
          digest('mixed-gallery-intent'),
          'image/png',
        ),
      },
    ]),
    null,
  );
  assert.equal(
    await normalizeWorkerGalleryStatuses([{ ...fixture.worker_claims.gallery_statuses[0], width: String(fixture.worker_claims.gallery_statuses[0].width) }]),
    null,
  );
  assert.equal(
    await normalizeWorkerGalleryStatuses([{ ...fixture.worker_claims.gallery_status_pending, mime: 'image/png' }]),
    null,
  );
  const acceptedWithoutHeight = { ...fixture.worker_claims.gallery_statuses[0] };
  delete acceptedWithoutHeight.height;
  assert.equal(await normalizeWorkerGalleryStatuses([acceptedWithoutHeight]), null);
  assert.equal(
    await normalizeWorkerGalleryStatuses(
      [{ ...fixture.worker_claims.gallery_statuses[0], upload_id: 'different_upload' }],
      fixture.worker_claims.gallery_items,
    ),
    null,
  );
  assert.equal(
    await workerGalleryStatusRequestClaimsMatchItems(
      { ...fixture.worker_claims.gallery_status_request, item_count: 2 },
      fixture.worker_claims.gallery_items,
    ),
    false,
  );
  assert.equal(
    await workerGalleryStatusResultClaimsMatchStatuses(
      { ...fixture.worker_claims.gallery_status_result, statuses_sha256: '0'.repeat(64) },
      fixture.worker_claims.gallery_statuses,
      fixture.worker_claims.gallery_items,
    ),
    false,
  );
  assert.equal(
    await workerGalleryStatusResultClaimsMatchStatuses(
      { ...fixture.worker_claims.gallery_status_result, item_count: 2 },
      fixture.worker_claims.gallery_statuses,
      fixture.worker_claims.gallery_items,
    ),
    false,
  );
  assert.equal(
    await workerGalleryStatusResultClaimsMatchStatuses(
      { ...fixture.worker_claims.gallery_status_result, items_sha256: '0'.repeat(64) },
      fixture.worker_claims.gallery_statuses,
      fixture.worker_claims.gallery_items,
    ),
    false,
  );
  assert.equal(
    await workerGalleryStatusRequestBodyBytes('x'.repeat(WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES), fixture.worker_claims.gallery_items),
    null,
  );
  assert.equal(
    await workerGalleryStatusResultBodyBytes(
      'x'.repeat(WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES),
      fixture.worker_claims.gallery_statuses,
      fixture.worker_claims.gallery_items,
    ),
    null,
  );
  assert.equal(await signWorkerReviewGrant({ ...fixture.worker_claims.review_grant, mime: 'image/png' }, fixture.active_key_id, secret, fixture.environment), '');
  assert.equal(
    await signWorkerObjectRequest(
      { ...fixture.worker_claims.object_request_unknown_delete, etag: 'worker-etag-v1' },
      fixture.active_key_id,
      secret,
      fixture.environment,
    ),
    '',
  );
  assert.equal(
    await signWorkerObjectRequest(
      { ...fixture.worker_claims.object_request_unknown_delete, action: 'inspect' },
      fixture.active_key_id,
      secret,
      fixture.environment,
    ),
    '',
  );
  assert.equal(
    await signWorkerObjectRequest(
      { ...fixture.worker_claims.object_request_known_delete, ordinal: 3 },
      fixture.active_key_id,
      secret,
      fixture.environment,
    ),
    '',
  );
  assert.equal(
    await signWorkerObjectRequest(
      { ...fixture.worker_claims.object_request_known_delete, validation_until: 2000001800 },
      fixture.active_key_id,
      secret,
      fixture.environment,
    ),
    '',
  );
  assert.equal(
    (await verifyWorkerObjectRequest(
      fixtureToken(
        'eforms-worker-object-request',
        fixture.worker_claims.object_request_known_delete,
        fixture.worker_vectors.object_request_known_delete.signature_b64,
        '2',
      ),
      keys,
      fixture.environment,
      fixture.verification_now,
    )).ok,
    false,
  );
  assert.deepEqual(await normalizeWorkerResultReference(workerResultReference()), workerResultReference());
  assert.deepEqual(await normalizeWorkerResultReference(reviewResultReference()), reviewResultReference());
  assert.equal(await normalizeWorkerResultReference({ ...workerResultReference(), filename: 'customer.png' }), null);
  assert.equal(await normalizeWorkerResultReference({ ...workerResultReference(), bytes: String(workerResultReference().bytes) }), null);
  assert.equal(await normalizeWorkerResultReference({ ...workerResultReference(), ordinal: String(workerResultReference().ordinal) }), null);
});

test('Worker rejects old opaque object keys on signed object surfaces', async () => {
  const oldKey = oldOpaqueObjectKey(fixture.worker_claims.upload_grant.batch_id, fixture.worker_claims.upload_grant.intent_id);
  const checks = [
    [
      verifyWorkerUploadGrant,
      'eforms-worker-upload-grant',
      { ...fixture.worker_claims.upload_grant, object_key: oldKey },
    ],
    [
      verifyWorkerReviewGrant,
      'eforms-worker-review-grant',
      { ...fixture.worker_claims.review_grant, object_key: oldKey },
    ],
    [
      verifyWorkerObjectRequest,
      'eforms-worker-object-request',
      { ...fixture.worker_claims.object_request_known_delete, object_key: oldKey },
    ],
  ];
  for (const [verifySurface, domain, claims] of checks) {
    const verified = await verifySurface(
      signedParts([domain, '3', fixture.active_key_id, fixture.environment, ...Object.values(claims).map(String)]),
      keys,
      fixture.environment,
      fixture.verification_now,
    );
    assert.equal(verified.ok, false, domain);
  }
});


test('Worker key configuration is strict and supports one secondary verifier', () => {
  const env = {
    EFORMS_WORKER_ENVIRONMENT_ID: fixture.environment,
    EFORMS_WORKER_ACTIVE_KEY_ID: fixture.active_key_id,
    EFORMS_WORKER_ACTIVE_KEY_B64: fixture.active_key_b64,
    EFORMS_WORKER_SECONDARY_KEY_ID: 'key-secondary',
    EFORMS_WORKER_SECONDARY_KEY_B64: 'ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8',
  };
  assert.deepEqual(Object.keys(keyConfiguration(env).keys), [fixture.active_key_id, 'key-secondary']);
  assert.equal(keyConfiguration({ ...env, EFORMS_WORKER_SECONDARY_KEY_ID: fixture.active_key_id }), null);
  assert.equal(decodeIntegrationKey(`${fixture.active_key_b64}=`), null);
});

function signedParts(parts) {
  const encoded = parts.map((part) => new TextEncoder().encode(part));
  const payload = Buffer.alloc(encoded.reduce((total, part) => total + 4 + part.byteLength, 0));
  let offset = 0;
  for (const part of encoded) {
    payload.writeUInt32BE(part.byteLength, offset);
    offset += 4;
    Buffer.from(part).copy(payload, offset);
    offset += part.byteLength;
  }
  return `${payload.toString('base64url')}.${createHmac('sha256', Buffer.from(secret)).update(payload).digest('base64url')}`;
}

function wrongShardKey(objectKey) {
  return objectKey.replace(/^artifacts\/([0-9a-f]{2})\//, (_match, shard) => `artifacts/${shard === '00' ? '01' : '00'}/`);
}

function oldOpaqueObjectKey(batchId, intentId) {
  const identity = createHash('sha256').update(`${batchId}\0${intentId}`).digest('hex');
  return `artifacts/${createHash('sha256').update(identity).digest('hex').slice(0, 2)}/${identity}`;
}

function digest(label) {
  return createHash('sha256').update(label).digest('base64url');
}

async function workerGalleryItems(count) {
  const items = [];
  const batchId = 'b'.repeat(fixture.worker_claims.upload_grant.batch_id.length);
  for (let index = 0; index < count; index += 1) {
    const intentId = `${'i'.repeat(fixture.worker_claims.upload_grant.intent_id.length)}${index}`.slice(-fixture.worker_claims.upload_grant.intent_id.length);
    items.push({
      upload_id: `upload_${String(index).padStart(120, 'u')}`,
      ordinal: index,
      validation_contract_version: 'v'.repeat(WORKER_OPAQUE_MAX_CHARS),
      object_key: await createManagedArtifactKey(batchId, index, intentId, 'image/png'),
      object_version: 'o'.repeat(WORKER_OPAQUE_MAX_CHARS),
      etag: 'e'.repeat(WORKER_OPAQUE_MAX_CHARS),
      bytes: Number.MAX_SAFE_INTEGER,
      policy_fingerprint: 'd'.repeat(64),
      validation_until: Number.MAX_SAFE_INTEGER,
    });
  }
  return items;
}

function workerGalleryStatuses(items) {
  return items.map((item) => ({
    upload_id: item.upload_id,
    status: 'accepted',
    mime: 'image/png',
    width: Number.MAX_SAFE_INTEGER,
    height: Number.MAX_SAFE_INTEGER,
  }));
}

function workerResultReference(overrides = {}) {
  const item = fixture.worker_claims.gallery_items[0];
  return {
    environment_id: fixture.environment,
    storage_identity: fixture.worker_claims.review_grant.storage_identity,
    validation_contract_version: item.validation_contract_version,
    upload_id: item.upload_id,
    ordinal: item.ordinal,
    object_key: item.object_key,
    object_version: item.object_version,
    etag: item.etag,
    bytes: item.bytes,
    policy_fingerprint: item.policy_fingerprint,
    ...overrides,
  };
}

function reviewResultReference(overrides = {}) {
  const { ordinal: _ordinal, ...reference } = workerResultReference(overrides);
  return reference;
}
