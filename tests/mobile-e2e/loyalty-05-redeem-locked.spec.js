// Scenario 05 — Paliers verrouillés (modèle continu, contrat §2)
//
// [GOAL-SYNC 2026-07-08] réécrit : plus de « Burger gratuit 1000 pts » (catalogue
// mock supprimé — aucune table backend). Les paliers indicatifs (tiers config
// 100/250/500/1000/2000) remplacent les récompenses : déverrouillé = solde ≥ palier,
// verrouillé = « X pts manquants » SANS bouton UTILISER (pas de CTA à force-cliquer).

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S05 — Paliers : déverrouillés ≤ solde avec UTILISER, verrouillés = pts manquants sans CTA', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 1347, lifetime_earned: 2247 } });
  await gotoLoyaltyScreen(page);

  // Onglet « Mes points » (défaut) : rows de paliers dérivés de la config réelle
  await page.waitForSelector('[data-testid="reward-row-1000"]', { timeout: 5000 });

  // 1000 déverrouillé à 1347 pts → bouton UTILISER présent.
  await expect(page.locator('[data-testid="reward-redeem-btn-1000"]')).toBeVisible();
  await expect(page.locator('[data-testid="reward-row-1000"]')).toContainText('Disponible');

  // 2000 verrouillé → « 653 pts manquants », AUCUN bouton UTILISER
  const locked = page.locator('[data-testid="reward-row-2000"]');
  await expect(locked).toContainText('653 pts manquants');
  await expect(page.locator('[data-testid="reward-redeem-btn-2000"]')).toHaveCount(0);

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/05-tier-locked.png' });

  // Défense : même en force-cliquant la row verrouillée, aucun débit local
  const before = await page.evaluate(() => window.LC.loyalty.account.balance);
  await page.evaluate(() => {
    const row = document.querySelector('[data-testid="reward-row-2000"]');
    if (row) row.click();
  });
  await page.waitForTimeout(100);
  const after = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(after).toBe(before);

  // UTILISER (100) bascule sur l'onglet Réductions avec le montant présélectionné
  await page.click('[data-testid="reward-redeem-btn-1000"]');
  await page.waitForSelector('[data-testid="redeem-panel"]', { timeout: 5000 });
  await expect(page.locator('[data-testid="redeem-points-value"]')).toContainText('1000 pts');
});
