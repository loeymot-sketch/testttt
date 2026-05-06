/**
 * Critical-flow Catalog Studio — End-to-end de l'arbre central :
 *   admin → /admin/items/studio → catégorie → produit → drawer composer (wizard).
 *
 * Pré-requis :
 *  - serveur Laravel up (PLAYWRIGHT_BASE_URL),
 *  - admin seedé (E2E_ADMIN_USER / E2E_ADMIN_PASS),
 *  - catalogue seedé avec ≥1 catégorie et ≥1 produit.
 *
 * Périmètre : non destructif — n'écrit pas, n'efface pas. Crée optionnellement une
 * catégorie de test (avec timestamp) puis nettoie best-effort, mais l'assertion
 * critique est le pipeline « catégorie → produit → composer drawer » via UI.
 */
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';

const STAMP = Date.now();
const CATEGORY_NAME = `E2E Cat ${STAMP}`;

test.describe('Catalog Studio — create-product critical flow', () => {
  test.setTimeout(150_000);

  test('admin can open Studio, create a category, and open the wizard composer drawer on an existing product', async ({ page }) => {
    clearFoodKingRateLimits();
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);

    await page.goto('/admin/items/studio', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('catalog-studio-page')).toBeVisible({ timeout: 30_000 });

    const categoryForm = page.locator('aside.catalog-studio__sidebar form.catalog-studio__quick-form');
    const addCategoryBtn = page.getByTestId('catalog-studio-add-category');
    await expect(addCategoryBtn).toBeVisible();
    await addCategoryBtn.click();
    await expect(categoryForm).toBeVisible({ timeout: 10_000 });
    await categoryForm.locator('input[type="text"]').first().fill(CATEGORY_NAME);
    const categoryPost = page.waitForResponse(
      (r) => {
        if (r.request().method() !== 'POST') return false;
        if (!/\/api\/admin\/setting\/item-category\/?($|\?)/.test(r.url())) return false;
        const body = r.request().postData() || '';
        return body.includes(CATEGORY_NAME);
      },
      { timeout: 30_000 },
    );
    await Promise.all([categoryPost, categoryForm.locator('button[type="submit"]').click()]);
    const catResp = await categoryPost;
    expect(catResp.ok(), `category POST failed: HTTP ${catResp.status()}`).toBeTruthy();

    const newCategoryRow = page.locator(
      `[data-testid^="catalog-studio-category-row-"]:has-text("${CATEGORY_NAME}")`,
    );
    await expect(newCategoryRow).toBeVisible({ timeout: 20_000 });

    const seedCategoryRow = page.locator('[data-testid^="catalog-studio-category-row-"]')
      .filter({ has: page.locator('button.catalog-studio__category') })
      .first();
    await expect(seedCategoryRow).toBeVisible({ timeout: 10_000 });
    await seedCategoryRow.locator('button.catalog-studio__category').first().click();

    const grid = page.getByTestId('catalog-studio-products-grid');
    await expect(grid).toBeVisible({ timeout: 15_000 });

    const firstProduct = page.locator('article.catalog-studio__product').first();
    let productCardForCleanup = firstProduct;

    if (!(await firstProduct.isVisible({ timeout: 8_000 }).catch(() => false))) {
      const fallbackCategory = page.locator(
        '[data-testid^="catalog-studio-category-row-"]:not(:has-text("' + CATEGORY_NAME + '"))',
      ).first();
      await fallbackCategory.locator('button.catalog-studio__category').first().click();
      const fallbackProduct = page.locator('article.catalog-studio__product').first();
      await expect(fallbackProduct).toBeVisible({ timeout: 10_000 });
      productCardForCleanup = fallbackProduct;
    }

    const wizardButton = productCardForCleanup.locator(
      '[data-testid^="catalog-studio-product-wizard-"]',
    );
    await expect(wizardButton).toBeVisible();
    await wizardButton.click();

    const composerDrawer = page.getByTestId('catalog-studio-composer-overlay');
    await expect(composerDrawer).toBeVisible({ timeout: 25_000 });
    await expect(page.getByTestId('catalog-studio-composer-frame')).toBeVisible();

    await page.getByTestId('catalog-studio-composer-close').click();
    await expect(composerDrawer).not.toBeVisible({ timeout: 10_000 });

    try {
      const deleteCategoryBtn = newCategoryRow.locator(
        '[data-testid^="catalog-studio-category-delete-"]',
      );
      if (await deleteCategoryBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
        page.once('dialog', (d) => d.accept());
        await deleteCategoryBtn.click();
      }
    } catch {
      /* cleanup best-effort */
    }
  });
});
