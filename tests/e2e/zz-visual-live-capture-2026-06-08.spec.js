// FoodKing — LIVE visual-capture wave (read-the-screenshots, §6 Visual Test Mandate).
//   DB_DATABASE=foodking_e2e APP_ENV=e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-visual-live-capture-2026-06-08.spec.js --retries=0 --workers=1
//
// Captures each daily-path surface full-page so the supervisor can READ the image
// (layout break? raw label? broken empty/error state? FR locale? branding?).
// :8766 = disposable foodking_e2e clone — safe to drive.

const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../../reports/test-e2e/goal-felt-product-2026-06-08/visual-live');

test.describe.configure({ mode: 'serial', timeout: 120_000 });

async function shoot(page, name) {
  fs.mkdirSync(OUT, { recursive: true });
  await page.waitForTimeout(1200); // let SPA settle
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
  console.log(`[VIS] captured ${name}`);
}

const ADMIN_SURFACES = [
  ['dashboard', '/admin/dashboard'],
  ['pos-caisse', '/admin/pos'],
  ['catalogue-items', '/admin/items'],
  ['stock-rupture', '/admin/stock/rupture'],
  ['kds', '/kds'],
  ['oss-order-status', '/admin/order-status-screen'],
  ['sales-report', '/admin/sales-report'],
  ['customers', '/admin/customers'],
];

test('capture login + admin daily-path surfaces', async ({ page }) => {
  page.setViewportSize({ width: 1440, height: 900 });

  // Public login screen first.
  await page.goto('/login', { waitUntil: 'networkidle' }).catch(() => {});
  await shoot(page, '00-login');

  await loginAsAdmin(page);

  for (const [name, url] of ADMIN_SURFACES) {
    try {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 30_000 });
    } catch (_) {
      await page.goto(url, { timeout: 30_000 }).catch(() => {});
    }
    await shoot(page, name);
  }
});

test('capture kiosk borne idle + categories', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
  const page = await ctx.newPage();
  // The borne idle screen is the public entry; categories needs a kiosk session,
  // but idle is the most-seen customer surface. Capture what loads.
  await page.goto('/kiosk/idle', { waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {});
  await shoot(page, 'kiosk-idle');
  await ctx.close();
});
