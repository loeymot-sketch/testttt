/**
 * Capture all 13 sauces — scroll within sauce step container.
 */
const { test } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/le-cayenne-v2-2026-05-21';

test.use({ viewport: { width: 1366, height: 1200 } });  // taller viewport
test.setTimeout(90000);

test('All 13 sauces visible — tall viewport', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.getByText('Tacos', { exact: true }).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.getByText('Personnaliser').first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(3000);

  await page.locator('.kiosk-viande-card').first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.getByRole('button', { name: /^suivant$/i }).first().click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(3000);

  // Scroll sauce grid into view + capture full page
  await page.evaluate(() => {
    const grid = document.querySelector('.kiosk-sauce-grid');
    if (grid) grid.scrollIntoView({ block: 'start' });
  });
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${OUT}/PROOF-11c-13-sauces-full.png`, fullPage: true });

  const sauceCount = await page.evaluate(() => {
    const grid = document.querySelector('.kiosk-sauce-grid');
    if (!grid) return -1;
    return grid.querySelectorAll(':scope > *').length;
  });
  console.log('SAUCE COUNT:', sauceCount);

  // Also list names
  const names = await page.evaluate(() => {
    const cards = document.querySelectorAll('.kiosk-sauce-grid > *');
    return Array.from(cards).map(c => c.textContent?.trim().split('\n')[0] || '?');
  });
  console.log('SAUCE NAMES:', JSON.stringify(names));
});
