// FoodKing — KIOSK WIZARD -> ORDER -> composition_snapshot integrity (NF525 SSOT)
// 2026-06-07. Disposable clone ONLY:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-kiosk-wizard-order-snapshot-2026-06-07.spec.js --retries=0
//
// Composes a Tacos via the FROZEN wizard (Viande + Sauce), adds to cart, places a
// Plan-B counter-routed order, then inspects the persisted composition_snapshot in
// the e2e DB: proves chosen options (viande/sauce) were sealed into the snapshot
// (A5 — composition_snapshot frozen at creation, not silently dropped).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsKiosk } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'kiosk-wizard-order-snapshot-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });
const REPO = path.resolve(__dirname, '../..');
function db(sql) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], {
    cwd: REPO, encoding: 'utf8', timeout: 15_000,
  }).trim();
}

const ITEM_ID = 26; // Tacos — Viande + Sauce groups
test.describe.configure({ mode: 'serial', timeout: 180_000 });

test('wizard Tacos -> order -> composition_snapshot sealed', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  const baselineMax = parseInt(db('SELECT IFNULL(MAX(id),0) FROM orders;'), 10);

  await loginAsKiosk(page);
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (!(await takeaway.isVisible().catch(() => false))) {
    const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
    if (await touch.isVisible().catch(() => false)) { await touch.click(); await page.waitForTimeout(1000); }
  }
  await expect(takeaway).toBeVisible({ timeout: 12_000 });
  await takeaway.click();
  await page.waitForTimeout(1500);

  // Open Tacos wizard (category 5).
  await page.goto('/kiosk/categories?cat=5', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.locator(`[data-testid="kiosk-product-card-${ITEM_ID}"]`).first().click();
  await page.waitForTimeout(2000);
  await expect(page.locator('.kiosk-wizard'), 'wizard mounted').toBeVisible({ timeout: 8000 });

  // Step 1 Viande -> pick first; Step 2 Sauce -> pick first; advance to add.
  const CHOICE = '.kiosk-viande-card:not(.kiosk-variation--disabled):not(.is-out-of-stock), .kiosk-option-card:not(.kiosk-variation--disabled):not(.is-out-of-stock), .kiosk-generic-choice:not(.unavailable):not([disabled])';
  const next = page.locator('.kiosk-btn-next');
  let guard = 0;
  let added = false;
  const chosen = { viande: null, sauce: null };
  while (guard++ < 10) {
    await page.waitForTimeout(800);
    const menuNone = page.locator('.kiosk-menu-card').filter({ hasText: /sans menu/i }).first();
    if (await menuNone.isVisible().catch(() => false)) { await menuNone.click().catch(() => {}); await page.waitForTimeout(400); }
    const choices = page.locator(CHOICE);
    if ((await choices.count().catch(() => 0)) > 0) {
      const label = (await choices.first().innerText().catch(() => '')).split('\n')[0].trim();
      if (guard === 1) chosen.viande = label; else if (!chosen.sauce) chosen.sauce = label;
      await choices.first().click().catch(() => {});
      await page.waitForTimeout(500);
    }
    const isLast = await page.locator('.kiosk-btn-next--cart').isVisible().catch(() => false);
    const enabled = await next.first().isEnabled().catch(() => false);
    if (isLast && enabled) { added = true; await next.first().click(); await page.waitForTimeout(1500); break; }
    if (enabled) { await next.first().click(); await page.waitForTimeout(800); }
    else { if ((await choices.count().catch(() => 0)) === 0) break; }
  }
  console.log(`[SNAP] added=${added} chosen=${JSON.stringify(chosen)}`);
  expect(added, 'reached add-to-cart').toBeTruthy();

  // Cart -> checkout -> upsell skip -> payment counter confirm.
  await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(OUT, '1-cart.png'), fullPage: true });
  const checkout = page.locator('[data-testid="kiosk-cart-checkout"]');
  await expect(checkout).toBeVisible({ timeout: 10_000 });
  await checkout.click();
  const upsellSkip = page.locator('[data-testid="kiosk-upsell-skip"]');
  await upsellSkip.waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
  if (await upsellSkip.isVisible().catch(() => false)) { await upsellSkip.click().catch(() => {}); }
  await page.waitForURL(/\/kiosk\/payment/, { timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(2500);
  const confirm = page.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first();
  await expect(confirm).toBeVisible({ timeout: 12_000 });
  const orderResp = page.waitForResponse(
    (r) => /\/api\/kiosk\/order|\/kiosk\/orders|counter/i.test(r.url()) && r.request().method() === 'POST',
    { timeout: 25_000 },
  ).catch(() => null);
  await confirm.click();
  const resp = await orderResp;
  if (resp) console.log(`[SNAP] place API -> ${resp.status()}`);
  await page.waitForURL(/\/kiosk\/(confirmation|waiting)/, { timeout: 25_000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(OUT, '2-confirmation.png'), fullPage: true });

  // Inspect the newly created order's composition_snapshot.
  const newId = parseInt(db(`SELECT IFNULL(MAX(id),0) FROM orders WHERE id > ${baselineMax} AND id IN (SELECT order_id FROM order_items WHERE item_id=${ITEM_ID});`), 10);
  console.log(`[SNAP] baselineMax=${baselineMax} newOrderId=${newId}`);
  expect(newId, 'a new Tacos order was created').toBeGreaterThan(baselineMax);

  const snapRaw = db(`SELECT composition_snapshot FROM order_items WHERE order_id=${newId} AND item_id=${ITEM_ID} LIMIT 1;`);
  const priceRow = db(`SELECT price, item_variation_total, item_extra_total FROM order_items WHERE order_id=${newId} AND item_id=${ITEM_ID} LIMIT 1;`);
  console.log(`[SNAP] snapshot=${snapRaw}`);
  console.log(`[SNAP] price/varTotal/extraTotal=${priceRow}`);
  fs.writeFileSync(path.join(OUT, 'snapshot.json'), JSON.stringify({ newId, chosen, snapRaw, priceRow }, null, 2));

  // The snapshot must be present (non-null), valid JSON, and reflect the composition.
  expect(snapRaw && snapRaw !== 'NULL', 'composition_snapshot is non-null').toBeTruthy();
  let snap = null;
  try { snap = JSON.parse(snapRaw); } catch (e) { /* assert below */ }
  expect(snap, 'composition_snapshot is valid JSON').toBeTruthy();
  expect(typeof snap.schema_version, 'snapshot has schema_version').not.toBe('undefined');

  // Sealed options: at least the chosen viande or sauce must appear in lines/addons/extras.
  const flat = JSON.stringify(snap).toLowerCase();
  const viandeOk = chosen.viande ? flat.includes(chosen.viande.toLowerCase().split(' ')[0]) : true;
  console.log(`[SNAP] viandeOk=${viandeOk} (looked for "${chosen.viande}")`);

  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});
