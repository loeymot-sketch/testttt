// Adversarial A4 — Double-tap « Confirmer » : un SEUL POST /loyalty/redeem
//
// [GOAL-SYNC 2026-07-08] réécrit pour LoyaltyRedeemPanel (modèle continu) :
// le double-tap rapide sur le bouton de confirmation ne doit produire qu'UN
// SEUL appel réseau (garde phase 'busy' + disabled). L'idempotence serveur
// (X-Idempotency-Key auto de api/client.js) est la 2e ligne de défense.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');

test('A4 — Double-tap sur Confirmer ⇒ exactement 1 POST redeem, zéro débit client', async ({ page }) => {
  await waitForLoyaltyReady(page, { seedAccount: { balance: 347 } });
  await gotoLoyaltyScreen(page);

  await page.click('[data-testid="loyalty-tab-rewards"]');
  await page.waitForSelector('[data-testid="redeem-panel"]', { timeout: 5000 });

  const redeemPosts = [];
  page.on('request', r => { if (/\/api\/frontend\/loyalty\/redeem/.test(r.url()) && r.method() === 'POST') redeemPosts.push(Date.now()); });

  await page.click('[data-testid="redeem-cta"]');
  const confirm = page.locator('[data-testid="redeem-confirm-btn"]');
  await Promise.all([
    confirm.click(),
    confirm.click({ timeout: 1500 }).catch(() => {}), // 2e tap : bouton déjà retiré/busy
  ]);
  await page.waitForSelector('[data-testid="redeem-error"]', { timeout: 15000 });

  expect(redeemPosts.length).toBe(1);

  // Zéro mutation locale (le backend est le SSOT points)
  const balance = await page.evaluate(() => window.LC.loyalty.account.balance);
  expect(balance).toBe(347);
  const redeems = await page.evaluate(() => window.LC.loyalty.history.filter(h => h.type === 'redeem').length);
  expect(redeems).toBe(0);
});
