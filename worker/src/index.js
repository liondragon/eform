import {
  ENVELOPE_MAX_CHARS,
  REVIEW_RECIPE_VERSION,
  keyConfiguration,
  signHealthResult,
  signObjectResult,
  signUploadReceipt,
  verifyHealthRequest,
  verifyObjectRequest,
  verifyReviewGrant,
  verifyUploadGrant,
} from './protocol.js';
import {
  MANAGED_FINALIZED_TTL_SECONDS,
  REVIEW_PREVIEW_JPEG_QUALITY_INITIAL,
  REVIEW_PREVIEW_MAX_BYTES,
  REVIEW_PREVIEW_MAX_EDGE,
  WORKER_UPLOAD_RATE_LIMIT_PERIOD_SECONDS,
} from './anchors.js';
import { inspectArtifact } from './media.js';
import { extensionForMime, mimeMatches, supportedMime } from './media-policy.js';

const UPLOAD_PATH = '/v1/upload';
const HEALTH_PATH = '/v1/health';
const OBJECT_PATH = '/v1/object';
const REVIEW_PATH = '/v1/review';
const GRANT_HEADER = 'x-eforms-worker-grant';
const HEALTH_HEADER = 'x-eforms-worker-health';
const OBJECT_HEADER = 'x-eforms-worker-object';
const ALLOWED_REQUEST_HEADERS = ['content-type', GRANT_HEADER];
const CORS_ALLOW_HEADERS = 'Content-Type, X-EForms-Worker-Grant';
export default {
  fetch(request, env) {
    return handleRequest(request, env);
  },
};

export async function handleRequest(request, env, runtime = {}) {
  const url = new URL(request.url);
  if (url.pathname === UPLOAD_PATH && request.method === 'OPTIONS') return preflight(request, env);
  if (url.pathname === UPLOAD_PATH && request.method === 'PUT') return upload(request, env, runtime);
  if (url.pathname === HEALTH_PATH && request.method === 'POST') return health(request, env, runtime);
  if (url.pathname === OBJECT_PATH && request.method === 'POST') return objectOperation(request, env, runtime);
  if (url.pathname === REVIEW_PATH && request.method === 'GET') return review(request, env, runtime);
  return publicError(404);
}

function preflight(request, env) {
  const origin = configuredOrigin(env);
  const requestedMethod = request.headers.get('access-control-request-method');
  const requestedHeaders = headerSet(request.headers.get('access-control-request-headers'));
  if (!origin || request.headers.get('origin') !== origin || requestedMethod !== 'PUT'
    || !sameSet(requestedHeaders, ALLOWED_REQUEST_HEADERS)) return publicError(403);
  return new Response(null, { status: 204, headers: corsHeaders(origin, true) });
}

async function upload(request, env, runtime) {
  const origin = configuredOrigin(env);
  if (!origin || request.headers.get('origin') !== origin || forbiddenAuthorityHeader(request.headers, GRANT_HEADER)) {
    return publicError(403);
  }
  const grantToken = request.headers.get(GRANT_HEADER);
  const contentType = request.headers.get('content-type');
  const contentLength = canonicalLength(request.headers.get('content-length'));
  if (!boundedEnvelope(grantToken) || !contentType || contentLength === null || !request.body) return corsError(400, origin);

  const configuration = keyConfiguration(env);
  if (!configuration || !env.ARTIFACTS || !env.IMAGES) return corsError(503, origin);
  const admittedAt = nowSeconds(runtime);
  const verified = await verifyUploadGrant(grantToken, configuration.keys, configuration.environment, admittedAt);
  if (!verified.ok) return corsError(403, origin);
  const claims = verified.claims;
  if (claims.declared_mime !== contentType || claims.declared_bytes !== contentLength
    || claims.declared_bytes > claims.max_bytes || claims.intent_expires_at < admittedAt) {
    return corsError(claims.declared_bytes > claims.max_bytes ? 413 : 400, origin);
  }
  if (!env.UPLOAD_RATE_LIMITER || typeof env.UPLOAD_RATE_LIMITER.limit !== 'function') {
    return corsError(503, origin);
  }
  try {
    const admission = await env.UPLOAD_RATE_LIMITER.limit({ key: claims.intent_id });
    if (!admission || admission.success !== true) {
      return corsError(429, origin, WORKER_UPLOAD_RATE_LIMIT_PERIOD_SECONDS);
    }
  } catch {
    return corsError(503, origin);
  }

  let object = null;
  let created = false;
  let recoverablePut = false;
  let markerKey = null;
  let validationLease = null;
  try {
    object = await env.ARTIFACTS.head(claims.object_key);
    if (object) {
      cancelUnusedBody(request.body);
      assertMatchingObject(object, claims);
    } else {
      const uploadDeadline = Math.min(claims.intent_expires_at, admittedAt + claims.upload_max_seconds);
      const remainingMilliseconds = (uploadDeadline - nowSeconds(runtime)) * 1000;
      if (remainingMilliseconds <= 0) throw new Error('upload_deadline');
      const counted = countedBody(request.body, claims.declared_bytes, remainingMilliseconds, runtime);
      try {
        object = await env.ARTIFACTS.put(claims.object_key, counted.stream, {
          onlyIf: { etagDoesNotMatch: '*' },
          customMetadata: metadataFor(claims),
        });
        if (!object) throw new Error('conditional_conflict');
        await counted.done();
      } catch {
        await counted.dispose();
        // R2 may commit the write and lose only its response. Reopen the
        // immutable object and validate it, but do not assume deletion ownership:
        // an indistinguishable concurrent creator may have won the conditional put.
        object = await env.ARTIFACTS.head(claims.object_key);
        if (!object) throw new Error('put_unconfirmed');
        assertMatchingObject(object, claims);
        recoverablePut = true;
      } finally {
        await counted.dispose();
      }
      if (!recoverablePut) created = true;
      if (!recoverablePut && counted.bytes() !== claims.declared_bytes) throw new Error('length_mismatch');
      assertMatchingObject(object, claims);
    }

    const current = await env.ARTIFACTS.head(claims.object_key);
    if (!current || current.version !== object.version || current.etag !== object.etag) throw new Error('object_changed');
    assertMatchingObject(current, claims);
    markerKey = await validationMarkerKey(claims.object_key, current.version);
    let facts = validatedFacts(await env.ARTIFACTS.head(markerKey), current, claims);
    if (!facts) {
      validationLease = await acquireValidationLease(env.ARTIFACTS, current, claims, admittedAt);
      if (!validationLease.acquired) throw new Error('validation_pending');
      facts = await inspectArtifact(env.ARTIFACTS, env.IMAGES, current, claims);
      const inspectedAt = nowSeconds(runtime);
      if (inspectedAt > claims.intent_expires_at || inspectedAt - admittedAt > claims.upload_max_seconds) {
        throw new Error('upload_deadline');
      }
      const inspectedObject = await env.ARTIFACTS.head(claims.object_key);
      if (!inspectedObject || inspectedObject.version !== current.version || inspectedObject.etag !== current.etag) {
        throw new Error('object_changed');
      }
      let marker = await env.ARTIFACTS.put(markerKey, new Uint8Array(0), {
        onlyIf: { etagDoesNotMatch: '*' },
        customMetadata: validationMetadata(current, claims, facts),
      });
      if (!marker) marker = await env.ARTIFACTS.head(markerKey);
      const recorded = validatedFacts(marker, current, claims);
      if (!recorded || !sameFacts(recorded, facts)) throw new Error('validation_conflict');
    }
    if (validationLease && validationLease.acquired) {
      await releaseValidationLease(env.ARTIFACTS, validationLease, nowSeconds(runtime));
      validationLease = null;
    }
    const finishedAt = nowSeconds(runtime);
    if (finishedAt > claims.intent_expires_at || finishedAt - admittedAt > claims.upload_max_seconds) {
      throw new Error('upload_deadline');
    }
    const receipt = await signUploadReceipt({
      intent_id: claims.intent_id,
      batch_id: claims.batch_id,
      upload_id: claims.upload_id,
      ordinal: claims.ordinal,
      object_key: claims.object_key,
      object_version: current.version,
      etag: current.etag,
      bytes: facts.bytes,
      mime: facts.mime,
      width: facts.width,
      height: facts.height,
      policy_fingerprint: claims.policy_fingerprint,
      expires_at: finishedAt + claims.receipt_ttl_seconds,
    }, configuration.activeId, configuration.active, configuration.environment);
    if (!receipt) throw new Error('receipt_failed');
    return jsonResponse({ receipt }, 200, corsHeaders(origin, false));
  } catch {
    if (validationLease && validationLease.acquired) {
      await releaseValidationLease(env.ARTIFACTS, validationLease, nowSeconds(runtime));
    }
    return corsError(created ? 422 : 409, origin);
  }
}

async function health(request, env, runtime) {
  if (forbiddenAuthorityHeader(request.headers, HEALTH_HEADER)) return publicError(403);
  const configuration = keyConfiguration(env);
  const token = request.headers.get(HEALTH_HEADER);
  if (!configuration || !boundedEnvelope(token)) return publicError(503);
  const checkedAt = nowSeconds(runtime);
  const verified = await verifyHealthRequest(token, configuration.keys, configuration.environment, checkedAt);
  if (!verified.ok) return publicError(403);
  let storageReady = false;
  let inspectionReady = false;
  try {
    if (env.ARTIFACTS && typeof env.ARTIFACTS.head === 'function'
      && env.UPLOAD_RATE_LIMITER && typeof env.UPLOAD_RATE_LIMITER.limit === 'function') {
      const limiter = await env.UPLOAD_RATE_LIMITER.limit({ key: `health:${verified.claims.request_id}` });
      if (limiter && limiter.success === true) {
        await env.ARTIFACTS.head('health/eforms-readiness-v1');
        storageReady = true;
      }
    }
  } catch {
    storageReady = false;
  }
  try {
    if (env.IMAGES && typeof env.IMAGES.info === 'function' && typeof env.IMAGES.input === 'function') {
      const info = await env.IMAGES.info(new Response(healthPng()).body);
      const preview = (
        await env.IMAGES.input(new Response(healthPng()).body)
          .transform({ width: 1, height: 1, fit: 'scale-down' })
          .output({ format: 'image/jpeg', quality: REVIEW_PREVIEW_JPEG_QUALITY_INITIAL, anim: false })
      ).response();
      inspectionReady = Boolean(info && Number.isSafeInteger(info.width) && Number.isSafeInteger(info.height)
        && preview instanceof Response && preview.ok && preview.body);
    }
  } catch {
    inspectionReady = false;
  }
  const result = await signHealthResult({
    request_id: verified.claims.request_id,
    storage_ready: storageReady,
    inspection_ready: inspectionReady,
    checked_at: checkedAt,
    expires_at: verified.claims.expires_at,
  }, configuration.activeId, configuration.active, configuration.environment);
  return result ? jsonResponse({ result }, 200) : publicError(503);
}

async function objectOperation(request, env, runtime) {
  if (forbiddenAuthorityHeader(request.headers, OBJECT_HEADER)) return publicError(403);
  const configuration = keyConfiguration(env);
  const token = request.headers.get(OBJECT_HEADER);
  if (!configuration || !boundedEnvelope(token) || !env.ARTIFACTS) return publicError(503);
  const checkedAt = nowSeconds(runtime);
  const verified = await verifyObjectRequest(token, configuration.keys, configuration.environment, checkedAt);
  if (!verified.ok) return publicError(403);
  const claims = verified.claims;
  let status = 'version_mismatch';
  try {
    const current = await env.ARTIFACTS.head(claims.object_key);
    if (!current) {
      if (claims.action === 'delete' && claims.object_version !== '-') {
        await env.ARTIFACTS.delete(await validationMarkerKey(claims.object_key, claims.object_version));
        await env.ARTIFACTS.delete(await validationLeaseKey(claims.object_key, claims.object_version));
      }
      status = 'absent';
    } else if (claims.object_version === '-' || current.version === claims.object_version) {
      if (claims.action === 'inspect') {
        status = 'present';
      } else {
        await env.ARTIFACTS.delete(await validationMarkerKey(claims.object_key, current.version));
        await env.ARTIFACTS.delete(await validationLeaseKey(claims.object_key, current.version));
        await env.ARTIFACTS.delete(claims.object_key);
        status = await env.ARTIFACTS.head(claims.object_key) ? 'version_mismatch' : 'absent';
      }
    }
  } catch {
    return publicError(503);
  }
  const result = await signObjectResult({
    request_id: claims.request_id,
    object_key: claims.object_key,
    object_version: claims.object_version,
    status,
    expires_at: claims.expires_at,
  }, configuration.activeId, configuration.active, configuration.environment);
  return result ? jsonResponse({ result }, 200) : publicError(503);
}

async function review(request, env, runtime) {
  const configuration = keyConfiguration(env);
  const token = new URL(request.url).searchParams.get('grant');
  if (!configuration || !boundedEnvelope(token) || !env.ARTIFACTS) return reviewError(404);
  const checkedAt = nowSeconds(runtime);
  const verified = await verifyReviewGrant(token, configuration.keys, configuration.environment, checkedAt);
  if (!verified.ok || verified.claims.recipe_version !== REVIEW_RECIPE_VERSION) return reviewError(404);
  const claims = verified.claims;
  let current;
  try {
    current = await env.ARTIFACTS.head(claims.object_key);
  } catch {
    return reviewError(503, true);
  }
  if (!current || current.version !== claims.object_version) return reviewError(404);

  if (claims.action === 'download') {
    try {
      const marker = await env.ARTIFACTS.head(await validationMarkerKey(claims.object_key, current.version));
      const facts = validatedReviewFacts(marker, current);
      if (!facts) return reviewError(404);
      const object = await env.ARTIFACTS.get(claims.object_key, { onlyIf: { etagMatches: current.etag } });
      if (!object || !object.body || object.version !== claims.object_version) return reviewError(404);
      const headers = reviewHeaders(facts.mime);
      headers.set('Content-Disposition', `attachment; filename="submitted-image.${extensionForMime(facts.mime)}"`);
      headers.set('Content-Length', String(object.size));
      return new Response(object.body, { status: 200, headers });
    } catch {
      return reviewError(503, true);
    }
  }

  if (!env.IMAGES || typeof env.IMAGES.input !== 'function') return reviewError(503, true);
  const cache = reviewCache(runtime);
  const cacheRequest = cache ? new Request(await reviewCacheKey(claims, request.url)) : null;
  if (cache && cacheRequest) {
    try {
      const cached = await cache.match(cacheRequest);
      const bounded = cached ? await boundedPreviewResponse(cached) : null;
      if (bounded) return privatePreviewResponse(bounded);
    } catch {
      // Cache is optional; authorization and exact-version checks already passed.
    }
  }
  try {
    const object = await env.ARTIFACTS.get(claims.object_key, { onlyIf: { etagMatches: current.etag } });
    if (!object || !object.body || object.version !== claims.object_version) return reviewError(404);
    const transformed = (
      await env.IMAGES.input(object.body)
        .transform({ width: REVIEW_PREVIEW_MAX_EDGE, height: REVIEW_PREVIEW_MAX_EDGE, fit: 'scale-down' })
        .output({ format: 'image/jpeg', quality: REVIEW_PREVIEW_JPEG_QUALITY_INITIAL, anim: false })
    ).response();
    if (!(transformed instanceof Response) || !transformed.ok || !transformed.body) return reviewError(503, true);
    const bounded = await boundedPreviewResponse(transformed);
    if (!bounded) return reviewError(503, true);
    if (cache && cacheRequest) {
      const cacheHeaders = new Headers(bounded.headers);
      cacheHeaders.set('Cache-Control', `public, max-age=${MANAGED_FINALIZED_TTL_SECONDS}`);
      try {
        await cache.put(cacheRequest, new Response(bounded.clone().body, { status: 200, headers: cacheHeaders }));
      } catch {
        // A cache write failure does not make an authorized preview fail.
      }
    }
    return privatePreviewResponse(bounded);
  } catch {
    return reviewError(503, true);
  }
}

async function boundedPreviewResponse(response) {
  const declared = response.headers.get('content-length');
  if (declared && (/^(?:0|[1-9][0-9]*)$/.test(declared) === false || Number(declared) > REVIEW_PREVIEW_MAX_BYTES)) {
    if (response.body) await response.body.cancel().catch(() => {});
    return null;
  }
  if (!response.body) return null;
  const reader = response.body.getReader();
  const chunks = [];
  let bytes = 0;
  try {
    while (true) {
      const result = await reader.read();
      if (result.done) break;
      if (!(result.value instanceof Uint8Array) || bytes > REVIEW_PREVIEW_MAX_BYTES - result.value.byteLength) {
        await reader.cancel().catch(() => {});
        return null;
      }
      chunks.push(result.value);
      bytes += result.value.byteLength;
    }
  } catch {
    await reader.cancel().catch(() => {});
    return null;
  }
  if (bytes < 1) return null;
  const body = new Uint8Array(bytes);
  let offset = 0;
  for (const chunk of chunks) {
    body.set(chunk, offset);
    offset += chunk.byteLength;
  }
  const headers = new Headers(response.headers);
  headers.set('Content-Length', String(bytes));
  return new Response(body, { status: 200, headers });
}

function reviewCache(runtime) {
  if (runtime.cache && typeof runtime.cache.match === 'function' && typeof runtime.cache.put === 'function') {
    return runtime.cache;
  }
  return typeof caches !== 'undefined' && caches.default ? caches.default : null;
}

function boundedEnvelope(value) {
  return typeof value === 'string' && value.length > 0 && value.length <= ENVELOPE_MAX_CHARS;
}

async function reviewCacheKey(claims, requestUrl) {
  const bytes = new TextEncoder().encode(`${claims.object_key}\0${claims.object_version}\0${claims.recipe_version}`);
  const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
  return `${new URL(requestUrl).origin}/.eforms-internal-preview/${hex(digest)}`;
}

function privatePreviewResponse(response) {
  const headers = reviewHeaders('image/jpeg');
  const length = response.headers.get('content-length');
  if (length && /^[1-9][0-9]*$/.test(length)) headers.set('Content-Length', length);
  return new Response(response.body, { status: 200, headers });
}

function reviewHeaders(contentType) {
  return new Headers({
    'Cache-Control': 'private, no-store, max-age=0',
    Pragma: 'no-cache',
    'Content-Type': contentType,
    'X-Robots-Tag': 'noindex, nofollow',
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'no-referrer',
  });
}

function reviewError(status, transient = false) {
  const headers = reviewHeaders('text/plain; charset=UTF-8');
  if (transient) headers.set('Retry-After', '2');
  return new Response('Review unavailable.', { status, headers });
}

function hex(bytes) {
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

function healthPng() {
  return new Uint8Array([
    137, 80, 78, 71, 13, 10, 26, 10,
    0, 0, 0, 13, 73, 72, 68, 82, 0, 0, 0, 1, 0, 0, 0, 1,
    8, 6, 0, 0, 0, 31, 21, 196, 137,
    0, 0, 0, 13, 73, 68, 65, 84, 120, 218, 99, 252, 255, 159, 161, 30, 0, 7, 130, 2, 127, 61, 200, 72, 239,
    0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130,
  ]);
}

function configuredOrigin(env) {
  if (typeof env.EFORMS_SITE_ORIGIN !== 'string') return '';
  try {
    const url = new URL(env.EFORMS_SITE_ORIGIN);
    return url.origin === env.EFORMS_SITE_ORIGIN && /^https?:$/.test(url.protocol) ? url.origin : '';
  } catch {
    return '';
  }
}

function forbiddenAuthorityHeader(headers, allowedHeader) {
  if (headers.has('authorization') || headers.has('cookie')) return true;
  for (const [name] of headers) {
    if (name.toLowerCase().startsWith('x-eforms-') && name.toLowerCase() !== allowedHeader) return true;
  }
  return false;
}

function canonicalLength(value) {
  if (typeof value !== 'string' || !/^[1-9][0-9]*$/.test(value)) return null;
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) && String(parsed) === value ? parsed : null;
}

function metadataFor(claims) {
  return {
    intentId: claims.intent_id,
    batchId: claims.batch_id,
    uploadId: claims.upload_id,
    policyFingerprint: claims.policy_fingerprint,
    declaredMime: claims.declared_mime,
  };
}

function assertMatchingObject(object, claims) {
  const metadata = object.customMetadata || {};
  if (object.key !== claims.object_key || object.size !== claims.declared_bytes
    || typeof object.version !== 'string' || typeof object.etag !== 'string'
    || metadata.intentId !== claims.intent_id || metadata.batchId !== claims.batch_id
    || metadata.uploadId !== claims.upload_id || metadata.policyFingerprint !== claims.policy_fingerprint
    || metadata.declaredMime !== claims.declared_mime) throw new Error('object_conflict');
}

async function validationMarkerKey(objectKey, objectVersion) {
  const digest = new Uint8Array(await crypto.subtle.digest(
    'SHA-256', new TextEncoder().encode(objectVersion),
  ));
  return `${objectKey}.validated-${hex(digest)}`;
}

async function validationLeaseKey(objectKey, objectVersion) {
  const digest = new Uint8Array(await crypto.subtle.digest(
    'SHA-256', new TextEncoder().encode(objectVersion),
  ));
  return `${objectKey}.validating-${hex(digest)}`;
}

async function acquireValidationLease(bucket, object, claims, admittedAt) {
  const key = await validationLeaseKey(claims.object_key, object.version);
  let existing = await bucket.head(key);
  const customMetadata = validationLeaseMetadata(object.version, claims, admittedAt, 'active');
  if (existing) {
    const metadata = existing.customMetadata || {};
    const startedAt = canonicalPositiveInteger(metadata.startedAt);
    if (existing.size !== 0 || metadata.validationVersion !== '1'
      || metadata.artifactVersion !== object.version || metadata.intentId !== claims.intent_id
      || metadata.policyFingerprint !== claims.policy_fingerprint
      || !['active', 'released'].includes(metadata.state) || startedAt < 1) {
      throw new Error('validation_lease_conflict');
    }
    if (metadata.state !== 'released' && admittedAt - startedAt <= claims.upload_max_seconds) {
      return { acquired: false };
    }
    const replacement = await bucket.put(key, new Uint8Array(0), {
      onlyIf: { etagMatches: existing.etag },
      customMetadata,
    });
    return replacement
      ? { acquired: true, key, etag: replacement.etag, metadata: customMetadata }
      : { acquired: false };
  }
  const lease = await bucket.put(key, new Uint8Array(0), {
    onlyIf: { etagDoesNotMatch: '*' },
    customMetadata,
  });
  if (!lease) return { acquired: false };
  return { acquired: true, key, etag: lease.etag, metadata: customMetadata };
}

function validationLeaseMetadata(objectVersion, claims, startedAt, state) {
  return {
    validationVersion: '1',
    artifactVersion: objectVersion,
    intentId: claims.intent_id,
    policyFingerprint: claims.policy_fingerprint,
    startedAt: String(startedAt),
    state,
  };
}

async function releaseValidationLease(bucket, lease, releasedAt) {
  if (!lease || !lease.acquired) return;
  const metadata = { ...lease.metadata, startedAt: String(releasedAt), state: 'released' };
  try {
    await bucket.put(lease.key, new Uint8Array(0), {
      onlyIf: { etagMatches: lease.etag },
      customMetadata: metadata,
    });
  } catch {
    // A failed or stale-owner release leaves the current lease unchanged.
  }
}

function validationMetadata(object, claims, facts) {
  return {
    validationVersion: '1',
    artifactVersion: object.version,
    artifactEtag: object.etag,
    intentId: claims.intent_id,
    policyFingerprint: claims.policy_fingerprint,
    bytes: String(facts.bytes),
    mime: facts.mime,
    width: String(facts.width),
    height: String(facts.height),
  };
}

function validatedFacts(marker, object, claims) {
  const metadata = marker && marker.customMetadata;
  if (!marker || marker.size !== 0 || !metadata || metadata.validationVersion !== '1'
    || metadata.artifactVersion !== object.version || metadata.artifactEtag !== object.etag
    || metadata.intentId !== claims.intent_id || metadata.policyFingerprint !== claims.policy_fingerprint) return null;
  const facts = {
    bytes: canonicalPositiveInteger(metadata.bytes),
    mime: typeof metadata.mime === 'string' ? metadata.mime : '',
    width: canonicalPositiveInteger(metadata.width),
    height: canonicalPositiveInteger(metadata.height),
  };
  if (facts.bytes !== object.size || facts.bytes !== claims.declared_bytes
    || !mimeMatches(facts.mime, claims.declared_mime)
    || facts.width < 1 || facts.height < 1 || Math.max(facts.width, facts.height) > claims.max_edge
    || facts.width > Math.floor(claims.max_pixels / facts.height)) return null;
  return facts;
}

function validatedReviewFacts(marker, object) {
  const metadata = marker && marker.customMetadata;
  if (!marker || marker.size !== 0 || !metadata || metadata.validationVersion !== '1'
    || metadata.artifactVersion !== object.version || metadata.artifactEtag !== object.etag) return null;
  const facts = {
    bytes: canonicalPositiveInteger(metadata.bytes),
    mime: typeof metadata.mime === 'string' ? metadata.mime : '',
    width: canonicalPositiveInteger(metadata.width),
    height: canonicalPositiveInteger(metadata.height),
  };
  return facts.bytes === object.size && supportedMime(facts.mime)
    && facts.width > 0 && facts.height > 0 ? facts : null;
}

function canonicalPositiveInteger(value) {
  if (typeof value !== 'string' || !/^[1-9][0-9]*$/.test(value)) return -1;
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) && String(parsed) === value ? parsed : -1;
}

function sameFacts(left, right) {
  return left.bytes === right.bytes && left.mime === right.mime
    && left.width === right.width && left.height === right.height;
}

function countedBody(body, limit, timeoutMilliseconds, runtime) {
  let total = 0;
  let timeout = null;
  const abortController = new AbortController();
  const schedule = runtime && typeof runtime.setTimeout === 'function' ? runtime.setTimeout : setTimeout;
  const unschedule = runtime && typeof runtime.clearTimeout === 'function' ? runtime.clearTimeout : clearTimeout;
  const clearDeadline = () => {
    if (timeout !== null) {
      unschedule(timeout);
      timeout = null;
    }
  };
  const counter = new TransformStream({
    start(controller) {
      timeout = schedule(() => {
        timeout = null;
        try {
          controller.error(new Error('upload_deadline'));
        } catch {
          // The stream completed while the deadline callback was queued.
        }
      }, timeoutMilliseconds);
    },
    transform(chunk, controller) {
      if (!(chunk instanceof Uint8Array)) throw new Error('invalid_body');
      total += chunk.byteLength;
      if (total > limit) throw new Error('body_too_large');
      controller.enqueue(chunk);
    },
    flush() {
      clearDeadline();
    },
  });
  const inputCompleted = body.pipeTo(counter.writable, { signal: abortController.signal });
  inputCompleted.catch(() => {});
  let stream = counter.readable;
  const pipelines = [inputCompleted];
  if (typeof FixedLengthStream === 'function') {
    const fixed = new FixedLengthStream(limit);
    const fixedCompleted = stream.pipeTo(fixed.writable, { signal: abortController.signal });
    fixedCompleted.catch(() => {});
    pipelines.push(fixedCompleted);
    stream = fixed.readable;
  }
  const completed = Promise.all(pipelines);
  completed.catch(() => {});
  return {
    stream,
    bytes: () => total,
    done: () => completed,
    async dispose() {
      if (!abortController.signal.aborted) abortController.abort();
      clearDeadline();
      await Promise.allSettled(pipelines);
    },
  };
}

function cancelUnusedBody(body) {
  try {
    const cancellation = body.cancel();
    if (cancellation && typeof cancellation.catch === 'function') cancellation.catch(() => {});
  } catch {
    // The retry body is non-authoritative; recovery proceeds from immutable R2 state.
  }
}

function headerSet(value) {
  if (typeof value !== 'string' || value.trim() === '') return [];
  const values = value.split(',').map((entry) => entry.trim().toLowerCase());
  if (values.some((entry) => !/^[a-z0-9-]+$/.test(entry))) return [];
  return [...new Set(values)].sort();
}

function sameSet(left, right) {
  const sortedRight = [...right].sort();
  return left.length === sortedRight.length && left.every((value, index) => value === sortedRight[index]);
}

function corsHeaders(origin, preflight) {
  const headers = new Headers({
    'Access-Control-Allow-Origin': origin,
    Vary: preflight ? 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers' : 'Origin',
  });
  if (preflight) {
    headers.set('Access-Control-Allow-Methods', 'PUT');
    headers.set('Access-Control-Allow-Headers', CORS_ALLOW_HEADERS);
  }
  return headers;
}

function nowSeconds(runtime) {
  return runtime && typeof runtime.now === 'function'
    ? runtime.now() : Math.floor(Date.now() / 1000);
}

function corsError(status, origin, retryAfter = 0) {
  const headers = corsHeaders(origin, false);
  if (retryAfter > 0) headers.set('Retry-After', String(retryAfter));
  return jsonResponse({ error: 'Upload unavailable.' }, status, headers);
}

function publicError(status) {
  return jsonResponse({ error: 'Request unavailable.' }, status);
}

function jsonResponse(body, status, headers = new Headers()) {
  headers.set('Content-Type', 'application/json; charset=utf-8');
  headers.set('Cache-Control', 'no-store');
  return new Response(JSON.stringify(body), { status, headers });
}
