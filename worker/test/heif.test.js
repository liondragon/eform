import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { inspectHeif } from '../src/heif.js';

const encoded = await readFile(new URL('../../tests/fixtures/staged-landscape.heic.b64', import.meta.url), 'utf8');
const fixture = new Uint8Array(Buffer.from(encoded.trim(), 'base64'));
const limit = 4096;

test('bounded HEIF inspection accepts the camera still and rejects sequence or track containers', () => {
  const inspected = inspectHeif(fixture, limit);
  assert.ok(inspected && inspected.width > 0 && inspected.height > 0);

  const sequence = new Uint8Array(fixture);
  const ftyp = asciiOffset(sequence, 'ftyp');
  sequence.set(new TextEncoder().encode('hevc'), ftyp + 4);
  assert.equal(inspectHeif(sequence, limit), null);

  const track = concat(fixture, box('moov', new Uint8Array(0)));
  assert.equal(inspectHeif(track, limit), null);
});

test('bounded HEIF inspection rejects protected definitions and exhausted box budgets', () => {
  const protectedItem = new Uint8Array(fixture);
  protectFirstItemDefinition(protectedItem);
  assert.equal(inspectHeif(protectedItem, limit), null);
  assert.equal(inspectHeif(fixture, 1), null);
});

function protectFirstItemDefinition(bytes) {
  const offset = asciiOffset(bytes, 'infe');
  const version = bytes[offset + 4];
  const protectionOffset = version === 2 ? offset + 10 : (version === 3 ? offset + 12 : -1);
  if (protectionOffset < 0) throw new Error('Unsupported HEIF item fixture.');
  bytes[protectionOffset] = 0;
  bytes[protectionOffset + 1] = 1;
}

function asciiOffset(bytes, value) {
  const target = new TextEncoder().encode(value);
  for (let offset = 0; offset <= bytes.byteLength - target.byteLength; offset += 1) {
    if (target.every((byte, index) => bytes[offset + index] === byte)) return offset;
  }
  throw new Error(`Fixture is missing ${value}.`);
}

function box(type, body) {
  const bytes = new Uint8Array(8 + body.byteLength);
  new DataView(bytes.buffer).setUint32(0, bytes.byteLength, false);
  bytes.set(new TextEncoder().encode(type), 4);
  bytes.set(body, 8);
  return bytes;
}

function concat(left, right) {
  const bytes = new Uint8Array(left.byteLength + right.byteLength);
  bytes.set(left, 0);
  bytes.set(right, left.byteLength);
  return bytes;
}
