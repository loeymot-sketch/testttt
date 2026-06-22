/**
 * POS V4 + KDS captures — direct routes (no login required for the dev instance).
 */
const { test } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/le-cayenne-v2-2026-05-21';

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(60000);

test('PROOF-09 POS V4 with new images + prices', async ({ page }) => {
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(6000);
  await page.screenshot({ path: `${OUT}/PROOF-09-pos-v4.png`, fullPage: false });
});

test('PROOF-10 KDS view', async ({ page }) => {
  await page.goto(`${BASE}/kds`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4500);
  await page.screenshot({ path: `${OUT}/PROOF-10-kds.png`, fullPage: false });
});
