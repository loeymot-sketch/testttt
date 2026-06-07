const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
test('CP-2: TR order #4212 admin show page shows "Ticket Restaurant" not blank', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/admin/pos-orders/show/4212', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const txt = await page.evaluate(() => document.body.innerText);
  const line = (txt.split('\n').find((l) => /Type de paiement/i.test(l)) || '').trim();
  console.log(`[CP-2 #4212] payment-line="${line}"`);
  expect(line).toContain('Ticket Restaurant');
});
