import assert from 'node:assert/strict';
import test from 'node:test';
import { canonicalJsonBytes, fixture } from './fixture.js';
import {
  readValidationResult,
  validationResultKey,
} from '../src/validation-result.js';
import { createManagedArtifactKey } from '../src/managed-artifact-key.js';
import worker, { workerQueueBatch } from '../src/index.js';

const job = fixture.worker_claims.queue_job;
const accepted = fixture.worker_claims.terminal_result_accepted;
const rejected = fixture.worker_claims.terminal_result_rejected;

test('Worker queue writes accepted and typed rejected terminal results', async () => {
  const acceptedEnv = queueEnvironment();
  acceptedEnv.ARTIFACTS.seedArtifact(job, pngBody());
  const acceptedMessage = new FakeMessage(job);
  await workerQueueBatch({ messages: [acceptedMessage] }, acceptedEnv, clock());
  assertSettled(acceptedMessage, 1, 0);
  assert.equal(acceptedEnv.IMAGES.calls, 1);
  assert.equal(acceptedEnv.ARTIFACTS.getCalls.filter((key) => key === job.object_key).length, 2);
  const acceptedResult = await storedResult(acceptedEnv.ARTIFACTS, job);
  assert.equal(acceptedResult.outcome, 'accepted');
  assert.equal(acceptedResult.validated_at, fixture.verification_now);
  assert.equal(acceptedResult.mime, 'image/png');
  assertNoMarkerOrLease(acceptedEnv.ARTIFACTS);

  const rejectedEnv = queueEnvironment({ info: { format: 'png', fileSize: job.bytes, width: job.max_edge + 1, height: 24 } });
  rejectedEnv.ARTIFACTS.seedArtifact(job, pngBody());
  const rejectedMessage = new FakeMessage(job);
  await workerQueueBatch({ messages: [rejectedMessage] }, rejectedEnv, clock());
  assertSettled(rejectedMessage, 1, 0);
  const rejectedResult = await storedResult(rejectedEnv.ARTIFACTS, job);
  assert.deepEqual(rejectedResult, {
    result_version: 1,
    protocol_version: 3,
    environment_id: job.environment_id,
    storage_identity: job.storage_identity,
    validation_contract_version: job.validation_contract_version,
    batch_id: job.batch_id,
    intent_id: job.intent_id,
    upload_id: job.upload_id,
    ordinal: job.ordinal,
    object_key: job.object_key,
    object_version: job.object_version,
    etag: job.etag,
    bytes: job.bytes,
    policy_fingerprint: job.policy_fingerprint,
    outcome: 'rejected',
    validated_at: fixture.verification_now,
    reason: 'policy_rejected',
  });
});

test('Worker queue stores unsupported media rejection reason', async () => {
  const env = queueEnvironment({ info: { format: 'jpeg', fileSize: job.bytes, width: 32, height: 24 } });
  env.ARTIFACTS.seedArtifact(job, pngBody());
  const message = new FakeMessage(job);
  await workerQueueBatch({ messages: [message] }, env, clock());

  assertSettled(message, 1, 0);
  assert.equal((await storedResult(env.ARTIFACTS, job)).reason, 'unsupported_media');
});

test('Worker queue writes rejected results for deterministic media parse failures', async () => {
  for (const [sourceJob, info, body] of [
    [job, { format: 'png', fileSize: job.bytes, width: 32, height: 24 }, invalidPngBody()],
    [
      { ...job, declared_mime: 'image/webp', object_version: `${job.object_version}-webp`, etag: `${job.etag}-webp` },
      { format: 'webp', fileSize: job.bytes, width: 32, height: 24 },
      invalidWebpBody(),
    ],
  ]) {
    const env = queueEnvironment({ info });
    env.ARTIFACTS.seedArtifact(sourceJob, body);
    const message = new FakeMessage(sourceJob);
    await workerQueueBatch({ messages: [message] }, env, clock());

    assertSettled(message, 1, 0);
    assert.equal((await storedResult(env.ARTIFACTS, sourceJob)).outcome, 'rejected');
    assert.equal((await storedResult(env.ARTIFACTS, sourceJob)).reason, 'invalid_media');
  }
});

test('Worker queue rejects invalid declared containers before provider info', async () => {
  const env = queueEnvironment();
  env.ARTIFACTS.seedArtifact(job, invalidPngBody());
  env.IMAGES.throwInfo = true;
  const message = new FakeMessage(job);
  await workerQueueBatch({ messages: [message] }, env, clock());

  assertSettled(message, 1, 0);
  assert.equal(env.IMAGES.calls, 0);
  assert.equal((await storedResult(env.ARTIFACTS, job)).outcome, 'rejected');
  assert.equal((await storedResult(env.ARTIFACTS, job)).reason, 'invalid_media');
});

test('Worker queue malformed and unsupported jobs permanently ack before provider work', async () => {
  const malformed = new FakeMessage({ not: 'a-job' });
  const malformedRuntime = alertsClock();
  await workerQueueBatch({ messages: [malformed] }, queueEnvironment(), malformedRuntime);
  assertSettled(malformed, 1, 0);
  assert.deepEqual(malformedRuntime.alerts, ['malformed_job']);

  const unsupported = new FakeMessage({ ...job, protocol_version: 4 });
  const unsupportedEnv = queueEnvironment();
  unsupportedEnv.ARTIFACTS.seedArtifact(job, pngBody());
  const unsupportedRuntime = alertsClock();
  await workerQueueBatch({ messages: [unsupported] }, unsupportedEnv, unsupportedRuntime);
  assertSettled(unsupported, 1, 0);
  assert.equal(unsupportedEnv.IMAGES.calls, 0);
  assert.equal(unsupportedEnv.ARTIFACTS.objects.has(await validationResultKey(job.object_key, job.object_version)), false);
  assert.deepEqual(unsupportedRuntime.alerts, ['malformed_job']);
});

test('Worker queue existing matching result acks without inspection', async () => {
  const env = queueEnvironment();
  env.ARTIFACTS.seedArtifact(job, pngBody());
  env.ARTIFACTS.seedJson(await validationResultKey(job.object_key, job.object_version), accepted);
  const message = new FakeMessage(job);
  await workerQueueBatch({ messages: [message] }, env, clock());
  assertSettled(message, 1, 0);
  assert.equal(env.IMAGES.calls, 0);
});

test('Worker queue deletion-attempts an already-present result committed at the closed deadline', async () => {
  const env = queueEnvironment();
  const key = await validationResultKey(job.object_key, job.object_version);
  env.ARTIFACTS.seedBytes(key, canonicalJsonBytes(accepted), {
    uploaded: new Date(job.validation_until * 1000),
  });
  const message = new FakeMessage(job);
  const runtime = alertsClock();
  await workerQueueBatch({ messages: [message] }, env, runtime);
  assert.deepEqual(
    {
      acks: message.acked,
      retries: message.retried,
      deletes: env.ARTIFACTS.deleteCalls,
      present: env.ARTIFACTS.objects.has(key),
      alerts: runtime.alerts,
      inspections: env.IMAGES.calls,
    },
    { acks: 1, retries: 0, deletes: [key], present: false, alerts: ['validation_deadline'], inspections: 0 },
  );
});

test('Worker queue retries transient artifact, provider, existing-result, and write uncertainty before deadline', async () => {
  const noArtifactsEnv = queueEnvironment();
  delete noArtifactsEnv.ARTIFACTS;
  const noArtifacts = new FakeMessage(job);
  await workerQueueBatch({ messages: [noArtifacts] }, noArtifactsEnv, clock());
  assertSettled(noArtifacts, 0, 1);

  const noImagesEnv = queueEnvironment();
  delete noImagesEnv.IMAGES;
  const noImages = new FakeMessage(job);
  await workerQueueBatch({ messages: [noImages] }, noImagesEnv, clock());
  assertSettled(noImages, 0, 1);

  const missing = new FakeMessage(job);
  await workerQueueBatch({ messages: [missing] }, queueEnvironment(), clock());
  assertSettled(missing, 0, 1);

  const headFailure = queueEnvironment();
  headFailure.ARTIFACTS.throwHeadFor.add(job.object_key);
  const headFailureMessage = new FakeMessage(job);
  await workerQueueBatch({ messages: [headFailureMessage] }, headFailure, clock());
  assertSettled(headFailureMessage, 0, 1);

  const provider = queueEnvironment();
  provider.ARTIFACTS.seedArtifact(job, pngBody());
  provider.IMAGES.throwInfo = true;
  const providerMessage = new FakeMessage(job);
  await workerQueueBatch({ messages: [providerMessage] }, provider, clock());
  assertSettled(providerMessage, 0, 1);

  const invalidResult = queueEnvironment();
  invalidResult.ARTIFACTS.seedArtifact(job, pngBody());
  invalidResult.ARTIFACTS.seedBytes(await validationResultKey(job.object_key, job.object_version), new TextEncoder().encode('not-json'));
  const invalidMessage = new FakeMessage(job);
  await workerQueueBatch({ messages: [invalidMessage] }, invalidResult, clock());
  assertSettled(invalidMessage, 0, 1);

  const uncertain = queueEnvironment();
  uncertain.ARTIFACTS.seedArtifact(job, pngBody());
  uncertain.ARTIFACTS.throwResultPut = true;
  const uncertainMessage = new FakeMessage(job);
  await workerQueueBatch({ messages: [uncertainMessage] }, uncertain, clock());
  assertSettled(uncertainMessage, 0, 1);
});

test('Worker queue acks stale existing invalid result reads at deadline equality', async () => {
  const env = queueEnvironment();
  const runtime = alertsClock(fixture.verification_now);
  const key = await validationResultKey(job.object_key, job.object_version);
  const bytes = new TextEncoder().encode('not-json');
  env.ARTIFACTS.seedArtifact(job, pngBody());
  env.ARTIFACTS.seedBody(key, streamFromChunks([bytes], {
    onRead() {
      runtime.current = job.validation_until;
    },
  }), { size: bytes.byteLength });
  const message = new FakeMessage(job);

  await workerQueueBatch({ messages: [message] }, env, runtime);

  assertSettled(message, 1, 0);
  assert.deepEqual(runtime.alerts, ['existing_result_invalid']);
});

test('Worker queue permanently acks environment or artifact identity mismatch', async () => {
  const envMismatch = queueEnvironment();
  envMismatch.ARTIFACTS.seedArtifact(job, pngBody());
  const envMessage = new FakeMessage({ ...job, environment_id: 'other-environment' });
  const envRuntime = alertsClock();
  await workerQueueBatch({ messages: [envMessage] }, envMismatch, envRuntime);
  assertSettled(envMessage, 1, 0);
  assert.deepEqual(envRuntime.alerts, ['environment_mismatch']);

  const artifactMismatch = queueEnvironment();
  artifactMismatch.ARTIFACTS.seedArtifact(job, pngBody(), { storageIdentity: 'f'.repeat(64) });
  const artifactMessage = new FakeMessage(job);
  const artifactRuntime = alertsClock();
  await workerQueueBatch({ messages: [artifactMessage] }, artifactMismatch, artifactRuntime);
  assertSettled(artifactMessage, 1, 0);
  assert.deepEqual(artifactRuntime.alerts, ['artifact_identity_mismatch']);
  assert.equal(artifactMismatch.IMAGES.calls, 0);
});

test('Worker queue permanently acks configured storage identity or validation contract mismatch before provider work', async () => {
  const identityMismatch = queueEnvironment();
  identityMismatch.EFORMS_WORKER_URL = 'https://alternate-media.example.test';
  identityMismatch.ARTIFACTS.seedArtifact(job, pngBody());
  const identityMessage = new FakeMessage(job);
  const identityRuntime = alertsClock();
  await workerQueueBatch({ messages: [identityMessage] }, identityMismatch, identityRuntime);
  assertSettled(identityMessage, 1, 0);
  assert.deepEqual(identityRuntime.alerts, ['storage_identity_mismatch']);
  assert.deepEqual(identityMismatch.ARTIFACTS.getCalls, []);
  assert.equal(identityMismatch.IMAGES.calls, 0);

  const contractMismatch = queueEnvironment();
  contractMismatch.EFORMS_VALIDATION_CONTRACT_VERSION = 'managed-image-v2';
  contractMismatch.ARTIFACTS.seedArtifact(job, pngBody());
  const contractMessage = new FakeMessage(job);
  const contractRuntime = alertsClock();
  await workerQueueBatch({ messages: [contractMessage] }, contractMismatch, contractRuntime);
  assertSettled(contractMessage, 1, 0);
  assert.deepEqual(contractRuntime.alerts, ['validation_contract_mismatch']);
  assert.deepEqual(contractMismatch.ARTIFACTS.getCalls, []);
  assert.equal(contractMismatch.IMAGES.calls, 0);
});

test('Worker queue permanently acks exact object changes during inspection', async () => {
  const env = queueEnvironment();
  env.ARTIFACTS.seedArtifact(job, pngBody());
  const message = new FakeMessage(job);
  const runtime = alertsClock();
  env.IMAGES.onInfo = () => {
    env.ARTIFACTS.objects.get(job.object_key).etag = `${job.etag}-changed`;
  };

  await workerQueueBatch({ messages: [message] }, env, runtime);

  assertSettled(message, 1, 0);
  assert.deepEqual(runtime.alerts, ['object_changed']);
  assert.equal((await readValidationResult(env.ARTIFACTS, job)).status, 'absent');
});

test('Worker queue permanently acks exact artifact changes after inspection', async () => {
  const env = queueEnvironment();
  env.ARTIFACTS.seedArtifact(job, pngBody());
  env.ARTIFACTS.onHead = (key, count) => {
    if (key === job.object_key && count === 2) {
      env.ARTIFACTS.objects.get(job.object_key).customMetadata.policyFingerprint = 'changed';
    }
  };
  const message = new FakeMessage(job);
  const runtime = alertsClock();

  await workerQueueBatch({ messages: [message] }, env, runtime);

  assertSettled(message, 1, 0);
  assert.deepEqual(runtime.alerts, ['artifact_identity_mismatch']);
  assert.equal((await readValidationResult(env.ARTIFACTS, job)).status, 'absent');
});

test('Worker queue deadline equality before and after inspection creates no result', async () => {
  const before = queueEnvironment();
  before.ARTIFACTS.seedArtifact(job, pngBody());
  const beforeMessage = new FakeMessage(job);
  const beforeRuntime = alertsClock(job.validation_until);
  await workerQueueBatch({ messages: [beforeMessage] }, before, beforeRuntime);
  assertSettled(beforeMessage, 1, 0);
  assert.equal(before.IMAGES.calls, 0);
  assert.equal(await readValidationResult(before.ARTIFACTS, job).then((result) => result.status), 'absent');

  const after = queueEnvironment();
  after.ARTIFACTS.seedArtifact(job, pngBody());
  const afterRuntime = alertsClock(fixture.verification_now);
  after.IMAGES.onInfo = () => { afterRuntime.current = job.validation_until; };
  const afterMessage = new FakeMessage(job);
  await workerQueueBatch({ messages: [afterMessage] }, after, afterRuntime);
  assertSettled(afterMessage, 1, 0);
  assert.equal(after.IMAGES.calls, 1);
  assert.equal(await readValidationResult(after.ARTIFACTS, job).then((result) => result.status), 'absent');
});

test('Worker queue converges duplicate, conditional race, and contradictory race without overwrite', async () => {
  const duplicate = queueEnvironment();
  duplicate.ARTIFACTS.seedArtifact(job, pngBody());
  duplicate.ARTIFACTS.seedJson(await validationResultKey(job.object_key, job.object_version), accepted);
  const duplicateMessage = new FakeMessage(job);
  await workerQueueBatch({ messages: [duplicateMessage] }, duplicate, clock());
  assertSettled(duplicateMessage, 1, 0);

  const race = queueEnvironment();
  race.ARTIFACTS.seedArtifact(job, pngBody());
  race.ARTIFACTS.beforePutReturnNull = async (key) => {
    if (key === await validationResultKey(job.object_key, job.object_version)) {
      race.ARTIFACTS.seedJson(key, accepted);
    }
  };
  const raceMessage = new FakeMessage(job);
  await workerQueueBatch({ messages: [raceMessage] }, race, clock());
  assertSettled(raceMessage, 1, 0);
  assert.deepEqual(await storedResult(race.ARTIFACTS, job), accepted);

  const contradictory = queueEnvironment();
  contradictory.ARTIFACTS.seedArtifact(job, pngBody());
  contradictory.ARTIFACTS.beforePutReturnNull = async (key) => {
    if (key === await validationResultKey(job.object_key, job.object_version)) {
      contradictory.ARTIFACTS.seedJson(key, rejected);
    }
  };
  const contradictoryMessage = new FakeMessage(job);
  const contradictoryRuntime = alertsClock();
  await workerQueueBatch({ messages: [contradictoryMessage] }, contradictory, contradictoryRuntime);
  assertSettled(contradictoryMessage, 1, 0);
  assert.deepEqual(contradictoryRuntime.alerts, ['result_nondeterminism']);
  assert.deepEqual(await storedResult(contradictory.ARTIFACTS, job), rejected);
});

test('Worker queue converges true same-batch duplicate jobs to one immutable result', async () => {
  const env = queueEnvironment();
  env.ARTIFACTS.seedArtifact(job, pngBody());
  const first = new FakeMessage(job);
  const second = new FakeMessage(job);

  await workerQueueBatch({ messages: [first, second] }, env, clock());

  assertSettled(first, 1, 0);
  assertSettled(second, 1, 0);
  assert.deepEqual(await storedResult(env.ARTIFACTS, job), {
    ...accepted,
    validated_at: fixture.verification_now,
  });
  assert.equal([...env.ARTIFACTS.objects.keys()].filter((key) => key.includes('.validation-')).length, 1);
  assertNoMarkerOrLease(env.ARTIFACTS);
});

test('Worker queue bounds provider inspection to one message at a time within a batch', async () => {
  const env = queueEnvironment();
  const otherJob = {
    ...job,
    intent_id: 'j'.repeat(43),
    upload_id: 'upload_fixture_02',
    ordinal: job.ordinal + 1,
    object_version: 'fedcba9876543210fedcba9876543210',
    etag: '0123456789abcdef0123456789abcdef',
  };
  otherJob.object_key = await createManagedArtifactKey(
    otherJob.batch_id,
    otherJob.ordinal,
    otherJob.intent_id,
    otherJob.declared_mime,
  );
  let active = 0;
  let peak = 0;
  env.IMAGES.onInfo = async () => {
    active += 1;
    peak = Math.max(peak, active);
    await Promise.resolve();
    active -= 1;
  };
  env.ARTIFACTS.seedArtifact(job, pngBody());
  env.ARTIFACTS.seedArtifact(otherJob, pngBody());
  const first = new FakeMessage(job);
  const second = new FakeMessage(otherJob);

  await workerQueueBatch({ messages: [first, second] }, env, clock());

  assertSettled(first, 1, 0);
  assertSettled(second, 1, 0);
  assert.equal(env.IMAGES.calls, 2);
  assert.equal(peak, 1);
  assert.equal((await readValidationResult(env.ARTIFACTS, job)).status, 'matching');
  assert.equal((await readValidationResult(env.ARTIFACTS, otherJob)).status, 'matching');
});

test('default worker queue delegates to Worker queue batch', async () => {
  const env = queueEnvironment();
  env.ARTIFACTS.seedArtifact(job, pngBody());
  const message = new FakeMessage(job);

  await worker.queue({ messages: [message] }, env, {});

  assertSettled(message, 1, 0);
  assert.equal((await readValidationResult(env.ARTIFACTS, job)).status, 'matching');
});

test('Worker queue processes each batch message independently', async () => {
  const env = queueEnvironment();
  env.ARTIFACTS.seedArtifact(job, pngBody());
  const valid = new FakeMessage(job);
  const malformed = new FakeMessage({ missing: true });
  const runtime = alertsClock();
  await workerQueueBatch({ messages: [valid, malformed] }, env, runtime);
  assertSettled(valid, 1, 0);
  assertSettled(malformed, 1, 0);
  assert.deepEqual(runtime.alerts, ['malformed_job']);
  assert.equal((await readValidationResult(env.ARTIFACTS, job)).status, 'matching');
  assertNoMarkerOrLease(env.ARTIFACTS);
});

class FakeBucket {
  constructor() {
    this.objects = new Map();
    this.putCalls = [];
    this.headCalls = [];
    this.getCalls = [];
    this.headCounts = new Map();
    this.sequence = 0;
    this.beforePutReturnNull = null;
    this.onHead = null;
    this.throwHeadFor = new Set();
    this.throwAfterPut = false;
    this.throwResultPut = false;
    this.deleteCalls = [];
  }

  seedArtifact(sourceJob, bytes, metadataOverrides = {}) {
    this.objects.set(sourceJob.object_key, {
      key: sourceJob.object_key,
      bytes,
      body: null,
      size: bytes.byteLength,
      version: sourceJob.object_version,
      etag: sourceJob.etag,
      customMetadata: workerObjectMetadata(sourceJob, metadataOverrides),
    });
  }

  seedJson(key, value) {
    this.seedBytes(key, canonicalJsonBytes(value), {
      uploaded: Number.isSafeInteger(value.validated_at) ? new Date(value.validated_at * 1000) : undefined,
    });
  }

  seedBytes(key, bytes, metadata = {}) {
    this.seedBody(key, new Response(bytes).body, { size: bytes.byteLength, ...metadata, bytes });
  }

  seedBody(key, body, metadata = {}) {
    this.sequence += 1;
    this.objects.set(key, {
      key,
      bytes: Object.hasOwn(metadata, 'bytes') ? metadata.bytes : null,
      body,
      size: metadata.size,
      version: `result-version-${this.sequence}`,
      etag: `result-etag-${this.sequence}`,
      customMetadata: metadata.customMetadata || {},
      uploaded: metadata.uploaded || new Date((job.validation_until - 1) * 1000),
    });
  }

  async head(key) {
    this.headCalls.push(key);
    const count = (this.headCounts.get(key) || 0) + 1;
    this.headCounts.set(key, count);
    if (this.throwHeadFor.has(key)) throw new Error('head failed');
    if (this.onHead) this.onHead(key, count);
    const stored = this.objects.get(key);
    return stored ? this.descriptor(stored) : null;
  }

  async get(key, options = {}) {
    this.getCalls.push(key);
    const stored = this.objects.get(key);
    if (!stored) return null;
    if (options.onlyIf && options.onlyIf.etagMatches && options.onlyIf.etagMatches !== stored.etag) return null;
    return {
      key,
      body: stored.bytes ? new Response(stored.bytes).body : stored.body,
      size: stored.size,
      version: stored.version,
      etag: stored.etag,
      customMetadata: { ...(stored.customMetadata || {}) },
      uploaded: stored.uploaded,
    };
  }

  async put(key, bytes, options) {
    this.putCalls.push({ key, options });
    if (this.beforePutReturnNull) {
      await this.beforePutReturnNull(key);
      this.beforePutReturnNull = null;
      return null;
    }
    if (this.objects.has(key) && options.onlyIf && options.onlyIf.etagDoesNotMatch === '*') return null;
    if (this.throwResultPut) throw new Error('lost result response');
    this.seedBytes(key, bytes);
    if (this.throwAfterPut) throw new Error('lost result response');
    return { key, etag: this.objects.get(key).etag, uploaded: this.objects.get(key).uploaded };
  }

  async delete(key) {
    this.deleteCalls.push(key);
    this.objects.delete(key);
  }

  descriptor(stored) {
    return {
      key: stored.key,
      size: stored.size,
      version: stored.version,
      etag: stored.etag,
      customMetadata: { ...(stored.customMetadata || {}) },
    };
  }
}

class FakeImages {
  constructor(options = {}) {
    this.options = options;
    this.calls = 0;
    this.throwInfo = false;
    this.onInfo = null;
  }

  async info(stream) {
    this.calls += 1;
    await new Response(stream).arrayBuffer();
    if (this.onInfo) await this.onInfo();
    if (this.throwInfo) throw new Error('images unavailable');
    return this.options.info || { format: 'png', fileSize: job.bytes, width: 32, height: 24 };
  }
}

class FakeMessage {
  constructor(body) {
    this.body = body;
    this.acked = 0;
    this.retried = 0;
  }

  ack() {
    this.acked += 1;
  }

  retry() {
    this.retried += 1;
  }
}


function text(bytes) {
  return new TextDecoder().decode(bytes);
}

function queueEnvironment(imageOptions = {}) {
  return {
    EFORMS_WORKER_URL: 'https://media.example.test',
    EFORMS_WORKER_ENVIRONMENT_ID: job.environment_id,
    EFORMS_VALIDATION_CONTRACT_VERSION: job.validation_contract_version,
    ARTIFACTS: new FakeBucket(),
    IMAGES: new FakeImages(imageOptions),
  };
}

function clock(now = fixture.verification_now) {
  return { now: () => now };
}

function alertsClock(now = fixture.verification_now) {
  return {
    current: now,
    alerts: [],
    now() {
      return this.current;
    },
    alert(code) {
      this.alerts.push(code);
    },
  };
}

function workerObjectMetadata(sourceJob, overrides = {}) {
  return {
    protocolVersion: '3',
    environmentId: sourceJob.environment_id,
    intentId: sourceJob.intent_id,
    batchId: sourceJob.batch_id,
    uploadId: sourceJob.upload_id,
    ordinal: String(sourceJob.ordinal),
    storageIdentity: sourceJob.storage_identity,
    validationContractVersion: sourceJob.validation_contract_version,
    policyFingerprint: sourceJob.policy_fingerprint,
    declaredMime: sourceJob.declared_mime,
    maxBytes: String(sourceJob.max_bytes),
    maxEdge: String(sourceJob.max_edge),
    maxPixels: String(sourceJob.max_pixels),
    containerEntryLimit: String(sourceJob.container_entry_limit),
    ...overrides,
  };
}

async function storedResult(bucket, sourceJob) {
  const key = await validationResultKey(sourceJob.object_key, sourceJob.object_version);
  return JSON.parse(text(bucket.objects.get(key).bytes));
}




function assertSettled(message, acked, retried) {
  assert.equal(message.acked, acked);
  assert.equal(message.retried, retried);
}

function assertNoMarkerOrLease(bucket) {
  const keys = [
    ...bucket.headCalls,
    ...bucket.putCalls.map((call) => call.key),
  ];
  assert.equal(keys.some((key) => key.includes('.validated-') || key.includes('.validating-')), false);
}

function pngBody() {
  const bytes = new Uint8Array(job.bytes);
  bytes.set([137, 80, 78, 71, 13, 10, 26, 10]);
  bytes.set([0, 0, 0, 13, 73, 72, 68, 82], 8);
  bytes.set([0, 0, 0, 0, 73, 68, 65, 84], 33);
  return bytes;
}

function invalidPngBody() {
  const bytes = new Uint8Array(job.bytes);
  bytes.set([137, 80, 78, 71, 13, 10, 26, 9]);
  return bytes;
}

function invalidWebpBody() {
  const bytes = new Uint8Array(job.bytes);
  bytes.set(new TextEncoder().encode('RIFF'));
  bytes.set(new TextEncoder().encode('NOPE'), 8);
  return bytes;
}

function streamFromChunks(chunks, hooks = {}) {
  return new ReadableStream({
    pull(controller) {
      if (hooks.onRead) hooks.onRead();
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
