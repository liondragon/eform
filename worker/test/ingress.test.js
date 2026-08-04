import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import {
  workerGalleryStatus,
  workerObjectOperation,
  workerReview,
  workerUpload,
  handleRequest,
} from '../src/index.js';
import {
  MANAGED_STAGED_MAX_FILES,
  REVIEW_PREVIEW_MAX_BYTES,
  WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES,
} from '../src/anchors.js';
import {
  workerGalleryItemsSha256,
  workerGalleryStatusRequestBodyBytes,
  workerGalleryStatusesSha256,
  decodeIntegrationKey,
  signWorkerGalleryStatusRequest,
  signWorkerObjectResult,
  signWorkerReviewGrant,
  signWorkerStoredReceipt,
  verifyWorkerObjectResult,
  verifyWorkerGalleryStatusResult,
} from '../src/protocol.js';
import { inspectHeif } from '../src/heif.js';
import { createManagedArtifactKey } from '../src/managed-artifact-key.js';
import { validationResultKey } from '../src/validation-result.js';
import { canonicalJsonBytes, workerTokens, fixture, tokens } from './fixture.js';
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
  const unknown = await handleRequest(workerUploadRequest(pngBody(), { 'X-EForms-Unknown': '1' }), env, clock());
  assert.equal(unknown.status, 403);
  assert.deepEqual(env.ARTIFACTS.calls, []);
});

test('Worker endpoints reject mismatched storage identity or validation contract before storage access', async () => {
  for (const mutate of [
    (env) => { env.EFORMS_WORKER_URL = 'https://alternate-media.example.test'; },
    (env) => { env.EFORMS_VALIDATION_CONTRACT_VERSION = 'managed-image-v2'; },
  ]) {
    const uploadEnv = environment();
    mutate(uploadEnv);
    const upload = await workerUpload(workerUploadRequest(pngBody()), uploadEnv, clock());
    assert.equal(upload.status, 403);
    assert.deepEqual(uploadEnv.ARTIFACTS.calls, []);
    assert.deepEqual(uploadEnv.VALIDATION_QUEUE.jobs, []);

    const objectEnv = environment();
    mutate(objectEnv);
    const object = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), objectEnv, clock());
    assert.equal(object.status, 403);
    assert.deepEqual(objectEnv.ARTIFACTS.calls, []);

    const galleryEnv = environment();
    mutate(galleryEnv);
    const gallery = await workerGalleryStatus(await galleryStatusRequest(await galleryItems(1)), galleryEnv, clock());
    assert.equal(gallery.status, 403);
    assert.deepEqual(galleryEnv.ARTIFACTS.calls, []);

    const reviewEnv = environment();
    mutate(reviewEnv);
    const review = await workerReview(
      workerReviewRequest(await workerReviewGrant(workerReviewClaims())),
      reviewEnv,
      clock(),
    );
    assert.equal(review.status, 404);
    assert.deepEqual(reviewEnv.ARTIFACTS.calls, []);
  }
});


test('Worker upload stores once, publishes one exact Queue job, and signs a v3 Stored receipt', async () => {
  const env = environment();
  const response = await workerUpload(workerUploadRequest(pngBody()), env, clock());

  assert.equal(response.status, 200);
  const stored = env.ARTIFACTS.objects.get(fixture.worker_claims.upload_grant.object_key);
  assert.ok(stored);
  assert.deepEqual([...stored.bytes], [...pngBody()]);
  assert.equal(stored.customMetadata.protocolVersion, '3');
  assert.equal(stored.customMetadata.storageIdentity, fixture.worker_claims.upload_grant.storage_identity);
  assert.equal(stored.customMetadata.validationContractVersion, fixture.worker_claims.upload_grant.validation_contract_version);
  assert.equal(env.VALIDATION_QUEUE.jobs.length, 1);
  assert.deepEqual(env.VALIDATION_QUEUE.jobs[0], expectedWorkerJob(stored));
  const expectedReceipt = await expectedWorkerReceipt(stored);
  assert.equal((await response.json()).receipt, expectedReceipt);
  assert.equal(env.IMAGES.calls, 0);
  assertNoWorkerMarkerOrLease(env);
});

test('Worker existing-object retry cancels the unused body and republishes the same job bytes', async () => {
  const env = environment();
  const first = await workerUpload(workerUploadRequest(pngBody()), env, clock());
  assert.equal(first.status, 200);
  const firstJob = JSON.stringify(env.VALIDATION_QUEUE.jobs[0]);

  let cancellations = 0;
  const retryBody = new ReadableStream({
    start() {},
    cancel() {
      cancellations += 1;
    },
  });
  env.VALIDATION_QUEUE.onSend = () => assert.equal(cancellations, 1);
  const retry = await workerUpload(workerUploadRequest(retryBody, {
    'Content-Length': String(fixture.worker_claims.upload_grant.declared_bytes),
  }), env, clock());

  assert.equal(retry.status, 200);
  assert.equal(cancellations, 1);
  assert.equal(env.VALIDATION_QUEUE.jobs.length, 2);
  assert.equal(JSON.stringify(env.VALIDATION_QUEUE.jobs[1]), firstJob);
  assert.equal(env.ARTIFACTS.putCalls.length, 1);
  assert.equal(env.IMAGES.calls, 0);
  assertNoWorkerMarkerOrLease(env);
});

test('Worker existing-object metadata conflicts do not overwrite, queue, or receipt', async () => {
  const claims = fixture.worker_claims.upload_grant;
  const conflictCases = [
    { storageIdentity: 'f'.repeat(64) },
    { validationContractVersion: 'managed-image-v2' },
    { ordinal: '9' },
    { policyFingerprint: 'c'.repeat(64) },
    { maxEdge: '1' },
  ];
  for (const override of conflictCases) {
    const env = environment();
    env.ARTIFACTS.seed(claims.object_key, pngBody(), workerObjectMetadata(override), 'conflict-version');

    const response = await workerUpload(workerUploadRequest(pngBody()), env, clock());

    assert.equal(response.status, 409);
    assert.equal(env.ARTIFACTS.objects.get(claims.object_key).version, 'conflict-version');
    assert.deepEqual(env.VALIDATION_QUEUE.jobs, []);
    assert.equal(env.ARTIFACTS.putCalls.length, 0);
    assert.equal(env.IMAGES.calls, 0);
    assertNoWorkerMarkerOrLease(env);
  }
});

test('Worker upload recovers an exact winner after a lost put response', async () => {
  const env = environment();
  env.ARTIFACTS.throwAfterPut = (key) => key === fixture.worker_claims.upload_grant.object_key;

  const response = await workerUpload(workerUploadRequest(pngBody()), env, clock());

  assert.equal(response.status, 200);
  const stored = env.ARTIFACTS.objects.get(fixture.worker_claims.upload_grant.object_key);
  assert.ok(stored);
  assert.deepEqual(env.VALIDATION_QUEUE.jobs[0], expectedWorkerJob(stored));
  assert.equal((await response.json()).receipt, await expectedWorkerReceipt(stored));
  assert.equal(env.IMAGES.calls, 0);
  assertNoWorkerMarkerOrLease(env);
});

test('Worker upload recovers an exact conditional race winner', async () => {
  const env = environment();
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

  const response = await workerUpload(workerUploadRequest(body), env, clock());

  assert.equal(response.status, 200);
  assert.equal(cancelled, true);
  const stored = env.ARTIFACTS.objects.get(fixture.worker_claims.upload_grant.object_key);
  assert.ok(stored);
  assert.deepEqual(env.VALIDATION_QUEUE.jobs[0], expectedWorkerJob(stored));
  assert.equal((await response.json()).receipt, await expectedWorkerReceipt(stored));
  assert.equal(env.IMAGES.calls, 0);
  assertNoWorkerMarkerOrLease(env);
});

test('Worker upload rejects object version or ETag changes before Queue publication', async () => {
  for (const mutate of [
    (stored) => { stored.version = 'changed-version'; },
    (stored) => { stored.etag = 'changed-etag'; },
  ]) {
    const env = environment();
    env.ARTIFACTS.beforeHead = (key) => {
      if (key === fixture.worker_claims.upload_grant.object_key
        && env.ARTIFACTS.headCalls.length === 2) {
        mutate(env.ARTIFACTS.objects.get(key));
      }
    };

    const response = await workerUpload(workerUploadRequest(pngBody()), env, clock());

    assert.equal(response.status, 409);
    assert.deepEqual(env.VALIDATION_QUEUE.jobs, []);
    assert.equal(env.IMAGES.calls, 0);
    assertNoWorkerMarkerOrLease(env);
  }
});

test('Worker upload preserves overrun and underflow classifications without Queue or receipt', async () => {
  const overrun = new Uint8Array(fixture.worker_claims.upload_grant.declared_bytes + 1);
  const envOverrun = environment();
  const overrunResponse = await workerUpload(workerUploadRequest(overrun, {
    'Content-Length': String(fixture.worker_claims.upload_grant.declared_bytes),
  }), envOverrun, clock());
  assert.equal(overrunResponse.status, 413);
  assert.deepEqual(envOverrun.VALIDATION_QUEUE.jobs, []);
  assert.equal(envOverrun.ARTIFACTS.objects.has(fixture.worker_claims.upload_grant.object_key), false);

  const envUnderflow = environment();
  const underflow = new Uint8Array(fixture.worker_claims.upload_grant.declared_bytes - 1);
  const underflowResponse = await workerUpload(workerUploadRequest(underflow, {
    'Content-Length': String(fixture.worker_claims.upload_grant.declared_bytes),
  }), envUnderflow, clock());
  assert.equal(underflowResponse.status, 409);
  assert.deepEqual(envUnderflow.VALIDATION_QUEUE.jobs, []);
  assert.equal(envUnderflow.ARTIFACTS.objects.has(fixture.worker_claims.upload_grant.object_key), true);
});

test('Worker Queue failure or untimely acceptance returns no receipt and keeps the artifact', async () => {
  const scenarios = [
    { configure: (env) => { delete env.VALIDATION_QUEUE; }, jobs: 0 },
    { configure: (env) => { env.VALIDATION_QUEUE.throwOnSend = true; }, jobs: 1 },
    {
      configure: (env, runtime) => {
        env.VALIDATION_QUEUE.onSend = () => {
          runtime.current = Math.min(
            fixture.worker_claims.upload_grant.accept_until,
            fixture.worker_claims.upload_grant.validation_until,
          );
        };
      },
      jobs: 1,
    },
  ];
  for (const scenario of scenarios) {
    const env = environment();
    const runtime = mutableClock(now);
    scenario.configure(env, runtime);

    const response = await workerUpload(workerUploadRequest(pngBody()), env, runtime);

    assert.equal(response.status, 503);
    assert.deepEqual(await response.json(), { error: 'Upload unavailable.' });
    assert.equal(env.ARTIFACTS.objects.has(fixture.worker_claims.upload_grant.object_key), true);
    assert.equal((env.VALIDATION_QUEUE && env.VALIDATION_QUEUE.jobs.length) || 0, scenario.jobs);
    assert.equal(env.IMAGES.calls, 0);
    assertNoWorkerMarkerOrLease(env);
  }
});

test('Worker upload reaching upload_until before or during Queue publication returns no receipt', async () => {
  const beforeSend = environment();
  const beforeClock = mutableClock(now);
  beforeSend.ARTIFACTS.afterHead = (key, descriptor) => {
    if (key === fixture.worker_claims.upload_grant.object_key && descriptor
      && beforeSend.ARTIFACTS.headCalls.length === 2) {
      beforeClock.current = fixture.worker_claims.upload_grant.upload_until;
    }
  };
  const notSent = await workerUpload(workerUploadRequest(pngBody()), beforeSend, beforeClock);
  assert.equal(notSent.status, 503);
  assert.deepEqual(beforeSend.VALIDATION_QUEUE.jobs, []);
  assert.equal(beforeSend.ARTIFACTS.objects.has(fixture.worker_claims.upload_grant.object_key), true);
  assert.equal(beforeSend.IMAGES.calls, 0);
  assertNoWorkerMarkerOrLease(beforeSend);

  const duringSend = environment();
  const duringClock = mutableClock(now);
  duringSend.VALIDATION_QUEUE.onSend = () => {
    duringClock.current = fixture.worker_claims.upload_grant.upload_until;
  };
  const noReceipt = await workerUpload(workerUploadRequest(pngBody()), duringSend, duringClock);
  assert.equal(noReceipt.status, 503);
  assert.equal(duringSend.VALIDATION_QUEUE.jobs.length, 1);
  assert.equal(duringSend.ARTIFACTS.objects.has(fixture.worker_claims.upload_grant.object_key), true);
  assert.equal(duringSend.IMAGES.calls, 0);
  assertNoWorkerMarkerOrLease(duringSend);
});

test('Worker Queue send waits for final exact HEAD before any receipt is observable', async () => {
  const env = environment();
  let releaseSend;
  const sendGate = new Promise((resolve) => { releaseSend = resolve; });
  env.VALIDATION_QUEUE.wait = sendGate;
  let settled = false;
  const upload = workerUpload(workerUploadRequest(pngBody()), env, clock()).then((response) => {
    settled = true;
    return response;
  });
  await env.VALIDATION_QUEUE.sent;

  assert.equal(settled, false);
  assert.equal(env.ARTIFACTS.headCalls.at(-1), fixture.worker_claims.upload_grant.object_key);
  assert.equal(env.VALIDATION_QUEUE.jobs.length, 1);

  releaseSend();
  const response = await upload;
  assert.equal(response.status, 200);
  assert.ok((await response.json()).receipt);
  assert.equal(env.IMAGES.calls, 0);
  assertNoWorkerMarkerOrLease(env);
});

test('Worker upload closes upload_until equality before mutation', async () => {
  const env = environment();
  const response = await workerUpload(
    workerUploadRequest(pngBody()),
    env,
    { now: () => fixture.worker_claims.upload_grant.upload_until },
  );

  assert.equal(response.status, 400);
  assert.deepEqual(env.ARTIFACTS.calls, []);
  assert.deepEqual(env.UPLOAD_RATE_LIMITER.keys, []);
  assert.deepEqual(env.VALIDATION_QUEUE.jobs, []);
});

test('live upload dispatch accepts Worker grants through the Queue-backed ingress', async () => {
  const env = environment();
  const response = await handleRequest(workerUploadRequest(pngBody()), env, clock());

  assert.equal(response.status, 200);
  assert.equal(env.UPLOAD_RATE_LIMITER.keys.length, 1);
  assert.equal(env.VALIDATION_QUEUE.jobs.length, 1);
});

test('live fetch and handleRequest dispatch use v3 Worker ingress', async () => {
  const source = await readFile(new URL('../src/index.js', import.meta.url), 'utf8');
  const defaultExport = source.match(/export default \{[\s\S]*?\n\};/);
  const handle = source.match(/export async function handleRequest[\s\S]*?\n}\n\nfunction preflight/);
  assert.ok(defaultExport);
  assert.ok(handle);
  assert.equal(defaultExport[0].includes('workerQueueBatch'), true);
  assert.equal(handle[0].includes('workerUpload'), true);
  assert.equal(handle[0].includes('workerGalleryStatus'), true);
  assert.equal(handle[0].includes('workerReview'), true);
  assert.equal(handle[0].includes('workerObjectOperation'), true);

  const env = environment();
  const response = await handleRequest(await galleryStatusRequest([]), env, clock());
  assert.equal(response.status, 200);

  assert.equal(env.IMAGES.calls, 0);

  const objectResponse = await handleRequest(workerObjectRequest(workerTokens.object_request_known_delete), env, clock());
  assert.equal(objectResponse.status, 200);
});

test('Worker object delete removes accepted and rejected results before exact artifacts', async () => {
  for (const outcome of ['accepted', 'rejected']) {
    const claims = fixture.worker_claims.object_request_known_delete;
    const env = environment();
    seedWorkerObjectArtifact(env, claims);
    const resultKey = await seedWorkerObjectResult(env, claims, outcome);

    const response = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), env, clock());
    assert.equal(response.status, 200);
    assert.equal((await response.json()).result, await expectedWorkerObjectResult(claims, 'absent'));
    assert.equal(env.ARTIFACTS.objects.has(resultKey), false);
    assert.equal(env.ARTIFACTS.objects.has(claims.object_key), false);
    assert.ok(env.ARTIFACTS.deleteCalls.indexOf(resultKey) < env.ARTIFACTS.deleteCalls.indexOf(claims.object_key));
    assertNoWorkerMarkerOrLease(env);
  }
});

test('Worker object delete handles no-result and known artifact-absent cleanup', async () => {
  const claims = fixture.worker_claims.object_request_known_delete;
  const env = environment();
  seedWorkerObjectArtifact(env, claims);

  const noResult = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), env, clock());
  assert.equal(noResult.status, 200);
  assert.equal((await noResult.json()).result, await expectedWorkerObjectResult(claims, 'absent'));
  assert.equal(env.ARTIFACTS.objects.has(claims.object_key), false);

  const resultKey = await seedWorkerObjectResult(env, claims);
  const retry = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), env, clock());
  assert.equal(retry.status, 200);
  assert.equal((await retry.json()).result, await expectedWorkerObjectResult(claims, 'absent'));
  assert.equal(env.ARTIFACTS.objects.has(resultKey), false);
  assertNoWorkerMarkerOrLease(env);
});

test('Worker object unknown delete resolves present artifacts and is idempotent when absent', async () => {
  const claims = fixture.worker_claims.object_request_unknown_delete;
  const env = environment();
  seedWorkerObjectArtifact(env, {
    ...claims,
    object_version: fixture.worker_claims.object_request_known_delete.object_version,
    etag: fixture.worker_claims.object_request_known_delete.etag,
  });
  const resultKey = await seedWorkerObjectResult(env, {
    ...claims,
    object_version: fixture.worker_claims.object_request_known_delete.object_version,
    etag: fixture.worker_claims.object_request_known_delete.etag,
  });

  const response = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_unknown_delete), env, clock());
  assert.equal(response.status, 200);
  const payload = await response.json();
  assert.equal(payload.result, await expectedWorkerObjectResult(claims, 'absent'));
  assert.deepEqual(
    (await verifyWorkerObjectResult(payload.result, keyring(), fixture.environment, now)).claims,
    { request_id: claims.request_id, object_key: claims.object_key, object_version: '-', status: 'absent', expires_at: claims.expires_at },
  );
  assert.equal(env.ARTIFACTS.objects.has(resultKey), false);
  assert.equal(env.ARTIFACTS.objects.has(claims.object_key), false);
  assert.ok(env.ARTIFACTS.deleteCalls.indexOf(resultKey) < env.ARTIFACTS.deleteCalls.indexOf(claims.object_key));

  const absent = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_unknown_delete), env, clock());
  assert.equal(absent.status, 200);
  assert.equal((await absent.json()).result, await expectedWorkerObjectResult(claims, 'absent'));
  assertNoWorkerMarkerOrLease(env);
});

test('Worker object delete refuses foreign or invalid terminal results without artifact deletion', async () => {
  for (const invalidResult of [false, true]) {
    const claims = fixture.worker_claims.object_request_known_delete;
    const env = environment();
    seedWorkerObjectArtifact(env, claims);
    const resultKey = await validationResultKey(claims.object_key, claims.object_version);
    if (invalidResult) {
      env.ARTIFACTS.seedBytes(resultKey, new TextEncoder().encode('not-json'));
    } else {
      await seedWorkerObjectResult(env, claims, 'accepted', { etag: 'different-etag' });
    }

    const response = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), env, clock());
    assert.equal(response.status, 200);
    assert.equal((await response.json()).result, await expectedWorkerObjectResult(claims, 'version_mismatch'));
    assert.equal(env.ARTIFACTS.objects.has(claims.object_key), true);
    assert.equal(env.ARTIFACTS.deleteCalls.includes(claims.object_key), false);
    assert.equal(env.ARTIFACTS.objects.has(resultKey), true);
  }
});

test('Worker object delete reports artifact metadata, version, etag, and size mismatches', async () => {
  const claims = fixture.worker_claims.object_request_known_delete;
  const cases = [
    { object_version: 'different-version' },
    { etag: 'different-etag' },
    { body: new Uint8Array(4321) },
    { metadata: { storageIdentity: 'f'.repeat(64) } },
  ];
  for (const mismatch of cases) {
    const env = environment();
    seedWorkerObjectArtifact(env, { ...claims, ...mismatch }, mismatch.body || pngBody(), mismatch.metadata || {});
    const response = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), env, clock());
    assert.equal(response.status, 200);
    assert.equal((await response.json()).result, await expectedWorkerObjectResult(claims, 'version_mismatch'));
    assert.equal(env.ARTIFACTS.objects.has(claims.object_key), true);
    assert.equal(env.ARTIFACTS.deleteCalls.includes(claims.object_key), false);
  }
});

test('Worker object delete maps result and artifact provider failures to retryable errors', async () => {
  const claims = fixture.worker_claims.object_request_known_delete;

  const artifactReadFailure = environment();
  seedWorkerObjectArtifact(artifactReadFailure, claims);
  await seedWorkerObjectResult(artifactReadFailure, claims);
  artifactReadFailure.ARTIFACTS.throwOnHead = (key) => key === claims.object_key;
  assert.equal(
    (await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), artifactReadFailure, clock())).status,
    503,
  );
  assert.deepEqual(artifactReadFailure.ARTIFACTS.deleteCalls, []);
  assert.equal(artifactReadFailure.ARTIFACTS.objects.has(claims.object_key), true);

  const resultReadFailure = environment();
  seedWorkerObjectArtifact(resultReadFailure, claims);
  const readFailureKey = await seedWorkerObjectResult(resultReadFailure, claims);
  resultReadFailure.ARTIFACTS.throwOnGet.add(readFailureKey);
  assert.equal(
    (await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), resultReadFailure, clock())).status,
    503,
  );
  assert.equal(resultReadFailure.ARTIFACTS.objects.has(claims.object_key), true);

  const resultDeleteFailure = environment();
  seedWorkerObjectArtifact(resultDeleteFailure, claims);
  const resultKey = await seedWorkerObjectResult(resultDeleteFailure, claims);
  resultDeleteFailure.ARTIFACTS.throwOnDelete.add(resultKey);
  assert.equal(
    (await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), resultDeleteFailure, clock())).status,
    503,
  );
  assert.equal(resultDeleteFailure.ARTIFACTS.objects.has(claims.object_key), true);

  const resultDeleteUncertain = environment();
  seedWorkerObjectArtifact(resultDeleteUncertain, claims);
  const uncertainKey = await seedWorkerObjectResult(resultDeleteUncertain, claims);
  resultDeleteUncertain.ARTIFACTS.noopDelete.add(uncertainKey);
  assert.equal(
    (await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), resultDeleteUncertain, clock())).status,
    503,
  );
  assert.equal(resultDeleteUncertain.ARTIFACTS.objects.has(claims.object_key), true);

  const artifactDeleteFailure = environment();
  seedWorkerObjectArtifact(artifactDeleteFailure, claims);
  await seedWorkerObjectResult(artifactDeleteFailure, claims);
  artifactDeleteFailure.ARTIFACTS.throwOnDelete.add(claims.object_key);
  assert.equal(
    (await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), artifactDeleteFailure, clock())).status,
    503,
  );
  assert.equal(artifactDeleteFailure.ARTIFACTS.objects.has(claims.object_key), true);

  const artifactRereadFailure = environment();
  seedWorkerObjectArtifact(artifactRereadFailure, claims);
  const rereadResultKey = await seedWorkerObjectResult(artifactRereadFailure, claims);
  artifactRereadFailure.ARTIFACTS.throwOnHead = (key) => (
    key === claims.object_key && artifactRereadFailure.ARTIFACTS.headCalls.filter((call) => call === claims.object_key).length === 2
  );
  assert.equal(
    (await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), artifactRereadFailure, clock())).status,
    503,
  );
  assert.equal(artifactRereadFailure.ARTIFACTS.objects.has(rereadResultKey), false);
  assert.equal(artifactRereadFailure.ARTIFACTS.objects.has(claims.object_key), true);

  artifactRereadFailure.ARTIFACTS.throwOnHead = false;
  const recovered = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), artifactRereadFailure, clock());
  assert.equal(recovered.status, 200);
  assert.equal((await recovered.json()).result, await expectedWorkerObjectResult(claims, 'absent'));
  assert.equal(artifactRereadFailure.ARTIFACTS.objects.has(claims.object_key), false);
});

test('Worker object delete detects result races and converges on retry', async () => {
  const claims = fixture.worker_claims.object_request_known_delete;
  const env = environment();
  seedWorkerObjectArtifact(env, claims);
  const resultKey = await seedWorkerObjectResult(env, claims);
  env.ARTIFACTS.afterDelete = (key) => {
    if (key === claims.object_key) env.ARTIFACTS.seedJson(resultKey, workerObjectTerminalResult(claims));
  };

  const raced = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), env, clock());
  assert.equal(raced.status, 503);
  assert.equal(env.ARTIFACTS.objects.has(claims.object_key), false);
  assert.equal(env.ARTIFACTS.objects.has(resultKey), true);

  env.ARTIFACTS.afterDelete = null;
  const retry = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), env, clock());
  assert.equal(retry.status, 200);
  assert.equal((await retry.json()).result, await expectedWorkerObjectResult(claims, 'absent'));
  assert.equal(env.ARTIFACTS.objects.has(resultKey), false);
});

test('Worker object delete treats artifact reappearance after delete as retryable without deleting successors', async () => {
  const claims = fixture.worker_claims.object_request_known_delete;
  const exactArtifact = {
    ...claims,
    object_version: claims.object_version,
    etag: claims.etag,
  };
  const successorArtifact = {
    ...claims,
    object_version: 'successor-version',
    etag: 'successor-etag',
  };

  const reappeared = environment();
  seedWorkerObjectArtifact(reappeared, exactArtifact);
  await seedWorkerObjectResult(reappeared, exactArtifact);
  reappeared.ARTIFACTS.afterDelete = (key) => {
    if (key === claims.object_key) seedWorkerObjectArtifact(reappeared, exactArtifact);
  };
  const reappearedResponse = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), reappeared, clock());
  assert.equal(reappearedResponse.status, 503);
  assert.deepEqual(await reappearedResponse.json(), { error: 'Request unavailable.' });
  assert.equal(reappeared.ARTIFACTS.objects.has(claims.object_key), true);
  assert.equal(reappeared.ARTIFACTS.objects.get(claims.object_key).version, claims.object_version);

  reappeared.ARTIFACTS.afterDelete = null;
  const reappearedRetry = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), reappeared, clock());
  assert.equal(reappearedRetry.status, 200);
  assert.equal((await reappearedRetry.json()).result, await expectedWorkerObjectResult(claims, 'absent'));
  assert.equal(reappeared.ARTIFACTS.objects.has(claims.object_key), false);

  const changed = environment();
  seedWorkerObjectArtifact(changed, exactArtifact);
  await seedWorkerObjectResult(changed, exactArtifact);
  changed.ARTIFACTS.afterDelete = (key) => {
    if (key === claims.object_key) seedWorkerObjectArtifact(changed, successorArtifact);
  };
  const changedResponse = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), changed, clock());
  assert.equal(changedResponse.status, 503);
  assert.deepEqual(await changedResponse.json(), { error: 'Request unavailable.' });
  assert.equal(changed.ARTIFACTS.objects.has(claims.object_key), true);
  assert.equal(changed.ARTIFACTS.objects.get(claims.object_key).version, successorArtifact.object_version);

  changed.ARTIFACTS.afterDelete = null;
  const changedRetry = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), changed, clock());
  assert.equal(changedRetry.status, 200);
  assert.equal((await changedRetry.json()).result, await expectedWorkerObjectResult(claims, 'version_mismatch'));
  assert.equal(changed.ARTIFACTS.objects.has(claims.object_key), true);
  assert.equal(changed.ARTIFACTS.objects.get(claims.object_key).version, successorArtifact.object_version);
  assert.equal(changed.ARTIFACTS.deleteCalls.filter((key) => key === claims.object_key).length, 1);
});

test('Worker object operation rejects forbidden or expired authority before reads', async () => {
  const claims = fixture.worker_claims.object_request_known_delete;
  for (const options of [
    { method: 'GET' },
    { url: 'https://media.example.test/v1/not-object' },
  ]) {
    const env = environment();
    const response = await workerObjectOperation(
      workerObjectRequest(workerTokens.object_request_known_delete, {}, options),
      env,
      clock(),
    );
    assert.equal(response.status, 404);
    assert.deepEqual(env.ARTIFACTS.calls, []);
  }

  for (const headers of [
    { Cookie: 'sid=1' },
    { Authorization: 'Bearer x' },
    { 'X-EForms-Worker-Grant': 'x' },
  ]) {
    const env = environment();
    const response = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete, headers), env, clock());
    assert.equal(response.status, 403);
    assert.deepEqual(env.ARTIFACTS.calls, []);
  }

  const expiredEnv = environment();
  const expired = await workerObjectOperation(
    workerObjectRequest(workerTokens.object_request_known_delete),
    expiredEnv,
    { now: () => claims.expires_at },
  );
  assert.equal(expired.status, 403);
  assert.deepEqual(expiredEnv.ARTIFACTS.calls, []);

  const malformedEnv = environment();
  const malformed = await workerObjectOperation(workerObjectRequest('not-a-token'), malformedEnv, clock());
  assert.equal(malformed.status, 403);
  assert.deepEqual(malformedEnv.ARTIFACTS.calls, []);

  const missingR2 = environment();
  delete missingR2.ARTIFACTS;
  const unavailable = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_delete), missingR2, clock());
  assert.equal(unavailable.status, 503);
});

test('Worker object operation emits no result after crossing expiry before signing', async () => {
  const claims = fixture.worker_claims.object_request_known_inspect;
  const env = environment();
  seedWorkerObjectArtifact(env, claims);
  let calls = 0;
  const response = await workerObjectOperation(
    workerObjectRequest(workerTokens.object_request_known_inspect),
    env,
    { now: () => (calls++ === 0 ? claims.expires_at - 1 : claims.expires_at) },
  );
  assert.equal(response.status, 403);
  assert.equal(env.ARTIFACTS.objects.has(claims.object_key), true);
  assert.equal(env.ARTIFACTS.deleteCalls.length, 0);
  assertNoWorkerMarkerOrLease(env);
});

test('Worker object inspect reports exact present, absent, and mismatch without mutation', async () => {
  const claims = fixture.worker_claims.object_request_known_inspect;
  const presentEnv = environment();
  seedWorkerObjectArtifact(presentEnv, claims);
  const present = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_inspect), presentEnv, clock());
  assert.equal(present.status, 200);
  assert.equal((await present.json()).result, await expectedWorkerObjectResult(claims, 'present'));
  assert.equal(presentEnv.ARTIFACTS.deleteCalls.length, 0);
  assertNoWorkerMarkerOrLease(presentEnv);

  const absentEnv = environment();
  const absent = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_inspect), absentEnv, clock());
  assert.equal(absent.status, 200);
  assert.equal((await absent.json()).result, await expectedWorkerObjectResult(claims, 'absent'));
  assert.equal(absentEnv.ARTIFACTS.deleteCalls.length, 0);
  assertNoWorkerMarkerOrLease(absentEnv);

  const mismatchEnv = environment();
  seedWorkerObjectArtifact(mismatchEnv, { ...claims, etag: 'different-etag' });
  const mismatch = await workerObjectOperation(workerObjectRequest(workerTokens.object_request_known_inspect), mismatchEnv, clock());
  assert.equal(mismatch.status, 200);
  assert.equal((await mismatch.json()).result, await expectedWorkerObjectResult(claims, 'version_mismatch'));
  assert.equal(mismatchEnv.ARTIFACTS.deleteCalls.length, 0);
  assertNoWorkerMarkerOrLease(mismatchEnv);
});

test('Worker gallery-status returns an exact empty canonical snapshot', async () => {
  const env = environment();
  const response = await workerGalleryStatus(await galleryStatusRequest([]), env, clock());

  assert.equal(response.status, 200);
  const body = new Uint8Array(await response.arrayBuffer());
  const payload = JSON.parse(new TextDecoder().decode(body));
  const verified = await verifyWorkerGalleryStatusResult(payload.result, keyring(), fixture.environment, now);
  assert.equal(verified.ok, true);
  assert.deepEqual(payload.statuses, []);
  assert.equal(verified.claims.item_count, 0);
  assert.equal(verified.claims.items_sha256, await workerGalleryItemsSha256([]));
  assert.equal(verified.claims.statuses_sha256, await workerGalleryStatusesSha256([], []));
  assert.deepEqual([...body], [...await canonicalGalleryStatusResponseBytes(payload.result, [])]);
  assert.deepEqual(env.ARTIFACTS.calls, []);
});

test('Worker gallery-status reports accepted, pending, rejected, and expired absent items in order', async () => {
  const items = await galleryItems(4);
  items[1].validation_until = now + 200;
  const env = environment();
  env.ARTIFACTS.seedJson(await validationResultKey(items[0].object_key, items[0].object_version), terminalResult(items[0], 'accepted'));
  env.ARTIFACTS.seedJson(await validationResultKey(items[2].object_key, items[2].object_version), terminalResult(items[2], 'rejected'));
  const runtime = mutableClock(now);
  runtime.current = items[3].validation_until;

  const response = await workerGalleryStatus(await galleryStatusRequest(items), env, runtime);

  assert.equal(response.status, 200);
  const body = new Uint8Array(await response.arrayBuffer());
  const payload = JSON.parse(new TextDecoder().decode(body));
  assert.deepEqual(payload.statuses, [
    { upload_id: items[0].upload_id, status: 'accepted', mime: 'image/png', width: 32, height: 24 },
    { upload_id: items[1].upload_id, status: 'pending' },
    { upload_id: items[2].upload_id, status: 'unavailable' },
    { upload_id: items[3].upload_id, status: 'unavailable' },
  ]);
  const verified = await verifyWorkerGalleryStatusResult(payload.result, keyring(), fixture.environment, runtime.current);
  assert.equal(verified.ok, true);
  assert.equal(verified.claims.request_id, fixture.worker_claims.gallery_status_request.request_id);
  assert.equal(verified.claims.submission_id, fixture.worker_claims.gallery_status_request.submission_id);
  assert.equal(verified.claims.item_count, items.length);
  assert.equal(verified.claims.items_sha256, await workerGalleryItemsSha256(items));
  assert.equal(verified.claims.statuses_sha256, await workerGalleryStatusesSha256(payload.statuses, items));
  assert.deepEqual([...body], [...await canonicalGalleryStatusResponseBytes(payload.result, payload.statuses, items)]);
  assert.equal(env.ARTIFACTS.headCalls.length, 0);
  assert.equal(env.ARTIFACTS.putCalls.length, 0);
  assertNoWorkerMarkerOrLease(env);
});

test('Worker gallery-status fails the whole operation without partial statuses for invalid result reads', async () => {
  for (const seed of [
    async (env, item) => env.ARTIFACTS.seedJson(await validationResultKey(item.object_key, item.object_version), terminalResult(item, 'accepted', { environment_id: 'foreign-env' })),
    async (env, item) => env.ARTIFACTS.seedBytes(await validationResultKey(item.object_key, item.object_version), new TextEncoder().encode(JSON.stringify({ not: 'canonical' }))),
    async (env, item) => env.ARTIFACTS.seedBytes(await validationResultKey(item.object_key, item.object_version), new TextEncoder().encode(JSON.stringify(terminalResult(item, 'accepted'), null, 2))),
    async (env, item) => env.ARTIFACTS.seedBody(await validationResultKey(item.object_key, item.object_version), bodyFromChunks([
      new Uint8Array(4096),
      new Uint8Array([123]),
    ]), { size: 4096 }),
    async (env, item) => env.ARTIFACTS.throwOnGet.add(await validationResultKey(item.object_key, item.object_version)),
  ]) {
    const items = await galleryItems(2);
    const env = environment();
    env.ARTIFACTS.seedJson(await validationResultKey(items[0].object_key, items[0].object_version), terminalResult(items[0], 'accepted'));
    await seed(env, items[1]);

    const response = await workerGalleryStatus(await galleryStatusRequest(items), env, clock());

    assert.equal(response.status, 503);
    assert.deepEqual(await response.json(), { error: 'Request unavailable.' });
  }
});

test('Worker gallery-status rejects expired equality requests before reads and emits no result if expiry crosses after reads', async () => {
  const items = await galleryItems(1);
  for (const expiresAt of [now - 1, now]) {
    const env = environment();
    const response = await workerGalleryStatus(await galleryStatusRequest(items, {
      claims: {
        ...fixture.worker_claims.gallery_status_request,
        items_sha256: await workerGalleryItemsSha256(items),
        item_count: items.length,
        expires_at: expiresAt,
      },
    }), env, { now: () => now });
    assert.equal(response.status, 403);
    assert.deepEqual(env.ARTIFACTS.calls, []);
  }

  const env = environment();
  env.ARTIFACTS.seedJson(await validationResultKey(items[0].object_key, items[0].object_version), terminalResult(items[0], 'accepted'));
  const runtime = mutableClock(now);
  const request = await galleryStatusRequest(items, {
    claims: {
      ...fixture.worker_claims.gallery_status_request,
      items_sha256: await workerGalleryItemsSha256(items),
      item_count: items.length,
      expires_at: now + 1,
    },
  });
  env.ARTIFACTS.afterGet = () => { runtime.current = now + 1; };
  const response = await workerGalleryStatus(request, env, runtime);
  assert.equal(response.status, 403);
  assert.deepEqual(env.ARTIFACTS.calls, ['get']);
});

test('Worker gallery-status fails closed on terminal results validated at or after item deadline', async () => {
  const items = await galleryItems(1);
  for (const validatedAt of [items[0].validation_until, items[0].validation_until + 1]) {
    const env = environment();
    env.ARTIFACTS.seedJson(
      await validationResultKey(items[0].object_key, items[0].object_version),
      terminalResult(items[0], 'accepted', { validated_at: validatedAt }),
    );
    const response = await workerGalleryStatus(await galleryStatusRequest(items), env, clock());
    assert.equal(response.status, 503);
  }
});

test('Worker gallery-status rejects malformed request envelopes before storage reads', async () => {
  const items = await galleryItems(1);
  const valid = await galleryStatusRequest(items);
  const badClaims = {
    ...fixture.worker_claims.gallery_status_request,
    items_sha256: await workerGalleryItemsSha256(items),
    item_count: items.length,
  };
  const cases = [
    new Request(valid.url, { method: 'GET', headers: valid.headers }),
    await galleryStatusRequest(items, { headers: { Cookie: 'sid=1' } }),
    await galleryStatusRequest(items, { headers: { Authorization: 'Bearer token' } }),
    await galleryStatusRequest(items, { headers: { 'X-EForms-Worker-Grant': 'token' } }),
    await galleryStatusRequest(items, { headers: { 'Content-Type': 'application/json; charset=utf-8' } }),
    new Request(valid.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: new Uint8Array([0xff]), duplex: 'half' }),
    rawGalleryStatusRequest(new TextEncoder().encode(JSON.stringify({ items, request: workerTokens.gallery_status_request, extra: true }))),
    rawGalleryStatusRequest(new TextEncoder().encode(JSON.stringify({ request: workerTokens.gallery_status_request, items }))),
    await galleryStatusRequest(items, { claims: { ...badClaims, item_count: items.length + 1 } }),
    await galleryStatusRequest(items, { claims: { ...badClaims, items_sha256: '0'.repeat(64) } }),
    await galleryStatusRequest([], { claims: { ...badClaims, items_sha256: await workerGalleryItemsSha256([]), item_count: 0, expires_at: now - 1 } }),
    await galleryStatusRequest(items, { environment: 'wrong-environment' }),
  ];
  for (const request of cases) {
    const env = environment();
    const response = await workerGalleryStatus(request, env, clock());
    assert.notEqual(response.status, 200);
    assert.deepEqual(env.ARTIFACTS.calls, []);
  }
});

test('Worker gallery-status cancels over-cap request bodies and max-24 fits', async () => {
  let cancelled = false;
  const overCapBody = bodyFromChunks([
    new Uint8Array(WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES),
    new Uint8Array([123]),
  ], { onCancel: () => { cancelled = true; } });
  const overCap = await workerGalleryStatus(new Request('https://media.example.test/v1/gallery-status', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: overCapBody,
    duplex: 'half',
  }), environment(), clock());
  assert.equal(overCap.status, 413);
  assert.equal(cancelled, true);

  const items = await galleryItems(MANAGED_STAGED_MAX_FILES);
  const env = environment();
  const response = await workerGalleryStatus(await galleryStatusRequest(items), env, clock());
  assert.equal(response.status, 200);
  const payload = await response.json();
  assert.equal(payload.statuses.length, MANAGED_STAGED_MAX_FILES);
});

test('Worker review directly serves accepted preview and download from exact terminal results', async () => {
  const previewClaims = workerReviewClaims();
  const previewEnv = environment();
  await seedAcceptedWorkerReview(previewEnv, previewClaims);
  const previewCache = new FakeCache();
  const preview = await workerReview(
    workerReviewRequest(await workerReviewGrant(previewClaims)),
    previewEnv,
    { ...clock(), cache: previewCache },
  );

  assert.equal(preview.status, 200);
  assert.equal(preview.headers.get('content-type'), 'image/jpeg');
  assert.equal(preview.headers.get('cache-control'), 'private, no-store, max-age=0');
  assert.equal(preview.headers.get('referrer-policy'), 'no-referrer');
  assert.equal(preview.headers.get('x-content-type-options'), 'nosniff');
  assert.deepEqual([...new Uint8Array(await preview.arrayBuffer())], [255, 216, 255, 217]);
  assert.equal(previewEnv.IMAGES.calls, 1);
  assert.equal(previewCache.matches, 1);
  assert.equal(previewCache.puts, 1);
  assert.equal(previewEnv.ARTIFACTS.getCounts.get(await validationResultKey(previewClaims.object_key, previewClaims.object_version)), 3);
  assertNoWorkerMarkerOrLease(previewEnv);

  const downloadClaims = workerReviewClaims({ action: 'download' });
  const downloadEnv = environment();
  const bytes = pngBody();
  await seedAcceptedWorkerReview(downloadEnv, downloadClaims, { bytes });
  const download = await workerReview(workerReviewRequest(await workerReviewGrant(downloadClaims)), downloadEnv, clock());

  assert.equal(download.status, 200);
  assert.equal(download.headers.get('content-type'), 'image/png');
  assert.equal(download.headers.get('content-disposition'), 'attachment; filename="submitted-image.png"');
  assert.equal(download.headers.get('cache-control'), 'private, no-store, max-age=0');
  assert.deepEqual([...new Uint8Array(await download.arrayBuffer())], [...bytes]);
  assert.equal(downloadEnv.IMAGES.calls, 0);
  assert.equal(downloadEnv.ARTIFACTS.getCounts.get(await validationResultKey(downloadClaims.object_key, downloadClaims.object_version)), 2);
  assertNoWorkerMarkerOrLease(downloadEnv);
});

test('Worker review rejects malformed authority before R2, Images, or cache', async () => {
  const validToken = await workerReviewGrant(workerReviewClaims());
  const cases = [
    new Request(`https://media.example.test/v1/review?grant=${encodeURIComponent(validToken)}`, { method: 'POST' }),
    new Request(`https://media.example.test/v1/reviewed?grant=${encodeURIComponent(validToken)}`),
    new Request('https://media.example.test/v1/review'),
    new Request('https://media.example.test/v1/review?token=abc'),
    new Request(`https://media.example.test/v1/review?grant=${encodeURIComponent(validToken)}&extra=1`),
    new Request(`https://media.example.test/v1/review?grant=${encodeURIComponent(validToken)}&grant=${encodeURIComponent(validToken)}`),
    workerReviewRequest(`${validToken.slice(0, -1)}${validToken.endsWith('A') ? 'B' : 'A'}`),
    workerReviewRequest(await workerReviewGrant(workerReviewClaims(), 'wrong-environment')),
    workerReviewRequest(await workerReviewGrant(workerReviewClaims({ expires_at: now }))),
    workerReviewRequest(await workerReviewGrant(workerReviewClaims({ recipe_version: 'preview-v1' }))),
    workerReviewRequest(validToken, { headers: { Authorization: 'Bearer token' } }),
    workerReviewRequest(validToken, { headers: { Cookie: 'sid=1' } }),
    workerReviewRequest(validToken, { headers: { 'X-EForms-Worker-Grant': 'token' } }),
  ];
  for (const request of cases) {
    const env = environment();
    const cache = new FakeCache();
    const response = await workerReview(request, env, { ...clock(), cache });

    await assertReviewUnavailable(response, 404);
    assert.deepEqual(env.ARTIFACTS.calls, []);
    assert.equal(env.IMAGES.calls, 0);
    assert.equal(cache.matches, 0);
    assert.equal(cache.puts, 0);
  }
});

test('Worker review rejects result failures before artifact, Images, or cache authority', async () => {
  const claims = workerReviewClaims();
  const resultKey = await validationResultKey(claims.object_key, claims.object_version);
  const cases = [
    { status: 404, seed: async () => {} },
    { status: 404, seed: async (env) => seedWorkerReviewResult(env, claims, workerReviewTerminalResult(claims, 'rejected')) },
    { status: 404, seed: async (env) => seedWorkerReviewResult(env, claims, workerReviewTerminalResult(claims, 'accepted', { environment_id: 'foreign-env' })) },
    { status: 404, seed: async (env) => env.ARTIFACTS.seedBytes(resultKey, new TextEncoder().encode(JSON.stringify({ not: 'canonical' }))) },
    { status: 404, seed: async (env) => env.ARTIFACTS.seedBytes(resultKey, new TextEncoder().encode(JSON.stringify(workerReviewTerminalResult(claims), null, 2))) },
    { status: 404, seed: async (env) => env.ARTIFACTS.seedBody(resultKey, bodyFromChunks([new Uint8Array(4096), new Uint8Array([123])]), { size: 4097 }) },
    { status: 503, seed: async (env) => env.ARTIFACTS.throwOnGet.add(resultKey) },
  ];
  for (const entry of cases) {
    const env = environment();
    const cache = new FakeCache();
    await entry.seed(env);
    const response = await workerReview(
      workerReviewRequest(await workerReviewGrant(claims)),
      env,
      { ...clock(), cache },
    );

    await assertReviewUnavailable(response, entry.status);
    assert.equal(env.ARTIFACTS.headCalls.includes(claims.object_key), false);
    assert.equal(env.IMAGES.calls, 0);
    assert.equal(cache.matches, 0);
    assert.equal(cache.puts, 0);
  }
});

test('Worker review rejects exact artifact and Worker metadata mismatches before Images or cache', async () => {
  const claims = workerReviewClaims();
  const metadataCases = [
    ['protocolVersion', { protocolVersion: '2' }],
    ['environmentId', { environmentId: 'foreign-env' }],
    ['batchId', { batchId: 'c'.repeat(43) }],
    ['intentId', { intentId: 'j'.repeat(43) }],
    ['ordinal', { ordinal: '3' }],
    ['uploadId', { uploadId: 'other_upload' }],
    ['storageIdentity', { storageIdentity: 'f'.repeat(64) }],
    ['validationContractVersion', { validationContractVersion: 'managed-image-v2' }],
    ['policyFingerprint', { policyFingerprint: 'c'.repeat(64) }],
    ['declaredMime', { declaredMime: 'image/webp' }],
  ];
  const cases = [
    ['missing artifact', async (env) => seedWorkerReviewResult(env, claims)],
    ['version', async (env) => {
      await seedAcceptedWorkerReview(env, claims);
      env.ARTIFACTS.objects.get(claims.object_key).version = 'wrong-version';
    }],
    ['etag', async (env) => {
      await seedAcceptedWorkerReview(env, claims);
      env.ARTIFACTS.objects.get(claims.object_key).etag = 'wrong-etag';
    }],
    ['bytes', async (env) => {
      await seedAcceptedWorkerReview(env, claims, { bytes: new Uint8Array(claims.bytes - 1) });
    }],
    ['changed after head', async (env) => {
      await seedAcceptedWorkerReview(env, claims);
      env.ARTIFACTS.afterHead = (key) => {
        if (key === claims.object_key) env.ARTIFACTS.objects.get(key).etag = 'changed-after-head';
      };
    }],
    ...metadataCases.map(([name, overrides]) => [name, async (env) => {
      await seedAcceptedWorkerReview(env, claims, { metadata: overrides });
    }]),
    ['unsupported accepted MIME', async (env) => {
      await seedAcceptedWorkerReview(env, claims, {
        result: { mime: 'image/gif' },
        metadata: { declaredMime: 'image/gif' },
      });
    }],
  ];

  for (const [, seed] of cases) {
    const env = environment();
    const cache = new FakeCache();
    await seed(env);
    const response = await workerReview(
      workerReviewRequest(await workerReviewGrant(claims)),
      env,
      { ...clock(), cache },
    );

    await assertReviewUnavailable(response, 404);
    assert.equal(env.IMAGES.calls, 0);
    assert.equal(cache.matches, 0);
    assert.equal(cache.puts, 0);
    assertNoWorkerMarkerOrLease(env);
  }
});

test('Worker review revalidates result cleanup races before download or preview delivery', async () => {
  for (const mutate of [
    async (env, claims) => env.ARTIFACTS.delete(await validationResultKey(claims.object_key, claims.object_version)),
    async (env, claims) => seedWorkerReviewResult(env, claims, workerReviewTerminalResult(claims, 'accepted', { width: 33 })),
  ]) {
    const downloadClaims = workerReviewClaims({ action: 'download' });
    const downloadEnv = environment();
    await seedAcceptedWorkerReview(downloadEnv, downloadClaims);
    downloadEnv.ARTIFACTS.afterGet = (key) => {
      if (key === downloadClaims.object_key) mutate(downloadEnv, downloadClaims);
    };
    const download = await workerReview(
      workerReviewRequest(await workerReviewGrant(downloadClaims)),
      downloadEnv,
      clock(),
    );
    await assertReviewUnavailable(download, 404);
    assert.equal(downloadEnv.IMAGES.calls, 0);

    const previewClaims = workerReviewClaims();
    const previewEnv = environment();
    const previewCache = new FakeCache();
    await seedAcceptedWorkerReview(previewEnv, previewClaims);
    previewEnv.ARTIFACTS.afterGet = (key) => {
      if (key === previewClaims.object_key) mutate(previewEnv, previewClaims);
    };
    const preview = await workerReview(
      workerReviewRequest(await workerReviewGrant(previewClaims)),
      previewEnv,
      { ...clock(), cache: previewCache },
    );
    await assertReviewUnavailable(preview, 404);
    assert.equal(previewEnv.IMAGES.calls, 1);
    assert.equal(previewCache.puts, 0);
  }
});

test('Worker review cache hits still revalidate lost result authority before cached delivery', async () => {
  const claims = workerReviewClaims();
  const env = environment();
  const cache = new FakeCache();
  await seedAcceptedWorkerReview(env, claims);
  const first = await workerReview(workerReviewRequest(await workerReviewGrant(claims)), env, { ...clock(), cache });
  assert.equal(first.status, 200);
  assert.equal(cache.puts, 1);
  assert.equal(env.IMAGES.calls, 1);

  const resultKey = await validationResultKey(claims.object_key, claims.object_version);
  env.ARTIFACTS.afterGet = (key) => {
    if (key === resultKey && env.ARTIFACTS.getCounts.get(key) === 4) env.ARTIFACTS.delete(key);
  };
  const cached = await workerReview(workerReviewRequest(await workerReviewGrant(claims)), env, { ...clock(), cache });

  await assertReviewUnavailable(cached, 404);
  assert.equal(cache.matches, 2);
  assert.equal(cache.puts, 1);
  assert.equal(env.IMAGES.calls, 1);
});

test('Worker review maps focused provider failures to retryable private errors', async () => {
  const claims = workerReviewClaims();
  const cases = [
    async (env) => {
      env.ARTIFACTS.throwOnHead = (key) => key === claims.object_key;
    },
    async (env) => {
      env.ARTIFACTS.throwOnGet.add(claims.object_key);
    },
    async (env) => {
      env.IMAGES.options.outputError = true;
    },
  ];
  for (const configure of cases) {
    const env = environment();
    const cache = new FakeCache();
    await seedAcceptedWorkerReview(env, claims);
    await configure(env);
    const response = await workerReview(
      workerReviewRequest(await workerReviewGrant(claims)),
      env,
      { ...clock(), cache },
    );

    await assertReviewUnavailable(response, 503);
    assert.equal(cache.puts, 0);
  }
});

test('Worker review download uses accepted HEIF MIME with HEIC declaration alias', async () => {
  const objectKey = await createManagedArtifactKey(
    fixture.worker_claims.upload_grant.batch_id,
    fixture.worker_claims.upload_grant.ordinal,
    fixture.worker_claims.upload_grant.intent_id,
    'image/heic',
  );
  const claims = workerReviewClaims({
    action: 'download',
    object_key: objectKey,
    object_version: 'heif-version',
    etag: 'heif-etag',
  });
  const env = environment();
  await seedAcceptedWorkerReview(env, claims, {
    metadata: { declaredMime: 'image/heic' },
    result: { mime: 'image/heif' },
  });

  const response = await workerReview(workerReviewRequest(await workerReviewGrant(claims)), env, clock());

  assert.equal(response.status, 200);
  assert.equal(response.headers.get('content-type'), 'image/heif');
  assert.equal(response.headers.get('content-disposition'), 'attachment; filename="submitted-image.heif"');
  assert.equal(env.IMAGES.calls, 0);
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
  const expected = await signUploadIndependentHealth(env, { storage_ready: false, limiter_ready: false });
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
    const expected = await signUploadIndependentHealth(env, { storage_ready: false, limiter_ready: false });
    assert.equal((await response.json()).result, expected);
    assert.deepEqual(env.ARTIFACTS.calls, []);
  }
});

test('signed health reports Worker dependency gaps without Queue mutation', async () => {
  const noQueue = environment();
  delete noQueue.VALIDATION_QUEUE;
  const queueResponse = await handleRequest(new Request('https://media.example.test/v1/health', {
    method: 'POST',
    headers: { 'X-EForms-Worker-Health': tokens.health_request },
  }), noQueue, clock());
  assert.equal(queueResponse.status, 200);
  assert.equal((await queueResponse.json()).result, await signUploadIndependentHealth(noQueue, { queue_producer_ready: false }));

  const wrongIdentity = environment();
  wrongIdentity.EFORMS_WORKER_URL = 'https://alternate-media.example.test';
  const identityResponse = await handleRequest(new Request('https://media.example.test/v1/health', {
    method: 'POST',
    headers: { 'X-EForms-Worker-Health': tokens.health_request },
  }), wrongIdentity, clock());
  assert.equal(identityResponse.status, 200);
  assert.equal((await identityResponse.json()).result, await signUploadIndependentHealth(wrongIdentity, { storage_identity_ready: false }));

  const defaultPort = environment();
  defaultPort.EFORMS_WORKER_URL = 'https://media.example.test:443';
  const defaultPortResponse = await handleRequest(new Request('https://media.example.test/v1/health', {
    method: 'POST',
    headers: { 'X-EForms-Worker-Health': tokens.health_request },
  }), defaultPort, clock());
  assert.equal(defaultPortResponse.status, 200);
  assert.equal((await defaultPortResponse.json()).result, await signUploadIndependentHealth(defaultPort));

  const malformedOrigin = environment();
  malformedOrigin.EFORMS_WORKER_URL = 'https://media.example.test\n';
  const malformedOriginResponse = await handleRequest(new Request('https://media.example.test/v1/health', {
    method: 'POST',
    headers: { 'X-EForms-Worker-Health': tokens.health_request },
  }), malformedOrigin, clock());
  assert.equal(malformedOriginResponse.status, 200);
  assert.equal((await malformedOriginResponse.json()).result, await signUploadIndependentHealth(malformedOrigin, {
    storage_identity_ready: false,
  }));

  const wrongContract = environment();
  wrongContract.EFORMS_VALIDATION_CONTRACT_VERSION = 'managed-image-v2';
  const contractResponse = await handleRequest(new Request('https://media.example.test/v1/health', {
    method: 'POST',
    headers: { 'X-EForms-Worker-Health': tokens.health_request },
  }), wrongContract, clock());
  assert.equal(contractResponse.status, 200);
  assert.equal((await contractResponse.json()).result, await signUploadIndependentHealth(wrongContract, { validation_contract_ready: false }));
  assert.equal(noQueue.ARTIFACTS.calls.includes('put'), false);
});


function environment(images = {}) {
  const queue = new FakeQueue();
  return {
    EFORMS_SITE_ORIGIN: 'https://forms.example.test',
    EFORMS_WORKER_URL: 'https://media.example.test',
    EFORMS_WORKER_ENVIRONMENT_ID: fixture.environment,
    EFORMS_VALIDATION_CONTRACT_VERSION: 'managed-image-v1',
    EFORMS_WORKER_ACTIVE_KEY_ID: fixture.active_key_id,
    EFORMS_WORKER_ACTIVE_KEY_B64: fixture.active_key_b64,
    ARTIFACTS: new FakeBucket(),
    IMAGES: new FakeImages(images),
    UPLOAD_RATE_LIMITER: new FakeRateLimiter(),
    VALIDATION_QUEUE: queue,
  };
}

function clock() {
  return { now: () => now };
}

function mutableClock(current) {
  return { current, now() { return this.current; } };
}

function workerUploadRequest(body, extraHeaders = {}, grant = workerTokens.upload_grant, mime = 'image/png') {
  const contentLength = body && typeof body.byteLength === 'number'
    ? String(body.byteLength)
    : String(fixture.worker_claims.upload_grant.declared_bytes);
  return new Request('https://media.example.test/v1/upload', {
    method: 'PUT',
    headers: {
      Origin: 'https://forms.example.test',
      'Content-Type': mime,
      'Content-Length': contentLength,
      'X-EForms-Worker-Grant': grant,
      ...extraHeaders,
    },
    body,
    duplex: 'half',
  });
}

function workerReviewClaims(overrides = {}) {
  return { ...fixture.worker_claims.review_grant, ...overrides };
}

function workerReviewGrant(claims, environment = fixture.environment) {
  return signWorkerReviewGrant(
    claims, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), environment,
  );
}

function workerReviewRequest(grant, options = {}) {
  return new Request(`https://media.example.test/v1/review?grant=${encodeURIComponent(grant)}`, options);
}

function workerObjectRequest(token, extraHeaders = {}, options = {}) {
  return new Request(options.url || 'https://media.example.test/v1/object', {
    method: options.method || 'POST',
    headers: {
      'X-EForms-Worker-Object': token,
      ...extraHeaders,
    },
  });
}

async function assertReviewUnavailable(response, status) {
  assert.equal(response.status, status);
  assert.equal(await response.text(), 'Review unavailable.');
  assert.equal(response.headers.get('cache-control'), 'private, no-store, max-age=0');
  assert.equal(response.headers.get('referrer-policy'), 'no-referrer');
  assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
  assert.equal(response.headers.get('retry-after'), status === 503 ? '2' : null);
}

async function seedAcceptedWorkerReview(env, claims, options = {}) {
  await seedWorkerReviewResult(
    env,
    claims,
    workerReviewTerminalResult(claims, 'accepted', options.result || {}),
  );
  seedWorkerReviewArtifact(env, claims, options.bytes || pngBody(), options.metadata || {});
}

async function seedWorkerReviewResult(env, claims, result = workerReviewTerminalResult(claims)) {
  env.ARTIFACTS.seedJson(await validationResultKey(claims.object_key, claims.object_version), result);
}

function seedWorkerReviewArtifact(env, claims, bytes = pngBody(), metadata = {}) {
  env.ARTIFACTS.seed(claims.object_key, bytes, workerReviewObjectMetadata(claims, metadata), claims.object_version);
  env.ARTIFACTS.objects.get(claims.object_key).etag = claims.etag;
}

function workerReviewTerminalResult(claims, outcome = 'accepted', overrides = {}) {
  const parts = workerReviewKeyParts(claims.object_key);
  const base = {
    result_version: 1,
    protocol_version: 3,
    environment_id: fixture.environment,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    batch_id: parts.batchId,
    intent_id: parts.intentId,
    upload_id: claims.upload_id,
    ordinal: parts.ordinal,
    object_key: claims.object_key,
    object_version: claims.object_version,
    etag: claims.etag,
    bytes: claims.bytes,
    policy_fingerprint: claims.policy_fingerprint,
    outcome,
    validated_at: now,
  };
  if (outcome === 'accepted') {
    return { ...base, mime: 'image/png', width: 32, height: 24, ...overrides };
  }
  return { ...base, reason: 'policy_rejected', ...overrides };
}

function workerReviewObjectMetadata(claims, overrides = {}) {
  const parts = workerReviewKeyParts(claims.object_key);
  return {
    protocolVersion: '3',
    environmentId: fixture.environment,
    intentId: parts.intentId,
    batchId: parts.batchId,
    uploadId: claims.upload_id,
    ordinal: String(parts.ordinal),
    storageIdentity: claims.storage_identity,
    validationContractVersion: claims.validation_contract_version,
    policyFingerprint: claims.policy_fingerprint,
    declaredMime: 'image/png',
    ...overrides,
  };
}

function seedWorkerObjectArtifact(env, claims, bytes = pngBody(), metadata = {}) {
  env.ARTIFACTS.seed(claims.object_key, bytes, workerObjectMetadataForClaims(claims, metadata), claims.object_version);
  env.ARTIFACTS.objects.get(claims.object_key).etag = claims.etag;
}

async function seedWorkerObjectResult(env, claims, outcome = 'accepted', overrides = {}) {
  const key = await validationResultKey(claims.object_key, claims.object_version);
  env.ARTIFACTS.seedJson(key, workerObjectTerminalResult(claims, outcome, overrides));
  return key;
}

function workerObjectTerminalResult(claims, outcome = 'accepted', overrides = {}) {
  const parts = workerReviewKeyParts(claims.object_key);
  const base = {
    result_version: 1,
    protocol_version: 3,
    environment_id: fixture.environment,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    batch_id: parts.batchId,
    intent_id: parts.intentId,
    upload_id: claims.upload_id,
    ordinal: parts.ordinal,
    object_key: claims.object_key,
    object_version: claims.object_version,
    etag: claims.etag,
    bytes: claims.bytes,
    policy_fingerprint: claims.policy_fingerprint,
    outcome,
    validated_at: now,
  };
  if (outcome === 'accepted') return { ...base, mime: 'image/png', width: 32, height: 24, ...overrides };
  return { ...base, reason: 'policy_rejected', ...overrides };
}

function workerObjectMetadataForClaims(claims, overrides = {}) {
  const parts = workerReviewKeyParts(claims.object_key);
  return {
    protocolVersion: '3',
    environmentId: fixture.environment,
    intentId: parts.intentId,
    batchId: parts.batchId,
    uploadId: claims.upload_id,
    ordinal: String(parts.ordinal),
    storageIdentity: claims.storage_identity,
    validationContractVersion: claims.validation_contract_version,
    policyFingerprint: claims.policy_fingerprint,
    declaredMime: 'image/png',
    ...overrides,
  };
}

function expectedWorkerObjectResult(claims, status) {
  return signWorkerObjectResult({
    request_id: claims.request_id,
    object_key: claims.object_key,
    object_version: claims.object_version,
    status,
    expires_at: claims.expires_at,
  }, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment);
}

function workerReviewKeyParts(objectKey) {
  const matches = objectKey.match(/^artifacts\/[0-9a-f]{2}\/([A-Za-z0-9_-]{43})\/(0|[1-9][0-9]*)-([A-Za-z0-9_-]{43})\.[a-z0-9]+$/);
  assert.ok(matches);
  return {
    batchId: matches[1],
    ordinal: Number(matches[2]),
    intentId: matches[3],
  };
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

function expectedWorkerJob(stored) {
  return {
    ...fixture.worker_claims.queue_job,
    environment_id: fixture.environment,
    object_version: stored.version,
    etag: stored.etag,
    bytes: stored.bytes.byteLength,
  };
}

function expectedWorkerReceipt(stored) {
  return signWorkerStoredReceipt({
    ...fixture.worker_claims.stored_receipt,
    object_version: stored.version,
    etag: stored.etag,
    bytes: stored.bytes.byteLength,
    expires_at: fixture.worker_claims.upload_grant.accept_until,
  }, fixture.active_key_id, decodeIntegrationKey(fixture.active_key_b64), fixture.environment);
}

async function galleryStatusRequest(items, options = {}) {
  const claims = {
    ...fixture.worker_claims.gallery_status_request,
    items_sha256: await workerGalleryItemsSha256(items),
    item_count: items.length,
    ...(options.claims || {}),
  };
  const token = await signWorkerGalleryStatusRequest(
    claims,
    fixture.active_key_id,
    decodeIntegrationKey(fixture.active_key_b64),
    options.environment || fixture.environment,
  );
  const bytes = await workerGalleryStatusRequestBodyBytes(token, items);
  return rawGalleryStatusRequest(bytes, options.headers || {});
}

function rawGalleryStatusRequest(bytes, headers = {}) {
  return new Request('https://media.example.test/v1/gallery-status', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Content-Length': String(bytes.byteLength),
      ...headers,
    },
    body: bytes,
    duplex: 'half',
  });
}

async function canonicalGalleryStatusResponseBytes(result, statuses, items = []) {
  const { workerGalleryStatusResultBodyBytes } = await import('../src/protocol.js');
  return workerGalleryStatusResultBodyBytes(result, statuses, items);
}

function keyring() {
  return { [fixture.active_key_id]: decodeIntegrationKey(fixture.active_key_b64) };
}

async function galleryItems(count) {
  const source = fixture.worker_claims.gallery_items[0];
  const items = [];
  for (let index = 0; index < count; index += 1) {
    const intentId = `${'i'.repeat(fixture.worker_claims.upload_grant.intent_id.length)}${index}`.slice(
      -fixture.worker_claims.upload_grant.intent_id.length,
    );
    items.push({
      ...source,
      upload_id: `gallery_${String(index).padStart(3, '0')}`,
      ordinal: index,
      object_key: await createManagedArtifactKey(fixture.worker_claims.upload_grant.batch_id, index, intentId, 'image/png'),
      object_version: `version-gallery-${index}`,
      etag: `etag-gallery-${index}`,
      bytes: 1234 + index,
      validation_until: now + 100 + index,
    });
  }
  return items;
}

function terminalResult(item, outcome, overrides = {}) {
  const base = {
    result_version: 1,
    protocol_version: 3,
    environment_id: fixture.environment,
    storage_identity: fixture.worker_claims.gallery_status_request.storage_identity,
    validation_contract_version: item.validation_contract_version,
    batch_id: fixture.worker_claims.upload_grant.batch_id,
    intent_id: item.object_key.match(/-([A-Za-z0-9_-]{43})\.[a-z0-9]+$/)[1],
    upload_id: item.upload_id,
    ordinal: item.ordinal,
    object_key: item.object_key,
    object_version: item.object_version,
    etag: item.etag,
    bytes: item.bytes,
    policy_fingerprint: item.policy_fingerprint,
    outcome,
    validated_at: now,
  };
  if (outcome === 'accepted') {
    return { ...base, mime: 'image/png', width: 32, height: 24, ...overrides };
  }
  return { ...base, reason: 'policy_rejected', ...overrides };
}

function workerObjectMetadata(overrides = {}) {
  const claims = fixture.worker_claims.upload_grant;
  return {
    protocolVersion: '3',
    environmentId: fixture.environment,
    intentId: claims.intent_id,
    batchId: claims.batch_id,
    uploadId: claims.upload_id,
    ordinal: String(claims.ordinal),
    storageIdentity: claims.storage_identity,
    validationContractVersion: claims.validation_contract_version,
    policyFingerprint: claims.policy_fingerprint,
    declaredMime: claims.declared_mime,
    maxBytes: String(claims.max_bytes),
    maxEdge: String(claims.max_edge),
    maxPixels: String(claims.max_pixels),
    containerEntryLimit: String(claims.container_entry_limit),
    ...overrides,
  };
}

function assertNoWorkerMarkerOrLease(env) {
  const keys = [
    ...env.ARTIFACTS.headCalls,
    ...env.ARTIFACTS.getCalls,
    ...env.ARTIFACTS.putCalls.map((call) => call.key),
    ...env.ARTIFACTS.deleteCalls,
  ];
  assert.equal(keys.some((key) => key.includes('.validated-') || key.includes('.validating-')), false);
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

function bodyFromChunks(chunks, hooks = {}) {
  return new ReadableStream({
    pull(controller) {
      const chunk = chunks.shift();
      if (chunk) {
        controller.enqueue(chunk);
      } else {
        controller.close();
      }
    },
    cancel() {
      if (hooks.onCancel) hooks.onCancel();
    },
  });
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
    this.headCalls = [];
    this.getCalls = [];
    this.deleteCalls = [];
    this.putOptions = null;
    this.putCalls = [];
    this.throwAfterPut = null;
    this.throwOnHead = false;
    this.throwOnGet = new Set();
    this.throwOnDelete = new Set();
    this.noopDelete = new Set();
    this.rejectPutBeforeRead = false;
    this.conditionalConflictBeforeRead = null;
    this.beforeConditionalPut = null;
    this.beforeHead = null;
    this.afterHead = null;
    this.afterGet = null;
    this.afterDelete = null;
    this.bodyCancellations = 0;
    this.getCounts = new Map();
    this.keepSecondBodyOpen = false;
    this.sequence = 0;
  }

  seed(key, bytes, metadata, version = 'version-seeded') {
    this.objects.set(key, { key, bytes, version, etag: 'etag-seeded', customMetadata: metadata });
  }

  seedJson(key, value) {
    this.seedBytes(key, canonicalJsonBytes(value), {
      uploaded: Number.isSafeInteger(value.validated_at) ? new Date(value.validated_at * 1000) : undefined,
    });
  }

  seedBytes(key, bytes, metadata = {}) {
    this.seedBody(key, new Response(bytes).body, { size: bytes.byteLength, bytes, ...metadata });
  }

  seedBody(key, body, metadata = {}) {
    this.sequence += 1;
    const identity = String(this.sequence).padStart(4, '0');
    this.objects.set(key, {
      key,
      bytes: Object.hasOwn(metadata, 'bytes') ? metadata.bytes : null,
      body,
      size: metadata.size,
      version: `result-version-${identity}`,
      etag: `result-etag-${identity}`,
      customMetadata: metadata.customMetadata || {},
      uploaded: metadata.uploaded,
    });
  }

  async head(key) {
    this.calls.push('head');
    this.headCalls.push(key);
    if (this.throwOnHead === true || (typeof this.throwOnHead === 'function' && this.throwOnHead(key))) {
      throw new Error('provider unavailable');
    }
    if (this.beforeHead) this.beforeHead(key);
    const descriptor = this.descriptor(this.objects.get(key));
    if (this.afterHead) this.afterHead(key, descriptor);
    return descriptor;
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
    const stored = {
      key,
      bytes,
      version: `version-${identity}`,
      etag: `etag-${identity}`,
      customMetadata: options.customMetadata,
      uploaded: new Date(now * 1000),
    };
    this.objects.set(key, stored);
    if ( this.throwAfterPut && this.throwAfterPut(key) ) throw new Error('lost put response');
    return this.descriptor(stored);
  }

  async get(key, options) {
    this.calls.push('get');
    this.getCalls.push(key);
    if (this.throwOnGet.has(key)) throw new Error('get failed');
    const stored = this.objects.get(key);
    if (!stored || (options && options.onlyIf && options.onlyIf.etagMatches !== stored.etag)) return null;
    const getCount = (this.getCounts.get(key) || 0) + 1;
    this.getCounts.set(key, getCount);
    if (!this.keepSecondBodyOpen || getCount !== 2) {
      const result = { ...this.descriptor(stored), body: stored.bytes ? new Response(stored.bytes).body : stored.body };
      if (this.afterGet) this.afterGet(key, result);
      return result;
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
    const result = { ...this.descriptor(stored), body };
    if (this.afterGet) this.afterGet(key, result);
    return result;
  }

  async delete(key) {
    this.calls.push('delete');
    this.deleteCalls.push(key);
    if (this.throwOnDelete.has(key)
      || (typeof this.throwOnDelete === 'function' && this.throwOnDelete(key))) {
      throw new Error('delete failed');
    }
    if (!this.noopDelete.has(key)) this.objects.delete(key);
    if (this.afterDelete) this.afterDelete(key);
  }

  descriptor(stored) {
    return stored ? {
      key: stored.key,
      size: stored.bytes ? stored.bytes.byteLength : stored.size,
      version: stored.version,
      etag: stored.etag,
      customMetadata: { ...stored.customMetadata },
      uploaded: stored.uploaded,
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

class FakeQueue {
  constructor() {
    this.jobs = [];
    this.throwOnSend = false;
    this.onSend = null;
    this.wait = null;
    this.sent = new Promise((resolve) => { this.resolveSent = resolve; });
  }

  async send(job) {
    this.jobs.push(JSON.parse(JSON.stringify(job)));
    if (this.onSend) this.onSend(job);
    this.resolveSent();
    if (this.wait) await this.wait;
    if (this.throwOnSend) throw new Error('queue unavailable');
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
    queue_producer_ready: true,
    limiter_ready: true,
    keys_ready: true,
    storage_identity_ready: true,
    validation_contract_ready: true,
    storage_identity: fixture.claims.health_request.storage_identity,
    validation_contract_version: fixture.claims.health_request.validation_contract_version,
    checked_at: now,
    expires_at: fixture.claims.health_request.expires_at,
    ...overrides,
  }, fixture.active_key_id, decodeIntegrationKey(env.EFORMS_WORKER_ACTIVE_KEY_B64), fixture.environment);
}
