// Scenario 13 — Filter history by source (kiosk / mobile / pos)

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S13 — History filter chips narrow visible entries by source_surface', async ({ page }) => {
  await waitForLoyaltyReady(page, {
    seedAccount: { balance: 347 },
    seedHistory: [
      { id: 9001, date: '2026-05-08', type: 'earn', points: 25, description: 'Box Nashville (app)',    order_id: 'C-9001', source_surface: 'mobile' },
      { id: 9002, date: '2026-05-07', type: 'earn', points: 12, description: 'Smash (app)',            order_id: 'C-9002', source_surface: 'mobile' },
      { id: 9003, date: '2026-05-06', type: 'earn', points: 18, description: 'Box Nashville (kiosk)',  order_id: 'C-9003', source_surface: 'kiosk' },
      { id: 9004, date: '2026-05-05', type: 'earn', points: 22, description: 'Wrap (kiosk)',           order_id: 'C-9004', source_surface: 'kiosk' },
      { id: 9005, date: '2026-05-04', type: 'earn', points: 15, description: 'Le Gourmet (POS)',       order_id: 'C-9005', source_surface: 'pos' },
    ],
  });
  await gotoLoyaltyScreen(page);
  await page.click('[data-testid="loyalty-tab-history"]');
  await page.waitForTimeout(150);

  // 'all' default — 5 entries
  await expect(page.locator('[data-testid^="history-entry-"]')).toHaveCount(5);

  // Filter kiosk
  await page.click('[data-testid="history-filter-kiosk"]');
  await page.waitForTimeout(100);
  await expect(page.locator('[data-testid^="history-entry-"]')).toHaveCount(2);

  // Filter mobile
  await page.click('[data-testid="history-filter-mobile"]');
  await page.waitForTimeout(100);
  await expect(page.locator('[data-testid^="history-entry-"]')).toHaveCount(2);

  // Filter pos
  await page.click('[data-testid="history-filter-pos"]');
  await page.waitForTimeout(100);
  await expect(page.locator('[data-testid^="history-entry-"]')).toHaveCount(1);

  // Filter all — back to 5
  await page.click('[data-testid="history-filter-all"]');
  await page.waitForTimeout(100);
  await expect(page.locator('[data-testid^="history-entry-"]')).toHaveCount(5);

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/13-filter-all.png' });
});
