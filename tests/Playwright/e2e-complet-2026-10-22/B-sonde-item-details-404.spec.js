/**
 * [E2E-COMPLET 2026-10-22 · VAGUE B] Sonde : qui demande `GET /api/admin/item/details/{1|3}` (404) quand la caisse s'ouvre / qu'on ajoute un article ?
 * Les items 1 « Menu (Frites + Boisson) », 2 « Frites Seules », 3 « Boisson Seule » sont dans la catégorie 27 « Technique (interne — upsell) » (inactive).
 * On relève l'initiateur réseau (pile JS) par CDP. Lecture seule, aucune vente.
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../e2e/__screenshots__/e2e-complet-B/_resultats-sonde-item-details.json');
test.setTimeout(240_000);

test('sonde item/details 404 : initiateur', async ({ page, context }) => {
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.enable');
  const trouve = [];
  cdp.on('Network.requestWillBeSent', (e) => {
    if (/\/api\/admin\/item\/details\/\d+/.test(e.request.url)) {
      trouve.push({ url: e.request.url.replace(/^https?:\/\/[^/]+/, ''), type: e.initiator.type, stack: (e.initiator.stack ? e.initiator.stack.callFrames : []).slice(0, 8).map((f) => `${f.functionName || '(anon)'} ${f.url.split('/').pop()}:${f.lineNumber}`), asyncParent: e.initiator.stack && e.initiator.stack.parent ? (e.initiator.stack.parent.callFrames || []).slice(0, 4).map((f) => `${f.functionName || '(anon)'} ${f.url.split('/').pop()}:${f.lineNumber}`) : null, t: Date.now() });
    }
  });
  const statuts = [];
  page.on('response', (r) => { if (/\/api\/admin\/item\/details\/\d+/.test(r.url())) statuts.push(`${new URL(r.url()).pathname} ${r.status()}`); });
  await loginAsPosOperator(page);
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(3000);
  const apresChargement = [...statuts];
  const ov = page.locator('[data-testid="cash-session-overlay"]');
  if (await ov.isVisible().catch(() => false)) { await page.locator('[data-testid="cash-session-close"]').click(); await page.waitForTimeout(400); }
  await page.locator('[data-testid="pos-category-tile"]', { hasText: 'Boissons' }).first().click();
  await page.waitForTimeout(1200);
  const tuiles = await page.evaluate(() => [...document.querySelectorAll('[data-pos-item-id]')].map((e) => e.getAttribute('data-pos-item-id')));
  const avantClic = [...statuts];
  await page.locator('button.pos-item-tile[aria-label^="Ajouter Sprite"]').first().click();
  await page.waitForTimeout(2500);
  const out = { apresChargement, tuiles_boissons: tuiles, avantClic, toutes: statuts, initiateurs: trouve };
  fs.writeFileSync(OUT, JSON.stringify(out, null, 2));
  console.log('[SONDE-ITEM]', JSON.stringify(out).slice(0, 3000));
});
