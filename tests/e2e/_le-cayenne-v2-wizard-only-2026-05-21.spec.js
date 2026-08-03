/**
 * Le Cayenne V2 — wizard sauce step (click viande card image).
 */
const { test } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/le-cayenne-v2-2026-05-21';

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(120000);

test('Tacos wizard — click viande image then advance', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.getByText('Tacos', { exact: true }).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.getByText('Personnaliser').first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);

  // Click the POULET MARINÉ text (full card click)
  await page.getByText('POULET MARINÉ', { exact: true }).first().click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(1200);
  await page.screenshot({ path: `${OUT}/70-viande-clicked.png`, fullPage: true });

  // Wait, then click SUIVANT (now hopefully enabled)
  const next = page.getByRole('button', { name: /^suivant$/i }).first();
  await next.click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.screenshot({ path: `${OUT}/71-sauce-step.png`, fullPage: true });
  await page.screenshot({ path: `${OUT}/72-sauce-viewport.png`, fullPage: false });
});
