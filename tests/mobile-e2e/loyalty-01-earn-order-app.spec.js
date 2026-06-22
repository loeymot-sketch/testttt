// Scenario 01 — Earn via order app (LC.dev.earnPoints debit balance + history entry)

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S01 — Earn via purchase_app updates balance + history entry visible', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 100, lifetime_earned: 100 } });
  await gotoLoyaltyScreen(page);

  // DEC-02 rate guard — earn_ratio MUST match backend default (10 pt/€).
  // Reverting this back to 1 (the original mock drift) would silently
  // 10x under-display points without breaking the rest of the suite.
  const earnRatio = await page.evaluate(() => window.LC.loyalty.config.earn_ratio);
  expect(earnRatio).toBe(10);

  const before = await page.locator('[data-testid="loyalty-balance"]').innerText();
  expect(before).toBe('100');

  // Trigger earn — order app gives +33 pts (3.3€ at 10pt/€ rate)
  await page.evaluate(() => window.LC.dev.earnPoints(33, 'purchase_app', { order_id: 'C-9000', serial: 9000 }));
  await page.waitForTimeout(150);

  // Balance updated
  await expect(page.locator('[data-testid="loyalty-balance"]')).toHaveText('133');

  // History tab — new entry with mobile source
  await page.click('[data-testid="loyalty-tab-history"]');
  await page.waitForTimeout(150);
  const entries = page.locator('[data-testid^="history-entry-"]');
  await expect(entries.first()).toContainText(/\+33/);
  const firstIcon = entries.first().locator('[data-testid="history-source-icon"]');
  await expect(firstIcon).toHaveAttribute('data-source-surface', /mobile/);

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/01-earn-history.png' });
});
