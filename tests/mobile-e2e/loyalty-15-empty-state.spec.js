// Scenario 15 — Empty state for new user with 0 points

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S15 — Zero-balance user sees welcoming empty state + first-order CTA', async ({ page }) => {
  await waitForLoyaltyReady(page, {
    seedAccount: { balance: 0, lifetime_earned: 0, lifetime_redeemed: 0 },
    seedHistory: [],
  });
  await gotoLoyaltyScreen(page);

  // Balance shows 0
  await expect(page.locator('[data-testid="loyalty-balance"]')).toHaveText('0');

  // Points tab — empty state visible
  await page.click('[data-testid="loyalty-tab-points"]');
  await page.waitForTimeout(150);
  await expect(page.locator('[data-testid="loyalty-empty-state"]')).toBeVisible();
  await expect(page.locator('[data-testid="cta-first-order"]')).toBeVisible();

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/15-empty-state.png' });

  // No NaN / undefined in rendered text
  const bodyText = await page.evaluate(() => document.body.innerText);
  expect(bodyText).not.toContain('NaN');
  expect(bodyText).not.toContain('undefined');
  expect(bodyText).not.toContain('null pts');
});
