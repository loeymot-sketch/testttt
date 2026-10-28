/**
 * Pivot V1 critical-flow — category wizard entry point.
 *
 * The destructive drawer CRUD/publish path remains intentionally fixme because
 * the editor is loaded inside a drawer iframe and needs stable seeded fixtures.
 */
const { test, expect } = require('@playwright/test');
const { login } = require('../../e2e/helpers/login');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
const CATEGORY_ID = process.env.E2E_CATEGORY_ID || '';
const BACKEND_AVAILABLE = /^(1|true|yes)$/i.test(process.env.E2E_BACKEND_AVAILABLE || '');

test.describe('Pivot V1 — category wizard affects products', () => {
  test.skip(!BACKEND_AVAILABLE, 'Backend live required: set E2E_BACKEND_AVAILABLE=1');
  test.setTimeout(120_000);

  test('opens the category composer drawer; product cards keep per-item composer entry', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);

    const targetUrl = CATEGORY_ID
      ? `/admin/items/studio?item_category_id=${encodeURIComponent(CATEGORY_ID)}`
      : '/admin/items/studio';
    await page.goto(targetUrl, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('catalog-studio-page')).toBeVisible({ timeout: 30_000 });

    if (!CATEGORY_ID) {
      const firstCategory = page.locator('[data-testid^="catalog-studio-category-row-"]').first();
      await expect(firstCategory).toBeVisible({ timeout: 20_000 });
      await firstCategory.locator('button.catalog-studio__category').first().click();
    }

    await expect(page.getByTestId('catalog-studio-category-wizard-entry')).toBeVisible({ timeout: 20_000 });
    const productCards = page.locator('article.catalog-studio__product');
    const productCount = await productCards.count();
    // [ONB-03 2026-08-28] Le bouton composeur PAR PRODUIT n'apparait que si
    // `wizard_per_item_demo` est leve — il vaut false par defaut, et le routeur
    // redirige alors vers le catalogue. Exiger le bouton sans regarder le drapeau,
    // c'etait exiger un bouton qui ne mene nulle part. On lit le drapeau REELLEMENT
    // expose par le serveur au lieu de le supposer.
    const wizardParProduit = await page.evaluate(
      () => window.foodkingConfig?.features?.wizard_per_item_demo === true,
    );
    if (productCount > 0 && wizardParProduit) {
      await expect(page.locator('[data-testid^="catalog-studio-product-wizard-"]')).toHaveCount(productCount);
    }
    if (!wizardParProduit) {
      await expect(page.locator('[data-testid^="catalog-studio-product-wizard-"]')).toHaveCount(0);
    }

    await page.getByTestId('catalog-studio-category-wizard-button').click();
    await expect(page.getByTestId('catalog-studio-composer-overlay')).toBeVisible({ timeout: 25_000 });
    await expect(page.getByTestId('catalog-studio-composer-frame')).toBeVisible();
  });

  test.fixme('adds a dummy category step and verifies it from a product runtime surface', async () => {
    // Deferred V1.5 debt: drawer iframe CRUD is fixture-sensitive and should run
    // once a stable test category with 2+ products and writable composer data exists.
  });
});
