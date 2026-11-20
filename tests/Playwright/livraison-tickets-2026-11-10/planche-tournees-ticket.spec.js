// [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.2.2 / T-1.3.3 · porte visuelle] La planche « Tournées » (/admin/livraisons)
// montre, pour un arrêt de livraison du site, le téléphone cliquable, l'adresse complète et le bouton « Ticket » ;
// le clic appelle le serveur (ticket client rendu, 200) et, sans pont d'impression sur ce poste, le DIT (toast d'erreur).
//
//   STOP_ID=<id d'un arrêt du jour> PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/livraison-tickets-2026-11-10/planche-tournees-ticket.spec.js --reporter=line --workers=1
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsPosOperator } = require('../../e2e/helpers/login');

const STOP_ID = process.env.STOP_ID;
const DIR = process.env.CAPTURE_DIR || '/tmp';

test.use({ viewport: { width: 1280, height: 800 } });

test('planche Tournées : téléphone, adresse complète, bouton Ticket qui parle', async ({ page }) => {
  test.skip(!STOP_ID, 'STOP_ID requis (arrêt de livraison du jour)');
  await loginAsPosOperator(page);
  await page.goto('/admin/livraisons');

  const bouton = page.locator(`[data-testid="pos-delivery-ticket-${STOP_ID}"]`);
  await expect(bouton).toBeVisible({ timeout: 30_000 });
  const tel = page.locator(`[data-testid="pos-delivery-phone-${STOP_ID}"]`);
  await expect(tel).toBeVisible();
  expect(await tel.getAttribute('href')).toMatch(/^tel:\+?\d{9,}$/);

  const carte = bouton.locator('xpath=ancestor::li[1]');
  await carte.screenshot({ path: path.join(DIR, `planche-tournees-arret-${STOP_ID}.png`) });
  await page.screenshot({ path: path.join(DIR, `planche-tournees-page-${STOP_ID}.png`) });

  const escpos = page.waitForResponse((r) => /\/admin\/pos\/orders\/\d+\/escpos\?ticket=client/.test(r.url()), { timeout: 15_000 });
  await bouton.click();
  const reponse = await escpos;
  expect(reponse.status()).toBe(200);
  const corps = await reponse.json();
  expect(typeof corps.escpos_b64).toBe('string');
  expect(corps.escpos_b64.length).toBeGreaterThan(100);

  // Pas de pont sur la machine de test : l'échec est annoncé, jamais muet.
  const toast = page.locator('text=/Pont d.impression injoignable|Ticket de livraison envoyé/');
  await expect(toast.first()).toBeVisible({ timeout: 10_000 });
  await page.screenshot({ path: path.join(DIR, `planche-tournees-ticket-toast-${STOP_ID}.png`) });
  await expect(bouton).toBeEnabled();
  expect(await page.locator('text=/label\\./').count()).toBe(0);
});
