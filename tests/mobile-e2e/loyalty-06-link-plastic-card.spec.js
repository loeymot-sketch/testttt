// Scenario 06 — Link plastic card via ModalCardLink

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S06 — Plastic card link persists localStorage flag + shows badge', async ({ page }) => {
  await waitForLoyaltyReady(page);
  await gotoLoyaltyScreen(page);

  // Initial state — "Lier carte plastique" CTA
  const linkBtn = page.locator('[data-testid="link-plastic-card-btn"]');
  await expect(linkBtn).toContainText(/Lier carte plastique/i);

  await linkBtn.click();
  // ModalCardLink visible
  await page.waitForTimeout(200);
  await expect(page.getByText(/Cadre la carte/i)).toBeVisible();

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/06-link-modal.png' });

  // Click "Activer l'appareil photo" → simulated scan success
  await page.getByText(/Activer l'appareil photo/i).click();
  await page.waitForTimeout(200);

  // Toast success
  await expect(page.locator('[data-testid="toast"][data-kind="success"]').first()).toBeVisible();

  // localStorage flag set
  const persisted = await page.evaluate(() => {
    const l = JSON.parse(localStorage.getItem('lecayenne.loyalty') || '{}');
    return l && l.account && l.account.plastic_card_linked;
  });
  expect(persisted).toBe(true);

  // After modal closes, CTA changes
  await page.waitForTimeout(500);
  await expect(linkBtn).toContainText(/Carte liée/i);
});
