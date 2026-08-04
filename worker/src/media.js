import { inspectHeif } from './heif.js';
import { detectedMime, mimeMatches } from './media-policy.js';

const PNG_SIGNATURE = new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10]);

export class MediaInspectionRejection extends Error {
  constructor(reason) {
    super('inspection_rejected');
    this.reason = reason;
  }
}

export async function inspectArtifact(bucket, images, object, claims) {
  await validateDeclaredContainer(bucket, object, claims);
  const infoObject = await exactObject(bucket, object.key, object.version, object.etag);
  const info = await images.info(infoObject.body);
  const mime = detectedMime(info && info.format);
  const bytes = integerFact(info && info.fileSize);
  let width = integerFact(info && info.width);
  let height = integerFact(info && info.height);
  if (mime === 'image/heic' || mime === 'image/heif') {
    const containerObject = await exactObject(bucket, object.key, object.version, object.etag);
    const container = inspectHeif(
      new Uint8Array(await new Response(containerObject.body).arrayBuffer()),
      claims.container_entry_limit,
    );
    if (!container) throw new MediaInspectionRejection('invalid_media');
    width = container.width;
    height = container.height;
  }
  if (!mime || !mimeMatches(mime, claims.declared_mime)) throw new MediaInspectionRejection('unsupported_media');
  if (bytes !== object.size || bytes !== claims.declared_bytes || width < 1 || height < 1) {
    throw new MediaInspectionRejection('invalid_media');
  }
  if (Math.max(width, height) > claims.max_edge || width > Math.floor(claims.max_pixels / height)) {
    throw new MediaInspectionRejection('policy_rejected');
  }
  if (mime === 'image/png' || mime === 'image/webp') {
    await headExactObject(bucket, object.key, object.version, object.etag);
  }
  return { bytes, mime, width, height };
}

async function validateDeclaredContainer(bucket, object, claims) {
  if (claims.declared_mime !== 'image/png' && claims.declared_mime !== 'image/webp') return;
  const scanObject = await exactObject(bucket, object.key, object.version, object.etag);
  const animated = await inspectAnimation(scanObject.body, claims.declared_mime, claims.container_entry_limit);
  if (animated) throw new MediaInspectionRejection('unsupported_media');
}

async function inspectAnimation(body, mime, entryLimit) {
  try {
    return mime === 'image/png'
      ? await pngIsAnimated(body, entryLimit)
      : await webpIsAnimated(body);
  } catch (error) {
    if (error && ['invalid_png', 'invalid_webp', 'truncated_media', 'container_limit'].includes(error.message)) {
      throw new MediaInspectionRejection('invalid_media');
    }
    throw error;
  }
}

async function exactObject(bucket, key, version, etag) {
  const object = await bucket.get(key, { onlyIf: { etagMatches: etag } });
  if (!object || object.version !== version || object.etag !== etag || !object.body) {
    throw new Error('object_changed');
  }
  return object;
}

async function headExactObject(bucket, key, version, etag) {
  const object = await bucket.head(key);
  if (!object || object.version !== version || object.etag !== etag) {
    throw new Error('object_changed');
  }
  return object;
}

function integerFact(value) {
  return Number.isSafeInteger(value) ? value : -1;
}

async function pngIsAnimated(stream, entryLimit) {
  const reader = new StreamReader(stream);
  try {
    if (!equalBytes(await reader.read(8), PNG_SIGNATURE)) throw new Error('invalid_png');
    for (let entries = 0; entries < entryLimit; entries += 1) {
      const header = await reader.read(8);
      const length = new DataView(header.buffer, header.byteOffset, 4).getUint32(0, false);
      const type = String.fromCharCode(...header.subarray(4, 8));
      if (type === 'acTL') return true;
      if (type === 'IDAT' || type === 'IEND') return false;
      await reader.skip(length + 4);
    }
    throw new Error('container_limit');
  } finally {
    await reader.cancel();
  }
}

async function webpIsAnimated(stream) {
  const reader = new StreamReader(stream);
  try {
    const header = await reader.read(12);
    if (ascii(header.subarray(0, 4)) !== 'RIFF' || ascii(header.subarray(8, 12)) !== 'WEBP') {
      throw new Error('invalid_webp');
    }
    const chunk = await reader.read(8);
    const type = ascii(chunk.subarray(0, 4));
    if (type === 'ANIM' || type === 'ANMF') return true;
    if (type !== 'VP8X') return false;
    const flags = await reader.read(1);
    return (flags[0] & 0x02) !== 0;
  } finally {
    await reader.cancel();
  }
}

class StreamReader {
  constructor(stream) {
    if (!stream || typeof stream.getReader !== 'function') throw new Error('body_unavailable');
    this.reader = stream.getReader();
    this.chunk = new Uint8Array(0);
    this.offset = 0;
  }

  async read(length) {
    const output = new Uint8Array(length);
    let written = 0;
    while (written < length) {
      if (this.offset >= this.chunk.byteLength) {
        const next = await this.reader.read();
        if (next.done || !(next.value instanceof Uint8Array)) throw new Error('truncated_media');
        this.chunk = next.value;
        this.offset = 0;
      }
      const take = Math.min(length - written, this.chunk.byteLength - this.offset);
      output.set(this.chunk.subarray(this.offset, this.offset + take), written);
      this.offset += take;
      written += take;
    }
    return output;
  }

  async skip(length) {
    let remaining = length;
    while (remaining > 0) {
      if (this.offset >= this.chunk.byteLength) {
        const next = await this.reader.read();
        if (next.done || !(next.value instanceof Uint8Array)) throw new Error('truncated_media');
        this.chunk = next.value;
        this.offset = 0;
      }
      const take = Math.min(remaining, this.chunk.byteLength - this.offset);
      this.offset += take;
      remaining -= take;
    }
  }

  async cancel() {
    try {
      await this.reader.cancel();
    } catch {
      // Inspection outcome remains authoritative when stream cleanup fails.
    }
    try {
      this.reader.releaseLock();
    } catch {
      // A closed or errored reader may already have released its lock.
    }
  }
}

function ascii(bytes) {
  return String.fromCharCode(...bytes);
}

function equalBytes(left, right) {
  return left.byteLength === right.byteLength && left.every((value, index) => value === right[index]);
}
