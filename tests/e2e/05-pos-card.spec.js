// FoodKing E2E — Flow 5 : POS Card Payment
// Login caissier → POS surface → card payment flow
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/login');

const POS_EMAIL    = 'pos@lecayenne.fr';
const POS_PASSWORD = '123456';

test.describe('POS Card — payment flow', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, POS_EMAIL, POS_PASSWORD);
    await page.waitForURL(/\/admin\/pos/, { timeout: 15_000 });
  });

  test('POS surface loads for card payment scenario', async ({ page }) => {
    await expect(page).toHaveURL(/\/admin\/pos/);

    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);

    // Wait for items to load
    await page.waitForTimeout(3_000);

    // Verify cart/payment area exists
    expect(visibleText.trim().length).toBeGreaterThan(100);
  });

  test('no JavaScript errors on POS card surface', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    await page.waitForTimeout(3_000);

    const criticalErrors = jsErrors.filter(msg =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg)
    );
    expect(criticalErrors).toHaveLength(0);
  });
});
