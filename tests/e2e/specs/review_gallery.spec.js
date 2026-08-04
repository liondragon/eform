const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const previewRuntime = fs.readFileSync(
  path.resolve(__dirname, '../../../assets/review-gallery.js'),
  'utf8'
);
const formsCss = [
  path.resolve(__dirname, '../../../assets/forms.css'),
  path.resolve(__dirname, '../../../assets/review-gallery.css')
].map(file => fs.readFileSync(file, 'utf8')).join('\n');
const reviewTemplate = path.resolve(__dirname, '../../../templates/pages/review-gallery.php');
const anchorsPhp = path.resolve(__dirname, '../../../src/Anchors.php');
const tinyPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
const tinyPng = Buffer.from(tinyPngBase64, 'base64');

function renderReviewTemplate(context) {
  const script = `
    require ${JSON.stringify(anchorsPhp)};
    $GLOBALS['eforms_e2e_review_context'] = json_decode(base64_decode($argv[1]), true);
    class PublicRequestController {
      public static function review_page_context() { return $GLOBALS['eforms_e2e_review_context']; }
    }
    function add_filter() {}
    function get_header() {}
    function get_footer() {}
    function wp_nonce_field($action, $name) { echo '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="nonce" />'; }
    require ${JSON.stringify(reviewTemplate)};
  `;
  return execFileSync('php', ['-r', script, Buffer.from(JSON.stringify(context)).toString('base64')], { encoding: 'utf8' });
}

test('review preview failure reveals the unavailable state without removing download access', async ({ page }) => {
  await page.route('https://example.test/original', route => route.fulfill({
    status: 200,
    contentType: 'image/png',
    body: tinyPng
  }));
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <div class="eforms-review-preview eforms-review-preview-with-image">
        <span hidden aria-hidden="true" data-eforms-review-fallback>
          <span>Preview unavailable</span>
          <button type="button" class="eforms-review-button eforms-review-button--compact" data-eforms-review-retry>Retry preview</button>
          <button type="button" class="eforms-review-button eforms-review-button--compact" data-eforms-review-original data-eforms-review-original-src="https://example.test/original">Load original</button>
        </span>
        <a class="eforms-review-preview-link ta-gallery__link" data-lbwps-width="1600" data-lbwps-height="900" aria-label="Open Photo 1">
          <img hidden data-eforms-review-src="data:image/jpeg;base64,invalid" alt="Photo 1 preview" data-eforms-review-preview>
        </a>
        <a class="eforms-review-download-overlay" href="https://example.test/original" aria-label="Download Photo 1">
          <span class="screen-reader-text">Download photo</span>
        </a>
      </div>
    </article>
  `);
  await page.addScriptTag({ content: previewRuntime });

  const image = page.locator('[data-eforms-review-preview]');
  const fallback = page.getByText('Preview unavailable');
  const previewLink = page.locator('a[aria-label="Open Photo 1"]');
  await expect(image).toBeHidden();
  await expect(image).not.toHaveAttribute('alt');
  await expect(previewLink).toHaveAttribute('aria-disabled', 'true');
  await expect(previewLink).toHaveAttribute('tabindex', '-1');
  await previewLink.evaluate((link) => {
    window.__eformsBrokenPreviewClicked = false;
    link.addEventListener('click', () => {
      window.__eformsBrokenPreviewClicked = true;
    });
    link.click();
  });
  await expect.poll(() => page.evaluate(() => window.__eformsBrokenPreviewClicked === true)).toBe(false);
  await expect(fallback).not.toHaveAttribute('aria-hidden');
  await expect(page.getByRole('button', { name: 'Retry preview' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Load original' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Download Photo 1' })).toBeVisible();
  await page.getByRole('button', { name: 'Load original' }).click();
  await expect(image).toBeVisible();
  await expect(image).toHaveAttribute('alt', 'Photo 1 original');
  await expect(previewLink).toHaveAttribute('href', /^blob:/);
  await expect(previewLink).toHaveAttribute('data-lbwps-width', '1');
  await expect(previewLink).toHaveAttribute('data-lbwps-height', '1');
  await expect(fallback).toBeHidden();
});

test('no-preview cards expose fallback image text without swallowing download access', async ({ page }) => {
  await page.setContent(`
    <style>${formsCss}</style>
    <div class="eforms-review-preview eforms-review-preview-with-image eforms-review-preview-unavailable">
      <span role="img" aria-label="Preview unavailable for Photo 1">Preview unavailable</span>
      <a class="eforms-review-download-overlay" href="/submitted-image" aria-label="Download Photo 1">
        <span class="screen-reader-text">Download photo</span>
      </a>
    </div>
  `);

  await expect(page.getByRole('img', { name: 'Preview unavailable for Photo 1' })).toBeVisible();
  const download = page.getByRole('link', { name: 'Download Photo 1' });
  await expect(download).toBeVisible();
  await expect.poll(() => download.evaluate(link => Boolean(link.closest('[role="img"]')))).toBe(false);
});

test('download-only cards fetch a full original only after explicit viewer action', async ({ page }) => {
  let originalRequests = 0;
  await page.route('https://example.test/download-only-original', route => {
    originalRequests += 1;
    return route.fulfill({
      status: 200,
      contentType: 'image/png',
      body: tinyPng
    });
  });
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <div class="eforms-review-preview eforms-review-preview-with-image eforms-review-preview-unavailable">
        <span aria-live="polite" data-eforms-review-fallback>
          <span data-eforms-review-fallback-status>Preview unavailable</span>
          <button type="button" data-eforms-review-original data-eforms-review-original-src="https://example.test/download-only-original">Load original</button>
        </span>
        <a class="eforms-review-preview-link ta-gallery__link" aria-label="Open Photo 1">
          <img hidden data-eforms-review-src="" alt="Photo 1 preview" data-eforms-review-preview>
        </a>
        <a class="eforms-review-download-overlay" href="https://example.test/download-only-original" aria-label="Download Photo 1">Download</a>
      </div>
    </article>
  `);
  await page.addScriptTag({ content: previewRuntime });

  expect(originalRequests).toBe(0);
  await expect(page.getByText('Preview unavailable')).toBeVisible();
  await page.getByRole('button', { name: 'Load original' }).click();
  await expect(page.locator('[data-eforms-review-preview]')).toBeVisible();
  await expect(page.locator('[data-eforms-review-preview]')).toHaveAttribute('alt', 'Photo 1 original');
  await expect(page.getByRole('link', { name: 'Open Photo 1' })).toHaveAttribute('href', /^blob:/);
  await expect(page.getByText('Preview unavailable')).toBeHidden();
  await expect(page.getByRole('link', { name: 'Download Photo 1' })).toBeVisible();
  const objectUrl = await page.getByRole('link', { name: 'Open Photo 1' }).getAttribute('href');
  expect(await page.evaluate(url => fetch(url).then(response => response.ok, () => false), objectUrl)).toBe(true);
  await page.evaluate(() => {
    const event = new Event('pagehide');
    Object.defineProperty(event, 'persisted', { value: true });
    window.dispatchEvent(event);
  });
  expect(await page.evaluate(url => fetch(url).then(response => response.ok, () => false), objectUrl)).toBe(true);
  await page.evaluate(() => {
    const event = new Event('pagehide');
    Object.defineProperty(event, 'persisted', { value: false });
    window.dispatchEvent(event);
  });
  await expect.poll(() => page.locator('[data-eforms-review-preview]').evaluate(image => image.__eformsReviewObjectUrl)).toBe('');
  expect(await page.evaluate(url => fetch(url).then(response => response.ok, () => false), objectUrl)).toBe(false);
  expect(originalRequests).toBe(1);
});

test('original fallback times out without AbortController support', async ({ page }) => {
  await page.setContent(`
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="250">
      <div class="eforms-review-preview eforms-review-preview-with-image eforms-review-preview-unavailable">
        <span data-eforms-review-fallback>
          <span data-eforms-review-fallback-status>Preview unavailable</span>
          <button type="button" data-eforms-review-original data-eforms-review-original-src="https://example.test/hanging-original">Load original</button>
        </span>
        <a class="eforms-review-preview-link" aria-label="Open Photo 1">
          <img hidden data-eforms-review-src="" alt="Photo 1 preview" data-eforms-review-preview>
        </a>
      </div>
    </article>
  `);
  await page.evaluate(() => {
    window.AbortController = undefined;
    window.fetch = () => new Promise(() => {});
  });
  await page.addScriptTag({ content: previewRuntime });

  const image = page.locator('[data-eforms-review-preview]');
  const original = page.getByRole('button', { name: 'Load original' });
  await original.click();
  await expect.poll(() => image.evaluate(node => node.__eformsReviewOriginalLoading)).toBe(true);
  await expect.poll(() => image.evaluate(node => node.__eformsReviewOriginalLoading)).toBe(false);
  await expect(page.getByText('Preview unavailable')).toBeVisible();
  await expect(original).toBeEnabled();
});

test('non-persisted pagehide invalidates a late original fetch without AbortController', async ({ page }) => {
  await page.setContent(`
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <div class="eforms-review-preview eforms-review-preview-with-image eforms-review-preview-unavailable">
        <span data-eforms-review-fallback>
          <span data-eforms-review-fallback-status>Preview unavailable</span>
          <button type="button" data-eforms-review-original data-eforms-review-original-src="https://example.test/late-pagehide-original">Load original</button>
        </span>
        <a class="eforms-review-preview-link" aria-label="Open Photo 1">
          <img hidden data-eforms-review-src="" alt="Photo 1 preview" data-eforms-review-preview>
        </a>
      </div>
    </article>
  `);
  await page.evaluate(() => {
    window.AbortController = undefined;
    window.fetch = () => new Promise(resolve => {
      window.__eformsLatePagehideResolve = resolve;
    });
  });
  await page.addScriptTag({ content: previewRuntime });

  const image = page.locator('[data-eforms-review-preview]');
  await page.getByRole('button', { name: 'Load original' }).click();
  await expect.poll(() => image.evaluate(node => node.__eformsReviewOriginalLoading)).toBe(true);
  await page.evaluate((pngBase64) => {
    const event = new Event('pagehide');
    Object.defineProperty(event, 'persisted', { value: false });
    window.dispatchEvent(event);
    const body = Uint8Array.from(atob(pngBase64), character => character.charCodeAt(0));
    window.__eformsLatePagehideResolve(new Response(body, { status: 200, headers: { 'Content-Type': 'image/png' } }));
  }, tinyPngBase64);
  await page.waitForTimeout(50);
  await expect.poll(() => image.evaluate(node => ({
    loading: node.__eformsReviewOriginalLoading,
    objectUrl: node.__eformsReviewObjectUrl,
    loader: node.__eformsReviewOriginalLoader
  }))).toEqual({ loading: false, objectUrl: '', loader: null });
  await expect(page.locator('a[aria-label="Open Photo 1"]')).toHaveAttribute('aria-disabled', 'true');
});

test('non-persisted pagehide detaches a queued original decoder callback', async ({ page }) => {
  await page.setContent(`
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <div class="eforms-review-preview eforms-review-preview-with-image eforms-review-preview-unavailable">
        <span data-eforms-review-fallback>
          <span data-eforms-review-fallback-status>Preview unavailable</span>
          <button type="button" data-eforms-review-original data-eforms-review-original-src="https://example.test/decoder-pagehide-original">Load original</button>
        </span>
        <a class="eforms-review-preview-link" aria-label="Open Photo 1">
          <img hidden data-eforms-review-src="" alt="Photo 1 preview" data-eforms-review-preview>
        </a>
      </div>
    </article>
  `);
  await page.evaluate((pngBase64) => {
    window.Image = function () {
      window.__eformsPagehideLoader = this;
    };
    const body = Uint8Array.from(atob(pngBase64), character => character.charCodeAt(0));
    window.fetch = () => Promise.resolve(new Response(body, { status: 200, headers: { 'Content-Type': 'image/png' } }));
  }, tinyPngBase64);
  await page.addScriptTag({ content: previewRuntime });

  const image = page.locator('[data-eforms-review-preview]');
  await page.getByRole('button', { name: 'Load original' }).click();
  await expect.poll(() => page.evaluate(() => Boolean(window.__eformsPagehideLoader && window.__eformsPagehideLoader.onload))).toBe(true);
  await page.evaluate(() => {
    const lateOnload = window.__eformsPagehideLoader.onload;
    const event = new Event('pagehide');
    Object.defineProperty(event, 'persisted', { value: false });
    window.dispatchEvent(event);
    lateOnload();
  });
  await expect.poll(() => image.evaluate(node => ({
    loading: node.__eformsReviewOriginalLoading,
    objectUrl: node.__eformsReviewObjectUrl,
    loader: node.__eformsReviewOriginalLoader
  }))).toEqual({ loading: false, objectUrl: '', loader: null });
  await expect(page.locator('a[aria-label="Open Photo 1"]')).toHaveAttribute('aria-disabled', 'true');
});

test('a late original response cannot complete a newer retry', async ({ page }) => {
  await page.setContent(`
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="400">
      <div class="eforms-review-preview eforms-review-preview-with-image eforms-review-preview-unavailable">
        <span data-eforms-review-fallback>
          <span data-eforms-review-fallback-status>Preview unavailable</span>
          <button type="button" data-eforms-review-original data-eforms-review-original-src="https://example.test/retry-original">Load original</button>
        </span>
        <a class="eforms-review-preview-link" aria-label="Open Photo 1">
          <img hidden data-eforms-review-src="" alt="Photo 1 preview" data-eforms-review-preview>
        </a>
      </div>
    </article>
  `);
  await page.evaluate(() => {
    window.AbortController = undefined;
    window.__eformsOriginalResolvers = [];
    window.fetch = () => new Promise(resolve => window.__eformsOriginalResolvers.push(resolve));
  });
  await page.addScriptTag({ content: previewRuntime });

  const image = page.locator('[data-eforms-review-preview]');
  const original = page.getByRole('button', { name: 'Load original' });
  await original.click();
  await expect.poll(() => image.evaluate(node => node.__eformsReviewOriginalLoading)).toBe(false);
  await original.click();
  await expect.poll(() => page.evaluate(() => window.__eformsOriginalResolvers.length)).toBe(2);

  await page.evaluate((pngBase64) => {
    const body = Uint8Array.from(atob(pngBase64), character => character.charCodeAt(0));
    window.__eformsOriginalResolvers[0](new Response(body, { status: 200, headers: { 'Content-Type': 'image/png' } }));
  }, tinyPngBase64);
  await expect.poll(() => image.evaluate(node => node.__eformsReviewOriginalLoading)).toBe(true);
  await expect(page.getByText('Loading original...')).toBeVisible();

  await page.evaluate((pngBase64) => {
    const body = Uint8Array.from(atob(pngBase64), character => character.charCodeAt(0));
    window.__eformsOriginalResolvers[1](new Response(body, { status: 200, headers: { 'Content-Type': 'image/png' } }));
  }, tinyPngBase64);
  await expect(image).toBeVisible();
  await expect(image).toHaveAttribute('alt', 'Photo 1 original');
});

test('download overlays stay secondary until review image hover or focus', async ({ page }) => {
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="gallery">
      <div class="eforms-review-preview eforms-review-preview-with-image" style="width:320px">
        <a class="eforms-review-preview-link ta-gallery__link" aria-label="Open Photo 1">
          <img src="data:image/png;base64,${tinyPngBase64}" alt="Photo 1 preview">
        </a>
        <a class="eforms-review-download-overlay" href="/submitted-image" aria-label="Download Photo 1">
          <span class="screen-reader-text">Download photo</span>
        </a>
      </div>
    </article>
  `);

  const preview = page.locator('.eforms-review-preview-with-image');
  const download = page.getByRole('link', { name: 'Download Photo 1' });
  await expect(download).toBeVisible();
  const resting = await download.evaluate(link => {
    const rect = link.getBoundingClientRect();
    const style = getComputedStyle(link);
    const circle = getComputedStyle(link).backgroundImage;
    return {
      width: rect.width,
      height: rect.height,
      opacity: style.opacity,
      borderWidth: style.borderTopWidth,
      circle,
      rightGap: Math.round(link.parentElement.getBoundingClientRect().right - rect.right),
      bottomGap: Math.round(link.parentElement.getBoundingClientRect().bottom - rect.bottom)
    };
  });
  expect(resting.width).toBeGreaterThanOrEqual(44);
  expect(resting.height).toBeGreaterThanOrEqual(44);
  expect(resting.borderWidth).toBe('0px');
  expect(resting.circle).toContain('radial-gradient');
  expect(resting.circle).toContain('16px');
  expect(resting.rightGap).toBeGreaterThanOrEqual(4);
  expect(resting.bottomGap).toBeGreaterThanOrEqual(4);

  if (await page.evaluate(() => matchMedia('(hover:hover) and (pointer:fine)').matches)) {
    expect(Number(resting.opacity)).toBeLessThan(0.1);
    await preview.hover();
    await expect(download).toHaveCSS('opacity', /0\.9|1/);
    await page.mouse.move(0, 0);
    await download.focus();
    await expect(download).toHaveCSS('opacity', /0\.9|1/);
  } else {
    expect(Number(resting.opacity)).toBeGreaterThan(0.5);
  }
});

test('operator can retry a transient review preview failure without losing the download', async ({ page }) => {
  let attempts = 0;
  let releaseRetry;
  await page.route('https://example.test/preview', async route => {
    attempts += 1;
    if (attempts === 1) {
      await route.fulfill({ status: 503, body: 'busy' });
      return;
    }
    await new Promise(resolve => {
      releaseRetry = resolve;
    });
    await route.fulfill({
      status: 200,
      contentType: 'image/png',
      body: tinyPng
    });
  });
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <div class="eforms-review-preview eforms-review-preview-with-image">
        <span hidden aria-hidden="true" data-eforms-review-fallback>
          <span data-eforms-review-fallback-status>Preview unavailable</span>
          <button type="button" class="eforms-review-button eforms-review-button--compact" data-eforms-review-retry>Retry preview</button>
          <button type="button" class="eforms-review-button eforms-review-button--compact" data-eforms-review-original data-eforms-review-original-src="https://example.test/original">Load original</button>
        </span>
        <a class="eforms-review-preview-link ta-gallery__link" data-lbwps-width="1600" data-lbwps-height="900" aria-label="Open Photo 1">
          <img hidden data-eforms-review-src="https://example.test/preview" alt="Photo 1 preview" data-eforms-review-preview>
        </a>
        <a class="eforms-review-download-overlay" href="/submitted-image" aria-label="Download Photo 1">
          <span class="screen-reader-text">Download photo</span>
        </a>
      </div>
    </article>
  `);
  await page.addScriptTag({ content: previewRuntime });

  const retry = page.getByRole('button', { name: 'Retry preview' });
  const original = page.locator('[data-eforms-review-original]');
  const previewLink = page.locator('a[aria-label="Open Photo 1"]');
  await expect(retry).toBeVisible();
  await expect(previewLink).toHaveAttribute('aria-disabled', 'true');
  await retry.click();
  await expect.poll(() => attempts).toBe(2);
  await expect(original).toBeDisabled();
  releaseRetry();
  await expect(page.locator('[data-eforms-review-preview]')).toBeVisible();
  await expect(page.locator('[data-eforms-review-preview]')).toHaveAttribute('alt', 'Photo 1 preview');
  await expect(previewLink).not.toHaveAttribute('aria-disabled');
  await expect(previewLink).not.toHaveAttribute('tabindex', '-1');
  await expect(previewLink).toHaveAttribute('href', 'https://example.test/preview');
  await expect(previewLink).toHaveAttribute('data-lbwps-srcsmall', 'https://example.test/preview');
  await expect(page.getByRole('link', { name: 'Open Photo 1' })).toBeVisible();
  await expect(page.getByText('Preview unavailable').first()).toBeHidden();
  await expect(original).toBeEnabled();
  await expect(page.getByRole('link', { name: 'Download Photo 1' })).toBeVisible();
  expect(attempts).toBe(2);
});

test('successful review preview keeps its truthful alternative and leaves gallery clicks to the site lightbox', async ({ page }) => {
  await page.setContent(`
    <style>${formsCss}</style>
    <div class="eforms-review-preview eforms-review-preview-with-image" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <span hidden aria-hidden="true" data-eforms-review-fallback>
        <span>Preview unavailable</span>
        <button type="button" data-eforms-review-retry>Retry preview</button>
      </span>
      <a class="ta-gallery__link" data-lbwps-width="1600" data-lbwps-height="900" aria-label="Open preview of phone photo">
        <img hidden data-eforms-review-src="data:image/png;base64,${tinyPngBase64}" alt="Preview of phone photo" data-eforms-review-preview>
      </a>
    </div>
    <div class="eforms-review-preview eforms-review-preview-with-image" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <span hidden aria-hidden="true" data-eforms-review-fallback>
        <span>Preview unavailable</span>
        <button type="button" data-eforms-review-retry>Retry preview</button>
      </span>
      <a class="ta-gallery__link" data-lbwps-width="1600" data-lbwps-height="900" aria-label="Open preview of second photo">
        <img hidden data-eforms-review-src="data:image/png;base64,${tinyPngBase64}" alt="Preview of second photo" data-eforms-review-preview>
      </a>
    </div>
  `);
  await page.addScriptTag({ content: previewRuntime });

  await expect(page.locator('[data-eforms-review-preview]').first()).toBeVisible();
  await expect(page.locator('[data-eforms-review-preview]').first()).toHaveAttribute('alt', 'Preview of phone photo');
  await expect(page.locator('[data-eforms-review-fallback]').first()).toBeHidden();
  await expect(page.locator('[data-eforms-review-fallback]').nth(1)).toBeHidden();
  await expect(page.locator('[data-eforms-review-fallback]').first()).toHaveAttribute('aria-hidden', 'true');

  const previewLink = page.getByRole('link', { name: 'Open preview of phone photo' });
  await previewLink.evaluate((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      window.__eformsReviewPreviewLinkClicked = true;
    });
  });
  await previewLink.click();
  await expect.poll(() => page.evaluate(() => window.__eformsReviewPreviewLinkClicked === true)).toBe(true);
  await expect(page.locator('[data-eforms-review-lightbox-dialog]')).toHaveCount(0);
});

test('review submission action row groups availability and operator actions', async ({ page }) => {
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="gallery">
      <div class="eforms-review-actions">
        <p class="eforms-review-summary">
          <span>ID: <strong>115f422a-ed2f-4854-9492-654cc8ac4304</strong></span>
          <span class="eforms-review-summary-count">8 photos</span>
          <span class="eforms-review-submitted">Submitted July 26, 2026 at 10:00 am</span>
          <span class="eforms-review-availability">Available until August 25, 2026 at 10:00 am</span>
        </p>
        <div class="eforms-review-action-buttons">
          <button type="button" class="eforms-review-button eforms-review-availability-open">Update availability</button>
          <button type="button" class="eforms-review-button eforms-review-button--danger-outline eforms-review-delete-open">Delete submission</button>
        </div>
      </div>
    </article>
  `);

  await expect(page.getByText('Submitted July 26, 2026 at 10:00 am')).toBeVisible();
  await expect(page.getByText('Available until August 25, 2026 at 10:00 am')).toBeVisible();
  const actions = page.locator('.eforms-review-actions');
  await expect(actions.locator('.eforms-review-summary')).toBeVisible();
  await expect(actions.locator('.eforms-review-action-buttons')).toBeVisible();
  await expect(actions.getByRole('button', { name: 'Update availability' })).toBeVisible();
  await expect(actions.getByRole('button', { name: 'Delete submission' })).toBeVisible();
});

test('operator lead details and anonymous project summary render above photos', async ({ page }) => {
  const photo = { download_url: '/submitted-image', preview_url: '' };
  const operatorHtml = renderReviewTemplate({
    title: 'Virtual Estimate Request',
    submission_id: '115f422a-ed2f-4854-9492-654cc8ac4304',
    items: [photo],
    submitted_label: 'July 26, 2026 at 10:00 am',
    availability_label: 'August 25, 2026 at 10:00 am',
    can_delete: true,
    attribution_name: 'Ada Lovelace',
    review_facts: {
      aria_label: 'Lead details',
      groups: [
        {
          layout: 'equal',
          rows: [
            { label: 'Zip Code', value: '80231', href: '', wide: false },
            { label: 'Email', value: 'ada@example.test', href: '', wide: false },
            { label: 'Phone', value: '720-900-5278', href: '', wide: false }
          ]
        },
        {
          layout: 'equal',
          rows: [
            { label: 'Project Description', value: 'Refinish the main floor.', href: '', wide: true },
            { label: 'Square Footage', value: '1145', href: '', wide: false },
            { label: 'Listing URL', value: 'https://example.test/listing', href: 'https://example.test/listing', wide: false }
          ]
        }
      ]
    },
    operator_action_url: '/review',
    operator_action_field: 'eforms_review_action',
    delete_action: 'delete_submission',
    delete_nonce_action: 'delete-action',
    delete_nonce_field: '_delete_nonce',
    availability_action: 'update_availability',
    availability_nonce_action: 'availability-action',
    availability_nonce_field: '_availability_nonce',
    availability_choice_field: 'eforms_review_availability',
    availability_options: [{ key: '30_days', label: '30 days', checked: true }]
  });
  await page.setContent(`<style>${formsCss}</style>${operatorHtml}`);

  await expect(page.locator('.eforms-review-attribution-name')).toHaveText('Ada Lovelace');
  await expect(page.getByText('ada@example.test')).toBeVisible();
  await expect(page.getByRole('link', { name: 'https://example.test/listing' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Download Photo 1' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Update availability' })).toBeVisible();
  await expect(page.locator('.eforms-review-facts')).toBeVisible();
  await expect(page.locator('.eforms-review-grid')).toBeVisible();
  const operatorOrder = await page.locator('.eforms-review-page').evaluate(root => {
    const facts = root.querySelector('.eforms-review-facts');
    const grid = root.querySelector('.eforms-review-grid');
    return facts.compareDocumentPosition(grid) & Node.DOCUMENT_POSITION_FOLLOWING;
  });
  expect(operatorOrder).toBeTruthy();

  const publicHtml = renderReviewTemplate({
    title: 'Submitted Photos',
    submission_id: '115f422a-ed2f-4854-9492-654cc8ac4304',
    items: [photo],
    can_delete: false,
    review_facts: {
      aria_label: 'Project summary',
      groups: [{
        layout: 'project',
        rows: [
          { label: 'Project Description', value: 'Refinish the main floor.', href: '', wide: true },
          { label: 'Square Footage', value: '1145', href: '', wide: false }
        ]
      }]
    }
  });
  await page.setContent(`<style>${formsCss}</style>${publicHtml}`);
  await expect(page.getByText('Refinish the main floor.')).toBeVisible();
  await expect(page.getByText('1145')).toBeVisible();
  await expect(page.locator('body')).not.toContainText('Ada Lovelace');
  await expect(page.locator('body')).not.toContainText('ada@example.test');
  await expect(page.locator('body')).not.toContainText('720-900-5278');
});

test('operator action controls open and close their dialogs', async ({ page }) => {
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="gallery">
      <div class="eforms-review-action-buttons">
        <button type="button" class="eforms-review-button eforms-review-availability-open" data-eforms-review-availability-open>Update availability</button>
        <button type="button" class="eforms-review-button eforms-review-button--danger-outline eforms-review-delete-open" data-eforms-review-delete-open>Delete submission</button>
      </div>
      <dialog class="eforms-review-delete-dialog eforms-review-availability-dialog" data-eforms-review-availability-dialog>
        <form method="post" action="/">
          <h2>Update availability</h2>
          <input type="hidden" name="eforms_review_action" value="update_availability">
          <input type="hidden" name="_eforms_review_availability_nonce" value="nonce">
          <div class="eforms-review-availability-options">
            <label><input type="radio" name="eforms_review_availability" value="30_days"> 30 days</label>
          </div>
          <div class="eforms-review-delete-actions">
            <button type="button" class="eforms-review-button" data-eforms-review-availability-close>Cancel</button>
            <button type="submit" class="eforms-review-button">Update availability</button>
          </div>
        </form>
      </dialog>
      <dialog class="eforms-review-delete-dialog" data-eforms-review-delete-dialog>
        <form method="post" action="/">
          <h2>Delete submission?</h2>
          <input type="hidden" name="eforms_review_action" value="delete_submission">
          <input type="hidden" name="_eforms_review_delete_nonce" value="nonce">
          <div class="eforms-review-delete-actions">
            <button type="button" class="eforms-review-button" data-eforms-review-delete-close>Cancel</button>
            <button type="submit" class="eforms-review-button eforms-review-button--danger">Delete</button>
          </div>
        </form>
      </dialog>
    </article>
  `);
  await page.addScriptTag({ content: previewRuntime });

  const dialog = page.locator('[data-eforms-review-delete-dialog]');
  await expect(dialog).not.toHaveAttribute('open', '');
  await page.getByRole('button', { name: 'Delete submission' }).click();
  await expect(dialog).toHaveAttribute('open', '');
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).not.toHaveAttribute('open', '');

  const availabilityDialog = page.locator('[data-eforms-review-availability-dialog]');
  await page.getByRole('button', { name: 'Update availability' }).click();
  await expect(availabilityDialog).toHaveAttribute('open', '');
  await availabilityDialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(availabilityDialog).not.toHaveAttribute('open', '');
});


test('expired operator review surface is management only', async ({ page }) => {
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="expired">
      <p class="eforms-review-status">This photo submission is no longer available. Available until August 25, 2026 at 2:00 pm.</p>
      <div class="eforms-review-actions">
        <p class="eforms-review-summary">
          <span>ID: <strong>320309e0-f751-4f8c-9a78-5c02e4beoefb</strong></span>
          <span class="eforms-review-summary-count">No photos shown</span>
          <span class="eforms-review-submitted">Submitted July 26, 2026 at 2:00 pm</span>
          <span class="eforms-review-availability">Available until August 25, 2026 at 2:00 pm</span>
        </p>
        <div class="eforms-review-action-buttons">
          <button type="button" class="eforms-review-button eforms-review-button--danger-outline eforms-review-delete-open" data-eforms-review-delete-open>Delete submission</button>
        </div>
      </div>
    </article>
  `);

  await expect(page.locator('[data-eforms-review="expired"]')).toBeVisible();
  await expect(page.getByText('This photo submission is no longer available.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Delete submission' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Update availability' })).toHaveCount(0);
  await expect(page.locator('.eforms-review-grid')).toHaveCount(0);
  await expect(page.locator('.eforms-review-download-overlay')).toHaveCount(0);
  await expect(page.locator('[href*="eforms_review_upload"]')).toHaveCount(0);
  await expect(page.locator('[href*="eforms_review_preview"]')).toHaveCount(0);
});

test('review previews enter the network one at a time', async ({ page }) => {
  const attempts = [];
  let releaseFirst;
  await page.route('https://example.test/preview/**', async route => {
    const name = new URL(route.request().url()).pathname.split('/').pop();
    attempts.push(name);
    if (name === 'first') {
      await new Promise(resolve => {
        releaseFirst = async () => {
          await route.fulfill({ status: 200, contentType: 'image/png', body: tinyPng });
          resolve();
        };
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'image/png', body: tinyPng });
  });
  await page.setContent(`
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <div class="eforms-review-preview eforms-review-preview-with-image">
        <span hidden aria-hidden="true" data-eforms-review-fallback><span>Preview unavailable</span><button type="button" data-eforms-review-retry>Retry preview</button></span>
        <img hidden data-eforms-review-src="https://example.test/preview/first" alt="Preview of first photo" data-eforms-review-preview>
      </div>
      <div class="eforms-review-preview eforms-review-preview-with-image">
        <span hidden aria-hidden="true" data-eforms-review-fallback><span>Preview unavailable</span><button type="button" data-eforms-review-retry>Retry preview</button></span>
        <img hidden data-eforms-review-src="https://example.test/preview/second" alt="Preview of second photo" data-eforms-review-preview>
      </div>
    </article>
  `);
  await page.addScriptTag({ content: previewRuntime });

  await expect.poll(() => typeof releaseFirst).toBe('function');
  expect(attempts).toEqual(['first']);
  await releaseFirst();
  await expect.poll(() => attempts).toEqual(['first', 'second']);
  await expect(page.locator('[data-eforms-review-preview]:visible')).toHaveCount(2);
});

test('a stalled review preview times out and releases the next queued preview', async ({ page }) => {
  const attempts = [];
  let releaseFirst;
  await page.route('https://example.test/preview/**', async route => {
    const name = new URL(route.request().url()).pathname.split('/').pop();
    attempts.push(name);
    if (name === 'first') {
      await new Promise(resolve => {
        releaseFirst = async () => {
          await route.abort('timedout').catch(() => {});
          resolve();
        };
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'image/png', body: tinyPng });
  });
  await page.setContent(`
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="50">
      <div class="eforms-review-preview eforms-review-preview-with-image">
        <span hidden aria-hidden="true" data-eforms-review-fallback><span>Preview unavailable</span><button type="button" data-eforms-review-retry>Retry preview</button></span>
        <img hidden data-eforms-review-src="https://example.test/preview/first" alt="Preview of first photo" data-eforms-review-preview>
      </div>
      <div class="eforms-review-preview eforms-review-preview-with-image">
        <span hidden aria-hidden="true" data-eforms-review-fallback><span>Preview unavailable</span><button type="button" data-eforms-review-retry>Retry preview</button></span>
        <img hidden data-eforms-review-src="https://example.test/preview/second" alt="Preview of second photo" data-eforms-review-preview>
      </div>
    </article>
  `);
  await page.addScriptTag({ content: previewRuntime });

  await expect.poll(() => attempts).toEqual(['first', 'second']);
  await expect(page.locator('.eforms-review-preview').first().getByText('Preview unavailable')).toBeVisible();
  await expect(page.locator('[data-eforms-review-preview]').nth(1)).toBeVisible();
  await releaseFirst();
});
