// Adversarial A5 — Annuler en phase confirmation : retour sans AUCUN appel réseau
//
// [GOAL-SYNC 2026-07-08] réécrit pour LoyaltyRedeemPanel : « Annuler » pendant
// la phase de confirmation revient à la sélection SANS POST /loyalty/redeem et
// sans débit (l'ancien wizard mock 3-steps LC.dev.redeemReward est retiré).

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('A5 — Annuler en confirmation ⇒ retour sélection, zéro POST, zéro débit', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 347 }, seedHistory: [] });
  await gotoLoyaltyScreen(page);

  await page.click('[data-testid="loyalty-tab-rewards"]');
  await page.waitForSelector('[data-testid="redeem-panel"]', { timeout: 5000 });

  const redeemPosts = [];
  page.on('request', r => { if (/\/api\/frontend\/loyalty\/redeem/.test(r.url()) && r.method() === 'POST') redeemPosts.push(r.url()); });

  // CTA → phase confirmation
  await page.click('[data-testid="redeem-cta"]');
  await page.waitForSelector('[data-testid="redeem-confirm-btn"]', { timeout: 5000 });

  // Annuler → retour à la sélection (CTA visible à nouveau), AUCUN réseau parti
  await page.click('button:has-text("Annuler")');
  await page.waitForSelector('[data-testid="redeem-cta"]', { timeout: 5000 });
  expect(redeemPosts.length).toBe(0);

  // Solde intact + aucune entrée historique
  const balance = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(balance).toBe(347);
  const redeems = await page.evaluate(() => window.LC.loyalty.history.filter(h => h.type === 'redeem').length);
  expect(redeems).toBe(0);
});
