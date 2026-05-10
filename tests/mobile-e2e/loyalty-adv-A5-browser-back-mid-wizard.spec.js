// Adversarial A5 — Browser back during wizard step 2 (Agent-6 §4)
// Verify back navigation in wizard doesn't double-debit.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('A5 — Back during wizard step 2 returns to step 1 without debit', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 347 }, seedHistory: [] });
  await gotoLoyaltyScreen(page);

  await page.click('[data-testid="loyalty-tab-rewards"]');
  await page.waitForTimeout(150);
  await page.click('[data-testid="reward-redeem-btn-1"]');

  await page.waitForSelector('[data-testid="redeem-wizard"][data-step="1"]');
  await page.click('[data-testid="redeem-next-btn"]');
  await page.waitForSelector('[data-testid="redeem-wizard"][data-step="2"]');

  // Click Retour
  await page.click('[data-testid="redeem-back-btn"]');
  await page.waitForTimeout(200);

  // Back at step 1
  await expect(page.locator('[data-testid="redeem-wizard"]')).toHaveAttribute('data-step', '1');

  // Balance NOT debited
  const balance = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(balance).toBe(347);

  // No history entry
  const redeems = await page.evaluate(() => window.LC.loyalty.history.filter(h => h.type === 'redeem').length);
  expect(redeems).toBe(0);
});
