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
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <div class="eforms-review-preview eforms-review-preview-with-image">
        <span hidden aria-hidden="true" data-eforms-review-fallback>
          <span>Preview unavailable</span>
          <button type="button" class="eforms-review-button eforms-review-button--compact" data-eforms-review-retry>Retry preview</button>
        </span>
        <a class="eforms-review-preview-link ta-gallery__link" data-lbwps-width="1600" data-lbwps-height="900" aria-label="Open Photo 1">
          <img hidden data-eforms-review-src="data:image/jpeg;base64,invalid" alt="Photo 1 preview" data-eforms-review-preview>
        </a>
        <a class="eforms-review-download-overlay" href="/submitted-image" aria-label="Download Photo 1">
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
  await expect(page.getByRole('link', { name: 'Download Photo 1' })).toBeVisible();
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

test('download overlays stay secondary until review image hover or focus', async ({ page }) => {
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="gallery">
      <div class="eforms-review-preview eforms-review-preview-with-image" style="width:320px">
        <a class="eforms-review-preview-link ta-gallery__link" aria-label="Open Photo 1">
          <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=" alt="Photo 1 preview">
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
  await page.route('https://example.test/preview', async route => {
    attempts += 1;
    if (attempts === 1) {
      await route.fulfill({ status: 503, body: 'busy' });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'image/png',
      body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')
    });
  });
  await page.setContent(`
    <style>${formsCss}</style>
    <article class="eforms-review-page" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <div class="eforms-review-preview eforms-review-preview-with-image">
        <span hidden aria-hidden="true" data-eforms-review-fallback>
          <span>Preview unavailable</span>
          <button type="button" class="eforms-review-button eforms-review-button--compact" data-eforms-review-retry>Retry preview</button>
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
  const previewLink = page.locator('a[aria-label="Open Photo 1"]');
  await expect(retry).toBeVisible();
  await expect(previewLink).toHaveAttribute('aria-disabled', 'true');
  await retry.click();
  await expect(page.locator('[data-eforms-review-preview]')).toBeVisible();
  await expect(page.locator('[data-eforms-review-preview]')).toHaveAttribute('alt', 'Photo 1 preview');
  await expect(previewLink).not.toHaveAttribute('aria-disabled');
  await expect(previewLink).not.toHaveAttribute('tabindex', '-1');
  await expect(previewLink).toHaveAttribute('href', 'https://example.test/preview');
  await expect(previewLink).toHaveAttribute('data-lbwps-srcsmall', 'https://example.test/preview');
  await expect(page.getByRole('link', { name: 'Open Photo 1' })).toBeVisible();
  await expect(page.getByText('Preview unavailable').first()).toBeHidden();
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
        <img hidden data-eforms-review-src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=" alt="Preview of phone photo" data-eforms-review-preview>
      </a>
    </div>
    <div class="eforms-review-preview eforms-review-preview-with-image" data-eforms-review="gallery" data-eforms-review-preview-timeout-ms="1000">
      <span hidden aria-hidden="true" data-eforms-review-fallback>
        <span>Preview unavailable</span>
        <button type="button" data-eforms-review-retry>Retry preview</button>
      </span>
      <a class="ta-gallery__link" data-lbwps-width="1600" data-lbwps-height="900" aria-label="Open preview of second photo">
        <img hidden data-eforms-review-src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=" alt="Preview of second photo" data-eforms-review-preview>
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
    <article class="eforms-review-page" data-eforms-review="gallery" style="max-width:52rem">
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
  const layout = await page.locator('.eforms-review-actions').evaluate(actions => {
    const summary = actions.querySelector('.eforms-review-summary');
    const idLine = summary.querySelector('span');
    const buttons = actions.querySelector('.eforms-review-action-buttons');
    const actionBox = actions.getBoundingClientRect();
    const summaryBox = summary.getBoundingClientRect();
    const idLineBox = idLine.getBoundingClientRect();
    const buttonBox = buttons.getBoundingClientRect();
    return {
      display: getComputedStyle(actions).display,
      gridTemplateColumns: getComputedStyle(actions).gridTemplateColumns,
      buttonDirection: getComputedStyle(buttons).flexDirection,
      summaryTop: summaryBox.top,
      summaryLeft: summaryBox.left,
      summaryRight: summaryBox.right,
      idLineHeight: idLineBox.height,
      buttonTop: buttonBox.top,
      buttonLeft: buttonBox.left,
      buttonRight: buttonBox.right,
      buttonHeight: buttonBox.height,
      actionsLeft: actionBox.left,
      actionsRight: actionBox.right
    };
  });

  expect(layout.display).toBe('grid');
  expect(layout.gridTemplateColumns.split(' ')).toHaveLength(2);
  expect(layout.buttonDirection).toBe('column');
  expect(Math.abs(layout.summaryTop - layout.buttonTop)).toBeLessThanOrEqual(1);
  expect(Math.abs(layout.summaryLeft - layout.actionsLeft)).toBeLessThanOrEqual(1);
  expect(layout.buttonLeft).toBeGreaterThan(layout.summaryRight);
  expect(Math.abs(layout.buttonRight - layout.actionsRight)).toBeLessThanOrEqual(1);
  expect(layout.buttonHeight).toBeGreaterThan(96);
  expect(layout.idLineHeight).toBeLessThanOrEqual(30);

  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.locator('.eforms-review-actions').evaluate(actions => getComputedStyle(actions).gridTemplateColumns.split(' ').length)).toBe(1);
  await expect(page.locator('.eforms-review-action-buttons')).toHaveCSS('justify-self', 'stretch');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
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
  await page.setContent(`<style>${formsCss}.eforms-review-page{max-width:52rem}</style>${operatorHtml}`);

  await expect(page.locator('.eforms-review-attribution-by')).toHaveText('by');
  await expect(page.locator('.eforms-review-attribution-name')).toHaveText('Ada Lovelace');
  await expect(page.getByText('Name', { exact: true })).toHaveCount(0);
  await expect(page.getByText('80231')).toBeVisible();
  await expect(page.getByText('ada@example.test')).toBeVisible();
  await expect(page.getByText('720-900-5278')).toBeVisible();
  await expect(page.getByRole('link', { name: 'https://example.test/listing' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Download Photo 1' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Update availability' })).toBeVisible();

  const attributionLayout = await page.locator('.eforms-review-attribution').evaluate(attribution => {
    const heading = attribution.closest('.eforms-review-heading');
    const title = heading.querySelector('.page-title').getBoundingClientRect();
    const by = attribution.querySelector('.eforms-review-attribution-by').getBoundingClientRect();
    const name = attribution.querySelector('.eforms-review-attribution-name').getBoundingClientRect();
    return {
      textAlign: getComputedStyle(attribution).textAlign,
      headingDirection: getComputedStyle(heading).flexDirection,
      byDisplay: getComputedStyle(attribution.querySelector('.eforms-review-attribution-by')).display,
      attributionTop: attribution.getBoundingClientRect().top,
      titleBottom: title.bottom,
      nameTop: name.top,
      byBottom: by.bottom,
      nameLeft: name.left,
      titleLeft: title.left,
      nameRight: name.right,
      titleRight: title.right
    };
  });
  expect(attributionLayout.headingDirection).toBe('column');
  expect(attributionLayout.textAlign).toBe('center');
  expect(attributionLayout.byDisplay).toBe('block');
  expect(attributionLayout.attributionTop).toBeGreaterThan(attributionLayout.titleBottom);
  expect(attributionLayout.nameTop).toBeGreaterThan(attributionLayout.byBottom);
  expect(attributionLayout.nameLeft).toBeGreaterThanOrEqual(attributionLayout.titleLeft - 1);
  expect(attributionLayout.nameRight).toBeLessThanOrEqual(attributionLayout.titleRight + 1);

  const factsLists = page.locator('.eforms-review-facts-list');
  await expect(factsLists).toHaveCount(2);
  expect(await factsLists.nth(0).evaluate(list => getComputedStyle(list).gridTemplateColumns.split(' ').length)).toBe(3);
  const contactRowLayout = await factsLists.nth(0).evaluate(list => {
    const facts = Array.from(list.querySelectorAll('.eforms-review-fact')).map(fact => fact.getBoundingClientRect());
    return {
      tops: facts.map(fact => fact.top),
      firstRight: facts[0].right,
      secondLeft: facts[1].left,
      thirdLeft: facts[2].left
    };
  });
  expect(Math.max(...contactRowLayout.tops) - Math.min(...contactRowLayout.tops)).toBeLessThanOrEqual(2);
  expect(contactRowLayout.secondLeft).toBeGreaterThan(contactRowLayout.firstRight - 1);
  expect(contactRowLayout.thirdLeft).toBeGreaterThan(contactRowLayout.secondLeft);

  expect(await factsLists.nth(1).evaluate(list => getComputedStyle(list).gridTemplateColumns.split(' ').length)).toBe(3);
  const operatorDetailLayout = await factsLists.nth(1).evaluate(list => {
    const description = list.querySelector('.eforms-review-fact--wide').getBoundingClientRect();
    const squareFootage = Array.from(list.querySelectorAll('.eforms-review-fact')).find(fact => fact.textContent.includes('Square Footage')).getBoundingClientRect();
    const bounds = list.getBoundingClientRect();
    return {
      descriptionLeft: description.left,
      descriptionRight: description.right,
      detailsLeft: bounds.left,
      detailsRight: bounds.right,
      descriptionBottom: description.bottom,
      squareFootageTop: squareFootage.top
    };
  });
  expect(Math.abs(operatorDetailLayout.descriptionLeft - operatorDetailLayout.detailsLeft)).toBeLessThanOrEqual(1);
  expect(Math.abs(operatorDetailLayout.descriptionRight - operatorDetailLayout.detailsRight)).toBeLessThanOrEqual(1);
  expect(operatorDetailLayout.squareFootageTop).toBeGreaterThan(operatorDetailLayout.descriptionBottom);

  await page.setViewportSize({ width: 390, height: 844 });
  const mobile = await page.locator('.eforms-review-page').evaluate(pageRoot => {
    const facts = pageRoot.querySelector('.eforms-review-facts').getBoundingClientRect();
    const lists = pageRoot.querySelectorAll('.eforms-review-facts-list');
    const details = lists[1].getBoundingClientRect();
    const grid = pageRoot.querySelector('.eforms-review-grid').getBoundingClientRect();
    const actions = pageRoot.querySelector('.eforms-review-actions').getBoundingClientRect();
    return {
      factsTop: facts.top,
      detailsTop: details.top,
      gridTop: grid.top,
      actionsTop: actions.top,
      detailsColumns: getComputedStyle(lists[1]).gridTemplateColumns.split(' ').length,
      scrollWidth: document.documentElement.scrollWidth
    };
  });
  expect(mobile.factsTop).toBeLessThan(mobile.detailsTop);
  expect(mobile.detailsTop).toBeLessThan(mobile.gridTop);
  expect(mobile.gridTop).toBeLessThan(mobile.actionsTop);
  expect(mobile.detailsColumns).toBe(1);
  expect(mobile.scrollWidth).toBe(390);

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
  await expect(page.locator('.eforms-review-facts')).toHaveCount(1);
  await expect(page.getByText('Refinish the main floor.')).toBeVisible();
  await expect(page.getByText('1145')).toBeVisible();
  await expect(page.locator('body')).not.toContainText('Ada Lovelace');
  await expect(page.locator('body')).not.toContainText('ada@example.test');
  await expect(page.locator('body')).not.toContainText('720-900-5278');
  await expect(page.locator('body')).not.toContainText('80231');
  await expect(page.locator('body')).not.toContainText('https://example.test/listing');

  const anonymousMobile = await page.locator('.eforms-review-page').evaluate(pageRoot => {
    const project = pageRoot.querySelector('.eforms-review-facts').getBoundingClientRect();
    const list = pageRoot.querySelector('.eforms-review-facts-list');
    const grid = pageRoot.querySelector('.eforms-review-grid').getBoundingClientRect();
    return {
      projectTop: project.top,
      gridTop: grid.top,
      projectColumns: getComputedStyle(list).gridTemplateColumns.split(' ').length,
      scrollWidth: document.documentElement.scrollWidth
    };
  });
  expect(anonymousMobile.projectTop).toBeLessThan(anonymousMobile.gridTop);
  expect(anonymousMobile.projectColumns).toBe(1);
  expect(anonymousMobile.scrollWidth).toBe(390);
});
test('operator action controls open and close their dialogs', async ({ page }) => {
  await page.setContent(`
    <style>${formsCss}</style>
    <style>
      form input { box-sizing:border-box; width:100%; min-height:3rem; }
      form button[type="submit"] { display:block; width:18rem; min-width:18.75rem; margin-top:2.25rem; padding:.5em 1em; border-radius:35px; font-size:1.5rem; text-transform:uppercase; }
    </style>
    <article class="eforms-review-page" data-eforms-review="gallery">
      <div class="eforms-review-action-buttons">
        <button type="button" class="eforms-review-button eforms-review-availability-open" data-eforms-review-availability-open>Update availability</button>
        <button type="button" class="eforms-review-button eforms-review-button--danger-outline eforms-review-delete-open" data-eforms-review-delete-open>Delete submission</button>
      </div>
      <dialog class="eforms-review-delete-dialog eforms-review-availability-dialog" data-eforms-review-availability-dialog>
        <form method="post" action="/">
          <h2>Update availability</h2>
          <p>Choose how long this submitted photo gallery should remain available.</p>
          <input type="hidden" name="eforms_review_action" value="update_availability">
          <input type="hidden" name="_eforms_review_availability_nonce" value="nonce">
          <div class="eforms-review-availability-options">
            <label><input type="radio" name="eforms_review_availability" value="30_days"> 30 days</label>
            <label><input type="radio" name="eforms_review_availability" value="90_days"> 90 days</label>
            <label><input type="radio" name="eforms_review_availability" value="1_year"> 1 year</label>
            <label><input type="radio" name="eforms_review_availability" value="manual"> Until manually deleted</label>
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
          <p>This deletes the review submission and its photos.</p>
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
  const boxes = await dialog.locator('.eforms-review-delete-actions button').evaluateAll(buttons =>
    buttons.map(button => {
      const rect = button.getBoundingClientRect();
      const style = getComputedStyle(button);
      return {
        top: rect.top,
        right: rect.right,
        bottom: rect.bottom,
        height: rect.height,
        width: rect.width,
        marginTop: style.marginTop,
        fontSize: style.fontSize,
        textTransform: style.textTransform,
        borderRadius: parseFloat(style.borderTopLeftRadius) || 0
      };
    })
  );
  expect(Math.abs(boxes[0].top - boxes[1].top)).toBeLessThanOrEqual(1);
  expect(Math.abs(boxes[0].bottom - boxes[1].bottom)).toBeLessThanOrEqual(1);
  expect(Math.abs(boxes[0].height - boxes[1].height)).toBeLessThanOrEqual(1);
  expect(boxes[0].marginTop).toBe('0px');
  expect(boxes[1].marginTop).toBe('0px');
  expect(boxes[0].fontSize).toBe(boxes[1].fontSize);
  expect(boxes[0].textTransform).toBe('none');
  expect(boxes[1].textTransform).toBe('none');
  expect(boxes[0].height).toBeGreaterThanOrEqual(44);
  expect(boxes[1].height).toBeGreaterThanOrEqual(44);
  expect(boxes[0].borderRadius).toBeGreaterThan(0);
  expect(boxes[0].borderRadius).toBeLessThan(boxes[0].height / 2);
  expect(Math.abs(boxes[0].borderRadius - boxes[1].borderRadius)).toBeLessThanOrEqual(1);
  expect(boxes[0].width).toBeLessThan(300);
  expect(boxes[1].width).toBeLessThan(300);
  await page.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).not.toHaveAttribute('open', '');

  const availabilityDialog = page.locator('[data-eforms-review-availability-dialog]');
  await expect(availabilityDialog).not.toHaveAttribute('open', '');
  await page.getByRole('button', { name: 'Update availability' }).click();
  await expect(availabilityDialog).toHaveAttribute('open', '');
  await expect(availabilityDialog.getByLabel('30 days')).not.toBeChecked();
  await expect(availabilityDialog.getByLabel('90 days')).not.toBeChecked();
  await expect(availabilityDialog.getByLabel('1 year')).not.toBeChecked();
  await expect(availabilityDialog.getByLabel('Until manually deleted')).not.toBeChecked();
  await expect(availabilityDialog.getByLabel('90 days')).toBeVisible();
  await expect(availabilityDialog.getByLabel('1 year')).toBeVisible();
  await expect(availabilityDialog.getByLabel('Until manually deleted')).toBeVisible();
  const radioLayout = await availabilityDialog.locator('.eforms-review-availability-options label').evaluateAll(labels => labels.map(label => {
    const input = label.querySelector('input[type="radio"]');
    const labelBox = label.getBoundingClientRect();
    const inputBox = input.getBoundingClientRect();
    return {
      labelWidth: labelBox.width,
      inputWidth: inputBox.width,
      inputLeft: inputBox.left - labelBox.left
    };
  }));
  for (const option of radioLayout) {
    expect(option.inputWidth).toBeLessThanOrEqual(32);
    expect(option.inputLeft).toBeLessThanOrEqual(2);
    expect(option.inputWidth).toBeLessThan(option.labelWidth / 2);
  }
  await expect(availabilityDialog.locator('input[type="number"]')).toHaveCount(0);
  await expect(availabilityDialog).not.toContainText('Archive');
  await expect(availabilityDialog).not.toContainText('link expires');
  await expect(availabilityDialog.locator('.eforms-review-delete-actions button')).toHaveCount(2);
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
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  await page.route('https://example.test/preview/**', async route => {
    const name = new URL(route.request().url()).pathname.split('/').pop();
    attempts.push(name);
    if (name === 'first') {
      await new Promise(resolve => {
        releaseFirst = async () => {
          await route.fulfill({ status: 200, contentType: 'image/png', body: png });
          resolve();
        };
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'image/png', body: png });
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
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
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
    await route.fulfill({ status: 200, contentType: 'image/png', body: png });
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
