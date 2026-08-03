/**
 * Wave Y-C Round 2 — Follow-up captures for stock-rupture Chicken Burger
 * Re-shoots /admin/stock/rupture with longer wait + Burgers category click
 * to surface the C-013 RUPTURE pill.
 */
const { test } = require('@playwright/test');
const fs = require('fs');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT_CAP =
  'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-2/captures/wave-C';
const STATE_FILE = '/tmp/_wave-y-c-storage-state-round-2.json';

test.use({ viewport: { width: 1366, height: 900 } });
test.describe.configure({ retries: 0 });

test('C-06-fix Stock rupture WITH Burgers click', async ({ browser }) => {
  if (!fs.existsSync(STATE_FILE)) {
    throw new Error('storage state missing — run main r2 spec first');
  }
  const ctx = await browser.newContext({ storageState: STATE_FILE });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/stock/rupture`, {
    waitUntil: 'domcontentloaded',
  });
  // Longer wait for Vue SPA to mount + fetch categories
  await page.waitForTimeout(8000);
  // Try to wait for any category badge/pill to appear (more reliable than fixed wait)
  await page
    .waitForSelector('text=/EN STOCK|RUPTURE/i', { timeout: 12000 })
    .catch(() => {});
  await page.screenshot({
    path: `${OUT_CAP}/C-06-fix-stock-rupture-default.png`,
    fullPage: true,
  });

  // Click on Burgers category to surface Chicken Burger
  const burgersTab = page
    .locator('text=/^Burgers$/i')
    .first();
  if (await burgersTab.count()) {
    await burgersTab.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(2500);
    await page.screenshot({
      path: `${OUT_CAP}/C-06-fix-stock-burgers.png`,
      fullPage: true,
    });
    // Also try to capture Chicken Burger card detail
    const chickenCard = page
      .locator('text=/Chicken Burger/i')
      .first();
    if (await chickenCard.count()) {
      try {
        await chickenCard.scrollIntoViewIfNeeded({ timeout: 1500 });
        const boundingBox = await chickenCard
          .locator('xpath=ancestor::*[contains(@class, "card") or contains(@class, "product") or contains(@class, "item")][1]')
          .first()
          .boundingBox()
          .catch(() => null);
        if (boundingBox) {
          await page.screenshot({
            path: `${OUT_CAP}/C-06-fix-chicken-burger-detail.png`,
            clip: {
              x: Math.max(0, boundingBox.x - 10),
              y: Math.max(0, boundingBox.y - 10),
              width: Math.min(1366, boundingBox.width + 20),
              height: Math.min(900, boundingBox.height + 20),
            },
          });
        }
      } catch (_e) {
        /* noop */
      }
    }
  }
  await ctx.close();
});
