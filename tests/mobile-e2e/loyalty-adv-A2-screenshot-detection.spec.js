// Adversarial A2 — iOS screenshot detection (Phase 11 native only)
// PWA cannot detect screenshots. This test documents the limitation rather
// than verifying mitigation — mitigation = short QR TTL (Phase 6 backend).

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('A2 — V0 cannot detect screenshot; TTL countdown is the mitigation', async ({ page }) => {
  await waitForLoyaltyReady(page);
  await gotoLoyaltyScreen(page);

  // Verify countdown chip exists — shows TTL to user
  const countdown = page.locator('[data-testid="loyalty-qr-countdown"]');
  await expect(countdown).toBeVisible();
  await expect(countdown).toContainText(/\d+:\d{2}/);

  // Document expectation : Phase 11 native build adds Capacitor App listener
  // 'screenshotTaken' (iOS 14+). Until then, the 5-min TTL is the only defense.
  const text = await countdown.innerText();
  expect(text).toMatch(/Expire/i);
});
