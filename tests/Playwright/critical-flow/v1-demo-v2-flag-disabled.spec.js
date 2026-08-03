/**
 * Pivot V1 critical-flow — Demo V2 direct URL is blocked when flag is false.
 *
 * Requires FEATURE_WIZARD_PER_ITEM_DEMO=false on the Laravel backend.
 * Enable with E2E_BACKEND_AVAILABLE=1.
 */
const { test, expect } = require('@playwright/test');
const { login } = require('../../e2e/helpers/login');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
const ITEM_ID = process.env.E2E_DEMO_ITEM_ID || '1';
const BACKEND_AVAILABLE = /^(1|true|yes)$/i.test(process.env.E2E_BACKEND_AVAILABLE || '');

test.describe('Pivot V1 — Demo V2 flag disabled', () => {
  test.skip(!BACKEND_AVAILABLE, 'Backend live required: set E2E_BACKEND_AVAILABLE=1');
  test.setTimeout(90_000);

  test('redirects /admin/items/:id/composer to Catalog Studio when the V2 flag is off', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);

    await page.goto(`/admin/items/${ITEM_ID}/composer`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/items\/studio(?:$|[?#])/, { timeout: 20_000 });
    await expect(page.getByTestId('catalog-studio-page')).toBeVisible({ timeout: 30_000 });
  });
});
