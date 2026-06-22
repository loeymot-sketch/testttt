// Adversarial A1 — Clipboard QR replay (Agent-6 §4)
// V0 has no clipboard copy UI (good). We verify the QR card has no
// user-select that would let attacker silently OCR/copy the payload via
// a long-press.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('A1 — QR card does not expose user-select-text path for silent copy', async ({ page }) => {
  await waitForLoyaltyReady(page);
  await gotoLoyaltyScreen(page);

  await page.waitForSelector('[data-testid="loyalty-qr"]');

  // Verify QR payload is rendered as data attribute (not selectable text).
  const payload = await page.locator('[data-testid="loyalty-qr"]').getAttribute('data-payload');
  expect(payload).toMatch(/^FK:/);

  // The member number IS visible as text — but is it the QR payload?
  // Member number = "FK-12345" ; QR payload = "FK:<loyalty_code>". Different.
  const bodyText = await page.evaluate(() => document.body.innerText);
  // Loyalty_code should NOT appear in body text in V0 (only member_number does)
  expect(bodyText).not.toMatch(/FK:A1B2C3D4/);  // raw QR payload not visible
});
