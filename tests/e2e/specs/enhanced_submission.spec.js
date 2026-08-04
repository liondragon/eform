const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../../..');
const formsScript = path.join(repoRoot, 'assets/forms.js');
const formsStyles = path.join(repoRoot, 'assets/forms.css');
const uploadStyles = path.join(repoRoot, 'assets/upload.css');
const protocolPhp = path.join(repoRoot, 'src/FormProtocol.php');
const bootstrapPhp = path.join(repoRoot, 'tests/bootstrap.php');
const formRendererPhp = path.join(repoRoot, 'src/Rendering/FormRenderer.php');
const browserProtocol = JSON.parse(execFileSync('php', ['-r', `require ${JSON.stringify(protocolPhp)}; echo json_encode(FormProtocol::browser_settings());`], { encoding: 'utf8' }));
const hiddenNames = browserProtocol.hiddenFields;
const uploadNames = browserProtocol.upload;
const uploadAttrs = uploadNames.dataAttributes;
const enhanced = browserProtocol.enhancedResponse;
const response = enhanced.response;
const endpoint = 'https://example.test/eforms/upload-batches';
const submitEndpoint = 'https://example.test/form-submit';
const batchId = 'B'.repeat(uploadNames.runtime.batchIdChars);
const batchSecret = 'S'.repeat(Math.ceil(uploadNames.runtime.batchSecretBytes * 8 / 6));

async function addFormStyles(page) {
  await page.addStyleTag({ path: formsStyles });
  await page.addStyleTag({ path: uploadStyles });
}

function renderVirtualEstimateMarkup() {
  return execFileSync('php', ['-r', `require ${JSON.stringify(bootstrapPhp)}; require ${JSON.stringify(formRendererPhp)}; FormRenderer::reset_for_tests(); echo FormRenderer::render('virtual-estimate', array('cacheable' => true));`], { encoding: 'utf8' });
}

function renderQuoteRequestMarkup() {
  return execFileSync('php', ['-r', `require ${JSON.stringify(bootstrapPhp)}; require ${JSON.stringify(formRendererPhp)}; FormRenderer::reset_for_tests(); echo FormRenderer::render('quote-request', array('cacheable' => true));`], { encoding: 'utf8' });
}

function formMarkup(options = {}) {
  const uploadCredentials = options.uploadCredentials === false ? '' : `
      <input type="hidden" name="${uploadNames.batchFields.root}[photos][${uploadNames.batchFields.batch_id}]" value="${batchId}">
      <input type="hidden" name="${uploadNames.batchFields.root}[photos][${uploadNames.batchFields.batch_secret}]" value="${batchSecret}">`;
  return `
    <form class="eforms-form eforms-form-enhanced eforms-row" action="${submitEndpoint}" method="post" ${browserProtocol.dataAttributes.mode}="hidden">
      <input type="hidden" name="${hiddenNames.mode}" value="hidden">
      <input type="hidden" name="${hiddenNames.token}" value="token">
      <input type="hidden" name="${hiddenNames.instance_id}" value="instance">
      <input type="hidden" name="${hiddenNames.timestamp}" value="1700000000">
      <input type="hidden" name="${hiddenNames.js_ok}" value="">
      ${uploadCredentials}
      <label for="name">Name</label>
      <input id="name" name="enhanced[name]" ${browserProtocol.dataAttributes.field_key}="name" ${browserProtocol.dataAttributes.field_control}="1">
      <label for="email">Email address</label>
      <input id="email" type="email" name="enhanced[email]" ${browserProtocol.dataAttributes.field_key}="email" ${browserProtocol.dataAttributes.field_control}="1">
      <label for="phone">Phone number</label>
      <input id="phone" type="tel" inputmode="tel" name="enhanced[phone]" ${browserProtocol.dataAttributes.field_key}="phone" ${browserProtocol.dataAttributes.field_control}="1" ${browserProtocol.dataAttributes.phone_format}="tel_us">
      <label for="zip">Project ZIP code</label>
      <input id="zip" type="text" inputmode="numeric" name="enhanced[zip]" ${browserProtocol.dataAttributes.field_key}="zip" ${browserProtocol.dataAttributes.field_control}="1" ${browserProtocol.dataAttributes.zip_format}="zip_us">
      <label for="area">Approximate square footage</label>
      <input id="area" type="text" inputmode="decimal" name="enhanced[area]" ${browserProtocol.dataAttributes.field_key}="area" ${browserProtocol.dataAttributes.field_control}="1" ${browserProtocol.dataAttributes.integer_format}="1" ${browserProtocol.dataAttributes.input_unit}="sqft">
      <label for="listing">Real-estate listing URL</label>
      <input id="listing" type="url" name="enhanced[listing]" ${browserProtocol.dataAttributes.field_key}="listing" ${browserProtocol.dataAttributes.field_control}="1" ${browserProtocol.dataAttributes.url_normalize}="1">
      <input id="photos" type="file" disabled ${uploadAttrs.picker}="1">
      <div ${uploadAttrs.mount}="1" ${uploadAttrs.pickerId}="photos" ${uploadAttrs.field}="photos" ${uploadAttrs.accept}=".png" ${uploadAttrs.maxFiles}="2" ${uploadAttrs.maxFileBytes}="100" ${uploadAttrs.maxTotalBytes}="200"></div>
      <div ${browserProtocol.dataAttributes.challenge_mount}="turnstile" hidden></div>
      <button type="submit">Send</button>
    </form>`;
}

function uploadResponse(state = 'open') {
  return {
    [uploadNames.response.batchId]: batchId,
    [uploadNames.response.state]: state,
    [uploadNames.response.acceptUntil]: Math.floor(Date.now() / 1000) + 3600,
    [uploadNames.response.deleteAfter]: Math.floor(Date.now() / 1000) + 7200,
    [uploadNames.response.items]: [],
    [uploadNames.response.intents]: [],
    [uploadNames.response.limits]: {
      [uploadNames.response.maxFileBytes]: 100,
      [uploadNames.response.maxFiles]: 2,
      [uploadNames.response.maxTotalBytes]: 200
    }
  };
}

async function boot(page, markup = formMarkup()) {
  await page.route(`${endpoint}/**`, route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadResponse()) }));
  await page.setContent(`<main>${markup}</main>`);
  await page.evaluate(({ uploadBatchEndpoint, protocol }) => {
    window.eformsSettings = { uploadBatchEndpoint, protocol };
  }, { uploadBatchEndpoint: endpoint, protocol: browserProtocol });
  await addFormStyles(page);
  await page.addScriptTag({ path: formsScript });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
}

async function bootAt(page, url, markup = formMarkup()) {
  await page.route(`${endpoint}/**`, route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadResponse()) }));
  await page.route(url, route => route.fulfill({ status: 200, contentType: 'text/html', body: `<main>${markup}</main>` }));
  await page.goto(url);
  await page.evaluate(({ uploadBatchEndpoint, protocol }) => {
    window.eformsSettings = { uploadBatchEndpoint, protocol };
  }, { uploadBatchEndpoint: endpoint, protocol: browserProtocol });
  await addFormStyles(page);
  await page.addScriptTag({ path: formsScript });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
}

async function bootRenderedVirtualEstimate(page) {
  await page.route(`${endpoint}/**`, route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadResponse()) }));
  await page.route('https://example.test/eforms/mint', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ token: 'token', instance_id: 'instance', timestamp: 1700000000, expires: Math.floor(Date.now() / 1000) + 3600 })
  }));
  await page.setContent(`<main>${renderVirtualEstimateMarkup()}</main>`);
  await page.locator('form.eforms-form-virtual-estimate').evaluate((form, action) => {
    form.setAttribute('action', action);
  }, submitEndpoint);
  await page.evaluate(({ mintEndpoint, uploadBatchEndpoint, protocol }) => {
    window.eformsSettings = { mintEndpoint, uploadBatchEndpoint, protocol };
  }, { mintEndpoint: 'https://example.test/eforms/mint', uploadBatchEndpoint: endpoint, protocol: browserProtocol });
  await addFormStyles(page);
  await page.addScriptTag({ path: formsScript });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
  await expect(page.locator('form.eforms-form-virtual-estimate button[type="submit"]')).toBeEnabled();
}

async function bootRenderedQuoteRequest(page) {
  await page.route('https://example.test/eforms/mint', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ token: 'token', instance_id: 'instance', timestamp: 1700000000, expires: Math.floor(Date.now() / 1000) + 3600 })
  }));
  await page.setContent(`<main id="footer_cta">${renderQuoteRequestMarkup()}</main>`);
  await page.evaluate(({ mintEndpoint, protocol }) => {
    window.eformsSettings = { mintEndpoint, protocol };
  }, { mintEndpoint: 'https://example.test/eforms/mint', protocol: browserProtocol });
  await addFormStyles(page);
  await page.addScriptTag({ path: formsScript });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
}

async function pasteInto(locator, text) {
  await locator.evaluate((node, value) => {
    const event = new Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'clipboardData', {
      value: { getData: () => value }
    });
    if (!node.dispatchEvent(event)) {
      return;
    }
    node.value = value;
    node.dispatchEvent(new Event('input', { bubbles: true }));
  }, text);
}

async function enterAndBlur(locator, text) {
  await locator.evaluate((node, value) => {
    node.focus();
    node.value = value;
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
    node.blur();
    node.dispatchEvent(new Event('blur', { bubbles: true }));
  }, text);
}

async function fulfillJson(route, status, body) {
  await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
}

function correctablePayload(fields = {}, recoveryState = response.open, challenge = null, global = []) {
  return {
    [response.ok]: false,
    [response.errors]: { [response.global]: global, [response.fields]: fields },
    [response.uploadRecovery]: recoveryState === null ? null : { [response.state]: recoveryState },
    [response.challenge]: challenge
  };
}

async function routeSubmit(page, fulfill) {
  const state = { count: 0, body: '' };
  await page.route(submitEndpoint, async route => {
    state.count += 1;
    state.body = route.request().postData() || '';
    await fulfill(route, state);
  });
  return state;
}

async function installNativeValidationProbe(form) {
  await form.evaluate(formNode => {
    window.__eformsNativeValidationProbe = { invalidIds: [], submitEvents: 0 };
    formNode.addEventListener('invalid', event => {
      window.__eformsNativeValidationProbe.invalidIds.push(event.target.id);
    }, true);
    formNode.addEventListener('submit', () => {
      window.__eformsNativeValidationProbe.submitEvents += 1;
    }, true);
  });
}

async function readNativeValidationState(form) {
  return form.evaluate(formNode => ({
    noValidate: formNode.noValidate,
    activeElementId: document.activeElement ? document.activeElement.id : '',
    summaryCount: formNode.querySelectorAll('.eforms-error-summary').length,
    probe: window.__eformsNativeValidationProbe
  }));
}

async function fillRenderedVirtualEstimateScalars(form) {
  await form.locator('#virtual-estimate-name').fill('Ada Lovelace');
  await form.locator('#virtual-estimate-email').fill('ada@example.test');
  await form.locator('#virtual-estimate-tel_us').fill('7209005278');
  await form.locator('#virtual-estimate-zip_us').fill('80231');
  await form.locator('#virtual-estimate-square_footage').fill('1145');
  await form.locator('#virtual-estimate-project_description').fill('Refinish the main floor.');
}

async function seedUploadedRuntimeCard(mount) {
  await mount.evaluate(node => {
    const runtime = node.__eformsUploadRuntime;
    const card = document.createElement('article');
    const image = document.createElement('img');
    const name = document.createElement('span');
    const progress = document.createElement('div');
    const status = document.createElement('span');
    const actions = document.createElement('div');
    const retry = document.createElement('button');
    const remove = document.createElement('button');
    card.className = 'eforms-upload-item';
    card.setAttribute('data-test-card', '1');
    actions.append(retry);
    card.append(image, name, progress, status, actions, remove);
    runtime.grid.appendChild(card);
    runtime.items.push({
      id: 'card', ordinal: 0, file: null, sourceFile: null, name: 'photo.png', bytes: 1,
      artifactChosen: true, objectUrl: '', previewUnavailable: true, state: 'uploaded', progress: 100,
      error: '', xhr: null, transferController: null, controlRequest: null, transportKind: '',
      removalInFlight: false, preparationAttempt: 0, previewRequest: 0,
      card, image, nameNode: name, progressNode: progress, statusNode: status, actionsNode: actions, retryButton: retry, removeButton: remove
    });
    runtime.nextOrdinal = 1;
    window.__enhancedCard = card;
  });
}

test('tel_us fields format for display but submit digits only', async ({ page }) => {
  await boot(page);
  const submit = await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload()));

  const form = page.locator('form.eforms-form-enhanced');
  const phone = form.locator('#phone');
  await phone.fill('7209005278');
  await expect(phone).toHaveValue('(720) 900-5278');
  await phone.fill('');
  await pasteInto(phone, '+1 720-900-5278');
  await expect(phone).toHaveValue('(720) 900-5278');
  await pasteInto(phone, '+17209005278fds');
  await expect(phone).toHaveValue('(720) 900-5278');

  await form.locator('button[type="submit"]').click();
  expect(submit.body).toContain('7209005278');
  expect(submit.body).not.toContain('(720) 900-5278');
  await expect(phone).toHaveValue('(720) 900-5278');
});

test('tel_us formatter rejects overlong input without truncating submitted value', async ({ page }) => {
  await boot(page);
  const submit = await routeSubmit(page, route => route.fulfill({ status: 204 }));

  const form = page.locator('form.eforms-form-enhanced');
  const phone = form.locator('#phone');
  await phone.fill('720900527812');

  await expect(phone).toHaveValue('720900527812');
  expect(await phone.evaluate(node => node.checkValidity())).toBe(false);
  await form.locator('button[type="submit"]').click();
  expect(submit.count).toBe(0);
});

test('tel_us formatter rejects punctuation-only input without clearing it', async ({ page }) => {
  await boot(page);
  const submit = await routeSubmit(page, route => route.fulfill({ status: 204 }));

  const form = page.locator('form.eforms-form-enhanced');
  const phone = form.locator('#phone');
  await phone.fill('---');

  await expect(phone).toHaveValue('---');
  expect(await phone.evaluate(node => node.checkValidity())).toBe(false);
  await form.locator('button[type="submit"]').click();
  expect(submit.count).toBe(0);
  await expect(phone).toHaveValue('---');
});

test('tel_us formatter preserves server-rendered invalid state during startup', async ({ page }) => {
  const markup = formMarkup()
    .replace(
      '<input id="phone" type="tel"',
      '<input id="phone" type="tel" value="" aria-invalid="true"'
    );
  await boot(page, markup);

  const phone = page.locator('#phone');
  await expect(phone).toHaveAttribute('aria-invalid', 'true');
  await expect(phone).not.toHaveAttribute('aria-describedby', /.+/);
});

test('client validation respects server-rendered novalidate', async ({ page }) => {
  await boot(page, formMarkup().replace('<form ', '<form novalidate '));
  const submit = await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload()));

  const form = page.locator('form.eforms-form-enhanced');
  await expect(form).toHaveAttribute('data-eforms-client-validation', '1');
  expect(await form.evaluate(formNode => formNode.noValidate)).toBe(true);
  await form.locator('#name').evaluate(node => {
    node.required = true;
    node.value = '';
  });
  await form.locator('button[type="submit"]').click();
  expect(submit.count).toBe(1);
});

test('client-side required fields use native validation before submit handlers', async ({ page }) => {
  await boot(page);
  const submit = await routeSubmit(page, route => route.fulfill({ status: 204 }));

  const form = page.locator('form.eforms-form-enhanced');
  await installNativeValidationProbe(form);
  await form.locator('#name').evaluate(node => {
    node.required = true;
    node.value = '';
  });
  await form.locator('button[type="submit"]').click();
  const state = await readNativeValidationState(form);
  expect(state.noValidate).toBe(false);
  expect(state.probe.submitEvents).toBe(0);
  expect(state.probe.invalidIds[0]).toBe('name');
  expect(state.activeElementId).toBe('name');
  expect(state.summaryCount).toBe(0);
  expect(submit.count).toBe(0);
});

test('rendered virtual-estimate uses native required popover before submit handlers', async ({ page }) => {
  await bootRenderedVirtualEstimate(page);
  const submit = await routeSubmit(page, route => route.fulfill({ status: 204 }));

  const form = page.locator('form.eforms-form-virtual-estimate');
  await installNativeValidationProbe(form);
  const stateBefore = await form.evaluate(formNode => ({
    noValidate: formNode.noValidate,
    clientValidation: formNode.getAttribute('data-eforms-client-validation'),
    enhancedHandler: formNode.getAttribute('data-eforms-enhanced-handler'),
    fieldControls: formNode.querySelectorAll('[data-eforms-field-control="1"]').length,
    phoneFormat: formNode.querySelector('#virtual-estimate-tel_us')?.getAttribute('data-eforms-phone-format') || '',
    zipFormat: formNode.querySelector('#virtual-estimate-zip_us')?.getAttribute('data-eforms-zip-format') || '',
    integerFormat: formNode.querySelector('#virtual-estimate-square_footage')?.getAttribute('data-eforms-integer-format') || '',
    urlNormalize: formNode.querySelector('#virtual-estimate-listing_url')?.getAttribute('data-eforms-url-normalize') || ''
  }));

  await expect(form.locator('.eforms-upload-field-status')).toHaveText('');
  await form.locator('button[type="submit"]').click();
  await expect(form.locator('.eforms-upload-field-status')).toHaveText('');

  const stateAfter = await readNativeValidationState(form);

  expect(stateBefore).toMatchObject({
    noValidate: false,
    clientValidation: '1',
    enhancedHandler: '1',
    fieldControls: 8,
    phoneFormat: 'tel_us',
    zipFormat: 'zip_us',
    integerFormat: '1',
    urlNormalize: '1'
  });
  expect(stateAfter.probe.submitEvents).toBe(0);
  expect(stateAfter.probe.invalidIds[0]).toBe('virtual-estimate-name');
  expect(stateAfter.activeElementId).toBe('virtual-estimate-name');
  expect(stateAfter.summaryCount).toBe(0);
  expect(submit.count).toBe(0);
});

test('rendered virtual-estimate shows the listing-or-photo rule after authoritative validation', async ({ page }) => {
  await bootRenderedVirtualEstimate(page);
  const message = 'Please provide a listing URL or upload at least one photo.';
  const submit = await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload({}, null, null, [{
    [response.code]: 'EFORMS_ERR_ONE_OF_REQUIRED',
    [response.message]: message
  }])));

  const form = page.locator('form.eforms-form-virtual-estimate');
  await expect(form.locator('.eforms-upload-field-status')).toHaveText('');
  await fillRenderedVirtualEstimateScalars(form);
  await form.locator('button[type="submit"]').click();

  expect(submit.count).toBe(1);
  await expect(form.locator('.eforms-upload-field-status')).toHaveText('');
  await expect(form.locator('.eforms-error-summary')).toContainText(message);
  await expect(form.locator('button[type="submit"]')).toHaveText('Send Estimate Request');
});

test('rendered virtual-estimate submits a listing URL without photos', async ({ page }) => {
  await bootRenderedVirtualEstimate(page);
  const submit = await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload({}, null)));

  const form = page.locator('form.eforms-form-virtual-estimate');
  await fillRenderedVirtualEstimateScalars(form);
  await form.locator('#virtual-estimate-listing_url').fill('https://example.com/listing');
  await form.locator('button[type="submit"]').click();

  expect(submit.count).toBe(1);
  expect(submit.body).toContain('https://example.com/listing');
  await expect(form.locator('.eforms-upload-field-status')).toHaveText('');
});

test('quote-request grouped inputs do not receive plugin-owned focus outlines', async ({ page }) => {
  await page.setContent(`<main id="footer_cta">${renderQuoteRequestMarkup()}</main>`);
  await addFormStyles(page);

  for (const selector of ['#quote-request-tel_us', '#quote-request-zip_us']) {
    const control = page.locator(selector);
    await control.focus();
    const focusStyle = await control.evaluate(node => {
      const style = getComputedStyle(node);
      return {
        outlineColor: style.outlineColor,
        outlineOffset: style.outlineOffset,
        outlineStyle: style.outlineStyle,
        outlineWidth: style.outlineWidth
      };
    });
    expect(focusStyle).not.toMatchObject({
      outlineColor: 'rgb(184, 184, 184)',
      outlineOffset: '-1px',
      outlineStyle: 'solid',
      outlineWidth: '1px'
    });
    expect(focusStyle).not.toMatchObject({
      outlineColor: 'rgb(29, 78, 216)',
      outlineOffset: '2px',
      outlineStyle: 'solid',
      outlineWidth: '2px'
    });
  }
});

test('rendered quote-request email uses shared native invalid icon on blur', async ({ page }) => {
  await bootRenderedQuoteRequest(page);

  const form = page.locator('form.eforms-form-quote-request');
  const email = form.locator('#quote-request-email');
  await enterAndBlur(email, 'bad-email');

  expect(await email.evaluate(node => node.validity.valid)).toBe(false);
  await expect(email).toHaveAttribute('aria-invalid', 'true');
  expect(await email.evaluate(node => {
    const style = getComputedStyle(node);
    return {
      backgroundImage: style.backgroundImage,
      boxShadow: style.boxShadow
    };
  })).toMatchObject({
    boxShadow: 'none'
  });
  expect(await email.evaluate(node => getComputedStyle(node).backgroundImage)).not.toBe('none');
  await expect(form.locator('.eforms-error-summary')).toHaveCount(0);
});

test('tel_us fields require a complete 10-digit phone number', async ({ page }) => {
  await boot(page);
  const submit = await routeSubmit(page, route => route.fulfill({ status: 204 }));

  const form = page.locator('form.eforms-form-enhanced');
  await installNativeValidationProbe(form);
  const phone = form.locator('#phone');
  await phone.fill('72090');
  await expect(phone).toHaveValue('(720) 90');
  await form.locator('button[type="submit"]').click();
  const state = await readNativeValidationState(form);
  expect(submit.count).toBe(0);
  expect(state.probe.submitEvents).toBe(0);
  expect(state.probe.invalidIds[0]).toBe('phone');
  expect(state.summaryCount).toBe(0);
  await expect(phone).toHaveJSProperty('validationMessage', 'Enter a valid 10-digit phone number.');
});

test('native email and URL fields expose shared invalid state on blur', async ({ page }) => {
  await boot(page);

  const form = page.locator('form.eforms-form-enhanced');
  const email = form.locator('#email');
  const listing = form.locator('#listing');

  await enterAndBlur(email, 'bad-email');
  expect(await email.evaluate(node => node.validity.valid)).toBe(false);
  await expect(email).toHaveAttribute('aria-invalid', 'true');
  expect(await email.evaluate(node => {
    const style = getComputedStyle(node);
    return {
      backgroundImage: style.backgroundImage,
      borderColor: style.borderColor,
      boxShadow: style.boxShadow
    };
  })).toMatchObject({
    boxShadow: 'none'
  });
  expect(await email.evaluate(node => getComputedStyle(node).backgroundImage)).not.toBe('none');
  expect(await email.evaluate(node => getComputedStyle(node).borderColor)).not.toBe('rgb(196, 71, 61)');
  await expect(form.locator('.eforms-error-summary')).toHaveCount(0);

  await enterAndBlur(email, 'ada@example.test');
  expect(await email.evaluate(node => node.validity.valid)).toBe(true);
  await expect(email).not.toHaveAttribute('aria-invalid', /.+/);

  await enterAndBlur(listing, 'not a url');
  await expect(listing).toHaveValue('not a url');
  expect(await listing.evaluate(node => node.validity.valid)).toBe(false);
  await expect(listing).toHaveAttribute('aria-invalid', 'true');

  await enterAndBlur(listing, 'zillow.com/homedetails/123');
  await expect(listing).toHaveValue('https://zillow.com/homedetails/123');
  expect(await listing.evaluate(node => node.validity.valid)).toBe(true);
  await expect(listing).not.toHaveAttribute('aria-invalid', /.+/);
});

test('empty required native email stays invalid after blocked submit and blur', async ({ page }) => {
  await boot(page);
  const submit = await routeSubmit(page, route => route.fulfill({ status: 204 }));

  const form = page.locator('form.eforms-form-enhanced');
  const email = form.locator('#email');
  await installNativeValidationProbe(form);
  await email.evaluate(node => {
    node.required = true;
    node.value = '';
  });

  await form.locator('button[type="submit"]').click();
  expect(await email.evaluate(node => node.validity.valueMissing)).toBe(true);
  await expect(email).toHaveAttribute('aria-invalid', 'true');
  expect((await readNativeValidationState(form)).probe.invalidIds[0]).toBe('email');

  await enterAndBlur(email, '');
  expect(await email.evaluate(node => node.validity.valueMissing)).toBe(true);
  await expect(email).toHaveAttribute('aria-invalid', 'true');
  expect(submit.count).toBe(0);

  await enterAndBlur(email, 'ada@example.test');
  expect(await email.evaluate(node => node.validity.valid)).toBe(true);
  await expect(email).not.toHaveAttribute('aria-invalid', /.+/);
});

test('structured scalar fields display kindly but submit canonical values', async ({ page }) => {
  await boot(page);
  const submit = await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload()));

  const form = page.locator('form.eforms-form-enhanced');
  const zip = form.locator('#zip');
  const area = form.locator('#area');
  const listing = form.locator('#listing');
  await pasteInto(zip, '80231-1234');
  await expect(zip).toHaveValue('80231');
  await area.fill('');
  await area.pressSequentially('57457474577457');
  await expect(area).toHaveValue('57,457,474,577,457');
  for (let i = 0; i < 12; i += 1) await area.press('Backspace');
  await expect(area).toHaveValue('57');
  await pasteInto(area, '1200');
  await area.blur();
  await expect(area).toHaveValue('1,200');
  const unitGap = await area.evaluate(node => {
    const wrapper = node.parentNode;
    const measure = wrapper.querySelector('.eforms-input-unit-measure');
    const unit = wrapper.querySelector('.eforms-input-unit');
    const styles = getComputedStyle(node);
    return Math.round(unit.getBoundingClientRect().left - node.getBoundingClientRect().left - (parseFloat(styles.paddingLeft) || 0) - measure.offsetWidth);
  });
  expect(unitGap).toBeGreaterThanOrEqual(3);
  expect(unitGap).toBeLessThanOrEqual(8);
  await listing.fill('zillow.com/homedetails/123');
  await listing.blur();
  await expect(listing).toHaveValue('https://zillow.com/homedetails/123');

  await form.locator('button[type="submit"]').click();
  expect(submit.body).toContain('name="enhanced[zip]"');
  expect(submit.body).toContain('80231');
  expect(submit.body).toContain('name="enhanced[area]"');
  expect(submit.body).toContain('1200');
  expect(submit.body).not.toContain('1,200');
  expect(submit.body).toContain('name="enhanced[listing]"');
  expect(submit.body).toContain('https://zillow.com/homedetails/123');
  await expect(area).toHaveValue('1,200');
});

test('focused schemeless URL normalizes before native submit validation', async ({ page }) => {
  await boot(page);
  const submit = await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload()));

  const form = page.locator('form.eforms-form-enhanced');
  const listing = form.locator('#listing');
  await listing.fill('zillow.com/homedetails/123');
  await listing.press('Enter');

  expect(submit.count).toBe(1);
  expect(submit.body).toContain('name="enhanced[listing]"');
  expect(submit.body).toContain('https://zillow.com/homedetails/123');
  await expect(listing).toHaveValue('https://zillow.com/homedetails/123');
});

test('malformed friendly scalar values stay invalid on enhanced submit', async ({ page }) => {
  await boot(page, formMarkup().replace('<form ', '<form novalidate '));
  const submit = await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload()));

  const form = page.locator('form.eforms-form-enhanced');
  await form.locator('#phone').evaluate(node => { node.value = '212+5551212'; });
  await form.locator('#zip').evaluate(node => { node.value = '80231 1234'; });
  await form.locator('#area').evaluate(node => { node.value = '12 34'; });
  await form.locator('button[type="submit"]').click();

  expect(submit.count).toBe(1);
  expect(submit.body).toContain('212+5551212');
  expect(submit.body).toContain('80231 1234');
  expect(submit.body).toContain('12 34');
});

test('tel_us autofill display syncs when native validation blocks submit', async ({ page }) => {
  await boot(page);
  const form = page.locator('form.eforms-form-enhanced');
  const phone = form.locator('#phone');
  const name = form.locator('#name');
  await name.evaluate(node => {
    node.required = true;
    node.value = '';
  });
  await phone.evaluate(node => {
    node.value = '+17203667181';
  });

  await form.locator('button[type="submit"]').click();
  await expect(phone).toHaveValue('(720) 366-7181');
});

test('required staged photos block final submit when empty', async ({ page }) => {
  await boot(page);
  const submit = await routeSubmit(page, route => route.fulfill({ status: 204 }));

  const form = page.locator('form.eforms-form-enhanced');
  const mount = form.locator(`[${uploadAttrs.mount}="1"]`);
  await mount.evaluate(node => {
    node.__eformsUploadRuntime.required = true;
  });
  await form.locator('button[type="submit"]').click();
  expect(submit.count).toBe(0);
  await expect(mount.locator('.eforms-upload-field-status')).toHaveText('Add at least one photo.');
  await expect(form.locator('button[type="submit"]')).toHaveText('Send');
});

test('retry-safe enhanced failures re-enable the photo picker', async ({ page }) => {
  await boot(page);
  await routeSubmit(page, route => fulfillJson(route, 503, {
    [response.ok]: false,
    [response.error]: { [response.code]: 'EFORMS_ERR_STORAGE_UNAVAILABLE', [response.message]: 'Your request couldn\'t be sent. Please try again.' },
    [response.canRetry]: true,
    [response.location]: null
  }));

  const form = page.locator('form.eforms-form-enhanced');
  const choose = form.locator('.eforms-upload-choose');
  await expect(choose).toBeEnabled();
  await form.locator('button[type="submit"]').click();
  await expect(form.locator('.eforms-error-summary')).toContainText('Your request couldn\'t be sent. Please try again.');
  await expect(choose).toBeEnabled();
});

test('enhanced staged submission preserves uploader nodes through 422 recovery', async ({ page }) => {
  let statusRequests = 0;
  await boot(page);
  const submit = await routeSubmit(page, route => {
    expect(route.request().headers()[enhanced.header.toLowerCase()]).toBe(enhanced.value);
    return fulfillJson(route, 422, correctablePayload({
      name: [{ [response.code]: 'EFORMS_ERR_FIELD_REQUIRED', [response.message]: 'Please complete Name.' }]
    }));
  });
  page.on('request', request => {
    if (request.url().startsWith(`${endpoint}/`)) statusRequests += 1;
  });

  const form = page.locator('form.eforms-form-enhanced');
  const mount = form.locator(`[${uploadAttrs.mount}="1"]`);
  await seedUploadedRuntimeCard(mount);

  await form.locator('button[type="submit"]').click();
  await expect(form.locator('.eforms-error-summary')).toContainText('Please fix the highlighted fields.');
  await expect(form.locator('.eforms-error-summary')).toContainText('Please complete Name.');
  await expect(form.locator('.eforms-error-summary')).not.toContainText('Please complete this field.');
  await expect(form.locator('.eforms-field-error')).toHaveCount(0);
  await expect(form.locator('.eforms-error-summary #error-name')).toContainText('Please complete Name.');
  await expect(form.locator('.eforms-error-summary #error-name')).toHaveAttribute(browserProtocol.dataAttributes.field_error_mount, '1');
  expect(await form.locator('#name').evaluate(node => getComputedStyle(node).backgroundImage)).toContain('data:image/svg+xml');
  await expect(form.locator('#name')).toHaveAttribute('aria-invalid', 'true');
  await expect(form.locator('#name')).toHaveAttribute('aria-describedby', 'error-name');
  await expect(form.locator('button[type="submit"]')).toBeEnabled();
  await expect(form.locator('button[type="submit"] .eforms-spinner')).toHaveCount(0);
  await expect(form).not.toHaveAttribute('data-eforms-enhanced-pending', '1');
  expect(await mount.evaluate(node => node.querySelector('[data-test-card="1"]') === window.__enhancedCard)).toBeTruthy();
  expect(statusRequests).toBe(0);
  expect(submit.count).toBe(1);
});

test('correctable 422 with null recovery keeps field errors sticky', async ({ page }) => {
  await boot(page);
  await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload({
    name: [{ [response.code]: 'EFORMS_ERR_FIELD_REQUIRED', [response.message]: 'Please complete Name.' }]
  }, null)));

  const form = page.locator('form.eforms-form-enhanced');
  await form.locator('button[type="submit"]').click();

  await expect(form.locator('.eforms-error-summary')).toContainText('Please fix the highlighted fields.');
  await expect(form.locator('.eforms-error-summary')).toContainText('Please complete Name.');
  await expect(form.locator('.eforms-error-summary')).not.toContainText('Your request couldn\'t be sent.');
  await expect(form.locator('.eforms-error-summary')).not.toContainText('This request can\'t be finished from this page.');
  await expect(form.locator('.eforms-field-error')).toHaveCount(0);
  await expect(form.locator('.eforms-error-summary #error-name')).toContainText('Please complete Name.');
  await expect(form.locator('#name')).toHaveAttribute('aria-invalid', 'true');
  await expect(form.locator('#name')).toHaveAttribute('aria-describedby', 'error-name');
  await expect(form.locator('button[type="submit"]')).toBeEnabled();
  await expect(form).not.toHaveAttribute('data-eforms-enhanced-pending', '1');
});

test('correctable 422 with no upload batch keeps picker usable', async ({ page }) => {
  await boot(page, formMarkup({ uploadCredentials: false }));
  await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload({
    name: [{ [response.code]: 'EFORMS_ERR_FIELD_REQUIRED', [response.message]: 'Please complete Name.' }]
  }, null)));

  const form = page.locator('form.eforms-form-enhanced');
  const picker = form.locator('#photos');
  await form.locator('button[type="submit"]').click();

  await expect(form.locator('.eforms-error-summary')).toContainText('Please complete Name.');
  await expect(form.locator(`[${uploadAttrs.mount}="1"]`)).not.toHaveAttribute('data-eforms-upload-frozen', '1');
  await expect(picker).toBeEnabled();
  await expect(form.locator('button[type="submit"]')).toBeEnabled();
});

test('enhanced field errors name the field and known concern without moving layout', async ({ page }) => {
  await boot(page);
  await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload({
    phone: [{ [response.code]: 'EFORMS_ERR_FIELD_INVALID', [response.message]: 'Phone number must be a valid phone number.' }]
  })));

  const form = page.locator('form.eforms-form-enhanced');
  const phone = form.locator('#phone');
  const phoneBoxBefore = await phone.boundingBox();
  await form.locator('button[type="submit"]').click();
  const phoneBoxAfter = await phone.boundingBox();
  await expect(form.locator('.eforms-error-summary')).toContainText('Please fix the highlighted fields.');
  await expect(form.locator('.eforms-error-summary')).toContainText('Phone number must be a valid phone number.');
  await expect(form.locator('.eforms-error-summary')).not.toContainText('Please check this field.');
  await expect(form.locator('.eforms-field-error')).toHaveCount(0);
  await expect(form.locator('.eforms-error-summary #error-phone')).toContainText('Phone number must be a valid phone number.');
  expect(await phone.evaluate(node => getComputedStyle(node).backgroundImage)).toContain('data:image/svg+xml');
  await expect(phone).toHaveAttribute('aria-describedby', 'error-phone');
  await expect(form.locator('button[type="submit"] .eforms-spinner')).toHaveCount(0);
  await expect(form).not.toHaveAttribute('data-eforms-enhanced-pending', '1');
  expect(phoneBoxAfter.width).toBe(phoneBoxBefore.width);
});

test('valid phone edits clear stale server invalid ARIA state', async ({ page }) => {
  await boot(page);
  await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload({
    phone: [{ [response.code]: 'EFORMS_ERR_FIELD_INVALID', [response.message]: 'Phone number must be a valid phone number.' }]
  })));

  const form = page.locator('form.eforms-form-enhanced');
  const phone = form.locator('#phone');
  await form.locator('button[type="submit"]').click();
  await expect(phone).toHaveAttribute('aria-invalid', 'true');
  await expect(phone).toHaveAttribute('aria-describedby', 'error-phone');

  await phone.fill('7209005278');
  await expect(phone).toHaveValue('(720) 900-5278');
  await expect(phone).not.toHaveAttribute('aria-invalid', /.+/);
  await expect(phone).not.toHaveAttribute('aria-describedby', /.+/);
});

test('bfcache restore clears enhanced-only pending lock', async ({ page }) => {
  await boot(page);

  const restored = await page.locator('form.eforms-form-enhanced').evaluate(form => {
    const button = form.querySelector('button[type="submit"]');
    form.setAttribute('data-eforms-enhanced-pending', '1');
    form.setAttribute('data-eforms-enhanced-navigating', '1');
    button.disabled = true;
    button.setAttribute('data-eforms-enhanced-disabled', '1');
    const spinner = document.createElement('span');
    spinner.className = 'eforms-spinner';
    spinner.setAttribute('data-eforms-enhanced-spinner', '1');
    button.appendChild(spinner);
    const event = new Event('pageshow');
    Object.defineProperty(event, 'persisted', { value: true });
    window.dispatchEvent(event);
    return {
      pending: form.getAttribute('data-eforms-enhanced-pending'),
      navigating: form.getAttribute('data-eforms-enhanced-navigating'),
      disabled: button.disabled,
      enhancedDisabled: button.getAttribute('data-eforms-enhanced-disabled'),
      spinners: button.querySelectorAll('[data-eforms-enhanced-spinner="1"]').length
    };
  });

  expect(restored).toEqual({ pending: null, navigating: null, disabled: false, enhancedDisabled: null, spinners: 0 });
});

test('enhanced pending spinner stays centered with the submit label', async ({ page }) => {
  let releaseSubmit;
  const pendingSubmit = new Promise(resolve => { releaseSubmit = resolve; });
  await boot(page);
  await routeSubmit(page, async route => {
    await pendingSubmit;
    await fulfillJson(route, 422, correctablePayload());
  });

  const button = page.locator('form.eforms-form-enhanced button[type="submit"]');
  await button.evaluate(node => {
    node.style.display = 'block';
    node.style.width = '320px';
  });
  await button.click();
  await expect(button.locator('.eforms-spinner')).toHaveCount(1);
  await expect(button).toHaveAttribute('data-eforms-spinner-button', 'block');

  const geometry = await button.evaluate(node => {
    const spinner = node.querySelector('.eforms-spinner');
    const nodeRect = node.getBoundingClientRect();
    const label = node.querySelector('.eforms-submit-label');
    const textRects = [];
    const walker = document.createTreeWalker(label || node, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
      if (!walker.currentNode.textContent.trim()) {
        continue;
      }
      const range = document.createRange();
      range.selectNodeContents(walker.currentNode);
      textRects.push(...Array.from(range.getClientRects()));
    }
    const spinnerRect = spinner.getBoundingClientRect();
    const labelRect = label.getBoundingClientRect();
    const textRight = Math.max(...textRects.map(rect => rect.right));
    const spinnerCenterY = (spinnerRect.top + spinnerRect.bottom) / 2;
    const labelCenterY = (labelRect.top + labelRect.bottom) / 2;
    const styles = getComputedStyle(node);
    const spinnerStyles = getComputedStyle(spinner);
    const spinnerBefore = getComputedStyle(spinner, '::before');
    return {
      display: styles.display,
      position: styles.position,
      textAlign: styles.textAlign,
      labelCount: node.querySelectorAll('.eforms-submit-label').length,
      labelTransform: getComputedStyle(label).transform,
      spinnerDisplay: spinnerStyles.display,
      spinnerAnimationName: spinnerStyles.animationName,
      spinnerBeforeAnimationName: spinnerBefore.animationName,
      labelDeltaX: ((labelRect.left + labelRect.right) / 2) - ((nodeRect.left + nodeRect.right) / 2),
      horizontalGap: spinnerRect.left - textRight,
      centerDeltaY: spinnerCenterY - labelCenterY
    };
  });

  expect(geometry).toMatchObject({ display: 'block', position: 'relative', textAlign: 'center', labelCount: 1, labelTransform: 'none', spinnerDisplay: 'grid', spinnerAnimationName: 'none', spinnerBeforeAnimationName: 'eforms-spin' });
  expect(geometry.horizontalGap).toBeGreaterThanOrEqual(18);
  expect(Math.abs(geometry.labelDeltaX)).toBeLessThanOrEqual(1);
  expect(Math.abs(geometry.centerDeltaY)).toBeLessThanOrEqual(2);


  releaseSubmit();
  await expect(button.locator('.eforms-spinner')).toHaveCount(0);
  await expect(button).not.toHaveAttribute('data-eforms-spinner-button', /.+/);
  await expect(button.locator('.eforms-submit-label')).toHaveCount(0);
});

test('enhanced pending spinner preserves flex submit button display', async ({ page }) => {
  let releaseSubmit;
  const pendingSubmit = new Promise(resolve => { releaseSubmit = resolve; });
  await boot(page);
  await routeSubmit(page, async route => {
    await pendingSubmit;
    await fulfillJson(route, 422, correctablePayload());
  });

  const button = page.locator('form.eforms-form-enhanced button[type="submit"]');
  await button.evaluate(node => {
    node.style.display = 'flex';
    node.style.alignItems = 'center';
    node.style.justifyContent = 'center';
    const icon = document.createElement('span');
    icon.setAttribute('data-test-icon', '1');
    icon.textContent = '+';
    node.insertBefore(icon, node.firstChild);
  });
  await button.click();
  await expect(button.locator('.eforms-spinner')).toHaveCount(1);
  await expect(button).toHaveAttribute('data-eforms-spinner-button', 'flex');

  const display = await button.evaluate(node => ({
    display: getComputedStyle(node).display,
    labelCount: node.querySelectorAll('.eforms-submit-label').length,
    spinnerCount: node.querySelectorAll('.eforms-spinner').length
  }));
  expect(display).toEqual({ display: 'flex', labelCount: 1, spinnerCount: 1 });

  releaseSubmit();
  await expect(button.locator('.eforms-spinner')).toHaveCount(0);
  await expect(button).not.toHaveAttribute('data-eforms-spinner-button', /.+/);
});

test('enhanced recovery activates one challenge and honors retry/location safety', async ({ page }) => {
  const responses = [
    correctablePayload({}, response.finalizingRecovery, { [response.provider]: 'turnstile', [response.siteKey]: 'site-key' }),
    {
      [response.ok]: false,
      [response.error]: { [response.code]: 'EFORMS_ERR_STORAGE_UNAVAILABLE', [response.message]: 'Try again.' },
      [response.canRetry]: true,
      [response.location]: 'https://outside.example/result'
    }
  ];
  await boot(page);
  await page.evaluate(() => {
    window.turnstile = {
      render(node, options) {
        const input = document.createElement('textarea');
        input.name = 'cf-turnstile-response';
        input.value = options.sitekey;
        node.appendChild(input);
        return 'widget-id';
      }
    };
  });
  await routeSubmit(page, route => fulfillJson(route, responses.length === 2 ? 422 : 503, responses.shift()));

  const form = page.locator('form.eforms-form-enhanced');
  await form.locator('button[type="submit"]').click();
  await expect(form.locator(`[${browserProtocol.dataAttributes.challenge_mount}] .cf-turnstile`)).toHaveCount(1);
  await expect(form.locator(`[${uploadAttrs.mount}="1"]`)).toHaveAttribute('data-eforms-upload-frozen', '1');
  await expect(form.locator('button[type="submit"]')).toBeEnabled();

  await form.locator('button[type="submit"]').click();
  await expect(form.locator('.eforms-error-summary')).toContainText('Try again.');
  await expect(form.locator('button[type="submit"]')).toBeEnabled();
  await expect(form.locator(`[${browserProtocol.dataAttributes.challenge_mount}] .cf-turnstile`)).toHaveCount(1);
});

test('enhanced challenge without provider runtime posts to server-rendered fallback', async ({ page }) => {
  const challenge = { [response.provider]: 'turnstile', [response.siteKey]: 'site-key' };
  const requests = [];
  await boot(page);
  await page.route(submitEndpoint, route => {
    requests.push({
      headers: route.request().headers(),
      body: route.request().postData() || ''
    });
    if (requests.length === 1) {
      return fulfillJson(route, 422, correctablePayload({}, null, challenge));
    }
    return route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: '<main data-server-rendered-challenge="1">Challenge required</main>'
    });
  });

  await page.locator('form.eforms-form-enhanced button[type="submit"]').click();
  await expect(page.locator('[data-server-rendered-challenge="1"]')).toBeVisible();
  expect(requests).toHaveLength(2);
  expect(requests[0].headers[enhanced.header.toLowerCase()]).toBe(enhanced.value);
  expect(requests[1].headers[enhanced.header.toLowerCase()]).toBeUndefined();
  expect(requests[1].body).toContain(hiddenNames.token);
});

test('enhanced challenge retry resets an already rendered widget', async ({ page }) => {
  const challenge = { [response.provider]: 'turnstile', [response.siteKey]: 'site-key' };
  await boot(page);
  await page.evaluate(() => {
    window.__turnstileCalls = { renders: [], resets: [] };
    window.turnstile = {
      render(widget, options) {
        window.__turnstileCalls.renders.push(options.sitekey);
        const input = document.createElement('textarea');
        input.name = 'cf-turnstile-response';
        input.value = 'used-token';
        widget.closest('form').appendChild(input);
        return 'widget-1';
      },
      reset(id) {
        window.__turnstileCalls.resets.push(id);
      }
    };
  });
  const submit = await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload({}, null, challenge)));

  const form = page.locator('form.eforms-form-enhanced');
  const button = form.locator('button[type="submit"]');
  await button.click();
  await expect(form.locator(`[${browserProtocol.dataAttributes.challenge_mount}] .cf-turnstile`)).toHaveCount(1);
  await expect(form.locator('textarea[name="cf-turnstile-response"]')).toHaveValue('used-token');
  await expect(form).not.toHaveAttribute('data-eforms-enhanced-pending', '1');

  await button.click();
  expect(submit.count).toBe(2);
  await expect.poll(() => page.evaluate(() => window.__turnstileCalls.resets.length)).toBe(1);
  const calls = await page.evaluate(() => window.__turnstileCalls);
  expect(calls).toEqual({ renders: ['site-key'], resets: ['widget-1'] });
  await expect(form.locator('textarea[name="cf-turnstile-response"]')).toHaveValue('');
});

test('enhanced challenge recovery clears stale widget when challenge stops being required', async ({ page }) => {
  const challenge = { [response.provider]: 'turnstile', [response.siteKey]: 'site-key' };
  const responses = [
    correctablePayload({}, null, challenge),
    correctablePayload({}, response.open, null)
  ];
  await boot(page);
  await page.evaluate(() => {
    window.turnstile = {
      render(widget) {
        const input = document.createElement('textarea');
        input.name = 'cf-turnstile-response';
        input.value = 'stale-token';
        widget.closest('form').appendChild(input);
        return 'widget-1';
      }
    };
  });
  await routeSubmit(page, route => fulfillJson(route, 422, responses.shift()));

  const form = page.locator('form.eforms-form-enhanced');
  const button = form.locator('button[type="submit"]');
  await button.click();
  await expect(form.locator(`[${browserProtocol.dataAttributes.challenge_mount}] .cf-turnstile`)).toHaveCount(1);
  await expect(form.locator('textarea[name="cf-turnstile-response"]')).toHaveValue('stale-token');

  await button.click();
  await expect(form.locator(`[${browserProtocol.dataAttributes.challenge_mount}] .cf-turnstile`)).toHaveCount(0);
  await expect(form.locator(`[${browserProtocol.dataAttributes.challenge_mount}]`)).toBeHidden();
  await expect(form.locator('textarea[name="cf-turnstile-response"]')).toHaveCount(0);
});

test('enhanced 422 ignores upload-status finalizing recovery vocabulary', async ({ page }) => {
  await boot(page);
  await routeSubmit(page, route => fulfillJson(route, 422, correctablePayload({}, 'finalizing')));

  const form = page.locator('form.eforms-form-enhanced');
  await form.locator('button[type="submit"]').click();
  await expect(form.locator(`[${uploadAttrs.mount}="1"]`)).not.toHaveAttribute('data-eforms-upload-frozen', '1');
  await expect(form.locator('button[type="submit"]')).toBeEnabled();
});

test('structured non-retryable failure keeps Send blocked even when uploads are open', async ({ page }) => {
  await boot(page);
  await routeSubmit(page, route => fulfillJson(route, 500, {
    [response.ok]: false,
    [response.error]: { [response.code]: 'EFORMS_ERR_TOKEN', [response.message]: 'This form was already submitted or has expired - please reload the page.' },
    [response.canRetry]: false,
    [response.location]: null
  }));

  const form = page.locator('form.eforms-form-enhanced');
  const button = form.locator('button[type="submit"]');
  await button.click();
  await expect(form.locator('.eforms-error-summary')).toContainText('This form was already submitted or has expired - please reload the page.');
  await expect(button).toBeDisabled();
  await expect(button.locator('.eforms-spinner')).toHaveCount(0);
  await expect(form).not.toHaveAttribute('data-eforms-enhanced-pending', '1');
});

test('enhanced success ignores same-origin non-result locations', async ({ page }) => {
  await boot(page);
  await routeSubmit(page, route => fulfillJson(route, 200, {
    [response.ok]: true,
    [response.location]: 'https://example.test/wp-admin/profile.php'
  }));

  const form = page.locator('form.eforms-form-enhanced');
  await form.locator('button[type="submit"]').click();
  await expect(form.locator('.eforms-error-summary')).toContainText('Your request couldn');
  expect(page.url()).toBe('about:blank');
});

test('enhanced result navigation keeps submit blocked until unload', async ({ page }) => {
  await bootAt(page, 'https://example.test/form-page?eforms_result=success&eforms_form=enhanced');
  const resultLocation = 'https://example.test/form-page?eforms_result=success&eforms_form=enhanced#done';
  const submit = await routeSubmit(page, route => fulfillJson(route, 200, {
    [response.ok]: true,
    [response.location]: resultLocation
  }));

  const form = page.locator('form.eforms-form-enhanced');
  const button = form.locator('button[type="submit"]');
  await button.click();
  await expect(form).toHaveAttribute('data-eforms-enhanced-pending', '1');
  await expect(form).toHaveAttribute('data-eforms-enhanced-navigating', '1');
  await expect(button).toBeDisabled();

  const secondSubmitAllowed = await form.evaluate(formNode => formNode.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
  expect(secondSubmitAllowed).toBe(false);
  expect(submit.count).toBe(1);
});

test('ambiguous finalizing status keeps uploads frozen and uses Send as retry', async ({ page }) => {
  await boot(page);
  await page.route(`${endpoint}/${batchId}`, route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(uploadResponse('finalizing')) }));
  await page.route(submitEndpoint, route => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Not JSON</title>' }));

  const form = page.locator('form.eforms-form-enhanced');
  const button = form.locator('button[type="submit"]');
  await button.click();
  await expect(form.locator('.eforms-error-summary')).toContainText('Your request couldn\'t be sent. Please try again.');
  await expect(form.locator('.eforms-error-summary button')).toHaveCount(0);
  await expect(form.locator(`[${uploadAttrs.mount}="1"]`)).toHaveAttribute('data-eforms-upload-frozen', '1');
  await expect(button).toBeEnabled();
  await expect(button.locator('.eforms-spinner')).toHaveCount(0);
  await expect(form).not.toHaveAttribute('data-eforms-enhanced-pending', '1');
});

test('terminal status blocks Send without exposing upload-status controls', async ({ page }) => {
  await boot(page);
  await page.route(`${endpoint}/${batchId}`, route => route.fulfill({ status: 410, contentType: 'application/json', body: JSON.stringify({ error: 'expired' }) }));
  await page.route(submitEndpoint, route => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Not JSON</title>' }));

  const form = page.locator('form.eforms-form-enhanced');
  const button = form.locator('button[type="submit"]');
  await button.click();
  await expect(form.locator('.eforms-error-summary')).toContainText('This request can\'t be finished from this page. Please reload.');
  await expect(form.locator('.eforms-error-summary')).not.toContainText('upload status');
  await expect(form.locator('.eforms-error-summary button')).toHaveCount(0);
  await expect(button).toBeDisabled();
  await expect(button.locator('.eforms-spinner')).toHaveCount(0);
  await expect(form).not.toHaveAttribute('data-eforms-enhanced-pending', '1');
});

test('ambiguous status-check failure keeps uploads frozen and uses Send as retry', async ({ page }) => {
  await boot(page);
  await page.route(`${endpoint}/${batchId}`, route => route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'busy' }) }));
  await page.route(submitEndpoint, route => route.abort());

  const form = page.locator('form.eforms-form-enhanced');
  const button = form.locator('button[type="submit"]');
  await button.click();
  await expect(form.locator('.eforms-error-summary')).toContainText('Your request couldn\'t be sent. Please try again.');
  await expect(form.locator('.eforms-error-summary button')).toHaveCount(0);
  await expect(form.locator(`[${uploadAttrs.mount}="1"]`)).toHaveAttribute('data-eforms-upload-frozen', '1');
  await expect(button).toBeEnabled();
  await expect(button.locator('.eforms-spinner')).toHaveCount(0);
  await expect(form).not.toHaveAttribute('data-eforms-enhanced-pending', '1');
});

test('scalar-only forms retain native submission', async ({ page }) => {
  await page.setContent('<form class="eforms-form"><button type="submit">Send</button></form>');
  await page.evaluate(({ protocol }) => { window.eformsSettings = { protocol }; }, { protocol: browserProtocol });
  await page.addScriptTag({ path: formsScript });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
  expect(await page.locator('form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))).toBeTruthy();
});
