import assert from 'node:assert/strict';
import { accessSync, constants as fsConstants } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { createHash, randomUUID } from 'node:crypto';
import { isAbsolute, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import {
  decodeIntegrationKey,
  signHealthRequest,
  signObjectRequest,
  signReviewGrant,
  signUploadGrant,
  verifyHealthResult,
  verifyObjectResult,
  verifyUploadReceipt,
} from '../src/protocol.js';

if (process.env.EFORMS_CF_INTEGRATION !== '1') {
  process.stdout.write('Cloudflare integration lane skipped; set EFORMS_CF_INTEGRATION=1.\n');
  process.exit(0);
}

const required = [
  'EFORMS_WORKER_URL', 'EFORMS_SITE_ORIGIN', 'EFORMS_WORKER_ENVIRONMENT_ID',
  'EFORMS_WORKER_ACTIVE_KEY_ID', 'EFORMS_WORKER_ACTIVE_KEY_B64',
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
const pairFingerprint = hexDigest(JSON.stringify(['wordpress_worker_pair', siteOrigin, origin, environment]));
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
if (process.env.EFORMS_CF_REPRESENTATIVE_MEDIA === '1') {
  for (const input of await representativeInputs()) await uploadInspectDelete(input);
  process.stdout.write('Cloudflare representative-media lane passed: externally supplied non-customer phone JPEG, PNG, WebP, HEIC, and HEIF fixtures.\n');
} else {
  process.stdout.write('Cloudflare representative-media acceptance is incomplete; set EFORMS_CF_REPRESENTATIVE_MEDIA=1 and EFORMS_CF_REPRESENTATIVE_DIR.\n');
}
await uploadInspectDelete(['boundary-jpeg', 'image/jpeg', boundaryJpeg(inputs[0][2])]);
await assertRejectedMedia(['malformed-png', 'image/png', Buffer.from('not an image')]);
await assertRejectedMedia(['animated-png', 'image/png', animatedPng(inputs[1][2])]);
await assertDiscardedResponseBodyRetry(inputs[0]);
await assertVersionMismatchIsolation(inputs[1]);
process.stdout.write('Cloudflare integration core passed: enabled formats, boundary size, malformed/animated rejection, discarded-response-body retry, origin/environment isolation, wrong-version isolation, signed health, and exact cleanup. Transport-level response-loss injection remains a Phase 6 evidence gate.\n');
if (process.env.EFORMS_CF_FAILURE_MATRIX === '1') {
  await assertControlledProviderFailures(inputs[1]);
  process.stdout.write('Cloudflare provider-failure matrix passed: genuine Images and R2 failures remained retryable, preserved the exact artifact, recovered, and cleaned up exactly.\n');
} else {
  process.stdout.write('Cloudflare provider-failure acceptance is incomplete; set EFORMS_CF_FAILURE_MATRIX=1 and EFORMS_CF_FAULT_COMMAND.\n');
}
if (process.env.EFORMS_CF_ROTATION_MATRIX === '1') {
  await assertRotationDrill(inputs[1]);
  process.stdout.write('Cloudflare key-rotation matrix passed: overlap, promotion, old-key removal, emergency cutover, retained-object access, restoration, and exact cleanup.\n');
} else {
  process.stdout.write('Cloudflare key-rotation acceptance is incomplete; set EFORMS_CF_ROTATION_MATRIX=1 with the rotation controller and disposable keys.\n');
}

async function uploadInspectDelete([label, mime, bytes]) {
  const claims = uploadClaims(label, mime, bytes);
  const objectKey = claims.object_key;
  const grant = await signUploadGrant(claims, keyId, secret, environment);
  let version = '';
  try {
    const response = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(response.status, 200, `${label} upload should be accepted by genuine Cloudflare inspection.`);
    const verified = await verifiedReceipt(await response.json());
    assert.equal(verified.claims.object_key, objectKey);
    assert.equal(verified.claims.bytes, bytes.byteLength);
    assert.ok(verified.claims.width > 0 && verified.claims.height > 0);
    if (mime === 'image/heic' || mime === 'image/heif') {
      assert.ok(['image/heic', 'image/heif'].includes(verified.claims.mime));
    } else {
      assert.equal(verified.claims.mime, mime);
    }
    version = verified.claims.object_version;
    await assertReview(label, objectKey, version, verified.claims.mime, bytes);
  } finally {
    // The key is unique to this disposable run. Unknown-version deletion is
    // safe here and prevents a failed receipt assertion from leaving residue.
    await deleteExact(objectKey, version || '-');
  }
}

async function assertRejectedMedia([label, mime, bytes]) {
  const claims = uploadClaims(label, mime, bytes);
  const grant = await signUploadGrant(claims, keyId, secret, environment);
  try {
    const response = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(response.status, 422, `${label} must fail genuine provider inspection.`);
  } finally {
    await deleteExact(claims.object_key, '-');
  }
}

async function assertDiscardedResponseBodyRetry([, mime, bytes]) {
  const claims = uploadClaims('discarded-response-body', mime, bytes);
  const grant = await signUploadGrant(claims, keyId, secret, environment);
  let version = '';
  try {
    const discarded = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(discarded.status, 200, 'The discarded-body request must reach committed provider state.');
    await discarded.body?.cancel();

    const retry = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(retry.status, 200, 'Retry after discarding the receipt body must recover the immutable object.');
    const verified = await verifiedReceipt(await retry.json());
    assert.equal(verified.claims.object_key, claims.object_key);
    version = verified.claims.object_version;
  } finally {
    await deleteExact(claims.object_key, version || '-');
  }
}

async function assertVersionMismatchIsolation([, mime, bytes]) {
  const claims = uploadClaims('review-delete-failure', mime, bytes);
  const grant = await signUploadGrant(claims, keyId, secret, environment);
  let version = '';
  try {
    const response = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(response.status, 200);
    const verified = await verifiedReceipt(await response.json());
    version = verified.claims.object_version;
    const now = Math.floor(Date.now() / 1000);
    const reviewGrant = await signReviewGrant({
      submission_id: 'integration_failure_submission',
      upload_id: claims.upload_id,
      object_key: claims.object_key,
      object_version: 'wrong-version',
      recipe_version: 'review-jpeg-v1',
      action: 'preview',
      expires_at: now + 300,
    }, keyId, secret, environment);
    const preview = await fetch(`${origin}/v1/review?grant=${encodeURIComponent(reviewGrant)}`);
    assert.equal(preview.status, 404, 'Wrong-version preview must fail without affecting the artifact.');

    const failedDelete = await objectOperation(claims.object_key, 'wrong-version', 'delete');
    assert.equal(failedDelete.status, 'version_mismatch', 'Wrong-version deletion must fail closed.');
    const stillPresent = await objectOperation(claims.object_key, version, 'inspect');
    assert.equal(stillPresent.status, 'present', 'Failed preview/deletion must leave the exact artifact available.');
    await assertReview('failure-recovery', claims.object_key, version, verified.claims.mime, bytes);
  } finally {
    await deleteExact(claims.object_key, version || '-');
  }
}

async function assertControlledProviderFailures([, mime, bytes]) {
  const command = process.env.EFORMS_CF_FAULT_COMMAND;
  assert.ok(command && isAbsolute(command), 'EFORMS_CF_FAULT_COMMAND must be an absolute executable path.');
  accessSync(command, fsConstants.X_OK);
  const evidence = [];
  const claims = uploadClaims('controlled-provider-failures', mime, bytes);
  const grant = await signUploadGrant(claims, keyId, secret, environment);
  let version = '';
  let primaryError = null;
  try {
    const response = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(response.status, 200);
    const verified = await verifiedReceipt(await response.json());
    version = verified.claims.object_version;
    const reviewGrant = await reviewGrantFor('controlled_provider_failure', claims.upload_id, claims.object_key, version, 'preview');

    evidence.push(setFault(command, 'preview-failure'));
    const failedPreview = await fetch(`${origin}/v1/review?grant=${encodeURIComponent(reviewGrant)}`);
    assert.equal(failedPreview.status, 503, 'Injected genuine Images failure must remain retryable.');
    evidence.push(setFault(command, 'clear'));
    await assertReview('provider-failure-recovery', claims.object_key, version, verified.claims.mime, bytes);

    evidence.push(setFault(command, 'delete-failure'));
    const failedDelete = await rawObjectOperation(claims.object_key, version, 'delete');
    assert.equal(failedDelete.response.status, 503, 'Injected genuine R2 deletion failure must remain retryable.');
    evidence.push(setFault(command, 'clear'));
    const stillPresent = await objectOperation(claims.object_key, version, 'inspect');
    assert.equal(stillPresent.status, 'present', 'Provider deletion failure must not claim absence.');
  } catch (error) {
    primaryError = error;
  }
  const cleanupErrors = [];
  let cleared = false;
  for (let attempt = 0; attempt < 2 && !cleared; attempt += 1) {
    try {
      evidence.push(setFault(command, 'clear'));
      cleared = true;
    } catch {
      if (attempt === 1) cleanupErrors.push(new Error('fault_controller_clear_failed; manually restore the disposable deployment mode'));
    }
  }
  try {
    await deleteExact(claims.object_key, version || '-');
  } catch {
    cleanupErrors.push(new Error(`provider_cleanup_failed for disposable object ${claims.object_key}`));
  }
  const failures = [primaryError, ...cleanupErrors].filter(Boolean);
  if (failures.length) throw new AggregateError(failures, 'Controlled provider-failure acceptance did not finish cleanly.');
  process.stdout.write(`EFORMS_CF_FAULT_EVIDENCE ${JSON.stringify(evidence)}\n`);
}

async function assertRotationDrill([, mime, bytes]) {
  const command = process.env.EFORMS_CF_ROTATION_COMMAND;
  const wordpressCommand = process.env.EFORMS_CF_WORDPRESS_ROTATION_PROBE_COMMAND;
  assert.ok(command && isAbsolute(command), 'EFORMS_CF_ROTATION_COMMAND must be an absolute executable path.');
  assert.ok(wordpressCommand && isAbsolute(wordpressCommand), 'EFORMS_CF_WORDPRESS_ROTATION_PROBE_COMMAND must be an absolute executable path.');
  accessSync(command, fsConstants.X_OK);
  accessSync(wordpressCommand, fsConstants.X_OK);
  const secondaryId = requiredKeyId('EFORMS_CF_SECONDARY_KEY_ID');
  const secondary = requiredKey('EFORMS_CF_SECONDARY_KEY_B64');
  const emergencyId = requiredKeyId('EFORMS_CF_EMERGENCY_KEY_ID');
  const emergency = requiredKey('EFORMS_CF_EMERGENCY_KEY_B64');
  assert.equal(new Set([keyId, secondaryId, emergencyId]).size, 3, 'Rotation key IDs must be distinct.');
  const rotationKeys = { [keyId]: secret, [secondaryId]: secondary, [emergencyId]: emergency };
  const evidence = [];
  const claims = uploadClaims('rotation-retained-object', mime, bytes);
  const grant = await signUploadGrant(claims, keyId, secret, environment);
  let version = '';
  let primaryError = null;
  let targetId = '';
  try {
    const uploaded = await uploadRequest(mime, bytes, grant, siteOrigin);
    assert.equal(uploaded.status, 200);
    const receipt = await verifiedReceipt(await uploaded.json());
    version = receipt.claims.object_version;

    const installed = setRotation(command, 'install-secondary');
    targetId = assertRotationTarget(installed, targetId);
    evidence.push(installed);
    probeWordPressRotation(wordpressCommand, 'install-secondary', keyId, [secondaryId]);
    await assertHealthWithKey(keyId, secret, rotationKeys, keyId);
    await assertHealthWithKey(secondaryId, secondary, rotationKeys, keyId);
    await assertObjectPresentWithKey(claims.object_key, version, keyId, secret, rotationKeys, keyId);

    const promoted = setRotation(command, 'promote-secondary');
    assertRotationTarget(promoted, targetId);
    evidence.push(promoted);
    probeWordPressRotation(wordpressCommand, 'promote-secondary', secondaryId, [keyId]);
    await assertHealthWithKey(secondaryId, secondary, rotationKeys, secondaryId);
    await assertObjectPresentWithKey(claims.object_key, version, keyId, secret, rotationKeys, secondaryId);

    const removed = setRotation(command, 'remove-old');
    assertRotationTarget(removed, targetId);
    evidence.push(removed);
    probeWordPressRotation(wordpressCommand, 'remove-old', secondaryId, []);
    const removedOld = await rawObjectOperation(claims.object_key, version, 'inspect', keyId, secret);
    assert.equal(removedOld.response.status, 403, 'Removed old key must no longer authorize retained-object access.');
    await assertObjectPresentWithKey(claims.object_key, version, secondaryId, secondary, rotationKeys, secondaryId);

    const emergencyCutover = setRotation(command, 'emergency-cutover');
    assertRotationTarget(emergencyCutover, targetId);
    evidence.push(emergencyCutover);
    probeWordPressRotation(wordpressCommand, 'emergency-cutover', emergencyId, []);
    for (const [retiredId, retiredKey] of [[keyId, secret], [secondaryId, secondary]]) {
      const retired = await rawObjectOperation(claims.object_key, version, 'inspect', retiredId, retiredKey);
      assert.equal(retired.response.status, 403, 'Emergency cutover must reject every retired key.');
    }
    await assertObjectPresentWithKey(claims.object_key, version, emergencyId, emergency, rotationKeys, emergencyId);
  } catch (error) {
    primaryError = error;
  }

  const cleanupErrors = [];
  let restored = false;
  try {
    const restore = setRotation(command, 'restore');
    assertRotationTarget(restore, targetId);
    evidence.push(restore);
    probeWordPressRotation(wordpressCommand, 'restore', keyId, []);
    await assertHealthWithKey(keyId, secret, rotationKeys, keyId);
    await assertObjectPresentWithKey(claims.object_key, version, keyId, secret, rotationKeys, keyId);
    for (const [retiredId, retiredKey] of [[secondaryId, secondary], [emergencyId, emergency]]) {
      const retired = await rawObjectOperation(claims.object_key, version, 'inspect', retiredId, retiredKey);
      assert.equal(retired.response.status, 403, 'Restoration must reject every retired rotation key.');
    }
    restored = true;
  } catch {
    cleanupErrors.push(new Error('rotation_restore_failed; manually restore the disposable deployment key state'));
  }
  try {
    if (restored) {
      await deleteExact(claims.object_key, version || '-');
    } else {
      await deleteWithAnyKey(claims.object_key, version || '-', [[emergencyId, emergency], [secondaryId, secondary], [keyId, secret]], rotationKeys);
    }
  } catch {
    cleanupErrors.push(new Error(`rotation_cleanup_failed for disposable object ${claims.object_key}`));
  }
  const failures = [primaryError, ...cleanupErrors].filter(Boolean);
  if (failures.length) throw new AggregateError(failures, 'Key-rotation acceptance did not finish cleanly.');
  process.stdout.write(`EFORMS_CF_ROTATION_EVIDENCE ${JSON.stringify(evidence)}\n`);
}

async function representativeInputs() {
  const directory = process.env.EFORMS_CF_REPRESENTATIVE_DIR;
  assert.ok(directory && isAbsolute(directory), 'EFORMS_CF_REPRESENTATIVE_DIR must be an absolute path.');
  const definitions = [
    ['representative-phone-jpeg', 'image/jpeg', 'phone.jpg'],
    ['representative-phone-png', 'image/png', 'phone.png'],
    ['representative-phone-webp', 'image/webp', 'phone.webp'],
    ['representative-phone-heic', 'image/heic', 'phone.heic'],
    ['representative-phone-heif', 'image/heif', 'phone.heif'],
  ];
  const loaded = [];
  for (const [label, mime, filename] of definitions) {
    const bytes = await readFile(join(directory, filename));
    assert.ok(bytes.byteLength > 0 && bytes.byteLength <= fixture.claims.upload_grant.max_bytes, `${filename} must be a nonempty bounded non-customer fixture.`);
    loaded.push([label, mime, bytes]);
  }
  return loaded;
}

function requiredKeyId(name) {
  const value = process.env[name];
  assert.ok(typeof value === 'string' && /^[A-Za-z0-9._:-]{1,64}$/.test(value), `${name} must be a bounded opaque key ID.`);
  return value;
}

function requiredKey(name) {
  const value = decodeIntegrationKey(process.env[name]);
  assert.ok(value, `${name} must decode to the protocol-owned byte length.`);
  return value;
}

function setRotation(command, phase) {
  const output = execFileSync(command, [phase], { encoding: 'utf8', timeout: 120000, maxBuffer: 65536 });
  const record = JSON.parse(output);
  assert.equal(record.phase, phase);
  assert.equal(record.ready, true);
  assert.equal(record.environment_id, environment);
  assert.ok(typeof record.target_id === 'string' && /^[A-Za-z0-9._:-]{1,128}$/.test(record.target_id));
  assert.ok(typeof record.deployment_id === 'string' && /^[A-Za-z0-9._:-]{1,128}$/.test(record.deployment_id));
  return { phase: record.phase, target_id: record.target_id, deployment_id: record.deployment_id, environment_id: record.environment_id, ready: record.ready };
}

function assertRotationTarget(record, expectedTargetId) {
  if (expectedTargetId) assert.equal(record.target_id, expectedTargetId, 'Every rotation phase must target the same paired deployment.');
  return record.target_id;
}

function probeWordPressRotation(command, phase, expectedActiveId, expectedSecondaryIds) {
  const output = execFileSync(command, [phase], { encoding: 'utf8', timeout: 120000, maxBuffer: 65536 });
  const record = JSON.parse(output);
  assert.equal(record.environment_id, environment);
  assert.equal(record.pair_fingerprint, pairFingerprint, 'WordPress must be configured for the exact site/Worker/environment pair under rotation.');
  assert.equal(record.active_key_id, expectedActiveId);
  assert.deepEqual(record.secondary_key_ids, expectedSecondaryIds.slice().sort());
  assert.equal(record.ready, true);
}

async function assertHealthWithKey(signingId, signingKey, verificationKeys, expectedSignerId) {
  const now = Math.floor(Date.now() / 1000);
  const claims = { request_id: digest(`rotation-health:${runId}:${signingId}:${now}`), expires_at: now + 60 };
  const token = await signHealthRequest(claims, signingId, signingKey, environment);
  const response = await fetch(`${origin}/v1/health`, { method: 'POST', headers: { 'X-EForms-Worker-Health': token } });
  assert.equal(response.status, 200, `Rotation health must accept key ${signingId}.`);
  const body = await response.json();
  const verified = await verifyHealthResult(body.result, verificationKeys, environment, Math.floor(Date.now() / 1000));
  assert.equal(verified.ok, true);
  assert.equal(verified.key_id, expectedSignerId, 'Worker health result must be signed by the promoted active key.');
  assert.equal(verified.claims.storage_ready, true);
  assert.equal(verified.claims.inspection_ready, true);
}

async function assertObjectPresentWithKey(objectKey, objectVersion, signingId, signingKey, verificationKeys, expectedSignerId) {
  const operation = await rawObjectOperation(objectKey, objectVersion, 'inspect', signingId, signingKey);
  assert.equal(operation.response.status, 200, `Retained-object inspection must accept key ${signingId}.`);
  const body = await operation.response.json();
  const verified = await verifyObjectResult(body.result, verificationKeys, environment, Math.floor(Date.now() / 1000));
  assert.equal(verified.ok, true);
  assert.equal(verified.key_id, expectedSignerId, 'Worker object result must be signed by the promoted active key.');
  assert.equal(verified.claims.status, 'present');
}

async function deleteWithAnyKey(objectKey, objectVersion, candidates, verificationKeys) {
  for (const [signingId, signingKey] of candidates) {
    const operation = await rawObjectOperation(objectKey, objectVersion, 'delete', signingId, signingKey);
    if (operation.response.status !== 200) continue;
    const body = await operation.response.json();
    const verified = await verifyObjectResult(body.result, verificationKeys, environment, Math.floor(Date.now() / 1000));
    if (verified.ok && verified.claims.status === 'absent') return;
  }
  throw new Error('no configured rotation key could delete the disposable object');
}

async function assertReview(label, objectKey, objectVersion, mime, expectedBytes) {
  const uploadId = `integration_${label.replace(/[^a-z0-9_-]/g, '_')}`;
  const previewGrant = await reviewGrantFor(`integration_submission_${label.replace(/[^a-z0-9_-]/g, '_')}`, uploadId, objectKey, objectVersion, 'preview');
  const preview = await fetch(`${origin}/v1/review?grant=${encodeURIComponent(previewGrant)}`);
  assert.equal(preview.status, 200, `${label} should produce a genuine Cloudflare preview.`);
  assert.equal(preview.headers.get('content-type'), 'image/jpeg');
  assert.equal(preview.headers.get('cache-control'), 'private, no-store, max-age=0');
  assert.ok((await preview.arrayBuffer()).byteLength > 3);

  const downloadGrant = await reviewGrantFor(`integration_submission_${label.replace(/[^a-z0-9_-]/g, '_')}`, uploadId, objectKey, objectVersion, 'download');
  const download = await fetch(`${origin}/v1/review?grant=${encodeURIComponent(downloadGrant)}`);
  assert.equal(download.status, 200, `${label} authoritative download should remain available.`);
  assert.equal(download.headers.get('content-type'), mime);
  const expectedExtension = mime === 'image/jpeg' ? 'jpg' : mime.slice('image/'.length);
  assert.equal(download.headers.get('content-disposition'), `attachment; filename="submitted-image.${expectedExtension}"`);
  assert.deepEqual(Buffer.from(await download.arrayBuffer()), expectedBytes);
}

function reviewGrantFor(submissionId, uploadId, objectKey, objectVersion, action) {
  return signReviewGrant({
    submission_id: submissionId,
    upload_id: uploadId,
    object_key: objectKey,
    object_version: objectVersion,
    recipe_version: 'review-jpeg-v1',
    action,
    expires_at: Math.floor(Date.now() / 1000) + 300,
  }, keyId, secret, environment);
}

async function assertRejectedGrant([label, mime, bytes]) {
  const claims = uploadClaims(`rejected-${label}`, mime, bytes);
  const grant = await signUploadGrant(claims, keyId, secret, environment);
  const [payload, signature] = grant.split('.');
  const tampered = `${payload}.${signature.startsWith('A') ? 'B' : 'A'}${signature.slice(1)}`;
  const response = await uploadRequest(mime, bytes, tampered, siteOrigin);
  assert.equal(response.status, 403, 'A tampered grant must be rejected before storage authority exists.');
}

async function assertOriginAndEnvironmentMismatch([label, mime, bytes]) {
  const originClaims = uploadClaims(`wrong-origin-${label}`, mime, bytes);
  const originGrant = await signUploadGrant(originClaims, keyId, secret, environment);
  const wrongOrigin = await uploadRequest(mime, bytes, originGrant, 'https://wrong-origin.invalid');
  assert.equal(wrongOrigin.status, 403, 'Wrong-origin upload must fail before provider mutation.');

  const environmentClaims = uploadClaims(`wrong-environment-${label}`, mime, bytes);
  const environmentGrant = await signUploadGrant(environmentClaims, keyId, secret, 'wrong-environment');
  const wrongEnvironment = await uploadRequest(mime, bytes, environmentGrant, siteOrigin);
  assert.equal(wrongEnvironment.status, 403, 'Cross-environment upload grant must be rejected.');
}

async function assertHealth() {
  const now = Math.floor(Date.now() / 1000);
  const claims = { request_id: digest(`health:${runId}`), expires_at: now + 60 };
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
}

async function deleteExact(objectKey, objectVersion) {
  const verified = await objectOperation(objectKey, objectVersion, 'delete');
  assert.equal(verified.status, 'absent');
}

async function objectOperation(objectKey, objectVersion, action) {
  const operation = await rawObjectOperation(objectKey, objectVersion, action);
  assert.equal(operation.response.status, 200, `Exact integration ${action} should be reachable.`);
  const body = await operation.response.json();
  const verified = await verifyObjectResult(body.result, keys, environment, Math.floor(Date.now() / 1000));
  assert.equal(verified.ok, true);
  return verified.claims;
}

async function rawObjectOperation(objectKey, objectVersion, action, signingId = keyId, signingKey = secret) {
  const now = Math.floor(Date.now() / 1000);
  const claims = {
    request_id: digest(`${action}:${runId}:${objectKey}:${objectVersion}`), object_key: objectKey,
    object_version: objectVersion, action, expires_at: now + 60,
  };
  const token = await signObjectRequest(claims, signingId, signingKey, environment);
  const response = await fetch(`${origin}/v1/object`, {
    method: 'POST', headers: { 'X-EForms-Worker-Object': token },
  });
  return { response, claims };
}

function uploadClaims(label, mime, bytes) {
  const now = Math.floor(Date.now() / 1000);
  const identity = hexDigest(`${runId}:${label}`);
  return {
    intent_id: digest(`intent:${runId}:${label}`),
    batch_id: digest(`batch:${runId}:${label}`),
    upload_id: `integration_${label.replace(/[^a-z0-9_-]/g, '_')}`,
    ordinal: 0,
    object_key: `artifacts/${identity.slice(0, 2)}/${identity}`,
    declared_bytes: bytes.byteLength,
    declared_mime: mime,
    policy_fingerprint: hexDigest(`policy:${label}`),
    max_bytes: fixture.claims.upload_grant.max_bytes,
    max_edge: fixture.claims.upload_grant.max_edge,
    max_pixels: fixture.claims.upload_grant.max_pixels,
    container_entry_limit: fixture.claims.upload_grant.container_entry_limit,
    intent_expires_at: now + (fixture.claims.upload_grant.intent_expires_at - fixture.verification_now),
    grant_expires_at: now + (fixture.claims.upload_grant.grant_expires_at - fixture.verification_now),
    upload_max_seconds: fixture.claims.upload_grant.upload_max_seconds,
    receipt_ttl_seconds: fixture.claims.upload_grant.receipt_ttl_seconds,
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
  const verified = await verifyUploadReceipt(body.receipt, keys, environment, Math.floor(Date.now() / 1000));
  assert.equal(verified.ok, true, 'Genuine-provider receipt must verify.');
  return verified;
}

function boundaryJpeg(bytes) {
  const maxBytes = fixture.claims.upload_grant.max_bytes;
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

function setFault(command, mode) {
  const output = execFileSync(command, [mode], { encoding: 'utf8', timeout: 120000, maxBuffer: 65536 });
  const record = JSON.parse(output);
  assert.equal(record.mode, mode);
  assert.equal(record.ready, true);
  assert.ok(typeof record.deployment_id === 'string' && /^[A-Za-z0-9._:-]{1,128}$/.test(record.deployment_id));
  return {
    mode: record.mode,
    deployment_id: record.deployment_id,
    ready: record.ready,
  };
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
