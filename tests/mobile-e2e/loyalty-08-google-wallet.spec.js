// Scenario 08 — Add to Google Wallet → ModalWalletV0Notice

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S08 — Google Wallet button opens V0 notice modal with Google branding', async ({ page }) => {
  await waitForLoyaltyReady(page);
  await gotoLoyaltyScreen(page);

  const btn = page.locator('[data-testid="wallet-google-btn"]');
  await expect(btn).toBeVisible();

  await btn.click();
  await page.waitForTimeout(200);

  await expect(page.locator('[data-modal-kind="wallet-google"]')).toBeAttached();
  await expect(page.getByText(/Bientôt/i).first()).toBeVisible();
  await expect(page.getByText(/Issuer ID|service|provisioning/i).first()).toBeVisible();

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/08-wallet-google-modal.png' });

  // Fermer button closes modal
  await page.getByText('Fermer').click();
  await page.waitForTimeout(200);
  await expect(page.locator('[data-modal-kind="wallet-google"]')).toHaveCount(0);
});
