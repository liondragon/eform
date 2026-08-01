import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { handleRequest } from '../src/index.js';
import { REVIEW_PREVIEW_MAX_BYTES } from '../src/anchors.js';
import {
  decodeIntegrationKey, signObjectResult, signReviewGrant, signUploadGrant, signUploadReceipt,
} from '../src/protocol.js';
import { inspectHeif } from '../src/heif.js';
import { createManagedArtifactKey } from '../src/managed-artifact-key.js';
import { fixture, fixtureToken, tokens } from './fixture.js';
const now = fixture.verification_now;

test('accepted preflight returns exact CORS policy without touching authority or storage', async () => {
  const env = environment();
  const response = await handleRequest(new Request('https://media.example.test/v1/upload', {
    method: 'OPTIONS',
    headers: {
      Origin: env.EFORMS_SITE_ORIGIN,
      'Access-Control-Request-Method': 'PUT',
      'Access-Control-Request-Headers': 'X-EForms-Worker-Grant, Content-Type',
    },
  }), env, clock());
  assert.equal(response.status, 204);
  assert.equal(response.headers.get('access-control-allow-origin'), env.EFORMS_SITE_ORIGIN);
  assert.equal(response.headers.get('access-control-allow-methods'), 'PUT');
  assert.equal(response.headers.get('access-control-allow-headers'), 'Content-Type, X-EForms-Worker-Grant');
  assert.equal(response.headers.get('access-control-allow-credentials'), null);
  assert.deepEqual(env.ARTIFACTS.calls, []);
  assert.equal(env.IMAGES.calls, 0);
});

test('preflight and upload reject wrong origins or unknown authority headers before mutation', async () => {
  const env = environment();
  const wrongOrigin = await handleRequest(new Request('https://media.example.test/v1/upload', {
    method: 'OPTIONS',
    headers: {
      Origin: 'https://attacker.example',
      'Access-Control-Request-Method': 'PUT',
      'Access-Control-Request-Headers': 'Content-Type, X-EForms-Worker-Grant',
    },
  }), env, clock());
  assert.equal(wrongOrigin.status, 403);
  const unknown = await handleRequest(uploadRequest(pngBody(), { 'X-EForms-Unknown': '1' }), env, clock());
  assert.equal(unknown.status, 403);
  assert.deepEqual(env.ARTIFACTS.calls, []);
});

test('upload streams a write-once object, reopens its exact version, and signs immutable facts', async () => {
  const body = pngBody();
  const env = environment({ info: { format: 'png', fileSize: body.byteLength, width: 32, height: 24 } });
  const response = await handleRequest(uploadRequest(body), env, clock());
  assert.equal(response.status, 200);
  const payload = await response.json();
  const stored = env.ARTIFACTS.objects.get(fixture.claims.upload_grant.object_key);
  assert.ok(stored);
  assert.deepEqual([...stored.bytes], [...body]);
  const objectPut = env.ARTIFACTS.putCalls.find((call) => call.key === fixture.claims.upload_grant.object_key);
  assert.equal(objectPut.options.onlyIf.etagDoesNotMatch, '*');
  assert.equal(env.IMAGES.calls, 1);
  assert.ok(env.ARTIFACTS.calls.filter((call) => call === 'get').length >= 2);
  const expected = await signUploadReceipt({
    ...fixture.claims.upload_receipt,
    object_version: stored.version,
    etag: stored.etag,
    expires_at: now + fixture.claims.upload_grant.receipt_ttl_seconds,
  }, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment);
  assert.equal(payload.receipt, expected);

  const retry = await handleRequest(uploadRequest(new Uint8Array(body.byteLength).fill(255)), env, clock());
  assert.equal(retry.status, 200);
  assert.equal((await retry.json()).receipt, payload.receipt);
  assert.equal(env.IMAGES.calls, 1);
  assert.deepEqual([...env.ARTIFACTS.objects.get(fixture.claims.upload_grant.object_key).bytes], [...body]);

  const deleteToken = await signedObjectRequest({
    ...fixture.claims.object_request,
    object_key: fixture.claims.upload_grant.object_key,
    object_version: stored.version,
    action: 'delete',
  });
  const deleted = await handleRequest(new Request('https://media.example.test/v1/object', {
    method: 'POST', headers: { 'X-EForms-Worker-Object': deleteToken },
  }), env, clock());
  assert.equal(deleted.status, 200);
  assert.equal(env.ARTIFACTS.objects.size, 0);
});

test('an existing-object retry explicitly cancels its unused upload body', async () => {
  const body = pngBody();
  const env = environment({ info: { format: 'png', fileSize: body.byteLength, width: 32, height: 24 } });
  const first = await handleRequest(uploadRequest(body), env, clock());
  assert.equal(first.status, 200);

  let cancelled = false;
  const retryBody = new ReadableStream({
    start() {},
    cancel() {
      cancelled = true;
    },
  });
  const retry = await handleRequest(new Request('https://media.example.test/v1/upload', {
    method: 'PUT',
    headers: {
      Origin: 'https://forms.example.test',
      'Content-Type': 'image/png',
      'Content-Length': String(fixture.claims.upload_grant.declared_bytes),
      'X-EForms-Worker-Grant': tokens.upload_grant,
    },
    body: retryBody,
    duplex: 'half',
  }), env, clock());

  assert.equal(retry.status, 200);
  assert.equal(cancelled, true);
  assert.deepEqual([...env.ARTIFACTS.objects.get(fixture.claims.upload_grant.object_key).bytes], [...body]);
  assert.equal(env.IMAGES.calls, 1);
});

test('over-limit grants fail before the request body or object store is used', async () => {
  const env = environment();
  const claims = { ...fixture.claims.upload_grant, max_bytes: 1000 };
  const grant = fixtureToken('eforms-worker-upload-grant', claims, fixture.vectors.declared_over_limit_grant.signature_b64);
  const response = await handleRequest(uploadRequest(pngBody(), {}, grant), env, clock());
  assert.equal(response.status, 413);
  assert.deepEqual(env.ARTIFACTS.calls, []);
});

test('a signed upload grant cannot redirect an intent outside its canonical MIME key', async () => {
  const claims = {
    ...fixture.claims.upload_grant,
    declared_mime: 'image/webp',
  };
  const grant = await signUploadGrant(
    claims, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment,
  );
  const env = environment();
  const response = await handleRequest(uploadRequest(webpBody(), {}, grant, 'image/webp'), env, clock());
  assert.equal(response.status, 400);
  assert.deepEqual(env.ARTIFACTS.calls, []);
  assert.equal(env.IMAGES.calls, 0);
});

test('an initial R2 lookup failure returns a controlled CORS response', async () => {
  const env = environment();
  env.ARTIFACTS.throwOnHead = true;
  const response = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(response.status, 409);
  assert.equal(response.headers.get('access-control-allow-origin'), env.EFORMS_SITE_ORIGIN);
  assert.deepEqual(await response.json(), { error: 'Upload unavailable.' });
  assert.deepEqual(env.ARTIFACTS.calls, ['head']);
});

test('streamed bytes cannot exceed the signed length even when the header claims otherwise', async () => {
  const env = environment();
  const oversized = new Uint8Array(1235);
  oversized.set(pngBody());
  const response = await handleRequest(uploadRequest(oversized, { 'Content-Length': '1234' }), env, clock());
  assert.equal(response.status, 409);
  assert.equal(env.ARTIFACTS.objects.size, 0);
});

test('an upload body that outlives its signed window is cancelled before R2 can commit it', async () => {
  const env = environment();
  const body = new ReadableStream({ start() {} });
  let scheduledDelay = 0;
  const response = await handleRequest(new Request('https://media.example.test/v1/upload', {
    method: 'PUT',
    headers: {
      Origin: 'https://forms.example.test',
      'Content-Type': 'image/png',
      'Content-Length': String(fixture.claims.upload_grant.declared_bytes),
      'X-EForms-Worker-Grant': tokens.upload_grant,
    },
    body,
    duplex: 'half',
  }), env, {
    now: () => now,
    setTimeout(callback, delay) {
      scheduledDelay = delay;
      queueMicrotask(callback);
      return 1;
    },
    clearTimeout() {},
  });

  assert.equal(scheduledDelay, fixture.claims.upload_grant.upload_max_seconds * 1000);
  assert.equal(response.status, 409);
  assert.equal(env.ARTIFACTS.objects.size, 0);
});

test('an early object-store rejection cancels and releases the incoming body pipeline', async () => {
  const env = environment();
  env.ARTIFACTS.rejectPutBeforeRead = true;
  let cancelled = false;
  const body = new ReadableStream({
    start() {},
    cancel() {
      cancelled = true;
    },
  });
  const request = new Request('https://media.example.test/v1/upload', {
    method: 'PUT',
    headers: {
      Origin: 'https://forms.example.test',
      'Content-Type': 'image/png',
      'Content-Length': String(fixture.claims.upload_grant.declared_bytes),
      'X-EForms-Worker-Grant': tokens.upload_grant,
    },
    body,
    duplex: 'half',
  });

  const response = await handleRequest(request, env, clock());

  assert.equal(response.status, 409);
  assert.equal(cancelled, true);
  assert.equal(request.body.locked, false);
  assert.equal(env.ARTIFACTS.objects.size, 0);
});

test('a conditional write loser cancels its body and recovers the exact concurrent winner', async () => {
  const env = environment({ info: { format: 'png', fileSize: pngBody().byteLength, width: 32, height: 24 } });
  env.ARTIFACTS.conditionalConflictBeforeRead = (key, options) => {
    env.ARTIFACTS.seed(key, pngBody(), options.customMetadata, 'version-concurrent');
  };
  let cancelled = false;
  const body = new ReadableStream({
    start() {},
    cancel() {
      cancelled = true;
    },
  });
  const request = new Request('https://media.example.test/v1/upload', {
    method: 'PUT',
    headers: {
      Origin: 'https://forms.example.test',
      'Content-Type': 'image/png',
      'Content-Length': String(fixture.claims.upload_grant.declared_bytes),
      'X-EForms-Worker-Grant': tokens.upload_grant,
    },
    body,
    duplex: 'half',
  });

  const response = await handleRequest(request, env, clock());

  assert.equal(response.status, 200);
  assert.equal(cancelled, true);
  assert.equal(request.body.locked, false);
  assert.equal(env.ARTIFACTS.objects.get(fixture.claims.upload_grant.object_key).version, 'version-concurrent');
  assert.equal(env.IMAGES.calls, 1);
});

test('validation failures produce no receipt and preserve the artifact for lifecycle-owned cleanup', async () => {
  for (const scenario of [
    { body: animatedPngBody(), info: { format: 'png', fileSize: 1234, width: 32, height: 24 } },
    { body: pngBody(), infoError: true },
  ]) {
    const env = environment(scenario);
    const response = await handleRequest(uploadRequest(scenario.body), env, clock());
    assert.equal(response.status, 422);
    assert.deepEqual(await response.json(), { error: 'Upload unavailable.' });
    assert.equal(env.ARTIFACTS.objects.has(fixture.claims.upload_grant.object_key), true);
    const released = [...env.ARTIFACTS.objects.values()].find((stored) => stored.key.includes('.validating-'));
    assert.equal(released.customMetadata.state, 'released');
    assert.equal(env.ARTIFACTS.calls.includes('delete'), false);
  }
});

test('bounded PNG and WebP scans cancel their exact-object streams on every early exit', async () => {
  const cases = [
    { body: pngBody(), mime: 'image/png', format: 'png', accepted: true },
    { body: invalidPngBody(), mime: 'image/png', format: 'png', accepted: false },
    { body: webpBody(), mime: 'image/webp', format: 'webp', accepted: true },
  ];
  for (const scenario of cases) {
    const claims = {
      ...fixture.claims.upload_grant,
      declared_mime: scenario.mime,
    };
    claims.object_key = await createManagedArtifactKey(
      claims.batch_id, claims.ordinal, claims.intent_id, claims.declared_mime,
    );
    const grant = await signUploadGrant(
      claims, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment,
    );
    const env = environment({
      info: { format: scenario.format, fileSize: scenario.body.byteLength, width: 32, height: 24 },
    });
    env.ARTIFACTS.keepSecondBodyOpen = true;
    const response = await handleRequest(
      uploadRequest(scenario.body, {}, grant, scenario.mime), env, clock(),
    );
    assert.equal(response.status, scenario.accepted ? 200 : 422);
    assert.equal(env.ARTIFACTS.bodyCancellations, 1);
  }
});

test('an unvalidated existing object is re-inspected and preserved when validation fails', async () => {
  const env = environment({ infoError: true });
  const claims = fixture.claims.upload_grant;
  env.ARTIFACTS.seed(claims.object_key, pngBody(), {
    intentId: claims.intent_id,
    batchId: claims.batch_id,
    uploadId: claims.upload_id,
    policyFingerprint: claims.policy_fingerprint,
    declaredMime: claims.declared_mime,
  });

  const response = await handleRequest(uploadRequest(pngBody()), env, clock());

  assert.equal(response.status, 409);
  assert.equal(env.ARTIFACTS.objects.has(claims.object_key), true);
  assert.equal(env.IMAGES.calls, 1);
  assert.equal(env.ARTIFACTS.calls.includes('delete'), false);
});

test('an in-flight creator cannot expose a receipt before its durable validation fence', async () => {
  let releaseInspection;
  let inspectionStarted;
  const started = new Promise((resolve) => { inspectionStarted = resolve; });
  const waiting = new Promise((resolve) => { releaseInspection = resolve; });
  const env = environment({ infoStarted: inspectionStarted, infoWait: waiting, infoError: true });

  const creatorPromise = handleRequest(uploadRequest(pngBody()), env, clock());
  await started;
  const retry = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(retry.status, 409);
  assert.deepEqual(await retry.json(), { error: 'Upload unavailable.' });

  releaseInspection();
  const creator = await creatorPromise;
  assert.equal(creator.status, 422);
  assert.equal(env.ARTIFACTS.objects.has(fixture.claims.upload_grant.object_key), true);
  const released = [...env.ARTIFACTS.objects.values()].find((stored) => stored.key.includes('.validating-'));
  assert.equal(released.customMetadata.state, 'released');
  assert.equal(env.ARTIFACTS.calls.includes('delete'), false);
});

test('a published validation marker preserves the artifact when its put response is lost', async () => {
  const env = environment();
  env.ARTIFACTS.throwAfterPut = (key) => key.includes('.validated-');

  const lostResponse = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(lostResponse.status, 422);
  assert.equal(env.ARTIFACTS.objects.has(fixture.claims.upload_grant.object_key), true);
  assert.equal([...env.ARTIFACTS.objects.keys()].some((key) => key.includes('.validated-')), true);
  const released = [...env.ARTIFACTS.objects.values()].find((stored) => stored.key.includes('.validating-'));
  assert.equal(released.customMetadata.state, 'released');

  env.ARTIFACTS.throwAfterPut = null;
  const retry = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(retry.status, 200);
  assert.ok((await retry.json()).receipt);
});

test('a committed artifact is recovered when its put response is lost', async () => {
  const env = environment();
  env.ARTIFACTS.throwAfterPut = (key) => key === fixture.claims.upload_grant.object_key;

  const recovered = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(recovered.status, 200);
  assert.ok((await recovered.json()).receipt);
  assert.equal(env.ARTIFACTS.objects.has(fixture.claims.upload_grant.object_key), true);
  assert.equal(env.IMAGES.calls, 1);

  env.ARTIFACTS.throwAfterPut = null;
  const retry = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(retry.status, 200);
  assert.ok((await retry.json()).receipt);
  assert.equal(env.IMAGES.calls, 1);
});

test('validation failure preserves the artifact without consulting marker state', async () => {
  const env = environment({ infoError: true });
  env.ARTIFACTS.throwOnHead = (key) => key.includes('.validated-');

  const response = await handleRequest(uploadRequest(pngBody()), env, clock());

  assert.equal(response.status, 422);
  assert.deepEqual(await response.json(), { error: 'Upload unavailable.' });
  assert.equal(response.headers.get('access-control-allow-origin'), env.EFORMS_SITE_ORIGIN);
  assert.equal(env.ARTIFACTS.objects.has(fixture.claims.upload_grant.object_key), true);
});

test('a retry recovers an unvalidated object after a transient inspection failure', async () => {
  const env = environment({ infoError: true });

  const failed = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(failed.status, 422);
  assert.equal(env.ARTIFACTS.objects.has(fixture.claims.upload_grant.object_key), true);
  assert.equal(env.ARTIFACTS.calls.includes('delete'), false);

  env.IMAGES.options.infoError = false;
  const recovered = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(recovered.status, 200);
  assert.ok((await recovered.json()).receipt);
  assert.equal(env.IMAGES.calls, 2);
});

test('an abandoned validation lease is reclaimed only after the upload window', async () => {
  const env = environment();
  const claims = fixture.claims.upload_grant;
  env.ARTIFACTS.seed(claims.object_key, pngBody(), {
    intentId: claims.intent_id,
    batchId: claims.batch_id,
    uploadId: claims.upload_id,
    policyFingerprint: claims.policy_fingerprint,
    declaredMime: claims.declared_mime,
  });
  const object = env.ARTIFACTS.objects.get(claims.object_key);
  const leaseKey = await validationLeaseTestKey(claims.object_key, object.version);
  env.ARTIFACTS.seed(leaseKey, new Uint8Array(0), {
    validationVersion: '1',
    artifactVersion: object.version,
    intentId: claims.intent_id,
    policyFingerprint: claims.policy_fingerprint,
    startedAt: String(now - claims.upload_max_seconds - 1),
    state: 'active',
  }, 'abandoned-lease');
  const recovered = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(recovered.status, 200);
  assert.ok((await recovered.json()).receipt);
  assert.equal(env.ARTIFACTS.objects.get(leaseKey).customMetadata.state, 'released');
  assert.equal(env.IMAGES.calls, 1);
});

test('simultaneous stale-lease retries admit only one inspector', async () => {
  const env = environment();
  const claims = fixture.claims.upload_grant;
  env.ARTIFACTS.seed(claims.object_key, pngBody(), {
    intentId: claims.intent_id,
    batchId: claims.batch_id,
    uploadId: claims.upload_id,
    policyFingerprint: claims.policy_fingerprint,
    declaredMime: claims.declared_mime,
  });
  const object = env.ARTIFACTS.objects.get(claims.object_key);
  const leaseKey = await validationLeaseTestKey(claims.object_key, object.version);
  env.ARTIFACTS.seed(leaseKey, new Uint8Array(0), {
    validationVersion: '1',
    artifactVersion: object.version,
    intentId: claims.intent_id,
    policyFingerprint: claims.policy_fingerprint,
    startedAt: String(now - claims.upload_max_seconds - 1),
    state: 'active',
  }, 'abandoned-lease');
  let replacements = 0;
  let releaseReplacements;
  const replacementBarrier = new Promise((resolve) => { releaseReplacements = resolve; });
  env.ARTIFACTS.beforeConditionalPut = async (key, options) => {
    if (key !== leaseKey || !options.onlyIf || !options.onlyIf.etagMatches) return;
    replacements += 1;
    if (replacements === 2) releaseReplacements();
    await replacementBarrier;
  };

  const responses = await Promise.all([
    handleRequest(uploadRequest(pngBody()), env, clock()),
    handleRequest(uploadRequest(pngBody()), env, clock()),
  ]);
  assert.equal(responses.filter((response) => response.status === 200).length, 1);
  assert.equal(responses.filter((response) => response.status === 409).length, 1);
  assert.equal(env.IMAGES.calls, 1);
});

test('an old owner release cannot overwrite a successor lease', async () => {
  const env = environment();
  let successorKey = '';
  env.ARTIFACTS.beforeConditionalPut = async (key, options) => {
    if (!key.includes('.validating-') || !options.customMetadata
      || options.customMetadata.state !== 'released') return;
    const current = env.ARTIFACTS.objects.get(key);
    successorKey = key;
    env.ARTIFACTS.objects.set(key, {
      ...current,
      version: 'successor-version',
      etag: 'successor-etag',
      customMetadata: { ...current.customMetadata, state: 'active' },
    });
  };

  const response = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(response.status, 200);
  assert.equal(env.ARTIFACTS.objects.get(successorKey).version, 'successor-version');
  assert.equal(env.ARTIFACTS.objects.get(successorKey).customMetadata.state, 'active');
  assert.equal(env.IMAGES.calls, 1);
});

test('intent-keyed rate limiting bounds repeated upload and inspection work', async () => {
  const env = environment({ info: { format: 'png', fileSize: 1234, width: 32, height: 24 } });
  env.UPLOAD_RATE_LIMITER = new FakeRateLimiter(1);
  const first = await handleRequest(uploadRequest(animatedPngBody()), env, clock());
  assert.equal(first.status, 422);
  const storageCalls = env.ARTIFACTS.calls.length;
  const imageCalls = env.IMAGES.calls;

  const limited = await handleRequest(uploadRequest(animatedPngBody()), env, clock());
  assert.equal(limited.status, 429);
  assert.equal(limited.headers.get('retry-after'), '60');
  assert.equal(env.ARTIFACTS.calls.length, storageCalls);
  assert.equal(env.IMAGES.calls, imageCalls);
  assert.deepEqual(env.UPLOAD_RATE_LIMITER.keys, [
    fixture.claims.upload_grant.intent_id,
    fixture.claims.upload_grant.intent_id,
  ]);
});

test('HEIF receipts require the bounded still-image container policy', async () => {
  const bytes = new Uint8Array(Buffer.from(
    (await readFile(new URL('../../tests/fixtures/staged-landscape.heic.b64', import.meta.url), 'utf8')).trim(),
    'base64',
  ));
  const dimensions = inspectHeif(bytes, fixture.claims.upload_grant.container_entry_limit);
  assert.ok(dimensions);
  const claims = {
    ...fixture.claims.upload_grant,
    declared_bytes: bytes.byteLength,
    declared_mime: 'image/heic',
  };
  claims.object_key = await createManagedArtifactKey(
    claims.batch_id, claims.ordinal, claims.intent_id, claims.declared_mime,
  );
  const grant = await signUploadGrant(
    claims, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment,
  );
  const env = environment({
    info: { format: 'heic', fileSize: bytes.byteLength, width: dimensions.width, height: dimensions.height },
  });
  const accepted = await handleRequest(uploadRequest(bytes, {}, grant, 'image/heic'), env, clock());
  assert.equal(accepted.status, 200);

  const protectedBytes = new Uint8Array(bytes);
  protectFirstItemDefinition(protectedBytes);
  const protectedClaims = {
    ...claims,
    intent_id: claims.intent_id.replace(/.$/, claims.intent_id.endsWith('A') ? 'B' : 'A'),
    upload_id: 'heif_protected',
  };
  protectedClaims.object_key = await createManagedArtifactKey(
    protectedClaims.batch_id, protectedClaims.ordinal, protectedClaims.intent_id, protectedClaims.declared_mime,
  );
  const protectedGrant = await signUploadGrant(
    protectedClaims, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment,
  );
  const rejected = await handleRequest(
    uploadRequest(protectedBytes, {}, protectedGrant, 'image/heic'), env, clock(),
  );
  assert.equal(rejected.status, 422);
  assert.equal(env.ARTIFACTS.objects.has(protectedClaims.object_key), true);
  assert.equal(env.ARTIFACTS.calls.includes('delete'), false);
});

test('a conflicting existing object cannot be overwritten or turned into a receipt', async () => {
  const env = environment();
  env.ARTIFACTS.seed(fixture.claims.upload_grant.object_key, pngBody(), { intentId: 'wrong-intent' });
  const response = await handleRequest(uploadRequest(pngBody()), env, clock());
  assert.equal(response.status, 409);
  assert.equal(env.ARTIFACTS.calls.includes('put'), false);
});

test('signed health requests return signed binding readiness without storage mutation', async () => {
  const env = environment();
  const response = await handleRequest(new Request('https://media.example.test/v1/health', {
    method: 'POST',
    headers: { 'X-EForms-Worker-Health': tokens.health_request },
  }), env, clock());
  assert.equal(response.status, 200);
  const expected = await signUploadIndependentHealth(env);
  assert.equal((await response.json()).result, expected);
  assert.deepEqual(env.ARTIFACTS.calls, ['head']);
  assert.deepEqual(env.UPLOAD_RATE_LIMITER.keys, [`health:${fixture.claims.health_request.request_id}`]);
  assert.equal(env.IMAGES.calls, 2);
});

test('signed health fails storage readiness when the upload limiter binding is absent', async () => {
  const env = environment();
  delete env.UPLOAD_RATE_LIMITER;
  const response = await handleRequest(new Request('https://media.example.test/v1/health', {
    method: 'POST',
    headers: { 'X-EForms-Worker-Health': tokens.health_request },
  }), env, clock());
  assert.equal(response.status, 200);
  const expected = await signUploadIndependentHealth(env, { storage_ready: false });
  assert.equal((await response.json()).result, expected);
  assert.deepEqual(env.ARTIFACTS.calls, []);
});

test('signed health fails storage readiness when the upload limiter rejects or throws', async () => {
  for (const limiter of [
    new FakeRateLimiter(0),
    { async limit() { throw new Error('limiter unavailable'); } },
  ]) {
    const env = environment();
    env.UPLOAD_RATE_LIMITER = limiter;
    const response = await handleRequest(new Request('https://media.example.test/v1/health', {
      method: 'POST',
      headers: { 'X-EForms-Worker-Health': tokens.health_request },
    }), env, clock());
    assert.equal(response.status, 200);
    const expected = await signUploadIndependentHealth(env, { storage_ready: false });
    assert.equal((await response.json()).result, expected);
    assert.deepEqual(env.ARTIFACTS.calls, []);
  }
});

test('review rejects before cache or storage reads and requires the fixed recipe', async () => {
  const env = environment();
  const cache = new FakeCache();
  const tampered = `${tokens.review_grant.slice(0, -1)}${tokens.review_grant.endsWith('A') ? 'B' : 'A'}`;
  const denied = await handleRequest(reviewRequest(tampered), env, { ...clock(), cache });
  assert.equal(denied.status, 404);
  assert.deepEqual(env.ARTIFACTS.calls, []);
  assert.equal(cache.matches, 0);

  const wrongRecipeToken = await reviewGrant({ ...fixture.claims.review_grant, recipe_version: 'preview-v1' });
  const wrongRecipe = await handleRequest(reviewRequest(wrongRecipeToken), env, { ...clock(), cache });
  assert.equal(wrongRecipe.status, 404);
  assert.deepEqual(env.ARTIFACTS.calls, []);
  assert.equal(cache.matches, 0);
});

test('authorized review verifies the exact object then privately serves one cached fixed preview', async () => {
  const claims = { ...fixture.claims.review_grant };
  const token = await reviewGrant(claims);
  const env = environment();
  env.ARTIFACTS.seed(claims.object_key, pngBody(), { declaredMime: 'image/png' }, claims.object_version);
  const cache = new FakeCache();
  const first = await handleRequest(reviewRequest(token), env, { ...clock(), cache });
  assert.equal(first.status, 200);
  assert.equal(first.headers.get('content-type'), 'image/jpeg');
  assert.equal(first.headers.get('cache-control'), 'private, no-store, max-age=0');
  assert.equal(first.headers.get('referrer-policy'), 'no-referrer');
  assert.deepEqual([...new Uint8Array(await first.arrayBuffer())], [255, 216, 255, 217]);
  assert.equal(env.IMAGES.calls, 1);

  const retry = await handleRequest(reviewRequest(token), env, { ...clock(), cache });
  assert.equal(retry.status, 200);
  assert.deepEqual([...new Uint8Array(await retry.arrayBuffer())], [255, 216, 255, 217]);
  assert.equal(env.IMAGES.calls, 1);
  assert.equal(cache.matches, 2);
  assert.equal(cache.puts, 1);
  assert.equal(env.ARTIFACTS.calls.filter((call) => call === 'head').length, 2);

  env.ARTIFACTS.seed(claims.object_key, pngBody(), { declaredMime: 'image/png' }, 'changed-version');
  const changed = await handleRequest(reviewRequest(token), env, { ...clock(), cache });
  assert.equal(changed.status, 404);
  assert.equal(cache.matches, 2);
  assert.equal(env.IMAGES.calls, 1);
});

test('authorized review rejects an oversized transformed preview before cache or delivery', async () => {
  const claims = { ...fixture.claims.review_grant, recipe_version: 'review-jpeg-v1' };
  const token = await reviewGrant(claims);
  const env = environment({
    output: new Uint8Array(REVIEW_PREVIEW_MAX_BYTES + 1),
    outputLengthHeader: false,
  });
  env.ARTIFACTS.seed(claims.object_key, pngBody(), { declaredMime: 'image/png' }, claims.object_version);
  const cache = new FakeCache();

  const response = await handleRequest(reviewRequest(token), env, { ...clock(), cache });

  assert.equal(response.status, 503);
  assert.equal(cache.puts, 0);
  assert.equal(await response.text(), 'Review unavailable.');
});

test('authorized review download streams the exact object as a private attachment', async () => {
  const claims = { ...fixture.claims.review_grant, action: 'download', recipe_version: 'review-jpeg-v1' };
  const token = await reviewGrant(claims);
  const env = environment();
  const bytes = pngBody();
  await seedValidatedReviewArtifact(env, claims, bytes, 'image/png', 'image/png');
  const response = await handleRequest(reviewRequest(token), env, clock());
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('content-type'), 'image/png');
  assert.equal(response.headers.get('content-disposition'), 'attachment; filename="submitted-image.png"');
  assert.deepEqual([...new Uint8Array(await response.arrayBuffer())], [...bytes]);
  assert.equal(env.IMAGES.calls, 0);
});

test('authorized review download uses the validated HEIF alias instead of the declaration', async () => {
  const claims = { ...fixture.claims.review_grant, action: 'download', recipe_version: 'review-jpeg-v1' };
  const token = await reviewGrant(claims);
  const env = environment();
  const bytes = pngBody();
  await seedValidatedReviewArtifact(env, claims, bytes, 'image/heic', 'image/heif');
  const response = await handleRequest(reviewRequest(token), env, clock());
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('content-type'), 'image/heif');
  assert.equal(response.headers.get('content-disposition'), 'attachment; filename="submitted-image.heif"');
});

test('signed object deletion is exact-version, idempotent, and reports mismatches', async () => {
  const key = fixture.claims.object_request.object_key;
  const version = fixture.claims.object_request.object_version;
  const env = environment();
  env.ARTIFACTS.seed(key, pngBody(), {}, version);
  const request = () => new Request('https://media.example.test/v1/object', {
    method: 'POST',
    headers: { 'X-EForms-Worker-Object': tokens.object_request },
  });
  const deleted = await handleRequest(request(), env, clock());
  assert.equal(deleted.status, 200);
  assert.equal((await deleted.json()).result, tokens.object_result);
  assert.equal(env.ARTIFACTS.objects.has(key), false);

  const retry = await handleRequest(request(), env, clock());
  assert.equal(retry.status, 200);
  assert.equal((await retry.json()).result, tokens.object_result);

  env.ARTIFACTS.seed(key, pngBody(), {}, 'different-version');
  const mismatch = await handleRequest(request(), env, clock());
  assert.equal(mismatch.status, 200);
  const payload = await mismatch.json();
  const expectedMismatch = await signObjectResult({
    ...fixture.claims.object_result,
    status: 'version_mismatch',
  }, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment);
  assert.equal(payload.result, expectedMismatch);
  assert.equal(env.ARTIFACTS.objects.get(key).version, 'different-version');
});

test('signed object inspection confirms one exact version without mutation', async () => {
  const key = fixture.claims.object_request.object_key;
  const version = fixture.claims.object_request.object_version;
  const env = environment();
  env.ARTIFACTS.seed(key, pngBody(), {}, version);
  const claims = { ...fixture.claims.object_request, action: 'inspect' };
  const token = await signedObjectRequest(claims);
  const response = await handleRequest(new Request('https://media.example.test/v1/object', {
    method: 'POST',
    headers: { 'X-EForms-Worker-Object': token },
  }), env, clock());
  assert.equal(response.status, 200);
  const expected = await signObjectResult({
    ...fixture.claims.object_result,
    status: 'present',
  }, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment);
  assert.equal((await response.json()).result, expected);
  assert.equal(env.ARTIFACTS.objects.get(key).version, version);
  assert.equal(env.ARTIFACTS.calls.includes('delete'), false);
});

function environment(images = {}) {
  return {
    EFORMS_SITE_ORIGIN: 'https://forms.example.test',
    EFORMS_WORKER_ENVIRONMENT_ID: fixture.environment,
    EFORMS_WORKER_ACTIVE_KEY_ID: fixture.active_key_id,
    EFORMS_WORKER_ACTIVE_KEY_B64: fixture.active_key_b64,
    ARTIFACTS: new FakeBucket(),
    IMAGES: new FakeImages(images),
    UPLOAD_RATE_LIMITER: new FakeRateLimiter(),
  };
}

function clock() {
  return { now: () => now };
}

function uploadRequest(body, extraHeaders = {}, grant = tokens.upload_grant, mime = 'image/png') {
  return new Request('https://media.example.test/v1/upload', {
    method: 'PUT',
    headers: {
      Origin: 'https://forms.example.test',
      'Content-Type': mime,
      'Content-Length': String(body.byteLength),
      'X-EForms-Worker-Grant': grant,
      ...extraHeaders,
    },
    body,
    duplex: 'half',
  });
}

function reviewRequest(grant) {
  return new Request(`https://media.example.test/v1/review?grant=${encodeURIComponent(grant)}`);
}

function reviewGrant(claims) {
  return signReviewGrant(
    claims, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment,
  );
}

function pngBody(animated = false) {
  const bytes = new Uint8Array(1234);
  bytes.set([137, 80, 78, 71, 13, 10, 26, 10]);
  if (animated) {
    bytes.set([0, 0, 0, 0, 97, 99, 84, 76], 8);
    return bytes;
  }
  bytes.set([0, 0, 0, 13, 73, 72, 68, 82], 8);
  bytes.set([0, 0, 0, 0, 73, 68, 65, 84], 33);
  return bytes;
}

async function signedObjectRequest(claims) {
  const parts = [
    'eforms-worker-object-request', fixture.version, fixture.active_key_id, fixture.environment,
    ...Object.values(claims).map(String),
  ];
  const encoded = parts.map((part) => new TextEncoder().encode(part));
  const payload = new Uint8Array(encoded.reduce((total, part) => total + 4 + part.byteLength, 0));
  const view = new DataView(payload.buffer);
  let offset = 0;
  for (const part of encoded) {
    view.setUint32(offset, part.byteLength, false);
    offset += 4;
    payload.set(part, offset);
    offset += part.byteLength;
  }
  const key = await crypto.subtle.importKey(
    'raw', decodeIntegrationKey(fixture.active_key_b64),
    { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'],
  );
  const signature = new Uint8Array(await crypto.subtle.sign('HMAC', key, payload));
  return `${base64url(payload)}.${base64url(signature)}`;
}

async function validationLeaseTestKey(objectKey, objectVersion) {
  const digest = new Uint8Array(await crypto.subtle.digest(
    'SHA-256', new TextEncoder().encode(objectVersion),
  ));
  return `${objectKey}.validating-${[...digest].map((byte) => byte.toString(16).padStart(2, '0')).join('')}`;
}

async function validationMarkerTestKey(objectKey, objectVersion) {
  const digest = new Uint8Array(await crypto.subtle.digest(
    'SHA-256', new TextEncoder().encode(objectVersion),
  ));
  return `${objectKey}.validated-${[...digest].map((byte) => byte.toString(16).padStart(2, '0')).join('')}`;
}

async function seedValidatedReviewArtifact(env, claims, bytes, declaredMime, detectedMime) {
  env.ARTIFACTS.seed(claims.object_key, bytes, { declaredMime }, claims.object_version);
  const markerKey = await validationMarkerTestKey(claims.object_key, claims.object_version);
  env.ARTIFACTS.seed(markerKey, new Uint8Array(0), {
    validationVersion: '1',
    artifactVersion: claims.object_version,
    artifactEtag: 'etag-seeded',
    intentId: 'review-fixture-intent',
    policyFingerprint: 'review-fixture-policy',
    bytes: String(bytes.byteLength),
    mime: detectedMime,
    width: '32',
    height: '24',
  }, 'validated-marker');
}

function base64url(bytes) {
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function animatedPngBody() {
  return pngBody(true);
}

function invalidPngBody() {
  return new Uint8Array(1234);
}

function webpBody() {
  const bytes = new Uint8Array(1234);
  bytes.set(new TextEncoder().encode('RIFF'), 0);
  bytes.set(new TextEncoder().encode('WEBP'), 8);
  bytes.set(new TextEncoder().encode('VP8X'), 12);
  return bytes;
}

function protectFirstItemDefinition(bytes) {
  for (let offset = 4; offset + 14 <= bytes.byteLength; offset += 1) {
    if (String.fromCharCode(...bytes.subarray(offset, offset + 4)) !== 'infe') continue;
    const version = bytes[offset + 4];
    const protectionOffset = version === 2 ? offset + 10 : (version === 3 ? offset + 12 : -1);
    if (protectionOffset > 0) {
      bytes[protectionOffset] = 0;
      bytes[protectionOffset + 1] = 1;
      return;
    }
  }
  throw new Error('HEIF fixture has no supported item definition.');
}

class FakeBucket {
  constructor() {
    this.objects = new Map();
    this.calls = [];
    this.putOptions = null;
    this.putCalls = [];
    this.throwAfterPut = null;
    this.throwOnHead = false;
    this.rejectPutBeforeRead = false;
    this.conditionalConflictBeforeRead = null;
    this.beforeConditionalPut = null;
    this.bodyCancellations = 0;
    this.getCounts = new Map();
    this.keepSecondBodyOpen = false;
    this.sequence = 0;
  }

  seed(key, bytes, metadata, version = 'version-seeded') {
    this.objects.set(key, { key, bytes, version, etag: 'etag-seeded', customMetadata: metadata });
  }

  async head(key) {
    this.calls.push('head');
    if (this.throwOnHead === true || (typeof this.throwOnHead === 'function' && this.throwOnHead(key))) {
      throw new Error('provider unavailable');
    }
    return this.descriptor(this.objects.get(key));
  }

  async put(key, stream, options) {
    this.calls.push('put');
    this.putOptions = options;
    this.putCalls.push({ key, options });
    if (this.rejectPutBeforeRead) throw new Error('provider rejected before reading');
    if (this.conditionalConflictBeforeRead) {
      const conflict = this.conditionalConflictBeforeRead;
      this.conditionalConflictBeforeRead = null;
      conflict(key, options);
      return null;
    }
    const bytes = new Uint8Array(await new Response(stream).arrayBuffer());
    if (this.beforeConditionalPut) await this.beforeConditionalPut(key, options);
    const current = this.objects.get(key);
    if (current && options.onlyIf && options.onlyIf.etagDoesNotMatch === '*') return null;
    if (options.onlyIf && options.onlyIf.etagMatches && (!current || current.etag !== options.onlyIf.etagMatches)) return null;
    this.sequence += 1;
    const identity = String(this.sequence).padStart(4, '0');
    const stored = { key, bytes, version: `version-${identity}`, etag: `etag-${identity}`, customMetadata: options.customMetadata };
    this.objects.set(key, stored);
    if ( this.throwAfterPut && this.throwAfterPut(key) ) throw new Error('lost put response');
    return this.descriptor(stored);
  }

  async get(key, options) {
    this.calls.push('get');
    const stored = this.objects.get(key);
    if (!stored || (options && options.onlyIf && options.onlyIf.etagMatches !== stored.etag)) return null;
    const getCount = (this.getCounts.get(key) || 0) + 1;
    this.getCounts.set(key, getCount);
    if (!this.keepSecondBodyOpen || getCount !== 2) {
      return { ...this.descriptor(stored), body: new Response(stored.bytes).body };
    }
    const bucket = this;
    let sent = false;
    const body = new ReadableStream({
      pull(controller) {
        if (!sent) {
          sent = true;
          controller.enqueue(stored.bytes);
        }
      },
      cancel() {
        bucket.bodyCancellations += 1;
      },
    });
    return { ...this.descriptor(stored), body };
  }

  async delete(key) {
    this.calls.push('delete');
    this.objects.delete(key);
  }

  descriptor(stored) {
    return stored ? {
      key: stored.key,
      size: stored.bytes.byteLength,
      version: stored.version,
      etag: stored.etag,
      customMetadata: { ...stored.customMetadata },
    } : null;
  }
}

class FakeRateLimiter {
  constructor(limit = Number.POSITIVE_INFINITY) {
    this.limitValue = limit;
    this.keys = [];
  }

  async limit({ key }) {
    this.keys.push(key);
    return { success: this.keys.length <= this.limitValue };
  }
}

class FakeImages {
  constructor(options) {
    this.options = options;
    this.calls = 0;
  }

  async info(stream) {
    this.calls += 1;
    await new Response(stream).arrayBuffer();
    if (this.options.infoStarted) this.options.infoStarted();
    if (this.options.infoWait) await this.options.infoWait;
    if (this.options.infoError) throw new Error('provider unavailable');
    return this.options.info || { format: 'png', fileSize: 1234, width: 32, height: 24 };
  }

  input(stream) {
    this.calls += 1;
    const options = this.options;
    return {
      transform() {
        return this;
      },
      async output() {
        await new Response(stream).arrayBuffer();
        if (options.outputError) throw new Error('provider unavailable');
        return {
          response() {
            const output = options.output || new Uint8Array([255, 216, 255, 217]);
            const headers = { 'Content-Type': 'image/jpeg' };
            if (options.outputLengthHeader !== false) headers['Content-Length'] = String(output.byteLength);
            return new Response(output, { status: 200, headers });
          },
        };
      },
    };
  }
}

class FakeCache {
  constructor() {
    this.entries = new Map();
    this.matches = 0;
    this.puts = 0;
  }

  async match(request) {
    this.matches += 1;
    const response = this.entries.get(request.url);
    return response ? response.clone() : undefined;
  }

  async put(request, response) {
    this.puts += 1;
    this.entries.set(request.url, response.clone());
  }
}

async function signUploadIndependentHealth(env, overrides = {}) {
  const { signHealthResult } = await import('../src/protocol.js');
  return signHealthResult({
    request_id: fixture.claims.health_request.request_id,
    storage_ready: true,
    inspection_ready: true,
    checked_at: now,
    expires_at: fixture.claims.health_request.expires_at,
    ...overrides,
  }, fixture.active_key_id, decodeIntegrationKey(env.EFORMS_WORKER_ACTIVE_KEY_B64), fixture.environment);
}
