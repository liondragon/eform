import { extensionForMime, supportedExtension } from './media-policy.js';
import { MANAGED_BATCH_ID_CHARS } from './anchors.js';

const DIGEST_PATTERN = `[A-Za-z0-9_-]{${MANAGED_BATCH_ID_CHARS}}`;
const VALID_DIGEST_PATTERN = new RegExp(`^${DIGEST_PATTERN}$`);
const OBJECT_KEY_PATTERN = new RegExp(`^artifacts/([0-9a-f]{2})/(${DIGEST_PATTERN})/((?:0|[1-9][0-9]*))-(${DIGEST_PATTERN})\\.([a-z0-9]{1,16})$`);

export async function createManagedArtifactKey(batchId, ordinal, intentId, mime) {
  const extension = extensionForMime(mime);
  if (!extension || !validManagedDigest(batchId)
    || !Number.isSafeInteger(ordinal) || ordinal < 0
    || !validManagedDigest(intentId)) return '';
  return `artifacts/${await batchShard(batchId)}/${batchId}/${ordinal}-${intentId}.${extension}`;
}

export function validManagedDigest(value) {
  return typeof value === 'string' && VALID_DIGEST_PATTERN.test(value);
}

export async function validManagedArtifactKey(value) {
  return await parseManagedArtifactKey(value) !== null;
}

export async function parseManagedArtifactKey(value) {
  if (typeof value !== 'string') return null;
  const matches = value.match(OBJECT_KEY_PATTERN);
  if (!matches || !supportedExtension(matches[5])) return null;
  const ordinal = Number(matches[3]);
  if (!Number.isSafeInteger(ordinal) || String(ordinal) !== matches[3]) return null;
  if (matches[1] !== await batchShard(matches[2])) return null;
  return {
    shard: matches[1],
    namespace: matches[2],
    ordinal,
    intent_id: matches[4],
    extension: matches[5],
    filename: `${matches[3]}-${matches[4]}.${matches[5]}`,
  };
}

async function batchShard(batchId) {
  const digest = new Uint8Array(await crypto.subtle.digest(
    'SHA-256', new TextEncoder().encode(batchId),
  ));
  return hex(digest).slice(0, 2);
}

function hex(bytes) {
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}
