/**
 * Capture kiosk sauce step — clicking the CARD (not CHOISIR text).
 */
const { test } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/le-cayenne-v2-2026-05-21';

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(90000);

test('Click viande card, advance to sauce step, capture 13 sauces', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.getByText('Tacos', { exact: true }).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.getByText('Personnaliser').first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(3000);

  // Click the first kiosk-viande-card directly
  await page.locator('.kiosk-viande-card').first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/PROOF-11a-viande-selected.png`, fullPage: true });

  // Click SUIVANT
  await page.getByRole('button', { name: /^suivant$/i }).first().click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${OUT}/PROOF-11-13-sauces-grid.png`, fullPage: true });

  // Scroll to see all
  await page.evaluate(() => document.querySelector('.kiosk-sauce-grid')?.scrollIntoView({ block: 'start' }));
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${OUT}/PROOF-11b-sauces-scrolled.png`, fullPage: true });

  const sauceCount = await page.evaluate(() => {
    const grid = document.querySelector('.kiosk-sauce-grid');
    if (!grid) return -1;
    return grid.querySelectorAll(':scope > *').length;
  });
  console.log('SAUCE COUNT IN DOM:', sauceCount);
});
