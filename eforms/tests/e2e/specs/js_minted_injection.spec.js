const { test, expect } = require('@playwright/test');

function mintEndpointRequest(url) {
  const parsed = new URL(url);
  const pathname = parsed.pathname.replace(/\/+$/, '');
  return pathname.endsWith('/eforms/mint') ||
    parsed.searchParams.get('rest_route') === '/eforms/mint';
}

test('JS-minted form injects token and reuses session token on reload', async ({ page }) => {
  const pageUrl = process.env.EFORMS_E2E_JS_PAGE_URL;
  if (!pageUrl) {
    test.skip(true, 'EFORMS_E2E_JS_PAGE_URL is required.');
  }

  let mintPosts = 0;
  page.on('request', (request) => {
    if (request.method() === 'POST' && mintEndpointRequest(request.url())) {
      mintPosts += 1;
    }
  });

  await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

  const form = page.locator('form.eforms-form-contact[data-eforms-mode="js"]');
  await expect(form).toHaveCount(1);
  await expect(form.locator('input[name="eforms_token"]')).not.toHaveValue('');
  await expect(form.locator('input[name="instance_id"]')).not.toHaveValue('');
  await expect(form.locator('input[name="timestamp"]')).not.toHaveValue('');

  const cachedRaw = await page.evaluate(() => sessionStorage.getItem('eforms:token:contact'));
  expect(cachedRaw).not.toBeNull();
  const cached = JSON.parse(cachedRaw);
  expect(cached).toEqual(
    expect.objectContaining({
      token: expect.any(String),
      instance_id: expect.any(String),
      timestamp: expect.any(String),
      expires: expect.any(Number)
    })
  );

  expect(mintPosts).toBeGreaterThanOrEqual(1);
  const beforeReload = mintPosts;

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(form.locator('input[name="eforms_token"]')).not.toHaveValue('');
  await page.waitForTimeout(250);
  expect(mintPosts).toBe(beforeReload);
});
