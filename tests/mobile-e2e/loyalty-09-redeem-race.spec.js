// Scenario 09 — Race condition : two concurrent LC.dev.redeemReward
// V0 atomic check via account.balance read-decrement.
// Phase 6 will test server-side Idempotency-Key + lockForUpdate.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady } = require('./utils/waitForLoyaltyReady');

test('S09 — Concurrent redeems : exactly one succeeds, balance correct', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 150 }, seedHistory: [] });

  // Two parallel Promise.allSettled redeems of reward id 2 (−1€, 100 pts).
  // Balance 150 → only one can succeed (would leave 50, second one fails INSUFFICIENT_POINTS).
  const result = await page.evaluate(async () => {
    const r = await Promise.allSettled([
      window.LC.dev.redeemReward(2),
      window.LC.dev.redeemReward(2),
    ]);
    return r.map(p => p.status === 'fulfilled' ? p.value : { ok: false, error: p.reason && p.reason.message });
  });

  const successes = result.filter(r => r.ok === true).length;
  const failures  = result.filter(r => r.ok === false).length;

  // V0 atomic localStorage : sequential async with the same JS event loop.
  // The 1st promise reads balance=150, decrements to 50. The 2nd reads 50
  // AFTER the 1st has resolved (single-threaded JS) → fails INSUFFICIENT.
  expect(successes).toBe(1);
  expect(failures).toBe(1);
  expect(result.find(r => !r.ok).error).toBe('INSUFFICIENT_POINTS');

  const balance = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(balance).toBe(50);

  // History — exactly one redeem entry
  const redeems = await page.evaluate(() => window.LC.loyalty.history.filter(h => h.type === 'redeem').length);
  expect(redeems).toBe(1);
});
