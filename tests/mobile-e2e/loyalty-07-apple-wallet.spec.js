// Scenario 07 — Add to Apple Wallet → ModalWalletV0Notice

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S07 — Apple Wallet button opens V0 notice modal with onSeeQR CTA', async ({ page }) => {
  await waitForLoyaltyReady(page);
  await gotoLoyaltyScreen(page);

  // Wallet pill present
  const btn = page.locator('[data-testid="wallet-apple-btn"]');
  await expect(btn).toBeVisible();

  await btn.click();
  await page.waitForTimeout(200);

  // Modal opens with content
  await expect(page.locator('[data-modal-kind="wallet-apple"]')).toBeAttached();
  await expect(page.getByText(/Bientôt/i).first()).toBeVisible();
  await expect(page.getByText(/Pass Type ID|provisioning/i).first()).toBeVisible();

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/07-wallet-apple-modal.png' });

  // CTA "Voir mon QR" navigates back to loyalty (already there) — close modal
  await page.getByText('Voir mon QR').click();
  await page.waitForTimeout(200);
  await expect(page.locator('[data-modal-kind="wallet-apple"]')).toHaveCount(0);
});
