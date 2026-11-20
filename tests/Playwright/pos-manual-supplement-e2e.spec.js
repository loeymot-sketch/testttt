// [ORDER-INTEGRITY-KDS-LOYALTY-20260914] Browser proof that a cashier can add
// a named free-form supplement to the POS cart. Fiscal quote/commit is covered
// against the real backend in QuoteBindingTest.
const { test, expect } = require('@playwright/test');

// Must match playwright.config.js / PLAYWRIGHT_BASE_URL rather than an old
// workstation-only port, otherwise this genuine browser proof never starts.
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';

async function loginAsPos(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#formEmail', 'pos@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.locator('button:has-text("Connexion")').click();
  await expect(page).not.toHaveURL(/\/login$/, { timeout: 10_000 });
}

test('caisse : un supplément libre nommé est ajouté au panier et au total', async ({ page }) => {
  await loginAsPos(page);
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });

  const toggle = page.getByTestId('pos-manual-supplement-toggle');
  await expect(toggle).toBeVisible({ timeout: 12_000 });
  await toggle.click();
  await page.getByTestId('pos-manual-supplement-label').fill('Olives');
  await page.getByTestId('pos-manual-supplement-amount').fill('1,25');
  await page.getByTestId('pos-manual-supplement-save').click();

  const line = page.locator('.pos-v5-cart-item').filter({ hasText: 'Olives' });
  await expect(line).toBeVisible();
  await expect(line).toContainText('1,25');
  await expect(page.getByTestId('pos-grand-total')).toContainText('1,25');
});
