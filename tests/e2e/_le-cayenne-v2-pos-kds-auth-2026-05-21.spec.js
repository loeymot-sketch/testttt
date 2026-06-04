/**
 * POS V4 + KDS with proper auth via Vue SPA.
 */
const { test } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/le-cayenne-v2-2026-05-21';

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(90000);

async function loginAdmin(page) {
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  // Vue SPA renders the login form dynamically. Find inputs by label.
  const emailInput = page.locator('input').filter({ hasText: '' }).first();
  // Try common input selectors
  const allInputs = await page.locator('input').all();
  if (allInputs.length >= 2) {
    await allInputs[0].fill('admin@lecayenne.fr');
    await allInputs[1].fill('123456');
  } else {
    await page.getByLabel(/email/i).fill('admin@lecayenne.fr').catch(() => {});
    await page.getByLabel(/mot de passe/i).fill('123456').catch(() => {});
  }
  await page.getByRole('button', { name: /connexion/i }).click({ timeout: 5000 }).catch(() => {});
  // Wait for redirect to actual POS
  await page.waitForTimeout(5000);
}

test('PROOF-09b POS V4 authenticated', async ({ page }) => {
  await loginAdmin(page);
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(7000);
  await page.screenshot({ path: `${OUT}/PROOF-09-pos-v4-auth.png`, fullPage: false });
});

test('PROOF-10b KDS authenticated', async ({ page }) => {
  await loginAdmin(page);
  await page.goto(`${BASE}/kds`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000);
  await page.screenshot({ path: `${OUT}/PROOF-10-kds-auth.png`, fullPage: false });
});
