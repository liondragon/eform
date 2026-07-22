import {
  MANAGED_ID_MAX_CHARS,
  WORKER_OPAQUE_MAX_CHARS,
  WORKER_CLOCK_SKEW_SECONDS,
  WORKER_ENVELOPE_MAX_CHARS,
  WORKER_INTEGRATION_KEY_BYTES,
} from './anchors.js';
import { MIME_PATTERN } from './media-policy.js';

const encoder = new TextEncoder();
const decoder = new TextDecoder('utf-8', { fatal: true });

export const VERSION = '1';
export const REVIEW_RECIPE_VERSION = 'review-jpeg-v1';
export const ENVELOPE_MAX_CHARS = WORKER_ENVELOPE_MAX_CHARS;
export const DOMAINS = Object.freeze({
  uploadGrant: 'eforms-worker-upload-grant',
  uploadReceipt: 'eforms-worker-upload-receipt',
  reviewGrant: 'eforms-worker-review-grant',
  objectRequest: 'eforms-worker-object-request',
  objectResult: 'eforms-worker-object-result',
  healthRequest: 'eforms-worker-health-request',
  healthResult: 'eforms-worker-health-result',
});

const schemas = Object.freeze({
  uploadGrant: {
    domain: DOMAINS.uploadGrant,
    fields: {
      intent_id: 'digest', batch_id: 'digest', upload_id: 'managedId', ordinal: 'uint',
      object_key: 'objectKey', declared_bytes: 'positiveInt', declared_mime: 'mime',
      policy_fingerprint: 'hexDigest', max_bytes: 'positiveInt', max_edge: 'positiveInt',
      max_pixels: 'positiveInt', container_entry_limit: 'positiveInt',
      intent_expires_at: 'positiveInt', grant_expires_at: 'positiveInt',
      upload_max_seconds: 'positiveInt', receipt_ttl_seconds: 'positiveInt',
    },
    expiry: 'grant_expires_at',
  },
  uploadReceipt: {
    domain: DOMAINS.uploadReceipt,
    fields: {
      intent_id: 'digest', batch_id: 'digest', upload_id: 'managedId', ordinal: 'uint',
      object_key: 'objectKey', object_version: 'opaque', etag: 'opaque', bytes: 'positiveInt',
      mime: 'mime', width: 'positiveInt', height: 'positiveInt',
      policy_fingerprint: 'hexDigest', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
  },
  reviewGrant: {
    domain: DOMAINS.reviewGrant,
    fields: {
      submission_id: 'managedId', upload_id: 'managedId', object_key: 'objectKey',
      object_version: 'opaque', action: 'reviewAction', recipe_version: 'opaque',
      expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
  },
  objectRequest: {
    domain: DOMAINS.objectRequest,
    fields: {
      request_id: 'managedId', object_key: 'objectKey', object_version: 'opaque',
      action: 'objectAction', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
  },
  objectResult: {
    domain: DOMAINS.objectResult,
    fields: {
      request_id: 'managedId', object_key: 'objectKey', object_version: 'opaque',
      status: 'objectStatus', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
  },
  healthRequest: {
    domain: DOMAINS.healthRequest,
    fields: { request_id: 'managedId', expires_at: 'positiveInt' },
    expiry: 'expires_at',
  },
  healthResult: {
    domain: DOMAINS.healthResult,
    fields: {
      request_id: 'managedId', storage_ready: 'boolean', inspection_ready: 'boolean',
      checked_at: 'positiveInt', expires_at: 'positiveInt',
    },
    expiry: 'expires_at',
  },
});

const patterns = Object.freeze({
  digest: /^[A-Za-z0-9_-]{43}$/,
  managedId: new RegExp(`^[A-Za-z0-9_-]{1,${MANAGED_ID_MAX_CHARS}}$`),
  objectKey: /^artifacts\/[0-9a-f]{2}\/[0-9a-f]{64}$/,
  opaque: new RegExp(`^[A-Za-z0-9._:-]{1,${WORKER_OPAQUE_MAX_CHARS}}$`),
  hexDigest: /^[0-9a-f]{64}$/,
  mime: MIME_PATTERN,
  reviewAction: /^(?:preview|download)$/,
  objectAction: /^(?:delete|inspect)$/,
  objectStatus: /^(?:present|absent|version_mismatch)$/,
  binding: /^[A-Za-z0-9._-]{1,64}$/,
});

export async function verifyUploadGrant(token, keys, environment, now) {
  return verify('uploadGrant', token, keys, environment, now);
}

export async function signUploadGrant(claims, keyId, secret, environment) {
  return sign('uploadGrant', claims, keyId, secret, environment);
}

export async function verifyUploadReceipt(token, keys, environment, now) {
  return verify('uploadReceipt', token, keys, environment, now);
}

export async function signUploadReceipt(claims, keyId, secret, environment) {
  return sign('uploadReceipt', claims, keyId, secret, environment);
}

export async function verifyReviewGrant(token, keys, environment, now) {
  return verify('reviewGrant', token, keys, environment, now);
}

export async function signReviewGrant(claims, keyId, secret, environment) {
  return sign('reviewGrant', claims, keyId, secret, environment);
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

export async function verifyObjectRequest(token, keys, environment, now) {
  return verify('objectRequest', token, keys, environment, now);
}

export async function signObjectRequest(claims, keyId, secret, environment) {
  return sign('objectRequest', claims, keyId, secret, environment);
}

export async function verifyObjectResult(token, keys, environment, now) {
  return verify('objectResult', token, keys, environment, now);
}

export async function signObjectResult(claims, keyId, secret, environment) {
  return sign('objectResult', claims, keyId, secret, environment);
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
  const normalized = normalizeClaims(claims, schema.fields);
  if (!normalized) return '';
  const parts = [schema.domain, VERSION, keyId, environment];
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
  if (!parts || parts[0] !== schema.domain || parts[1] !== VERSION
    || !patterns.binding.test(parts[2]) || parts[3] !== environment) return failure;
  const secret = keys[parts[2]];
  if (!(secret instanceof Uint8Array) || secret.byteLength !== WORKER_INTEGRATION_KEY_BYTES) return failure;
  const key = await crypto.subtle.importKey('raw', secret, { name: 'HMAC', hash: 'SHA-256' }, false, ['verify']);
  if (!await crypto.subtle.verify('HMAC', key, signature, payload)) return failure;
  const claims = {};
  let index = 4;
  for (const [field, type] of Object.entries(schema.fields)) {
    const value = canonicalValue(parts[index], type);
    if (value === null) return failure;
    claims[field] = typedValue(value, type);
    index += 1;
  }
  const clock = Number.isSafeInteger(now) ? now : Math.floor(Date.now() / 1000);
  const expired = schemaName === 'reviewGrant'
    ? claims[schema.expiry] <= clock
    : claims[schema.expiry] < clock - WORKER_CLOCK_SKEW_SECONDS;
  if (expired) return { ok: false, reason: 'expired_envelope' };
  return { ok: true, key_id: parts[2], claims };
}

function normalizeClaims(claims, fields) {
  if (!claims || typeof claims !== 'object' || Array.isArray(claims)) return null;
  const actual = Object.keys(claims).sort();
  const expected = Object.keys(fields).sort();
  if (actual.length !== expected.length || actual.some((field, index) => field !== expected[index])) return null;
  const normalized = {};
  for (const [field, type] of Object.entries(fields)) {
    const value = canonicalValue(claims[field], type);
    if (value === null) return null;
    normalized[field] = value;
  }
  return normalized;
}

function canonicalValue(input, type) {
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
  return typeof input === 'string' && patterns[type] && patterns[type].test(input) ? input : null;
}

function typedValue(value, type) {
  if (type === 'uint' || type === 'positiveInt') return Number(value);
  if (type === 'boolean') return value === '1';
  return value;
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
