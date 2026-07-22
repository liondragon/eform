const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const formsScript = path.resolve(__dirname, '../../../assets/forms.js');
const formsCss = path.resolve(__dirname, '../../../assets/forms.css');
const uploadCss = path.resolve(__dirname, '../../../assets/upload.css');
const preparationWorkerScript = path.resolve(__dirname, '../../../assets/client-image-preparer.js');
const protocolPhp = path.resolve(__dirname, '../../../src/FormProtocol.php');
const anchorsPhp = path.resolve(__dirname, '../../../src/Anchors.php');
const browserProtocol = JSON.parse(execFileSync('php', ['-r', `require ${JSON.stringify(protocolPhp)}; echo json_encode(FormProtocol::browser_settings());`], { encoding: 'utf8' }));
const preparationRecipe = JSON.parse(execFileSync('php', ['-r', `require ${JSON.stringify(protocolPhp)}; echo json_encode(FormProtocol::client_preparation_recipe());`], { encoding: 'utf8' }));
const managedArtifactMaxBytes = Number(execFileSync('php', ['-r', `require ${JSON.stringify(anchorsPhp)}; echo Anchors::get('MANAGED_ARTIFACT_MAX_BYTES');`], { encoding: 'utf8' }));
const hiddenNames = browserProtocol.hiddenFields;
const uploadNames = browserProtocol.upload;
const uploadFields = uploadNames.batchFields;
const uploadAttrs = uploadNames.dataAttributes;
const uploadResponse = uploadNames.response;
const batchIdChars = uploadNames.runtime.batchIdChars;
const displayNameMaxChars = uploadNames.runtime.displayNameMaxChars;
const endpoint = 'https://example.test/eforms/upload-batches';
const formsScriptBody = fs.readFileSync(formsScript, 'utf8');
const preparationWorkerScriptBody = fs.readFileSync(preparationWorkerScript, 'utf8');
const tinyPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');

async function addFormStyles(page) {
  await page.addStyleTag({ path: formsCss });
  await page.addStyleTag({ path: uploadCss });
}

function formMarkup(id = 'staged-demo', field = 'photos', credentials = '', pickerId = '', mode = 'hidden') {
  pickerId = pickerId || `${id}-${field}`;
  const token = mode === 'js' ? '' : `token-${id}`;
  const instance = mode === 'js' ? '' : `instance-${id}`;
  const timestamp = mode === 'js' ? '' : '1700000000';
  return `
    <form class="eforms-form eforms-form-${id}" ${browserProtocol.dataAttributes.mode}="${mode}" method="post">
      <input type="hidden" name="${hiddenNames.mode}" value="${mode}">
      <input type="hidden" name="${hiddenNames.token}" value="${token}">
      <input type="hidden" name="${hiddenNames.instance_id}" value="${instance}">
      <input type="hidden" name="${hiddenNames.timestamp}" value="${timestamp}">
      <input type="hidden" name="${hiddenNames.js_ok}" value="">
      ${credentials}
      <label for="${id}-${field}">Choose photos</label>
      <input id="${pickerId}" type="file" multiple required disabled ${uploadAttrs.picker}="1" accept="image/*">
      <div class="eforms-upload" ${uploadAttrs.mount}="1" ${uploadAttrs.pickerId}="${pickerId}" ${uploadAttrs.field}="${field}" ${uploadAttrs.accept}="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" ${uploadAttrs.maxFiles}="24" ${uploadAttrs.maxFileBytes}="${managedArtifactMaxBytes}" ${uploadAttrs.maxTotalBytes}="314572800"></div>
      <button type="submit">Send Estimate Request</button>
    </form>`;
}

function uploadSelector(kind, value = '1') {
  return `[${uploadAttrs[kind]}="${value}"]`;
}

function batchResponse(batchId, extra = {}) {
  return {
    [uploadResponse.batchId]: batchId,
    [uploadResponse.state]: 'open',
    [uploadResponse.acceptUntil]: Math.floor(Date.now() / 1000) + 3600,
    [uploadResponse.deleteAfter]: Math.floor(Date.now() / 1000) + 7200,
    [uploadResponse.items]: [],
    [uploadResponse.intents]: [],
    [uploadResponse.limits]: {
      [uploadResponse.maxFileBytes]: managedArtifactMaxBytes,
      [uploadResponse.maxFiles]: 24,
      [uploadResponse.maxTotalBytes]: 314572800
    },
    ...extra
  };
}

function batchId(character) {
  return character.repeat(batchIdChars);
}

function uploadItemResponse(uploadId, displayName = 'server-photo.png') {
  return {
    [uploadResponse.uploadId]: uploadId,
    [uploadResponse.displayName]: displayName
  };
}

function serverItem(uploadId, ordinal, displayName, bytes) {
  return {
    [uploadResponse.uploadId]: uploadId,
    [uploadResponse.ordinal]: ordinal,
    [uploadResponse.displayName]: displayName,
    [uploadResponse.bytes]: bytes
  };
}

function credentialInputs(field, batchId, secret) {
  return `
    <input type="hidden" name="${uploadFields.root}[${field}][${uploadFields.batch_id}]" value="${batchId}">
    <input type="hidden" name="${uploadFields.root}[${field}][${uploadFields.batch_secret}]" value="${secret}">`;
}

function isAuthorizationRequest(request) {
  return routeKind(request.url()) === 'item'
    && request.method() === 'POST'
    && (request.headers()['content-type'] || '').startsWith('application/x-www-form-urlencoded');
}

function authorizationResponse(extra = {}) {
  return {
    [uploadResponse.authorized]: true,
    [uploadResponse.committed]: false,
    [uploadResponse.transport]: {
      [uploadResponse.transportKind]: uploadNames.localTransport
    },
    ...extra
  };
}

async function boot(page, html, options = {}) {
  if (options.autoAuthorize !== false) {
    await page.route('https://example.test/eforms/upload-batches/**/items/**', async route => {
      if (isAuthorizationRequest(route.request())) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
        return;
      }
      await route.fallback();
    });
  }
  await page.setContent(`<main>${html}</main>`);
  await page.evaluate(({ uploadEndpoint, protocol, clientPreparation }) => {
    window.eformsSettings = { uploadBatchEndpoint: uploadEndpoint, protocol };
    if (clientPreparation !== '__absent__') {
      window.eformsSettings.clientPreparation = clientPreparation;
    }
  }, {
    uploadEndpoint: endpoint,
    protocol: browserProtocol,
    clientPreparation: Object.prototype.hasOwnProperty.call(options, 'clientPreparation') ? options.clientPreparation : '__absent__'
  });
  if (options.beforeScript) {
    await options.beforeScript(page);
  }
  await page.addScriptTag({ path: formsScript });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
}

function imagePayload(name, byte = 1, mimeType = 'image/png') {
  return { name, mimeType, buffer: Buffer.from([137, 80, 78, 71, byte]) };
}

function routeKind(url) {
  const pathname = new URL(url).pathname.replace(/\/+$/, '');
  if (pathname === '/eforms/upload-batches') return 'create';
  if (/\/items\/[^/]+\/preview$/.test(pathname)) return 'preview';
  if (/\/items\/[^/]+$/.test(pathname)) return 'item';
  if (/\/upload-batches\/[^/]+$/.test(pathname)) return 'status';
  return '';
}

test('staged mount uses its exact picker reference when capped IDs lose the field suffix', async ({ page }) => {
  const field = 'p'.repeat(64);
  await boot(page, formMarkup('association-demo', field, '', 'capped-picker-reference'));
  const picker = page.locator('#capped-picker-reference');
  await expect(picker).toBeEnabled();
  await expect(page.locator(uploadSelector('mount'))).toHaveAttribute(uploadAttrs.pickerId, 'capped-picker-reference');
});

test('opportunistic JPEG preparation uses one abortable slot and authorizes only the chosen artifact', async ({ page }) => {
  const declarations = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('P'))) });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      declarations.push(new URLSearchParams(request.postData()));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, declarations[declarations.length - 1].get(uploadNames.displayNameParam))) });
      return;
    }
    await route.fallback();
  });

  await boot(page, formMarkup('preparation-demo'), {
    autoAuthorize: false,
    clientPreparation: { workerUrl: '/fake-client-image-preparer.js', recipe: preparationRecipe },
    beforeScript: async browserPage => browserPage.evaluate(preparedBytes => {
      window.__preparation = { active: 0, maxActive: 0, finishes: [], terminations: 0, revocations: 0 };
      const originalRevoke = URL.revokeObjectURL.bind(URL);
      URL.revokeObjectURL = value => {
        window.__preparation.revocations += 1;
        originalRevoke(value);
      };
      window.Worker = class FakePreparationWorker {
        constructor() {
          this.terminated = false;
          this.running = false;
        }
        postMessage(message) {
          if (message.type === 'probe') {
            queueMicrotask(() => {
              if (!this.terminated) this.onmessage({ data: { type: 'ready', requestId: message.requestId } });
            });
            return;
          }
          if (message.type === 'prepare') {
            this.running = true;
            window.__preparation.active += 1;
            window.__preparation.maxActive = Math.max(window.__preparation.maxActive, window.__preparation.active);
            queueMicrotask(() => {
              if (!this.terminated) this.onmessage({ data: { type: 'preparing', requestId: message.requestId } });
            });
            window.__preparation.finishes.push(() => {
              if (this.terminated) return;
              const blob = new Blob([Uint8Array.from(preparedBytes)], { type: 'image/jpeg' });
              this.onmessage({ data: { type: 'prepared', requestId: message.requestId, blob } });
            });
          }
        }
        terminate() {
          if (this.running) {
            this.running = false;
            window.__preparation.active -= 1;
          }
          if (!this.terminated) window.__preparation.terminations += 1;
          this.terminated = true;
        }
      };
    }, Array.from(tinyPng))
  });

  const form = page.locator('form.eforms-form-preparation-demo');
  const picker = form.locator(uploadSelector('picker'));
  await picker.setInputFiles([
    imagePayload('first.jpg', 1, 'image/jpeg'),
    { name: 'second.jpg', mimeType: 'image/jpeg', buffer: Buffer.alloc(100, 2) },
    imagePayload('bypass.png', 3, 'image/png')
  ]);
  await expect(form.locator('[data-eforms-upload-state="preparing"]')).toHaveCount(1);
  await expect(form.locator('[data-eforms-upload-state="uploaded"]')).toContainText('bypass.png');
  expect(await page.evaluate(() => window.__preparation.maxActive)).toBe(1);
  expect(declarations.map(value => value.get(uploadNames.displayNameParam))).toEqual(['bypass.png']);

  await form.locator('[data-eforms-upload-state="preparing"] .eforms-upload-remove').click();
  await expect(form.locator('[data-eforms-upload-state="preparing"]')).toHaveCount(1);
  expect(await page.evaluate(() => ({ active: window.__preparation.active, max: window.__preparation.maxActive }))).toEqual({ active: 1, max: 1 });
  await page.evaluate(() => {
    window.__preparation.oldImage = document.querySelector('[data-eforms-upload-state="preparing"] img');
    window.__preparation.finishes[1]();
  });
  await expect(form.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(2);

  const jpegDeclaration = declarations.find(value => value.get(uploadNames.displayNameParam) === 'second.jpg');
  expect(jpegDeclaration).toBeTruthy();
  expect(jpegDeclaration.get(uploadNames.bytesParam)).toBe(String(tinyPng.length));
  expect(jpegDeclaration.get(uploadNames.mimeParam)).toBe('image/jpeg');
  expect(declarations.some(value => value.get(uploadNames.displayNameParam) === 'first.jpg')).toBeFalsy();
  await page.evaluate(() => window.__preparation.oldImage.dispatchEvent(new Event('error')));
  const preparedCard = form.locator('.eforms-upload-item').filter({ hasText: 'second.jpg' });
  await expect(preparedCard).not.toHaveAttribute('data-eforms-upload-preview', 'unavailable');
  expect(await preparedCard.locator('img').getAttribute('src')).toMatch(/^blob:/);
  expect(await page.evaluate(() => window.__preparation.revocations)).toBeGreaterThanOrEqual(2);
});

test('Clear all cancels the preparation queue without starting another Worker', async ({ page }) => {
  await boot(page, formMarkup('preparation-clear-all'), {
    clientPreparation: { workerUrl: '/fake-client-image-preparer.js', recipe: preparationRecipe },
    beforeScript: async browserPage => browserPage.evaluate(() => {
      window.__clearPreparation = { constructed: 0, terminated: 0 };
      window.Worker = class HeldPreparationWorker {
        constructor() {
          window.__clearPreparation.constructed += 1;
        }
        postMessage(message) {
          if (message.type === 'probe') {
            queueMicrotask(() => this.onmessage({ data: { type: 'ready', requestId: message.requestId } }));
            return;
          }
          if (message.type === 'prepare') {
            queueMicrotask(() => this.onmessage({ data: { type: 'preparing', requestId: message.requestId } }));
          }
        }
        terminate() {
          window.__clearPreparation.terminated += 1;
        }
      };
    })
  });

  const form = page.locator('form.eforms-form-preparation-clear-all');
  await form.locator(uploadSelector('picker')).setInputFiles([
    imagePayload('first.jpg', 1, 'image/jpeg'),
    imagePayload('second.jpg', 2, 'image/jpeg'),
    imagePayload('third.jpg', 3, 'image/jpeg')
  ]);
  await expect(form.locator('[data-eforms-upload-state="preparing"]')).toHaveCount(1);
  await form.locator('.eforms-upload-clear').click();
  await expect(form.locator('.eforms-upload-item')).toHaveCount(0);
  expect(await page.evaluate(() => window.__clearPreparation)).toEqual({ constructed: 1, terminated: 1 });
});

test('default-off client preparation never constructs a Worker', async ({ page }) => {
  const declarations = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('O'))) });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      declarations.push(new URLSearchParams(request.postData()));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'unchanged.jpg')) });
      return;
    }
    await route.fallback();
  });
  await boot(page, formMarkup('preparation-off'), {
    autoAuthorize: false,
    beforeScript: async browserPage => browserPage.evaluate(() => {
      window.__workersConstructed = 0;
      window.Worker = class UnexpectedWorker {
        constructor() { window.__workersConstructed += 1; }
      };
    })
  });
  await page.locator('form.eforms-form-preparation-off').locator(uploadSelector('picker')).setInputFiles(imagePayload('unchanged.jpg', 9, 'image/jpeg'));
  await expect(page.locator('form.eforms-form-preparation-off [data-eforms-upload-state="uploaded"]')).toHaveCount(1);
  expect(await page.evaluate(() => window.__workersConstructed)).toBe(0);
  expect(declarations[0].get(uploadNames.bytesParam)).toBe('5');
});

test('unavailable Worker fallback preserves selection order under aggregate limits', async ({ page }) => {
  const declarations = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(batchResponse(batchId('U'), {
          [uploadResponse.limits]: {
            [uploadResponse.maxFileBytes]: 5,
            [uploadResponse.maxFiles]: 24,
            [uploadResponse.maxTotalBytes]: 5
          }
        }))
      });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      declarations.push(new URLSearchParams(request.postData()));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      const declaration = declarations[declarations.length - 1];
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, declaration.get(uploadNames.displayNameParam))) });
      return;
    }
    await route.fallback();
  });

  const markup = formMarkup('preparation-unavailable').replace(
    `${uploadAttrs.maxTotalBytes}="314572800"`,
    `${uploadAttrs.maxTotalBytes}="5"`
  );
  await boot(page, markup, {
    autoAuthorize: false,
    clientPreparation: { workerUrl: '/fake-client-image-preparer.js', recipe: preparationRecipe },
    beforeScript: async browserPage => browserPage.evaluate(() => {
      window.Worker = undefined;
    })
  });

  const form = page.locator('form.eforms-form-preparation-unavailable');
  await form.locator(uploadSelector('picker')).setInputFiles([
    imagePayload('first.jpg', 1, 'image/jpeg'),
    imagePayload('second.jpg', 2, 'image/jpeg')
  ]);
  const first = form.locator('.eforms-upload-item').filter({ hasText: 'first.jpg' });
  const second = form.locator('.eforms-upload-item').filter({ hasText: 'second.jpg' });
  await expect(first).toHaveAttribute('data-eforms-upload-state', 'uploaded');
  await expect(second).toHaveAttribute('data-eforms-upload-state', 'failed');
  expect(declarations.map(value => value.get(uploadNames.displayNameParam))).toEqual(['first.jpg']);
});

test('preparation timeout and stale Worker errors fall back once and release the shared slot', async ({ page }) => {
  let authorizations = 0;
  let bodies = 0;
  const declarations = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('F'))) });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      authorizations += 1;
      declarations.push(new URLSearchParams(request.postData()));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      bodies += 1;
      const id = new URL(request.url()).pathname.split('/').pop();
      const declaration = declarations[bodies - 1];
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, declaration.get(uploadNames.displayNameParam))) });
      return;
    }
    await route.fallback();
  });

  await boot(page, formMarkup('preparation-fallback'), {
    autoAuthorize: false,
    clientPreparation: {
      workerUrl: '/fake-client-image-preparer.js',
      recipe: { ...preparationRecipe, timeoutMs: 30 }
    },
    beforeScript: async browserPage => browserPage.evaluate(() => {
      window.__preparationWorkers = 0;
      window.Worker = class FailingPreparationWorker {
        constructor() {
          this.index = window.__preparationWorkers;
          window.__preparationWorkers += 1;
        }
        postMessage(message) {
          if (this.index === 0 || message.type !== 'probe') return;
          queueMicrotask(() => this.onmessage({ data: { type: 'ready', requestId: message.requestId } }));
          if (this.index === 1) {
            queueMicrotask(() => {
              this.onerror(new Error('encode failed'));
              this.onerror(new Error('stale queued error'));
            });
          }
        }
        terminate() {}
      };
    })
  });

  const form = page.locator('form.eforms-form-preparation-fallback');
  await form.locator(uploadSelector('picker')).setInputFiles([
    imagePayload('timeout.jpg', 1, 'image/jpeg'),
    imagePayload('error.jpg', 2, 'image/jpeg')
  ]);
  await expect(form.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(2);
  expect({ authorizations, bodies }).toEqual({ authorizations: 2, bodies: 2 });
  expect(declarations.map(value => value.get(uploadNames.displayNameParam)).sort()).toEqual(['error.jpg', 'timeout.jpg']);
});

test('failed preparation leaves oversized byte or dimension sources failed without authorization', async ({ page }) => {
  let authorizations = 0;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    if (isAuthorizationRequest(request)) {
      authorizations += 1;
    }
    await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
  });
  await boot(page, formMarkup('preparation-oversized'), {
    autoAuthorize: false,
    clientPreparation: { workerUrl: '/fake-client-image-preparer.js', recipe: preparationRecipe },
    beforeScript: async browserPage => browserPage.evaluate(() => {
      window.Worker = class UnavailablePreparationWorker {
        postMessage(message) {
          if (message.type === 'probe') {
            queueMicrotask(() => this.onmessage({ data: { type: 'ready', requestId: message.requestId } }));
            return;
          }
          if (message.type === 'prepare') {
            const type = message.file.size > 5 ? 'use_source' : 'reject_source';
            queueMicrotask(() => this.onmessage({ data: { type, requestId: message.requestId } }));
          }
        }
        terminate() {}
      };
    })
  });

  const form = page.locator('form.eforms-form-preparation-oversized');
  await form.locator(uploadSelector('picker')).setInputFiles([
    {
      name: 'oversized.jpg',
      mimeType: 'image/jpeg',
      buffer: Buffer.alloc(managedArtifactMaxBytes + 1, 1)
    },
    imagePayload('too-wide.jpg', 2, 'image/jpeg')
  ]);
  await expect(form.locator('.eforms-upload-item').filter({ hasText: 'oversized.jpg' })).toContainText('Photo exceeds the allowed size.');
  const tooWide = form.locator('.eforms-upload-item').filter({ hasText: 'too-wide.jpg' });
  await expect(tooWide).toContainText('Photo exceeds the allowed image dimensions.');
  await expect(tooWide.locator('.eforms-upload-retry')).toBeHidden();
  expect(authorizations).toBe(0);
});

test('runtime expiry cancels active and queued preparation and ignores late Worker results', async ({ page }) => {
  const declarations = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(batchResponse(batchId('T'), {
          [uploadResponse.acceptUntil]: Math.floor(Date.now() / 1000) + 1
        }))
      });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      declarations.push(new URLSearchParams(request.postData()));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'trigger.png')) });
      return;
    }
    await route.fallback();
  });

  await boot(page, formMarkup('preparation-expiry'), {
    autoAuthorize: false,
    clientPreparation: { workerUrl: '/fake-client-image-preparer.js', recipe: preparationRecipe },
    beforeScript: async browserPage => browserPage.evaluate(() => {
      window.__expiryPreparation = { constructed: 0, terminated: 0, late: null };
      window.Worker = class HeldPreparationWorker {
        constructor() {
          window.__expiryPreparation.constructed += 1;
        }
        postMessage(message) {
          if (message.type === 'probe') {
            queueMicrotask(() => this.onmessage({ data: { type: 'ready', requestId: message.requestId } }));
            return;
          }
          if (message.type === 'prepare') {
            queueMicrotask(() => this.onmessage({ data: { type: 'preparing', requestId: message.requestId } }));
            window.__expiryPreparation.late = () => this.onmessage({ data: { type: 'use_source', requestId: message.requestId } });
          }
        }
        terminate() {
          window.__expiryPreparation.terminated += 1;
        }
      };
    })
  });

  const form = page.locator('form.eforms-form-preparation-expiry');
  const mount = form.locator(uploadSelector('mount'));
  await form.locator(uploadSelector('picker')).setInputFiles([
    imagePayload('held.jpg', 1, 'image/jpeg'),
    imagePayload('queued.jpg', 2, 'image/jpeg'),
    imagePayload('trigger.png', 3, 'image/png')
  ]);
  const held = form.locator('.eforms-upload-item').filter({ hasText: 'held.jpg' });
  await expect(held).toHaveAttribute('data-eforms-upload-state', 'preparing');
  await expect(mount).toHaveAttribute('data-eforms-upload-expired', '1', { timeout: 3000 });
  expect(await page.evaluate(() => ({
    constructed: window.__expiryPreparation.constructed,
    terminated: window.__expiryPreparation.terminated
  }))).toEqual({ constructed: 1, terminated: 1 });
  await page.evaluate(() => window.__expiryPreparation.late());
  await page.waitForTimeout(50);
  await expect(held).toHaveAttribute('data-eforms-upload-state', 'preparing');
  expect(await page.evaluate(() => window.__expiryPreparation.constructed)).toBe(1);
});

test('real preparation Worker safely bypasses ambiguous EXIF and preserves axis-swapping orientation', async ({ page }) => {
  await page.route('https://example.test/**', async route => {
    const pathname = new URL(route.request().url()).pathname;
    if (pathname === '/assets/client-image-preparer.js') {
      await route.fulfill({ status: 200, contentType: 'application/javascript', body: preparationWorkerScriptBody });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Worker test</title>' });
  });
  await page.goto('https://example.test/worker-test');
  const result = await page.evaluate(async recipe => {
    const canvas = document.createElement('canvas');
    canvas.width = 3000;
    canvas.height = 2000;
    const context = canvas.getContext('2d');
    [['#ff0000', 0, 0], ['#00ff00', 1500, 0], ['#0000ff', 0, 1000], ['#ffff00', 1500, 1000]].forEach(([color, x, y]) => {
      context.fillStyle = color;
      context.fillRect(x, y, 1500, 1000);
    });
    const encoded = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 1));
    const laterNonExifAppOne = Uint8Array.from([0xff, 0xe1, 0x00, 0x05, 0x58, 0x4d, 0x50]);
    const label = pixel => {
      const [red, green, blue] = pixel;
      if (red > 150 && green > 150) return 'yellow';
      if (red > green && red > blue) return 'red';
      if (green > red && green > blue) return 'green';
      return 'blue';
    };
    const squareCanvas = document.createElement('canvas');
    squareCanvas.width = 2000;
    squareCanvas.height = 2000;
    const squareContext = squareCanvas.getContext('2d');
    squareContext.fillStyle = '#ff0000';
    squareContext.fillRect(0, 0, 1000, 2000);
    squareContext.fillStyle = '#00ff00';
    squareContext.fillRect(1000, 0, 1000, 2000);
    const squareEncoded = await new Promise(resolve => squareCanvas.toBlob(resolve, 'image/jpeg', 1));
    const exifSegment = orientation => Uint8Array.from([
      0xff, 0xe1, 0x00, 0x22,
      0x45, 0x78, 0x69, 0x66, 0x00, 0x00,
      0x4d, 0x4d, 0x00, 0x2a, 0x00, 0x00, 0x00, 0x08,
      0x00, 0x01,
      0x01, 0x12, 0x00, 0x03, 0x00, 0x00, 0x00, 0x01, 0x00, orientation, 0x00, 0x00,
      0x00, 0x00, 0x00, 0x00
    ]);
    const locateSof = bytes => {
      for (let index = 2; index + 8 < bytes.length; index += 1) {
        if (bytes[index] === 0xff && (bytes[index + 1] === 0xc0 || bytes[index + 1] === 0xc2)) return index;
      }
      throw new Error('SOF marker missing');
    };
    const injectAfterSof = async (source, segment) => {
      const bytes = new Uint8Array(await source.arrayBuffer());
      const sof = locateSof(bytes);
      const segmentLength = (bytes[sof + 2] << 8) | bytes[sof + 3];
      const insertion = sof + 2 + segmentLength;
      return new Blob([bytes.slice(0, insertion), segment, bytes.slice(insertion)], { type: 'image/jpeg' });
    };
    const withOversizedWidth = async source => {
      const bytes = new Uint8Array(await source.arrayBuffer());
      const sof = locateSof(bytes);
      bytes[sof + 7] = 0x32;
      bytes[sof + 8] = 0xc8;
      return new Blob([bytes], { type: 'image/jpeg' });
    };
    const lateOrientation = await injectAfterSof(encoded, exifSegment(6));
    const oversizedDimensions = await withOversizedWidth(encoded);
    const malformedExif = Uint8Array.from([0xff, 0xe1, 0x00, 0x08, 0x45, 0x78, 0x69, 0x66, 0x00, 0x01]);
    const overlappingIfd = exifSegment(1).slice();
    overlappingIfd[17] = 0x04;
    const truncatedIfd = exifSegment(1).slice(0, -4);
    truncatedIfd[3] = 0x1e;
    const zeroDimensions = await (async source => {
      const bytes = new Uint8Array(await source.arrayBuffer());
      const sof = locateSof(bytes);
      bytes[sof + 7] = 0;
      bytes[sof + 8] = 0;
      return new Blob([bytes], { type: 'image/jpeg' });
    })(encoded);
    const prepare = (name, segments, encodedSource = encoded) => {
      segments = segments || [];
      const source = new Blob([encodedSource.slice(0, 2), ...segments, laterNonExifAppOne, encodedSource.slice(2)], { type: 'image/jpeg' });
      const worker = new Worker('/assets/client-image-preparer.js');
      const requestId = `worker-case-${name}`;
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('Worker timed out')), recipe.timeoutMs);
        worker.onerror = reject;
        worker.onmessage = async event => {
          if (!event.data || event.data.requestId !== requestId) return;
          if (event.data.type === 'ready') {
            worker.postMessage({ type: 'prepare', requestId, file: source, recipe, maxOutputBytes: 18874368 });
            return;
          }
          if (event.data.type !== 'prepared' && event.data.type !== 'use_source' && event.data.type !== 'reject_source') return;
          clearTimeout(timer);
          worker.terminate();
          if (event.data.type !== 'prepared') {
            resolve({ type: event.data.type });
            return;
          }
          const bitmap = await createImageBitmap(event.data.blob);
          const output = document.createElement('canvas');
          output.width = bitmap.width;
          output.height = bitmap.height;
          const outputContext = output.getContext('2d');
          outputContext.drawImage(bitmap, 0, 0);
          const sample = (x, y) => Array.from(outputContext.getImageData(x, y, 1, 1).data);
          const insetX = Math.floor(bitmap.width * 0.2);
          const insetY = Math.floor(bitmap.height * 0.2);
          const colors = [
            label(sample(insetX, insetY)),
            label(sample(bitmap.width - insetX, insetY)),
            label(sample(insetX, bitmap.height - insetY)),
            label(sample(bitmap.width - insetX, bitmap.height - insetY))
          ];
          const prepared = { type: event.data.type, sourceBytes: source.size, bytes: event.data.blob.size, width: bitmap.width, height: bitmap.height, colors };
          bitmap.close();
          resolve(prepared);
        };
        worker.postMessage({ type: 'probe', requestId, recipe });
      });
    };
    return Promise.all([
      prepare('orientation-2', [exifSegment(2)]),
      prepare('orientation-6', [exifSegment(6)]),
      prepare('square-orientation-6', [exifSegment(6)], squareEncoded),
      prepare('conflicting-orientation', [exifSegment(1), exifSegment(6)]),
      prepare('malformed-exif', [malformedExif]),
      prepare('overlapping-ifd', [overlappingIfd]),
      prepare('truncated-ifd', [truncatedIfd]),
      prepare('late-orientation', [], lateOrientation),
      prepare('oversized-dimensions', [], oversizedDimensions),
      prepare('zero-dimensions', [], zeroDimensions)
    ]);
  }, preparationRecipe);
  expect(result.map(item => item.type)).toEqual([
    'use_source', 'prepared', 'use_source', 'use_source', 'use_source', 'use_source', 'use_source',
    'prepared', 'reject_source', 'reject_source'
  ]);
  expect({ width: result[1].width, height: result[1].height, colors: result[1].colors }).toEqual({
    width: 1707,
    height: preparationRecipe.outputMaxEdge,
    colors: ['blue', 'red', 'yellow', 'green']
  });
  expect(result[1].bytes).toBeLessThanOrEqual(Math.floor(result[1].sourceBytes * 0.85));
  expect({ width: result[7].width, height: result[7].height }).toEqual({
    width: 1707,
    height: preparationRecipe.outputMaxEdge
  });
});

test('staged upload prompt keeps a compact vertical hierarchy at desktop and narrow widths', async ({ page }) => {
  await boot(page, formMarkup('upload-prompt-demo'));
  await addFormStyles(page);
  const mount = page.locator(uploadSelector('mount'));
  const prompt = page.locator('.eforms-upload-controls');
  await expect(prompt.locator('.eforms-upload-icon')).toBeVisible();
  await expect(prompt.locator('.eforms-upload-drop-hint')).toBeVisible();
  await expect(prompt.locator('.eforms-upload-choose')).toBeVisible();
  await expect(prompt.locator('.eforms-upload-guidance')).toHaveCount(0);
  await expect(mount.locator('.eforms-upload-formats')).toBeVisible();
  await expect(mount.locator('.eforms-upload-limits')).toBeVisible();

  const desktop = await prompt.evaluate(node => {
    const rect = selector => node.querySelector(selector).getBoundingClientRect();
    const box = node.getBoundingClientRect();
    const icon = rect('.eforms-upload-icon');
    const hint = rect('.eforms-upload-drop-hint');
    const choose = rect('.eforms-upload-choose');
    return {
      height: box.height,
      centered: [icon, hint, choose].every(item => Math.abs((item.left + item.right) / 2 - (box.left + box.right) / 2) < 2),
      ordered: icon.bottom <= hint.top && hint.bottom <= choose.top
    };
  });
  expect(desktop.centered).toBeTruthy();
  expect(desktop.ordered).toBeTruthy();
  expect(desktop.height).toBeLessThan(190);
  await page.setViewportSize({ width: 320, height: 640 });
  expect(await prompt.evaluate(node => ({
    contained: node.scrollWidth <= node.clientWidth,
    pageContained: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
    height: node.getBoundingClientRect().height
  }))).toEqual({ contained: true, pageContained: true, height: expect.any(Number) });
  expect(await prompt.evaluate(node => node.getBoundingClientRect().height)).toBeLessThan(210);
});

test('staged upload stays disabled when renderer upload endpoint settings are missing', async ({ page }) => {
  let uploadRequests = 0;
  await page.route('**/eforms/upload-batches**', route => {
    uploadRequests += 1;
    route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
  });
  await page.setContent(`<main>${formMarkup('missing-endpoint-demo')}</main>`);
  await page.evaluate(({ protocol }) => {
    window.eformsSettings = { protocol };
  }, { protocol: browserProtocol });
  await page.addScriptTag({ path: formsScript });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));

  await expect(page.locator(uploadSelector('picker'))).toBeDisabled();
  await expect(page.locator('.eforms-upload-choose')).toHaveCount(0);
  expect(await page.locator(uploadSelector('mount')).evaluate(node => Boolean(node.__eformsUploadRuntime))).toBeFalsy();
  expect(uploadRequests).toBe(0);
});

test('server effective limits replace rendered hints before authorization or body transfer', async ({ page }) => {
  let itemPosts = 0;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    if (routeKind(request.url()) === 'create') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(batchResponse(batchId('N'), {
          [uploadResponse.limits]: {
            [uploadResponse.maxFileBytes]: 4,
            [uploadResponse.maxFiles]: 24,
            [uploadResponse.maxTotalBytes]: 96
          }
        }))
      });
      return;
    }
    if (routeKind(request.url()) === 'item' && request.method() === 'POST') {
      itemPosts += 1;
    }
    await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'unexpected' }) });
  });

  await boot(page, formMarkup('effective-limit-demo'));
  const form = page.locator('form.eforms-form-effective-limit-demo');
  await form.locator(uploadSelector('picker')).setInputFiles(imagePayload('too-large.png'));
  await expect(form.locator('[data-eforms-upload-state="failed"]')).toContainText('current upload limits');
  expect(itemPosts).toBe(0);
});

test('effective aggregate limits choose deterministically and allow retry after capacity is released', async ({ page }) => {
  let authorizations = 0;
  const declarations = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(batchResponse(batchId('A'), {
          [uploadResponse.limits]: {
            [uploadResponse.maxFileBytes]: 5,
            [uploadResponse.maxFiles]: 24,
            [uploadResponse.maxTotalBytes]: 5
          }
        }))
      });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      authorizations += 1;
      declarations.push(new URLSearchParams(request.postData()));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      const declaration = declarations[declarations.length - 1];
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, declaration.get(uploadNames.displayNameParam))) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    await route.fallback();
  });

  await boot(page, formMarkup('aggregate-limit-demo'), { autoAuthorize: false });
  const form = page.locator('form.eforms-form-aggregate-limit-demo');
  await form.locator(uploadSelector('picker')).setInputFiles([
    imagePayload('first.png', 1),
    imagePayload('second.png', 2)
  ]);
  const first = form.locator('.eforms-upload-item').filter({ hasText: 'first.png' });
  const second = form.locator('.eforms-upload-item').filter({ hasText: 'second.png' });
  await expect(first).toHaveAttribute('data-eforms-upload-state', 'uploaded');
  await expect(second).toHaveAttribute('data-eforms-upload-state', 'failed');
  expect(authorizations).toBe(1);

  await second.locator('.eforms-upload-retry').click();
  await expect(second).toHaveAttribute('data-eforms-upload-state', 'failed');
  await expect(second.locator('.eforms-upload-retry')).toBeVisible();
  expect(authorizations).toBe(1);

  await first.locator('.eforms-upload-remove').click();
  await expect(first).toHaveCount(0);
  await second.locator('.eforms-upload-retry').click();
  await expect(second).toHaveAttribute('data-eforms-upload-state', 'uploaded');
  expect(authorizations).toBe(2);
});

test('a delayed prepared artifact obeys the effective file count and retries after capacity is released', async ({ page }) => {
  let authorizations = 0;
  const declarations = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(batchResponse(batchId('C'), {
          [uploadResponse.limits]: {
            [uploadResponse.maxFileBytes]: 5,
            [uploadResponse.maxFiles]: 1,
            [uploadResponse.maxTotalBytes]: 5
          }
        }))
      });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      authorizations += 1;
      declarations.push(new URLSearchParams(request.postData()));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      const declaration = declarations[declarations.length - 1];
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, declaration.get(uploadNames.displayNameParam))) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    await route.fallback();
  });

  await boot(page, formMarkup('prepared-file-count-demo'), {
    autoAuthorize: false,
    clientPreparation: { workerUrl: '/fake-client-image-preparer.js', recipe: preparationRecipe },
    beforeScript: async browserPage => browserPage.evaluate(() => {
      window.__fileCountPreparation = { finish: null };
      window.Worker = class HeldPreparationWorker {
        postMessage(message) {
          if (message.type === 'probe') {
            queueMicrotask(() => this.onmessage({ data: { type: 'ready', requestId: message.requestId } }));
            return;
          }
          if (message.type === 'prepare') {
            queueMicrotask(() => this.onmessage({ data: { type: 'preparing', requestId: message.requestId } }));
            window.__fileCountPreparation.finish = () => this.onmessage({
              data: { type: 'prepared', requestId: message.requestId, blob: new Blob(['ok'], { type: 'image/jpeg' }) }
            });
          }
        }
        terminate() {}
      };
    })
  });

  const form = page.locator('form.eforms-form-prepared-file-count-demo');
  await form.locator(uploadSelector('picker')).setInputFiles([
    imagePayload('held.jpg', 2, 'image/jpeg'),
    imagePayload('direct.png', 1)
  ]);
  const held = form.locator('.eforms-upload-item').filter({ hasText: 'held.jpg' });
  const direct = form.locator('.eforms-upload-item').filter({ hasText: 'direct.png' });
  await expect(held).toHaveAttribute('data-eforms-upload-state', 'preparing');
  await expect(direct).toHaveAttribute('data-eforms-upload-state', 'uploaded');
  await page.evaluate(() => window.__fileCountPreparation.finish());
  await expect(held).toHaveAttribute('data-eforms-upload-state', 'failed');
  await expect(held).toContainText('Photo exceeds the allowed size.');
  expect(authorizations).toBe(1);

  await direct.locator('.eforms-upload-remove').click();
  await expect(direct).toHaveCount(0);
  await held.locator('.eforms-upload-retry').click();
  await expect(held).toHaveAttribute('data-eforms-upload-state', 'uploaded');
  expect(authorizations).toBe(2);
});

test('fresh reload remints a staged JS form instead of reusing a token whose batch secret was lost', async ({ page }) => {
  const pageUrl = 'https://example.test/staged-js-reload';
  const scriptUrl = 'https://example.test/forms.js';
  const mintUrl = 'https://example.test/eforms/mint';
  const mintNames = browserProtocol.mint.response;
  const creates = [];
  let mintPosts = 0;

  await page.addInitScript(({ issued }) => {
    sessionStorage.setItem('eforms:token:staged-js', JSON.stringify({
      [browserProtocol.mint.response.token]: 'stale-token',
      [browserProtocol.mint.response.instance_id]: 'stale-instance',
      [browserProtocol.mint.response.timestamp]: String(issued),
      [browserProtocol.mint.response.expires]: issued + 3600
    }));
  }, { issued: Math.floor(Date.now() / 1000), browserProtocol });

  await page.route(pageUrl, route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: `<main>${formMarkup('staged-js', 'photos', '', '', 'js')}</main><script>window.eformsSettings=${JSON.stringify({ mintEndpoint: mintUrl, uploadBatchEndpoint: endpoint, protocol: browserProtocol })};</script><script src="${scriptUrl}"></script>`
  }));
  await page.route(scriptUrl, route => route.fulfill({ status: 200, contentType: 'application/javascript', body: formsScriptBody }));
  await page.route(mintUrl, route => {
    mintPosts += 1;
    const issued = Math.floor(Date.now() / 1000);
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        [mintNames.token]: `minted-token-${mintPosts}`,
        [mintNames.instance_id]: `minted-instance-${mintPosts}`,
        [mintNames.timestamp]: issued,
        [mintNames.expires]: issued + 3600
      })
    });
  });
  await page.route('https://example.test/eforms/upload-batches**', route => {
    const request = route.request();
    if (routeKind(request.url()) === 'create') {
      const posted = new URLSearchParams(request.postData() || '');
      creates.push({
        token: posted.get(hiddenNames.token),
        secret: request.headers()[uploadNames.batchSecretHeader.toLowerCase()]
      });
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(batchResponse(batchId(String(creates.length))))
      });
      return;
    }
    if (isAuthorizationRequest(request)) {
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    const id = new URL(request.url()).pathname.split('/').pop();
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'server-photo.png')) });
  });

  await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
  const form = page.locator('form.eforms-form-staged-js');
  const token = form.locator(`input[name="${hiddenNames.token}"]`);
  await expect(token).toHaveValue('minted-token-1');
  expect(await page.evaluate(() => sessionStorage.getItem('eforms:token:staged-js'))).toBeNull();
  await form.locator('input[type="file"]').setInputFiles(imagePayload('first.png'));
  await expect(form.locator('.eforms-upload-item')).toHaveAttribute('data-eforms-upload-state', 'uploaded');

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(token).toHaveValue('minted-token-2');
  await form.locator('input[type="file"]').setInputFiles(imagePayload('replacement.png', 2));
  await expect(form.locator('.eforms-upload-item')).toHaveAttribute('data-eforms-upload-state', 'uploaded');

  expect(mintPosts).toBe(2);
  expect(creates.map(entry => entry.token)).toEqual(['minted-token-1', 'minted-token-2']);
  expect(creates[0].secret).not.toBe(creates[1].secret);
});

test('removing a form while batch creation is pending prevents the multipart upload', async ({ page }) => {
  let releaseCreate;
  let markCreateStarted;
  let itemPosts = 0;
  let createAborted = false;
  const createStarted = new Promise(resolve => { markCreateStarted = resolve; });

  page.on('requestfailed', request => {
    if (routeKind(request.url()) === 'create') createAborted = true;
  });

  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      markCreateStarted();
      await new Promise(resolve => { releaseCreate = resolve; });
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(batchResponse(batchId('T')))
      }).catch(() => {});
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      itemPosts += 1;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
  });

  await boot(page, formMarkup('teardown-pending-create'));
  const mount = page.locator(uploadSelector('mount'));
  await page.locator(uploadSelector('picker')).setInputFiles(imagePayload('removed-form.png'));
  await createStarted;
  await mount.evaluate(node => {
    window.__eformsDestroyedRuntime = node.__eformsUploadRuntime;
    window.__eformsDestroyedItem = node.__eformsUploadRuntime.items[0];
    node.closest('form').remove();
  });
  await expect.poll(() => page.evaluate(() => window.__eformsDestroyedRuntime.destroyed)).toBe(true);
  await expect.poll(() => createAborted).toBe(true);
  expect(await page.evaluate(() => window.__eformsDestroyedRuntime.runtimeRequest)).toBeNull();
  releaseCreate();
  expect(await page.evaluate(() => window.__eformsDestroyedRuntime.createPending)).toBe(false);

  expect(itemPosts).toBe(0);
  expect(await page.evaluate(() => ({
    batchId: window.__eformsDestroyedRuntime.batchId,
    secret: window.__eformsDestroyedRuntime.secret,
    items: window.__eformsDestroyedRuntime.items.length,
    itemState: window.__eformsDestroyedItem.state,
    itemFile: window.__eformsDestroyedItem.file,
    itemCard: window.__eformsDestroyedItem.card
  }))).toEqual({ batchId: '', secret: '', items: 0, itemState: 'removed', itemFile: null, itemCard: null });
});

test('removing an item retires its heavy references and a pending batch create cannot revive it', async ({ page }) => {
  let releaseCreate;
  let markCreateStarted;
  let itemPosts = 0;
  const createStarted = new Promise(resolve => { markCreateStarted = resolve; });

  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      markCreateStarted();
      await new Promise(resolve => { releaseCreate = resolve; });
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(batchResponse(batchId('I')))
      });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      itemPosts += 1;
      const id = new URL(request.url()).pathname.split('/').pop();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'replacement.png')) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('I'))) });
  });

  await boot(page, formMarkup('retire-item-pending-create'));
  const mount = page.locator(uploadSelector('mount'));
  const picker = page.locator(uploadSelector('picker'));
  await picker.setInputFiles(imagePayload('pending.png'));
  await createStarted;
  await mount.evaluate(node => {
    window.__eformsRetiredItems = [node.__eformsUploadRuntime.items[0]];
  });
  await mount.locator('.eforms-upload-remove').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);
  expect(await mount.evaluate(node => node.__eformsUploadRuntime.items.length)).toBe(0);

  releaseCreate();
  await expect.poll(() => mount.evaluate(node => node.__eformsUploadRuntime.batchId)).toBe(batchId('I'));
  expect(itemPosts).toBe(0);

  for (const name of ['replacement-one.png', 'replacement-two.png']) {
    await picker.setInputFiles(imagePayload(name));
    await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
    await mount.evaluate(node => {
      window.__eformsRetiredItems.push(node.__eformsUploadRuntime.items[0]);
    });
    await mount.locator('.eforms-upload-remove').click();
    await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);
    expect(await mount.evaluate(node => node.__eformsUploadRuntime.items.length)).toBe(0);
  }

  expect(itemPosts).toBe(2);
  expect(await page.evaluate(() => window.__eformsRetiredItems.map(item => ({
    state: item.state,
    file: item.file,
    xhr: item.xhr,
    transferController: item.transferController,
    controlRequest: item.controlRequest,
    card: item.card,
    image: item.image,
    nameNode: item.nameNode,
    progressNode: item.progressNode,
    statusNode: item.statusNode,
    actionsNode: item.actionsNode,
    retryButton: item.retryButton,
    removeButton: item.removeButton
  })))).toEqual(Array.from({ length: 3 }, () => ({
    state: 'removed',
    file: null,
    xhr: null,
    transferController: null,
    controlRequest: null,
    card: null,
    image: null,
    nameNode: null,
    progressNode: null,
    statusNode: null,
    actionsNode: null,
    retryButton: null,
    removeButton: null
  })));
});

test('removing a form while rerender restoration is pending aborts the runtime request', async ({ page }) => {
  let releaseRestore;
  let markRestoreStarted;
  let restoreAborted = false;
  const restoreStarted = new Promise(resolve => { markRestoreStarted = resolve; });

  page.on('requestfailed', request => {
    if (routeKind(request.url()) === 'status') restoreAborted = true;
  });
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    if (routeKind(route.request().url()) !== 'status') {
      await route.fulfill({ status: 500, body: '' });
      return;
    }
    markRestoreStarted();
    await new Promise(resolve => { releaseRestore = resolve; });
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(batchResponse(batchId('U')))
    }).catch(() => {});
  });

  const credentials = credentialInputs('photos', batchId('U'), batchId('V'));
  await boot(page, formMarkup('teardown-restore', 'photos', credentials));
  await restoreStarted;
  const mount = page.locator(uploadSelector('mount'));
  await mount.evaluate(node => {
    window.__eformsDestroyedRuntime = node.__eformsUploadRuntime;
    node.closest('form').remove();
  });
  await expect.poll(() => page.evaluate(() => window.__eformsDestroyedRuntime.destroyed)).toBe(true);
  await expect.poll(() => restoreAborted).toBe(true);
  expect(await page.evaluate(() => ({
    request: window.__eformsDestroyedRuntime.runtimeRequest,
    state: window.__eformsDestroyedRuntime.restoreState
  }))).toEqual({ request: null, state: 'terminal' });
  releaseRestore();
});

test('removing a form during status reconciliation aborts the request and cannot revive its timer', async ({ page }) => {
  let releaseStatus;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('R'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      await route.fulfill({ status: 409, contentType: 'application/json', body: '{}' });
      return;
    }
    if (kind === 'status') {
      await new Promise(resolve => {
        releaseStatus = async () => {
          await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('R'))) }).catch(() => {});
          resolve();
        };
      });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'reconcile.png')) });
    }
  });

  await boot(page, formMarkup('teardown-reconciliation'));
  const mount = page.locator(uploadSelector('mount'));
  await page.locator(uploadSelector('picker')).setInputFiles(imagePayload('reconcile.png'));
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
  await mount.locator('.eforms-upload-remove').click();
  await expect.poll(() => typeof releaseStatus).toBe('function');
  await mount.evaluate(node => {
    const runtime = node.__eformsUploadRuntime;
    window.__eformsReconcileRuntime = runtime;
    window.__eformsReconcileItem = runtime.items[0];
    window.__eformsReconcileSignal = runtime.items[0].controlRequest.controller.signal;
    node.closest('form').remove();
  });

  await expect.poll(() => page.evaluate(() => window.__eformsReconcileRuntime.destroyed)).toBe(true);
  expect(await page.evaluate(() => window.__eformsReconcileSignal.aborted)).toBe(true);
  await releaseStatus();
  await expect.poll(() => page.evaluate(() => ({
    timer: window.__eformsReconcileRuntime.expiryTimer,
    items: window.__eformsReconcileRuntime.items.length,
    state: window.__eformsReconcileItem.state,
    controlRequest: window.__eformsReconcileItem.controlRequest
  }))).toEqual({ timer: null, items: 0, state: 'removed', controlRequest: null });
});

test('long filenames preserve their extension and moving a form keeps its uploader alive', async ({ page }) => {
  let multipart = '';
  const astralServerName = '\u{1F4F7}'.repeat(displayNameMaxChars - 4) + '.png';
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    if (routeKind(request.url()) === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('L'))) });
      return;
    }
    const id = new URL(request.url()).pathname.split('/').pop();
    multipart = request.postDataBuffer().toString('latin1');
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, astralServerName)) });
  });

  await boot(page, formMarkup('move-demo'));
  const form = page.locator('form.eforms-form-move-demo');
  const picker = form.locator(uploadSelector('picker'));
  await picker.setInputFiles(imagePayload('x'.repeat(160) + '.png'));
  await expect(form.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
  await expect(form.locator('.eforms-upload-name')).toHaveAttribute('title', astralServerName);
  expect(multipart).toMatch(/filename="x+\.png"/);

  await form.evaluate(node => {
    const destination = document.createElement('section');
    document.body.appendChild(destination);
    destination.appendChild(node);
  });
  await page.waitForTimeout(0);
  await expect(picker).toBeEnabled();
  expect(await form.evaluate(node => node.__eformsUploadState.runtimes[0].destroyed)).toBeFalsy();
  expect(await form.evaluate(node => {
    const runtime = node.__eformsUploadState.runtimes[0];
    return runtime.batchId !== '' && runtime.secret !== '';
  })).toBeTruthy();
});

test('staged queue authorizes first, uses one retry-safe secret, and caps concurrency at three', async ({ page }) => {
  const secrets = [];
  const authorizations = [];
  const itemIds = [];
  const pending = [];
  let active = 0;
  let maxActive = 0;
  let firstCreateLost = true;

  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    const secret = request.headers()[uploadNames.batchSecretHeader.toLowerCase()];
    expect(new URL(request.url()).searchParams.has(uploadFields.batch_secret)).toBeFalsy();
    if (kind === 'create') {
      secrets.push(secret);
      expect(request.postData()).not.toContain(secret);
      if (firstCreateLost) {
        firstCreateLost = false;
        await route.abort('failed');
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('B'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      if (isAuthorizationRequest(request)) {
        const declaration = new URLSearchParams(request.postData());
        authorizations.push({ id, declaration });
        expect(Number(declaration.get(uploadNames.bytesParam))).toBe(5);
        expect(declaration.get(uploadNames.displayNameParam)).toMatch(/\.png$/);
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
        return;
      }
      itemIds.push(id);
      active += 1;
      maxActive = Math.max(maxActive, active);
      await new Promise(resolve => pending.push(async () => {
        active -= 1;
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'server-canonical.png')) });
        resolve();
      }));
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse('', { [uploadResponse.batchId]: undefined })) });
  });

  await boot(page, formMarkup(), { autoAuthorize: false });
  const mount = page.locator(uploadSelector('mount'));
  const picker = page.locator(uploadSelector('picker'));
  await expect(picker).toBeEnabled();
  await expect(picker).not.toHaveAttribute('required', 'required');
  await expect(picker).not.toHaveAttribute('name');
  expect(await mount.evaluate(node => node.__eformsUploadRuntime.acceptedExtensions)).toEqual(['jpg', 'jpeg', 'png', 'webp']);

  await page.evaluate(({ pickerAttr }) => {
    const input = document.querySelector(`[${pickerAttr}="1"]`);
    window.__pickerClicks = 0;
    input.click = () => { window.__pickerClicks += 1; };
  }, { pickerAttr: uploadAttrs.picker });
  await mount.evaluate(node => node.dispatchEvent(new MouseEvent('click', { bubbles: true })));
  expect(await page.evaluate(() => window.__pickerClicks)).toBe(0);
  await mount.locator('.eforms-upload-choose').click();
  expect(await page.evaluate(() => window.__pickerClicks)).toBe(1);

  await picker.setInputFiles(imagePayload('one.png', 1, ''));
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(1);
  await expect(mount.locator('.eforms-upload-count')).toHaveText('1 of 24 photos selected');
  await expect(mount.locator('.eforms-upload-retry')).toBeVisible();
  await mount.locator('.eforms-upload-retry').click();
  await picker.setInputFiles([imagePayload('two.png', 2), imagePayload('three.png', 3), imagePayload('four.png', 4)]);
  await mount.evaluate(node => {
    const transfer = new DataTransfer();
    transfer.items.add(new File([new Uint8Array([137, 80, 78, 71, 5])], 'five.png', { type: 'image/png' }));
    node.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer }));
  });
  await expect.poll(() => pending.length).toBe(3);
  expect(maxActive).toBe(3);
  expect(secrets).toHaveLength(2);
  expect(secrets[0]).toBe(secrets[1]);
  expect(secrets[0]).toHaveLength(batchIdChars);
  expect(secrets[0]).toMatch(/^[A-Za-z0-9_-]+$/);
  expect(authorizations).toHaveLength(3);
  expect(authorizations[0].declaration.get(uploadNames.mimeParam)).toBe('image/png');
  expect(new Set(itemIds).size).toBe(itemIds.length);
  await mount.evaluate(node => {
    node.__eformsUploadRuntime.items.filter(item => item.xhr).forEach(item => {
      item.xhr.upload.onprogress({ lengthComputable: true, loaded: item.bytes, total: item.bytes });
    });
  });
  await expect(mount.locator('[data-eforms-upload-state="verifying"]')).toHaveCount(3);
  await expect(mount.locator('.eforms-upload-progress').first()).toHaveAttribute('aria-valuenow', '100');
  await expect(mount.locator('.eforms-upload-progress').first()).toHaveText('100%');
  await expect(mount.locator('.eforms-upload-status').first()).toHaveText('Finishing upload...');
  await Promise.all(pending.splice(0, 3).map(release => release()));
  await expect.poll(() => pending.length).toBe(2);
  await Promise.all(pending.splice(0).map(release => release()));
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(5);
  expect(authorizations).toHaveLength(5);
  expect(new Set(authorizations.map(entry => entry.id))).toEqual(new Set(itemIds));
  await expect(mount.locator('.eforms-upload-name').first()).toHaveText('server-canonical.png');
  await expect(mount.locator('.eforms-upload-name').first()).toHaveAttribute('title', 'server-canonical.png');
  await expect(mount.locator('.eforms-upload-remove').first()).toHaveAttribute('aria-label', 'Remove server-canonical.png');
  await expect(mount.locator('.eforms-upload-count')).toHaveText('5 of 24 photos selected');
  await expect(mount.locator('.eforms-upload-progress:visible')).toHaveCount(0);
  await expect(page.locator('button[type="submit"]')).toHaveText('Send Estimate Request');
  await picker.setInputFiles(imagePayload('unsupported.gif', 6, 'image/gif'));
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(5);
  await expect(mount.locator('.eforms-upload-field-status')).toContainText('allowed type');
});

test('ambiguous authorization and transfer retain their admission slots through reconciliation', async ({ page }) => {
  const pendingStatuses = [];
  let authorizationRequests = 0;
  let transferRequests = 0;
  let activeStatuses = 0;
  let maxActiveStatuses = 0;

  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('A'))) });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      authorizationRequests += 1;
      const ordinal = Number(new URLSearchParams(request.postData()).get(uploadNames.ordinalParam));
      if (ordinal < 2) {
        await route.fulfill({ status: ordinal === 0 ? 409 : 500, contentType: 'application/json', body: '{}' });
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      transferRequests += 1;
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
      return;
    }
    if (kind === 'status') {
      activeStatuses += 1;
      maxActiveStatuses = Math.max(maxActiveStatuses, activeStatuses);
      await new Promise(resolve => {
        pendingStatuses.push(async () => {
          await route.fulfill({ status: 503, contentType: 'application/json', body: '{}' });
          activeStatuses -= 1;
          resolve();
        });
      });
      return;
    }
    await route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
  });

  await boot(page, formMarkup('ambiguous-admission-demo'), { autoAuthorize: false });
  const form = page.locator('form.eforms-form-ambiguous-admission-demo');
  const mount = form.locator(uploadSelector('mount'));
  await form.locator(uploadSelector('picker')).setInputFiles(
    Array.from({ length: 4 }, (_, index) => imagePayload(`ambiguous-${index}.png`, index + 1))
  );

  await expect.poll(() => pendingStatuses.length).toBe(3);
  await page.waitForTimeout(50);
  expect(authorizationRequests).toBe(3);
  expect(transferRequests).toBe(1);
  expect(activeStatuses).toBe(3);
  expect(maxActiveStatuses).toBe(3);
  expect(await mount.evaluate(node => {
    const runtime = node.__eformsUploadRuntime;
    return runtime.active + runtime.starting;
  })).toBe(3);

  await pendingStatuses[0]();
  await expect.poll(() => authorizationRequests).toBe(4);
  await expect.poll(() => transferRequests).toBe(2);
  await expect.poll(() => pendingStatuses.length).toBe(4);
  expect(activeStatuses).toBe(3);
  expect(maxActiveStatuses).toBe(3);

  await Promise.all(pendingStatuses.slice(1).map(release => release()));
  await expect(mount.locator('[data-eforms-upload-state="failed"]')).toHaveCount(4);
  expect(await mount.evaluate(node => {
    const runtime = node.__eformsUploadRuntime;
    return {
      active: runtime.active,
      starting: runtime.starting,
      retainedSlots: runtime.items.filter(item => item.slotActive || item.starting).length
    };
  })).toEqual({ active: 0, starting: 0, retainedSlots: 0 });
});

test('Worker transport keeps the existing retry and verifying flow while sending bytes without cookies', async ({ page }) => {
  const workerUrl = 'https://media.example.test/v1/upload';
  const grant = 'signed-worker-grant';
  const receipt = 'signed-worker-receipt';
  const intents = [];
  const items = [];
  const authorizationIds = [];
  let workerAttempts = 0;
  let multipartPosts = 0;
  let releaseWorker;
  let releaseCompletion;
  let completionReceived = false;
  let workerRequestFacts = null;

  await page.route(workerUrl, async route => {
    const request = route.request();
    workerAttempts += 1;
    workerRequestFacts = {
      method: request.method(),
      grant: request.headers()[uploadNames.workerGrantHeader.toLowerCase()],
      cookie: request.headers().cookie,
      contentType: request.headers()['content-type'],
      body: request.postDataBuffer()
    };
    if (workerAttempts === 1) {
      await route.abort('failed');
      return;
    }
    await new Promise(resolve => {
      releaseWorker = async () => {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ receipt }) });
        resolve();
      };
    });
  });

  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('W'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      const body = new URLSearchParams(request.postData() || '');
      if (body.has(uploadNames.receiptParam)) {
        completionReceived = true;
        expect(body.get(uploadNames.receiptParam)).toBe(receipt);
        await new Promise(resolve => {
          releaseCompletion = async () => {
            items.splice(0, items.length, serverItem(id, 0, 'worker-canonical.png', 5));
            intents.splice(0, intents.length);
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'worker-canonical.png')) });
            resolve();
          };
        });
        return;
      }
      if (isAuthorizationRequest(request)) {
        authorizationIds.push(id);
        intents.splice(0, intents.length, serverItem(id, 0, 'worker.png', 5));
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(authorizationResponse({
            [uploadResponse.transport]: {
              [uploadResponse.transportKind]: uploadNames.workerTransport,
              [uploadResponse.transportUrl]: workerUrl,
              [uploadResponse.transportGrant]: grant,
              [uploadResponse.transportMime]: 'image/png'
            }
          }))
        });
        return;
      }
      multipartPosts += 1;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(batchResponse(batchId('W'), {
        [uploadResponse.items]: items,
        [uploadResponse.intents]: intents
      }))
    });
  });

  await boot(page, formMarkup('worker-demo'), { autoAuthorize: false });
  const mount = page.locator(uploadSelector('mount'));
  await page.locator(uploadSelector('picker')).setInputFiles(imagePayload('worker.png'));
  await expect(mount.locator('[data-eforms-upload-state="failed"]')).toHaveCount(1);
  await mount.locator('.eforms-upload-retry').click();
  await expect.poll(() => typeof releaseWorker).toBe('function');
  await mount.evaluate(node => {
    const item = node.__eformsUploadRuntime.items[0];
    item.xhr.upload.onprogress({ lengthComputable: true, loaded: item.bytes, total: item.bytes });
  });
  await expect(mount.locator('[data-eforms-upload-state="verifying"]')).toHaveCount(1);
  await releaseWorker();
  await expect.poll(() => completionReceived).toBe(true);
  await expect(mount.locator('.eforms-upload-status')).toHaveText('Finishing upload...');
  await releaseCompletion();
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
  await expect(mount.locator('.eforms-upload-name')).toHaveText('worker-canonical.png');

  expect(workerAttempts).toBe(2);
  expect(new Set(authorizationIds).size).toBe(1);
  expect(multipartPosts).toBe(0);
  expect(workerRequestFacts.method).toBe('PUT');
  expect(workerRequestFacts.grant).toBe(grant);
  expect(workerRequestFacts.cookie).toBeUndefined();
  expect(workerRequestFacts.contentType).toBe('image/png');
  expect(workerRequestFacts.body).toEqual(Buffer.from([137, 80, 78, 71, 1]));
});

test('Worker transport rejects a same-origin destination before sending photo bytes', async ({ page }) => {
  const workerUrl = 'https://example.test/worker-upload';
  const pageUrl = 'https://example.test/same-origin-worker-test';
  let workerRequests = 0;
  let multipartPosts = 0;

  await page.route(workerUrl, async route => {
    workerRequests += 1;
    await route.fulfill({ status: 500 });
  });
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('O'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST' && isAuthorizationRequest(request)) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(authorizationResponse({
          [uploadResponse.transport]: {
            [uploadResponse.transportKind]: uploadNames.workerTransport,
            [uploadResponse.transportUrl]: workerUrl,
            [uploadResponse.transportGrant]: 'same-origin-grant',
            [uploadResponse.transportMime]: 'image/png'
          }
        }))
      });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      multipartPosts += 1;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('O'))) });
  });
  await page.route(pageUrl, route => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Worker origin test</title>' }));
  await page.goto(pageUrl);

  await boot(page, formMarkup('same-origin-worker'), { autoAuthorize: false });
  const mount = page.locator(uploadSelector('mount'));
  await page.locator(uploadSelector('picker')).setInputFiles(imagePayload('same-origin.png'));
  await expect(mount.locator('[data-eforms-upload-state="failed"]')).toHaveCount(1);
  expect(workerRequests).toBe(0);
  expect(multipartPosts).toBe(0);
});

test('removing a stalled authorization aborts its fetch and frees the startup slot', async ({ page }) => {
  let stalledRoute;
  let stalledAuthorization = false;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('A'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST' && isAuthorizationRequest(request)) {
      const body = new URLSearchParams(request.postData() || '');
      if (body.get(uploadNames.displayNameParam) === 'stalled.png') {
        stalledAuthorization = true;
        await new Promise(resolve => {
          stalledRoute = async () => {
            await route.abort('failed').catch(() => {});
            resolve();
          };
        });
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'replacement.png')) });
    }
  });

  await boot(page, formMarkup('abort-authorization'), { autoAuthorize: false });
  const mount = page.locator(uploadSelector('mount'));
  const picker = page.locator(uploadSelector('picker'));
  await picker.setInputFiles(imagePayload('stalled.png'));
  await expect.poll(() => stalledAuthorization).toBe(true);
  await mount.evaluate(node => {
    window.__eformsStalledSignal = node.__eformsUploadRuntime.items[0].transferController.signal;
  });
  await mount.locator('.eforms-upload-remove').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);
  expect(await page.evaluate(() => window.__eformsStalledSignal.aborted)).toBe(true);
  expect(await mount.evaluate(node => node.__eformsUploadRuntime.starting)).toBe(0);
  await stalledRoute();

  await picker.setInputFiles(imagePayload('replacement.png'));
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
});

test('removing a stalled Worker completion aborts its fetch and frees the upload slot', async ({ page }) => {
  const workerUrl = 'https://media.example.test/v1/upload';
  let completionPending = false;
  let releaseCompletion;
  let authorizationCount = 0;
  await page.route(workerUrl, route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ receipt: 'abortable-receipt' })
  }));
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('Q'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const formEncoded = (request.headers()['content-type'] || '').startsWith('application/x-www-form-urlencoded');
      const body = formEncoded ? new URLSearchParams(request.postData() || '') : null;
      if (body && body.has(uploadNames.receiptParam)) {
        completionPending = true;
        await new Promise(resolve => {
          releaseCompletion = async () => {
            await route.abort('failed').catch(() => {});
            resolve();
          };
        });
        return;
      }
      if (isAuthorizationRequest(request)) {
        authorizationCount += 1;
        const response = authorizationCount === 1
          ? authorizationResponse({
            [uploadResponse.transport]: {
              [uploadResponse.transportKind]: uploadNames.workerTransport,
              [uploadResponse.transportUrl]: workerUrl,
              [uploadResponse.transportGrant]: 'abortable-grant',
              [uploadResponse.transportMime]: 'image/png'
            }
          })
          : authorizationResponse();
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(response) });
        return;
      }
      const id = new URL(request.url()).pathname.split('/').pop();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'after-completion.png')) });
    }
  });

  await boot(page, formMarkup('abort-completion'), { autoAuthorize: false });
  const mount = page.locator(uploadSelector('mount'));
  const picker = page.locator(uploadSelector('picker'));
  await picker.setInputFiles(imagePayload('completion.png'));
  await expect.poll(() => completionPending).toBe(true);
  await mount.evaluate(node => {
    window.__eformsCompletionSignal = node.__eformsUploadRuntime.items[0].transferController.signal;
  });
  await mount.locator('.eforms-upload-remove').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);
  expect(await page.evaluate(() => window.__eformsCompletionSignal.aborted)).toBe(true);
  expect(await mount.evaluate(node => node.__eformsUploadRuntime.active)).toBe(0);
  await releaseCompletion();

  await picker.setInputFiles(imagePayload('after-completion.png'));
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
});

test('HEIC remains uploaded when its browser-local selection preview cannot render', async ({ page }) => {
  let releaseUpload;
  let previewRequests = 0;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('H'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      await new Promise(resolve => {
        releaseUpload = async () => {
          await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'server-photo.heic')) });
          resolve();
        };
      });
      return;
    }
    if (kind === 'preview') {
      previewRequests += 1;
      await route.fulfill({ status: 200, contentType: 'image/heic', body: Buffer.from([0, 0, 0, 8, 102, 116, 121, 112]) });
    }
  });

  const markup = formMarkup('heic-preview-demo')
    .replace(
      `${uploadAttrs.accept}="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"`,
      `${uploadAttrs.accept}="image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif"`
    );
  await boot(page, markup);
  const mount = page.locator(uploadSelector('mount'));
  await page.locator(uploadSelector('picker')).setInputFiles(imagePayload('camera.heic', 1, 'image/heic'));
  await expect.poll(() => typeof releaseUpload).toBe('function');
  await expect.poll(() => mount.evaluate(node => node.__eformsUploadRuntime.items[0].previewUnavailable)).toBe(true);
  await expect(mount.locator('.eforms-upload-live')).not.toContainText('Uploaded');
  await releaseUpload();
  expect(previewRequests).toBe(0);
  await expect.poll(() => mount.evaluate(node => node.__eformsUploadRuntime.items[0].objectUrl)).toBe('');
  await expect(mount.locator('.eforms-upload-item')).toHaveAttribute('data-eforms-upload-state', 'uploaded');
  await expect(mount.locator('.eforms-upload-item')).toHaveAttribute('data-eforms-upload-preview', 'unavailable');
  await expect(mount.locator('.eforms-upload-preview')).not.toHaveAttribute('src', /.+/);
});

test('retry, removal, Clear all, stable identity, and terminal 410 stay server-authoritative', async ({ page }) => {
  const uploads = [];
  const deletes = [];
  let failUpload = true;
  let ambiguousUpload = false;
  let ambiguousPendingUpload = false;
  let ambiguousDelete = false;
  let retainAmbiguousDelete = false;
  let terminal = false;
  let terminalRequests = 0;
  const serverItems = [];
  const serverIntents = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('C'))) });
      return;
    }
    if (terminal) {
      terminalRequests += 1;
      await route.fulfill({ status: 410, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_TOKEN' }) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      uploads.push(id);
      if (ambiguousPendingUpload) {
        ambiguousPendingUpload = false;
        serverIntents.push(serverItem(id, 4, 'pending-delete.png', 5));
        await route.abort('failed');
        return;
      }
      if (ambiguousUpload) {
        ambiguousUpload = false;
        serverItems.push(serverItem(id, 3, 'canonical-response-loss.png', 5));
        await route.abort('failed');
        return;
      }
      if (failUpload) {
        failUpload = false;
        await route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_TOKEN' }) });
      } else {
        serverItems.push(serverItem(id, 0, 'retry.png', 5));
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id)) });
      }
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      const id = new URL(request.url()).pathname.split('/').pop();
      deletes.push(id);
      if (ambiguousDelete && retainAmbiguousDelete) {
        ambiguousDelete = false;
        retainAmbiguousDelete = false;
        await route.abort('failed');
        return;
      }
      const index = serverItems.findIndex(item => item[uploadResponse.uploadId] === id);
      if (index !== -1) serverItems.splice(index, 1);
      const intentIndex = serverIntents.findIndex(item => item[uploadResponse.uploadId] === id);
      if (intentIndex !== -1) serverIntents.splice(intentIndex, 1);
      if (ambiguousDelete) {
        ambiguousDelete = false;
        await route.abort('failed');
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('C'), {
      [uploadResponse.items]: serverItems,
      [uploadResponse.intents]: serverIntents
    })) });
  });

  await boot(page, formMarkup());
  const mount = page.locator(uploadSelector('mount'));
  const picker = page.locator(uploadSelector('picker'));
  await picker.setInputFiles(imagePayload('retry.png'));
  await expect(mount.locator('[data-eforms-upload-state="failed"]')).toHaveCount(1);
  await mount.locator('.eforms-upload-retry').click();
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
  expect(uploads[0]).toBe(uploads[1]);

  await mount.locator('.eforms-upload-remove').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);
  expect(deletes).toEqual([uploads[0]]);

  await picker.setInputFiles([imagePayload('a.png', 2), imagePayload('b.png', 3)]);
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(2);
  await mount.locator('.eforms-upload-clear').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);

  ambiguousUpload = true;
  const uploadsBeforeLoss = uploads.length;
  await picker.setInputFiles(imagePayload('response-loss.png', 6));
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
  await expect(mount.locator('.eforms-upload-name')).toHaveText('canonical-response-loss.png');
  expect(uploads.length).toBe(uploadsBeforeLoss + 1);
  ambiguousDelete = true;
  await mount.locator('.eforms-upload-remove').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);

  ambiguousPendingUpload = true;
  await picker.setInputFiles(imagePayload('pending-delete.png', 7));
  await expect(mount.locator('[data-eforms-upload-state="failed"]')).toHaveCount(1);
  ambiguousDelete = true;
  retainAmbiguousDelete = true;
  await mount.locator('.eforms-upload-remove').click();
  await expect(mount.locator('[data-eforms-upload-state="failed"]')).toContainText('Could not remove photo.');
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(1);
  await mount.locator('.eforms-upload-remove').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);

  await picker.setInputFiles([imagePayload('terminal.png', 4), imagePayload('terminal-sibling.png', 5)]);
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(2);
  terminal = true;
  await mount.locator('.eforms-upload-remove').first().click();
  await expect(mount).toHaveAttribute('data-eforms-upload-unavailable', '1');
  await expect(mount).toContainText('Photos are unavailable');
  await expect(mount.locator('.eforms-upload-remove:visible')).toHaveCount(0);
  await expect(mount.locator('.eforms-upload-retry:visible')).toHaveCount(0);
  expect(terminalRequests).toBe(1);
  await mount.locator('.eforms-upload-remove').last().evaluate(button => button.click());
  expect(terminalRequests).toBe(1);
  await expect(mount).not.toContainText('finalized');
  await expect(mount).not.toContainText('submitted');
});

test('Clear all serializes one batch cleanup instead of fanning out every delete', async ({ page }) => {
  const restoredBatchId = batchId('D');
  const restoredSecret = batchId('E');
  const restored = Array.from({ length: 24 }, (_, index) => serverItem(`restored-${index}`, index, `Photo ${index + 1}.png`, 5));
  const pendingDeletes = [];
  const deletedIds = [];
  let activeDeletes = 0;
  let maxActiveDeletes = 0;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'status') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(restoredBatchId, {
        [uploadResponse.items]: restored
      })) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      activeDeletes += 1;
      maxActiveDeletes = Math.max(maxActiveDeletes, activeDeletes);
      const id = new URL(request.url()).pathname.split('/').pop();
      deletedIds.push(id);
      await new Promise(resolve => {
        pendingDeletes.push(async () => {
          await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
          resolve();
        });
      });
      activeDeletes -= 1;
      return;
    }
    await route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
  });

  await boot(page, formMarkup('clear-queue-demo', 'photos', credentialInputs('photos', restoredBatchId, restoredSecret)));
  const mount = page.locator('form.eforms-form-clear-queue-demo').locator(uploadSelector('mount'));
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(24);
  await mount.locator('.eforms-upload-clear').click();
  await expect(mount.locator('[data-eforms-upload-state="removing"]')).toHaveCount(24);
  await expect(mount.locator('.eforms-upload-clear')).toBeHidden();

  for (let index = 0; index < restored.length; index += 1) {
    await expect.poll(() => pendingDeletes.length).toBe(index + 1);
    expect(activeDeletes).toBe(1);
    await pendingDeletes[index]();
  }

  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);
  expect(maxActiveDeletes).toBe(1);
  expect(deletedIds).toEqual(restored.map(item => item[uploadResponse.uploadId]));
});

test('Clear all does not start queued uploads while aborting active transfers', async ({ page }) => {
  let authorizationRequests = 0;
  const stalledTransfers = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('Q'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      authorizationRequests += 1;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      await new Promise(resolve => {
        stalledTransfers.push(async () => {
          await route.abort('failed').catch(() => {});
          resolve();
        });
      });
      return;
    }
    await route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
  });

  await boot(page, formMarkup('clear-active-demo'), { autoAuthorize: false });
  const mount = page.locator('form.eforms-form-clear-active-demo').locator(uploadSelector('mount'));
  await page.locator('form.eforms-form-clear-active-demo').locator(uploadSelector('picker')).setInputFiles(
    Array.from({ length: 6 }, (_, index) => imagePayload(`active-${index}.png`, index + 1))
  );
  await expect.poll(() => stalledTransfers.length).toBe(3);
  expect(authorizationRequests).toBe(3);
  await mount.locator('.eforms-upload-clear').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);
  expect(authorizationRequests).toBe(3);
  await Promise.all(stalledTransfers.map(release => release()));
});

test('local accept expiry rerenders failed cards while preserving server cleanup', async ({ page }) => {
  let deleteRequests = 0;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('L'), {
        [uploadResponse.acceptUntil]: Math.floor(Date.now() / 1000) + 2
      })) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      deleteRequests += 1;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      await route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_UPLOAD_TYPE' }) });
      return;
    }
    await route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
  });

  await boot(page, formMarkup('timer-expiry-demo'), { autoAuthorize: false });
  const form = page.locator('form.eforms-form-timer-expiry-demo');
  const mount = form.locator(uploadSelector('mount'));
  await form.locator(uploadSelector('picker')).setInputFiles(imagePayload('retry-before-expiry.png'));
  await expect(mount.locator('[data-eforms-upload-state="failed"]')).toHaveCount(1);
  await expect(mount.locator('.eforms-upload-retry')).toBeVisible();
  await expect(mount).toHaveAttribute('data-eforms-upload-expired', '1', { timeout: 4000 });
  await expect(mount.locator('.eforms-upload-retry')).toBeHidden();
  await expect(mount.locator('.eforms-upload-remove')).toBeVisible();
  await expect(mount.locator('.eforms-upload-clear')).toBeVisible();
  await mount.locator('.eforms-upload-remove').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);
  expect(deleteRequests).toBe(1);
});

test('submit blocks unresolved cards, restores the exact label, and posts only hidden batch credentials', async ({ page }) => {
  let releaseUpload;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('D'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      await new Promise(resolve => { releaseUpload = async () => { await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse('item')) }); resolve(); }; });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
  });
  await boot(page, formMarkup());
  await addFormStyles(page);
  const picker = page.locator(uploadSelector('picker'));
  await picker.setInputFiles(imagePayload('blocking.png'));
  await expect(page.locator('button[type="submit"]')).toHaveText('Finishing photos...');
  await page.locator(uploadSelector('mount')).evaluate(node => {
    const item = node.__eformsUploadRuntime.items[0];
    item.xhr.upload.onprogress({ lengthComputable: true, loaded: item.bytes, total: item.bytes });
  });
  const unresolvedCard = page.locator('[data-eforms-upload-state="verifying"]');
  const restingBackground = await unresolvedCard.evaluate(node => getComputedStyle(node).backgroundColor);
  const blocked = await page.evaluate(() => {
    const form = document.querySelector('form');
    const event = new Event('submit', { bubbles: true, cancelable: true });
    const allowed = form.dispatchEvent(event);
    return { allowed, activeState: document.activeElement.getAttribute('data-eforms-upload-state') };
  });
  expect(blocked.allowed).toBeFalsy();
  expect(blocked.activeState).toBe('verifying');
  const focusedStyle = await unresolvedCard.evaluate(node => {
    const style = getComputedStyle(node);
    return { backgroundColor: style.backgroundColor, outlineStyle: style.outlineStyle, outlineWidth: style.outlineWidth };
  });
  expect(focusedStyle.backgroundColor).not.toBe(restingBackground);
  expect(focusedStyle.outlineStyle === 'none' || focusedStyle.outlineWidth === '0px').toBeTruthy();
  await releaseUpload();
  await expect(page.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
  await expect(page.locator('button[type="submit"]')).toHaveText('Send Estimate Request');
  await page.locator('.eforms-upload-remove').click();
  await expect(page.locator('.eforms-upload-item')).toHaveCount(0);
  await page.locator(uploadSelector('mount')).evaluate(node => {
    node.__eformsUploadRuntime.required = false;
  });

  const submitted = await page.evaluate(() => {
    const form = document.querySelector('form');
    let result = null;
    form.addEventListener('submit', event => {
      event.preventDefault();
      const data = new FormData(form);
      result = Array.from(data.entries()).map(([key, value]) => [key, value instanceof File ? value.name : value]);
    });
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    return result;
  });
  expect(submitted).toContainEqual([`${uploadFields.root}[photos][${uploadFields.batch_id}]`, batchId('D')]);
  expect(submitted.some(([key]) => key === `${uploadFields.root}[photos][${uploadFields.batch_secret}]`)).toBeTruthy();
  expect(submitted.some(([, value]) => value === 'blocking.png')).toBeFalsy();
  await expect(picker).toBeDisabled();
  await expect(page.locator('.eforms-upload-remove')).toBeHidden();
});

test('validated rerender restores committed cards without rereading authoritative artifacts', async ({ page }) => {
  const statusHeaders = [];
  let uploadPosts = 0;
  let previewRequests = 0;
  let statusCalls = 0;
  let releaseRestore;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    statusHeaders.push(request.headers()[uploadNames.batchSecretHeader.toLowerCase()]);
    if (kind === 'status') {
      statusCalls += 1;
      if (statusCalls === 1) {
        await route.abort('failed');
        return;
      }
      if (statusCalls === 2) {
        await new Promise(resolve => { releaseRestore = resolve; });
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ [uploadResponse.state]: 'unknown' }) });
        return;
      }
      if (statusCalls === 3) {
        await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_STORAGE_UNAVAILABLE' }) });
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('E'), {
        [uploadResponse.items]: [
          serverItem('restored', 0, 'Restored Photo.png', 10),
          serverItem('preview-missing', 1, 'Server Photo.png', 11)
        ],
        [uploadResponse.intents]: [serverItem('interrupted', 2, 'Interrupted Photo.png', 12)]
      })) });
      return;
    }
    if (kind === 'preview') {
      previewRequests += 1;
      await route.fulfill({ status: 500, body: 'preview route must not be used during restore' });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') uploadPosts += 1;
  });
  const credentials = credentialInputs('photos', batchId('E'), batchId('S'));
  await page.setContent(`<main>${formMarkup('restore-demo', 'photos', credentials)}${formMarkup('second-demo')}</main>`);
  await page.evaluate(({ uploadEndpoint, protocol }) => {
    window.eformsSettings = { uploadBatchEndpoint: uploadEndpoint, protocol };
    window.__revoked = [];
    const originalCreate = URL.createObjectURL.bind(URL);
    const originalRevoke = URL.revokeObjectURL.bind(URL);
    URL.createObjectURL = blob => originalCreate(blob);
    URL.revokeObjectURL = value => { window.__revoked.push(value); originalRevoke(value); };
  }, { uploadEndpoint: endpoint, protocol: browserProtocol });
  await page.addScriptTag({ path: formsScript });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));

  const restored = page.locator('form.eforms-form-restore-demo');
  const picker = restored.locator(uploadSelector('picker'));
  const choose = restored.locator('.eforms-upload-choose');
  await expect(choose).toHaveText('Retry restore');
  await expect(picker).toBeDisabled();
  expect(await restored.evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))).toBeFalsy();

  await choose.click();
  await expect(restored.locator(uploadSelector('mount'))).toHaveAttribute('data-eforms-upload-restoring', '1');
  await expect(choose).toBeDisabled();
  await expect(picker).toBeDisabled();
  expect(await restored.evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))).toBeFalsy();
  expect(uploadPosts).toBe(0);
  await expect.poll(() => typeof releaseRestore).toBe('function');
  releaseRestore();
  await expect(choose).toHaveText('Retry restore');
  await expect(picker).toBeDisabled();
  await choose.click();
  await expect(choose).toHaveText('Retry restore');
  await expect(picker).toBeDisabled();
  await choose.click();

  await expect(restored.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(2);
  const interrupted = restored.locator('[data-eforms-upload-id="interrupted"]');
  await expect(interrupted).toHaveAttribute('data-eforms-upload-state', 'failed');
  await expect(interrupted).toContainText('Remove and select this photo again.');
  await expect(interrupted.locator('.eforms-upload-retry')).toBeHidden();
  await expect(picker).toBeEnabled();
  await expect(choose).toHaveText('Browse photos');
  await expect(restored.locator('[data-eforms-upload-id="restored"]')).toHaveAttribute('data-eforms-upload-preview', 'unavailable');
  await expect(restored.locator('[data-eforms-upload-id="restored"] .eforms-upload-preview')).not.toHaveAttribute('src', /.+/);
  const missingPreview = restored.locator('[data-eforms-upload-id="preview-missing"]');
  await expect(missingPreview).toHaveAttribute('data-eforms-upload-preview', 'unavailable');
  await expect(missingPreview).toContainText('Uploaded (preview unavailable)');
  expect(await restored.evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))).toBeFalsy();
  await interrupted.locator('.eforms-upload-remove').click();
  await expect(interrupted).toHaveCount(0);
  expect(await restored.evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))).toBeFalsy();
  expect(statusHeaders.every(value => value === batchId('S'))).toBeTruthy();
  expect(uploadPosts).toBe(0);
  expect(previewRequests).toBe(0);
  await expect(page.locator('form.eforms-form-second-demo .eforms-upload-item')).toHaveCount(0);

  await restored.evaluate(form => form.dispatchEvent(new Event('eforms:destroy')));
  expect((await page.evaluate(() => window.__revoked)).length).toBe(0);
  await expect(restored.locator(`input[name^="${uploadFields.root}"]`)).toHaveCount(0);
  await expect(restored.locator('.eforms-upload-item')).toHaveCount(0);
  expect(await restored.locator(uploadSelector('mount')).evaluate(node => node.__eformsUploadRuntime.items.length)).toBe(0);
});

test('expired finalizing rerender restores a frozen batch for corrected submission', async ({ page }) => {
  const recoveryId = batchId('R');
  const recoverySecret = batchId('T');
  let previewRequests = 0;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const kind = routeKind(route.request().url());
    if (kind === 'status') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(recoveryId, {
        [uploadResponse.state]: 'finalizing',
        [uploadResponse.acceptUntil]: Math.floor(Date.now() / 1000) - 60,
        [uploadResponse.deleteAfter]: Math.floor(Date.now() / 1000) + 3600,
        [uploadResponse.items]: [serverItem('recoverable', 0, 'Recoverable Photo.png', 10)]
      })) });
      return;
    }
    if (kind === 'preview') {
      previewRequests += 1;
      await route.fulfill({ status: 410, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_TOKEN' }) });
    }
  });

  await boot(page, formMarkup('recovery-demo', 'photos', credentialInputs('photos', recoveryId, recoverySecret)));
  const form = page.locator('form.eforms-form-recovery-demo');
  const mount = form.locator(uploadSelector('mount'));
  await expect(form.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
  await expect(form.locator(uploadSelector('picker'))).toBeDisabled();
  await expect(form.locator('.eforms-upload-choose')).toBeDisabled();
  await expect(form.locator('.eforms-upload-clear')).toBeDisabled();
  await expect(form.locator('.eforms-upload-clear')).toBeHidden();
  await expect(form.locator('.eforms-upload-remove')).toBeHidden();
  await expect(mount).toHaveAttribute('data-eforms-upload-frozen', '1');
  await expect(mount).not.toHaveAttribute('data-eforms-upload-unavailable', '1');
  await expect(mount).not.toHaveAttribute('data-eforms-upload-expired', '1');
  await expect(form.locator('.eforms-upload-field-status')).toContainText('restored for corrected submission');
  expect(previewRequests).toBe(0);

  const submitted = await form.evaluate(node => {
    const allowed = node.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    return { allowed, entries: Array.from(new FormData(node).entries()) };
  });
  expect(submitted.allowed).toBeFalsy();
  expect(submitted.entries).toContainEqual([`${uploadFields.root}[photos][${uploadFields.batch_id}]`, recoveryId]);
  expect(submitted.entries).toContainEqual([`${uploadFields.root}[photos][${uploadFields.batch_secret}]`, recoverySecret]);
});

test('authorization conflict retires an absent terminal upload ID before retry', async ({ page }) => {
  const authorizedIds = [];
  const multipartIds = [];
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('R'))) });
      return;
    }
    if (kind === 'status') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('R'))) });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      const id = new URL(request.url()).pathname.split('/').pop();
      authorizedIds.push(id);
      if (authorizedIds.length === 1) {
        await route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_TOKEN' }) });
      } else {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      }
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      multipartIds.push(id);
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'retried.png')) });
      return;
    }
    await route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
  });

  await boot(page, formMarkup('terminal-id-demo'), { autoAuthorize: false });
  const mount = page.locator('form.eforms-form-terminal-id-demo').locator(uploadSelector('mount'));
  await page.locator('form.eforms-form-terminal-id-demo').locator(uploadSelector('picker')).setInputFiles(imagePayload('retried.png'));
  await expect(mount.locator('[data-eforms-upload-state="failed"]')).toContainText('Upload expired. Retry.');
  const replacementId = await mount.locator('.eforms-upload-item').getAttribute('data-eforms-upload-id');
  expect(authorizedIds).toHaveLength(1);
  expect(replacementId).not.toBe(authorizedIds[0]);

  await mount.locator('.eforms-upload-retry').click();
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(1);
  expect(authorizedIds).toEqual([authorizedIds[0], replacementId]);
  expect(multipartIds).toEqual([replacementId]);
});

test('authorization failure before transfer never presents completed progress and remains removable', async ({ page }) => {
  let authorizationRequests = 0;
  let multipartRequests = 0;
  let deleteRequests = 0;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('S'))) });
      return;
    }
    if (kind === 'status') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('S'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'DELETE') {
      deleteRequests += 1;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      authorizationRequests += 1;
      await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_STORAGE_UNAVAILABLE' }) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      multipartRequests += 1;
    }
    await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
  });

  await boot(page, formMarkup('authorization-failure-demo'), { autoAuthorize: false });
  await addFormStyles(page);
  const mount = page.locator('form.eforms-form-authorization-failure-demo').locator(uploadSelector('mount'));
  await page.locator('form.eforms-form-authorization-failure-demo').locator(uploadSelector('picker')).setInputFiles(imagePayload('pre-transfer-failure.png'));

  const failedCard = mount.locator('[data-eforms-upload-state="failed"]');
  await expect(failedCard).toContainText('Upload failed. Retry.');
  await expect(failedCard.locator('.eforms-upload-progress')).toBeHidden();
  await expect(failedCard.locator('.eforms-upload-progress')).toHaveAttribute('aria-valuenow', '0');
  expect(authorizationRequests).toBe(1);
  expect(multipartRequests).toBe(0);

  await failedCard.locator('.eforms-upload-remove').click();
  await expect(mount.locator('.eforms-upload-item')).toHaveCount(0);
  expect(deleteRequests).toBe(1);
});

test('uploaded cards use quiet overlay removal while failed cards keep action text', async ({ page }) => {
  let failAuthorization = false;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('O'))) });
      return;
    }
    if (kind === 'item' && isAuthorizationRequest(request)) {
      if (failAuthorization) {
        await route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_TOKEN' }) });
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authorizationResponse()) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      const id = new URL(request.url()).pathname.split('/').pop();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadItemResponse(id, 'overlay-card.png')) });
      return;
    }
    await route.fallback();
  });

  await boot(page, formMarkup('overlay-card-demo'), { autoAuthorize: false });
  await addFormStyles(page);
  const mount = page.locator(uploadSelector('mount'));
  await page.locator(uploadSelector('picker')).setInputFiles({ name: 'overlay-card.png', mimeType: 'image/png', buffer: tinyPng });

  const uploaded = mount.locator('[data-eforms-upload-state="uploaded"]');
  await expect(uploaded).toHaveCount(1);
  await expect(uploaded.locator('.eforms-upload-name')).toHaveText('overlay-card.png');
  await expect(uploaded.locator('.eforms-upload-status')).toHaveText('Uploaded');
  await expect(uploaded.locator('.eforms-upload-status')).toHaveClass(/screen-reader-text/);
  expect(await uploaded.locator('.eforms-upload-status').evaluate(status => {
    const box = status.getBoundingClientRect();
    const style = getComputedStyle(status);
    return style.position === 'absolute' && style.overflow === 'hidden' && box.width <= 1 && box.height <= 1;
  })).toBe(true);
  await expect(uploaded.locator('.eforms-upload-actions')).toBeHidden();
  const media = uploaded.locator('.eforms-upload-media');
  const remove = media.locator('> .eforms-upload-remove');
  await expect(remove).toBeVisible();
  const mediaBox = await media.boundingBox();
  const removeBox = await remove.boundingBox();
  if (!mediaBox || !removeBox) throw new Error('Expected uploaded card overlay geometry');
  expect(removeBox.width).toBeGreaterThanOrEqual(44);
  expect(removeBox.height).toBeGreaterThanOrEqual(44);
  await expect(remove).toHaveAttribute('aria-label', 'Remove overlay-card.png');
  await expect(remove).toHaveText('');
  await expect(remove.locator('svg[aria-hidden="true"]')).toHaveCount(1);
  expect(removeBox.x).toBeGreaterThanOrEqual(mediaBox.x + mediaBox.width - removeBox.width - 1);
  expect(removeBox.x + removeBox.width).toBeLessThanOrEqual(mediaBox.x + mediaBox.width + 1);
  expect(removeBox.y).toBeLessThanOrEqual(mediaBox.y + 1);

  failAuthorization = true;
  await page.locator(uploadSelector('picker')).setInputFiles(imagePayload('needs-retry.png', 2));
  const failed = mount.locator('[data-eforms-upload-state="failed"]');
  await expect(failed).toContainText('Upload failed');
  await expect(failed.locator('.eforms-upload-status')).toBeVisible();
  await expect(failed.locator('.eforms-upload-status')).not.toHaveClass(/screen-reader-text/);
  await expect(failed.locator('.eforms-upload-retry')).toBeVisible();
  await expect(failed.locator('.eforms-upload-actions')).toBeVisible();
});

test('responsive uploader geometry keeps accessible targets and removes mobile drag wording', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 800 });
  await boot(page, formMarkup());
  await addFormStyles(page);
  const grid = page.locator('.eforms-upload-grid');
  const hint = page.locator('.eforms-upload-drop-hint');
  const columns = async () => grid.evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
  expect(await columns()).toBe(3);
  await page.setViewportSize({ width: 600, height: 800 });
  expect(await columns()).toBe(2);
  await expect(hint).toBeHidden();
  await page.setViewportSize({ width: 300, height: 800 });
  expect(await columns()).toBe(1);

  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const kind = routeKind(route.request().url());
    if (kind === 'create') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('F'))) });
    } else {
      await route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_TOKEN' }) });
    }
  });
  await page.locator(uploadSelector('picker')).setInputFiles(imagePayload('accessible-full-filename.png'));
  const remove = page.locator('.eforms-upload-remove');
  await expect(remove).toBeVisible();
  const box = await remove.boundingBox();
  expect(box.width).toBeGreaterThanOrEqual(44);
  expect(box.height).toBeGreaterThanOrEqual(44);
  await expect(page.locator('.eforms-upload-name')).toHaveAttribute('title', 'accessible-full-filename.png');
  await expect(page.locator('.eforms-upload-live')).toContainText('Upload failed');
  await expect(page.locator('.eforms-upload-live')).not.toContainText('%');
});

test('finalizing denial freezes mutation while accept expiry uses the reload contract', async ({ page }) => {
  let uploadBodies = 0;
  let releasePendingUpload;
  await page.route('https://example.test/eforms/upload-batches**', async route => {
    const request = route.request();
    const kind = routeKind(request.url());
    if (kind === 'create') {
      const expired = request.postData().includes('expired-demo');
      if (expired) {
        await route.fulfill({ status: 410, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_TOKEN' }) });
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('G'))) });
      return;
    }
    if (kind === 'item' && request.method() === 'POST') {
      uploadBodies += 1;
      if (uploadBodies === 2) {
        await new Promise(resolve => {
          releasePendingUpload = async () => {
            await route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_TOKEN' }) });
            resolve();
          };
        });
        return;
      }
      await route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_TOKEN' }) });
      return;
    }
    if (kind === 'status') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('G'), { [uploadResponse.state]: 'finalizing' })) });
    }
  });
  await boot(page, formMarkup('finalizing-demo') + formMarkup('expired-demo'), {
    clientPreparation: { workerUrl: '/fake-client-image-preparer.js', recipe: preparationRecipe },
    beforeScript: async browserPage => browserPage.evaluate(() => {
      window.__finalizingPreparation = { constructed: 0, terminated: 0, late: null };
      window.Worker = class HeldFinalizingWorker {
        constructor() {
          window.__finalizingPreparation.constructed += 1;
        }
        postMessage(message) {
          if (message.type === 'probe') {
            this.onmessage({ data: { type: 'ready', requestId: message.requestId } });
            return;
          }
          if (message.type === 'prepare') {
            this.onmessage({ data: { type: 'preparing', requestId: message.requestId } });
            window.__finalizingPreparation.late = () => this.onmessage({ data: { type: 'use_source', requestId: message.requestId } });
          }
        }
        terminate() {
          window.__finalizingPreparation.terminated += 1;
        }
      };
    })
  });
  const finalizing = page.locator('form.eforms-form-finalizing-demo');
  const picker = finalizing.locator(uploadSelector('picker'));
  const mount = finalizing.locator(uploadSelector('mount'));
  await picker.setInputFiles([imagePayload('race.png'), imagePayload('still-processing.png', 2), imagePayload('held.jpg', 3, 'image/jpeg')]);
  await expect.poll(() => typeof releasePendingUpload).toBe('function');
  await expect(finalizing.locator('[data-eforms-upload-state="failed"]')).toContainText('Photos are being submitted.');
  expect(await mount.evaluate(node => ({
    items: node.querySelectorAll('.eforms-upload-item').length,
    frozen: node.getAttribute('data-eforms-upload-frozen'),
    picker: { disabled: node.__eformsUploadRuntime.picker.disabled, value: node.__eformsUploadRuntime.picker.value },
    chooseDisabled: node.querySelector('.eforms-upload-choose').disabled,
    clear: { disabled: node.querySelector('.eforms-upload-clear').disabled, hidden: node.querySelector('.eforms-upload-clear').hidden },
    visibleRemoves: node.querySelectorAll('.eforms-upload-remove:not([hidden])').length,
    visibleRetries: node.querySelectorAll('.eforms-upload-retry:not([hidden])').length
  }))).toEqual({ items: 3, frozen: '1', picker: { disabled: true, value: '' }, chooseDisabled: true, clear: { disabled: true, hidden: true }, visibleRemoves: 0, visibleRetries: 0 });
  expect(await page.evaluate(() => ({
    constructed: window.__finalizingPreparation.constructed,
    terminated: window.__finalizingPreparation.terminated
  }))).toEqual({ constructed: 1, terminated: 1 });
  const held = finalizing.locator('.eforms-upload-item').filter({ hasText: 'held.jpg' });
  await expect(held).toHaveAttribute('data-eforms-upload-state', 'preparing');
  await page.evaluate(() => window.__finalizingPreparation.late());
  await page.waitForTimeout(50);
  await expect(held).toHaveAttribute('data-eforms-upload-state', 'preparing');
  await expect(finalizing).not.toContainText('finalized');

  expect(await mount.evaluate(async node => {
    let fetchCalls = 0;
    const fetchOwner = window.fetch;
    window.fetch = function () { fetchCalls += 1; return Promise.resolve({ status: 500 }); };
    try {
      node.querySelectorAll('.eforms-upload-remove, .eforms-upload-retry').forEach(button => button.click());
      node.querySelector('.eforms-upload-clear').click();
      const pickerNode = node.__eformsUploadRuntime.picker;
      const transfer = new DataTransfer();
      transfer.items.add(new File(['blocked'], 'blocked.png', { type: 'image/png' }));
      pickerNode.files = transfer.files;
      pickerNode.dispatchEvent(new Event('change', { bubbles: true }));
      await Promise.resolve();
      return { fetchCalls, items: node.querySelectorAll('.eforms-upload-item').length };
    } finally {
      window.fetch = fetchOwner;
    }
  })).toEqual({ fetchCalls: 0, items: 3 });
  await releasePendingUpload();

  const expired = page.locator('form.eforms-form-expired-demo');
  await expired.locator(uploadSelector('picker')).setInputFiles(imagePayload('expired.png'));
  await expect(expired.locator(uploadSelector('mount'))).toHaveAttribute('data-eforms-upload-expired', '1');
  await expect(expired).toContainText('Form expired—reload and select your photos again.');
  const allowed = await expired.evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
  expect(allowed).toBeFalsy();
  expect(uploadBodies).toBe(2);
});
