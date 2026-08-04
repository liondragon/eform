import {
  ENVELOPE_MAX_CHARS,
  QUEUE_JOB_VERSION,
  REVIEW_RECIPE_VERSION,
  TERMINAL_RESULT_VERSION,
  WORKER_PROTOCOL_VERSION,
  workerGalleryItemsSha256,
  workerGalleryStatusRequestBodyBytes,
  workerGalleryStatusRequestClaimsMatchItems,
  workerGalleryStatusResultBodyBytes,
  workerGalleryStatusesSha256,
  normalizeWorkerQueueJob,
  normalizeWorkerGalleryItems,
  normalizeWorkerGalleryStatuses,
  keyConfiguration,
  signWorkerGalleryStatusResult,
  signWorkerObjectResult,
  signWorkerStoredReceipt,
  signHealthResult,
  verifyWorkerGalleryStatusRequest,
  verifyWorkerObjectRequest,
  verifyWorkerReviewGrant,
  verifyWorkerUploadGrant,
  verifyHealthRequest,
} from './protocol.js';
import {
  MANAGED_FINALIZED_TTL_SECONDS,
  REVIEW_PREVIEW_JPEG_QUALITY_INITIAL,
  REVIEW_PREVIEW_MAX_BYTES,
  REVIEW_PREVIEW_MAX_EDGE,
  WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES,
  WORKER_UPLOAD_RATE_LIMIT_PERIOD_SECONDS,
} from './anchors.js';
import { MediaInspectionRejection, inspectArtifact } from './media.js';
import { extensionForMime, mimeMatches, supportedMime } from './media-policy.js';
import { createManagedArtifactKey, parseManagedArtifactKey } from './managed-artifact-key.js';
import {
  convergeValidationResult,
  deleteValidationResultReference,
  discardLateValidationResult,
  readValidationResult,
  readValidationResultReference,
} from './validation-result.js';

const UPLOAD_PATH = '/v1/upload';
const HEALTH_PATH = '/v1/health';
const OBJECT_PATH = '/v1/object';
const REVIEW_PATH = '/v1/review';
const GALLERY_STATUS_PATH = '/v1/gallery-status';
const GRANT_HEADER = 'x-eforms-worker-grant';
const HEALTH_HEADER = 'x-eforms-worker-health';
const OBJECT_HEADER = 'x-eforms-worker-object';
const ALLOWED_REQUEST_HEADERS = ['content-type', GRANT_HEADER];
const CORS_ALLOW_HEADERS = 'Content-Type, X-EForms-Worker-Grant';
export default {
  fetch(request, env) {
    return handleRequest(request, env);
  },
  queue(batch, env, ctx) {
    return workerQueueBatch(batch, env, { ctx });
  },
};

export async function handleRequest(request, env, runtime = {}) {
  const url = new URL(request.url);
  if (url.pathname === UPLOAD_PATH && request.method === 'OPTIONS') return preflight(request, env);
  if (url.pathname === UPLOAD_PATH && request.method === 'PUT') return workerUpload(request, env, runtime);
  if (url.pathname === HEALTH_PATH && request.method === 'POST') return health(request, env, runtime);
  if (url.pathname === GALLERY_STATUS_PATH && request.method === 'POST') return workerGalleryStatus(request, env, runtime);
  if (url.pathname === OBJECT_PATH && request.method === 'POST') return workerObjectOperation(request, env, runtime);
  if (url.pathname === REVIEW_PATH && request.method === 'GET') return workerReview(request, env, runtime);
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

export async function workerUpload(request, env, runtime = {}) {
  const body = request.body;
  let bodyDisposition = 'pending';
  let bodyDeadline = null;
  const schedule = runtime && typeof runtime.setTimeout === 'function' ? runtime.setTimeout : setTimeout;
  const unschedule = runtime && typeof runtime.clearTimeout === 'function' ? runtime.clearTimeout : clearTimeout;
  const clearBodyDeadline = () => {
    if (bodyDeadline !== null) {
      unschedule(bodyDeadline);
      bodyDeadline = null;
    }
  };
  const cancelPendingBody = () => {
    if (bodyDisposition !== 'pending') return;
    bodyDisposition = 'cancelled';
    clearBodyDeadline();
    cancelUnusedBody(body);
  };
  const transferBody = () => {
    if (bodyDisposition !== 'pending') return false;
    bodyDisposition = 'transferred';
    clearBodyDeadline();
    return true;
  };
  try {
    const origin = configuredOrigin(env);
    if (!origin || request.headers.get('origin') !== origin || forbiddenAuthorityHeader(request.headers, GRANT_HEADER)) {
      return publicError(403);
    }
    const grantToken = request.headers.get(GRANT_HEADER);
    const contentType = request.headers.get('content-type');
    const contentLength = canonicalLength(request.headers.get('content-length'));
    if (!boundedEnvelope(grantToken) || !contentType || contentLength === null || !body) return corsError(400, origin);

    const configuration = keyConfiguration(env);
    if (!configuration || !env.ARTIFACTS) return corsError(503, origin);
    const admittedAt = nowSeconds(runtime);
    const verified = await verifyWorkerUploadGrant(grantToken, configuration.keys, configuration.environment, admittedAt);
    if (!verified.ok) return corsError(403, origin);
    const claims = verified.claims;
    if (!await workerAuthorityMatchesEnvironment(env, claims)) return corsError(403, origin);
    if (claims.object_key !== await createManagedArtifactKey(
      claims.batch_id, claims.ordinal, claims.intent_id, claims.declared_mime,
    )) return corsError(400, origin);
    if (claims.declared_mime !== contentType || claims.declared_bytes !== contentLength
      || claims.declared_bytes > claims.max_bytes || admittedAt >= claims.upload_until) {
      return corsError(claims.declared_bytes > claims.max_bytes ? 413 : 400, origin);
    }
    bodyDeadline = schedule(() => {
      bodyDeadline = null;
      cancelPendingBody();
    }, Math.max(0, (claims.upload_until * 1000) - nowMilliseconds(runtime)));
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
    let recoveredObject = false;
    try {
      object = await env.ARTIFACTS.head(claims.object_key);
      if (object) {
        assertWorkerMatchingObject(object, claims, configuration.environment);
        cancelPendingBody();
      } else {
        const remainingMilliseconds = (claims.upload_until * 1000) - nowMilliseconds(runtime);
        if (remainingMilliseconds <= 0 || !transferBody()) throw new Error('upload_deadline');
        const counted = countedBody(body, claims.declared_bytes, remainingMilliseconds, runtime);
        try {
          object = await env.ARTIFACTS.put(claims.object_key, counted.stream, {
            onlyIf: { etagDoesNotMatch: '*' },
            customMetadata: workerMetadataFor(claims, configuration.environment),
          });
          if (!object) throw new Error('conditional_conflict');
          await counted.done();
        } catch (error) {
          await counted.dispose();
          if (error && error.message === 'body_too_large') throw error;
          object = await env.ARTIFACTS.head(claims.object_key);
          if (!object) throw new Error('put_unconfirmed');
          assertWorkerMatchingObject(object, claims, configuration.environment);
          recoveredObject = true;
        } finally {
          await counted.dispose();
        }
        if (!recoveredObject && counted.bytes() !== claims.declared_bytes) throw new Error('length_mismatch');
        assertWorkerMatchingObject(object, claims, configuration.environment);
      }

      const current = await env.ARTIFACTS.head(claims.object_key);
      if (!current || current.version !== object.version || current.etag !== object.etag) throw new Error('object_changed');
      assertWorkerMatchingObject(current, claims, configuration.environment);
      const readyAt = nowSeconds(runtime);
      if (readyAt >= claims.upload_until || readyAt >= claims.accept_until || readyAt >= claims.validation_until) {
        return corsError(503, origin);
      }
      const job = await workerQueueJob(claims, current, configuration.environment);
      try {
        await env.VALIDATION_QUEUE.send(job);
      } catch {
        return corsError(503, origin);
      }
      const acceptedAt = nowSeconds(runtime);
      if (acceptedAt >= claims.upload_until || acceptedAt >= claims.accept_until || acceptedAt >= claims.validation_until) {
        return corsError(503, origin);
      }
      const receipt = await signWorkerStoredReceipt({
        intent_id: claims.intent_id,
        batch_id: claims.batch_id,
        upload_id: claims.upload_id,
        ordinal: claims.ordinal,
        storage_identity: claims.storage_identity,
        validation_contract_version: claims.validation_contract_version,
        object_key: claims.object_key,
        object_version: current.version,
        etag: current.etag,
        bytes: current.size,
        policy_fingerprint: claims.policy_fingerprint,
        expires_at: claims.accept_until,
      }, configuration.activeId, configuration.active, configuration.environment);
      if (!receipt) return corsError(503, origin);
      return jsonResponse({ receipt }, 200, corsHeaders(origin, false));
    } catch (error) {
      return corsError(workerUploadErrorStatus(error), origin);
    }
  } finally {
    clearBodyDeadline();
    cancelPendingBody();
  }
}

function workerUploadErrorStatus(error) {
  const message = error && typeof error.message === 'string' ? error.message : '';
  if (message === 'body_too_large') return 413;
  if (message === 'invalid_body') return 400;
  if (['object_conflict', 'object_changed', 'length_mismatch', 'conditional_conflict'].includes(message)) {
    return 409;
  }
  return 503;
}

export async function workerGalleryStatus(request, env, runtime = {}) {
  try {
    if (request.method !== 'POST' || new URL(request.url).pathname !== GALLERY_STATUS_PATH) return publicError(404);
    if (forbiddenAuthorityHeader(request.headers, '')) return publicError(403);
    if (request.headers.get('content-type') !== 'application/json' || !request.body) return publicError(400);
    const declared = request.headers.get('content-length');
    if (declared !== null) {
      const length = canonicalLength(declared);
      if (length === null) return publicError(400);
      if (length > WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES) return publicError(413);
    }

    const read = await boundedJsonRequestBytes(request.body, WORKER_GALLERY_STATUS_REQUEST_MAX_BYTES);
    if (read.status !== 'ok') return publicError(read.status === 'too_large' ? 413 : 400);
    const parsed = parseJsonBytes(read.bytes);
    if (!parsed || !parsed.value || typeof parsed.value !== 'object' || Array.isArray(parsed.value)) return publicError(400);
    if (!sameStringSet(Object.keys(parsed.value), ['items', 'request'])) return publicError(400);
    const token = parsed.value.request;
    if (!boundedEnvelope(token)) return publicError(400);
    const items = parsed.value.items;
    const canonicalRequest = await workerGalleryStatusRequestBodyBytes(token, items);
    if (!canonicalRequest || !equalBytes(read.bytes, canonicalRequest)) return publicError(400);

    const configuration = keyConfiguration(env);
    if (!configuration || !env.ARTIFACTS) return publicError(503);
    const admittedAt = nowSeconds(runtime);
    const verified = await verifyWorkerGalleryStatusRequest(token, configuration.keys, configuration.environment, admittedAt);
    if (!verified.ok) return publicError(403);
    const claims = verified.claims;
    if (!await workerAuthorityMatchesEnvironment(env, claims, false)) return publicError(403);
    if (admittedAt >= claims.expires_at) return publicError(403);
    const normalizedItems = await normalizeWorkerGalleryItems(items);
    if (!normalizedItems || !await workerGalleryStatusRequestClaimsMatchItems(claims, normalizedItems)) {
      return publicError(400);
    }
    if (!normalizedItems.every((item) => item.validation_contract_version === env.EFORMS_VALIDATION_CONTRACT_VERSION)) {
      return publicError(403);
    }

    const readResults = await Promise.all(normalizedItems.map((item) => readValidationResultReference(env.ARTIFACTS, {
      environment_id: configuration.environment,
      storage_identity: claims.storage_identity,
      validation_contract_version: item.validation_contract_version,
      upload_id: item.upload_id,
      ordinal: item.ordinal,
      object_key: item.object_key,
      object_version: item.object_version,
      etag: item.etag,
      bytes: item.bytes,
      policy_fingerprint: item.policy_fingerprint,
      validation_until: item.validation_until,
    })));
    const statuses = [];
    for (const [index, result] of readResults.entries()) {
      const item = normalizedItems[index];
      if (result.status === 'matching') {
        if (result.result.outcome === 'accepted') {
          statuses.push({
            upload_id: item.upload_id,
            status: 'accepted',
            mime: result.result.mime,
            width: result.result.width,
            height: result.result.height,
          });
        } else {
          statuses.push({ upload_id: item.upload_id, status: 'unavailable' });
        }
      } else if (result.status === 'absent') {
        statuses.push({ upload_id: item.upload_id, status: 'absent' });
      } else {
        return publicError(503);
      }
    }

    const checkedAt = nowSeconds(runtime);
    if (checkedAt >= claims.expires_at) return publicError(403);
    const presentedStatuses = statuses.map((status, index) => (
      status.status === 'absent'
        ? {
          upload_id: status.upload_id,
          status: checkedAt < normalizedItems[index].validation_until ? 'pending' : 'unavailable',
        }
        : status
    ));
    const normalizedStatuses = await normalizeWorkerGalleryStatuses(presentedStatuses, normalizedItems);
    if (!normalizedStatuses) return publicError(503);
    const resultClaims = {
      request_id: claims.request_id,
      submission_id: claims.submission_id,
      items_sha256: await workerGalleryItemsSha256(normalizedItems),
      statuses_sha256: await workerGalleryStatusesSha256(normalizedStatuses, normalizedItems),
      item_count: normalizedItems.length,
      checked_at: checkedAt,
      expires_at: claims.expires_at,
    };
    const result = await signWorkerGalleryStatusResult(
      resultClaims,
      configuration.activeId,
      configuration.active,
      configuration.environment,
    );
    const responseBytes = await workerGalleryStatusResultBodyBytes(result, normalizedStatuses, normalizedItems);
    if (!result || !responseBytes) return publicError(503);
    const headers = new Headers({
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
    });
    return new Response(responseBytes, { status: 200, headers });
  } catch {
    return publicError(503);
  }
}

export async function workerReview(request, env, runtime = {}) {
  let objectBody = null;
  let objectBodyDelivered = false;
  try {
    const token = workerReviewGrantToken(request);
    if (!token || forbiddenAuthorityHeader(request.headers, '')) return reviewError(404);
    const configuration = keyConfiguration(env);
    if (!configuration || !boundedEnvelope(token) || !env.ARTIFACTS) return reviewError(404);

    const checkedAt = nowSeconds(runtime);
    const verified = await verifyWorkerReviewGrant(token, configuration.keys, configuration.environment, checkedAt);
    if (!verified.ok || verified.claims.recipe_version !== REVIEW_RECIPE_VERSION) return reviewError(404);
    const claims = verified.claims;
    if (!await workerAuthorityMatchesEnvironment(env, claims)) return reviewError(404);
    if (claims.action === 'preview' && (!env.IMAGES || typeof env.IMAGES.input !== 'function')) {
      return reviewError(503, true);
    }

    const parsedKey = await parseManagedArtifactKey(claims.object_key);
    if (!parsedKey) return reviewError(404);
    const firstResult = await workerAcceptedReviewResult(env.ARTIFACTS, configuration.environment, claims);
    if (!firstResult.ok) return workerReviewResultError(firstResult.status);

    let current;
    try {
      current = await env.ARTIFACTS.head(claims.object_key);
    } catch {
      return reviewError(503, true);
    }
    if (!workerReviewArtifactMatches(current, claims, configuration.environment, parsedKey, firstResult.result)) {
      return reviewError(404);
    }

    let object;
    try {
      object = await env.ARTIFACTS.get(claims.object_key, { onlyIf: { etagMatches: current.etag } });
    } catch {
      return reviewError(503, true);
    }
    if (!object || !object.body
      || !workerReviewArtifactMatches(object, claims, configuration.environment, parsedKey, firstResult.result)) {
      if (object && object.body) cancelUnusedBody(object.body);
      return reviewError(404);
    }
    objectBody = object.body;

    if (claims.action === 'download') {
      const finalResult = await workerAcceptedReviewResult(env.ARTIFACTS, configuration.environment, claims);
      if (!finalResult.ok) {
        cancelUnusedBody(object.body);
        return workerReviewResultError(finalResult.status);
      }
      if (!sameAcceptedWorkerResult(firstResult.result, finalResult.result)) {
        cancelUnusedBody(object.body);
        return reviewError(404);
      }
      if (nowSeconds(runtime) >= claims.expires_at) {
        cancelUnusedBody(object.body);
        return reviewError(404);
      }
      const headers = reviewHeaders(firstResult.result.mime);
      headers.set('Content-Disposition', `attachment; filename="submitted-image.${extensionForMime(firstResult.result.mime)}"`);
      headers.set('Content-Length', String(object.size));
      objectBodyDelivered = true;
      return new Response(object.body, { status: 200, headers });
    }

    let cache;
    let cacheRequest;
    try {
      cache = reviewCache(runtime);
      cacheRequest = cache ? new Request(await reviewCacheKey(claims, request.url)) : null;
    } catch {
      cancelUnusedBody(object.body);
      return reviewError(503, true);
    }
    if (cache && cacheRequest) {
      try {
        const cached = await cache.match(cacheRequest);
        const bounded = cached ? await boundedPreviewResponse(cached) : null;
        if (bounded) {
          cancelUnusedBody(object.body);
          const finalResult = await workerAcceptedReviewResult(env.ARTIFACTS, configuration.environment, claims);
          if (!finalResult.ok) return workerReviewResultError(finalResult.status);
          if (!sameAcceptedWorkerResult(firstResult.result, finalResult.result)) return reviewError(404);
          if (nowSeconds(runtime) >= claims.expires_at) return reviewError(404);
          return privatePreviewResponse(bounded);
        }
      } catch {
        // Cache is optional; grant, accepted result, and exact artifact checks already passed.
      }
    }

    const transformed = (
      await env.IMAGES.input(object.body)
        .transform({ width: REVIEW_PREVIEW_MAX_EDGE, height: REVIEW_PREVIEW_MAX_EDGE, fit: 'scale-down' })
        .output({ format: 'image/jpeg', quality: REVIEW_PREVIEW_JPEG_QUALITY_INITIAL, anim: false })
    ).response();
    if (!(transformed instanceof Response) || !transformed.ok || !transformed.body) {
      cancelResponseBody(transformed);
      return reviewError(503, true);
    }
    const bounded = await boundedPreviewResponse(transformed);
    if (!bounded) {
      cancelResponseBody(transformed);
      return reviewError(503, true);
    }

    const beforeCacheResult = await workerAcceptedReviewResult(env.ARTIFACTS, configuration.environment, claims);
    if (!beforeCacheResult.ok) return workerReviewResultError(beforeCacheResult.status);
    if (!sameAcceptedWorkerResult(firstResult.result, beforeCacheResult.result)) return reviewError(404);
    if (cache && cacheRequest) {
      const cacheHeaders = new Headers(bounded.headers);
      cacheHeaders.set('Cache-Control', `public, max-age=${MANAGED_FINALIZED_TTL_SECONDS}`);
      try {
        await cache.put(cacheRequest, new Response(bounded.clone().body, { status: 200, headers: cacheHeaders }));
      } catch {
        // A cache write failure does not make an authorized preview fail.
      }
    }
    const finalResult = await workerAcceptedReviewResult(env.ARTIFACTS, configuration.environment, claims);
    if (!finalResult.ok) return workerReviewResultError(finalResult.status);
    if (!sameAcceptedWorkerResult(firstResult.result, finalResult.result)) return reviewError(404);
    if (nowSeconds(runtime) >= claims.expires_at) return reviewError(404);
    return privatePreviewResponse(bounded);
  } catch {
    return reviewError(503, true);
  } finally {
    if (objectBody && !objectBodyDelivered) cancelUnusedBody(objectBody);
  }
}

export async function workerObjectOperation(request, env, runtime = {}) {
  try {
    if (request.method !== 'POST' || new URL(request.url).pathname !== OBJECT_PATH) return publicError(404);
    if (forbiddenAuthorityHeader(request.headers, OBJECT_HEADER)) return publicError(403);

    const configuration = keyConfiguration(env);
    if (!configuration || !env.ARTIFACTS) return publicError(503);
    const token = request.headers.get(OBJECT_HEADER);
    if (!boundedEnvelope(token)) return publicError(403);

    const checkedAt = nowSeconds(runtime);
    const verified = await verifyWorkerObjectRequest(token, configuration.keys, configuration.environment, checkedAt);
    if (!verified.ok) return publicError(403);
    const claims = verified.claims;
    if (!await workerAuthorityMatchesEnvironment(env, claims)) return publicError(403);
    const parsedKey = await parseManagedArtifactKey(claims.object_key);
    if (!parsedKey || !workerObjectClaimsMatchKey(claims, parsedKey)) return publicError(403);

    if (claims.action === 'inspect') {
      if (claims.object_version === '-' || claims.etag === '-') return publicError(403);
      let current;
      try {
        current = await env.ARTIFACTS.head(claims.object_key);
      } catch {
        return publicError(503);
      }
      const status = !current
        ? 'absent'
        : (workerObjectArtifactMatches(current, claims, configuration.environment, parsedKey, claims.object_version, claims.etag)
          ? 'present'
          : 'version_mismatch');
      return workerObjectResultResponse(claims, status, configuration, runtime);
    }

    if ((claims.object_version === '-') !== (claims.etag === '-')) return publicError(403);
    const knownVersion = claims.object_version !== '-';
    let current;
    try {
      current = await env.ARTIFACTS.head(claims.object_key);
    } catch {
      return publicError(503);
    }

    let effectiveVersion = claims.object_version;
    let effectiveEtag = claims.etag;
    if (current) {
      const matches = workerObjectArtifactMatches(
        current,
        claims,
        configuration.environment,
        parsedKey,
        knownVersion ? claims.object_version : current.version,
        knownVersion ? claims.etag : current.etag,
      );
      if (!matches) return workerObjectResultResponse(claims, 'version_mismatch', configuration, runtime);
      if (!knownVersion) {
        effectiveVersion = current.version;
        effectiveEtag = current.etag;
      }
    } else if (!knownVersion) {
      return workerObjectResultResponse(claims, 'absent', configuration, runtime);
    }

    const reference = workerObjectResultReference(configuration.environment, claims, effectiveVersion, effectiveEtag);
    const resultDelete = await deleteValidationResultReference(env.ARTIFACTS, reference);
    if (workerObjectResultDeleteRetryable(resultDelete.status)) return publicError(503);
    if (!workerObjectResultDeleteAllowsArtifact(resultDelete.status)) {
      return workerObjectResultResponse(claims, 'version_mismatch', configuration, runtime);
    }

    try {
      current = await env.ARTIFACTS.head(claims.object_key);
      if (current) {
        if (!workerObjectArtifactMatches(
          current,
          claims,
          configuration.environment,
          parsedKey,
          effectiveVersion,
          effectiveEtag,
        )) {
          return workerObjectResultResponse(claims, 'version_mismatch', configuration, runtime);
        }
        await env.ARTIFACTS.delete(claims.object_key);
        current = await env.ARTIFACTS.head(claims.object_key);
        if (current) return publicError(503);
      }
    } catch {
      return publicError(503);
    }

    const finalResult = await readValidationResultReference(env.ARTIFACTS, reference);
    if (finalResult.status !== 'absent') return publicError(503);
    return workerObjectResultResponse(claims, 'absent', configuration, runtime);
  } catch {
    return publicError(503);
  }
}

export async function workerQueueBatch(batch, env, runtime = {}) {
  const messages = batch && Array.isArray(batch.messages) ? batch.messages : [];
  for (const message of messages) {
    await workerQueueMessage(message, env, runtime);
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
  const claims = verified.claims;
  let storageReady = false;
  let inspectionReady = false;
  let limiterReady = false;
  try {
    if (env.UPLOAD_RATE_LIMITER && typeof env.UPLOAD_RATE_LIMITER.limit === 'function') {
      const limiter = await env.UPLOAD_RATE_LIMITER.limit({ key: `health:${claims.request_id}` });
      limiterReady = Boolean(limiter && limiter.success === true);
    }
  } catch {
    limiterReady = false;
  }
  try {
    if (limiterReady && env.ARTIFACTS && typeof env.ARTIFACTS.head === 'function') {
      await env.ARTIFACTS.head('health/eforms-readiness-v1');
      storageReady = true;
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
      cancelResponseBody(preview);
    }
  } catch {
    inspectionReady = false;
  }
  const storageIdentity = await configuredStorageIdentity(env);
  const queueProducerReady = Boolean(env.VALIDATION_QUEUE && typeof env.VALIDATION_QUEUE.send === 'function');
  const validationContractReady = typeof env.EFORMS_VALIDATION_CONTRACT_VERSION === 'string'
    && env.EFORMS_VALIDATION_CONTRACT_VERSION !== ''
    && claims.validation_contract_version === env.EFORMS_VALIDATION_CONTRACT_VERSION;
  const result = await signHealthResult({
    request_id: claims.request_id,
    storage_ready: storageReady,
    inspection_ready: inspectionReady,
    queue_producer_ready: queueProducerReady,
    limiter_ready: limiterReady,
    keys_ready: true,
    storage_identity_ready: storageIdentity !== '' && claims.storage_identity === storageIdentity,
    validation_contract_ready: validationContractReady,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    checked_at: checkedAt,
    expires_at: claims.expires_at,
  }, configuration.activeId, configuration.active, configuration.environment);
  return result ? jsonResponse({ result }, 200) : publicError(503);
}

function workerReviewGrantToken(request) {
  const url = new URL(request.url);
  const entries = [...url.searchParams.entries()];
  if (request.method !== 'GET' || url.pathname !== REVIEW_PATH
    || entries.length !== 1 || entries[0][0] !== 'grant') return '';
  return entries[0][1];
}

function workerReviewReference(environment, claims) {
  return {
    environment_id: environment,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    upload_id: claims.upload_id,
    object_key: claims.object_key,
    object_version: claims.object_version,
    etag: claims.etag,
    bytes: claims.bytes,
    policy_fingerprint: claims.policy_fingerprint,
    validation_until: claims.validation_until,
  };
}

async function workerAcceptedReviewResult(bucket, environment, claims) {
  const result = await readValidationResultReference(bucket, workerReviewReference(environment, claims));
  if (result.status !== 'matching' || result.result.outcome !== 'accepted') {
    return { ok: false, status: result.status };
  }
  return { ok: true, result: result.result };
}

function workerReviewResultError(status) {
  return status === 'read_error' ? reviewError(503, true) : reviewError(404);
}

function workerReviewArtifactMatches(object, claims, environment, parsedKey, result) {
  const metadata = object && object.customMetadata;
  if (!object || !metadata || object.key !== claims.object_key || object.version !== claims.object_version
    || object.etag !== claims.etag || object.size !== claims.bytes || !supportedMime(result.mime)
    || !mimeMatches(result.mime, metadata.declaredMime)) return false;
  return workerMetadataBindingMatches(metadata, claims, environment, parsedKey);
}

function workerObjectClaimsMatchKey(claims, parsedKey) {
  return parsedKey.namespace === claims.batch_id
    && parsedKey.intent_id === claims.intent_id
    && parsedKey.ordinal === claims.ordinal;
}

function workerObjectArtifactMatches(object, claims, environment, parsedKey, objectVersion, etag) {
  const metadata = object && object.customMetadata;
  if (!object || !metadata || object.key !== claims.object_key || object.version !== objectVersion
    || object.etag !== etag || object.size !== claims.bytes) return false;
  return workerMetadataBindingMatches(metadata, claims, environment, parsedKey);
}

function workerObjectResultReference(environment, claims, objectVersion, etag) {
  return {
    environment_id: environment,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    upload_id: claims.upload_id,
    object_key: claims.object_key,
    object_version: objectVersion,
    etag,
    bytes: claims.bytes,
    policy_fingerprint: claims.policy_fingerprint,
  };
}

function workerObjectResultDeleteRetryable(status) {
  return status === 'read_error' || status === 'delete_error' || status === 'delete_uncertain';
}

function workerObjectResultDeleteAllowsArtifact(status) {
  return status === 'absent' || status === 'deleted';
}

async function workerObjectResultResponse(claims, status, configuration, runtime) {
  if (nowSeconds(runtime) >= claims.expires_at) return publicError(403);
  const result = await signWorkerObjectResult({
    request_id: claims.request_id,
    object_key: claims.object_key,
    object_version: claims.object_version,
    status,
    expires_at: claims.expires_at,
  }, configuration.activeId, configuration.active, configuration.environment);
  return result ? jsonResponse({ result }, 200) : publicError(503);
}

function sameAcceptedWorkerResult(left, right) {
  return left.result_version === right.result_version && left.protocol_version === right.protocol_version
    && left.environment_id === right.environment_id && left.storage_identity === right.storage_identity
    && left.validation_contract_version === right.validation_contract_version && left.batch_id === right.batch_id
    && left.intent_id === right.intent_id && left.upload_id === right.upload_id && left.ordinal === right.ordinal
    && left.object_key === right.object_key && left.object_version === right.object_version
    && left.etag === right.etag && left.bytes === right.bytes
    && left.policy_fingerprint === right.policy_fingerprint && left.outcome === 'accepted'
    && right.outcome === 'accepted' && left.validated_at === right.validated_at
    && left.mime === right.mime && left.width === right.width && left.height === right.height;
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
  } finally {
    reader.releaseLock();
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

async function workerAuthorityMatchesEnvironment(env, claims, requireValidationContract = true) {
  const storageIdentity = await configuredStorageIdentity(env);
  return storageIdentity !== ''
    && claims.storage_identity === storageIdentity
    && typeof env.EFORMS_VALIDATION_CONTRACT_VERSION === 'string'
    && env.EFORMS_VALIDATION_CONTRACT_VERSION !== ''
    && (!requireValidationContract
      || claims.validation_contract_version === env.EFORMS_VALIDATION_CONTRACT_VERSION);
}

async function configuredStorageIdentity(env) {
  if (typeof env.EFORMS_WORKER_URL !== 'string' || typeof env.EFORMS_WORKER_ENVIRONMENT_ID !== 'string') return '';
  const origin = canonicalWorkerOrigin(env.EFORMS_WORKER_URL);
  if (origin === '') return '';
  const bytes = new TextEncoder().encode(JSON.stringify(['worker_r2_cloudflare', origin, env.EFORMS_WORKER_ENVIRONMENT_ID]));
  return hex(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)));
}

function canonicalWorkerOrigin(rawOrigin) {
  const rawMatch = rawOrigin.match(/^https:\/\/([a-z0-9.-]+)(?::([1-9][0-9]{0,4}))?(?![\s\S])/);
  if (!rawMatch) return '';
  const host = canonicalOriginHost(rawMatch[1]);
  if (host === '' || host !== rawMatch[1]) return '';
  const port = rawMatch[2] === undefined ? 443 : Number(rawMatch[2]);
  if (!Number.isSafeInteger(port) || port < 1 || port > 65535) return '';
  return `https://${host}${port === 443 ? '' : `:${port}`}`;
}

function canonicalOriginHost(host) {
  if (typeof host !== 'string' || host === '' || host.toLowerCase() !== host) return '';
  if (host.length > 253) return '';
  if (/^\d+(?:\.\d+){3}$/.test(host)) {
    const octets = host.split('.');
    for (const octet of octets) {
      if ((octet.length > 1 && octet.startsWith('0')) || Number(octet) > 255) return '';
    }
    return host;
  }
  if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/.test(host)) return '';
  return host;
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

function workerMetadataFor(claims, environment) {
  return {
    protocolVersion: '3',
    environmentId: environment,
    intentId: claims.intent_id,
    batchId: claims.batch_id,
    uploadId: claims.upload_id,
    ordinal: String(claims.ordinal),
    storageIdentity: claims.storage_identity,
    validationContractVersion: claims.validation_contract_version,
    policyFingerprint: claims.policy_fingerprint,
    declaredMime: claims.declared_mime,
    maxBytes: String(claims.max_bytes),
    maxEdge: String(claims.max_edge),
    maxPixels: String(claims.max_pixels),
    containerEntryLimit: String(claims.container_entry_limit),
  };
}

function workerMetadataBindingMatches(metadata, binding, environment, parsedKey = null) {
  const batchId = parsedKey ? parsedKey.namespace : binding.batch_id;
  const intentId = parsedKey ? parsedKey.intent_id : binding.intent_id;
  const ordinal = parsedKey ? parsedKey.ordinal : binding.ordinal;
  return metadata.protocolVersion === '3'
    && metadata.environmentId === environment
    && metadata.intentId === intentId
    && metadata.batchId === batchId
    && metadata.uploadId === binding.upload_id
    && metadata.ordinal === String(ordinal)
    && metadata.storageIdentity === binding.storage_identity
    && metadata.validationContractVersion === binding.validation_contract_version
    && metadata.policyFingerprint === binding.policy_fingerprint;
}

function assertWorkerMatchingObject(object, claims, environment) {
  const metadata = object.customMetadata || {};
  if (object.key !== claims.object_key || object.size !== claims.declared_bytes
    || typeof object.version !== 'string' || typeof object.etag !== 'string'
    || !workerMetadataBindingMatches(metadata, claims, environment)
    || metadata.declaredMime !== claims.declared_mime
    || metadata.maxBytes !== String(claims.max_bytes) || metadata.maxEdge !== String(claims.max_edge)
    || metadata.maxPixels !== String(claims.max_pixels)
    || metadata.containerEntryLimit !== String(claims.container_entry_limit)) {
    throw new Error('object_conflict');
  }
}

async function workerQueueJob(claims, object, environment) {
  const job = {
    job_version: QUEUE_JOB_VERSION,
    protocol_version: WORKER_PROTOCOL_VERSION,
    environment_id: environment,
    storage_identity: claims.storage_identity,
    validation_contract_version: claims.validation_contract_version,
    batch_id: claims.batch_id,
    intent_id: claims.intent_id,
    upload_id: claims.upload_id,
    ordinal: claims.ordinal,
    object_key: claims.object_key,
    object_version: object.version,
    etag: object.etag,
    bytes: object.size,
    declared_mime: claims.declared_mime,
    policy_fingerprint: claims.policy_fingerprint,
    max_bytes: claims.max_bytes,
    max_edge: claims.max_edge,
    max_pixels: claims.max_pixels,
    container_entry_limit: claims.container_entry_limit,
    validation_until: claims.validation_until,
  };
  const normalized = await normalizeWorkerQueueJob(job);
  if (!normalized) throw new Error('queue_job_invalid');
  return normalized;
}

async function workerQueueMessage(message, env, runtime) {
  const job = await normalizeWorkerQueueJob(message && message.body);
  if (!job) {
    alert(runtime, 'malformed_job');
    ack(message);
    return;
  }
  if (job.environment_id !== env.EFORMS_WORKER_ENVIRONMENT_ID) {
    alert(runtime, 'environment_mismatch');
    ack(message);
    return;
  }
  if (!await workerAuthorityMatchesEnvironment(env, job)) {
    alert(runtime, job.validation_contract_version !== env.EFORMS_VALIDATION_CONTRACT_VERSION
      ? 'validation_contract_mismatch'
      : 'storage_identity_mismatch');
    ack(message);
    return;
  }
  if (!env.ARTIFACTS || !env.IMAGES) {
    await retryUntilDeadline(message, job.validation_until, nowSeconds(runtime), runtime, 'provider_unavailable');
    return;
  }

  const existing = await readValidationResult(env.ARTIFACTS, job);
  if (existing.status === 'matching') {
    ack(message);
    return;
  }
  if (existing.status === 'late') {
    await discardLateValidationResult(env.ARTIFACTS, existing);
    alert(runtime, 'validation_deadline');
    ack(message);
    return;
  }
  if (existing.status === 'foreign' || existing.status === 'invalid' || existing.status === 'read_error') {
    await retryUntilDeadline(message, job.validation_until, nowSeconds(runtime), runtime, `existing_result_${existing.status}`);
    return;
  }
  if (nowSeconds(runtime) >= job.validation_until) {
    alert(runtime, 'validation_deadline');
    ack(message);
    return;
  }

  let object;
  try {
    object = await env.ARTIFACTS.head(job.object_key);
  } catch {
    await retryUntilDeadline(message, job.validation_until, nowSeconds(runtime), runtime, 'artifact_head_error');
    return;
  }
  if (!object) {
    await retryUntilDeadline(message, job.validation_until, nowSeconds(runtime), runtime, 'artifact_missing');
    return;
  }
  if (!queueJobMatchesArtifact(job, object)) {
    alert(runtime, 'artifact_identity_mismatch');
    ack(message);
    return;
  }
  if (nowSeconds(runtime) >= job.validation_until) {
    alert(runtime, 'validation_deadline');
    ack(message);
    return;
  }

  let inspection;
  try {
    const facts = await inspectArtifact(env.ARTIFACTS, env.IMAGES, object, { ...job, declared_bytes: job.bytes });
    inspection = { outcome: 'accepted', facts };
  } catch (error) {
    if (error && error.message === 'object_changed') {
      alert(runtime, 'object_changed');
      ack(message);
      return;
    }
    if (!(error instanceof MediaInspectionRejection)) {
      await retryUntilDeadline(message, job.validation_until, nowSeconds(runtime), runtime, 'inspection_transient');
      return;
    }
    inspection = { outcome: 'rejected', reason: error.reason };
  }

  let finalObject;
  try {
    finalObject = await env.ARTIFACTS.head(job.object_key);
  } catch {
    await retryUntilDeadline(message, job.validation_until, nowSeconds(runtime), runtime, 'artifact_head_error');
    return;
  }
  if (!finalObject || !queueJobMatchesArtifact(job, finalObject)) {
    alert(runtime, 'artifact_identity_mismatch');
    ack(message);
    return;
  }
  const validatedAt = nowSeconds(runtime);
  if (validatedAt >= job.validation_until) {
    alert(runtime, 'validation_deadline');
    ack(message);
    return;
  }
  const terminal = terminalResultFor(job, inspection, validatedAt);
  const convergence = await convergeValidationResult(env.ARTIFACTS, job, terminal);
  if (convergence.status === 'created' || convergence.status === 'matching') {
    ack(message);
    return;
  }
  if (convergence.status === 'contradictory') {
    alert(runtime, 'result_nondeterminism');
    ack(message);
    return;
  }
  if (convergence.status === 'late') {
    alert(runtime, 'validation_deadline');
    ack(message);
    return;
  }
  await retryUntilDeadline(message, job.validation_until, nowSeconds(runtime), runtime, `result_${convergence.status}`);
}

function terminalResultFor(job, inspection, validatedAt) {
  const result = {
    result_version: TERMINAL_RESULT_VERSION,
    protocol_version: WORKER_PROTOCOL_VERSION,
    environment_id: job.environment_id,
    storage_identity: job.storage_identity,
    validation_contract_version: job.validation_contract_version,
    batch_id: job.batch_id,
    intent_id: job.intent_id,
    upload_id: job.upload_id,
    ordinal: job.ordinal,
    object_key: job.object_key,
    object_version: job.object_version,
    etag: job.etag,
    bytes: job.bytes,
    policy_fingerprint: job.policy_fingerprint,
    outcome: inspection.outcome,
    validated_at: validatedAt,
  };
  if (inspection.outcome === 'accepted') {
    return {
      ...result,
      mime: inspection.facts.mime,
      width: inspection.facts.width,
      height: inspection.facts.height,
    };
  }
  return { ...result, reason: inspection.reason };
}

function queueJobMatchesArtifact(job, object) {
  const metadata = object.customMetadata || {};
  return object.key === job.object_key && object.version === job.object_version
    && object.etag === job.etag && object.size === job.bytes
    && workerMetadataBindingMatches(metadata, job, job.environment_id)
    && metadata.declaredMime === job.declared_mime
    && metadata.maxBytes === String(job.max_bytes) && metadata.maxEdge === String(job.max_edge)
    && metadata.maxPixels === String(job.max_pixels)
    && metadata.containerEntryLimit === String(job.container_entry_limit);
}

async function retryUntilDeadline(message, deadline, now, runtime, code) {
  if (now < deadline) {
    retry(message);
  } else {
    alert(runtime, code);
    ack(message);
  }
}

function ack(message) {
  if (message && typeof message.ack === 'function') message.ack();
}

function retry(message) {
  if (message && typeof message.retry === 'function') message.retry();
}

function alert(runtime, code) {
  if (runtime && typeof runtime.alert === 'function') {
    runtime.alert(code);
    return;
  }
  console.warn(`eforms_queue_alert:${code}`);
}

function canonicalPositiveInteger(value) {
  if (typeof value !== 'string' || !/^[1-9][0-9]*$/.test(value)) return -1;
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) && String(parsed) === value ? parsed : -1;
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

async function boundedJsonRequestBytes(body, maxBytes) {
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
        return { status: 'too_large' };
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
  if (bytes < 1) return { status: 'invalid' };
  const output = new Uint8Array(bytes);
  let offset = 0;
  for (const chunk of chunks) {
    output.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return { status: 'ok', bytes: output };
}

function parseJsonBytes(bytes) {
  let decoded;
  try {
    decoded = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch {
    return null;
  }
  try {
    return { value: JSON.parse(decoded) };
  } catch {
    return null;
  }
}

function cancelUnusedBody(body) {
  try {
    const cancellation = body.cancel();
    if (cancellation && typeof cancellation.catch === 'function') cancellation.catch(() => {});
  } catch {
    // The retry body is non-authoritative; recovery proceeds from immutable R2 state.
  }
}

function cancelResponseBody(response) {
  if (response instanceof Response && response.body) cancelUnusedBody(response.body);
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

function sameStringSet(left, right) {
  const sortedLeft = [...left].sort();
  const sortedRight = [...right].sort();
  return sortedLeft.length === sortedRight.length && sortedLeft.every((value, index) => value === sortedRight[index]);
}

function equalBytes(left, right) {
  return left.byteLength === right.byteLength && left.every((value, index) => value === right[index]);
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

function nowMilliseconds(runtime) {
  if (runtime && typeof runtime.nowMilliseconds === 'function') return runtime.nowMilliseconds();
  if (runtime && typeof runtime.now === 'function') return runtime.now() * 1000;
  return Date.now();
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
