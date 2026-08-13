/**
 * GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13 — real persistence proof,
 * single-form Settings pages (companion to admin-settings-crud-functional
 * which covers list-based Currency/Tax). Same technique already proven live
 * on Mail (change value -> save -> reload -> value persisted): applied here
 * to Social Media and Loyalty setup. Each test mutates a real field, saves,
 * reloads to prove persistence (not a stale client-side echo), then
 * restores the original value so the page is left exactly as found.
 *
 * (Site settings was attempted and dropped: its form mixes plain inputs with
 * custom multiselect components for required fields, which need dedicated
 * interaction handling beyond this generic technique — not a defect, just
 * out of scope for this pass.)
 */
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

test.describe.serial('Real persistence proof — Social Media, Loyalty setup (single-form Settings)', () => {
  test.setTimeout(180_000);
  let page;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    if (page) await page.context().close().catch(() => {});
  });

  test('Social Media: facebook URL edit -> save -> reload -> persisted -> restored', async () => {
    // social_media_facebook is validated as ['nullable', 'url'] (SocialMediaRequest.php:27) —
    // the mutated value must itself be a valid URL, not an arbitrary appended marker.
    const url = '/admin/settings/social-media';
    const fieldId = 'social_media_facebook';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator(`#${fieldId}`);
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = `https://facebook.com/e2e-test-${Date.now()}`;
    await field.fill(mutated);
    await field.press('Tab');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(mutated, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Social Media: field mutated, saved, reload confirms persistence.');

    await page.locator(`#${fieldId}`).fill(original);
    await page.locator(`#${fieldId}`).press('Tab');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Social Media: restored to original value.');
  });

  test('Loyalty setup: points-per-euro edit -> save -> reload -> persisted -> restored', async () => {
    const url = '/admin/settings/loyalty-setup';
    const fieldId = 'loyalty_points_per_euro';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator(`#${fieldId}`);
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = String((parseFloat(original) || 0) + 1);
    await field.fill(mutated);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(mutated, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Loyalty setup: ${original} -> ${mutated}, reload confirms persistence.`);

    await page.locator(`#${fieldId}`).fill(original);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Loyalty setup: restored to original value.');
  });
});
