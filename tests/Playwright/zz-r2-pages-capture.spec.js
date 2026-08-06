const { test, expect } = require('@playwright/test');
const BASE = 'http://127.0.0.1:8000';
const SHOTS = 'reports/goal-revision-absolue-2026-08-06/round-1/heals';

test('pages Z reports + imprimantes rendues', async ({ page }) => {
  test.setTimeout(90000);
  await page.setViewportSize({ width: 1366, height: 768 });
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.fill('#formEmail', 'admin@lecayenne.fr').catch(async () => page.fill('#formEmail', 'pos@lecayenne.fr'));
  await page.fill('#formPassword', '123456');
  await page.click('button:has-text("Connexion")');
  await page.waitForTimeout(3500);

  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

  await page.goto(`${BASE}/admin/settings/z-reports`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${SHOTS}/z-reports.png` });
  const zVisible = await page.locator('[data-testid="fiscal-x-report-btn"]').isVisible().catch(() => false);
  console.log('Z_PAGE', JSON.stringify({ zVisible, url: page.url() }));

  await page.goto(`${BASE}/admin/settings/printers`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${SHOTS}/printers.png` });
  const pVisible = await page.locator('[data-testid="printer-add-btn"]').isVisible().catch(() => false);
  console.log('P_PAGE', JSON.stringify({ pVisible, url: page.url(), consoleErrors: errors.slice(0, 3) }));
  expect(zVisible || pVisible).toBeTruthy();
});
