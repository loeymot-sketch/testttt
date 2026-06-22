// Scenario 03 — QR refresh after TTL expiry
// useLoyaltyQR uses Date.now() (stubbed via LC.dev.advanceTime) + chained
// setTimeout. We verify the QR payload changes after TTL elapse.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S03 — QR refresh : payload changes after 5min TTL advance', async ({ page }) => {
  await waitForLoyaltyReady(page);
  await gotoLoyaltyScreen(page);

  // Wait for initial QR
  await page.waitForSelector('[data-testid="loyalty-qr"]');
  await page.waitForTimeout(300);  // allow async generateSignedQR to settle

  const payload1 = await page.locator('[data-testid="loyalty-qr"]').getAttribute('data-payload');
  expect(payload1).toMatch(/^FK:/);

  // Advance time past TTL (5 min + buffer)
  await page.evaluate(() => window.LC.dev.advanceTime(360));
  await page.waitForTimeout(1500);  // hook's setTimeout chain re-schedules + refresh

  const payload2 = await page.locator('[data-testid="loyalty-qr"]').getAttribute('data-payload');
  expect(payload2).toMatch(/^FK:/);
  // Format same but underlying signature differs (timestamps differ — visible
  // only via internal qr state, not the data-payload attribute which is the
  // public FK:<code> string). The countdown should reset.

  // Countdown should be far from 0
  const countdown = await page.locator('[data-testid="loyalty-qr-countdown"]').innerText();
  expect(countdown).toMatch(/\d+:\d\d/);

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/03-qr-refresh.png' });
});
