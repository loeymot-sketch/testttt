// Wave-Wizard — mobile-design-perfect-2026-05-11 round-1
// Orchestrator-authored spec. Captures 12 wizard states for Tacos XXL + 2 variants.
// Asserts pricing decomposition Tacos XXL full combo = 18,00 €.
// Uses tests/e2e/helpers/mega-audit-snap.js quartet recorder.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { attachMegaAuditRecorder } = require('../e2e/helpers/mega-audit-snap');

const OUT = path.resolve(__dirname, '__screenshots__/test-e2e-mobile-design-perfect-wave-wizard');

async function bootstrapMobile(page) {
  await page.goto('/index.html', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!window.LC && !!window.LC.storage && !!window.LC.menu, { timeout: 15_000 });
  await page.evaluate(() => {
    window.LC.storage.clearAuth();
    window.LC.storage.setAuth({ token: 'test', phone: '+33600000000', user_id: 12345 });
    window.LC.storage.markOnboardingSeen();
    window.LC.storage.clearCart();
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-screen-label]', { timeout: 15_000 });
}

async function gotoItemBySlug(page, slug) {
  // Navigate to Menu tab then click the item with matching slug.
  await page.evaluate((slug) => {
    // Use go() exposed indirectly — easier path: tap menu then find item card.
    const menuTab = Array.from(document.querySelectorAll('.lc-tab, .lc-tabs > *')).find(el => /Menu/i.test(el.textContent || ''));
    if (menuTab) menuTab.click();
  });
  await page.waitForSelector('[data-screen-label*="Menu"]', { timeout: 10_000 });
  await page.evaluate((slug) => {
    // Find clickable card whose nearby text contains slug-derived name fragments.
    // Fallback: use first item that opens wizard with matching id via direct nav.
    const item = window.LC.menu.findItem(slug);
    if (!item) throw new Error('Item not found for slug ' + slug);
    // The App component exposes go() via window? — go() lives inside React closure.
    // We trigger navigation by clicking the matching DOM element.
    const candidates = Array.from(document.querySelectorAll('[data-screen-label*="Menu"] [onClick], [data-screen-label*="Menu"] [style*="cursor: pointer"], [data-screen-label*="Menu"] [style*="cursor:pointer"]'));
    const target = candidates.find(el => (el.textContent || '').toUpperCase().includes(item.name.toUpperCase().slice(0, 8)));
    if (target) target.click();
    else throw new Error('Item card not found in Menu DOM for ' + slug);
  }, slug);
  await page.waitForSelector('[data-screen-label*="Item Wizard"], [data-screen-label*="Item Direct"]', { timeout: 10_000 });
}

test.describe('Wave-Wizard — Tacos XXL full pricing + variants', () => {
  let recorder;
  test.beforeAll(async () => { fs.mkdirSync(OUT, { recursive: true }); });
  test.beforeEach(async ({ page }) => {
    recorder = attachMegaAuditRecorder(page, OUT);
  });
  test.afterEach(async () => {
    if (recorder && recorder.dispose) recorder.dispose();
  });

  test('W01 — Tacos XXL entry → step1 viandes 0/4 CTA disabled with hint', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    // step1 viandes 0/4 — CTA disabled, hint visible
    const cta = page.locator('button[aria-disabled="true"]:has-text("Suivant")');
    await expect(cta).toHaveCount(1);
    const hint = page.locator('#wizard-cta-hint');
    await expect(hint).toBeVisible();
    const hintText = (await hint.textContent() || '').toLowerCase();
    expect(hintText).toMatch(/viande/);
    await recorder.snap('01-tacos-xxl-step1-viandes-0of4');
  });

  test('W02 — Tacos XXL step1 viandes 4/4 → CTA enabled', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    // Pick 4 viandes — click first 4 ChoiceCard role=checkbox in step
    const cards = page.locator('[role="checkbox"]');
    const n = await cards.count();
    for (let i = 0; i < Math.min(4, n); i++) await cards.nth(i).click();
    const cta = page.locator('button:has-text("Suivant")');
    await expect(cta).toHaveAttribute('aria-disabled', 'false');
    await recorder.snap('02-tacos-xxl-step1-viandes-4of4-valid-cta');
  });

  test('W03 — Tacos XXL step2 sauce ketchup free → step3', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    // Advance through step1
    const cards = page.locator('[role="checkbox"]');
    for (let i = 0; i < 4; i++) await cards.nth(i).click();
    await page.click('button:has-text("Suivant")');
    await page.waitForSelector('[data-screen-label*="Sauce"]', { timeout: 5_000 });
    // step2 sauce: pick Ketchup (1st free)
    const ketchupCard = page.locator('[role="checkbox"]').filter({ hasText: /ketchup/i }).first();
    if (await ketchupCard.count() > 0) await ketchupCard.click();
    else await page.locator('[role="checkbox"]').first().click();
    await recorder.snap('03-tacos-xxl-step2-sauce-ketchup-free');
  });

  test('W04 — Tacos XXL step2 sauce 2nd paid +0,50€', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    const cards = page.locator('[role="checkbox"]');
    for (let i = 0; i < 4; i++) await cards.nth(i).click();
    await page.click('button:has-text("Suivant")');
    await page.waitForSelector('[data-screen-label*="Sauce"]');
    // pick 2 sauces — first free, second paid
    await page.locator('[role="checkbox"]').nth(0).click();
    await page.locator('[role="checkbox"]').nth(1).click();
    // Verify +0,50€ chip on 2nd sauce
    const paid = page.locator('text=+0,50€');
    expect(await paid.count()).toBeGreaterThanOrEqual(1);
    await recorder.snap('04-tacos-xxl-step2-sauce-2nd-paid-0.50');
  });

  test('W05 — Tacos XXL step3 crudités default ON (toggle visible)', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    const cards = page.locator('[role="checkbox"]');
    for (let i = 0; i < 4; i++) await cards.nth(i).click();
    await page.click('button:has-text("Suivant")');
    await page.locator('[role="checkbox"]').first().click(); // sauce
    await page.click('button:has-text("Suivant")');
    await page.waitForSelector('[data-screen-label*="Crudités"]');
    // step3: expect 3 cards visible (salade/tomate/oignon)
    const cruditeCards = page.locator('[data-screen-label*="Crudités"] [role="checkbox"]');
    expect(await cruditeCards.count()).toBeGreaterThanOrEqual(3);
    await recorder.snap('05-tacos-xxl-step3-crudites-default-on');
  });

  test('W06 — Tacos XXL step4 supplements Œuf +1,00€', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    const cards = page.locator('[role="checkbox"]');
    for (let i = 0; i < 4; i++) await cards.nth(i).click();
    await page.click('button:has-text("Suivant")'); // step2
    await page.locator('[role="checkbox"]').first().click(); // sauce
    await page.click('button:has-text("Suivant")'); // step3
    await page.click('button:has-text("Suivant")'); // step4 suppl
    await page.waitForSelector('[data-screen-label*="Suppléments"]');
    // Pick Œuf
    const oeufRow = page.locator('[role="checkbox"]').filter({ hasText: /Œuf|oeuf/i });
    if (await oeufRow.count() > 0) await oeufRow.first().click();
    await recorder.snap('06-tacos-xxl-step4-supplements-oeuf');
  });

  test('W07 — Tacos XXL step5 menu full → cascade injected', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    const cards = page.locator('[role="checkbox"]');
    for (let i = 0; i < 4; i++) await cards.nth(i).click();
    // step1→step2 sauce
    await page.click('button:has-text("Suivant")');
    await page.locator('[role="checkbox"]').first().click();
    // step2→step3 crudites
    await page.click('button:has-text("Suivant")');
    // step3→step4 supplements
    await page.click('button:has-text("Suivant")');
    // step4→step5 menu (4 Suivants total to reach menu)
    await page.click('button:has-text("Suivant")');
    await page.waitForSelector('[data-screen-label*="Faire un menu"], [data-screen-label*="menu"]', { timeout: 5_000 });
    const menuFull = page.locator('[role="radio"]').filter({ hasText: /Menu complet/i }).first();
    if (await menuFull.count() > 0) await menuFull.click();
    await recorder.snap('07-tacos-xxl-step5-menu-full');
  });

  test('W08 — Tacos XXL step6 drink coca cascade', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    const cards = page.locator('[role="checkbox"]');
    for (let i = 0; i < 4; i++) await cards.nth(i).click();
    await page.click('button:has-text("Suivant")');
    await page.locator('[role="checkbox"]').first().click();
    await page.click('button:has-text("Suivant")'); // step3
    await page.click('button:has-text("Suivant")'); // step4
    await page.click('button:has-text("Suivant")'); // step5
    await page.waitForSelector('[data-screen-label*="menu"]');
    await page.locator('[role="radio"]').first().click(); // menu full
    await page.click('button:has-text("Suivant")');
    await page.waitForSelector('[data-screen-label*="Boisson"]', { timeout: 5_000 });
    const coca = page.locator('[role="radio"]').filter({ hasText: /coca/i }).first();
    if (await coca.count() > 0) await coca.click();
    else await page.locator('[role="radio"]').first().click();
    await recorder.snap('08-tacos-xxl-step6-drink-coca');
  });

  test('W09 — Tacos XXL step7 frites style Cheddar fondu +1,00€', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    const cards = page.locator('[role="checkbox"]');
    for (let i = 0; i < 4; i++) await cards.nth(i).click();
    await page.click('button:has-text("Suivant")');
    await page.locator('[role="checkbox"]').first().click();
    await page.click('button:has-text("Suivant")'); // c3
    await page.click('button:has-text("Suivant")'); // c4
    await page.click('button:has-text("Suivant")'); // c5
    await page.locator('[role="radio"]').first().click(); // menu full
    await page.click('button:has-text("Suivant")'); // c6
    await page.locator('[role="radio"]').first().click(); // drink
    await page.click('button:has-text("Suivant")'); // c7
    await page.waitForSelector('[data-screen-label*="frites"]', { timeout: 5_000 });
    const cheddar = page.locator('[role="radio"]').filter({ hasText: /cheddar/i }).first();
    if (await cheddar.count() > 0) await cheddar.click();
    else await page.locator('[role="radio"]').first().click();
    await recorder.snap('09-tacos-xxl-step7-frites-style-cheddar');
  });

  test('W10 — Tacos XXL step8 frites sauce BBQ', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    const cards = page.locator('[role="checkbox"]');
    for (let i = 0; i < 4; i++) await cards.nth(i).click();
    await page.click('button:has-text("Suivant")');
    await page.locator('[role="checkbox"]').first().click();
    await page.click('button:has-text("Suivant")');
    await page.click('button:has-text("Suivant")');
    await page.click('button:has-text("Suivant")');
    await page.locator('[role="radio"]').first().click(); // menu
    await page.click('button:has-text("Suivant")');
    await page.locator('[role="radio"]').first().click(); // drink
    await page.click('button:has-text("Suivant")');
    await page.locator('[role="radio"]').first().click(); // frites style
    await page.click('button:has-text("Suivant")');
    await page.waitForSelector('[data-screen-label*="frites sauce"], [data-screen-label*="Sauce frites"], [data-screen-label*="Frites Sauce"]', { timeout: 5_000 });
    await page.locator('[role="checkbox"]').first().click();
    await recorder.snap('10-tacos-xxl-step8-frites-sauce');
  });

  test('W11 — Tacos XXL recap full combo total = 18,00 €', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'tacos-xxl');
    const cards = page.locator('[role="checkbox"]');
    for (let i = 0; i < 4; i++) await cards.nth(i).click();
    await page.click('button:has-text("Suivant")');
    // step2: pick Ketchup (1st free) + BBQ (2nd +0.50)
    await page.locator('[role="checkbox"]').nth(0).click();
    await page.locator('[role="checkbox"]').nth(1).click();
    await page.click('button:has-text("Suivant")'); // step3 crudites
    await page.click('button:has-text("Suivant")'); // step4 suppl
    // pick Œuf
    const oeuf = page.locator('[role="checkbox"]').filter({ hasText: /Œuf|oeuf/i });
    if (await oeuf.count() > 0) await oeuf.first().click();
    await page.click('button:has-text("Suivant")'); // step5 menu
    await page.locator('[role="radio"]').filter({ hasText: /Menu complet/i }).first().click();
    await page.click('button:has-text("Suivant")'); // step6 drink
    await page.locator('[role="radio"]').first().click();
    await page.click('button:has-text("Suivant")'); // step7 frites style
    await page.locator('[role="radio"]').filter({ hasText: /cheddar/i }).first().click();
    await page.click('button:has-text("Suivant")'); // step8 frites sauce
    await page.locator('[role="checkbox"]').first().click();
    await page.click('button:has-text("Suivant")'); // recap
    await page.waitForSelector('[data-screen-label*="Récapitulatif"]', { timeout: 5_000 });
    // Assert total 18,00 €
    const total = page.locator('text=/18,00\\s*€/').first();
    await expect(total).toBeVisible({ timeout: 5_000 });
    await recorder.snap('11-tacos-xxl-recap-full-combo-18eur');
  });

  test('W12 — Cheese Burger no-formule recap = 6,00 €', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoItemBySlug(page, 'cheese-burger');
    // Burger template: sauce → crudites → suppl → menu → recap
    await page.locator('[role="checkbox"]').first().click(); // sauce
    await page.click('button:has-text("Suivant")'); // crudites
    await page.click('button:has-text("Suivant")'); // suppl
    await page.click('button:has-text("Suivant")'); // menu
    await page.locator('[role="radio"]').filter({ hasText: /Sans formule/i }).first().click();
    await page.click('button:has-text("Suivant")'); // recap
    await page.waitForSelector('[data-screen-label*="Récapitulatif"]');
    const total = page.locator('text=/6,00\\s*€/').first();
    await expect(total).toBeVisible({ timeout: 5_000 });
    await recorder.snap('12-cheese-burger-recap-6eur');
  });
});
