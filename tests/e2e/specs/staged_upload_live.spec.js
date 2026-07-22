const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const protocolPhp = path.resolve(__dirname, '../../../src/FormProtocol.php');
const browserProtocol = JSON.parse(execFileSync('php', ['-r', `require ${JSON.stringify(protocolPhp)}; echo json_encode(FormProtocol::browser_settings());`], { encoding: 'utf8' }));
const uploadNames = browserProtocol.upload;
const uploadAttrs = uploadNames.dataAttributes;
const batchSecretHeader = uploadNames.batchSecretHeader.toLowerCase();
const batchIdPattern = new RegExp(`^[A-Za-z0-9_-]{${uploadNames.runtime.batchIdChars}}$`);
const batchSecretChars = Math.ceil(uploadNames.runtime.batchSecretBytes * 8 / 6);
const batchSecretPattern = new RegExp(`^[A-Za-z0-9_-]{${batchSecretChars}}$`);
const fixtureDir = path.resolve(__dirname, '../../fixtures');

const jpeg = Buffer.from(fs.readFileSync(path.join(fixtureDir, 'oriented-landscape.jpg.b64'), 'utf8').trim(), 'base64');
const png = Buffer.from(fs.readFileSync(path.join(fixtureDir, 'staged-landscape.png.b64'), 'utf8').trim(), 'base64');

function uploadRoute(url) {
  const parsed = new URL(url);
  return parsed.searchParams.get('rest_route') || parsed.pathname;
}

function isUploadBatchRequest(url) {
  return uploadRoute(url).includes('/eforms/upload-batches');
}

function uploadSelector(kind, value = '1') {
  return `[${uploadAttrs[kind]}="${value}"]`;
}

test('live WordPress upload survives validation rerender and submits without re-upload', async ({ page }) => {
  const pageUrl = process.env.EFORMS_E2E_STAGED_PAGE_URL;
  if (!pageUrl) {
    test.skip(true, 'EFORMS_E2E_STAGED_PAGE_URL is required.');
  }

  const requests = [];
  page.on('request', request => {
    if (isUploadBatchRequest(request.url())) {
      requests.push({ method: request.method(), url: request.url(), headers: request.headers() });
    }
  });

  await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
  const form = page.locator('form.eforms-form-upload-test');
  const picker = form.locator(uploadSelector('picker'));
  await expect(picker).toBeEnabled();

  await picker.setInputFiles([
    { name: 'oriented-camera.jpg', mimeType: 'image/jpeg', buffer: jpeg },
    { name: 'transparent-floor.png', mimeType: 'image/png', buffer: png }
  ]);
  await expect(form.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(2);

  const itemPosts = () => requests.filter(entry => entry.method === 'POST' && /\/items\/[^/]+$/.test(uploadRoute(entry.url)));
  expect(itemPosts()).toHaveLength(2);
  expect(itemPosts().every(entry => entry.headers['content-type'].startsWith('multipart/form-data; boundary='))).toBeTruthy();
  expect(itemPosts().every(entry => uploadRoute(entry.url).split('/').some(segment => batchIdPattern.test(segment)))).toBeTruthy();
  expect(requests.every(entry => batchSecretPattern.test(entry.headers[batchSecretHeader] || ''))).toBeTruthy();

  // Send a malformed field through the real navigation so server validation,
  // rather than HTML constraint validation, owns the rerender.
  const name = form.locator('input[name="upload-test[name]"]');
  await name.evaluate(input => {
    input.name = 'upload-test[name][]';
    input.value = 'invalid-type';
    input.form.noValidate = true;
  });
  await form.evaluate(node => {
    window.__eformsOriginalLiveForm = node;
    window.__eformsOriginalLiveCards = Array.from(node.querySelectorAll('[data-eforms-upload-state="uploaded"]'));
  });
  const pageBeforeRecovery = page.url();
  await form.locator('button[type="submit"]').click();

  await expect(form.locator('.eforms-error-summary')).toBeVisible();
  await expect(form.locator('[data-eforms-upload-state="uploaded"]')).toHaveCount(2);
  expect(page.url()).toBe(pageBeforeRecovery);
  expect(await form.evaluate(node => node === window.__eformsOriginalLiveForm)).toBeTruthy();
  expect(await form.evaluate(node => Array.from(node.querySelectorAll('[data-eforms-upload-state="uploaded"]')).every((card, index) => card === window.__eformsOriginalLiveCards[index]))).toBeTruthy();
  expect(itemPosts()).toHaveLength(2);
  expect(requests.some(entry => entry.method === 'GET' && /\/items\/[^/]+\/preview$/.test(uploadRoute(entry.url)))).toBeFalsy();

  await name.evaluate(input => { input.name = 'upload-test[name]'; });
  await form.locator('input[name="upload-test[name]"]').fill('Ada Lovelace');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    form.locator('button[type="submit"]').click()
  ]);
  await expect(page.locator('[data-eforms-result="success"]')).toBeVisible();
  expect(itemPosts()).toHaveLength(2);
});
