// C-r2-c101 — VÉRIFICATION DE FERMETURE de C-101 (P1 du tour 1) : un 422 à plusieurs erreurs ne doit plus afficher
// « (and N more error(s)) », ni dans la réponse API, ni à l'écran de l'app. Tour 2, vague C, e2e-complet-2026-10-22.
// Deux moyens indépendants : (1) l'API en direct (réponse JSON + base de données : AUCUNE commande créée),
// (2) l'écran de l'app (ligne A servie sur :8011, qui propose « 2 viandes » pour le Cayenne -> 422 réel), texte rendu.
// Les assertions portent sur le résultat attendu, pas sur l'absence de plantage.
const { test, expect, chromium, request } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const L = require('./C-lib-r2');

const A = L.API + '/api';
const J = L.journal(path.join(L.SHOTS_ROOT, 'c101'), 'constats.json');
const SITE_A = process.env.C_SITE_A || 'http://127.0.0.1:8011';

function sql(requete) {
  const env = fs.readFileSync(path.resolve(__dirname, '../../../.env'), 'utf8');
  const mdp = ((env.match(/^DB_PASSWORD=(.*)$/m) || [])[1] || '').trim().replace(/^"|"$/g, '');
  return execFileSync('mysql', ['-uroot', '-h127.0.0.1', 'foodking_e2e', '-N', '-B', '-e', requete], { env: { ...process.env, MYSQL_PWD: mdp }, encoding: 'utf8' }).trim();
}
const entetes = (extra = {}) => ({ Origin: 'https://localhost', 'X-API-KEY': L.cleApiLocale(), Accept: 'application/json', 'Content-Type': 'application/json', 'Accept-Language': 'fr', ...extra });
const ANGLAIS = /\(and \d+ more|\bmore errors?\)|\(and :count/;

test('C-R2-101-API 422 à plusieurs erreurs : résumé en français, aucune commande créée', async () => {
  test.skip(!L.jeton(), 'jeton E2E-APP- absent');
  const api = await request.newContext();
  const avant = sql('select count(*), ifnull(max(id),0) from orders');
  // Charge utile EXACTE envoyée par le wizard de la ligne A (Cayenne item 22, 2 variations viande 976+974) — vue au tour 1 (C-006).
  const corps = { branch_id: 1, order_type: 10, source: 5, is_advance_order: 10, payment_method: 1, expected_total: 7.4,
    items: JSON.stringify([{ item_id: 22, quantity: 1, item_variations: [{ id: 976, quantity: 1 }, { id: 974, quantity: 1 }, { id: 450, quantity: 1 }, { id: 453, quantity: 1 }], item_extras: [{ id: 49, quantity: 1 }, { id: 50, quantity: 1 }, { id: 51, quantity: 1 }], instruction: 'E2E-APP- r2 C-101 (doit être refusée)' }]) };
  const r = await api.fetch(A + '/frontend/order', { method: 'POST', headers: entetes({ Authorization: 'Bearer ' + L.jeton(), 'X-Idempotency-Key': 'e2e-app-r2-c101-' + Date.now() }), data: JSON.stringify(corps) });
  const j = await r.json();
  const apres = sql('select count(*), ifnull(max(id),0) from orders');
  J.note('API.commandeInvalide', { status: r.status(), message: j.message, erreurs: j.errors || null, avant, apres });
  expect(r.status()).toBe(422);
  expect(j.message, 'résumé de validation').toMatch(/\(et \d+ autres? erreurs?\)/);
  expect(j.message).not.toMatch(ANGLAIS);
  expect(JSON.stringify(j), 'aucun fragment anglais nulle part dans la réponse').not.toMatch(ANGLAIS);
  expect(apres, 'AUCUNE commande créée par une charge utile refusée').toBe(avant);

  // Pluriel : une validation à 3 erreurs ou plus (vérification d'inscription vide) -> « autres erreurs »
  const v = await api.fetch(A + '/auth/guest-signup/verify', { method: 'POST', headers: entetes(), data: JSON.stringify({}) });
  const vj = await v.json();
  J.note('API.verifVide', { status: v.status(), message: vj.message, nbErreurs: Object.keys(vj.errors || {}).length });
  expect(v.status()).toBe(422);
  if (Object.keys(vj.errors || {}).length > 1) expect(vj.message).toMatch(/\(et \d+ autres? erreurs?\)/);
  expect(JSON.stringify(vj)).not.toMatch(ANGLAIS);
  // Langue absente (Accept-Language non envoyé) : l'app n'envoie pas toujours l'en-tête -> la langue par défaut doit suffire
  const sans = await api.fetch(A + '/frontend/order', { method: 'POST', headers: (() => { const h = entetes({ Authorization: 'Bearer ' + L.jeton(), 'X-Idempotency-Key': 'e2e-app-r2-c101b-' + Date.now() }); delete h['Accept-Language']; return h; })(), data: JSON.stringify(corps) });
  const sj = await sans.json();
  J.note('API.sansAcceptLanguage', { status: sans.status(), message: sj.message });
  expect(sj.message).not.toMatch(ANGLAIS);
  expect(sql('select count(*), ifnull(max(id),0) from orders')).toBe(avant);
  await api.dispose();
});

test('C-R2-101-ECRAN le même 422 affiché dans l\'app (ligne A, :8011) : français, visible, aucune commande créée', async () => {
  test.skip(!L.jeton(), 'jeton E2E-APP- absent');
  const nav = await chromium.launch({ channel: 'chromium', args: ['--lang=fr-FR'] });
  try {
    const { ctx, page } = await L.contexte(nav, 'android', { width: 390, height: 844 });
    const avant = sql('select count(*), ifnull(max(id),0) from orders');
    let statut = null;
    page.on('response', (r) => { if (/\/api\/frontend\/order$/.test(r.url()) && r.request().method() === 'POST') statut = r.status(); });
    await page.goto(SITE_A + '/index.html', { waitUntil: 'load' });
    await page.evaluate((t) => localStorage.setItem('lecayenne.authToken', t), L.jeton());
    await page.reload({ waitUntil: 'load' }); await page.waitForTimeout(2500);
    await page.locator('button.lc-navx-item', { has: page.locator('.lc-navx-lbl', { hasText: /^Menu$/ }) }).first().click(); await page.waitForTimeout(1200);
    await page.locator('button.lc-card-item-name:not([disabled])').first().click(); await page.waitForTimeout(1000);
    const perso = page.locator('button', { hasText: /^\s*Personnaliser/ }).first();
    if (await perso.count()) { await perso.click(); await page.waitForTimeout(1000); }
    for (let i = 0; i < 20; i++) {
      const cta = page.locator('.lc-wiz-foot-next').first();
      if (!(await cta.count())) break;
      const txt = ((await cta.innerText()) || '').replace(/\s+/g, ' ');
      if ((await cta.getAttribute('aria-disabled')) === 'true') { const o = page.locator('button.lc-wiz-choice:not(.is-on):visible').first(); if (!(await o.count())) break; await o.click(); await page.waitForTimeout(350); continue; }
      await cta.click(); await page.waitForTimeout(800);
      if (/Ajouter au panier/.test(txt)) break;
    }
    for (let i = 0; i < 3; i++) { const b = page.getByRole('button', { name: /^\s*(Non merci(, continuer|, régler)?|Continuer sans|Pas maintenant)\b/ }).first(); if (!(await b.isVisible().catch(() => false))) break; await b.click(); await page.waitForTimeout(600); }
    const barre = page.locator('.lc-menu-cartbar');
    if (await barre.count()) await barre.click(); else await page.locator('.lc-nav-btn-cart').first().click();
    await page.waitForTimeout(900);
    await page.locator('button:visible', { hasText: /^\s*(Passer commande|Commander)/ }).first().click(); await page.waitForTimeout(1500);
    for (let i = 0; i < 3; i++) { const b = page.getByRole('button', { name: /^\s*(Non merci(, continuer|, régler)?|Continuer sans|Pas maintenant)\b/ }).first(); if (!(await b.isVisible().catch(() => false))) break; await b.click(); await page.waitForTimeout(600); }
    const note = page.locator('textarea').first();
    if (await note.count()) await note.fill('E2E-APP- r2 C-101 écran (doit être refusée)');
    const att = page.waitForResponse((r) => /\/api\/frontend\/order$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30_000 }).catch(() => null);
    await page.locator('button.lcf-cta-bar-next', { hasText: /Confirmer ma commande/ }).click();
    await att; await page.waitForTimeout(1500);
    const texte = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
    const apres = sql('select count(*), ifnull(max(id),0) from orders');
    const i = texte.search(/Sélectionnez|erreur/i);
    J.note('ECRAN.refus', { statut, extrait: i >= 0 ? texte.slice(Math.max(0, i - 20), i + 160) : texte.slice(0, 200), avant, apres });
    test.skip(statut === null, 'aucun POST émis (comportement ligne A variable, cf. rapport) : fermeture à l\'écran non exerçable cette fois');
    expect(statut).toBe(422);
    expect(texte, 'le refus est VISIBLE à l\'écran (pas silencieux)').toMatch(/Sélectionnez au maximum/);
    expect(texte).toMatch(/\(et \d+ autres? erreurs?\)/);
    expect(texte, 'aucun fragment anglais à l\'écran').not.toMatch(ANGLAIS);
    expect(apres).toBe(avant);
    await ctx.close();
  } finally { await nav.close(); }
});

test('C-R2-101-MORD le détecteur d\'anglais mord sur la chaîne EXACTE du tour 1 et se tait sur la chaîne corrigée', () => {
  const tour1 = 'Sélectionnez au maximum 1 Viande 1 (actuel : 2). (and 1 more error)'; // wave-C-capture.md C-006 / adversarial C-101
  const tour1pluriel = 'Le champ x est obligatoire. (and 3 more errors)';
  expect(tour1).toMatch(ANGLAIS);
  expect(tour1pluriel).toMatch(ANGLAIS);
  expect('Sélectionnez au maximum 1 Viande 1 (actuel : 2). (et 1 autre erreur)').not.toMatch(ANGLAIS);
  expect('Le champ x est obligatoire. (et 3 autres erreurs)').not.toMatch(ANGLAIS);
});
