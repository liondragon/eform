const { test, expect } = require('@playwright/test');

function escapeRegex(input) {
  return input.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function extractFormMarkup(html, classSuffix) {
  const pattern = new RegExp(
    `<form[^>]*class="[^"]*\\beforms-form-${escapeRegex(classSuffix)}\\b[^"]*"[^>]*>[\\s\\S]*?<\\/form>`,
    'i'
  );
  const match = html.match(pattern);
  return match ? match[0] : '';
}

function hiddenValue(formHtml, name) {
  const pattern = new RegExp(`<input[^>]*name="${escapeRegex(name)}"[^>]*value="([^"]*)"[^>]*>`, 'i');
  const match = formHtml.match(pattern);
  return match ? match[1] : null;
}

function mintPath(url) {
  return new URL(url).pathname === '/eforms/mint';
}

test('mixed-mode page keeps server-owned modes and mints only JS form', async ({ page, request }) => {
  const mixedUrl = process.env.EFORMS_E2E_MIXED_PAGE_URL;
  if (!mixedUrl) {
    test.skip(true, 'EFORMS_E2E_MIXED_PAGE_URL is required.');
  }

  const response = await request.get(mixedUrl);
  expect(response.ok()).toBeTruthy();
  const html = await response.text();

  const hiddenFormMarkup = extractFormMarkup(html, 'contact');
  const jsFormMarkup = extractFormMarkup(html, 'quote-request');

  expect(hiddenFormMarkup).toContain('data-eforms-mode="hidden"');
  expect(hiddenValue(hiddenFormMarkup, 'eforms_mode')).toBe('hidden');
  expect(hiddenValue(hiddenFormMarkup, 'eforms_token')).not.toBe('');
  expect(hiddenValue(hiddenFormMarkup, 'instance_id')).not.toBe('');
  expect(hiddenValue(hiddenFormMarkup, 'timestamp')).not.toBe('');

  expect(jsFormMarkup).toContain('data-eforms-mode="js"');
  expect(hiddenValue(jsFormMarkup, 'eforms_mode')).toBe('js');
  expect(hiddenValue(jsFormMarkup, 'eforms_token')).toBe('');
  expect(hiddenValue(jsFormMarkup, 'instance_id')).toBe('');
  expect(hiddenValue(jsFormMarkup, 'timestamp')).toBe('');

  let mintPosts = 0;
  page.on('request', (req) => {
    if (req.method() === 'POST' && mintPath(req.url())) {
      mintPosts += 1;
    }
  });

  await page.goto(mixedUrl, { waitUntil: 'domcontentloaded' });

  const hiddenForm = page.locator('form.eforms-form-contact[data-eforms-mode="hidden"]');
  const jsForm = page.locator('form.eforms-form-quote-request[data-eforms-mode="js"]');
  await expect(hiddenForm).toHaveCount(1);
  await expect(jsForm).toHaveCount(1);
  await expect(jsForm.locator('input[name="eforms_token"]')).not.toHaveValue('');
  await expect(hiddenForm.locator('input[name="eforms_token"]')).not.toHaveValue('');
  expect(mintPosts).toBe(1);
});

test('mixed-mode page blocks only JS form when mint fails', async ({ browser }) => {
  const mixedUrl = process.env.EFORMS_E2E_MIXED_PAGE_URL;
  if (!mixedUrl) {
    test.skip(true, 'EFORMS_E2E_MIXED_PAGE_URL is required.');
  }

  const context = await browser.newContext();
  const page = await context.newPage();

  await page.route('**/eforms/mint', (route) => route.abort());
  await page.goto(mixedUrl, { waitUntil: 'domcontentloaded' });

  const hiddenForm = page.locator('form.eforms-form-contact[data-eforms-mode="hidden"]');
  const jsForm = page.locator('form.eforms-form-quote-request[data-eforms-mode="js"]');

  await expect(jsForm).toHaveAttribute('data-eforms-mint-state', 'failed');
  await expect(jsForm.locator('.eforms-error-summary')).toContainText(
    'This form is temporarily unavailable. Please reload the page.'
  );

  await expect(hiddenForm.locator('input[name="eforms_token"]')).not.toHaveValue('');
  await expect(hiddenForm).not.toHaveAttribute('data-eforms-mint-state', 'failed');

  await context.close();
});
