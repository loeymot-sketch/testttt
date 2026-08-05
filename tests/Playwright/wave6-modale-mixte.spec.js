const { test } = require('@playwright/test');
const BASE = 'http://127.0.0.1:8000';
const SHOTS = 'reports/goal-8axes-2026-08-05/wave6';

test('modale MIXTE', async ({ page }) => {
  test.setTimeout(90000);
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  await page.fill('#formEmail', 'pos@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.click('button:has-text("Connexion")');
  await page.waitForTimeout(3000);
  await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(6000);
  await page.getByRole('button', { name: '💳 Encaisser' }).first().click({ timeout: 8000 });
  await page.waitForTimeout(1500);
  const mixte = page.locator('[data-testid="pos-counter-collect-mode-MIXTE"]');
  await mixte.waitFor({ state: 'visible', timeout: 8000 });
  await mixte.click();
  await page.waitForTimeout(600);
  // Taper 5,00 sur la 1re partie via le champ
  await page.locator('#ccReceivedInput').evaluate((el) => { el.removeAttribute('readonly'); });
  await page.fill('#ccReceivedInput', '5,00');
  await page.waitForTimeout(600);
  await page.screenshot({ path: `${SHOTS}/caisse-modale-mixte.png` });
  await page.locator('[data-testid="pos-counter-collect-close"]').click().catch(() => {});
});
