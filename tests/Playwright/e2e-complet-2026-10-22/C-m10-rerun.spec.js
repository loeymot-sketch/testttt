// C-m10-rerun — REJEU du banc M10 (verif-globale-2026-10-21) pour la vague C de e2e-complet-2026-10-22.
// Copie de tests/Playwright/verif-globale-2026-10-21/M10-app-capacitor.spec.js, SEULS changements :
//   · SHOTS -> tests/e2e/__screenshots__/e2e-complet-C/rerun-<C_TAG> (le banc d'origine n'est pas écrasé)
//   · TOKEN_FILE -> jeton du client « E2E-APP-Client » (préfixe E2E-APP-), note de commande « E2E-APP- »
//   · SITE/C_TAG paramétrables : C_TAG=main-8011 (arbre de travail : pont mort) ou C_TAG=natif-8012 (origin/main construit)
//   · le recorder ne PRODUIT un PNG que pour les états listés dans C_SNAP_ONLY (regex) — budget disque ;
//     les .dom/.console/.network sont écrits dans tous les cas (preuves).
// Le reste (pont, faux androidBridge, modes A/B, expect.soft) est INCHANGÉ : c'est l'instrument d'origine.
// [en-tête d'origine ci-dessous]
// M10 — Application réelle des stores (Capacitor 8) : ce que le site fait QUAND IL TOURNE DANS LA COQUE.
// Tour 1 de verif-globale-2026-10-21 : constater, ne rien corriger.
//
// INSTRUMENT (lire avant de croire un résultat)
// ---------------------------------------------
// La coque Android injecte, avant tout script de la page, trois choses (JSExport.java de
// @capacitor/android) :
//   1. `window.Capacitor = { DEBUG, isLoggingEnabled, Plugins: {} }`        (getGlobalJS)
//   2. le fichier `native-bridge.js` TEL QUEL                                (getBridgeJS)
//   3. `window.Capacitor.PluginHeaders = [...]`                               (getPluginJS)
// et expose `window.androidBridge.postMessage` ; les réponses reviennent par
// `window.androidBridge.onmessage({ data: JSON })`. Ici on fait EXACTEMENT cela : le vrai
// `native-bridge.js` du dépôt de l'app est injecté, et `androidBridge` est un faux natif qui
// journalise chaque appel (`window.__pont.appels`) et répond `success:true` avec un résultat
// plausible. Aucun `registerPlugin` n'est ajouté : il vient de `@capacitor/core`, que le site
// ne charge pas (mémoire du projet : six semaines de pont mort sous des bancs verts qui le
// simulaient). Le mode « B » ajoute ce `registerPlugin` tel que `@capacitor/core` le ferait,
// pour montrer ce que le code FERAIT si le pont était réparé — il est étiqueté comme tel.
//
// Serveurs attendus : site :8011 (copie locale pointée sur :8766), backend :8766.

const fs = require('fs');
const path = require('path');
const { test, expect, chromium } = require('@playwright/test');
const { attachMegaAuditRecorder: _attach } = require('../../e2e/helpers/mega-audit-snap');
const SNAP_ONLY = process.env.C_SNAP_ONLY ? new RegExp(process.env.C_SNAP_ONLY) : /.*/;
function attachMegaAuditRecorder(page, dir) {
  const rec = _attach(page, dir);
  const snapOrigine = rec.snap.bind(rec);
  rec.snap = async (nom) => {
    if (SNAP_ONLY.test(nom)) return snapOrigine(nom);
    // état non retenu en PNG : on garde dom/console/network via un snap puis on supprime le PNG
    const r = await snapOrigine(nom);
    try { fs.unlinkSync(path.join(dir, nom + '.png')); } catch (_e) { /* déjà absent */ }
    return r;
  };
  return rec;
}

const SITE = process.env.M10_SITE_URL || 'http://127.0.0.1:8011';
const API = process.env.M10_API_URL || 'http://127.0.0.1:8766';
const SITE_REPO = process.env.M10_SITE_REPO
  || '/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne';
const BRIDGE = path.join(SITE_REPO, 'app/node_modules/@capacitor/android/capacitor/src/main/assets/native-bridge.js');
const C_TAG = process.env.C_TAG || 'main-8011';
const SHOTS = path.resolve(__dirname, '../../e2e/__screenshots__/e2e-complet-C/rerun-' + C_TAG);
const TOKEN_FILE = process.env.M10_TOKEN_FILE
  || '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/15fca3a9-ef91-4f69-8ff0-680ff8c89dfd/scratchpad/c-token.txt';
const CONSTATS = path.join(SHOTS, 'constats.json');

// Chrome Android WebView (c'est ce que Capacitor utilise ; aucun appendUserAgent dans capacitor.config.json)
const UA_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240805.005; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.6723.86 Mobile Safari/537.36';
const UA_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';

// Constats écrits À CHAQUE note : Playwright relance un worker après un test en échec, et un
// expect.soft en échec est ici un CONSTAT attendu — sans écriture immédiate, tout serait perdu.
function lireConstats() { try { return JSON.parse(fs.readFileSync(CONSTATS, 'utf8')); } catch (_e) { return {}; } }
function note(cle, valeur) {
  fs.mkdirSync(SHOTS, { recursive: true });
  const c = lireConstats(); c[cle] = valeur;
  fs.writeFileSync(CONSTATS, JSON.stringify(c, null, 2));
}
function flush() { /* conservé pour l'afterAll : tout est déjà sur disque */ }

// [INSTRUMENT] La copie locale du site (:8011) porte la clé API de PRODUCTION dans
// <meta name="api-key"> (constaté : 400 « Clé API invalide » sur chaque appel). On ne modifie
// pas la copie partagée (d'autres missions la lisent) : on réécrit la meta À LA VOLÉE, avec
// MIX_API_KEY du .env du backend — c'est ce que vaut la meta sur une app correctement déployée.
function cleApiLocale() {
  if (process.env.M10_API_KEY) return process.env.M10_API_KEY;
  try {
    const env = fs.readFileSync(path.resolve(__dirname, '../../../.env'), 'utf8');
    const m = env.match(/^MIX_API_KEY=(.*)$/m);
    return m ? m[1].trim().replace(/^"|"$/g, '') : '';
  } catch (_e) { return ''; }
}
async function injecterCleApi(page) {
  const cle = cleApiLocale();
  note('instrument.cleApiInjectee', cle ? 'oui (MIX_API_KEY du backend, longueur ' + cle.length + ')' : 'NON — .env illisible');
  if (!cle) return;
  // [HEAL instrument] Première version : `route.fulfill` du DOCUMENT avec la meta réécrite. Faux :
  // un document « fabriqué » par Playwright n'a pas d'adresse IP → Chrome (Local Network Access)
  // le classe « public » et BLOQUE ses fetch vers 127.0.0.1:8766 (« Permission was denied for this
  // request to access the `loopback` address space »). On réécrit donc l'EN-TÊTE X-API-Key sur les
  // seules requêtes API ; le document reste servi tel quel par :8011.
  await page.route((u) => u.origin === API && u.pathname.startsWith('/api/'), async (route) => {
    const req = route.request();
    if (req.method() === 'OPTIONS') return route.continue();
    const h = { ...req.headers() };
    for (const k of Object.keys(h)) if (k.toLowerCase() === 'x-api-key') delete h[k];
    h['x-api-key'] = cle;
    return route.continue({ headers: h });
  });
}

/**
 * Script d'initialisation = la coque. `plateforme` : 'android' (androidBridge) ou 'ios'
 * (webkit.messageHandlers.bridge). `avecRegisterPlugin` : mode B (ce que @capacitor/core ajoute).
 */
function scriptCoque(plateforme, avecRegisterPlugin) {
  const bridge = fs.readFileSync(BRIDGE, 'utf8');
  const transport = plateforme === 'android'
    ? `window.androidBridge = { postMessage: function (json) { window.__pont.recevoir(JSON.parse(json), function (rep) { window.androidBridge.onmessage({ data: JSON.stringify(rep) }); }); } };`
    : `window.webkit = { messageHandlers: { bridge: { postMessage: function (data) { window.__pont.recevoir(data, function (rep) { window.Capacitor.fromNative(rep); }); } } } };`;
  return `
    window.__pont = { appels: [], ecouteurs: [], plateforme: '${plateforme}' };
    window.__pont.fauxResultat = function (d) {
      var p = d.pluginId, m = d.methodName;
      if (p === 'SocialLogin' && m === 'login') return { provider: 'apple', result: { idToken: 'eyJhbGciOiJSUzI1NiJ9.eyJmYWtlIjoidHJ1ZSIsInN1YiI6InZnLW0xMCJ9.c2lnbmF0dXJlLWZhY3RpY2UtdmctbTEw', profile: { email: 'vg-m10@example.invalid', givenName: 'VG', familyName: 'M10' } } };
      if (p === 'Network' && m === 'getStatus') return { connected: true, connectionType: 'wifi' };
      if (p === 'LocalNotifications' && m === 'requestPermissions') return { display: 'granted' };
      if (p === 'App' && m === 'getLaunchUrl') return { url: null };
      return {};
    };
    window.__pont.recevoir = function (d, repondre) {
      window.__pont.appels.push({ plugin: d.pluginId, methode: d.methodName, options: d.options || {} });
      if (d.methodName === 'addListener') window.__pont.ecouteurs.push(d.pluginId + ':' + ((d.options || {}).eventName || '?'));
      var rep = { callbackId: d.callbackId, pluginId: d.pluginId, methodName: d.methodName, success: true,
        data: window.__pont.fauxResultat(d), save: d.methodName === 'addListener' };
      setTimeout(function () { try { repondre(rep); } catch (e) { console.error('[pont] réponse impossible', e); } }, 0);
    };
    window.WEBVIEW_SERVER_URL = 'https://localhost';
    ${transport}
    window.Capacitor = { DEBUG: false, isLoggingEnabled: false, Plugins: {} };
    ${bridge}
    window.Capacitor.PluginHeaders = [
      { name: 'App', methods: [{ name: 'exitApp', rtype: 'promise' }, { name: 'getLaunchUrl', rtype: 'promise' }, { name: 'addListener' }, { name: 'removeListener' }] },
      { name: 'Browser', methods: [{ name: 'open', rtype: 'promise' }] },
      { name: 'Haptics', methods: [{ name: 'impact', rtype: 'promise' }] },
      { name: 'Keyboard', methods: [{ name: 'setAccessoryBarVisible', rtype: 'promise' }] },
      { name: 'LocalNotifications', methods: [{ name: 'requestPermissions', rtype: 'promise' }, { name: 'schedule', rtype: 'promise' }] },
      { name: 'Network', methods: [{ name: 'getStatus', rtype: 'promise' }, { name: 'addListener' }] },
      { name: 'Share', methods: [{ name: 'share', rtype: 'promise' }] },
      { name: 'SplashScreen', methods: [{ name: 'hide', rtype: 'promise' }] },
      { name: 'StatusBar', methods: [{ name: 'setStyle', rtype: 'promise' }, { name: 'setBackgroundColor', rtype: 'promise' }, { name: 'setOverlaysWebView', rtype: 'promise' }] },
      { name: 'SocialLogin', methods: [{ name: 'initialize', rtype: 'promise' }, { name: 'login', rtype: 'promise' }, { name: 'logout', rtype: 'promise' }] }
    ];
    ${avecRegisterPlugin ? `
    // MODE B — ce que @capacitor/core ferait sur plateforme native : createPluginMethod →
    // cap.nativePromise ; addListener → cap.addListener (forme de core/src/runtime.ts).
    window.Capacitor.registerPlugin = function (nom) {
      return new Proxy({}, { get: function (_, methode) {
        if (methode === 'then') return undefined;
        if (methode === 'addListener') return function (ev, cb) { return Promise.resolve(window.Capacitor.addListener(nom, ev, cb)); };
        return function (opts) { return window.Capacitor.nativePromise(nom, methode, opts || {}); };
      } });
    };` : ''}
  `;
}

async function contexte(browser, plateforme, avecRegisterPlugin) {
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
    locale: 'fr-FR', userAgent: plateforme === 'android' ? UA_ANDROID : UA_IOS,
  });
  await ctx.addInitScript(scriptCoque(plateforme, avecRegisterPlugin));
  const page = await ctx.newPage();
  await injecterCleApi(page);
  return { ctx, page };
}


/** Upsell facultatif (boissons → desserts) : on le passe s'il est RÉELLEMENT visible, sans attendre. */
async function passerUpsell(page) {
  const passes = [];
  for (let i = 0; i < 4; i++) {
    const b = page.getByRole('button', { name: /^\s*(Non merci(, continuer)?|Continuer sans|Pas maintenant|Passer cette étape)\b/ }).first();
    if (!(await b.isVisible().catch(() => false))) break;
    passes.push(((await b.innerText()) || '').trim().slice(0, 40));
    await b.click({ timeout: 5_000 });
    await page.waitForTimeout(700);
  }
  return passes;
}

/** Onglet de la bande de navigation visible sur mobile (`.lc-nav-links` est masqué < desktop). */
function onglet(page, libelle) {
  return page.locator('button.lc-navx-item', { has: page.locator('.lc-navx-lbl', { hasText: new RegExp('^' + libelle + '$') }) }).first();
}

async function etatNatif(page) {
  return page.evaluate(() => ({
    isNativePlatform: !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()),
    getPlatform: window.Capacitor && window.Capacitor.getPlatform ? window.Capacitor.getPlatform() : null,
    typeofRegisterPlugin: typeof (window.Capacitor && window.Capacitor.registerPlugin),
    typeofNativePromise: typeof (window.Capacitor && window.Capacitor.nativePromise),
    isPluginAvailableApp: !!(window.Capacitor && window.Capacitor.isPluginAvailable && window.Capacitor.isPluginAvailable('App')),
    pluginHeaders: (window.Capacitor && window.Capacitor.PluginHeaders || []).length,
    lcNativeActif: !!(window.LC && window.LC.native && window.LC.native.actif),
    lcPlateforme: window.LC && window.LC.native ? window.LC.native.plateforme : null,
    fournisseurs: window.LC && window.LC.native ? window.LC.native.fournisseursDisponibles() : null,
    onlineCardEnabled: !!(window.LC && window.LC.api && window.LC.api.config && window.LC.api.config.onlineCardEnabled),
    apiBase: window.LC && window.LC.api && window.LC.api.config ? window.LC.api.config.base : null,
    serviceWorkerController: !!(navigator.serviceWorker && navigator.serviceWorker.controller),
    viewportFitCover: /viewport-fit=cover/.test((document.querySelector('meta[name=viewport]') || {}).content || ''),
    appels: window.__pont.appels.map((a) => a.plugin + '.' + a.methode),
    ecouteurs: window.__pont.ecouteurs.slice(),
    bandeauConfig: !!document.getElementById('lc-apibase-warning'),
    bandeauHorsLigne: !!document.getElementById('lc-hors-ligne'),
  }));
}

// Pas de mode « serial » : chaque test a son propre contexte ; un constat (expect.soft en échec)
// dans l'un ne doit pas empêcher les autres de s'exercer.
test.setTimeout(240_000);
// [ENV 2026-10-21 15:46] `chromium_headless_shell-1234` a été supprimé du cache ms-playwright pendant
// la campagne (autre session, disque plein) ; `chromium-1234` complet est toujours là : on l'utilise.
// [HEAL instrument 2026-10-21] Avec la fixture `browser` du lanceur, TOUS les fetch de :8011 vers
// :8766 étaient refusés (« Permission was denied for this request to access the `loopback` address
// space », Chromium 151, Local Network Access) ; la même page, le même pont et les mêmes options dans
// un `chromium.launch` direct passent (sondes <scratchpad>/lna-probe*.js : 400 « clé » = requête partie).
// La spec lance donc son propre navigateur. Un seul navigateur, ≤ 2 contextes à la fois.
let NAV = null;
test.beforeAll(async () => { NAV = await chromium.launch({ channel: 'chromium', args: ['--lang=fr-FR'] }); });
test.afterAll(async () => { if (NAV) await NAV.close(); });

test.beforeAll(() => {
  fs.mkdirSync(SHOTS, { recursive: true });
  if (!fs.existsSync(BRIDGE)) throw new Error('native-bridge.js introuvable : ' + BRIDGE + ' — l\'instrument ne peut pas être honnête sans le vrai pont.');
  note('instrument', {
    bridge: BRIDGE,
    bridgeSha256: require('crypto').createHash('sha256').update(fs.readFileSync(BRIDGE)).digest('hex'),
    site: SITE, api: API, viewport: '390x844@2', date: new Date().toISOString(),
  });
});
test.afterAll(() => flush());

// ---------------------------------------------------------------------------------------------
test('M10-A1 — pont natif RÉEL (Android) : détection, appels partis vers la coque, liens externes', async () => {
  const { ctx, page } = await contexte(NAV, 'android', false);
  const rec = attachMegaAuditRecorder(page, SHOTS);
  const erreurs = [];
  page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 200)));

  await page.goto(SITE + '/index.html', { waitUntil: 'load' });
  await page.waitForTimeout(3000);
  const etat = await etatNatif(page);
  note('A1.etat', etat);
  note('A1.pageerrors', erreurs);
  await rec.snap('A1-accueil-android-pont-reel');

  // Instrument : le pont est bien là, de la forme de la coque.
  expect(etat.isNativePlatform, 'Capacitor.isNativePlatform()').toBe(true);
  expect(etat.getPlatform).toBe('android');
  expect(etat.typeofNativePromise).toBe('function');
  expect(etat.typeofRegisterPlugin, 'registerPlugin n\'existe PAS sans @capacitor/core (forme réelle)').toBe('undefined');
  expect(etat.lcNativeActif, 'app-native.js se croit natif').toBe(true);

  // Produit : ce que l'app fait réellement au démarrage.
  expect.soft(etat.appels.length, 'appels natifs partis au démarrage (StatusBar, Keyboard, SplashScreen.hide, Network.getStatus, App.addListener…) — 0 = pont mort').toBeGreaterThan(0);
  expect.soft(etat.ecouteurs, 'écouteur Network.networkStatusChange enregistré côté natif').toContain('Network:networkStatusChange');
  expect.soft(etat.ecouteurs, 'écouteur App.backButton enregistré côté natif').toContain('App:backButton');
  expect.soft(etat.onlineCardEnabled, 'paiement en ligne COUPÉ dans l\'app (api.js:66)').toBe(false);
  expect.soft(etat.serviceWorkerController, 'pas de service worker dans l\'app').toBe(false);
  expect.soft(etat.viewportFitCover).toBe(true);
  expect.soft(etat.fournisseurs, 'Android sans Services ID Apple ni client Google → aucun bouton social').toEqual([]);
  expect.soft(erreurs, 'aucune erreur JS au démarrage').toEqual([]);

  // Lien externe (pied de page) : dans l'app il DOIT s'ouvrir via Browser.open (Apple refuse qu'un site tiers remplace l'app).
  const lien = page.locator('a[href^="http"]:not([href*="127.0.0.1"]):not([href*="localhost"])').first();
  let lienHref = null;
  if (await lien.count()) {
    lienHref = await lien.getAttribute('href');
    await lien.scrollIntoViewIfNeeded();
    const avant = etat.appels.length;
    const [popup] = await Promise.all([
      ctx.waitForEvent('page', { timeout: 2500 }).catch(() => null),
      lien.click({ force: true }),
    ]);
    await page.waitForTimeout(800);
    const apres = await page.evaluate(() => window.__pont.appels.map((a) => a.plugin + '.' + a.methode));
    const browserOpen = apres.slice(avant).filter((a) => a === 'Browser.open');
    note('A1.lienExterne', { href: lienHref, popupOuverte: !!popup, browserOpen: browserOpen.length, urlPage: page.url() });
    if (popup) await popup.close();
    expect.soft(browserOpen.length, 'clic sur ' + lienHref + ' → Browser.open attendu ; 0 = clic annulé par l\'intercepteur puis RIEN (lien mort)').toBeGreaterThan(0);
    expect.soft(page.url().startsWith(SITE), 'la page de l\'app n\'a pas été remplacée par le site tiers').toBe(true);
  } else {
    note('A1.lienExterne', 'NON EXERCÉ : aucun lien externe trouvé sur l\'accueil');
  }
  await rec.snap('A1b-apres-clic-lien-externe');
  rec.dispose();
  await ctx.close();
});

// ---------------------------------------------------------------------------------------------
test('M10-A2 — 6 écrans clés à 390×844 dans la coque (accueil, carte, wizard, panier, compte, suivi) + commande VG-M10', async () => {
  test.skip(!fs.existsSync(TOKEN_FILE), 'jeton client local absent (' + TOKEN_FILE + ')');
  const token = fs.readFileSync(TOKEN_FILE, 'utf8').trim();
  const { ctx, page } = await contexte(NAV, 'android', false);
  const rec = attachMegaAuditRecorder(page, SHOTS);
  const lents = [];
  const requetesRacine = [];
  page.on('response', async (r) => {
    try {
      const t = r.request().timing();
      const ms = t && t.responseEnd > 0 ? Math.round(t.responseEnd) : null;
      if (ms && ms > 1000) lents.push({ url: r.url(), status: r.status(), ms });
    } catch (_e) { /* timing indisponible */ }
  });
  page.on('request', (r) => { if (r.url() === SITE + '/' || r.url() === SITE + '/index.html') requetesRacine.push(r.url()); });

  note('A2.suivi', null); note('A2.commande', null);
  await page.goto(SITE + '/index.html', { waitUntil: 'load' });
  await page.evaluate((t) => { localStorage.setItem('lecayenne.authToken', t); }, token);
  await page.reload({ waitUntil: 'load' });
  await page.waitForTimeout(2500);
  await rec.snap('A2-1-accueil');

  // Carte
  await onglet(page, 'Menu').click();
  await page.waitForTimeout(1500);
  await expect(page.locator('.lc-card-item-name').first()).toBeVisible({ timeout: 15_000 });
  await rec.snap('A2-2-carte');

  // Wizard : premier produit disponible
  const produit = page.locator('button.lc-card-item-name:not([disabled])').first();
  const nomProduit = (await produit.textContent() || '').trim();
  note('A2.produit', nomProduit);
  await produit.click();
  await page.waitForTimeout(1200);
  await rec.snap('A2-3-wizard');
  // Fiche produit → « Personnaliser » → wizard (`.lc-wiz-foot-next`, désactivé par aria-disabled).
  const perso = page.locator('button', { hasText: /^\s*Personnaliser/ }).first();
  if (await perso.count()) { await perso.click(); await page.waitForTimeout(1200); }
  let ajoute = false;
  const etapes = [];
  let capturesEtape = 0;
  for (let i = 0; i < 16 && !ajoute; i++) {
    const cta = page.locator('.lc-wiz-foot-next').first();
    if (!(await cta.count())) break;
    const txt = (await cta.innerText() || '').replace(/\s+/g, ' ').trim();
    if ((await cta.getAttribute('aria-disabled')) === 'true') {
      // étape obligatoire : première option non choisie, VISIBLE
      const opt = page.locator('button.lc-wiz-choice:not(.is-on):visible').first();
      if (!(await opt.count())) { etapes.push('bloqué : CTA désactivé sans option'); break; }
      etapes.push('choix : ' + ((await opt.innerText()) || '').replace(/\s+/g, ' ').slice(0, 40));
      await opt.click();
      await page.waitForTimeout(350);
      continue;
    }
    if (capturesEtape === 0) { await rec.snap('A2-3b-wizard-etape'); capturesEtape++; }
    if (/Ajouter au panier/.test(txt)) await rec.snap('A2-3c-wizard-recap');
    etapes.push('suivant : ' + txt.slice(0, 50));
    await cta.click();
    await page.waitForTimeout(900);
    if (/Ajouter au panier/.test(txt)) ajoute = true;
  }
  note('A2.wizardEtapes', etapes);
  note('A2.ajoutPanier', ajoute);
  expect.soft(ajoute, 'le wizard aboutit à « Ajouter au panier »').toBe(true);
  await page.waitForTimeout(800);
  // Upsell éventuel : passer
  await passerUpsell(page);

  // Panier
  const barre = page.locator('.lc-menu-cartbar');
  if (await barre.count()) await barre.click(); else await page.locator('.lc-nav-btn-cart').click();
  await page.waitForTimeout(1000);
  await rec.snap('A2-4-panier');
  const totalPanier = await page.evaluate(() => {
    const t = Array.from(document.querySelectorAll('[class*="total"]')).map((e) => e.textContent.trim()).filter((s) => /€/.test(s));
    return t.slice(-3);
  });
  note('A2.panierTotalTexte', totalPanier);

  // Commander → page retrait & paiement (comptoir) → confirmer → suivi
  const commander = page.locator('button:visible', { hasText: /^\s*(Passer commande|Commander)/ }).first();
  expect.soft(await commander.count(), 'bouton Commander dans le tiroir panier').toBeGreaterThan(0);
  const racineAvant = requetesRacine.length;
  if (await commander.count()) {
    await commander.click();
    await page.waitForTimeout(1500);
    note('A2.upsellPasse', await passerUpsell(page));
    note('A2.gardeOngletPerime.fetchRacineApresCommander', requetesRacine.length - racineAvant);
    await rec.snap('A2-5-retrait-paiement');
    const note_ = page.locator('textarea').first();
    if (await note_.count()) await note_.fill('E2E-APP- test app Capacitor (' + C_TAG + ')');
    const confirmer = page.locator('button.lcf-cta-bar-next', { hasText: /Confirmer ma commande/ });
    note('A2.confirmerVisible', await confirmer.count());
    if (await confirmer.count()) {
      const attenteOrdre = page.waitForResponse((r) => /\/api\/frontend\/order$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30_000 }).catch(() => null);
      await expect(confirmer).toBeEnabled({ timeout: 10_000 });
      await confirmer.click();
      const rep = await attenteOrdre;
      let corps = null;
      if (rep) { try { corps = await rep.json(); } catch (_e) { corps = await rep.text(); } }
      note('A2.commande', { status: rep ? rep.status() : null, corps: corps && typeof corps === 'object' ? { status: corps.status, id: corps.data && corps.data.id, order_id: corps.data && corps.data.order_id, total: corps.data && corps.data.total, message: corps.message } : corps });
      expect.soft(rep && rep.status(), 'POST /api/frontend/order depuis la coque').toBe(201);
      await page.waitForTimeout(2000);
      await rec.snap('A2-6-confirmation');
      const suivre = page.locator('button', { hasText: /Suivre ma commande/ }).first();
      if (await suivre.count()) {
        await suivre.click();
        await page.waitForTimeout(2500);
        await rec.snap('A2-7-suivi');
      } else {
        note('A2.suivi', 'NON EXERCÉ : bouton « Suivre ma commande » absent après confirmation');
      }
    }
  }

  // Compte (connecté → onglet profil de la fidélité). Une modale (ticket, suivi) peut couvrir l'en-tête.
  // Retour à une page neutre (une modale ticket/suivi peut couvrir l'en-tête) : rechargement, la session vit en localStorage.
  await page.goto(SITE + '/index.html', { waitUntil: 'load' });
  await page.waitForTimeout(2000);
  await page.locator('.lc-nav-btn-account').first().click({ timeout: 20_000 });
  await page.waitForTimeout(1500);
  await rec.snap('A2-8-compte-connecte');
  const etatFin = await etatNatif(page);
  note('A2.appelsNatifsSurToutLeParcours', etatFin.appels);
  note('A2.ecouteurs', etatFin.ecouteurs);
  note('A2.reponsesLentes', lents);
  // Notification « commande prête » : LocalNotifications jamais appelé par aucun écran ?
  expect.soft(etatFin.appels.filter((a) => a.startsWith('LocalNotifications.')).length, 'LocalNotifications.requestPermissions/schedule après une commande').toBeGreaterThan(0);
  rec.dispose();
  await ctx.close();
});

// ---------------------------------------------------------------------------------------------
test('M10-A3 — iOS, pont RÉEL : « Se connecter avec Apple » (ce que vit le client aujourd\'hui)', async () => {
  const { ctx, page } = await contexte(NAV, 'ios', false);
  const rec = attachMegaAuditRecorder(page, SHOTS);
  const social = [];
  page.on('request', (r) => { if (/\/api\/auth\/social\//.test(r.url())) social.push({ method: r.request ? r.method() : null, url: r.url() }); });
  await page.goto(SITE + '/index.html', { waitUntil: 'load' });
  await page.waitForTimeout(2500);
  const etat = await etatNatif(page);
  note('A3.etat', { getPlatform: etat.getPlatform, fournisseurs: etat.fournisseurs, appels: etat.appels });
  expect(etat.getPlatform).toBe('ios');
  expect.soft(etat.fournisseurs, 'Apple proposé sur iOS sans configuration').toContain('apple');

  await page.locator('.lc-nav-btn-account').first().click();
  await page.waitForTimeout(1200);
  await rec.snap('A3-1-compte-ios-deconnecte');
  const boutonsSociaux = await page.locator('.lc-acc-social button').count();
  note('A3.boutonsSociaux', boutonsSociaux);
  const apple = page.locator('.lc-acc-social button', { hasText: /Apple/i }).first();
  if (await apple.count()) {
    await apple.click();
    await page.waitForTimeout(2500);
    await rec.snap('A3-2-apres-clic-apple-pont-reel');
    const appels = await page.evaluate(() => window.__pont.appels.map((a) => a.plugin + '.' + a.methode));
    const message = await page.evaluate(() => {
      const el = document.querySelector('[role="alert"], .lc-acc-error, .lcf-acc-error, .lc-toast');
      return el ? el.textContent.trim().slice(0, 300) : null;
    });
    note('A3.apple', { appelsNatifs: appels.filter((a) => a.startsWith('SocialLogin.')), requetesSocial: social, messageAffiche: message });
    expect.soft(appels.filter((a) => a.startsWith('SocialLogin.')).length, 'SocialLogin.initialize/login partis vers la coque').toBeGreaterThan(0);
    expect.soft(social.length, 'POST /api/auth/social/apple émis').toBeGreaterThan(0);
    expect.soft(message, 'un message visible explique l\'échec (jamais un bouton muet)').not.toBeNull();
  } else {
    note('A3.apple', 'NON EXERCÉ : aucun bouton Apple dans la modale compte');
  }
  rec.dispose();
  await ctx.close();
});

// ---------------------------------------------------------------------------------------------
test('M10-B1 — iOS, pont + registerPlugin (= correctif) : connexion Apple → route backend, push, lien profond paiement', async () => {
  const { ctx, page } = await contexte(NAV, 'ios', true);
  const rec = attachMegaAuditRecorder(page, SHOTS);
  const social = [];
  page.on('response', async (r) => {
    if (/\/api\/auth\/social\//.test(r.url())) {
      let corps = null; try { corps = await r.text(); } catch (_e) {}
      social.push({ method: r.request().method(), url: r.url(), status: r.status(), acao: r.headers()['access-control-allow-origin'] || null, corps: (corps || '').slice(0, 200), postData: r.request().postData() });
    }
  });
  await page.goto(SITE + '/index.html', { waitUntil: 'load' });
  await page.waitForTimeout(2500);
  const etat = await etatNatif(page);
  note('B1.etatDemarrage', etat);
  expect(etat.typeofRegisterPlugin).toBe('function');
  expect.soft(etat.appels.length, 'avec registerPlugin, les appels natifs partent').toBeGreaterThan(0);
  await rec.snap('B1-1-accueil-ios-pont-corrige');

  await page.locator('.lc-nav-btn-account').first().click();
  await page.waitForTimeout(1200);
  const apple = page.locator('.lc-acc-social button', { hasText: /Apple/i }).first();
  if (await apple.count()) {
    await apple.click();
    await page.waitForTimeout(3500);
    await rec.snap('B1-2-apres-clic-apple-pont-corrige');
    const appels = await page.evaluate(() => window.__pont.appels.filter((a) => a.plugin === 'SocialLogin').map((a) => ({ m: a.methode, o: a.options })));
    const message = await page.evaluate(() => {
      const el = document.querySelector('[role="alert"], .lc-acc-error, .lcf-acc-error, .lc-toast');
      return el ? el.textContent.trim().slice(0, 300) : null;
    });
    note('B1.apple', { appelsNatifs: appels, requetesSocial: social, messageAffiche: message });
    expect.soft(appels.map((a) => a.m)).toContain('login');
    expect.soft(social.length, 'POST /api/auth/social/apple émis').toBeGreaterThan(0);
    if (social.length) {
      expect.soft(social[0].url).toBe(API + '/api/auth/social/apple');
      expect.soft(social[0].postData || '').toContain('id_token');
      expect.soft(social[0].status, 'jeton factice → 401/422 propre (jamais 400 ni 500)').toBeGreaterThanOrEqual(401);
      expect.soft(social[0].status).toBeLessThan(500);
      expect.soft(social[0].acao, 'CORS sur la réponse').not.toBeNull();
    }
    expect.soft(message, 'jeton factice refusé → message visible').not.toBeNull();
  } else {
    note('B1.apple', 'NON EXERCÉ : aucun bouton Apple');
  }

  // Push : enregistrement par le pont ? (l'app n'a que LocalNotifications — pas de FCM/APNs)
  const pushAppels = await page.evaluate(() => window.__pont.appels.filter((a) => /Notification|Push/i.test(a.plugin)).map((a) => a.plugin + '.' + a.methode));
  note('B1.push', { appels: pushAppels, note: 'aucun greffon PushNotifications dans app/package.json ; LocalNotifications exposé par app-native.js (notifier/demanderPermissionNotifs) sans aucun appelant dans *.jsx/compiled' });

  // Lien profond retour paiement : un écouteur App.appUrlOpen est-il posé ? Simuler l'ouverture.
  const ecouteurs = await page.evaluate(() => window.__pont.ecouteurs.slice());
  const appUrlOpen = ecouteurs.includes('App:appUrlOpen');
  note('B1.deepLink', { ecouteurs, appUrlOpen, onlineCardEnabled: etat.onlineCardEnabled });
  if (!appUrlOpen) {
    note('B1.deepLink.simulation', 'NON EXERCÉ : aucun écouteur App.appUrlOpen enregistré par le site — fr.lecayenne.app://paiement/retour n\'est reçu par personne (paiement en ligne coupé dans l\'app par conception, api.js:47-66)');
  }
  rec.dispose();
  await ctx.close();
});

// ---------------------------------------------------------------------------------------------
test('M10-C1 — garde « onglet périmé » : regarde-t-elle compiled/funnel.js ?', async () => {
  const { ctx, page } = await contexte(NAV, 'android', false);
  await page.goto(SITE + '/index.html', { waitUntil: 'load' });
  const r = await page.evaluate(async () => {
    const parJsx = document.querySelector('script[src*="funnel.jsx"]');
    const parJs = document.querySelector('script[src*="compiled/funnel.js"]');
    const html = await (await fetch('/', { cache: 'no-store' })).text();
    return {
      scriptFunnelJsxTrouve: !!parJsx,
      scriptCompiledFunnelJsTrouve: !!parJs,
      srcReel: parJs ? parJs.getAttribute('src') : null,
      regexJsxSurIndexFrais: (html.match(/funnel\.jsx\?v=([0-9a-z]+)/i) || [])[1] || null,
      regexJsSurIndexFrais: (html.match(/compiled\/funnel\.js\?v=([0-9a-z]+)/i) || [])[1] || null,
    };
  });
  const racineJs = fs.readFileSync(path.join(SITE_REPO, 'compiled/racine.js'), 'utf8');
  const ligneGarde = racineJs.split('\n').findIndex((l) => /script\[src\*="funnel\.jsx"\]/.test(l)) + 1;
  const ligneGardeJs = racineJs.split('\n').findIndex((l) => /script\[src\*="compiled\/funnel\.js"\]/.test(l)) + 1;
  note('C1.gardeOngletPerime', { ...r, 'compiled/racine.js:selecteur funnel.jsx ligne': ligneGarde || null, 'compiled/racine.js:selecteur compiled/funnel.js ligne': ligneGardeJs || null });
  expect.soft(r.scriptFunnelJsxTrouve || !r.scriptCompiledFunnelJsTrouve, 'la garde cherche funnel.jsx alors que la page charge compiled/funnel.js → elle sort sur `if (!own) return true` : morte').toBe(true);
  expect.soft(ligneGardeJs, 'compiled/racine.js doit viser compiled/funnel.js').toBeGreaterThan(0);
  await ctx.close();
});
