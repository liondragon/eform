import { WORKER_TERMINAL_RESULT_MAX_BYTES } from './anchors.js';
import { parseManagedArtifactKey } from './managed-artifact-key.js';
import { canonicalJsonBytes, normalizeWorkerResultReference, normalizeWorkerTerminalResult } from './protocol.js';

const exactBindingFields = Object.freeze([
  'environment_id',
  'storage_identity',
  'validation_contract_version',
  'batch_id',
  'intent_id',
  'upload_id',
  'ordinal',
  'object_key',
  'object_version',
  'etag',
  'bytes',
  'policy_fingerprint',
]);

export async function validationResultKey(objectKey, objectVersion) {
  const digest = new Uint8Array(await crypto.subtle.digest(
    'SHA-256', new TextEncoder().encode(objectVersion),
  ));
  return `${objectKey}.validation-${hex(digest)}.json`;
}

export async function readValidationResult(bucket, job, expected = null) {
  const key = await validationResultKey(job.object_key, job.object_version);
  let object;
  try {
    object = await bucket.get(key);
  } catch {
    return { status: 'read_error', key };
  }
  if (!object) return { status: 'absent', key };
  const parsed = await strictResultFromObject(object);
  if (parsed.status !== 'ok') return { status: parsed.status, key };
  const result = parsed.result;
  if (!resultBindingMatchesJob(result, job)) return { status: 'foreign', key, result };
  if (!resultCommittedBefore(result, object, job.validation_until)) return { status: 'late', key, result };
  if (!expected) return { status: 'matching', key, result };
  const normalized = await normalizeWorkerTerminalResult(expected);
  if (!normalized || !resultBindingMatchesJob(normalized, job)
    || normalized.validated_at >= job.validation_until) return { status: 'invalid_expected', key, result };
  return sameTerminalOutcome(result, normalized)
    ? { status: 'matching', key, result }
    : { status: 'contradictory', key, result };
}

export async function readValidationResultReference(bucket, reference) {
  const normalized = await normalizeResultReference(reference);
  if (!normalized) return { status: 'invalid', key: '' };
  const key = await validationResultKey(normalized.object_key, normalized.object_version);
  return readValidationResultReferenceNormalized(bucket, normalized, key);
}

async function readValidationResultReferenceNormalized(bucket, normalized, key) {
  let object;
  try {
    object = await bucket.get(key);
  } catch {
    return { status: 'read_error', key };
  }
  if (!object) return { status: 'absent', key };
  const parsed = await strictResultFromObject(object);
  if (parsed.status !== 'ok') return { status: parsed.status, key };
  const result = parsed.result;
  if (!resultBindingMatchesReference(result, normalized)) return { status: 'foreign', key, result };
  if (Object.hasOwn(normalized, 'validation_until')
    && !resultCommittedBefore(result, object, normalized.validation_until)) {
    return { status: 'late', key, result };
  }
  return { status: 'matching', key, result };
}

export async function deleteValidationResultReference(bucket, reference) {
  const normalized = await normalizeResultReference(reference);
  if (!normalized) return { status: 'invalid', key: '' };
  const key = await validationResultKey(normalized.object_key, normalized.object_version);
  const before = await readValidationResultReferenceNormalized(bucket, normalized, key);
  if (before.status === 'absent') return { status: 'absent', key };
  if (before.status !== 'matching' && before.status !== 'late') return before;

  try {
    await bucket.delete(key);
  } catch {
    return { status: 'delete_error', key };
  }

  const after = await readValidationResultReferenceNormalized(bucket, normalized, key);
  if (after.status === 'absent') return { status: 'deleted', key };
  if (after.status === 'read_error') return after;
  return { status: 'delete_uncertain', key, result: after.result || null };
}

export async function convergeValidationResult(bucket, job, candidate) {
  const normalized = await normalizeWorkerTerminalResult(candidate);
  const key = await validationResultKey(job.object_key, job.object_version);
  if (!normalized || !resultBindingMatchesJob(normalized, job)
    || normalized.validated_at >= job.validation_until) return { status: 'worker_invalid', key };

  const before = await readValidationResult(bucket, job, normalized);
  if (before.status === 'matching' || before.status === 'contradictory') return before;
  if (before.status === 'late') return discardLateValidationResult(bucket, before);
  if (before.status === 'foreign' || before.status === 'invalid' || before.status === 'read_error') {
    return { status: before.status, key, result: before.result || null };
  }

  try {
    const written = await bucket.put(key, canonicalJsonBytes(normalized), {
      onlyIf: { etagDoesNotMatch: '*' },
      httpMetadata: { contentType: 'application/json; charset=utf-8' },
    });
    if (written) {
      if (!resultCommittedBefore(normalized, written, job.validation_until)) {
        return discardLateValidationResult(bucket, { status: 'late', key, result: normalized });
      }
      return { status: 'created', key, result: normalized };
    }
  } catch {
    const recovered = await readValidationResult(bucket, job, normalized);
    if (recovered.status === 'matching' || recovered.status === 'contradictory') return recovered;
    if (recovered.status === 'late') return discardLateValidationResult(bucket, recovered);
    return { status: 'write_uncertain', key };
  }

  const after = await readValidationResult(bucket, job, normalized);
  if (after.status === 'matching' || after.status === 'contradictory') return after;
  if (after.status === 'late') return discardLateValidationResult(bucket, after);
  if (after.status === 'foreign' || after.status === 'invalid' || after.status === 'read_error') {
    return { status: after.status, key, result: after.result || null };
  }
  return { status: 'write_uncertain', key };
}

export async function discardLateValidationResult(bucket, late) {
  try {
    await bucket.delete(late.key);
  } catch {
    // The result remains non-authoritative; lifecycle cleanup owns confirmation.
  }
  return { status: 'late', key: late.key, result: late.result || null };
}

async function strictResultFromObject(object) {
  if (!object || !object.body) return { status: 'invalid' };
  if (Number.isSafeInteger(object.size)
    && (object.size < 1 || object.size > WORKER_TERMINAL_RESULT_MAX_BYTES)) {
    return { status: 'invalid' };
  }
  const read = await boundedBodyBytes(object.body, WORKER_TERMINAL_RESULT_MAX_BYTES);
  if (read.status !== 'ok') return read;
  const bytes = read.bytes;
  if (bytes.byteLength < 1 || bytes.byteLength > WORKER_TERMINAL_RESULT_MAX_BYTES) return { status: 'invalid' };
  const text = new TextDecoder('utf-8', { fatal: true });
  let decoded;
  try {
    decoded = text.decode(bytes);
  } catch {
    return { status: 'read_error' };
  }
  let parsed;
  try {
    parsed = JSON.parse(decoded);
  } catch {
    return { status: 'invalid' };
  }
  const normalized = await normalizeWorkerTerminalResult(parsed);
  if (!normalized || !equalBytes(bytes, canonicalJsonBytes(normalized))) return { status: 'invalid' };
  return { status: 'ok', result: normalized };
}

async function boundedBodyBytes(body, maxBytes) {
  if (!body || typeof body.getReader !== 'function') return { status: 'invalid' };
  const reader = body.getReader();
  const chunks = [];
  let bytes = 0;
  try {
    while (true) {
      const next = await reader.read();
      if (next.done) break;
      if (!(next.value instanceof Uint8Array)) return { status: 'read_error' };
      bytes += next.value.byteLength;
      if (bytes > maxBytes) {
        await reader.cancel().catch(() => {});
        return { status: 'invalid' };
      }
      chunks.push(next.value);
    }
  } catch {
    return { status: 'read_error' };
  } finally {
    try {
      reader.releaseLock();
    } catch {
      // A cancelled or errored stream can already be released by the runtime.
    }
  }
  const output = new Uint8Array(bytes);
  let offset = 0;
  for (const chunk of chunks) {
    output.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return { status: 'ok', bytes: output };
}

function resultBindingMatchesJob(result, job) {
  return exactBindingFields.every((field) => result[field] === job[field]);
}

async function normalizeResultReference(reference) {
  const normalized = await normalizeWorkerResultReference(reference);
  if (!normalized) return null;
  const objectKey = await parseManagedArtifactKey(normalized.object_key);
  if (!objectKey) return null;
  if (Object.hasOwn(normalized, 'ordinal') && normalized.ordinal !== objectKey.ordinal) {
    return null;
  }
  return {
    ...normalized,
    batch_id: objectKey.namespace,
    intent_id: objectKey.intent_id,
    ordinal: objectKey.ordinal,
  };
}

function resultBindingMatchesReference(result, reference) {
  return exactBindingFields.every((field) => result[field] === reference[field]);
}

function resultCommittedBefore(result, object, validationUntil) {
  const uploaded = object && object.uploaded;
  return result.validated_at < validationUntil
    && uploaded instanceof Date
    && Number.isFinite(uploaded.getTime())
    && uploaded.getTime() < validationUntil * 1000;
}

function sameTerminalOutcome(left, right) {
  if (left.outcome !== right.outcome) return false;
  if (left.outcome === 'accepted') {
    return left.mime === right.mime && left.width === right.width && left.height === right.height;
  }
  return left.reason === right.reason;
}

function equalBytes(left, right) {
  return left.byteLength === right.byteLength && left.every((value, index) => value === right[index]);
}

function hex(bytes) {
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}
