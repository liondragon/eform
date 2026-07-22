const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const formsScript = path.resolve(__dirname, '../../../assets/forms.js');
const formsCss = path.resolve(__dirname, '../../../assets/forms.css');
const protocolPhp = path.resolve(__dirname, '../../../src/FormProtocol.php');
const browserProtocol = JSON.parse(execFileSync('php', ['-r', `require ${JSON.stringify(protocolPhp)}; echo json_encode(FormProtocol::browser_settings());`], { encoding: 'utf8' }));
const hiddenNames = browserProtocol.hiddenFields;
const uploadNames = browserProtocol.upload;
const uploadFields = uploadNames.batchFields;
const uploadAttrs = uploadNames.dataAttributes;
const uploadResponse = uploadNames.response;
const batchIdChars = uploadNames.runtime.batchIdChars;
const displayNameMaxChars = uploadNames.runtime.displayNameMaxChars;
const endpoint = 'https://example.test/eforms/upload-batches';
const formsScriptBody = fs.readFileSync(formsScript, 'utf8');

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
      <input id="${pickerId}" type="file" multiple required disabled ${uploadAttrs.picker}="1" accept="image/jpeg,image/png,image/webp">
      <div class="eforms-upload" ${uploadAttrs.mount}="1" ${uploadAttrs.pickerId}="${pickerId}" ${uploadAttrs.field}="${field}" ${uploadAttrs.accept}="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" ${uploadAttrs.maxFiles}="24" ${uploadAttrs.maxFileBytes}="20971520" ${uploadAttrs.maxTotalBytes}="314572800"></div>
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
    [uploadResponse.items]: [],
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

async function boot(page, html) {
  await page.setContent(`<main>${html}</main>`);
  await page.evaluate(({ uploadEndpoint, protocol }) => {
    window.eformsSettings = { uploadBatchEndpoint: uploadEndpoint, protocol };
  }, { uploadEndpoint: endpoint, protocol: browserProtocol });
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

test('staged upload prompt keeps a compact vertical hierarchy at desktop and narrow widths', async ({ page }) => {
  await boot(page, formMarkup('upload-prompt-demo'));
  await page.addStyleTag({ path: formsCss });
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
        body: JSON.stringify(batchResponse(batchId('T')))
      });
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
    node.closest('form').remove();
  });
  await expect.poll(() => page.evaluate(() => window.__eformsDestroyedRuntime.destroyed)).toBe(true);
  releaseCreate();
  await expect.poll(() => page.evaluate(() => window.__eformsDestroyedRuntime.createPending)).toBe(false);

  expect(itemPosts).toBe(0);
  expect(await page.evaluate(() => ({
    batchId: window.__eformsDestroyedRuntime.batchId,
    secret: window.__eformsDestroyedRuntime.secret
  }))).toEqual({ batchId: '', secret: '' });
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

test('staged queue uses one retry-safe secret, explicit selection, three uploads, and Processing', async ({ page }) => {
  const secrets = [];
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

  await boot(page, formMarkup());
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
  expect(new Set(itemIds).size).toBe(itemIds.length);
  await mount.evaluate(node => {
    node.__eformsUploadRuntime.items.filter(item => item.xhr).forEach(item => {
      item.xhr.upload.onprogress({ lengthComputable: true, loaded: item.bytes, total: item.bytes });
    });
  });
  await expect(mount.locator('[data-eforms-upload-state="processing"]')).toHaveCount(3);
  await expect(mount.locator('.eforms-upload-progress').first()).toHaveAttribute('aria-valuenow', '100');
  await expect(mount.locator('.eforms-upload-progress').first()).toHaveText('100%');
  await expect(mount.locator('.eforms-upload-status').first()).toHaveText('Processing');
  await Promise.all(pending.splice(0, 3).map(release => release()));
  await expect.poll(() => pending.length).toBe(2);
  await Promise.all(pending.splice(0).map(release => release()));
  await expect(mount.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(5);
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

test('HEIC upload replaces its browser-local source with the generated JPEG preview', async ({ page }) => {
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
      await route.fulfill({ status: 200, contentType: 'image/jpeg', body: Buffer.from([255, 216, 255, 217]) });
    }
  });

  const markup = formMarkup('heic-preview-demo')
    .replace('accept="image/jpeg,image/png,image/webp"', 'accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif"')
    .replace(
      `${uploadAttrs.accept}="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"`,
      `${uploadAttrs.accept}="image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif"`
    );
  await boot(page, markup);
  const mount = page.locator(uploadSelector('mount'));
  await page.locator(uploadSelector('picker')).setInputFiles(imagePayload('camera.heic', 1, 'image/heic'));
  await expect.poll(() => typeof releaseUpload).toBe('function');
  const localUrl = await mount.evaluate(node => node.__eformsUploadRuntime.items[0].objectUrl);
  await releaseUpload();
  await expect.poll(() => previewRequests).toBe(1);
  await expect.poll(() => mount.evaluate(node => node.__eformsUploadRuntime.items[0].objectUrl)).not.toBe(localUrl);
  await expect(mount.locator('.eforms-upload-item')).toHaveAttribute('data-eforms-upload-state', 'uploaded');
  await expect(mount.locator('.eforms-upload-preview')).toHaveAttribute('src', /^blob:/);
});

test('retry, removal, Clear all, stable identity, and terminal 410 stay server-authoritative', async ({ page }) => {
  const uploads = [];
  const deletes = [];
  let failUpload = true;
  let ambiguousUpload = false;
  let ambiguousDelete = false;
  let terminal = false;
  let terminalRequests = 0;
  const serverItems = [];
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
      const index = serverItems.findIndex(item => item[uploadResponse.uploadId] === id);
      if (index !== -1) serverItems.splice(index, 1);
      if (ambiguousDelete) {
        ambiguousDelete = false;
        await route.abort('failed');
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ deleted: true }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(batchResponse(batchId('C'), { [uploadResponse.items]: serverItems })) });
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
  await page.addStyleTag({ path: formsCss });
  const picker = page.locator(uploadSelector('picker'));
  await picker.setInputFiles(imagePayload('blocking.png'));
  await expect(page.locator('button[type="submit"]')).toHaveText('WAITING FOR PHOTOS');
  await page.locator(uploadSelector('mount')).evaluate(node => {
    const item = node.__eformsUploadRuntime.items[0];
    item.xhr.upload.onprogress({ lengthComputable: true, loaded: item.bytes, total: item.bytes });
  });
  const unresolvedCard = page.locator('[data-eforms-upload-state="processing"]');
  const restingBackground = await unresolvedCard.evaluate(node => getComputedStyle(node).backgroundColor);
  const blocked = await page.evaluate(() => {
    const form = document.querySelector('form');
    const event = new Event('submit', { bubbles: true, cancelable: true });
    const allowed = form.dispatchEvent(event);
    return { allowed, activeState: document.activeElement.getAttribute('data-eforms-upload-state') };
  });
  expect(blocked.allowed).toBeFalsy();
  expect(blocked.activeState).toBe('processing');
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

test('validated rerender restores authenticated previews; teardown revokes URLs and isolates forms', async ({ page }) => {
  const statusHeaders = [];
  let uploadPosts = 0;
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
        ]
      })) });
      return;
    }
    if (kind === 'preview') {
      await route.fulfill(request.url().includes('preview-missing')
        ? { status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'EFORMS_ERR_STORAGE_UNAVAILABLE' }) }
        : { status: 200, contentType: 'image/jpeg', body: Buffer.from([255, 216, 255, 217]) });
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
  await expect(picker).toBeEnabled();
  await expect(choose).toHaveText('Browse photos');
  await expect(restored.locator('[data-eforms-upload-id="restored"] .eforms-upload-preview')).toHaveAttribute('src', /^blob:/);
  const missingPreview = restored.locator('[data-eforms-upload-id="preview-missing"]');
  await expect(missingPreview).toHaveAttribute('data-eforms-upload-preview', 'unavailable');
  await expect(missingPreview).toContainText('Uploaded (preview unavailable)');
  expect(await restored.evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))).toBeTruthy();
  expect(statusHeaders.every(value => value === batchId('S'))).toBeTruthy();
  expect(uploadPosts).toBe(0);
  await expect(page.locator('form.eforms-form-second-demo .eforms-upload-item')).toHaveCount(0);

  await restored.evaluate(form => form.dispatchEvent(new Event('eforms:destroy')));
  expect((await page.evaluate(() => window.__revoked)).length).toBeGreaterThan(0);
  await expect(restored.locator(`input[name^="${uploadFields.root}"]`)).toHaveCount(0);
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
  expect(submitted.allowed).toBeTruthy();
  expect(submitted.entries).toContainEqual([`${uploadFields.root}[photos][${uploadFields.batch_id}]`, recoveryId]);
  expect(submitted.entries).toContainEqual([`${uploadFields.root}[photos][${uploadFields.batch_secret}]`, recoverySecret]);
});

test('responsive uploader geometry keeps accessible targets and removes mobile drag wording', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 800 });
  await boot(page, formMarkup());
  await page.addStyleTag({ path: formsCss });
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
  await boot(page, formMarkup('finalizing-demo') + formMarkup('expired-demo'));
  const finalizing = page.locator('form.eforms-form-finalizing-demo');
  const picker = finalizing.locator(uploadSelector('picker'));
  const mount = finalizing.locator(uploadSelector('mount'));
  await picker.setInputFiles([imagePayload('race.png'), imagePayload('still-processing.png', 2)]);
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
  }))).toEqual({ items: 2, frozen: '1', picker: { disabled: true, value: '' }, chooseDisabled: true, clear: { disabled: true, hidden: true }, visibleRemoves: 0, visibleRetries: 0 });
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
  })).toEqual({ fetchCalls: 0, items: 2 });
  await releasePendingUpload();

  const expired = page.locator('form.eforms-form-expired-demo');
  await expired.locator(uploadSelector('picker')).setInputFiles(imagePayload('expired.png'));
  await expect(expired.locator(uploadSelector('mount'))).toHaveAttribute('data-eforms-upload-expired', '1');
  await expect(expired).toContainText('Form expired—reload and select your photos again.');
  const allowed = await expired.evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
  expect(allowed).toBeFalsy();
  expect(uploadBodies).toBe(2);
});
