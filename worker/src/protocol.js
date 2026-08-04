import {
  MANAGED_ID_MAX_CHARS,
  MANAGED_STAGED_MAX_FILES,
  WORKER_OPAQUE_MAX_CHARS,
  WORKER_CLOCK_SKEW_SECONDS,
  WORKER_ENVELOPE_MAX_CHARS,
  WORKER_INTEGRATION_KEY_BYTES,
  WORKER_QUEUE_JOB_MAX_BYTES,
  WORKER_TERMINAL_RESULT_MAX_BYTES,
  WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES,
  WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES,
} from './anchors.js';
import { MIME_PATTERN } from './media-policy.js';
import { parseManagedArtifactKey, validManagedArtifactKey, validManagedDigest } from './managed-artifact-key.js';

const encoder = new TextEncoder();
const decoder = new TextDecoder('utf-8', { fatal: true });

export const WORKER_PROTOCOL_VERSION = 3;
export const QUEUE_JOB_VERSION = 1;
export const TERMINAL_RESULT_VERSION = 1;
export const VERSION = String(WORKER_PROTOCOL_VERSION);
export const REVIEW_RECIPE_VERSION = 'review-jpeg-v1';
export const ENVELOPE_MAX_CHARS = WORKER_ENVELOPE_MAX_CHARS;
export const DOMAINS = Object.freeze({
  uploadGrant: 'eforms-worker-upload-grant',
  storedReceipt: 'eforms-worker-stored-receipt',
  galleryStatusRequest: 'eforms-worker-gallery-status-request',
  galleryStatusResult: 'eforms-worker-gallery-status-result',
  reviewGrant: 'eforms-worker-review-grant',
  objectRequest: 'eforms-worker-object-request',
  objectResult: 'eforms-worker-object-result',
  healthRequest: 'eforms-worker-health-request',
  healthResult: 'eforms-worker-health-result',
});

const schemas = Object.freeze({
  healthRequest: {
    domain: DOMAINS.healthRequest,
    fields: {
      request_id: 'managedId', storage_identity: 'hexDigest',
      validation_contract_version: 'opaque', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
  },
  healthResult: {
    domain: DOMAINS.healthResult,
    fields: {
      request_id: 'managedId', storage_ready: 'boolean', inspection_ready: 'boolean',
      queue_producer_ready: 'boolean', limiter_ready: 'boolean', keys_ready: 'boolean',
      storage_identity_ready: 'boolean', validation_contract_ready: 'boolean',
      storage_identity: 'hexDigest', validation_contract_version: 'opaque',
      checked_at: 'positiveInt', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
  },
  workerUploadGrant: {
    domain: DOMAINS.uploadGrant,
    version: VERSION,
    fields: {
      intent_id: 'digest', batch_id: 'digest', upload_id: 'managedId', ordinal: 'uint',
      storage_identity: 'hexDigest', validation_contract_version: 'opaque',
      object_key: 'objectKey', declared_bytes: 'positiveInt', declared_mime: 'mime',
      policy_fingerprint: 'hexDigest', max_bytes: 'positiveInt', max_edge: 'positiveInt',
      max_pixels: 'positiveInt', container_entry_limit: 'positiveInt',
      upload_until: 'positiveInt', accept_until: 'positiveInt', validation_until: 'positiveInt',
      staged_delete_after: 'positiveInt', grant_expires_at: 'positiveInt',
    },
    expiry: 'grant_expires_at',
    deadlineOrder: true,
  },
  workerStoredReceipt: {
    domain: DOMAINS.storedReceipt,
    version: VERSION,
    fields: {
      intent_id: 'digest', batch_id: 'digest', upload_id: 'managedId', ordinal: 'uint',
      storage_identity: 'hexDigest', validation_contract_version: 'opaque',
      object_key: 'objectKey', object_version: 'knownOpaque', etag: 'knownOpaque', bytes: 'positiveInt',
      policy_fingerprint: 'hexDigest', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
  },
  workerGalleryStatusRequest: {
    domain: DOMAINS.galleryStatusRequest,
    version: VERSION,
    fields: {
      request_id: 'managedId', submission_id: 'managedId', storage_identity: 'hexDigest',
      items_sha256: 'hexDigest', item_count: 'uint', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
    closedAtEquality: true,
  },
  workerGalleryStatusResult: {
    domain: DOMAINS.galleryStatusResult,
    version: VERSION,
    fields: {
      request_id: 'managedId', submission_id: 'managedId', items_sha256: 'hexDigest',
      statuses_sha256: 'hexDigest', item_count: 'uint', checked_at: 'positiveInt',
      expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
    closedAtEquality: true,
  },
  workerReviewGrant: {
    domain: DOMAINS.reviewGrant,
    version: VERSION,
    fields: {
      submission_id: 'managedId', upload_id: 'managedId', storage_identity: 'hexDigest',
      validation_contract_version: 'opaque', object_key: 'objectKey', object_version: 'knownOpaque',
      etag: 'knownOpaque', bytes: 'positiveInt', policy_fingerprint: 'hexDigest',
      validation_until: 'positiveInt', action: 'reviewAction', recipe_version: 'opaque', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
    closedAtEquality: true,
  },
  workerObjectRequest: {
    domain: DOMAINS.objectRequest,
    version: VERSION,
    fields: {
      request_id: 'managedId', batch_id: 'digest', intent_id: 'digest', upload_id: 'managedId',
      ordinal: 'uint', storage_identity: 'hexDigest', validation_contract_version: 'opaque',
      object_key: 'objectKey', object_version: 'workerObjectVersionOrUnknown',
      etag: 'workerEtagOrUnknown', bytes: 'positiveInt', policy_fingerprint: 'hexDigest',
      action: 'objectAction', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
    closedAtEquality: true,
    workerObjectRequest: true,
  },
  workerObjectResult: {
    domain: DOMAINS.objectResult,
    version: VERSION,
    fields: {
      request_id: 'managedId', object_key: 'objectKey',
      object_version: 'workerObjectVersionOrUnknown', status: 'objectStatus',
      expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
    closedAtEquality: true,
  },
});

const workerQueueJobFields = Object.freeze({
  job_version: 'queueJobVersion',
  protocol_version: 'workerProtocolVersion',
  environment_id: 'binding',
  storage_identity: 'hexDigest',
  validation_contract_version: 'opaque',
  batch_id: 'digest',
  intent_id: 'digest',
  upload_id: 'managedId',
  ordinal: 'uint',
  object_key: 'objectKey',
  object_version: 'knownOpaque',
  etag: 'knownOpaque',
  bytes: 'positiveInt',
  declared_mime: 'mime',
  policy_fingerprint: 'hexDigest',
  max_bytes: 'positiveInt',
  max_edge: 'positiveInt',
  max_pixels: 'positiveInt',
  container_entry_limit: 'positiveInt',
  validation_until: 'positiveInt',
});

const workerTerminalResultCommonFields = Object.freeze({
  result_version: 'terminalResultVersion',
  protocol_version: 'workerProtocolVersion',
  environment_id: 'binding',
  storage_identity: 'hexDigest',
  validation_contract_version: 'opaque',
  batch_id: 'digest',
  intent_id: 'digest',
  upload_id: 'managedId',
  ordinal: 'uint',
  object_key: 'objectKey',
  object_version: 'knownOpaque',
  etag: 'knownOpaque',
  bytes: 'positiveInt',
  policy_fingerprint: 'hexDigest',
  outcome: 'resultOutcome',
  validated_at: 'positiveInt',
});

const workerTerminalResultAcceptedFields = Object.freeze({
  mime: 'mime',
  width: 'positiveInt',
  height: 'positiveInt',
});

const workerTerminalResultRejectedFields = Object.freeze({
  reason: 'resultReason',
});

const workerGalleryItemFields = Object.freeze({
  upload_id: 'managedId',
  ordinal: 'uint',
  validation_contract_version: 'opaque',
  object_key: 'objectKey',
  object_version: 'knownOpaque',
  etag: 'knownOpaque',
  bytes: 'positiveInt',
  policy_fingerprint: 'hexDigest',
  validation_until: 'positiveInt',
});

const workerGalleryStatusPendingFields = Object.freeze({
  upload_id: 'managedId',
  status: 'galleryStatus',
});

const workerGalleryStatusAcceptedFields = Object.freeze({
  upload_id: 'managedId',
  status: 'galleryStatus',
  mime: 'mime',
  width: 'positiveInt',
  height: 'positiveInt',
});

const workerResultReferenceFields = Object.freeze({
  environment_id: 'binding',
  storage_identity: 'hexDigest',
  validation_contract_version: 'opaque',
  upload_id: 'managedId',
  object_key: 'objectKey',
  object_version: 'knownOpaque',
  etag: 'knownOpaque',
  bytes: 'positiveInt',
  policy_fingerprint: 'hexDigest',
});

const patterns = Object.freeze({
  managedId: new RegExp(`^[A-Za-z0-9_-]{1,${MANAGED_ID_MAX_CHARS}}$`),
  opaque: new RegExp(`^[A-Za-z0-9._:-]{1,${WORKER_OPAQUE_MAX_CHARS}}$`),
  hexDigest: /^[0-9a-f]{64}$/,
  mime: MIME_PATTERN,
  reviewAction: /^(?:preview|download)$/,
  objectAction: /^(?:delete|inspect)$/,
  objectStatus: /^(?:present|absent|version_mismatch)$/,
  binding: /^[A-Za-z0-9._-]{1,64}$/,
  resultOutcome: /^(?:accepted|rejected)$/,
  resultReason: /^(?:invalid_media|unsupported_media|policy_rejected)$/,
  galleryStatus: /^(?:pending|unavailable|accepted)$/,
});

export async function verifyWorkerUploadGrant(token, keys, environment, now) {
  return verify('workerUploadGrant', token, keys, environment, now);
}

export async function signWorkerUploadGrant(claims, keyId, secret, environment) {
  return sign('workerUploadGrant', claims, keyId, secret, environment);
}

export async function verifyWorkerStoredReceipt(token, keys, environment, now) {
  return verify('workerStoredReceipt', token, keys, environment, now);
}

export async function signWorkerStoredReceipt(claims, keyId, secret, environment) {
  return sign('workerStoredReceipt', claims, keyId, secret, environment);
}

export async function verifyWorkerGalleryStatusRequest(token, keys, environment, now) {
  return verify('workerGalleryStatusRequest', token, keys, environment, now);
}

export async function signWorkerGalleryStatusRequest(claims, keyId, secret, environment) {
  return sign('workerGalleryStatusRequest', claims, keyId, secret, environment);
}

export async function verifyWorkerGalleryStatusResult(token, keys, environment, now) {
  return verify('workerGalleryStatusResult', token, keys, environment, now);
}

export async function signWorkerGalleryStatusResult(claims, keyId, secret, environment) {
  return sign('workerGalleryStatusResult', claims, keyId, secret, environment);
}

export async function verifyWorkerReviewGrant(token, keys, environment, now) {
  return verify('workerReviewGrant', token, keys, environment, now);
}

export async function signWorkerReviewGrant(claims, keyId, secret, environment) {
  return sign('workerReviewGrant', claims, keyId, secret, environment);
}

export async function verifyWorkerObjectRequest(token, keys, environment, now) {
  return verify('workerObjectRequest', token, keys, environment, now);
}

export async function signWorkerObjectRequest(claims, keyId, secret, environment) {
  return sign('workerObjectRequest', claims, keyId, secret, environment);
}

export async function verifyWorkerObjectResult(token, keys, environment, now) {
  return verify('workerObjectResult', token, keys, environment, now);
}

export async function signWorkerObjectResult(claims, keyId, secret, environment) {
  return sign('workerObjectResult', claims, keyId, secret, environment);
}

export async function normalizeWorkerQueueJob(job) {
  return normalizeExactJsonObject(job, workerQueueJobFields, WORKER_QUEUE_JOB_MAX_BYTES);
}

export async function normalizeWorkerTerminalResult(result) {
  if (!result || typeof result !== 'object' || Array.isArray(result)) return null;
  let fields = null;
  if (result.outcome === 'accepted') {
    fields = { ...workerTerminalResultCommonFields, ...workerTerminalResultAcceptedFields };
  } else if (result.outcome === 'rejected') {
    fields = { ...workerTerminalResultCommonFields, ...workerTerminalResultRejectedFields };
  } else {
    return null;
  }
  return normalizeExactJsonObject(result, fields, WORKER_TERMINAL_RESULT_MAX_BYTES);
}

export async function normalizeWorkerGalleryItems(items) {
  if (!Array.isArray(items) || items.length > MANAGED_STAGED_MAX_FILES) return null;
  const normalized = [];
  const seenUploads = new Set();
  const seenOrdinals = new Set();
  let namespace = null;
  for (const item of items) {
    const candidate = await normalizeExactJsonObjectFields(item, workerGalleryItemFields);
    const objectKey = candidate ? await parseManagedArtifactKey(candidate.object_key) : null;
    if (!candidate || !objectKey || objectKey.ordinal !== candidate.ordinal
      || (namespace !== null && objectKey.namespace !== namespace)
      || seenUploads.has(candidate.upload_id) || seenOrdinals.has(candidate.ordinal)) {
      return null;
    }
    namespace = namespace === null ? objectKey.namespace : namespace;
    seenUploads.add(candidate.upload_id);
    seenOrdinals.add(candidate.ordinal);
    normalized.push(candidate);
  }
  const expected = [...normalized].sort((a, b) => (
    a.ordinal === b.ordinal ? asciiCompare(a.upload_id, b.upload_id) : a.ordinal - b.ordinal
  ));
  return JSON.stringify(normalized) === JSON.stringify(expected) ? normalized : null;
}

export async function normalizeWorkerGalleryStatuses(statuses, items = null) {
  if (!Array.isArray(statuses) || statuses.length > MANAGED_STAGED_MAX_FILES) return null;
  let normalizedItems = null;
  if (items !== null) {
    normalizedItems = await normalizeWorkerGalleryItems(items);
    if (!normalizedItems || normalizedItems.length !== statuses.length) return null;
  }
  const normalized = [];
  const seenUploads = new Set();
  for (const [index, status] of statuses.entries()) {
    if (!status || typeof status !== 'object' || Array.isArray(status) || typeof status.status !== 'string') return null;
    let candidate = null;
    if (status.status === 'accepted') {
      candidate = await normalizeExactJsonObjectFields(status, workerGalleryStatusAcceptedFields);
    } else if (status.status === 'pending' || status.status === 'unavailable') {
      candidate = await normalizeExactJsonObjectFields(status, workerGalleryStatusPendingFields);
    } else {
      return null;
    }
    if (!candidate || seenUploads.has(candidate.upload_id)) return null;
    if (normalizedItems && candidate.upload_id !== normalizedItems[index].upload_id) return null;
    seenUploads.add(candidate.upload_id);
    normalized.push(candidate);
  }
  return normalized;
}

export async function normalizeWorkerResultReference(reference) {
  if (!reference || typeof reference !== 'object' || Array.isArray(reference)) return null;
  const fields = Object.hasOwn(reference, 'ordinal')
    ? { ...workerResultReferenceFields, ordinal: 'uint' }
    : workerResultReferenceFields;
  const fieldsWithDeadline = Object.hasOwn(reference, 'validation_until')
    ? { ...fields, validation_until: 'positiveInt' }
    : fields;
  return normalizeExactJsonObjectFields(reference, fieldsWithDeadline);
}

export async function workerGalleryItemsSha256(items) {
  const normalized = await normalizeWorkerGalleryItems(items);
  return normalized ? await canonicalSha256(normalized) : '';
}

export async function workerGalleryStatusesSha256(statuses, items = null) {
  const normalized = await normalizeWorkerGalleryStatuses(statuses, items);
  return normalized ? await canonicalSha256(normalized) : '';
}

export async function workerGalleryStatusRequestClaimsMatchItems(claims, items) {
  const normalizedClaims = await normalizeClaims(claims, schemas.workerGalleryStatusRequest.fields);
  const normalizedItems = await normalizeWorkerGalleryItems(items);
  return Boolean(normalizedClaims && normalizedItems
    && normalizedClaims.item_count === String(normalizedItems.length)
    && normalizedClaims.items_sha256 === await canonicalSha256(normalizedItems));
}

export async function workerGalleryStatusResultClaimsMatchStatuses(claims, statuses, items) {
  const normalizedClaims = await normalizeClaims(claims, schemas.workerGalleryStatusResult.fields);
  const normalizedStatuses = await normalizeWorkerGalleryStatuses(statuses, items);
  const normalizedItems = await normalizeWorkerGalleryItems(items);
  if (!normalizedClaims || !normalizedStatuses || !normalizedItems) return false;
  return normalizedClaims.item_count === String(normalizedStatuses.length)
    && normalizedClaims.items_sha256 === await canonicalSha256(normalizedItems)
    && normalizedClaims.statuses_sha256 === await canonicalSha256(normalizedStatuses);
}

export async function workerGalleryStatusRequestBodyBytes(token, items) {
  if (typeof token !== 'string' || token === '') return null;
  const normalized = await normalizeWorkerGalleryItems(items);
  if (!normalized) return null;
  const bytes = canonicalJsonBytes({ request: token, items: normalized });
  return bytes && bytes.byteLength <= WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES ? bytes : null;
}

export async function workerGalleryStatusResultBodyBytes(token, statuses, items) {
  if (typeof token !== 'string' || token === '') return null;
  const normalizedItems = await normalizeWorkerGalleryItems(items);
  const normalized = normalizedItems ? await normalizeWorkerGalleryStatuses(statuses, normalizedItems) : null;
  if (!normalized) return null;
  const bytes = canonicalJsonBytes({ result: token, statuses: normalized });
  return bytes && bytes.byteLength <= WORKER_GALLERY_STATUS_RESPONSE_MAX_BYTES ? bytes : null;
}

export async function verifyHealthRequest(token, keys, environment, now) {
  return verify('healthRequest', token, keys, environment, now);
}

export async function signHealthRequest(claims, keyId, secret, environment) {
  return sign('healthRequest', claims, keyId, secret, environment);
}

export async function verifyHealthResult(token, keys, environment, now) {
  return verify('healthResult', token, keys, environment, now);
}

export async function signHealthResult(claims, keyId, secret, environment) {
  return sign('healthResult', claims, keyId, secret, environment);
}

export function keyConfiguration(env) {
  const environment = typeof env.EFORMS_WORKER_ENVIRONMENT_ID === 'string'
    ? env.EFORMS_WORKER_ENVIRONMENT_ID : '';
  const activeId = typeof env.EFORMS_WORKER_ACTIVE_KEY_ID === 'string'
    ? env.EFORMS_WORKER_ACTIVE_KEY_ID : '';
  const active = decodeIntegrationKey(env.EFORMS_WORKER_ACTIVE_KEY_B64);
  if (!patterns.binding.test(environment) || !patterns.binding.test(activeId) || !active) {
    return null;
  }
  const keys = { [activeId]: active };
  const secondaryId = typeof env.EFORMS_WORKER_SECONDARY_KEY_ID === 'string'
    ? env.EFORMS_WORKER_SECONDARY_KEY_ID : '';
  const secondaryEncoded = typeof env.EFORMS_WORKER_SECONDARY_KEY_B64 === 'string'
    ? env.EFORMS_WORKER_SECONDARY_KEY_B64 : '';
  if (secondaryId !== '' || secondaryEncoded !== '') {
    const secondary = decodeIntegrationKey(secondaryEncoded);
    if (!patterns.binding.test(secondaryId) || secondaryId === activeId || !secondary) {
      return null;
    }
    keys[secondaryId] = secondary;
  }
  return { environment, activeId, active, keys };
}

export function decodeIntegrationKey(encoded) {
  const decoded = decodeBase64url(encoded);
  return decoded && decoded.byteLength === WORKER_INTEGRATION_KEY_BYTES ? decoded : null;
}

async function sign(schemaName, claims, keyId, secret, environment) {
  const schema = schemas[schemaName];
  if (!schema || !patterns.binding.test(keyId) || !patterns.binding.test(environment)
    || !(secret instanceof Uint8Array) || secret.byteLength !== WORKER_INTEGRATION_KEY_BYTES) {
    return '';
  }
  const normalized = await normalizeClaims(claims, schema.fields);
  if (!normalized) return '';
  if (!await schemaClaimsAllowed(schema, normalized)) return '';
  const parts = [schema.domain, schemaVersion(schema), keyId, environment];
  for (const field of Object.keys(schema.fields)) parts.push(normalized[field]);
  const payload = encodeParts(parts);
  const key = await crypto.subtle.importKey('raw', secret, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const signature = new Uint8Array(await crypto.subtle.sign('HMAC', key, payload));
  return `${encodeBase64url(payload)}.${encodeBase64url(signature)}`;
}

async function verify(schemaName, token, keys, environment, now) {
  const failure = { ok: false, reason: 'invalid_envelope' };
  const schema = schemas[schemaName];
  if (!schema || typeof token !== 'string' || !keys || typeof keys !== 'object'
    || !patterns.binding.test(environment)) return failure;
  const segments = token.split('.');
  if (segments.length !== 2) return failure;
  const payload = decodeBase64url(segments[0]);
  const signature = decodeBase64url(segments[1]);
  if (!payload || !signature || signature.byteLength !== 32) return failure;
  const parts = decodeParts(payload, 4 + Object.keys(schema.fields).length);
  if (!parts || parts[0] !== schema.domain || parts[1] !== schemaVersion(schema)
    || !patterns.binding.test(parts[2]) || parts[3] !== environment) return failure;
  const secret = keys[parts[2]];
  if (!(secret instanceof Uint8Array) || secret.byteLength !== WORKER_INTEGRATION_KEY_BYTES) return failure;
  const key = await crypto.subtle.importKey('raw', secret, { name: 'HMAC', hash: 'SHA-256' }, false, ['verify']);
  if (!await crypto.subtle.verify('HMAC', key, signature, payload)) return failure;
  const claims = {};
  let index = 4;
  for (const [field, type] of Object.entries(schema.fields)) {
    const value = await canonicalValue(parts[index], type);
    if (value === null) return failure;
    claims[field] = typedValue(value, type);
    index += 1;
  }
  if (!await schemaClaimsAllowed(schema, claims)) return failure;
  const clock = Number.isSafeInteger(now) ? now : Math.floor(Date.now() / 1000);
  const expired = schema.closedAtEquality
    ? claims[schema.expiry] <= clock
    : claims[schema.expiry] < clock - WORKER_CLOCK_SKEW_SECONDS;
  if (expired) return { ok: false, reason: 'expired_envelope' };
  return { ok: true, key_id: parts[2], claims };
}

function schemaVersion(schema) {
  return schema.version || VERSION;
}

async function schemaClaimsAllowed(schema, claims) {
  if (schema.workerObjectRequest && !await workerObjectRequestClaimsAllowed(claims)) return false;
  if (!schema.deadlineOrder) return true;
  const required = ['upload_until', 'accept_until', 'validation_until', 'staged_delete_after', 'grant_expires_at'];
  if (required.some((field) => !Number.isSafeInteger(Number(claims[field])))) return false;
  return Number(claims.upload_until) < Math.min(Number(claims.accept_until), Number(claims.validation_until))
    && Number(claims.validation_until) < Number(claims.staged_delete_after)
    && Number(claims.grant_expires_at) === Number(claims.upload_until);
}

async function workerObjectRequestClaimsAllowed(claims) {
  const parts = await parseManagedArtifactKey(claims.object_key);
  if (!parts || parts.namespace !== claims.batch_id || parts.intent_id !== claims.intent_id
    || parts.ordinal !== Number(claims.ordinal)) {
    return false;
  }
  const unknown = claims.object_version === '-' || claims.etag === '-';
  if (unknown) return claims.object_version === '-' && claims.etag === '-' && claims.action === 'delete';
  return await canonicalValue(claims.object_version, 'opaque') !== null
    && await canonicalValue(claims.etag, 'opaque') !== null;
}

async function normalizeClaims(claims, fields) {
  if (!claims || typeof claims !== 'object' || Array.isArray(claims)) return null;
  const actual = Object.keys(claims).sort();
  const expected = Object.keys(fields).sort();
  if (actual.length !== expected.length || actual.some((field, index) => field !== expected[index])) return null;
  const normalized = {};
  for (const [field, type] of Object.entries(fields)) {
    const value = await canonicalValue(claims[field], type);
    if (value === null) return null;
    normalized[field] = value;
  }
  return normalized;
}

async function canonicalValue(input, type) {
  if (type === 'workerProtocolVersion') return String(input) === VERSION ? VERSION : null;
  if (type === 'queueJobVersion') return String(input) === String(QUEUE_JOB_VERSION) ? String(QUEUE_JOB_VERSION) : null;
  if (type === 'terminalResultVersion') return String(input) === String(TERMINAL_RESULT_VERSION) ? String(TERMINAL_RESULT_VERSION) : null;
  if (type === 'objectKey') return await validManagedArtifactKey(input) ? input : null;
  if (type === 'digest') return validManagedDigest(input) ? input : null;
  if (type === 'uint' || type === 'positiveInt') {
    const value = typeof input === 'number' && Number.isSafeInteger(input) ? String(input) : input;
    if (typeof value !== 'string' || !/^(?:0|[1-9][0-9]*)$/.test(value)) return null;
    const parsed = Number(value);
    if (!Number.isSafeInteger(parsed) || String(parsed) !== value || (type === 'positiveInt' && parsed === 0)) return null;
    return value;
  }
  if (type === 'boolean') {
    if (input === true || input === 1) return '1';
    if (input === false || input === 0) return '0';
    return input === '0' || input === '1' ? input : null;
  }
  if (type === 'workerObjectVersionOrUnknown' || type === 'workerEtagOrUnknown') {
    return typeof input === 'string' && (input === '-' || patterns.opaque.test(input)) ? input : null;
  }
  if (type === 'knownOpaque') {
    return typeof input === 'string' && input !== '-' && patterns.opaque.test(input) ? input : null;
  }
  return typeof input === 'string' && patterns[type] && patterns[type].test(input) ? input : null;
}

function typedValue(value, type) {
  if (type === 'uint' || type === 'positiveInt' || type === 'workerProtocolVersion' || type === 'queueJobVersion' || type === 'terminalResultVersion') return Number(value);
  if (type === 'boolean') return value === '1';
  return value;
}

async function normalizeExactJsonObject(value, fields, maxBytes) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const encoded = JSON.stringify(value);
  if (typeof encoded !== 'string' || new TextEncoder().encode(encoded).byteLength > maxBytes) return null;
  return normalizeExactJsonObjectFields(value, fields);
}

async function normalizeExactJsonObjectFields(value, fields) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const actual = Object.keys(value).sort();
  const expected = Object.keys(fields).sort();
  if (actual.length !== expected.length || actual.some((field, index) => field !== expected[index])) return null;
  const normalized = {};
  for (const [field, type] of Object.entries(fields)) {
    const candidate = await canonicalJsonValue(value[field], type);
    if (candidate === null) return null;
    normalized[field] = candidate;
  }
  return normalized;
}

async function canonicalJsonValue(input, type) {
  if (['uint', 'positiveInt', 'workerProtocolVersion', 'queueJobVersion', 'terminalResultVersion'].includes(type)) {
    if (!Number.isSafeInteger(input)) return null;
  } else if (type === 'boolean') {
    if (typeof input !== 'boolean') return null;
  } else if (typeof input !== 'string') {
    return null;
  }
  const value = await canonicalValue(input, type);
  return value === null ? null : typedValue(value, type);
}

async function canonicalSha256(value) {
  const bytes = canonicalJsonBytes(value);
  if (!bytes) return '';
  return hex(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)));
}

export function canonicalJsonBytes(value) {
  const canonical = canonicalJsonValueTree(value);
  if (canonical === null) return null;
  return encoder.encode(JSON.stringify(canonical));
}

function canonicalJsonValueTree(value) {
  if (Array.isArray(value)) {
    const result = [];
    for (const entry of value) {
      const candidate = canonicalJsonValueTree(entry);
      if (candidate === null) return null;
      result.push(candidate);
    }
    return result;
  }
  if (value && typeof value === 'object') {
    const result = {};
    for (const key of Object.keys(value).sort()) {
      const candidate = canonicalJsonValueTree(value[key]);
      if (candidate === null) return null;
      result[key] = candidate;
    }
    return result;
  }
  if (typeof value === 'string' || typeof value === 'boolean') return value;
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value;
  return null;
}

function encodeParts(parts) {
  const encoded = parts.map((part) => encoder.encode(part));
  const length = encoded.reduce((total, part) => total + 4 + part.byteLength, 0);
  const output = new Uint8Array(length);
  const view = new DataView(output.buffer);
  let offset = 0;
  for (const part of encoded) {
    view.setUint32(offset, part.byteLength, false);
    offset += 4;
    output.set(part, offset);
    offset += part.byteLength;
  }
  return output;
}

function decodeParts(payload, expectedCount) {
  const parts = [];
  const view = new DataView(payload.buffer, payload.byteOffset, payload.byteLength);
  let offset = 0;
  try {
    while (offset < payload.byteLength && parts.length <= expectedCount) {
      if (payload.byteLength - offset < 4) return null;
      const length = view.getUint32(offset, false);
      offset += 4;
      if (length > payload.byteLength - offset) return null;
      parts.push(decoder.decode(payload.subarray(offset, offset + length)));
      offset += length;
    }
  } catch {
    return null;
  }
  return offset === payload.byteLength && parts.length === expectedCount ? parts : null;
}

function encodeBase64url(bytes) {
  let binary = '';
  for (let offset = 0; offset < bytes.byteLength; offset += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function decodeBase64url(encoded) {
  if (typeof encoded !== 'string' || encoded === '' || !/^[A-Za-z0-9_-]+$/.test(encoded) || encoded.length % 4 === 1) return null;
  try {
    const padded = encoded.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - encoded.length % 4) % 4);
    const binary = atob(padded);
    const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
    return encodeBase64url(bytes) === encoded ? bytes : null;
  } catch {
    return null;
  }
}

function hex(bytes) {
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

function asciiCompare(a, b) {
  if (a === b) return 0;
  return a < b ? -1 : 1;
}
