// RED-Visual D — Mobile Fictional Purge Validator (Round 3, 2026-05-18)
//
// Adversarial visual gate on Impl D's mobile data heal (orders.js + loyalty.js).
// Captures the 3 user-facing screens Impl D claimed to have purged of
// fictional SKUs (Box Nashville, Box Familiale, Le Cheese Smash, etc.) :
//
//   1. ScreenOrders (history list)   — 6 orders rendered from healed data
//   2. ScreenLoyalty (rewards grid)  — R1/R5/R6/R7 with canonical labels
//   3. ScreenOrderDetail (C-1234)    — items list with canonical names
//
// READ-ONLY — no data mutation, no API calls. Mobile standalone.
//
// Run :
//   npx playwright test --config=tests/mobile-e2e/playwright.config.js \
//     tests/mobile-e2e/red-d-mobile-fictional-purge-2026-05-18.spec.js

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT_DIR = '/tmp/foodking-goal-round3/red-d-mobile';
// [GOAL-SYNC 2026-07-08] port 8081 → 8087 (8081 hijacké par un autre projet ;
// 8087 = port dédié Cayenne mobile, aligné playwright.config.js).
const MOBILE_URL = 'http://127.0.0.1:8087/index.html';
const PROTOTYPE_URL = 'http://127.0.0.1:8087/Le%20Cayenne%20-%20Prototype.html';

if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

const FORBIDDEN_TOKENS = [
  'Box Nashville', 'Box Familiale', 'Box Solo',
  'Le Cheese', 'Bowl Cheesy', 'Wrap Poulet', 'Cookie XL',
  'Frite XXL', 'Bowl Gratiné',
];

async function bootMobile(page) {
  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  await page.waitForFunction(
    () => window.LC && window.LC.menu && window.LC.menu.items.length > 0,
    { timeout: 30000 }
  );
  await page.evaluate(() => {
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
    localStorage.setItem('lecayenne.auth', JSON.stringify({
      token: 'red-test', phone: '0642799884', user_id: 'red-test'
    }));
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
}

async function captureAndScan(page, screenName) {
  const file = path.join(OUT_DIR, `${screenName}.png`);
  await page.screenshot({ path: file, fullPage: true });
  const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
  const leaks = FORBIDDEN_TOKENS.filter(t => text.includes(t));
  return { file, text, leaks };
}

test.describe('RED-D mobile fictional purge visual gate', () => {
  test('1 — Order history (Commandes tab) shows healed canonical SKUs', async ({ page }) => {
    await bootMobile(page);
    // Click the COMMANDES tab in the bottom tabbar
    await page.locator('text=COMMANDES').first().click();
    await page.waitForTimeout(600);
    const r = await captureAndScan(page, '01-orders-active');
    expect(r.leaks, `Fictional tokens in orders active: ${r.leaks.join(', ')}`).toEqual([]);
    // Switch to HISTORIQUE tab
    const histTab = page.locator('text=HISTORIQUE').first();
    if (await histTab.count() > 0) {
      await histTab.click();
      await page.waitForTimeout(500);
    }
    const r2 = await captureAndScan(page, '01b-orders-history');
    expect(r2.leaks, `Fictional tokens in orders history: ${r2.leaks.join(', ')}`).toEqual([]);
  });

  test('2 — Loyalty screen via prototype direct nav', async ({ page }) => {
    // Use prototype which exposes left-rail buttons for each screen
    await page.goto(PROTOTYPE_URL, { waitUntil: 'networkidle' });
    await page.waitForFunction(
      () => window.LC && window.LC.menu && window.LC.menu.items.length > 0,
      { timeout: 30000 }
    );
    await page.evaluate(() => {
      localStorage.setItem('lecayenne.onboarding_seen', 'true');
      localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'red', phone: '0642799884', user_id: 'red' }));
    });
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    // Click the "14 Fidélité" button in the left rail
    await page.locator('button:has-text("Fidélité")').first().click();
    await page.waitForTimeout(800);
    const r = await captureAndScan(page, '02-loyalty-rewards');
    expect(r.leaks, `Fictional tokens in loyalty: ${r.leaks.join(', ')}`).toEqual([]);
  });

  test('3 — Order detail C-1234 via prototype direct nav', async ({ page }) => {
    await page.goto(PROTOTYPE_URL, { waitUntil: 'networkidle' });
    await page.waitForFunction(
      () => window.LC && window.LC.menu && window.LC.menu.items.length > 0,
      { timeout: 30000 }
    );
    await page.evaluate(() => {
      localStorage.setItem('lecayenne.onboarding_seen', 'true');
      localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'red', phone: '0642799884', user_id: 'red' }));
    });
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.locator('button:has-text("Détail")').first().click();
    await page.waitForTimeout(800);
    const r = await captureAndScan(page, '03-order-detail-c1234');
    expect(r.leaks, `Fictional tokens in order detail: ${r.leaks.join(', ')}`).toEqual([]);
  });

  test('4 — window.LC data layer SSOT check (sentinel sanity)', async ({ page }) => {
    await bootMobile(page);
    const report = await page.evaluate(() => {
      const canon = new Set(window.LC.menu.items.map(i => i.id));
      const cats = new Set(window.LC.menu.categories.map(c => c.id));
      const orderIds = [];
      const allOrders = [...window.LC.orders.active, ...window.LC.orders.history];
      allOrders.forEach(o => (o.items || []).forEach(l => orderIds.push({
        order: o.id, item_id: l.item_id, name: l.name, canonical: canon.has(l.item_id)
      })));
      // [GOAL-SYNC 2026-07-08] catalogue rewards SUPPRIMÉ (modèle continu points→€) — stub [] gardé.
      const rewardIds = (window.LC.loyalty.rewards || []).map(r => ({
        id: r.id, name: r.name,
        item_id: r.payload && r.payload.item_id,
        category_id: r.payload && r.payload.category_id,
        item_canonical: r.payload && r.payload.item_id ? canon.has(r.payload.item_id) : 'n/a',
        cat_canonical: r.payload && r.payload.category_id ? cats.has(r.payload.category_id) : 'n/a',
      }));
      return { orderIds, rewardIds };
    });
    // Write to JSON for evidence
    fs.writeFileSync(path.join(OUT_DIR, 'data-layer-evidence.json'),
                     JSON.stringify(report, null, 2));
    // Assert all canonical
    const orderLeaks = report.orderIds.filter(x => !x.canonical);
    expect(orderLeaks, `Order item_id leaks: ${JSON.stringify(orderLeaks)}`).toEqual([]);
    const rewardItemLeaks = report.rewardIds.filter(r => r.item_canonical === false);
    expect(rewardItemLeaks, `Reward item_id leaks: ${JSON.stringify(rewardItemLeaks)}`).toEqual([]);
    const rewardCatLeaks = report.rewardIds.filter(r => r.cat_canonical === false);
    expect(rewardCatLeaks, `Reward category_id leaks: ${JSON.stringify(rewardCatLeaks)}`).toEqual([]);
  });
});
