// FoodKing — KIOSK (borne) menu flow (headless): idle -> takeaway -> categories -> products
// 2026-06-07. Disposable clone (needs KIOSK_AUTO_LOGIN_TRUSTED_IPS incl. test IP in .env.e2e):
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-kiosk-menu-flow-2026-06-07.spec.js
//
// Read-only navigation of the customer self-order menu (no order submitted).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsKiosk } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'kiosk-menu-flow-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

const CRASH = ['Whoops, something went wrong', 'Server Error', 'SQLSTATE', 'Undefined variable', 'Class "'];
const RAW_LABEL_RE = /\b(kiosk|pos|kds|common|label|messages?)\.[a-z_]+\.[a-z_.]+\b/i;

test.describe.configure({ mode: 'serial', timeout: 120_000 });

test('kiosk idle -> takeaway -> categories -> products', async ({ page }) => {
  const consoleErrors = [];
  const pageErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => pageErrors.push(e.message));

  await loginAsKiosk(page);
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);

  // Some idle variants require a first tap to reveal the order-type chooser.
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (!(await takeaway.isVisible().catch(() => false))) {
    const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
    if (await touch.isVisible().catch(() => false)) { await touch.click(); await page.waitForTimeout(1200); }
  }
  await expect(takeaway, 'takeaway tile visible').toBeVisible({ timeout: 10_000 });
  await takeaway.click();

  // Land on categories.
  await page.waitForURL(/\/kiosk\/(categories|products)/, { timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(3000);
  const catUrl = page.url();
  await page.screenshot({ path: path.join(OUT, '1-categories.png'), fullPage: true });
  const catBody = await page.locator('body').innerText().catch(() => '');
  console.log(`[KMENU] categories url=${catUrl} crashes=${CRASH.filter((m) => catBody.includes(m)).length} rawLabel=${(catBody.match(RAW_LABEL_RE) || ['none'])[0]}`);

  // Click the first category/product tile to reach products.
  const tile = page.locator('[data-testid^="kiosk-category"], .kiosk-category-card, [data-testid^="kiosk-product"], .kiosk-product-card').first();
  if (await tile.isVisible().catch(() => false)) {
    await tile.click();
    await page.waitForTimeout(3000);
    await page.screenshot({ path: path.join(OUT, '2-products.png'), fullPage: true });
    console.log(`[KMENU] after-tile url=${page.url()}`);
  } else {
    console.log('[KMENU] no category/product tile found to drill into');
  }

  const realConsoleErrors = consoleErrors.filter((t) =>
    !/favicon|websocket|ws:|wss:|soketi|pusher|6001|Deprecation|preload|sourcemap/i.test(t));
  console.log(`[KMENU] consoleErr=${realConsoleErrors.length} pageErr=${pageErrors.length}`);
  if (realConsoleErrors.length) console.log(`   ${JSON.stringify(realConsoleErrors.slice(0, 5))}`);

  expect(catUrl, 'reached menu (categories/products)').toMatch(/\/kiosk\/(categories|products)/);
  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});
