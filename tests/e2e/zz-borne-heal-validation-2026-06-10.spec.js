// W-A BORNE — validation EMPIRIQUE des 2 heals (worktree patché servi sur :8767, DB foodking_e2e)
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8767 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   npx playwright test tests/e2e/zz-borne-heal-validation-2026-06-10.spec.js --retries=0
// H1 = F-BORNE-01 : landing catégorie == première catégorie (Sandwich Cayenne), plus « Boissons ».
// H2 = F-BORNE-07 : panier avec ligne upsell rend ses lignes, ZÉRO TypeError item_variations.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const REPO = path.resolve(__dirname, '../..');
const CAPS = path.join(REPO, 'reports/test-e2e/validation-profonde-2026-06-10/borne/captures/heal-validation');
fs.mkdirSync(CAPS, { recursive: true });
const snap = (page, name) => page.screenshot({ path: path.join(CAPS, `${name}.jpg`), type: 'jpeg', quality: 70 });

test.describe.configure({ timeout: 420_000 });

test('heals F-BORNE-01 + F-BORNE-07 validés sur build patché', async ({ page }) => {
  test.setTimeout(420_000);
  page.setDefaultTimeout(15_000);
  const tErrors = [];
  page.on('pageerror', (e) => tErrors.push(`pageerror:${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && /item_variations/.test(m.text())) tErrors.push(`console:${m.text().slice(0, 120)}`); });

  // Le cache menu (redis partagé, TTL 60 s) peut encore porter le payload du
  // serveur non patché — attendre l'expiration avant le premier rendu.
  await page.waitForTimeout(65_000);

  // -- H1 : landing catégorie
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  await expect(takeaway).toBeVisible({ timeout: 12_000 });
  await takeaway.click();
  await page.waitForURL(/\/kiosk\/categories/, { timeout: 15_000 });
  await page.waitForTimeout(2500);
  const title = (await page.locator('[data-testid="kiosk-categories-zone-title"]').innerText()).trim();
  await snap(page, 'h1-landing-category');
  console.log(`[H1] landing zone-title = "${title}"`);
  expect(title.toUpperCase(), 'landing = première catégorie (F-BORNE-01 healed)').toContain('SANDWICH CAYENNE');

  // -- H2 : upsell accept -> panier rend ses lignes sans TypeError
  await page.goto('/kiosk/categories?cat=10', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1800);
  await page.locator('[data-testid="kiosk-product-add-52"]').click();
  await page.waitForTimeout(1200);
  await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.locator('[data-testid="kiosk-cart-checkout"]').click();
  const upsellRoot = page.locator('[data-testid="kiosk-upsell-root"]');
  await upsellRoot.waitFor({ state: 'visible', timeout: 15_000 });
  await page.waitForTimeout(900);
  await page.locator('.kiosk-upsell-card').first().click();
  await page.waitForTimeout(400);
  await page.locator('[data-testid="kiosk-upsell-add-continue"]').click();
  await page.waitForURL(/\/kiosk\/payment/, { timeout: 15_000 });
  await page.waitForTimeout(1200);
  await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const nLines = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
  await snap(page, 'h2-cart-with-upsell');
  console.log(`[H2] cart lines = ${nLines} ; item_variations errors = ${tErrors.length}`);
  expect(nLines, 'panier rend ses lignes avec ligne upsell (F-BORNE-07 healed)').toBeGreaterThanOrEqual(2);
  expect(tErrors, `TypeError item_variations: ${tErrors.join(' | ')}`).toHaveLength(0);
});
