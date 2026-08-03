// Adversarial A1 — Replay presse-papiers du QR (Agent-6 §4)
//
// [GOAL-SYNC 2026-07-08] adapté au QR RÉEL (contrat §3) : le payload est le
// token signé 'lqr.…' (TTL 300 s) rendu en SVG — il ne doit JAMAIS apparaître
// comme texte sélectionnable dans le DOM (pas de copie silencieuse par
// long-press). Le loyalty_code humain (8 alphanum) est VOLONTAIREMENT affiché
// en clair (« dictez votre numéro en caisse ») — c'est le token signé qui est
// la donnée sensible, pas le code.

const { test, expect } = require('@playwright/test');
const { waitForLoyaltyReady, gotoLoyaltyScreen } = require('./utils/waitForLoyaltyReady');
const { seedRealAuth } = require('./utils/realAuth');

test('A1 — Le token QR lqr. n\'apparaît jamais en texte sélectionnable', async ({ page, request }) => {
  await waitForLoyaltyReady(page);
  await seedRealAuth(page, request);
  await gotoLoyaltyScreen(page);

  await page.waitForSelector('[data-testid="loyalty-qr"]');
  await page.waitForSelector('[data-testid="loyalty-qr-svg"] svg', { timeout: 15000 });

  // Payload = attribut data (pas du texte)
  const payload = await page.locator('[data-testid="loyalty-qr"]').getAttribute('data-payload');
  expect(payload).toMatch(/^lqr\./);

  // Le token signé n'est PAS dans le texte du body (aucune copie silencieuse possible)
  const bodyText = await page.evaluate(() => document.body.innerText);
  expect(bodyText).not.toContain(payload);
  expect(bodyText).not.toMatch(/lqr\.[A-Za-z0-9_\-\.]{20,}/);

  // Le loyalty_code humain, lui, EST affiché (design contrat §3)
  await expect(page.locator('[data-testid="loyalty-code-text"]')).toBeVisible();
});
