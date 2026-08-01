/**
 * Worker-owned projection of media capabilities that Cloudflare must inspect
 * and deliver. Changes remain coordinated with PHP acceptance but are not
 * inferred from it: provider support must be proved independently.
 */

const MIME_BY_FORMAT = Object.freeze({
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  heic: 'image/heic',
  heif: 'image/heif',
  'image/jpeg': 'image/jpeg',
  'image/png': 'image/png',
  'image/webp': 'image/webp',
  'image/heic': 'image/heic',
  'image/heif': 'image/heif',
});

const EXTENSION_BY_MIME = Object.freeze({
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/heic': 'heic',
  'image/heif': 'heif',
});
const SUPPORTED_EXTENSIONS = new Set(Object.values(EXTENSION_BY_MIME));

export const MIME_PATTERN = /^image\/(?:jpeg|png|webp|heic|heif)$/;

export function detectedMime(format) {
  const normalized = typeof format === 'string' ? format.toLowerCase() : '';
  return MIME_BY_FORMAT[normalized] || '';
}

export function extensionForMime(mime) {
  return EXTENSION_BY_MIME[mime] || '';
}

export function supportedExtension(extension) {
  return typeof extension === 'string' && SUPPORTED_EXTENSIONS.has(extension);
}

export function supportedMime(mime) {
  return typeof mime === 'string' && MIME_PATTERN.test(mime);
}

export function mimeMatches(left, right) {
  if (left === right) return supportedMime(left);
  return (left === 'image/heic' || left === 'image/heif')
    && (right === 'image/heic' || right === 'image/heif');
}
