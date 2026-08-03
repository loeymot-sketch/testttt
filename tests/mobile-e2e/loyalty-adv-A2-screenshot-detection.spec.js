// Adversarial A2 — détection screenshot impossible en PWA ; TTL = mitigation
//
// [GOAL-SYNC 2026-07-08] adapté au QR réel : le compte à rebours FR
// (« Expire dans X min Y s ») est la mitigation visible — TTL serveur 300 s
// (un screenshot du QR devient inutilisable après expiration du token signé).

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');
const { seedRealAuth } = require('./utils/realAuth');

test('A2 — V0 ne détecte pas le screenshot ; le compte à rebours TTL est la mitigation', async ({ page, request }) => {
  await waitForLoyaltyReady(page);
  await seedRealAuth(page, request);
  await gotoLoyaltyScreen(page);

  // Countdown FR visible avec TTL serveur
  const countdown = page.locator('[data-testid="loyalty-qr-countdown"]');
  await expect(countdown).toBeVisible();
  await expect(countdown).toContainText(/Expire dans/i);
  await expect(countdown).toContainText(/min|s/);

  // Le TTL vient du serveur (~300 s) — jamais un QR permanent
  const text = await countdown.innerText();
  expect(text).toMatch(/Expire dans [0-5] min|Expire dans \d+ s/i);
});
