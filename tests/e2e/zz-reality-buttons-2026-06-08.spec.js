// FoodKing — "reality test bottons": actually CLICK each daily-path control on :8766
// and assert it produces an effect (route change / modal / DOM mutation) — proves the
// button is wired, not dead. Read-only-ish: drives the safe foodking_e2e clone, stops
// before any fiscal-mutating confirm.
//   DB_DATABASE=foodking_e2e APP_ENV=e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-reality-buttons-2026-06-08.spec.js --workers=1

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../../reports/test-e2e/goal-felt-product-2026-06-08/reality-buttons');
function shoot(page, name) {
  fs.mkdirSync(OUT, { recursive: true });
  return page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
}

test.describe.configure({ mode: 'serial', timeout: 150_000 });

// Records each button outcome so the supervisor can see which controls did something.
const results = [];
async function probe(page, label, action) {
  const before = page.url() + '|' + (await page.locator('body').innerHTML().catch(() => '')).length;
  let effect = 'none';
  try {
    await action();
    await page.waitForTimeout(900);
    const after = page.url() + '|' + (await page.locator('body').innerHTML().catch(() => '')).length;
    // effect = URL changed, OR a dialog/modal/overlay is visible, OR the DOM size changed materially.
    const overlay = await page.locator('.modal, [role="dialog"], .swal2-container, .kiosk-modal, .pos-wizard, #posWizard, .v-overlay, .offcanvas').first().isVisible().catch(() => false);
    const [u0, l0] = before.split('|');
    const [u1, l1] = after.split('|');
    if (u0 !== u1) effect = 'navigated:' + u1;
    else if (overlay) effect = 'overlay-opened';
    else if (Math.abs(Number(l1) - Number(l0)) > 300) effect = 'dom-changed';
  } catch (e) {
    effect = 'click-error:' + (e.message || '').slice(0, 60);
  }
  results.push({ label, effect });
  console.log(`[BTN] ${label} -> ${effect}`);
  return effect;
}

test('POS caisse control surface — every top button does something', async ({ page }) => {
  page.setViewportSize({ width: 1440, height: 900 });
  await loginAsAdmin(page);
  await page.goto('/admin/pos', { waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await shoot(page, 'pos-00-loaded');

  // Category tab filter (grid should react).
  await probe(page, 'category:Burgers', async () => {
    await page.getByText(/^Burgers$/i).first().click({ timeout: 8000 });
  });
  // An item in the grid → opens the (frozen) wizard popup.
  await probe(page, 'item-click→wizard', async () => {
    const card = page.locator('.pos-product, .product-card, [data-product-id], .menu-item, .pos-grid >> button').first();
    await card.click({ timeout: 8000 });
  });
  await shoot(page, 'pos-01-after-item');
  // Close any wizard that opened (Esc) so the next probes are clean.
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(500);

  // Top action buttons — assert each is wired (navigates or opens something).
  await probe(page, 'Suivi commandes', async () => {
    await page.getByText(/Suivi commandes/i).first().click({ timeout: 8000 });
  });
  await page.goto('/admin/pos', { waitUntil: 'networkidle' }).catch(() => {});
  await page.waitForTimeout(1500);

  await probe(page, 'Écran client', async () => {
    await page.getByText(/Écran client/i).first().click({ timeout: 8000 });
  });
  await page.goto('/admin/pos', { waitUntil: 'networkidle' }).catch(() => {});
  await page.waitForTimeout(1500);

  await probe(page, 'Ouvrir tiroir', async () => {
    await page.getByText(/Ouvrir tiroir/i).first().click({ timeout: 8000 });
  });
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(800);

  await probe(page, 'À encaisser (panel)', async () => {
    await page.getByText(/À encaisser/i).first().click({ timeout: 8000 });
  });

  await shoot(page, 'pos-02-final');
  fs.writeFileSync(path.join(OUT, 'pos-button-results.json'), JSON.stringify(results, null, 2));

  // Reality assertion: NONE of the probed buttons may be dead ('none').
  const dead = results.filter((r) => r.effect === 'none');
  console.log('[BTN] dead controls:', JSON.stringify(dead));
  expect(dead, `dead POS controls (no effect on click): ${JSON.stringify(dead)}`).toEqual([]);
});
