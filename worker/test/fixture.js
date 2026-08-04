import { readFile } from 'node:fs/promises';

export const fixture = JSON.parse(await readFile(new URL('../../tests/fixtures/worker_protocol.json', import.meta.url), 'utf8'));

const domains = {
  health_request: 'eforms-worker-health-request',
  health_result: 'eforms-worker-health-result',
};

const workerDomains = {
  upload_grant: 'eforms-worker-upload-grant',
  stored_receipt: 'eforms-worker-stored-receipt',
  gallery_status_request: 'eforms-worker-gallery-status-request',
  gallery_status_result: 'eforms-worker-gallery-status-result',
  review_grant: 'eforms-worker-review-grant',
  object_request_known_delete: 'eforms-worker-object-request',
  object_request_unknown_delete: 'eforms-worker-object-request',
  object_request_known_inspect: 'eforms-worker-object-request',
  object_result: 'eforms-worker-object-result',
};

export const tokens = Object.fromEntries(Object.entries(domains).map(([kind, domain]) => [
  kind,
  fixtureToken(domain, fixture.claims[kind], fixture.vectors[kind].signature_b64),
]));

export const workerTokens = Object.fromEntries(Object.entries(workerDomains).map(([kind, domain]) => [
  kind,
  fixtureToken(domain, fixture.worker_claims[kind], fixture.worker_vectors[kind].signature_b64),
]));

export function fixtureToken(domain, claims, signature, version = fixture.version) {
  const parts = [domain, version, fixture.active_key_id, fixture.environment, ...Object.values(claims).map(String)];
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
  let binary = '';
  for (const byte of payload) binary += String.fromCharCode(byte);
  const encodedPayload = btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  return `${encodedPayload}.${signature}`;
}

export function canonicalJsonBytes(value) {
  return new TextEncoder().encode(JSON.stringify(orderJson(value)));
}

function orderJson(value) {
  if (Array.isArray(value)) return value.map(orderJson);
  if (!value || typeof value !== 'object') return value;
  const output = {};
  for (const key of Object.keys(value).sort()) output[key] = orderJson(value[key]);
  return output;
}
