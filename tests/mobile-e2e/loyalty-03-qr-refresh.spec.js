// Scenario 03 — QR fidélité RÉEL : mint-on-display + rotation via « Actualiser »
//
// [GOAL-SYNC 2026-07-08] réécrit pour le modèle RÉEL (contrat §3) : le QR n'est
// plus un mock 'FK:<code>' (format REJETÉ backend) mais un token signé serveur
// 'lqr.…' minté par POST /api/frontend/loyalty/qr (TTL 300 s). On vérifie :
//   • payload initial = token 'lqr.…' réel (auth réelle guest-signup)
//   • bouton « Actualiser » re-mint → le payload CHANGE (signature/timestamp)
//   • compte à rebours FR affiché et re-anchoré après refresh
// L'ancien mock offline n'existe plus par design : sans réseau le composant
// affiche l'état hors-ligne FR (JAMAIS de QR legacy) — couvert par A2/A1.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');
const { seedRealAuth } = require('./utils/realAuth');

test('S03 — QR réel lqr. : mint-on-display + rotation payload via Actualiser', async ({ page, request }) => {
  await waitForLoyaltyReady(page);
  await seedRealAuth(page, request);
  await gotoLoyaltyScreen(page);

  // QR réel rendu (lib vendorisée locale) + payload token serveur
  await page.waitForSelector('[data-testid="loyalty-qr"]');
  await page.waitForSelector('[data-testid="loyalty-qr-svg"] svg', { timeout: 15000 });
  const payload1 = await page.locator('[data-testid="loyalty-qr"]').getAttribute('data-payload');
  expect(payload1).toMatch(/^lqr\./);
  expect(payload1).not.toMatch(/^FK:/); // legacy interdit (rejeté backend)

  // Compte à rebours FR visible
  const countdown = page.locator('[data-testid="loyalty-qr-countdown"]');
  await expect(countdown).toBeVisible();
  await expect(countdown).toContainText(/Expire dans/i);

  // « Actualiser » re-mint un token — le payload change (nouvelle signature serveur).
  // Click via JS : un div décoratif (hero) intercepte le pointer en headless.
  await page.evaluate(() => document.querySelector('[data-testid="loyalty-qr-refresh"]').click());
  await page.waitForFunction(
    (p1) => {
      const el = document.querySelector('[data-testid="loyalty-qr"]');
      return el && el.getAttribute('data-payload') && el.getAttribute('data-payload') !== p1;
    },
    payload1,
    { timeout: 15000 }
  );
  const payload2 = await page.locator('[data-testid="loyalty-qr"]').getAttribute('data-payload');
  expect(payload2).toMatch(/^lqr\./);
  expect(payload2).not.toBe(payload1);

  // Countdown re-anchoré proche du TTL plein (≥ 4 min)
  await expect(countdown).toContainText(/Expire dans [45] min/i);

  await page.screenshot({ path: 'tests/e2e/__screenshots__/mobile-loyalty/03-qr-refresh.png' });
});
