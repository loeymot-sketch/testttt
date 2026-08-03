// Scenario 04 — Redeem CONTINU points→€ (LoyaltyRedeemPanel, contrat §2)
//
// [GOAL-SYNC 2026-07-08] réécrit : le catalogue mock 8 rewards + WizardRedeem
// (LC.dev.redeemReward) est REMPLACÉ par le modèle continu 100 pts = 1 €
// (multiples de 100, POST /api/frontend/loyalty/redeem RÉEL, X-Idempotency-Key).
// Invariants verrouillés ici (fallback local hors-ligne token invalide) :
//   • règle « 100 pts = 1 € » affichée ; sélection par pas de 100, clampée au solde
//   • CTA → phase confirmation → POST réel /loyalty/redeem (1 seul appel)
//   • réponse non-2xx (session invalide / kill-switch 422 V1) ⇒ erreur FR propre
//   • le client NE DÉBITE JAMAIS le solde localement (le backend est le SSOT)

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('S04 — Panel redeem continu : pas de 100, confirm → POST réel, erreur FR, zéro débit client', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 347 } });
  await gotoLoyaltyScreen(page);

  await page.click('[data-testid="loyalty-tab-rewards"]');
  await page.waitForSelector('[data-testid="redeem-panel"]', { timeout: 5000 });

  // Règle du modèle continu affichée
  await expect(page.locator('[data-testid="redeem-rule"]')).toContainText('100 pts = 1 €');

  // Sélection par multiples de 100, clampée au solde (347 → max 300)
  await expect(page.locator('[data-testid="redeem-points-value"]')).toContainText('100 pts');
  await page.click('button[aria-label="Ajouter 100 points"]');
  await page.click('button[aria-label="Ajouter 100 points"]');
  await expect(page.locator('[data-testid="redeem-points-value"]')).toContainText('300 pts');
  await expect(page.locator('button[aria-label="Ajouter 100 points"]')).toBeDisabled(); // plafond solde
  await expect(page.locator('[data-testid="redeem-euro-value"]')).toContainText('3,00');

  // CTA → confirmation → submit = exactement 1 POST /loyalty/redeem
  const redeemPosts = [];
  page.on('request', r => { if (/\/api\/frontend\/loyalty\/redeem/.test(r.url()) && r.method() === 'POST') redeemPosts.push(r.url()); });
  await page.click('[data-testid="redeem-cta"]');
  await page.click('[data-testid="redeem-confirm-btn"]');
  await page.waitForSelector('[data-testid="redeem-error"]', { timeout: 15000 });
  expect(redeemPosts.length).toBe(1);

  // Erreur FR propre (session mock invalide → non-2xx géré, pas de crash EN)
  const errText = await page.locator('[data-testid="redeem-error"]').innerText();
  expect(errText).not.toMatch(/Too Many|Unauthenticated|error|undefined/i);
  expect(errText.length).toBeGreaterThan(8);

  // Le client n'a RIEN débité localement (backend = SSOT des points)
  const balance = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(balance).toBe(347);
  const redeems = await page.evaluate(() => window.LC.loyalty.history.filter(h => h.type === 'redeem').length);
  expect(redeems).toBe(0);

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/04-redeem-panel.png' });
});
