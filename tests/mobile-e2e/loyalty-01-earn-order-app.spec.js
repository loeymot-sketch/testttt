// Scenario 01 — Earn via order app (LC.dev.earnPoints debit balance + history entry)

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S01 — Earn via purchase_app updates balance + history entry visible', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 100, lifetime_earned: 100 } });
  await gotoLoyaltyScreen(page);

  // [GOAL-SYNC 2026-07-08] DEC-02 rate guard — earn_ratio = 1 pt/€ (canon backend
  // points_per_euro=1, floor du total TTC — contrat §2). L'ancien 10 pt/€ était
  // un drift mock : le verrou pointe désormais sur la valeur RÉELLE du backend.
  const earnRatio = await page.evaluate(() => window.LC.loyalty.config.earn_ratio);
  expect(earnRatio).toBe(1);
  const ppe = await page.evaluate(() => window.LC.loyalty.config.points_per_euro);
  expect(ppe).toBe(1);

  const before = await page.locator('[data-testid="loyalty-balance"]').innerText();
  expect(before).toBe('100');

  // Trigger earn — order app gives +33 pts (33 € à 1 pt/€ — fallback local hors-ligne ;
  // l'EARN réel est crédité par le backend au statut PREPARED/DELIVERED)
  await page.evaluate(() => window.LC.dev.earnPoints(33, 'purchase_app', { order_id: 'C-9000', serial: 9000 }));
  await page.waitForTimeout(150);

  // Balance updated
  await expect(page.locator('[data-testid="loyalty-balance"]')).toHaveText('133');

  // History tab — new entry with mobile source
  await page.click('[data-testid="loyalty-tab-history"]');
  await page.waitForTimeout(150);
  const entries = page.locator('[data-testid^="history-entry-"]');
  await expect(entries.first()).toContainText(/\+33/);
  const firstIcon = entries.first().locator('[data-testid="history-source-icon"]');
  await expect(firstIcon).toHaveAttribute('data-source-surface', /mobile/);

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/01-earn-history.png' });
});
