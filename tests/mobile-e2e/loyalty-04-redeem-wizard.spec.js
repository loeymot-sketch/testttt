// Scenario 04 — Redeem unlocked reward via WizardRedeem 3-step

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S04 — Wizard 3-step redeem flow debits balance and writes history', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 347 } });
  await gotoLoyaltyScreen(page);

  await page.click('[data-testid="loyalty-tab-rewards"]');
  await page.waitForTimeout(150);

  // Reward id=1 (Frites M, 100 pts) — unlocked at balance 347
  await page.click('[data-testid="reward-redeem-btn-1"]');

  // Wizard step 1
  await page.waitForSelector('[data-testid="redeem-wizard"]', { timeout: 5_000 });
  const wizardStep1 = page.locator('[data-testid="redeem-wizard"]');
  await expect(wizardStep1).toHaveAttribute('data-step', '1');
  await expect(page.getByText(/Tu reçois|Solde après/i).first()).toBeVisible();
  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/04-wizard-step1.png' });

  // Continuer → step 2
  await page.click('[data-testid="redeem-next-btn"]');
  await page.waitForTimeout(150);
  await expect(wizardStep1).toHaveAttribute('data-step', '2');
  await expect(page.getByText(/Appliquer maintenant/i)).toBeVisible();
  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/04-wizard-step2.png' });

  // Apply now → step 3
  await page.click('[data-testid="redeem-apply-now-btn"]');
  await page.waitForTimeout(300);
  await expect(wizardStep1).toHaveAttribute('data-step', '3');
  await expect(page.locator('[data-testid="redeem-success"]')).toBeVisible();
  await expect(page.locator('[data-testid="redeem-success-code"]')).toContainText(/^LCY-/);
  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/04-wizard-step3.png' });

  // Verify balance debited
  const balance = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(balance).toBe(247);

  // History entry written
  const lastEntry = await page.evaluate(() => window.LC.loyalty.history[0]);
  expect(lastEntry.type).toBe('redeem');
  expect(lastEntry.points).toBe(-100);
  expect(lastEntry.reward_id).toBe(1);

  // Close wizard
  await page.click('[data-testid="redeem-close-btn"]');
  await page.waitForTimeout(150);
  await expect(page.locator('[data-testid="redeem-wizard"]')).toHaveCount(0);
});
