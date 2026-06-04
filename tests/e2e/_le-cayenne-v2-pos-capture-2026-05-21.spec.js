/**
 * Capture POS admin to inspect category header image bug.
 */
const { test } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/le-cayenne-v2-2026-05-21';

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(60000);

test('admin POS auth + category strip switch', async ({ page }) => {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.fill('input[name="email"]', 'admin@lecayenne.fr');
  await page.fill('input[name="password"]', '123456');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});

  await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000);
  await page.screenshot({ path: `${OUT}/80-pos-default.png`, fullPage: true });

  // Click each category and capture
  const cats = ['Sandwich Cayenne', 'Galette', 'Burgers', 'Tacos', 'Bols Gourmands', 'Boissons'];
  for (let i = 0; i < cats.length; i++) {
    await page.getByRole('tab', { name: new RegExp(cats[i], 'i') }).click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1200);
    const idx = String(81 + i).padStart(2, '0');
    await page.screenshot({ path: `${OUT}/${idx}-pos-${cats[i].toLowerCase().replace(/\s+/g, '-')}.png`, fullPage: false });
  }
});
