// [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.2.1 · porte visuelle] Le reçu HTML de la caisse d'une LIVRAISON du site montre
// le bloc client (nom, téléphone, « Livrer à »), et « À régler à la livraison : <mode choisi> » — jamais « en caisse ».
//
//   ORDER_ID=<id d'une livraison web d'AUJOURD'HUI> PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/livraison-tickets-2026-11-10/recu-caisse-livraison.spec.js --reporter=line --workers=1
//
// Les captures vont dans CAPTURE_DIR (défaut : /tmp) — jamais commitées.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsPosOperator } = require('../../e2e/helpers/login');

const ORDER_ID = process.env.ORDER_ID;
const DIR = process.env.CAPTURE_DIR || '/tmp';

test.use({ viewport: { width: 1280, height: 800 } });

test('reçu caisse d’une livraison web : client, adresse, règlement à la porte', async ({ page }) => {
  test.skip(!ORDER_ID, 'ORDER_ID requis (livraison web du jour)');
  await loginAsPosOperator(page);
  await page.goto('/admin/pos-orders-tracker');
  const bouton = page.locator(`[data-testid="tracker-reprint-${ORDER_ID}"]`);
  await expect(bouton).toBeVisible({ timeout: 30_000 });
  await bouton.click();

  const recu = page.locator('#print-receipt-client');
  await expect(recu).toBeVisible({ timeout: 30_000 });
  const bloc = recu.locator('[data-testid="receipt-client-block"]');
  await expect(bloc).toBeVisible();
  const texte = await recu.innerText();
  expect(texte).toMatch(/Client\s*:/);
  expect(texte).toMatch(/Téléphone\s*:/);
  expect(texte).toMatch(/Livrer à\s*:/);
  expect(texte).toMatch(/À régler à la livraison\s*:\s*(Espèces|Carte au livreur|Ticket Restaurant)/);
  expect(texte).not.toMatch(/label\./); // aucune clé brute
  await page.waitForTimeout(600);
  await recu.screenshot({ path: path.join(DIR, `recu-caisse-livraison-${ORDER_ID}.png`) });
  const porte = recu.locator('[data-testid="receipt-door-payment"]');
  await porte.scrollIntoViewIfNeeded();
  await expect(porte).toBeVisible();
  await page.waitForTimeout(300);
  await recu.locator('xpath=..').screenshot({ path: path.join(DIR, `recu-caisse-livraison-${ORDER_ID}-reglement.png`) });
  await page.screenshot({ path: path.join(DIR, `recu-caisse-livraison-${ORDER_ID}-page.png`), fullPage: false });
});
