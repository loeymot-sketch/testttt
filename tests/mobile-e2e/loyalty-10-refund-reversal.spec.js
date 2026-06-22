// Scenario 10 — Refund/reversal mirrors backend LoyaltyService::refundPoints
// V0 writes `type='manual_add'` reversal (per backend enum) — NOT 'reverse'.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady } = require('./utils/waitForLoyaltyReady');

test('S10 — Refund writes manual_add reversal entry + restores balance', async ({ page }) => {
  await waitForLoyaltyReady(page, {
    seedAccount: { balance: 380 },
    seedHistory: [
      { id: 9999, date: '2026-05-09', type: 'earn', points: 33, description: 'Box Nashville', order_id: 'C-9999', source_surface: 'mobile' },
    ],
  });

  const beforeRefund = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(beforeRefund).toBe(380);

  // Simulate refund
  const reversal = await page.evaluate(() => window.LC.dev.simulateRefund('C-9999'));
  expect(reversal).toBeTruthy();
  expect(reversal.type).toBe('manual_add');
  expect(reversal.points).toBe(-33);
  expect(reversal.linked_to_history_id).toBe(9999);

  // Balance restored (33 deducted from 380 = 347)
  const afterRefund = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(afterRefund).toBe(347);

  // Original earn entry STILL PRESENT (append-only audit pattern)
  const originalStillThere = await page.evaluate(() => window.LC.loyalty.history.some(h => h.id === 9999));
  expect(originalStillThere).toBe(true);

  // Reversal entry at top
  const top = await page.evaluate(() => window.LC.loyalty.history[0]);
  expect(top.type).toBe('manual_add');
  expect(top.points).toBe(-33);
});
