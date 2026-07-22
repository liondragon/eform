import { readFile } from 'node:fs/promises';

export const fixture = JSON.parse(await readFile(new URL('../../tests/fixtures/worker_protocol.json', import.meta.url), 'utf8'));

const domains = {
  upload_grant: 'eforms-worker-upload-grant', upload_receipt: 'eforms-worker-upload-receipt',
  review_grant: 'eforms-worker-review-grant', object_request: 'eforms-worker-object-request',
  object_result: 'eforms-worker-object-result', health_request: 'eforms-worker-health-request',
  health_result: 'eforms-worker-health-result',
};

export const tokens = Object.fromEntries(Object.entries(domains).map(([kind, domain]) => [
  kind,
  fixtureToken(domain, fixture.claims[kind], fixture.vectors[kind].signature_b64),
]));

export function fixtureToken(domain, claims, signature) {
  const parts = [domain, fixture.version, fixture.active_key_id, fixture.environment, ...Object.values(claims).map(String)];
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
