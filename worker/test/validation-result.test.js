import assert from 'node:assert/strict';
import test from 'node:test';
import {
  convergeValidationResult,
  deleteValidationResultReference,
  readValidationResult,
  readValidationResultReference,
  validationResultKey,
} from '../src/validation-result.js';
import { WORKER_TERMINAL_RESULT_MAX_BYTES } from '../src/anchors.js';
import { createManagedArtifactKey } from '../src/managed-artifact-key.js';
import { canonicalJsonBytes, fixture } from './fixture.js';

const job = fixture.worker_claims.queue_job;
const accepted = fixture.worker_claims.terminal_result_accepted;
const rejected = fixture.worker_claims.terminal_result_rejected;

test('terminal result key is deterministic and bound to the exact object version', async () => {
  const expectedDigest = await sha256Hex(job.object_version);
  assert.equal(
    await validationResultKey(job.object_key, job.object_version),
    `${job.object_key}.validation-${expectedDigest}.json`,
  );
});

test('strict result reads classify absent, malformed, foreign, matching, and contradictory winners', async () => {
  const bucket = new FakeBucket();
  assert.equal((await readValidationResult(bucket, job, accepted)).status, 'absent');

  const key = await validationResultKey(job.object_key, job.object_version);
  bucket.seedBytes(key, new TextEncoder().encode('{"not":"a terminal result"}'));
  assert.equal((await readValidationResult(bucket, job, accepted)).status, 'invalid');

  bucket.seedBytes(key, canonicalJsonBytes({ ...accepted, unexpected: 'field' }));
  assert.equal((await readValidationResult(bucket, job, accepted)).status, 'invalid');

  bucket.seedJson(key, { ...accepted, storage_identity: 'f'.repeat(64) });
  assert.equal((await readValidationResult(bucket, job, accepted)).status, 'foreign');

  bucket.seedJson(key, { ...accepted, validated_at: accepted.validated_at + 17 });
  assert.equal((await readValidationResult(bucket, job, accepted)).status, 'matching');
  assert.equal((await readValidationResult(bucket, job, rejected)).status, 'contradictory');
});

test('strict result references classify accepted and rejected exact immutable results', async () => {
  const bucket = new FakeBucket();
  const reference = galleryReference();
  const key = await validationResultKey(reference.object_key, reference.object_version);
  assert.equal((await readValidationResultReference(bucket, reference)).status, 'absent');
  assert.equal((await readValidationResultReference(bucket, reviewReference())).key, key);

  bucket.seedJson(key, accepted);
  assert.deepEqual(await readValidationResultReference(bucket, reference), {
    status: 'matching',
    key,
    result: accepted,
  });
  assert.equal((await readValidationResultReference(bucket, reviewReference())).status, 'matching');

  bucket.seedJson(key, rejected);
  assert.deepEqual(await readValidationResultReference(bucket, reference), {
    status: 'matching',
    key,
    result: rejected,
  });
});

test('strict result references reject foreign exact-result bindings without trusting the result key alone', async () => {
  const variants = [
    ['environment_id', 'foreign-env'],
    ['storage_identity', 'f'.repeat(64)],
    ['validation_contract_version', 'other-contract'],
    ['upload_id', 'other_upload'],
    ['etag', `${job.etag}-foreign`],
    ['bytes', job.bytes + 1],
    ['policy_fingerprint', 'f'.repeat(64)],
  ];
  for (const [field, value] of variants) {
    const bucket = new FakeBucket();
    const reference = galleryReference({ [field]: value });
    bucket.seedJson(await validationResultKey(reference.object_key, reference.object_version), accepted);
    assert.equal((await readValidationResultReference(bucket, reference)).status, 'foreign', field);
  }

  const objectReference = galleryReference({ object_key: await alternateObjectKey(7), ordinal: 7 });
  const objectBucket = new FakeBucket();
  objectBucket.seedJson(await validationResultKey(objectReference.object_key, objectReference.object_version), accepted);
  assert.equal((await readValidationResultReference(objectBucket, objectReference)).status, 'foreign', 'object_key');

  const versionReference = galleryReference({ object_version: `${job.object_version}-foreign` });
  const versionBucket = new FakeBucket();
  versionBucket.seedJson(await validationResultKey(versionReference.object_key, versionReference.object_version), accepted);
  assert.equal((await readValidationResultReference(versionBucket, versionReference)).status, 'foreign', 'object_version');
});

test('strict result references bind terminal result batch, intent, and ordinal to the parsed object key', async () => {
  const cases = [
    { ...accepted, batch_id: fixture.worker_claims.upload_grant.intent_id },
    { ...accepted, intent_id: fixture.worker_claims.upload_grant.batch_id },
    { ...accepted, ordinal: accepted.ordinal + 1 },
  ];
  for (const result of cases) {
    const bucket = new FakeBucket();
    const reference = galleryReference();
    bucket.seedJson(await validationResultKey(reference.object_key, reference.object_version), result);
    assert.equal((await readValidationResultReference(bucket, reference)).status, 'foreign');
  }

  assert.equal((await readValidationResultReference(new FakeBucket(), galleryReference({ ordinal: job.ordinal + 1 }))).status, 'invalid');
});

test('strict result references reject terminal results at or after validation_until when supplied', async () => {
  for (const validatedAt of [job.validation_until, job.validation_until + 1]) {
    const bucket = new FakeBucket();
    const reference = galleryReference({ validation_until: job.validation_until });
    bucket.seedJson(await validationResultKey(reference.object_key, reference.object_version), { ...accepted, validated_at: validatedAt });
    assert.equal((await readValidationResultReference(bucket, reference)).status, 'late');
  }

  const reviewBucket = new FakeBucket();
  const review = reviewReference();
  reviewBucket.seedJson(await validationResultKey(review.object_key, review.object_version), { ...accepted, validated_at: job.validation_until });
  assert.equal((await readValidationResultReference(reviewBucket, review)).status, 'matching');
});

test('strict result references reject malformed scalars before result key lookup', async () => {
  const malformedReferences = [
    galleryReference({ environment_id: 'bad environment' }),
    galleryReference({ storage_identity: 'not-hex' }),
    galleryReference({ policy_fingerprint: 'not-hex' }),
    galleryReference({ upload_id: 'bad upload id' }),
    galleryReference({ validation_contract_version: 'bad value!' }),
    galleryReference({ object_version: 'bad value!' }),
    galleryReference({ etag: 'bad value!' }),
    galleryReference({ bytes: String(job.bytes) }),
    galleryReference({ ordinal: String(job.ordinal) }),
    galleryReference({ filename: 'customer.png' }),
    (() => {
      const reference = galleryReference();
      delete reference.object_key;
      return reference;
    })(),
  ];
  for (const reference of malformedReferences) {
    const bucket = new FakeBucket();
    const result = await readValidationResultReference(bucket, reference);
    assert.equal(result.status, 'invalid');
    assert.equal(bucket.getCalls.length, 0);
  }
});

test('strict result references reject malformed, noncanonical, oversized, and failed result reads', async () => {
  const reference = galleryReference();
  const key = await validationResultKey(reference.object_key, reference.object_version);

  const malformed = new FakeBucket();
  malformed.seedBytes(key, new TextEncoder().encode('not-json'));
  assert.equal((await readValidationResultReference(malformed, reference)).status, 'invalid');

  const noncanonical = new FakeBucket();
  noncanonical.seedBytes(key, new TextEncoder().encode(JSON.stringify(accepted, null, 2)));
  assert.equal((await readValidationResultReference(noncanonical, reference)).status, 'invalid');

  const oversized = new FakeBucket();
  let read = false;
  oversized.seedBody(key, bodyFromReader({
    async read() {
      read = true;
      return { done: true };
    },
    releaseLock() {},
  }), { size: WORKER_TERMINAL_RESULT_MAX_BYTES + 1 });
  assert.equal((await readValidationResultReference(oversized, reference)).status, 'invalid');
  assert.equal(read, false);

  const failing = new FakeBucket();
  failing.seedBody(key, new ReadableStream({ pull() { throw new Error('stream failed'); } }));
  assert.equal((await readValidationResultReference(failing, reference)).status, 'read_error');
});

test('first structurally valid exact result is conditionally created as canonical JSON', async () => {
  const bucket = new FakeBucket();
  const created = await convergeValidationResult(bucket, job, accepted);

  assert.equal(created.status, 'created');
  const key = await validationResultKey(job.object_key, job.object_version);
  assert.equal(bucket.putCalls.length, 1);
  assert.equal(bucket.putCalls[0].key, key);
  assert.deepEqual(bucket.putCalls[0].options.onlyIf, { etagDoesNotMatch: '*' });
  assert.equal(bucket.putCalls[0].options.httpMetadata.contentType, 'application/json; charset=utf-8');
  assert.deepEqual(JSON.parse(text(bucket.objects.get(key).bytes)), accepted);
  assert.deepEqual(Object.keys(JSON.parse(text(bucket.objects.get(key).bytes))), Object.keys(accepted).sort());
  assert.deepEqual([...bucket.objects.get(key).bytes], [...canonicalJsonBytes(accepted)]);
});

test('strict reads reject oversized result metadata before reading the body', async () => {
  const bucket = new FakeBucket();
  const key = await validationResultKey(job.object_key, job.object_version);
  let read = false;
  bucket.seedBody(key, bodyFromReader({
    async read() {
      read = true;
      return { done: true };
    },
    releaseLock() {},
  }), {
    size: WORKER_TERMINAL_RESULT_MAX_BYTES + 1,
  });

  assert.equal((await readValidationResult(bucket, job, accepted)).status, 'invalid');
  assert.equal(read, false);
});

test('strict reads cap lying or missing-size result streams and cancel on overflow', async () => {
  for (const size of [undefined, WORKER_TERMINAL_RESULT_MAX_BYTES]) {
    const bucket = new FakeBucket();
    const key = await validationResultKey(job.object_key, job.object_version);
    let cancelled = false;
    bucket.seedBody(key, bodyFromChunks([
      new Uint8Array(WORKER_TERMINAL_RESULT_MAX_BYTES),
      new Uint8Array([123]),
    ], { onCancel: () => { cancelled = true; } }), { size });

    assert.equal((await readValidationResult(bucket, job, accepted)).status, 'invalid');
    assert.equal(cancelled, true);
  }
});

test('body stream or UTF-8 decode failures return read_error without throwing', async () => {
  const key = await validationResultKey(job.object_key, job.object_version);
  for (const body of [
    new ReadableStream({ pull() { throw new Error('stream failed'); } }),
    streamFromChunks([new Uint8Array([0xff])]),
  ]) {
    const bucket = new FakeBucket();
    bucket.seedBody(key, body);
    assert.equal((await readValidationResult(bucket, job, accepted)).status, 'read_error');
  }
});

test('noncanonical terminal result JSON is invalid even when the normalized shape is valid', async () => {
  const bucket = new FakeBucket();
  const key = await validationResultKey(job.object_key, job.object_version);
  bucket.seedBytes(key, new TextEncoder().encode(JSON.stringify(accepted, null, 2)));
  assert.equal((await readValidationResult(bucket, job, accepted)).status, 'invalid');

  const reversed = {};
  for (const field of Object.keys(accepted).reverse()) reversed[field] = accepted[field];
  bucket.seedBytes(key, new TextEncoder().encode(JSON.stringify(reversed)));
  assert.equal((await readValidationResult(bucket, job, accepted)).status, 'invalid');
});

test('same terminal winner converges without overwrite', async () => {
  const bucket = new FakeBucket();
  const key = await validationResultKey(job.object_key, job.object_version);
  bucket.seedJson(key, { ...accepted, validated_at: accepted.validated_at + 33 });

  const duplicate = await convergeValidationResult(bucket, job, accepted);

  assert.equal(duplicate.status, 'matching');
  assert.equal(bucket.putCalls.length, 0);
  assert.equal(JSON.parse(text(bucket.objects.get(key).bytes)).validated_at, accepted.validated_at + 33);
});

test('conditional result creation race re-reads the first valid winner', async () => {
  const bucket = new FakeBucket();
  const key = await validationResultKey(job.object_key, job.object_version);
  bucket.beforePutReturnNull = () => bucket.seedJson(key, accepted);

  const raced = await convergeValidationResult(bucket, job, accepted);

  assert.equal(raced.status, 'matching');
  assert.equal(bucket.putCalls.length, 1);
  assert.deepEqual(JSON.parse(text(bucket.objects.get(key).bytes)), accepted);
});

test('lost result put response re-reads the committed winner', async () => {
  const bucket = new FakeBucket();
  bucket.throwAfterPut = true;

  const recovered = await convergeValidationResult(bucket, job, accepted);

  assert.equal(recovered.status, 'matching');
  assert.equal(bucket.putCalls.length, 1);
  assert.deepEqual(recovered.result, accepted);
});

test('contradictory exact winner is reported and never overwritten', async () => {
  const bucket = new FakeBucket();
  const key = await validationResultKey(job.object_key, job.object_version);
  bucket.seedJson(key, rejected);

  const contradicted = await convergeValidationResult(bucket, job, accepted);

  assert.equal(contradicted.status, 'contradictory');
  assert.equal(bucket.putCalls.length, 0);
  assert.deepEqual(JSON.parse(text(bucket.objects.get(key).bytes)), rejected);
});

test('invalid and differently bound existing results are not accepted or overwritten', async () => {
  for (const [seed, expectedStatus] of [
    [new TextEncoder().encode('not-json'), 'invalid'],
    [canonicalJsonBytes({ ...accepted, environment_id: 'foreign-env' }), 'foreign'],
  ]) {
    const bucket = new FakeBucket();
    const key = await validationResultKey(job.object_key, job.object_version);
    bucket.seedBytes(key, seed);

    const result = await convergeValidationResult(bucket, job, accepted);

    assert.equal(result.status, expectedStatus);
    assert.equal(bucket.putCalls.length, 0);
    assert.deepEqual([...bucket.objects.get(key).bytes], [...seed]);
  }
});

test('deleteValidationResultReference deletes only exact matching terminal results', async () => {
  const bucket = new ResultBucket();
  const reference = resultReference();
  const key = await validationResultKey(reference.object_key, reference.object_version);
  bucket.seedJson(key, terminalResult());

  const deleted = await deleteValidationResultReference(bucket, reference);
  assert.equal(deleted.status, 'deleted');
  assert.equal(deleted.key, key);
  assert.equal(bucket.objects.has(key), false);

  const absent = await deleteValidationResultReference(bucket, reference);
  assert.equal(absent.status, 'absent');
  assert.equal(bucket.deleteCalls.length, 1);
});

test('deleteValidationResultReference refuses foreign, invalid, and read-error results', async () => {
  const reference = resultReference();
  const key = await validationResultKey(reference.object_key, reference.object_version);

  const foreign = new ResultBucket();
  foreign.seedJson(key, terminalResult({ etag: 'different-etag' }));
  const foreignResult = await deleteValidationResultReference(foreign, reference);
  assert.equal(foreignResult.status, 'foreign');
  assert.equal(foreign.objects.has(key), true);
  assert.deepEqual(foreign.deleteCalls, []);

  const invalid = new ResultBucket();
  invalid.seedBytes(key, new TextEncoder().encode('not-json'));
  const invalidResult = await deleteValidationResultReference(invalid, reference);
  assert.equal(invalidResult.status, 'invalid');
  assert.equal(invalid.objects.has(key), true);
  assert.deepEqual(invalid.deleteCalls, []);

  const readError = new ResultBucket();
  readError.seedJson(key, terminalResult());
  readError.throwOnGet.add(key);
  const failed = await deleteValidationResultReference(readError, reference);
  assert.equal(failed.status, 'read_error');
  assert.equal(readError.objects.has(key), true);
  assert.deepEqual(readError.deleteCalls, []);
});

test('deleteValidationResultReference confirms deletion and reports uncertainty', async () => {
  const reference = resultReference();
  const key = await validationResultKey(reference.object_key, reference.object_version);
  const bucket = new ResultBucket();
  bucket.seedJson(key, terminalResult());
  bucket.noopDelete.add(key);

  const uncertain = await deleteValidationResultReference(bucket, reference);
  assert.equal(uncertain.status, 'delete_uncertain');
  assert.equal(bucket.objects.has(key), true);
  assert.deepEqual(bucket.deleteCalls, [key]);
});

function resultReference(overrides = {}) {
  const claims = fixture.worker_claims.object_request_known_delete;
  return {
    environment_id: fixture.environment,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    upload_id: claims.upload_id,
    object_key: claims.object_key,
    object_version: claims.object_version,
    etag: claims.etag,
    bytes: claims.bytes,
    policy_fingerprint: claims.policy_fingerprint,
    ...overrides,
  };
}

function terminalResult(overrides = {}) {
  const claims = fixture.worker_claims.object_request_known_delete;
  return {
    result_version: 1,
    protocol_version: 3,
    environment_id: fixture.environment,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    batch_id: claims.batch_id,
    intent_id: claims.intent_id,
    upload_id: claims.upload_id,
    ordinal: claims.ordinal,
    object_key: claims.object_key,
    object_version: claims.object_version,
    etag: claims.etag,
    bytes: claims.bytes,
    policy_fingerprint: claims.policy_fingerprint,
    outcome: 'accepted',
    mime: 'image/png',
    width: 32,
    height: 24,
    validated_at: fixture.verification_now,
    ...overrides,
  };
}

class ResultBucket {
  constructor() {
    this.objects = new Map();
    this.deleteCalls = [];
    this.throwOnGet = new Set();
    this.noopDelete = new Set();
  }

  seedJson(key, value) {
    this.seedBytes(key, canonicalJsonBytes(value));
  }

  seedBytes(key, bytes) {
    this.objects.set(key, {
      key,
      bytes,
      size: bytes.byteLength,
      body: new Response(bytes).body,
    });
  }

  async get(key) {
    if (this.throwOnGet.has(key)) throw new Error('read failed');
    const stored = this.objects.get(key);
    if (!stored) return null;
    return { key, size: stored.size, body: new Response(stored.bytes).body };
  }

  async delete(key) {
    this.deleteCalls.push(key);
    if (!this.noopDelete.has(key)) this.objects.delete(key);
  }
}

class FakeBucket {
  constructor() {
    this.objects = new Map();
    this.putCalls = [];
    this.getCalls = [];
    this.beforePutReturnNull = null;
    this.throwAfterPut = false;
    this.deleteCalls = [];
    this.uploaded = new Date((job.validation_until - 1) * 1000);
  }

  seedJson(key, value) {
    this.seedBytes(key, canonicalJsonBytes(value));
  }

  seedBytes(key, bytes, metadata = {}) {
    this.seedBody(key, new Response(bytes).body, { size: bytes.byteLength, ...metadata, bytes });
  }

  seedBody(key, body, metadata = {}) {
    this.objects.set(key, {
      key,
      bytes: Object.hasOwn(metadata, 'bytes') ? metadata.bytes : null,
      body,
      size: metadata.size,
      etag: 'result-etag',
      uploaded: metadata.uploaded || this.uploaded,
    });
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
      etag: stored.etag,
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
    this.seedBytes(key, bytes);
    if (this.throwAfterPut) throw new Error('lost result response');
    return { key, etag: this.objects.get(key).etag, uploaded: this.objects.get(key).uploaded };
  }

  async delete(key) {
    this.deleteCalls.push(key);
    this.objects.delete(key);
  }
}

async function sha256Hex(value) {
  const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value)));
  return [...digest].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

function text(bytes) {
  return new TextDecoder().decode(bytes);
}

function galleryReference(overrides = {}) {
  return {
    environment_id: job.environment_id,
    storage_identity: job.storage_identity,
    validation_contract_version: job.validation_contract_version,
    upload_id: job.upload_id,
    ordinal: job.ordinal,
    object_key: job.object_key,
    object_version: job.object_version,
    etag: job.etag,
    bytes: job.bytes,
    policy_fingerprint: job.policy_fingerprint,
    ...overrides,
  };
}

function reviewReference(overrides = {}) {
  const { ordinal: _ordinal, ...reference } = galleryReference(overrides);
  return reference;
}

async function alternateObjectKey(ordinal) {
  const intentId = `${'i'.repeat(job.intent_id.length)}${ordinal}`.slice(-job.intent_id.length);
  return createManagedArtifactKey(job.batch_id, ordinal, intentId, 'image/png');
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

function bodyFromChunks(chunks, hooks = {}) {
  return bodyFromReader({
    async read() {
      const chunk = chunks.shift();
      return chunk ? { done: false, value: chunk } : { done: true };
    },
    async cancel() {
      if (hooks.onCancel) hooks.onCancel();
    },
    releaseLock() {},
  });
}

function bodyFromReader(reader) {
  return { getReader: () => reader };
}
