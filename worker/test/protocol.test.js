import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import test from 'node:test';
import {
  decodeIntegrationKey,
  keyConfiguration,
  signHealthResult,
  signObjectResult,
  signUploadReceipt,
  verifyHealthRequest,
  verifyObjectRequest,
  verifyReviewGrant,
  verifyUploadGrant,
} from '../src/protocol.js';
import { WORKER_CLOCK_SKEW_SECONDS } from '../src/anchors.js';
import { detectedMime, extensionForMime, mimeMatches, supportedMime } from '../src/media-policy.js';
import { fixture, tokens } from './fixture.js';

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
});

test('Worker consumes and produces the canonical cross-language vectors', async () => {
  const grant = await verifyUploadGrant(tokens.upload_grant, keys, fixture.environment, fixture.verification_now);
  assert.deepEqual(grant, { ok: true, key_id: fixture.active_key_id, claims: fixture.claims.upload_grant });
  assert.equal(
    await signUploadReceipt(fixture.claims.upload_receipt, fixture.active_key_id, secret, fixture.environment),
    tokens.upload_receipt,
  );
  const review = await verifyReviewGrant(tokens.review_grant, keys, fixture.environment, fixture.verification_now);
  assert.deepEqual(review.claims, fixture.claims.review_grant);
  assert.equal((await verifyReviewGrant(tokens.review_grant, keys, fixture.environment, fixture.claims.review_grant.expires_at - 1)).ok, true);
  assert.equal((await verifyReviewGrant(tokens.review_grant, keys, fixture.environment, fixture.claims.review_grant.expires_at)).ok, false);
  const health = await verifyHealthRequest(tokens.health_request, keys, fixture.environment, fixture.verification_now);
  assert.deepEqual(health.claims, fixture.claims.health_request);
  const object = await verifyObjectRequest(tokens.object_request, keys, fixture.environment, fixture.verification_now);
  assert.deepEqual(object.claims, fixture.claims.object_request);
  assert.equal(
    await signObjectResult(fixture.claims.object_result, fixture.active_key_id, secret, fixture.environment),
    tokens.object_result,
  );
  assert.equal(
    await signHealthResult(fixture.claims.health_result, fixture.active_key_id, secret, fixture.environment),
    tokens.health_result,
  );
});

test('Worker rejects malformed, expired, cross-environment, and tampered grants', async () => {
  const token = tokens.upload_grant;
  assert.equal((await verifyUploadGrant(`${token}=`, keys, fixture.environment, fixture.verification_now)).ok, false);
  assert.equal((await verifyUploadGrant(token, keys, 'wrong-environment', fixture.verification_now)).ok, false);
  assert.equal((await verifyUploadGrant(token, keys, fixture.environment, fixture.claims.upload_grant.grant_expires_at + WORKER_CLOCK_SKEW_SECONDS)).ok, true);
  assert.equal((await verifyUploadGrant(token, keys, fixture.environment, fixture.claims.upload_grant.grant_expires_at + WORKER_CLOCK_SKEW_SECONDS + 1)).ok, false);
  const tampered = `${token.slice(0, -1)}${token.endsWith('A') ? 'B' : 'A'}`;
  assert.equal((await verifyUploadGrant(tampered, keys, fixture.environment, fixture.verification_now)).ok, false);
});

test('Worker rejects validly signed grants with the wrong schema identity or shape', async () => {
  const claimParts = Object.values(fixture.claims.upload_grant).map(String);
  const base = ['eforms-worker-upload-grant', fixture.version, fixture.active_key_id, fixture.environment, ...claimParts];
  const reordered = [...base];
  [reordered[9], reordered[10]] = [reordered[10], reordered[9]];
  const variants = {
    unknown_version: base.with(1, '2'),
    wrong_domain: base.with(0, 'eforms-worker-upload-receipt'),
    unknown_key: base.with(2, 'unknown-key'),
    reordered_fields: reordered,
    missing_field: base.slice(0, -1),
  };
  for (const [name, parts] of Object.entries(variants)) {
    const verified = await verifyUploadGrant(signedParts(parts), keys, fixture.environment, fixture.verification_now);
    assert.equal(verified.ok, false, name);
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
