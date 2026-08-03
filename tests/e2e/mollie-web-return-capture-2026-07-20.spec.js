// ============================================================================
// PREUVE RÉELLE — RETOUR de paiement Mollie ({site}?order={id}) contre le BACKEND LOCAL.
// Prouve le SECOND maillon du flux carte (le webhook→PAID est déjà prouvé backend 8/8 ;
// le paiement sur la page Mollie elle-même = seul reste, gated clé test_) :
//   commande PAID en base → retour ?order=N → handler sonde le statut SERVEUR réel →
//   « Paiement confirmé ✓ » sur le compte client ; commande UNPAID → repli comptoir.
// = « le statut synchronisé doit être affiché sur son compte que c'est prêt/payé » (mandat).
// Override base→:8000 + apiKey local au runtime (addInitScript) : aucun fichier édité.
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 \
//         SEED_TOKEN=... PAID_ID=... UNPAID_ID=... PAID_SERIAL=... \
//         npx playwright test tests/e2e/mollie-web-return-capture-2026-07-20.spec.js --project=chromium
// ============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'mollie-web-return-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);

const TOKEN = process.env.SEED_TOKEN;
const PAID_ID = process.env.PAID_ID;
const UNPAID_ID = process.env.UNPAID_ID;
const PAID_SERIAL = process.env.PAID_SERIAL || ('#' + PAID_ID);
const LOCAL_BASE = 'http://127.0.0.1:8000';
const LOCAL_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';

test.describe.configure({ retries: 0 });
test.setTimeout(120_000);

async function primeReturn(page, { orderDbId, serial, total, points }) {
  await page.addInitScript((d) => {
    try { localStorage.setItem('lecayenne.authToken', d.token); } catch (e) {}
    try {
      sessionStorage.setItem('lc.mollie.pending', JSON.stringify({
        orderDbId: d.orderDbId, orderId: d.serial, orderQueue: null,
        orderTotal: d.total, earnedPoints: d.points,
      }));
    } catch (e) {}
    // dès que window.LC.api existe (chargé avant l'App), forcer le backend LOCAL
    const iv = setInterval(() => {
      if (window.LC && window.LC.api && window.LC.api.config) {
        window.LC.api.config.base = d.base;
        window.LC.api.config.apiKey = d.apiKey;
        clearInterval(iv);
      }
    }, 5);
  }, { token: TOKEN, base: LOCAL_BASE, apiKey: LOCAL_KEY, orderDbId, serial, total, points });
  await page.goto('/?order=' + orderDbId, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 45_000 });
}

test('Retour Mollie — commande PAID → « Paiement confirmé ✓ » sur le compte', async ({ page }) => {
  expect(TOKEN, 'SEED_TOKEN requis').toBeTruthy();
  await primeReturn(page, { orderDbId: PAID_ID, serial: PAID_SERIAL, total: 13.20, points: 132 });
  // le handler sonde getOrder → PAID (payment_status=5) → mollieReturn='paid'
  await expect(page.locator('.lcf-confirm-sub')).toContainText('Paiement confirmé', { timeout: 20_000 });
  const sub = (await page.locator('.lcf-confirm-sub').innerText()).replace(/\s+/g, ' ').trim();
  console.log('[PAID sub]', sub);
  await page.screenshot({ path: shot('01-return-PAID-confirme.png'), fullPage: true });
  expect(sub).not.toContain('Tu paies sur place'); // ne DOIT pas dire comptoir à un client qui a payé
});

test('Retour Mollie — commande UNPAID → repli comptoir (jamais faux « payé »)', async ({ page }) => {
  await primeReturn(page, { orderDbId: UNPAID_ID, serial: ('#' + UNPAID_ID), total: 13.20, points: 132 });
  // handler sonde 5× (~6s) puis conclut UNPAID → copie comptoir (jamais « Paiement confirmé »)
  await expect(page.locator('.lcf-confirm-sub')).toContainText('Tu paies sur place', { timeout: 20_000 });
  const sub = (await page.locator('.lcf-confirm-sub').innerText()).replace(/\s+/g, ' ').trim();
  console.log('[UNPAID sub]', sub);
  await page.screenshot({ path: shot('02-return-UNPAID-comptoir.png'), fullPage: true });
  expect(sub).not.toContain('Paiement confirmé'); // JAMAIS de faux « payé »
});
