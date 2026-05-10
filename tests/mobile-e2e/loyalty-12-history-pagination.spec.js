// Scenario 12 — History with 100 seeded entries
// V0 ScreenLoyalty renders all entries in a single scrollable list (no virtualization).
// Phase 6 will require true pagination via /loyalty/history?page=N.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S12 — 100 history entries render and remain scrollable', async ({ page }) => {
  await waitForLoyaltyReady(page, {
    seedAccount: { balance: 1000 },
    seedHistory: 100,  // numeric → use LC.dev.seedHistory(100)
  });
  await gotoLoyaltyScreen(page);

  await page.click('[data-testid="loyalty-tab-history"]');
  await page.waitForTimeout(200);

  const count = await page.locator('[data-testid^="history-entry-"]').count();
  expect(count).toBe(100);

  // Scroll to last entry
  const lastEntry = page.locator('[data-testid^="history-entry-"]').nth(99);
  await lastEntry.scrollIntoViewIfNeeded();
  await expect(lastEntry).toBeVisible();

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/12-history-100-bottom.png' });

  // No duplicate IDs
  const ids = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('[data-testid^="history-entry-"]'))
      .map(el => el.getAttribute('data-testid'));
  });
  const uniq = new Set(ids);
  expect(uniq.size).toBe(ids.length);
});
