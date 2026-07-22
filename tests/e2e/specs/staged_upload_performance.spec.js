const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const protocolPhp = path.resolve(__dirname, '../../../src/FormProtocol.php');
const browserProtocol = JSON.parse(execFileSync('php', ['-r', `require ${JSON.stringify(protocolPhp)}; echo json_encode(FormProtocol::browser_settings());`], { encoding: 'utf8' }));
const uploadAttrs = browserProtocol.upload.dataAttributes;
const fixtureDir = path.resolve(__dirname, '../../fixtures');
const files = [
  { label: 'jpeg', name: 'oriented-camera.jpg', mimeType: 'image/jpeg', buffer: fixture('oriented-landscape.jpg.b64') },
  { label: 'png', name: 'transparent-floor.png', mimeType: 'image/png', buffer: fixture('staged-landscape.png.b64') },
];
const scenarios = [
  ['baseline', process.env.EFORMS_PERF_BASELINE_URL, 'baseline-local-normalized', process.env.EFORMS_PERF_BASELINE_BUILD_ID],
  ['local', process.env.EFORMS_PERF_LOCAL_URL, 'candidate-local-artifact', process.env.EFORMS_PERF_LOCAL_BUILD_ID],
  ['worker', process.env.EFORMS_PERF_WORKER_URL, 'candidate-worker-r2', process.env.EFORMS_PERF_WORKER_BUILD_ID],
];
const objectCensusFactors = {
  'baseline-local-normalized': {
    authoritative_artifacts: 0,
    normalized_masters: 1,
    normalized_previews: 1,
    validation_records: 0,
    validation_leases: 0,
    preview_caches: 0,
    unclassified_objects: 0,
  },
  'candidate-local-artifact': {
    authoritative_artifacts: 1,
    normalized_masters: 0,
    normalized_previews: 0,
    validation_records: 0,
    validation_leases: 0,
    preview_caches: 0,
    unclassified_objects: 0,
  },
  'candidate-worker-r2': {
    authoritative_artifacts: 1,
    normalized_masters: 0,
    normalized_previews: 0,
    validation_records: 1,
    validation_leases: 1,
    preview_caches: 0,
    unclassified_objects: 0,
  },
};
const objectCensusRoles = Object.keys(objectCensusFactors['candidate-local-artifact']).sort();
const workGraphFactors = {
  'baseline-local-normalized': {
    browser_wordpress_intents: 1,
    browser_wordpress_artifact_streams: 1,
    browser_worker_artifact_streams: 0,
    worker_r2_artifact_body_writes: 0,
    worker_images_inspections: 0,
    browser_wordpress_completions: 0,
    browser_wordpress_deletes: 1,
    wordpress_worker_deletes: 0,
    external_work_under_lock: 0,
  },
  'candidate-local-artifact': {
    browser_wordpress_intents: 1,
    browser_wordpress_artifact_streams: 1,
    browser_worker_artifact_streams: 0,
    worker_r2_artifact_body_writes: 0,
    worker_images_inspections: 0,
    browser_wordpress_completions: 0,
    browser_wordpress_deletes: 1,
    wordpress_worker_deletes: 0,
    external_work_under_lock: 0,
  },
  'candidate-worker-r2': {
    browser_wordpress_intents: 1,
    browser_wordpress_artifact_streams: 0,
    browser_worker_artifact_streams: 1,
    worker_r2_artifact_body_writes: 1,
    worker_images_inspections: 1,
    browser_wordpress_completions: 1,
    browser_wordpress_deletes: 1,
    wordpress_worker_deletes: 1,
    external_work_under_lock: 0,
  },
};
const workGraphRoles = Object.keys(workGraphFactors['candidate-local-artifact']).sort();

test('performance request graph retains failed transfer attempts', async ({ page }) => {
  const observed = observeManagedRequests(page, 'https://wordpress.test');
  let attempt = 0;
  await page.route('https://worker.test/', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Performance observer test</title>' }));
  await page.route('https://worker.test/v1/upload', async route => {
    attempt += 1;
    if (attempt === 1) {
      await route.abort('connectionfailed');
      return;
    }
    await route.fulfill({ status: 200, body: '{}' });
  });
  await page.goto('https://worker.test/');
  await page.evaluate(async () => {
    await fetch('https://worker.test/v1/upload', { method: 'PUT', body: 'first' }).catch(() => null);
    await fetch('https://worker.test/v1/upload', { method: 'PUT', body: 'second' });
  });
  await observed.wait();
  const transfers = observed.requests.filter(request => request.route === '/v1/upload');
  expect(transfers).toHaveLength(2);
  expect(transfers.filter(request => request.outcome === 'request_failed')).toHaveLength(1);
  expect(transfers.filter(request => request.outcome === 'response' && request.status === 200)).toHaveLength(1);
  expect(Math.max(0, transfers.length - 1)).toBe(1);
});

test('performance evidence rejects deployment drift between commit and cleanup', () => {
  expect(() => assertDeploymentContinuation('deployment-a', 'deployment-b')).toThrow();
  expect(() => assertDeploymentContinuation('deployment-a', 'deployment-a')).not.toThrow();
});

test('performance evidence rejects a reused workload token', () => {
  const seen = new Set();
  expect(() => assertFreshWorkloadToken('fresh_workload_token_1', seen)).not.toThrow();
  expect(() => assertFreshWorkloadToken('fresh_workload_token_1', seen)).toThrow();
  expect(seen).toEqual(new Set(['fresh_workload_token_1']));
});

test('performance transfer accounting excludes Worker CORS preflights', () => {
  expect(isArtifactTransfer({ method: 'OPTIONS', route: '/v1/upload', content_type: '' })).toBeFalsy();
  expect(isArtifactTransfer({ method: 'PUT', route: '/v1/upload', content_type: 'image/png' })).toBeTruthy();
  expect(isArtifactTransfer({ method: 'POST', route: '/items/opaque/artifact', content_type: 'multipart/form-data; boundary=test' })).toBeTruthy();
});

test('performance evidence requires the exact object roles for each composition', () => {
  for (const [, , composition] of scenarios) {
    expect(() => assertCommittedObjectCensus(scaledObjectCensus(composition, 2), composition, 2)).not.toThrow();
    expect(() => assertWorkGraph(scaledWorkGraph(composition, 2), composition, 2)).not.toThrow();
  }
  const duplicateCandidate = scaledObjectCensus('candidate-worker-r2', 2);
  expect(duplicateCandidate.validation_leases).toBe(2);
  duplicateCandidate.authoritative_artifacts += 1;
  expect(() => assertCommittedObjectCensus(duplicateCandidate, 'candidate-worker-r2', 2)).toThrow();
  const duplicateWork = scaledWorkGraph('candidate-worker-r2', 2);
  duplicateWork.worker_r2_artifact_body_writes += 1;
  expect(() => assertWorkGraph(duplicateWork, 'candidate-worker-r2', 2)).toThrow();
  expect(() => assertDeletedObjectCensus(scaledObjectCensus('candidate-local-artifact', 0))).not.toThrow();
  expect(() => assertCompletionTail({ transfer_completed_at_ms: 1100, manifest_committed_at_ms: 1250, completion_tail_ms: 150 }, 1000, 1300, 200)).not.toThrow();
  expect(() => assertCompletionTail({ transfer_completed_at_ms: 1100, manifest_committed_at_ms: 1401, completion_tail_ms: 301 }, 1000, 1500, 300)).toThrow();
});

test('equivalent staged-upload workload satisfies the controlled five-run gate', async ({ browser }) => {
  const missing = scenarios.flatMap(([name, url, , buildId]) => [
    ...(!url ? [`EFORMS_PERF_${name.toUpperCase()}_URL`] : []),
    ...(!buildId ? [`EFORMS_PERF_${name.toUpperCase()}_BUILD_ID`] : []),
  ]);
  const metricsCommand = process.env.EFORMS_PERF_WP_METRICS_COMMAND;
  const maxCompletionTailMs = Number(process.env.EFORMS_PERF_MAX_COMPLETION_TAIL_MS);
  const profile = process.env.EFORMS_PERF_NETWORK_PROFILE;
  const region = process.env.EFORMS_PERF_REGION;
  const warmCold = process.env.EFORMS_PERF_WARM_COLD;
  const acceptanceMissing = [
    ...missing,
    ...(!metricsCommand ? ['EFORMS_PERF_WP_METRICS_COMMAND'] : []),
    ...(!profile ? ['EFORMS_PERF_NETWORK_PROFILE'] : []),
    ...(!region ? ['EFORMS_PERF_REGION'] : []),
    ...(!warmCold ? ['EFORMS_PERF_WARM_COLD'] : []),
    ...(!Number.isSafeInteger(maxCompletionTailMs) || maxCompletionTailMs < 1 ? ['EFORMS_PERF_MAX_COMPLETION_TAIL_MS'] : []),
  ];
  if (acceptanceMissing.length) {
    const reason = `Performance acceptance requires explicit deployment evidence; missing ${acceptanceMissing.join(', ')}.`;
    if (process.env.npm_lifecycle_event === 'test:performance' || process.env.EFORMS_PERF_REQUIRED === '1') throw new Error(reason);
    test.skip(true, reason);
  }
  expect(new Set(scenarios.map(([, url]) => url)).size).toBe(scenarios.length);
  for (const [, , , buildId] of scenarios) expect(/^[A-Za-z0-9._:-]{1,128}$/.test(buildId)).toBeTruthy();
  expect(scenarios[1][3], 'Local and Worker candidates must use the same source build.').toBe(scenarios[2][3]);
  expect(scenarios[0][3], 'Baseline and candidate build identities must differ.').not.toBe(scenarios[1][3]);

  const runs = Number(process.env.EFORMS_PERF_RUNS || 5);
  const itemCount = Number(process.env.EFORMS_PERF_ITEM_COUNT || 2);
  expect(Number.isInteger(runs) && runs >= 5).toBeTruthy();
  expect(Number.isInteger(itemCount) && itemCount >= 1 && itemCount <= 24).toBeTruthy();
  const network = networkSettings();
  expect(path.isAbsolute(metricsCommand)).toBeTruthy();
  fs.accessSync(metricsCommand, fs.constants.X_OK);
  const observations = [];
  const workloadTokens = new Set();
  test.setTimeout(Math.max(120000, scenarios.length * files.length * runs * 60000));

  for (const [scenario, pageUrl, composition, buildId] of scenarios) {
    for (const file of files) {
      for (let run = 1; run <= runs; run += 1) {
        observations.push(await exercise(browser, scenario, file, itemCount, run, pageUrl, network, metricsCommand, composition, buildId, maxCompletionTailMs, workloadTokens));
      }
    }
  }

  const summary = Object.fromEntries(observations.map(record => `${record.scenario}:${record.fixture}`).filter((key, index, keys) => keys.indexOf(key) === index).map(key => {
    const records = observations.filter(record => `${record.scenario}:${record.fixture}` === key);
    const durations = records.map(record => record.selection_to_commit_ms);
    return [key, {
      median_selection_to_commit_ms: median(durations),
      worst_selection_to_commit_ms: Math.max(...durations),
      worst_completion_tail_ms: Math.max(...records.map(record => record.completion_tail_ms)),
      variance_ratio: spreadRatio(durations),
      transferred_request_bytes: records.reduce((sum, record) => sum + record.transferred_request_bytes, 0),
      wordpress_request_bytes: records.reduce((sum, record) => sum + record.wordpress.request_bytes, 0),
      wordpress_worst_duration_ms: Math.max(...records.map(record => record.wordpress.duration_ms)),
      wordpress_worst_peak_memory_bytes: Math.max(...records.map(record => record.wordpress.peak_memory_bytes)),
      retries: records.reduce((sum, record) => sum + record.retries, 0),
      cleanup_ok: records.every(record => record.cleanup_ok),
    }];
  }));

  for (const file of files) {
    expect(summary[`local:${file.label}`].median_selection_to_commit_ms).toBeLessThanOrEqual(summary[`baseline:${file.label}`].median_selection_to_commit_ms * 1.1);
    expect(summary[`worker:${file.label}`].median_selection_to_commit_ms).toBeLessThanOrEqual(summary[`baseline:${file.label}`].median_selection_to_commit_ms * 1.1);
  }
  for (const key of Object.keys(summary)) {
    expect(summary[key].variance_ratio, `${key} variance exceeded 20%; discard and rerun the contaminated series.`).toBeLessThanOrEqual(0.2);
    expect(summary[key].cleanup_ok).toBeTruthy();
  }
  for (const record of observations) {
    expect(record.successful_artifact_transfers, `${record.scenario}:${record.fixture} must commit exactly one successful artifact transfer per item.`).toBe(itemCount);
  }
  for (const [scenario] of scenarios) {
    const records = observations.filter(record => record.scenario === scenario);
    expect(new Set(records.map(record => record.wordpress.deployment_id)).size, `${scenario} measurements crossed deployment identities.`).toBe(1);
  }
  expect(new Set(scenarios.map(([scenario]) => observations.find(record => record.scenario === scenario).wordpress.deployment_id)).size).toBe(scenarios.length);
  expect(observations.filter(record => record.scenario === 'worker').every(record => record.wordpress_artifact_body_bytes === 0)).toBeTruthy();
  expect(observations.filter(record => record.scenario !== 'baseline').every(record => record.wordpress.full_image_decodes === 0)).toBeTruthy();

  process.stdout.write(`EFORMS_PERFORMANCE_RESULT ${JSON.stringify({
    runs,
    item_count: itemCount,
    profile,
    region,
    warm_cold: warmCold,
    max_completion_tail_ms: maxCompletionTailMs,
    fixture_bytes: Object.fromEntries(files.map(file => [file.label, file.buffer.byteLength])),
    summary,
    observations,
  })}\n`);
});

async function exercise(browser, scenario, file, itemCount, run, pageUrl, network, metricsCommand, composition, buildId, maxCompletionTailMs, workloadTokens) {
  const context = await browser.newContext();
  const page = await context.newPage();
  const session = await context.newCDPSession(page);
  await session.send('Network.enable');
  await session.send('Network.emulateNetworkConditions', network);
  const pageOrigin = new URL(pageUrl).origin;
  const observed = observeManagedRequests(page, pageOrigin);

  try {
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    const form = page.locator('form.eforms-form-upload-test');
    const picker = form.locator(`[${uploadAttrs.picker}="1"]`);
    await expect(picker).toBeEnabled();
    const started = Date.now();
    process.stdout.write(`EFORMS_PERFORMANCE_RUN ${scenario} ${file.label} ${run} START ${started}\n`);
    const selected = Array.from({ length: itemCount }, (_, index) => ({
      name: `${index + 1}-${file.name}`,
      mimeType: file.mimeType,
      buffer: file.buffer,
    }));
    await picker.setInputFiles(selected);
    await expect(form.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(itemCount);
    const selectionToCommit = Date.now() - started;
    await observed.wait();
    const privateBatchId = singlePrivateBatchId(observed.batchIds);
    const committedAt = Date.now();
    const committed = readCommittedWorkload(
      metricsCommand, scenario, file.label, run, started, committedAt,
      privateBatchId, itemCount, composition, buildId, maxCompletionTailMs, workloadTokens,
    );
    await form.locator('.eforms-upload-clear').click();
    await expect(form.locator('[data-eforms-upload-state]')).toHaveCount(0);
    await observed.wait();

    const transfers = observed.requests.filter(isArtifactTransfer);
    const deletes = observed.requests.filter(request => request.method === 'DELETE');
    const finishedAt = Date.now();
    const wordpress = readWordPressMetric(
      metricsCommand, scenario, file.label, run, started, finishedAt,
      privateBatchId, committed.workloadToken, committed.deploymentId, composition, buildId, itemCount,
    );
    process.stdout.write(`EFORMS_PERFORMANCE_RUN ${scenario} ${file.label} ${run} END ${finishedAt}\n`);
    return {
      scenario,
      fixture: file.label,
      run,
      selection_to_commit_ms: selectionToCommit,
      completion_tail_ms: committed.completionTailMs,
      transferred_request_bytes: transfers.reduce((sum, request) => sum + request.request_bytes, 0),
      successful_artifact_transfers: transfers.filter(request => request.outcome === 'response' && request.status >= 200 && request.status < 300).length,
      browser_observed_wordpress_artifact_body_bytes: transfers.filter(request => request.same_origin).reduce((sum, request) => sum + request.request_bytes, 0),
      wordpress_artifact_body_bytes: wordpress.artifact_body_bytes,
      retries: Math.max(0, transfers.length - itemCount),
      delete_responses_accepted: deletes.length === itemCount && deletes.every(request => request.outcome === 'response' && request.status >= 200 && request.status < 300),
      cleanup_ok: wordpress.logical_absent && wordpress.capacity_released && wordpress.provider_absent,
      wordpress,
      request_graph: observed.requests,
    };
  } finally {
    await context.close();
  }
}

function isArtifactTransfer(request) {
  return (request.method === 'PUT' && request.route === '/v1/upload')
    || (request.method === 'POST' && request.content_type.startsWith('multipart/form-data;'));
}

function observeManagedRequests(page, pageOrigin) {
  const requests = [];
  const batchIds = new Set();
  const pending = [];
  const records = new Map();
  page.on('request', request => {
    if (!isManagedRequest(request.url())) return;
    const batchId = privateBatchIdFromUrl(request.url());
    if (batchId) batchIds.add(batchId);
    const body = request.postDataBuffer();
    const headers = request.headers();
    let resolve;
    pending.push(new Promise(done => { resolve = done; }));
    const record = {
      route: routeShape(request.url()),
      method: request.method(),
      same_origin: new URL(request.url()).origin === pageOrigin,
      request_bytes: body ? body.byteLength : 0,
      content_type: headers['content-type'] || '',
      duration_ms: -1,
      status: null,
      outcome: 'pending',
    };
    requests.push(record);
    records.set(request, { record, resolve });
  });
  page.on('response', response => {
    const tracked = records.get(response.request());
    if (tracked) tracked.record.status = response.status();
  });
  page.on('requestfinished', request => finalizeObservedRequest(records, request, 'response'));
  page.on('requestfailed', request => finalizeObservedRequest(records, request, 'request_failed'));
  return {
    requests,
    batchIds,
    wait: () => Promise.all(pending),
  };
}

function finalizeObservedRequest(records, request, outcome) {
  const tracked = records.get(request);
  if (!tracked || tracked.record.outcome !== 'pending') return;
  const timing = request.timing();
  tracked.record.duration_ms = timing.responseEnd >= 0 ? Math.round(timing.responseEnd) : -1;
  tracked.record.outcome = outcome;
  records.delete(request);
  tracked.resolve();
}

function readCommittedWorkload(command, scenario, fixtureLabel, run, startedAt, finishedAt, batchId, itemCount, composition, buildId, maxCompletionTailMs, workloadTokens) {
  const record = readMetricRecord(command, 'committed', scenario, fixtureLabel, run, startedAt, finishedAt, batchId, '');
  assertMetricIdentity(record, scenario, composition, buildId);
  assertFreshWorkloadToken(record.workload_token, workloadTokens);
  for (const field of ['logical_item_count', 'capacity_item_count']) expect(record[field]).toBe(itemCount);
  assertCommittedObjectCensus(record.object_census, composition, itemCount);
  assertCompletionTail(record, startedAt, finishedAt, maxCompletionTailMs);
  return { workloadToken: record.workload_token, deploymentId: record.deployment_id, completionTailMs: record.completion_tail_ms };
}

function assertFreshWorkloadToken(token, seen) {
  expect(typeof token === 'string' && /^[A-Za-z0-9_-]{16,128}$/.test(token)).toBeTruthy();
  expect(seen instanceof Set).toBeTruthy();
  expect(seen.has(token), 'Every committed workload must receive a fresh token within the acceptance run.').toBeFalsy();
  seen.add(token);
}

function readWordPressMetric(command, scenario, fixtureLabel, run, startedAt, finishedAt, batchId, workloadToken, committedDeploymentId, composition, buildId, itemCount) {
  const record = readMetricRecord(command, 'deleted', scenario, fixtureLabel, run, startedAt, finishedAt, batchId, workloadToken);
  assertMetricIdentity(record, scenario, composition, buildId);
  assertDeploymentContinuation(committedDeploymentId, record.deployment_id);
  expect(record.workload_token).toBe(workloadToken);
  for (const field of ['logical_item_count', 'capacity_item_count']) expect(record[field]).toBe(0);
  assertDeletedObjectCensus(record.object_census);
  assertWorkGraph(record.work_graph, composition, itemCount);
  for (const field of ['logical_absent', 'capacity_released', 'provider_absent']) expect(record[field]).toBe(true);
  for (const field of ['request_bytes', 'duration_ms', 'peak_memory_bytes']) {
    expect(Number.isFinite(record[field]) && record[field] >= 0).toBeTruthy();
  }
  for (const field of ['artifact_body_bytes', 'full_image_decodes']) expect(Number.isSafeInteger(record[field]) && record[field] >= 0).toBeTruthy();
  return {
    request_bytes: record.request_bytes,
    duration_ms: record.duration_ms,
    peak_memory_bytes: record.peak_memory_bytes,
    artifact_body_bytes: record.artifact_body_bytes,
    full_image_decodes: record.full_image_decodes,
    deployment_id: record.deployment_id,
    build_id: record.build_id,
    composition: record.composition,
    preparation_enabled: record.preparation_enabled,
    logical_absent: record.logical_absent,
    capacity_released: record.capacity_released,
    provider_absent: record.provider_absent,
    work_graph: record.work_graph,
  };
}

function assertDeploymentContinuation(committedDeploymentId, deletedDeploymentId) {
  expect(deletedDeploymentId, 'Committed and deleted evidence must come from the same deployment.').toBe(committedDeploymentId);
}

function assertCommittedObjectCensus(census, composition, itemCount) {
  expect(objectCensusFactors).toHaveProperty(composition);
  expect(census && typeof census === 'object' && !Array.isArray(census)).toBeTruthy();
  expect(Object.keys(census).sort()).toEqual(objectCensusRoles);
  expect(census).toEqual(scaledObjectCensus(composition, itemCount));
}

function assertDeletedObjectCensus(census) {
  expect(census && typeof census === 'object' && !Array.isArray(census)).toBeTruthy();
  expect(Object.keys(census).sort()).toEqual(objectCensusRoles);
  expect(Object.values(census).every(count => Number.isSafeInteger(count) && count === 0)).toBeTruthy();
}

function scaledObjectCensus(composition, itemCount) {
  return Object.fromEntries(Object.entries(objectCensusFactors[composition]).map(([role, factor]) => [role, factor * itemCount]));
}

function assertWorkGraph(graph, composition, itemCount) {
  expect(workGraphFactors).toHaveProperty(composition);
  expect(graph && typeof graph === 'object' && !Array.isArray(graph)).toBeTruthy();
  expect(Object.keys(graph).sort()).toEqual(workGraphRoles);
  expect(graph).toEqual(scaledWorkGraph(composition, itemCount));
}

function assertCompletionTail(record, startedAt, finishedAt, maxCompletionTailMs) {
  for (const field of ['transfer_completed_at_ms', 'manifest_committed_at_ms', 'completion_tail_ms']) expect(Number.isSafeInteger(record[field])).toBeTruthy();
  expect(record.transfer_completed_at_ms).toBeGreaterThanOrEqual(startedAt);
  expect(record.manifest_committed_at_ms).toBeGreaterThanOrEqual(record.transfer_completed_at_ms);
  expect(record.manifest_committed_at_ms).toBeLessThanOrEqual(finishedAt);
  expect(record.completion_tail_ms).toBe(record.manifest_committed_at_ms - record.transfer_completed_at_ms);
  expect(record.completion_tail_ms).toBeLessThanOrEqual(maxCompletionTailMs);
}

function scaledWorkGraph(composition, itemCount) {
  return Object.fromEntries(Object.entries(workGraphFactors[composition]).map(([edge, factor]) => [edge, factor * itemCount]));
}

function readMetricRecord(command, phase, scenario, fixtureLabel, run, startedAt, finishedAt, batchId, workloadToken) {
  const output = execFileSync(command, [
    phase, scenario, fixtureLabel, String(run), String(startedAt), String(finishedAt), batchId, workloadToken,
  ], {
    encoding: 'utf8',
    timeout: 30000,
    maxBuffer: 65536,
  });
  return JSON.parse(output);
}

function assertMetricIdentity(record, scenario, composition, buildId) {
  expect(record.scenario).toBe(scenario);
  expect(record.composition).toBe(composition);
  expect(record.build_id).toBe(buildId);
  expect(typeof record.deployment_id === 'string' && /^[A-Za-z0-9._:-]{1,128}$/.test(record.deployment_id)).toBeTruthy();
  expect(record.preparation_enabled).toBe(false);
}

function singlePrivateBatchId(batchIds) {
  expect(batchIds.size, 'Each measured workload must bind to exactly one private batch identity.').toBe(1);
  return [...batchIds][0];
}

function privateBatchIdFromUrl(url) {
  const parsed = new URL(url);
  const route = parsed.searchParams.get('rest_route') || parsed.pathname;
  const match = route.match(/\/upload-batches\/([^/]+)/);
  return match ? decodeURIComponent(match[1]) : '';
}

function networkSettings() {
  return {
    offline: false,
    latency: requiredNumber('EFORMS_PERF_LATENCY_MS'),
    downloadThroughput: requiredNumber('EFORMS_PERF_DOWNLOAD_KBPS') * 1024 / 8,
    uploadThroughput: requiredNumber('EFORMS_PERF_UPLOAD_KBPS') * 1024 / 8,
    connectionType: 'cellular4g',
  };
}

function requiredNumber(name) {
  const value = Number(process.env[name]);
  expect(Number.isFinite(value) && value > 0, `${name} must be a positive number.`).toBeTruthy();
  return value;
}

function isManagedRequest(url) {
  const parsed = new URL(url);
  return parsed.pathname.includes('/eforms/upload-batches')
    || (parsed.searchParams.get('rest_route') || '').includes('/eforms/upload-batches')
    || parsed.pathname === '/v1/upload';
}

function routeShape(url) {
  const parsed = new URL(url);
  const route = parsed.searchParams.get('rest_route') || parsed.pathname;
  if (route === '/v1/upload') return route;
  return route.replace(/\/upload-batches\/[^/]+/, '/upload-batches/{batch}').replace(/\/items\/[^/]+/, '/items/{item}');
}

function median(values) {
  const sorted = values.slice().sort((a, b) => a - b);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

function spreadRatio(values) {
  const center = median(values);
  return center === 0 ? Infinity : (Math.max(...values) - Math.min(...values)) / center;
}

function fixture(name) {
  return Buffer.from(fs.readFileSync(path.join(fixtureDir, name), 'utf8').trim(), 'base64');
}
