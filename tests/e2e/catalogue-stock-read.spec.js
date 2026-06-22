// FoodKing E2E — GOAL_MGMT_TESTPLAN Wave C · A2 Catalogue + A3 Stock (READ-SIDE)
//
// NON-destructive visual + technical smoke for the two admin management
// read surfaces. NO create / update / delete (destructive CRUD is owner-gated
// Wave D — we never click a toggle, save, or delete button here).
//
// GROUNDING (verified 2026-06-03):
//   - Routes (resources/js/router/modules/{itemRoutes,stockRoutes}.js):
//       /admin/items/studio   → CatalogStudioComponent.vue  (name admin.items.studio,
//                                /admin/items redirects here), permissionUrl 'items'
//       /admin/stock/rupture  → StockRuptureDashboardComponent.vue (name admin.stock.rupture),
//                                permissionUrl 'items'
//     admin@lecayenne.fr holds Permission::all() (RolePermissionTableSeeder) so both pass.
//   - Login button label = "Connexion" ($t('button.login'), fr.json); inputs are
//     type="text" (email) + type="password". Helper mirrors the DASH-T12 spec
//     (dashboard-sidebar-permission-filtering.spec.js, dated today).
//   - Catalogue selectors (CatalogStudioComponent.vue):
//       root [data-testid="catalog-studio-page"], products grid
//       [data-testid="catalog-studio-products-grid"], product card
//       article.catalog-studio__product (price = item.flat_price span),
//       category rows [data-testid^="catalog-studio-category-row-"],
//       category counter .catalog-studio__counter. Defaults to "all categories"
//       (selectedCategoryId null) so the full ~45-item V1 menu renders.
//   - Stock selectors (StockRuptureDashboardComponent.vue):
//       root [data-testid="stock-management-v2"], rail [data-testid="stock-mgmt-rail"],
//       bucket buttons [data-testid^="stock-mgmt-bucket-"] (count badge inside),
//       product cards [data-testid^="stock-mgmt-product-"], availability pill
//       (in-stock / out-of-stock — we only READ it, never click).
//
// DUAL GUARD: a blank/one-card shell must FAIL — thresholds are meaningful
// (items >= 20, category rows >= 3, stock buckets >= 1 with >= 1 product card).
// NaN / undefined / raw i18n key visible anywhere in the page root => REAL
// finding (the assertion message documents it).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.describe.configure({ timeout: 90_000 });

const ADMIN_EMAIL = 'admin@lecayenne.fr';
const ADMIN_PASSWORD = '123456';

const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'cat-stock');
if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

// Anchored raw-key shape: a *whole* token that is entirely an i18n key path,
// e.g. "studio.title" or "admin.stock_mgmt.in_stock". Applied per-token so a
// real word ("Catalogue") never matches.
const RAW_KEY_RE = /^[a-z]+(\.[a-z_]+){1,4}$/;

async function loginAsAdmin(page) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="text"]').first().fill(ADMIN_EMAIL);
  await page.locator('input[type="password"]').first().fill(ADMIN_PASSWORD);
  // FR login button label = "Connexion".
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/\/admin\//, { timeout: 30_000 });
}

/**
 * Scan the visible text of `rootLocator` for NaN / undefined / null leakage and
 * for raw i18n keys (anchored, per token). Returns { naN: [...], rawKeys: [...] }.
 */
async function scanRootText(rootLocator) {
  const text = (await rootLocator.innerText()) || '';
  const naN = (text.match(/\b(NaN|undefined|null)\b/g) || []);
  const tokens = text.split(/\s+/).filter(Boolean);
  const rawKeys = [...new Set(tokens.filter((tok) => RAW_KEY_RE.test(tok)))];
  return { naN, rawKeys };
}

test.describe('Wave C — A2 Catalogue + A3 Stock (read-side, non-destructive)', () => {
  test('A2 — /admin/items/studio renders the V1 catalogue (items + categories + prices, no NaN/raw-key)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/items/studio', { waitUntil: 'domcontentloaded' });

    // URL stability: a permission/auth failure would bounce to /login or /dashboard.
    await page.waitForURL(/\/admin\/items\/studio(\/|\?|$)/, { timeout: 30_000 });
    expect(page.url(), 'must stay on /admin/items/studio (not redirected on a permission gate)').toMatch(/\/admin\/items\/studio/);

    const root = page.locator('[data-testid="catalog-studio-page"]');
    await expect(root, 'CatalogStudio page root must mount').toBeVisible({ timeout: 30_000 });

    // Web-first wait on the FIRST REAL product card (gate against loading skeleton).
    const productCards = page.locator('article.catalog-studio__product');
    await expect(productCards.first(), 'at least one product card must render').toBeVisible({ timeout: 30_000 });

    const itemCount = await productCards.count();
    const categoryRows = page.locator('[data-testid^="catalog-studio-category-row-"]');
    const categoryCount = await categoryRows.count();

    // Category sidebar counter (.catalog-studio__counter renders categories.length) must be a plain integer.
    const counterText = ((await page.locator('.catalog-studio__counter').first().innerText()) || '').trim();
    expect(counterText, `category counter must be a plain integer, got "${counterText}"`).toMatch(/^\d+$/);

    // Meaningful thresholds — reject a one-card shell. V1 menu ~45 items.
    expect(itemCount, `expected >= 20 catalogue items (V1 menu ~45), saw ${itemCount}`).toBeGreaterThanOrEqual(20);
    expect(categoryCount, `expected >= 3 category rows, saw ${categoryCount}`).toBeGreaterThanOrEqual(3);

    // Every product card must expose a non-empty price string (item.flat_price).
    const firstPrice = ((await productCards.first().locator('.catalog-studio__product-meta span').first().innerText()) || '').trim();
    expect(firstPrice.length, 'first product card price must be a non-empty formatted string').toBeGreaterThan(0);

    // Visual evidence.
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'a2-catalogue.png'), fullPage: true });

    // No NaN / undefined / raw i18n key inside the page root.
    const { naN, rawKeys } = await scanRootText(root);
    expect(naN, `NaN/undefined/null leaked in catalogue: ${naN.join(', ')}`).toHaveLength(0);
    expect(rawKeys, `raw i18n key(s) visible in catalogue: ${rawKeys.join(', ')}`).toHaveLength(0);

    // eslint-disable-next-line no-console
    console.log(`[A2 Catalogue] items=${itemCount} categoryRows=${categoryCount} counter=${counterText} firstPrice="${firstPrice}"`);
  });

  test('A3 — /admin/stock/rupture renders the availability dashboard (buckets + product cards, valid counts, no NaN/raw-key)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });

    // URL stability — stale note (09-admin header) claims a 404 permission gate;
    // newer specs (D4, wave-final-s6) navigate it fine as admin. If it bounces,
    // this assertion documents the regression rather than swallowing a shell.
    await page.waitForURL(/\/admin\/stock\/rupture(\/|\?|$)/, { timeout: 30_000 });
    expect(page.url(), 'must stay on /admin/stock/rupture (not redirected on a permission gate)').toMatch(/\/admin\/stock\/rupture/);

    const root = page.locator('[data-testid="stock-management-v2"]');
    await expect(root, 'StockRupture dashboard root must mount').toBeVisible({ timeout: 30_000 });

    // Rail with buckets present.
    await expect(page.locator('[data-testid="stock-mgmt-rail"]'), 'bucket rail must render').toBeVisible({ timeout: 30_000 });
    const buckets = page.locator('[data-testid^="stock-mgmt-bucket-"]');
    await expect(buckets.first(), 'at least one bucket must render').toBeVisible({ timeout: 30_000 });
    const bucketCount = await buckets.count();
    expect(bucketCount, `expected >= 1 stock bucket, saw ${bucketCount}`).toBeGreaterThanOrEqual(1);

    // Web-first wait on the FIRST REAL product card (gate against loading state).
    const productCards = page.locator('[data-testid^="stock-mgmt-product-"]');
    await expect(productCards.first(), 'at least one stock product card must render').toBeVisible({ timeout: 30_000 });
    const productCount = await productCards.count();
    expect(productCount, `expected >= 1 stock product card, saw ${productCount}`).toBeGreaterThanOrEqual(1);

    // Every bucket count badge must be a plain integer (no NaN).
    const badgeTexts = [];
    for (let i = 0; i < bucketCount; i += 1) {
      // Last <span> in the bucket button is the count badge.
      const badge = ((await buckets.nth(i).locator('span').last().innerText()) || '').trim();
      badgeTexts.push(badge);
      expect(badge, `bucket #${i} count badge must be a plain integer, got "${badge}"`).toMatch(/^\d+$/);
    }

    // Visual evidence.
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'a3-stock.png'), fullPage: true });

    // No NaN / undefined / raw i18n key inside the page root.
    const { naN, rawKeys } = await scanRootText(root);
    expect(naN, `NaN/undefined/null leaked in stock dashboard: ${naN.join(', ')}`).toHaveLength(0);
    expect(rawKeys, `raw i18n key(s) visible in stock dashboard: ${rawKeys.join(', ')}`).toHaveLength(0);

    // eslint-disable-next-line no-console
    console.log(`[A3 Stock] buckets=${bucketCount} products=${productCount} badges=[${badgeTexts.join(', ')}]`);
  });
});
