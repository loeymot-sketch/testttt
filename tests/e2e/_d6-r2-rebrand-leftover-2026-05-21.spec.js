/**
 * D6 — Wave Y Round 2 rebrand leftover ("Caisse FoodKing").
 *
 * Locks down Round 2 finding C-R2-NEW-01: 3 hardcoded literal strings still
 * contained the legacy "Caisse FoodKing" eyebrow text after the Wave Y
 * rebrand commit. Fixed inline 2026-05-21:
 *   - resources/js/components/admin/pos/FloorplanComponent.vue:6
 *   - resources/js/components/admin/pos/PosComponent.vue:80
 *   - resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:14
 *   - resources/css/foundations/pos-v5-tokens.css:93 (comment, no UI impact)
 *
 * This spec logs into /admin/pos-v4, captures the POS main shell (PosComponent
 * eyebrow), navigates to the floorplan + tracker tabs when reachable, captures
 * each, and asserts:
 *   - body text DOES NOT contain "FoodKing"
 *   - body text DOES contain "Le Cayenne" on the POS main shell
 *
 * Frozen-zone: none of the 4 touched files are in CLAUDE.md §7.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'reports/test-e2e/d6-r2-rebrand-leftover-2026-05-21';

if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

test.use({ viewport: { width: 1366, height: 1000 } });
test.setTimeout(120_000);

async function shoot(page, idx, label) {
  const base = path.join(OUT, `${String(idx).padStart(2, '0')}-${label}`);
  await page.screenshot({ path: `${base}-viewport.png`, fullPage: false }).catch(() => {});
  await page.screenshot({ path: `${base}-full.png`, fullPage: true }).catch(() => {});
}

async function loginAdmin(page) {
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  const inputs = await page.locator('input').all();
  if (inputs.length >= 2) {
    await inputs[0].fill('admin@lecayenne.fr').catch(() => {});
    await inputs[1].fill('123456').catch(() => {});
  } else {
    await page.getByLabel(/email/i).fill('admin@lecayenne.fr').catch(() => {});
    await page.getByLabel(/mot de passe/i).fill('123456').catch(() => {});
  }
  await page.getByRole('button', { name: /connexion/i }).click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(5000);
}

test('D6 POS shell no longer says "FoodKing", says "Le Cayenne"', async ({ page }) => {
  await loginAdmin(page);
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(6000);
  await shoot(page, 1, 'pos-main-shell');

  const bodyText = await page.evaluate(() => document.body.innerText);
  console.log(`[D6] body length=${bodyText.length}`);
  // Core invariant: no FoodKing on the visible POS shell.
  expect(bodyText).not.toMatch(/FoodKing/);
  // Positive: the new brand appears as eyebrow ("Caisse Le Cayenne") on the
  // operator bar of PosComponent.vue (now compiled into pos-shell.js).
  expect(bodyText).toMatch(/Le Cayenne/);
});

test('D6 POS orders tracker no longer says "FoodKing"', async ({ page }) => {
  await loginAdmin(page);
  // Tracker route name in code: admin.pos.tracker → /admin/pos-v4/tracker
  // Try a few likely paths; the spec is exploratory but the bundle string
  // assertion at the end is what locks the regression.
  const candidates = [
    `${BASE}/admin/pos-v4/tracker`,
    `${BASE}/admin/pos/tracker`,
    `${BASE}/admin/pos-tracker`,
  ];
  let reached = false;
  for (const url of candidates) {
    await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(3500);
    const txt = await page.evaluate(() => document.body.innerText).catch(() => '');
    if (/Commandes? actives|tracker|En préparation/i.test(txt)) {
      reached = true;
      break;
    }
  }
  await shoot(page, 2, reached ? 'tracker-reached' : 'tracker-not-found');

  const bodyText = await page.evaluate(() => document.body.innerText);
  // Whether or not we landed on the tracker UI, the bundle must not leak the
  // legacy brand on any admin shell route reachable post-login.
  expect(bodyText).not.toMatch(/FoodKing/);
});

test('D6 floorplan eyebrow (if reachable) no longer says "FoodKing"', async ({ page }) => {
  await loginAdmin(page);
  const candidates = [
    `${BASE}/admin/pos-v4/floorplan`,
    `${BASE}/admin/pos/floorplan`,
    `${BASE}/admin/floorplan`,
  ];
  let reached = false;
  for (const url of candidates) {
    await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(3500);
    const txt = await page.evaluate(() => document.body.innerText).catch(() => '');
    if (/Plan de salle|floorplan/i.test(txt)) {
      reached = true;
      break;
    }
  }
  await shoot(page, 3, reached ? 'floorplan-reached' : 'floorplan-not-found');

  const bodyText = await page.evaluate(() => document.body.innerText);
  expect(bodyText).not.toMatch(/FoodKing/);
});

test('D6 compiled bundle proof — pos-shell.js no longer ships "Caisse FoodKing"', async () => {
  const bundlePath = path.join(
    'public', 'js', 'pos-shell.js'
  );
  const content = fs.readFileSync(bundlePath, 'utf8');
  // The compiled string from the 3 fixed Vue templates.
  const matchesOld = content.match(/Caisse FoodKing/g) || [];
  const matchesNew = content.match(/Caisse Le Cayenne/g) || [];
  console.log(`[D6] pos-shell.js "Caisse FoodKing" count=${matchesOld.length}`);
  console.log(`[D6] pos-shell.js "Caisse Le Cayenne" count=${matchesNew.length}`);
  expect(matchesOld.length).toBe(0);
  // 3 cached template literals (PosComponent + PosOrdersTracker + Floorplan).
  expect(matchesNew.length).toBeGreaterThanOrEqual(3);
});
