// Scenario 09 — Race redeem : le client NE mutile JAMAIS le solde, erreurs typées
//
// [GOAL-SYNC 2026-07-08] réécrit : l'ancien LC.dev.redeemReward (débit localStorage
// atomique) n'existe plus comme chemin réel — le redeem est un POST backend avec
// X-Idempotency-Key (contrat §2), la course est arbitrée CÔTÉ SERVEUR (lock +
// idempotency + kill-switch 422 V1). Invariants côté client verrouillés ici :
//   • 2 appels concurrents api.loyaltyRedeem ⇒ 2 rejets TYPÉS {kind:'http'}
//     (session invalide) — AUCUNE unhandled rejection, AUCUN crash
//   • le solde local n'est JAMAIS modifié par le client (backend = SSOT)
//   • aucune entrée d'historique 'redeem' fabriquée côté client

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady } = require('./utils/waitForLoyaltyReady');

test('S09 — Redeems concurrents : erreurs typées gérées, zéro mutation client', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 150 }, seedHistory: [] });

  const result = await page.evaluate(async () => {
    const api = window.LC.mobileApi;
    const r = await Promise.allSettled([
      api.loyaltyRedeem('A1B2C3D4', 100),
      api.loyaltyRedeem('A1B2C3D4', 100),
    ]);
    return r.map(p => p.status === 'fulfilled'
      ? { ok: true }
      : { ok: false, kind: p.reason && p.reason.kind, message: p.reason && p.reason.message });
  });

  // Token mock 'test' ⇒ les DEUX rejettent proprement, typé http (pas network, pas crash)
  expect(result.filter(r => r.ok).length).toBe(0);
  result.forEach(r => {
    expect(r.kind).toBe('http');
    expect(String(r.message || '')).not.toMatch(/undefined|\[object/i);
  });

  // Solde local INTACT + aucune entrée redeem fabriquée
  const balance = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(balance).toBe(150);
  const redeems = await page.evaluate(() => window.LC.loyalty.history.filter(h => h.type === 'redeem').length);
  expect(redeems).toBe(0);
});
