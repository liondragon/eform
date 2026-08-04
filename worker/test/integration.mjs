import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash, randomUUID } from 'node:crypto';
import {
  decodeIntegrationKey,
  signHealthRequest,
  signWorkerGalleryStatusRequest,
  signWorkerObjectRequest,
  signWorkerReviewGrant,
  signWorkerUploadGrant,
  verifyWorkerGalleryStatusResult,
  verifyHealthResult,
  verifyWorkerObjectResult,
  verifyWorkerStoredReceipt,
  workerGalleryItemsSha256,
  workerGalleryStatusRequestBodyBytes,
  workerGalleryStatusResultClaimsMatchStatuses,
} from '../src/protocol.js';
import { createManagedArtifactKey } from '../src/managed-artifact-key.js';

if (process.env.EFORMS_CF_INTEGRATION !== '1') {
  process.stdout.write('Cloudflare integration lane skipped; set EFORMS_CF_INTEGRATION=1.\n');
  process.exit(0);
}

const required = [
  'EFORMS_WORKER_URL', 'EFORMS_SITE_ORIGIN', 'EFORMS_WORKER_ENVIRONMENT_ID',
  'EFORMS_VALIDATION_CONTRACT_VERSION', 'EFORMS_WORKER_ACTIVE_KEY_ID',
  'EFORMS_WORKER_ACTIVE_KEY_B64',
];
for (const name of required) {
  if (!process.env[name]) throw new Error(`Missing integration setting: ${name}`);
}
const origin = new URL(process.env.EFORMS_WORKER_URL).origin;
assert.equal(origin, process.env.EFORMS_WORKER_URL, 'Worker URL must be an exact HTTPS origin.');
assert.equal(new URL(origin).protocol, 'https:', 'Worker URL must use HTTPS.');
const siteOrigin = new URL(process.env.EFORMS_SITE_ORIGIN).origin;
assert.equal(siteOrigin, process.env.EFORMS_SITE_ORIGIN, 'Site origin must not contain a path.');

const fixture = JSON.parse(await readFile(new URL('../../tests/fixtures/worker_protocol.json', import.meta.url), 'utf8'));
const secret = decodeIntegrationKey(process.env.EFORMS_WORKER_ACTIVE_KEY_B64);
if (!secret) throw new Error('Integration key must decode to the protocol-owned byte length.');
const keyId = process.env.EFORMS_WORKER_ACTIVE_KEY_ID;
const environment = process.env.EFORMS_WORKER_ENVIRONMENT_ID;
const keys = { [keyId]: secret };
const runId = randomUUID();
const storageIdentity = hexDigest(JSON.stringify(['worker_r2_cloudflare', origin, environment]));
const webp = Buffer.from('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAgA0JaQAA3AA/vuUAAA=', 'base64');
const inputs = [
  ['jpeg', 'image/jpeg', await fixtureBytes('oriented-landscape.jpg.b64')],
  ['png', 'image/png', await fixtureBytes('staged-landscape.png.b64')],
  ['webp', 'image/webp', webp],
  ['heic', 'image/heic', await fixtureBytes('staged-landscape.heic.b64')],
  ['heif-alias', 'image/heif', await fixtureBytes('staged-landscape.heic.b64')],
];

await assertRejectedGrant(inputs[1]);
await assertOriginAndEnvironmentMismatch(inputs[1]);
await assertHealth();
for (const input of inputs) await uploadInspectDelete(input);
await uploadInspectDelete(['boundary-jpeg', 'image/jpeg', boundaryJpeg(inputs[0][2])]);
await assertRejectedMedia(['malformed-png', 'image/png', Buffer.from('not an image')]);
await assertRejectedMedia(['animated-png', 'image/png', animatedPng(inputs[1][2])]);
await assertDiscardedResponseBodyRetry(inputs[0]);
await assertVersionMismatchIsolation(inputs[1]);
process.stdout.write('Cloudflare integration core passed: enabled formats, boundary size, malformed/animated rejection, discarded-response-body retry, origin/environment isolation, wrong-version isolation, signed health, gallery-status validation, review, and exact cleanup.\n');

async function uploadInspectDelete([label, mime, bytes]) {
  const claims = await uploadClaims(label, mime, bytes);
  const objectKey = claims.object_key;
  const grant = await signWorkerUploadGrant(claims, keyId, secret, environment);
  let authority = authorityFromUploadClaims(claims);
  try {
    const response = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(response.status, 200, `${label} upload should store and publish genuine Cloudflare validation work.`);
    const verified = await verifiedReceipt(await response.json());
    assert.equal(verified.claims.object_key, objectKey);
    assert.equal(verified.claims.bytes, bytes.byteLength);
    assert.equal(Object.hasOwn(verified.claims, 'mime'), false, 'Stored receipts must not contain validation MIME.');
    authority = authorityFromReceipt(verified.claims, authority);
    const status = await waitForGalleryStatus(label, authority, 'accepted');
    assert.ok(status.width > 0 && status.height > 0);
    if (mime === 'image/heic' || mime === 'image/heif') {
      assert.ok(['image/heic', 'image/heif'].includes(status.mime));
    } else {
      assert.equal(status.mime, mime);
    }
    await assertReview(label, authority, status.mime, bytes);
  } finally {
    // The key is unique to this disposable run. Unknown-version deletion is
    // safe here and prevents a failed receipt assertion from leaving residue.
    await deleteExact(authority);
  }
}

async function assertRejectedMedia([label, mime, bytes]) {
  const claims = await uploadClaims(label, mime, bytes);
  const grant = await signWorkerUploadGrant(claims, keyId, secret, environment);
  let authority = authorityFromUploadClaims(claims);
  try {
    const response = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(response.status, 200, `${label} upload should store before asynchronous validation rejects it.`);
    const verified = await verifiedReceipt(await response.json());
    authority = authorityFromReceipt(verified.claims, authority);
    await waitForGalleryStatus(label, authority, 'unavailable');
  } finally {
    await deleteExact(authority);
  }
}

async function assertDiscardedResponseBodyRetry([, mime, bytes]) {
  const claims = await uploadClaims('discarded-response-body', mime, bytes);
  const grant = await signWorkerUploadGrant(claims, keyId, secret, environment);
  let authority = authorityFromUploadClaims(claims);
  try {
    const discarded = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(discarded.status, 200, 'The discarded-body request must reach committed provider state.');
    await discarded.body?.cancel();

    const retry = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(retry.status, 200, 'Retry after discarding the receipt body must recover the immutable object.');
    const verified = await verifiedReceipt(await retry.json());
    assert.equal(verified.claims.object_key, claims.object_key);
    authority = authorityFromReceipt(verified.claims, authority);
    await waitForGalleryStatus('discarded-response-body', authority, 'accepted');
  } finally {
    await deleteExact(authority);
  }
}

async function assertVersionMismatchIsolation([, mime, bytes]) {
  const claims = await uploadClaims('review-delete-failure', mime, bytes);
  const grant = await signWorkerUploadGrant(claims, keyId, secret, environment);
  let authority = authorityFromUploadClaims(claims);
  try {
    const response = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(response.status, 200);
    const verified = await verifiedReceipt(await response.json());
    authority = authorityFromReceipt(verified.claims, authority);
    const status = await waitForGalleryStatus('review-delete-failure', authority, 'accepted');
    const now = Math.floor(Date.now() / 1000);
    const reviewGrant = await signWorkerReviewGrant({
      submission_id: 'integration_failure_submission',
      upload_id: claims.upload_id,
      storage_identity: authority.storage_identity,
      validation_contract_version: authority.validation_contract_version,
      object_key: claims.object_key,
      object_version: 'wrong-version',
      etag: authority.etag,
      bytes: authority.bytes,
      policy_fingerprint: authority.policy_fingerprint,
      recipe_version: 'review-jpeg-v1',
      action: 'preview',
      expires_at: now + 300,
    }, keyId, secret, environment);
    const preview = await fetch(`${origin}/v1/review?grant=${encodeURIComponent(reviewGrant)}`);
    assert.equal(preview.status, 404, 'Wrong-version preview must fail without affecting the artifact.');

    const failedDelete = await objectOperation(authority, 'delete', { object_version: 'wrong-version' });
    assert.equal(failedDelete.status, 'version_mismatch', 'Wrong-version deletion must fail closed.');
    const stillPresent = await objectOperation(authority, 'inspect');
    assert.equal(stillPresent.status, 'present', 'Failed preview/deletion must leave the exact artifact available.');
    await assertReview('failure-recovery', authority, status.mime, bytes);
  } finally {
    await deleteExact(authority);
  }
}

async function assertReview(label, authority, mime, expectedBytes) {
  const previewGrant = await reviewGrantFor(`integration_submission_${label.replace(/[^a-z0-9_-]/g, '_')}`, authority, 'preview');
  const preview = await fetch(`${origin}/v1/review?grant=${encodeURIComponent(previewGrant)}`);
  assert.equal(preview.status, 200, `${label} should produce a genuine Cloudflare preview.`);
  assert.equal(preview.headers.get('content-type'), 'image/jpeg');
  assert.equal(preview.headers.get('cache-control'), 'private, no-store, max-age=0');
  assert.ok((await preview.arrayBuffer()).byteLength > 3);

  const downloadGrant = await reviewGrantFor(`integration_submission_${label.replace(/[^a-z0-9_-]/g, '_')}`, authority, 'download');
  const download = await fetch(`${origin}/v1/review?grant=${encodeURIComponent(downloadGrant)}`);
  assert.equal(download.status, 200, `${label} authoritative download should remain available.`);
  assert.equal(download.headers.get('content-type'), mime);
  const expectedExtension = mime === 'image/jpeg' ? 'jpg' : mime.slice('image/'.length);
  assert.equal(download.headers.get('content-disposition'), `attachment; filename="submitted-image.${expectedExtension}"`);
  assert.deepEqual(Buffer.from(await download.arrayBuffer()), expectedBytes);
}

function reviewGrantFor(submissionId, authority, action) {
  return signWorkerReviewGrant({
    submission_id: submissionId,
    upload_id: authority.upload_id,
    storage_identity: authority.storage_identity,
    validation_contract_version: authority.validation_contract_version,
    object_key: authority.object_key,
    object_version: authority.object_version,
    etag: authority.etag,
    bytes: authority.bytes,
    policy_fingerprint: authority.policy_fingerprint,
    recipe_version: 'review-jpeg-v1',
    action,
    expires_at: Math.floor(Date.now() / 1000) + 300,
  }, keyId, secret, environment);
}

async function assertRejectedGrant([label, mime, bytes]) {
  const claims = await uploadClaims(`rejected-${label}`, mime, bytes);
  const grant = await signWorkerUploadGrant(claims, keyId, secret, environment);
  const [payload, signature] = grant.split('.');
  const tampered = `${payload}.${signature.startsWith('A') ? 'B' : 'A'}${signature.slice(1)}`;
  const response = await uploadRequest(mime, bytes, tampered, siteOrigin);
  assert.equal(response.status, 403, 'A tampered grant must be rejected before storage authority exists.');
}

async function assertOriginAndEnvironmentMismatch([label, mime, bytes]) {
  const originClaims = await uploadClaims(`wrong-origin-${label}`, mime, bytes);
  const originGrant = await signWorkerUploadGrant(originClaims, keyId, secret, environment);
  const wrongOrigin = await uploadRequest(mime, bytes, originGrant, 'https://wrong-origin.invalid');
  assert.equal(wrongOrigin.status, 403, 'Wrong-origin upload must fail before provider mutation.');

  const environmentClaims = await uploadClaims(`wrong-environment-${label}`, mime, bytes);
  const environmentGrant = await signWorkerUploadGrant(environmentClaims, keyId, secret, 'wrong-environment');
  const wrongEnvironment = await uploadRequest(mime, bytes, environmentGrant, siteOrigin);
  assert.equal(wrongEnvironment.status, 403, 'Cross-environment upload grant must be rejected.');
}

async function assertHealth() {
  const now = Math.floor(Date.now() / 1000);
  const claims = {
    request_id: digest(`health:${runId}`),
    storage_identity: storageIdentity,
    validation_contract_version: process.env.EFORMS_VALIDATION_CONTRACT_VERSION,
    expires_at: now + 60,
  };
  const token = await signHealthRequest(claims, keyId, secret, environment);
  const response = await fetch(`${origin}/v1/health`, {
    method: 'POST', headers: { 'X-EForms-Worker-Health': token },
  });
  assert.equal(response.status, 200, 'Signed genuine-provider health should succeed.');
  const body = await response.json();
  const verified = await verifyHealthResult(body.result, keys, environment, Math.floor(Date.now() / 1000));
  assert.equal(verified.ok, true);
  assert.equal(verified.key_id, keyId);
  assert.equal(verified.claims.storage_ready, true);
  assert.equal(verified.claims.inspection_ready, true);
  assert.equal(verified.claims.queue_producer_ready, true);
  assert.equal(verified.claims.limiter_ready, true);
  assert.equal(verified.claims.keys_ready, true);
  assert.equal(verified.claims.storage_identity_ready, true);
  assert.equal(verified.claims.validation_contract_ready, true);
}

async function deleteExact(authority) {
  const verified = await objectOperation(authority, 'delete');
  assert.equal(verified.status, 'absent');
}

async function objectOperation(authority, action, overrides = {}) {
  const operation = await rawObjectOperation(authority, action, overrides);
  assert.equal(operation.response.status, 200, `Exact integration ${action} should be reachable.`);
  const body = await operation.response.json();
  const verified = await verifyWorkerObjectResult(body.result, keys, environment, Math.floor(Date.now() / 1000));
  assert.equal(verified.ok, true);
  return verified.claims;
}

async function rawObjectOperation(authority, action, overrides = {}, signingId = keyId, signingKey = secret) {
  const now = Math.floor(Date.now() / 1000);
  const claims = {
    request_id: digest(`${action}:${runId}:${authority.object_key}:${authority.object_version}:${JSON.stringify(overrides)}`),
    batch_id: authority.batch_id,
    intent_id: authority.intent_id,
    upload_id: authority.upload_id,
    ordinal: authority.ordinal,
    storage_identity: authority.storage_identity,
    validation_contract_version: authority.validation_contract_version,
    object_key: authority.object_key,
    object_version: authority.object_version,
    etag: authority.etag,
    bytes: authority.bytes,
    policy_fingerprint: authority.policy_fingerprint,
    action,
    expires_at: now + 60,
    ...overrides,
  };
  const token = await signWorkerObjectRequest(claims, signingId, signingKey, environment);
  const response = await fetch(`${origin}/v1/object`, {
    method: 'POST', headers: { 'X-EForms-Worker-Object': token },
  });
  return { response, claims };
}

async function uploadClaims(label, mime, bytes) {
  const now = Math.floor(Date.now() / 1000);
  const intentId = digest(`intent:${runId}:${label}`);
  const batchId = digest(`batch:${runId}:${label}`);
  const base = fixture.worker_claims.upload_grant;
  const uploadUntil = now + 300;
  const acceptUntil = now + 600;
  const validationUntil = now + 900;
  return {
    intent_id: intentId,
    batch_id: batchId,
    upload_id: `integration_${label.replace(/[^a-z0-9_-]/g, '_')}`,
    ordinal: 0,
    storage_identity: storageIdentity,
    validation_contract_version: process.env.EFORMS_VALIDATION_CONTRACT_VERSION,
    object_key: await createManagedArtifactKey(batchId, 0, intentId, mime),
    declared_bytes: bytes.byteLength,
    declared_mime: mime,
    policy_fingerprint: hexDigest(`policy:${label}`),
    max_bytes: base.max_bytes,
    max_edge: base.max_edge,
    max_pixels: base.max_pixels,
    container_entry_limit: base.container_entry_limit,
    upload_until: uploadUntil,
    accept_until: acceptUntil,
    validation_until: validationUntil,
    staged_delete_after: now + 86400,
    grant_expires_at: uploadUntil,
  };
}

function uploadRequest(mime, bytes, grant, requestOrigin) {
  return fetch(`${origin}/v1/upload`, {
    method: 'PUT',
    headers: {
      Origin: requestOrigin,
      'Content-Type': mime,
      'Content-Length': String(bytes.byteLength),
      'X-EForms-Worker-Grant': grant,
    },
    body: bytes,
  });
}

async function verifiedReceipt(body) {
  const verified = await verifyWorkerStoredReceipt(body.receipt, keys, environment, Math.floor(Date.now() / 1000));
  assert.equal(verified.ok, true, 'Genuine-provider receipt must verify.');
  return verified;
}

function authorityFromUploadClaims(claims) {
  return {
    intent_id: claims.intent_id,
    batch_id: claims.batch_id,
    upload_id: claims.upload_id,
    ordinal: claims.ordinal,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    object_key: claims.object_key,
    object_version: '-',
    etag: '-',
    bytes: claims.declared_bytes,
    policy_fingerprint: claims.policy_fingerprint,
    validation_until: claims.validation_until,
  };
}

function authorityFromReceipt(claims, priorAuthority) {
  return {
    intent_id: claims.intent_id,
    batch_id: claims.batch_id,
    upload_id: claims.upload_id,
    ordinal: claims.ordinal,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    object_key: claims.object_key,
    object_version: claims.object_version,
    etag: claims.etag,
    bytes: claims.bytes,
    policy_fingerprint: claims.policy_fingerprint,
    validation_until: priorAuthority.validation_until,
  };
}

function galleryItemFromAuthority(authority) {
  return {
    upload_id: authority.upload_id,
    ordinal: authority.ordinal,
    validation_contract_version: authority.validation_contract_version,
    object_key: authority.object_key,
    object_version: authority.object_version,
    etag: authority.etag,
    bytes: authority.bytes,
    policy_fingerprint: authority.policy_fingerprint,
    validation_until: authority.validation_until,
  };
}

async function waitForGalleryStatus(label, authority, expectedStatus) {
  let last = null;
  for (let attempt = 0; attempt < 45; attempt += 1) {
    last = await galleryStatus(authority);
    if (last.status === expectedStatus) return last;
    if (last.status !== 'pending') break;
    await delay(1000);
  }
  assert.equal(last && last.status, expectedStatus, `${label} gallery-status should reach ${expectedStatus}.`);
  return last;
}

async function galleryStatus(authority) {
  const now = Math.floor(Date.now() / 1000);
  const item = galleryItemFromAuthority(authority);
  const items = [item];
  const claims = {
    request_id: digest(`gallery:${runId}:${authority.upload_id}:${now}`),
    submission_id: `gallery_${authority.upload_id}`.slice(0, 64),
    storage_identity: authority.storage_identity,
    items_sha256: await workerGalleryItemsSha256(items),
    item_count: items.length,
    expires_at: now + 60,
  };
  const token = await signWorkerGalleryStatusRequest(claims, keyId, secret, environment);
  const bytes = await workerGalleryStatusRequestBodyBytes(token, items);
  assert.ok(bytes, 'Gallery-status request must canonicalize.');
  const response = await fetch(`${origin}/v1/gallery-status`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Content-Length': String(bytes.byteLength),
    },
    body: bytes,
  });
  assert.equal(response.status, 200, 'Gallery-status request should succeed.');
  const payload = await response.json();
  const verified = await verifyWorkerGalleryStatusResult(payload.result, keys, environment, Math.floor(Date.now() / 1000));
  assert.equal(verified.ok, true, 'Gallery-status result must verify.');
  assert.equal(verified.key_id, keyId);
  assert.equal(verified.claims.request_id, claims.request_id);
  assert.equal(verified.claims.submission_id, claims.submission_id);
  assert.ok(await workerGalleryStatusResultClaimsMatchStatuses(verified.claims, payload.statuses, items));
  assert.equal(payload.statuses.length, 1);
  assert.equal(payload.statuses[0].upload_id, authority.upload_id);
  return payload.statuses[0];
}

function delay(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function boundaryJpeg(bytes) {
  const maxBytes = fixture.worker_claims.upload_grant.max_bytes;
  assert.ok(bytes.byteLength <= maxBytes);
  return Buffer.concat([bytes, Buffer.alloc(maxBytes - bytes.byteLength)]);
}

function animatedPng(bytes) {
  const idat = bytes.indexOf(Buffer.from('IDAT'));
  assert.ok(idat >= 4, 'PNG fixture must contain an IDAT chunk.');
  const chunk = pngChunk('acTL', Buffer.from([0, 0, 0, 2, 0, 0, 0, 0]));
  return Buffer.concat([bytes.subarray(0, idat - 4), chunk, bytes.subarray(idat - 4)]);
}

function pngChunk(type, data) {
  const name = Buffer.from(type, 'ascii');
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.byteLength);
  const checksum = Buffer.alloc(4);
  checksum.writeUInt32BE(crc32(Buffer.concat([name, data])));
  return Buffer.concat([length, name, data, checksum]);
}

function crc32(bytes) {
  let crc = 0xffffffff;
  for (const byte of bytes) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit += 1) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
  }
  return (crc ^ 0xffffffff) >>> 0;
}

async function fixtureBytes(name) {
  const encoded = await readFile(new URL(`../../tests/fixtures/${name}`, import.meta.url), 'utf8');
  return Buffer.from(encoded.trim(), 'base64');
}

function hexDigest(value) {
  return createHash('sha256').update(value).digest('hex');
}

function digest(value) {
  return createHash('sha256').update(value).digest('base64url');
}
