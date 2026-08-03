// Adversarial A3 — localStorage tampering (devtools attack)
// V0 trusts localStorage (mock-only). We verify the BUG CLASS exists and
// document that Phase 6 backend MUST be source of truth.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('A3 — Tampered localStorage balance is reflected in V0 (Phase 6 must override)', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 100 } });

  // Tamper : pretend user has 99999 pts
  await page.evaluate(() => {
    const current = JSON.parse(localStorage.getItem('lecayenne.loyalty') || '{}');
    current.account = { ...(current.account || {}), balance: 99999 };
    localStorage.setItem('lecayenne.loyalty', JSON.stringify(current));
    if (window.LC && window.LC.loyalty && window.LC.loyalty._rehydrate) {
      window.LC.loyalty._rehydrate();
    }
  });

  await gotoLoyaltyScreen(page);
  await page.reload();
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

  // V0 BUG : balance shows 99999. Phase 6 MUST re-fetch from /loyalty/balance.
  await expect(page.locator('[data-testid="loyalty-balance"]')).toContainText('99999');
  // Documented limitation. Logged in 99_VERDICT.md §3.
});
