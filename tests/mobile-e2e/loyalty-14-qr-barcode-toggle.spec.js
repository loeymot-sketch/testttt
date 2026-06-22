// Scenario 14 — Toggle QR ↔ Barcode, persist across reload
// Verifies localStorage.qr_preference survives reload.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S14 — QR/Barcode toggle persists preference across reload', async ({ page }) => {
  await waitForLoyaltyReady(page);
  await gotoLoyaltyScreen(page);

  // Default = QR
  const qrInitial = page.locator('[data-testid="loyalty-qr"]');
  await expect(qrInitial).toBeVisible();

  // Toggle to barcode
  await page.click('[data-testid="qr-mode-toggle"]');
  await page.waitForTimeout(200);

  const pref1 = await page.evaluate(() => localStorage.getItem('lecayenne.qr_preference'));
  expect(pref1).toContain('barcode');

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/14-barcode-mode.png' });

  // Reload — preference must survive
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-screen-label]');
  await page.evaluate(() => {
    const profileTab = Array.from(document.querySelectorAll('.lc-tab')).find(el => /Profil/i.test(el.textContent));
    if (profileTab) profileTab.click();
  });
  await page.waitForTimeout(150);
  await page.evaluate(() => {
    const el = Array.from(document.querySelectorAll('div')).find(d => /Carte fidélité/i.test(d.textContent || '') && d.style && d.style.cursor === 'pointer');
    if (el) el.click();
  });
  await page.waitForSelector('[data-testid="loyalty-screen"]');

  const pref2 = await page.evaluate(() => localStorage.getItem('lecayenne.qr_preference'));
  expect(pref2).toContain('barcode');

  // Toggle back to QR
  await page.click('[data-testid="qr-mode-toggle"]');
  await page.waitForTimeout(150);
  const pref3 = await page.evaluate(() => localStorage.getItem('lecayenne.qr_preference'));
  expect(pref3).toContain('qr');
});
