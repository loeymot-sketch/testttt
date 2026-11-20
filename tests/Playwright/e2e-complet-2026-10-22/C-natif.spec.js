// C-natif — comportements NATIFS de l'application (vague C, e2e-complet-2026-10-22), avec le pont corrigé
// de ./C-lib.js (addListener muet + emettre() à la demande). Paramètres :
//   C_SITE (défaut http://127.0.0.1:8012 = paquet app/www construit depuis origin/main, api -> :8766)
//   C_TAG  (défaut natif-8012) : sous-dossier des preuves.
// Le même fichier rejoué avec C_SITE=http://127.0.0.1:8011 C_TAG=main-8011 mesure l'arbre de travail (pont mort).
// Préfixe des comptes/commandes : « E2E-APP- » (jeton du client E2E-APP-Client).
const { test, expect, chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const L = require('./C-lib');

const SITE = process.env.C_SITE || 'http://127.0.0.1:8012';
const TAG = process.env.C_TAG || 'natif-8012';
const DIR = path.join(L.SHOTS_ROOT, 'natif-' + TAG);
const J = L.journal(DIR, 'constats.json');
const PNG = /^(N3-|N4-)/;

function sql(requete) {
  const env = fs.readFileSync(path.resolve(__dirname, '../../../.env'), 'utf8');
  const mdp = ((env.match(/^DB_PASSWORD=(.*)$/m) || [])[1] || '').trim().replace(/^"|"$/g, '');
  return execFileSync('mysql', ['-uroot', '-h127.0.0.1', 'foodking_e2e', '-N', '-B', '-e', requete], { env: { ...process.env, MYSQL_PWD: mdp }, encoding: 'utf8' }).trim();
}

let NAV = null;
test.setTimeout(240_000);
test.beforeAll(async () => {
  NAV = await chromium.launch({ channel: 'chromium', args: ['--lang=fr-FR'] });
  J.note('instrument', { site: SITE, tag: TAG, bridgeSha256: L.sha(L.BRIDGE), bridgeIosSha256: L.sha(L.BRIDGE_IOS), date: new Date().toISOString() });
});
test.afterAll(async () => { if (NAV) await NAV.close(); });

const noms = (page) => page.evaluate(() => window.__pont.appels.map((a) => a.plugin + '.' + a.methode));
function onglet(page, libelle) {
  return page.locator('button.lc-navx-item', { has: page.locator('.lc-navx-lbl', { hasText: new RegExp('^' + libelle + '$') }) }).first();
}
async function ouvrir(page, connecte) {
  await page.goto(SITE + '/index.html', { waitUntil: 'load' });
  if (connecte) {
    await page.evaluate((t) => localStorage.setItem('lecayenne.authToken', t), L.jeton());
    await page.reload({ waitUntil: 'load' });
  }
  await page.waitForTimeout(2500);
}

for (const plateforme of ['android', 'ios']) {
  test(`C-NAT-1 [${plateforme}] démarrage : appels natifs partis, écouteurs posés, AUCUN App.exitApp, console propre`, async () => {
    const { ctx, page } = await L.contexte(NAV, plateforme, { width: 390, height: 844 });
    const erreurs = []; const consoles = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 200)));
    page.on('console', (m) => { if (m.type() === 'error') consoles.push(m.text().slice(0, 200)); });
    await ouvrir(page, false);
    const appels = await noms(page);
    const ecouteurs = await page.evaluate(() => window.__pont.ecouteurs.slice());
    const etat = await page.evaluate(() => ({
      natif: window.Capacitor.isNativePlatform(), plateforme: window.Capacitor.getPlatform(), registerPlugin: typeof window.Capacitor.registerPlugin,
      lcActif: !!(window.LC && window.LC.native && window.LC.native.actif), fournisseurs: window.LC.native ? window.LC.native.fournisseursDisponibles() : null,
      vp: (document.querySelector('meta[name=viewport]') || {}).content, carteEnLigne: !!(window.LC.api.config || {}).onlineCardEnabled,
    }));
    J.note(`NAT-1.${plateforme}`, { appels, ecouteurs, etat, pageerrors: erreurs, consoleErrors: consoles });
    expect(etat.registerPlugin, 'forme réelle : registerPlugin absent (vient de @capacitor/core)').toBe('undefined');
    expect(appels.length, 'appels natifs au démarrage (0 = pont mort)').toBeGreaterThan(3);
    expect(ecouteurs).toEqual(expect.arrayContaining(['Network:networkStatusChange', 'App:backButton']));
    expect(appels, 'le démarrage ne quitte pas l\'app').not.toContain('App.exitApp');
    expect(appels).toContain('SplashScreen.hide');
    expect(erreurs).toEqual([]);
    expect(etat.vp).toContain('viewport-fit=cover');
    expect(etat.carteEnLigne, 'paiement en ligne coupé dans l\'app (par conception)').toBe(false);
    await ctx.close();
  });
}

test('C-NAT-2 liens externes : ouverts par Browser.open, la page de l\'app n\'est jamais remplacée', async () => {
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  await ouvrir(page, false);
  const hrefs = await page.evaluate(() => Array.from(document.querySelectorAll('a[href^="http"]')).map((a) => a.href).filter((h) => new URL(h).origin !== location.origin));
  const distincts = [...new Set(hrefs.map((h) => new URL(h).host))];
  J.note('NAT-2.liensExternesSurAccueil', { total: hrefs.length, hotes: distincts });
  const resultats = [];
  for (const h of distincts.slice(0, 6)) {
    const lien = page.locator(`a[href*="${h}"]`).first();
    if (!(await lien.count())) continue;
    await lien.scrollIntoViewIfNeeded().catch(() => {});
    const avant = (await noms(page)).filter((n) => n === 'Browser.open').length;
    const [popup] = await Promise.all([ctx.waitForEvent('page', { timeout: 1200 }).catch(() => null), lien.click({ force: true, timeout: 5000 }).catch(() => null)]);
    await page.waitForTimeout(500);
    const apres = (await noms(page)).filter((n) => n === 'Browser.open').length;
    resultats.push({ hote: h, browserOpen: apres - avant, popup: !!popup, pageRemplacee: !page.url().startsWith(SITE) });
    if (popup) await popup.close();
  }
  J.note('NAT-2.resultats', resultats);
  expect(resultats.length).toBeGreaterThan(0);
  for (const r of resultats) { expect(r.browserOpen, `Browser.open pour ${r.hote}`).toBeGreaterThan(0); expect(r.pageRemplacee).toBe(false); }
  await ctx.close();
});

test('C-NAT-3 hors ligne : le bandeau apparaît à la coupure, ne bloque rien, disparaît au retour', async () => {
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  const rec = L.enregistreur(page, DIR, PNG);
  await ouvrir(page, false);
  expect(await page.evaluate(() => window.__pont.emettre('Network', 'networkStatusChange', { connected: false, connectionType: 'none' }))).toBe(true);
  await page.waitForTimeout(800);
  const bandeau = page.locator('#lc-hors-ligne');
  const visible = await bandeau.isVisible().catch(() => false);
  const info = visible ? await bandeau.evaluate((el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return { x: r.x, y: r.y, w: r.width, h: r.height, pe: s.pointerEvents, pos: s.position, texte: el.innerText.trim(), role: el.getAttribute('role'), live: el.getAttribute('aria-live') }; }) : null;
  J.note('NAT-3.bandeau', { visible, info, viewport: { w: 390, h: 844 } });
  await rec.snap('N3-hors-ligne-390');
  expect(visible, 'bandeau « pas de connexion » visible').toBe(true);
  expect(info.texte.length).toBeGreaterThan(5);
  expect(info.pe, 'ne doit jamais bloquer les appuis').toBe('none');
  // La navigation reste utilisable pendant la coupure.
  await onglet(page, 'Menu').click({ timeout: 5000 });
  await page.waitForTimeout(600);
  expect(await page.locator('.lc-card-item-name').count()).toBeGreaterThan(0);
  await page.evaluate(() => window.__pont.emettre('Network', 'networkStatusChange', { connected: true, connectionType: 'wifi' }));
  await page.waitForTimeout(800);
  expect(await bandeau.isVisible().catch(() => false), 'bandeau retiré au retour du réseau').toBe(false);
  rec.dispose(); await ctx.close();
});

test('C-NAT-4 bouton retour matériel avec le tiroir panier ouvert : le tiroir se referme, l\'app ne quitte pas', async () => {
  // [HEAL instrument] la 1re version émettait canGoBack:false ; dans la vraie WebView l'accueil a 2 entrées d'historique
  // (history.length = 2, state {route:'home'}) donc canGoBack = true. Vérité terrain : émulateur Android 36
  // (device-captures/panier-ouvert.png -> apres-retour-panier.png : tiroir fermé, MainActivity toujours au premier plan).
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  const rec = L.enregistreur(page, DIR, PNG);
  await ouvrir(page, false);
  const hist = await page.evaluate(() => ({ len: history.length, state: history.state }));
  await page.locator('.lc-nav-btn-cart').first().click();
  await page.waitForTimeout(900);
  const sel = '.lc-cart-drawer.is-open';
  const ouvertAvant = await page.locator(sel).count();
  await rec.snap('N4-panier-ouvert-avant-retour');
  await page.evaluate((cg) => window.__pont.emettre('App', 'backButton', { canGoBack: cg }), hist.len > 1);
  await page.waitForTimeout(900);
  const ouvertApres = await page.locator(sel).count();
  const exit = (await noms(page)).filter((n) => n === 'App.exitApp').length;
  J.note('NAT-4', { historique: hist, tiroirOuvertAvant: ouvertAvant, tiroirOuvertApres: ouvertApres, exitApp: exit });
  expect(ouvertAvant).toBe(1);
  expect(ouvertApres, 'le tiroir est refermé par le retour').toBe(0);
  expect(exit, 'le retour avec tiroir ouvert ne quitte PAS l\'app').toBe(0);
  rec.dispose(); await ctx.close();
});

for (const plateforme of ['android', 'ios']) {
  test(`C-NAT-5 [${plateforme}] commande passée DEPUIS la coque : arrive en base avec le bon total ; rappel de retrait natif`, async () => {
    test.skip(!L.jeton(), 'jeton E2E-APP- absent');
    const { ctx, page } = await L.contexte(NAV, plateforme, { width: 390, height: 844 });
    const rec = L.enregistreur(page, DIR, false);
    const requeteOrdre = []; let ordre = null; let corpsRep = null;
    page.on('request', (r) => { if (/\/api\/frontend\/order$/.test(r.url()) && r.method() === 'POST') requeteOrdre.push({ ua: r.headers()['user-agent'], origin: r.headers()['origin'] || null, post: (r.postData() || '').slice(0, 600) }); });
    await ouvrir(page, true);
    await onglet(page, 'Menu').click(); await page.waitForTimeout(1200);
    await page.locator('button.lc-card-item-name:not([disabled])').first().click(); await page.waitForTimeout(1000);
    const perso = page.locator('button', { hasText: /^\s*Personnaliser/ }).first();
    if (await perso.count()) { await perso.click(); await page.waitForTimeout(1000); }
    const etapes = [];
    for (let i = 0; i < 20; i++) {
      const cta = page.locator('.lc-wiz-foot-next').first();
      if (!(await cta.count())) break;
      const txt = ((await cta.innerText()) || '').replace(/\s+/g, ' ').trim();
      if ((await cta.getAttribute('aria-disabled')) === 'true') {
        const o = page.locator('button.lc-wiz-choice:not(.is-on):visible').first();
        if (!(await o.count())) { etapes.push('bloqué'); break; }
        etapes.push('choix:' + ((await o.innerText()) || '').replace(/\s+/g, ' ').slice(0, 30)); await o.click(); await page.waitForTimeout(350); continue;
      }
      etapes.push(txt.slice(0, 40)); await cta.click(); await page.waitForTimeout(800);
      if (/Ajouter au panier/.test(txt)) break;
    }
    J.note(`NAT-5.${plateforme}.etapes`, etapes);
    for (let i = 0; i < 3; i++) { // upsell éventuel
      const b = page.getByRole('button', { name: /^\s*(Non merci(, continuer|, régler)?|Continuer sans|Pas maintenant)\b/ }).first();
      if (!(await b.isVisible().catch(() => false))) break; await b.click(); await page.waitForTimeout(600);
    }
    const barre = page.locator('.lc-menu-cartbar');
    if (await barre.count()) await barre.click(); else await page.locator('.lc-nav-btn-cart').click();
    await page.waitForTimeout(900);
    const totalAffiche = await page.evaluate(() => (Array.from(document.querySelectorAll('[class*="total"]')).map((e) => e.textContent.trim()).filter((s) => /€/.test(s)).pop() || ''));
    await page.locator('button:visible', { hasText: /^\s*(Passer commande|Commander)/ }).first().click(); await page.waitForTimeout(1500);
    for (let i = 0; i < 3; i++) {
      const b = page.getByRole('button', { name: /^\s*(Non merci(, continuer|, régler)?|Continuer sans|Pas maintenant)\b/ }).first();
      if (!(await b.isVisible().catch(() => false))) break; await b.click(); await page.waitForTimeout(600);
    }
    const note = page.locator('textarea').first();
    if (await note.count()) await note.fill(`E2E-APP- commande depuis la coque ${plateforme} (${TAG})`);
    const attente = page.waitForResponse((r) => /\/api\/frontend\/order$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30_000 }).catch(() => null);
    await page.locator('button.lcf-cta-bar-next', { hasText: /Confirmer ma commande/ }).click();
    const rep = await attente;
    try { corpsRep = rep ? await rep.json() : null; } catch (_e) { corpsRep = null; }
    ordre = { status: rep ? rep.status() : null, id: corpsRep && corpsRep.data ? corpsRep.data.id : null, total: corpsRep && corpsRep.data ? corpsRep.data.total : null, message: corpsRep && corpsRep.message };
    await page.waitForTimeout(2500);
    // AVANT toute navigation : le journal du pont est remis à zéro à chaque chargement (défaut de l'ancien banc).
    const appels = await noms(page);
    const rappel = await page.evaluate(() => window.__pont.appels.filter((a) => a.plugin === 'LocalNotifications' && a.methode === 'schedule').map((a) => a.options));
    let enBase = null;
    if (ordre.id) enBase = sql(`select total, source, order_type, user_id from orders where id=${ordre.id}`);
    J.note(`NAT-5.${plateforme}`, { totalAffichePanier: totalAffiche, requete: requeteOrdre, ordre, enBase, appelsNatifsApresCommande: appels.filter((a) => a.startsWith('LocalNotifications') || a.startsWith('Haptics')), rappel });
    await rec.snap('N5-confirmation-' + plateforme);
    expect(ordre.status, 'POST /api/frontend/order depuis la coque ' + plateforme + ' : ' + JSON.stringify(ordre)).toBe(201);
    expect(requeteOrdre[0].origin || SITE).toBeTruthy();
    expect(Number(enBase.split('\t')[0]), 'total en base = total renvoyé').toBeCloseTo(Number(ordre.total), 2);
    expect(totalAffiche.replace(/\s| /g, '')).toContain(String(ordre.total).replace('.', ',').replace(/,0$/, ',00').replace(/^(\d+),(\d)$/, '$1,$20'));
    expect(appels).toContain('LocalNotifications.requestPermissions');
    expect(rappel.length, 'un rappel local programmé').toBe(1);
    expect(JSON.stringify(rappel[0])).not.toMatch(/\d+\s*min/); // règle propriétaire : jamais de durée précise
    expect(rappel[0].notifications[0].isExactNotification).toBe(false); // pas de SCHEDULE_EXACT_ALARM
    rec.dispose(); await ctx.close();
  });
}
