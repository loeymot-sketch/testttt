// Scenario 05 — Redeem locked reward (insufficient points → disabled CTA + tooltip)
// Status : testable-now per Agent-6 §2 ; verifies disabled state + missing-points text.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S05 — Locked reward shows disabled CTA + missing-points tooltip', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 347, lifetime_earned: 1247 } });
  await gotoLoyaltyScreen(page);

  // Switch to Récompenses tab
  await page.click('[data-testid="loyalty-tab-rewards"]');
  await page.waitForSelector('#loyalty-tabpanel-rewards', { timeout: 5_000 });

  // Reward id 5 = "Burger gratuit", points_cost=1000 — locked at balance 347
  const lockedTooltip = page.locator('[data-testid="reward-locked-tooltip-5"]');
  await expect(lockedTooltip).toBeVisible();
  await expect(lockedTooltip).toContainText('653 pts manquants');

  const lockedBtn = page.locator('[data-testid="reward-redeem-btn-5"]');
  await expect(lockedBtn).toBeDisabled();
  await expect(lockedBtn).toContainText('Verrouillé');

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/05-reward-locked.png' });

  // Defensive : even if user force-clicks via JS, balance must not change
  const before = await page.evaluate(() => window.LC.loyalty.account.balance);
  await page.evaluate(() => {
    const btn = document.querySelector('[data-testid="reward-redeem-btn-5"]');
    if (btn) btn.click();
  });
  await page.waitForTimeout(100);
  const after = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(after).toBe(before);
});
