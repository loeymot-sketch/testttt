// Scenario 11 — Opt-out RGPD via ModalOptOutConfirm

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S11 — RGPD opt-out hides QR + shows reactivation panel', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 347 } });
  await gotoLoyaltyScreen(page);

  // QR is initially visible
  await expect(page.locator('[data-testid="loyalty-qr"]')).toBeVisible();

  // Scroll to opt-out button + click
  await page.locator('[data-testid="loyalty-opt-out-btn"]').scrollIntoViewIfNeeded();
  await page.click('[data-testid="loyalty-opt-out-btn"]');

  // Confirm modal appears — outer wrapper carries ARIA attrs but is layout-less,
  // so we check DOM presence + visible content separately.
  const modalWrapper = page.locator('[data-modal-kind="opt-out-confirm"]');
  await expect(modalWrapper).toBeAttached();
  await expect(page.getByText(/Désactiver mon/i).first()).toBeVisible();
  await expect(page.getByText(/365 jours|RGPD/i).first()).toBeVisible();

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/11-opt-out-modal.png' });

  // Confirm
  await page.click('[data-testid="opt-out-confirm-btn"]');
  await page.waitForTimeout(200);

  // Consent flag set
  const consent = await page.evaluate(() => localStorage.getItem('lecayenne.loyalty_consent'));
  expect(consent).toContain('opted_out');

  // QR hidden + reactivation panel shown (multiple matches expected — toast + screen)
  await expect(page.locator('[data-testid="loyalty-qr"]')).toHaveCount(0);
  await expect(page.getByText(/Programme désactivé/i).first()).toBeVisible();
  await expect(page.getByText(/Réactiver mon compte/i)).toBeVisible();

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/11-opt-out-state.png' });
});
