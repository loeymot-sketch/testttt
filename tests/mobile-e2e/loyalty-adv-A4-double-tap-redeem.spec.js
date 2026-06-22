// Adversarial A4 — Rapid double-tap redeem (UX debouncing)
// V0 WizardRedeem inflight guard via setInflight state. Two quick clicks
// on "Appliquer maintenant" must result in exactly one redemption.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('A4 — Double-tap on apply-now button results in single redemption', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 347 } });
  await gotoLoyaltyScreen(page);

  await page.click('[data-testid="loyalty-tab-rewards"]');
  await page.waitForTimeout(150);
  await page.click('[data-testid="reward-redeem-btn-1"]');  // Frites M, 100pt

  await page.waitForSelector('[data-testid="redeem-wizard"]');
  await page.click('[data-testid="redeem-next-btn"]');
  await page.waitForTimeout(150);

  // Rapid double-tap
  const apply = page.locator('[data-testid="redeem-apply-now-btn"]');
  await Promise.all([
    apply.click(),
    apply.click().catch(() => {}),  // second may fail if button immediately disabled
  ]);
  await page.waitForTimeout(500);

  // Balance debited ONCE (347 → 247)
  const balance = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(balance).toBe(247);

  // History — exactly 1 redeem
  const redeems = await page.evaluate(() => window.LC.loyalty.history.filter(h => h.type === 'redeem' && h.reward_id === 1).length);
  expect(redeems).toBe(1);
});
