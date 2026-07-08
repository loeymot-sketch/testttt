// Scenario 02 — Earn via phone at kiosk (fallback local hors-ligne)
//
// [GOAL-SYNC 2026-07-08] réécrit pour le modèle RÉEL : `welcome_bonus` a été
// RETIRÉ des EARN_METHODS (aucun trigger backend n'existe — contrat §2, earn
// = floor(total × 1 pt/€) crédité par le backend au statut PREPARED/DELIVERED).
// Ce spec verrouille la mécanique EARN LOCALE hors-ligne (source kiosk wired
// 'purchase_kiosk_phone') : crédit + entrée d'historique avec la bonne surface.
// L'earn réel bout-en-bout est couvert par la commande réelle du smoke
// (reports/goal-web-app-sync/captures-mobile).

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady } = require('./utils/waitForLoyaltyReady');

test('S02 — Earn kiosk (source wired) crédite le solde local + entrée historique kiosk', async ({ page }) => {
  await waitForLoyaltyReady(page);

  // welcome_bonus n'existe PLUS — earnPoints doit le refuser proprement (null).
  const welcomeRejected = await page.evaluate(() => window.LC.dev.earnPoints(25, 'welcome_bonus') === null);
  expect(welcomeRejected).toBe(true);

  const before = await page.evaluate(() => window.LC.loyalty.account.balance);
  await page.evaluate(() => {
    window.LC.dev.earnPoints(12, 'purchase_kiosk_phone', { order_id: 'C-9012', serial: 9012 });
  });
  await page.waitForTimeout(150);

  const balance = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(balance).toBe(before + 12);

  // Entrée d'historique avec la surface kiosk
  const hasKioskEntry = await page.evaluate(() => {
    return window.LC.loyalty.history.some(h => h.type === 'earn' && h.points === 12 && h.source_surface === 'kiosk');
  });
  expect(hasKioskEntry).toBe(true);

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/02-kiosk-earn-applied.png' });
});
