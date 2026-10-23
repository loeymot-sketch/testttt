// ÉTAT DES LIEUX 2026-10-23 — vague A : l'APPLICATION des stores (site lecayenne.fr empaqueté Capacitor 8) face à R1..R7.
// LECTURE SEULE sur le produit. Chaque test CONSTATE (le vert = « le constat est exact »), il ne réclame pas la correction.
//
// Trois cibles, servies par le MÊME pont Capacitor réel (native-bridge.js injecté tel quel, SANS registerPlugin) :
//   APP  = http://127.0.0.1:8013  copie de  <site>/app/www  (ce qui est EMBARQUÉ dans android/ et ios/), seule l'URL de l'API est réécrite
//   WT   = http://127.0.0.1:8011  arbre de travail du site (ce que l'app deviendrait après `npm run build:www`)
//   CTRL = http://127.0.0.1:8014  paquet www construit depuis origin/main (pont natif corrigé, sans livraison) = TÉMOIN d'instrument
// Paramètres : A_APP, A_WT, A_CTRL (URLs). Preuves : reports/test-e2e/etat-des-lieux-2026-10-23/A-app-evidence/.
// Préfixe des commandes/comptes créés : « E2E-APP- » (une seule commande de retrait, base locale foodking_e2e).
const { test, expect, chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const L = require('../e2e-complet-2026-10-22/C-lib-r2');

const APP = process.env.A_APP || 'http://127.0.0.1:8013';
const WT = process.env.A_WT || 'http://127.0.0.1:8011';
const CTRL = process.env.A_CTRL || 'http://127.0.0.1:8014';
const DIR = path.resolve(__dirname, '../../../reports/test-e2e/etat-des-lieux-2026-10-23/A-app-evidence');
const J = L.journal(DIR, 'constats.json');
const SITE_REPO = L.SITE_REPO;

function sql(requete) {
  const env = fs.readFileSync(path.resolve(__dirname, '../../../.env'), 'utf8');
  const mdp = ((env.match(/^DB_PASSWORD=(.*)$/m) || [])[1] || '').trim().replace(/^"|"$/g, '');
  return execFileSync('mysql', ['-uroot', '-h127.0.0.1', 'foodking_e2e', '-N', '-B', '-e', requete], { env: { ...process.env, MYSQL_PWD: mdp }, encoding: 'utf8' }).trim();
}

// Tutoiement adressé au client : tu / ton / ta / tes / toi / te / t'
const RE_TU = /(?:^|[^\p{L}\p{N}])(?:tu|ton|ta|tes|toi|te)(?![\p{L}\p{N}])|(?:^|[^\p{L}\p{N}])t['’]\p{L}/giu;
const tutoiements = (texte) => {
  const out = []; let m; RE_TU.lastIndex = 0;
  while ((m = RE_TU.exec(texte))) out.push(texte.slice(Math.max(0, m.index - 25), m.index + m[0].length + 25).replace(/\s+/g, ' ').trim());
  return out;
};

let NAV = null;
test.setTimeout(420_000);
test.beforeAll(async () => {
  NAV = await chromium.launch({ channel: 'chromium', args: ['--lang=fr-FR'] });
  J.note('instrument', {
    cibles: { APP, WT, CTRL }, bridgeAndroidSha256: L.sha(L.BRIDGE), date: new Date().toISOString(),
    ua: L.UA_ANDROID, viewport: '390x844',
  });
});
test.afterAll(async () => { if (NAV) await NAV.close(); });

const png = async (page, nom, opts = {}) => page.screenshot({ path: path.join(DIR, nom + '.png'), ...opts });
const appelsNatifs = (page) => page.evaluate(() => window.__pont.appels.map((a) => a.plugin + '.' + a.methode));
const onglet = (page, libelle) => page.locator('button.lc-navx-item', { has: page.locator('.lc-navx-lbl', { hasText: new RegExp('^' + libelle + '$') }) }).first();

async function ouvrir(page, site, connecte = true) {
  await page.goto(site + '/index.html', { waitUntil: 'load' });
  if (connecte) {
    await page.evaluate((t) => localStorage.setItem('lecayenne.authToken', t), L.jeton());
    await page.reload({ waitUntil: 'load' });
  }
  await page.waitForTimeout(2800);
}

async function nonMerci(page) {
  for (let i = 0; i < 3; i++) {
    const b = page.getByRole('button', { name: /^\s*(Non merci(, continuer|, régler)?|Continuer sans|Pas maintenant)\b/ }).first();
    if (!(await b.isVisible().catch(() => false))) break;
    await b.click(); await page.waitForTimeout(600);
  }
}

/** Menu -> 1er produit -> panier -> « Passer commande » : l'écran « Retrait & paiement » (ou « Livraison & paiement »). */
async function versLeTunnel(page, produit) {
  await onglet(page, 'Menu').click(); await page.waitForTimeout(1200);
  const carte = produit ? page.locator('button.lc-card-item-name:not([disabled])', { hasText: produit }) : page.locator('button.lc-card-item-name:not([disabled])');
  await carte.first().click(); await page.waitForTimeout(1000);
  const perso = page.locator('button', { hasText: /^\s*Personnaliser/ }).first();
  if (await perso.count()) { await perso.click(); await page.waitForTimeout(1000); }
  for (let i = 0; i < 20; i++) {
    const cta = page.locator('.lc-wiz-foot-next').first();
    if (!(await cta.count())) break;
    const txt = ((await cta.innerText()) || '').replace(/\s+/g, ' ').trim();
    if ((await cta.getAttribute('aria-disabled')) === 'true') {
      const o = page.locator('button.lc-wiz-choice:not(.is-on):visible').first();
      if (!(await o.count())) break;
      await o.click(); await page.waitForTimeout(350); continue;
    }
    await cta.click(); await page.waitForTimeout(800);
    if (/Ajouter au panier/.test(txt)) break;
  }
  await nonMerci(page);
  const barre = page.locator('.lc-menu-cartbar');
  if (await barre.count()) await barre.click(); else await page.locator('.lc-nav-btn-cart').click();
  await page.waitForTimeout(900);
  await page.locator('button:visible', { hasText: /^\s*(Passer commande|Commander)/ }).first().click();
  await page.waitForTimeout(1500);
  await nonMerci(page);
}

const texteTunnel = (page) => page.evaluate(() => (document.querySelector('.lcf-card') || document.body).innerText);

/** Écrit la liste des statuts des appels /api/frontend/delivery/* (preuve réseau). */
function espionLivraison(page) {
  const trace = [];
  page.on('response', (r) => { if (/\/api\/frontend\/(delivery|address)/.test(r.url())) trace.push({ m: r.request().method(), u: r.url().replace(/^https?:\/\/[^/]+/, '').slice(0, 90), s: r.status() }); });
  return trace;
}

// ───────────────────────────── (a) INSTRUMENT + écart app/www ─────────────────────────────
test('A-INS-1 instrument : pont réel sans registerPlugin ; le témoin CTRL appelle le natif, APP non (app-native.js embarqué = ancien)', async () => {
  const mesures = {};
  for (const [nom, site] of [['CTRL', CTRL], ['APP', APP], ['WT', WT]]) {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
    const erreurs = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 160)));
    await ouvrir(page, site, false);
    const etat = await page.evaluate(() => ({
      natif: window.Capacitor.isNativePlatform(), registerPlugin: typeof window.Capacitor.registerPlugin,
      lcActif: !!(window.LC && window.LC.native && window.LC.native.actif), apiBase: (document.querySelector('meta[name=api-base-url]') || {}).content,
      deliveryEnabled: !!(window.LC.api.config || {}).deliveryEnabled, fetchMeta: (document.querySelector('meta[name=feature-delivery]') || {}).content || null,
      script: Array.from(document.scripts).map((s) => s.src).filter((s) => /funnel|api\.js|app-native/.test(s)).map((s) => s.replace(/^https?:\/\/[^/]+/, '')),
    }));
    const appels = await appelsNatifs(page);
    mesures[nom] = { site, etat, nbAppelsNatifs: appels.length, appels: appels.slice(0, 14), pageerrors: erreurs };
    await ctx.close();
  }
  J.note('INS-1.mesures', mesures);
  // le témoin bat : un pont vivant fait partir des appels natifs
  expect(mesures.CTRL.etat.registerPlugin, 'forme réelle du pont : registerPlugin absent').toBe('undefined');
  expect(mesures.CTRL.nbAppelsNatifs, 'témoin CTRL : le natif part (l\'instrument mord)').toBeGreaterThan(3);
  // APP : app-native.js embarqué passe par Capacitor.registerPlugin => aucun appel natif
  expect(mesures.APP.etat.lcActif).toBe(true);
  expect(mesures.APP.nbAppelsNatifs, 'APP (app/www tel quel) : pont natif MORT').toBeLessThanOrEqual(1);
  expect(mesures.WT.nbAppelsNatifs, 'WT : app-native.js identique à APP => mort aussi').toBeLessThanOrEqual(1);
  expect(mesures.APP.etat.deliveryEnabled, 'APP : livraison coupée (feature-delivery absent -> défaut 0)').toBe(false);
  expect(mesures.WT.etat.deliveryEnabled, 'WT : livraison active (témoin : l\'instrument voit une livraison quand elle existe)').toBe(true);
  expect(mesures.APP.etat.fetchMeta).toBeNull();
});

test('A-INS-2 écart app/www vs site : empreintes sha256, ligne de commits reflétée, copies natives', async () => {
  const www = path.join(SITE_REPO, 'app/www');
  const walk = (d, base = d) => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(d, e.name), base) : [path.relative(base, path.join(d, e.name))]));
  const fichiers = walk(www).filter((f) => !f.endsWith('.DS_Store')).sort();
  const sha = (p) => (fs.existsSync(p) ? L.sha(p) : null);
  const cmp = (racine) => fichiers.filter((f) => sha(path.join(racine, f)) !== sha(path.join(www, f)));
  const divergentsArbre = cmp(SITE_REPO);
  const critiques = ['index.html', 'api.js', 'app-native.js', 'compiled/funnel.js', 'compiled/wizard-v2.js', 'compiled/screens.js', 'compiled/racine.js', 'compiled/components.js'];
  const git = (...a) => execFileSync('git', ['-C', SITE_REPO, ...a], { encoding: 'utf8' }).trim();
  const blobOk = (ref, f) => { try { return git('rev-parse', `${ref}:${f}`) === git('hash-object', path.join(www, f)); } catch (_e) { return false; } };
  const parRef = {};
  for (const ref of ['HEAD', 'origin/main', 'b9e1f64']) parRef[ref] = fichiers.filter((f) => !blobOk(ref, f)).length;
  const appNativeEgalWT = sha(path.join(www, 'app-native.js')) === sha(path.join(SITE_REPO, 'app-native.js'));
  const appNativeEgalOriginMain = blobOk('origin/main', 'app-native.js');
  const natifAndroid = path.join(SITE_REPO, 'app/android/app/src/main/assets/public');
  const natifIos = path.join(SITE_REPO, 'app/ios/App/App/public');
  const ecartNatif = (d) => fichiers.filter((f) => sha(path.join(d, f)) !== sha(path.join(www, f)));
  const apk = path.join(L.SCRATCH, 'app-natif-main.apk');
  const constat = {
    nbFichiersWww: fichiers.length, divergentsAvecArbreDeTravail: divergentsArbre,
    critiques: Object.fromEntries(critiques.map((f) => [f, { www: sha(path.join(www, f)).slice(0, 12), arbreTravail: (sha(path.join(SITE_REPO, f)) || '').slice(0, 12), egal: sha(path.join(SITE_REPO, f)) === sha(path.join(www, f)) }])),
    fichiersDifferentsParRef: parRef, appNativeEgalWT, appNativeEgalOriginMain,
    ecartAssetsAndroid: ecartNatif(natifAndroid), ecartAssetsIos: ecartNatif(natifIos),
    head: git('log', '-1', '--format=%h %ci %s', 'HEAD').slice(0, 120), mergeBase: git('merge-base', 'HEAD', 'origin/main').slice(0, 9),
    commitsHeadSeul: git('rev-list', '--count', 'origin/main..HEAD'), commitsOriginSeul: git('rev-list', '--count', 'HEAD..origin/main'),
    celluleWwwIgnoree: git('check-ignore', '-v', 'app/www'), apkPresent: fs.existsSync(apk),
    apkCompteLivraison: fs.existsSync(apk) ? execFileSync('sh', ['-c', `unzip -p '${apk}' assets/public/api.js | grep -c deliveryStatus || true`], { encoding: 'utf8' }).trim() : null,
  };
  J.note('INS-2.ecartWww', constat);
  expect(divergentsArbre.sort()).toEqual(['api.js', 'carte.html', 'compiled/funnel.js', 'compiled/wizard-v2.js', 'data/menu.js', 'index.html', 'plat/cayenne.html', 'plat/supreme.html', 'sandwichs-galettes.html']);
  expect(appNativeEgalWT).toBe(true);
  expect(appNativeEgalOriginMain, 'app/www n\'embarque PAS le pont natif corrigé de origin/main').toBe(false);
  expect(constat.ecartAssetsAndroid).toEqual([]); // les dossiers natifs contiennent déjà la même copie périmée
  expect(constat.ecartAssetsIos).toEqual([]);
  expect(constat.apkCompteLivraison, 'l\'APK du scratchpad (origin/main) n\'a AUCUNE livraison').toBe('0');
});

// ───────────────────────────── R1 : choix À emporter / Livraison ─────────────────────────────
test('A-R1 choix du mode : APP = « Livraison » est un lien Uber Eats ; WT = vrai choix mais à l\'étape paiement ; lien Uber Eats mort sur APP', async () => {
  const res = {};
  for (const [nom, site] of [['APP', APP], ['WT', WT]]) {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
    await ouvrir(page, site, true);
    const accueil = await page.evaluate(() => document.body.innerText);
    res[nom] = { accueilDitAEmporterSeul: /À emporter, retrait au comptoir/.test(accueil), accueilMentionneLivraison: /livraison|livrer/i.test(accueil) };
    // le choix de mode est-il proposé AVANT le tunnel (menu, panier) ?
    await onglet(page, 'Menu').click(); await page.waitForTimeout(1000);
    res[nom].menuMentionneMode = /À emporter|Livraison/.test(await page.evaluate(() => document.body.innerText));
    await versLeTunnel(page);
    res[nom].radios = await page.evaluate(() => Array.from(document.querySelectorAll('.lcf-card [aria-label="Mode de retrait"] [role=radio]')).map((e) => e.innerText.replace(/\s+/g, ' ') + ' | ' + e.getAttribute('aria-checked')));
    res[nom].lienUberEats = await page.evaluate(() => { const a = document.querySelector('.lcf-card a[href*="ubereats"]'); return a ? { texte: a.innerText.replace(/\s+/g, ' '), href: a.href, target: a.target } : null; });
    res[nom].titre = (await texteTunnel(page)).split('\n').slice(0, 6).join(' / ');
    await page.locator('.lcf-card').first().scrollIntoViewIfNeeded().catch(() => {});
    await png(page, `R1-${nom}-tunnel-mode`);
    await ctx.close();
  }
  J.note('R1.modes', res);
  expect(res.APP.accueilDitAEmporterSeul).toBe(true);
  expect(res.APP.accueilMentionneLivraison).toBe(false);
  expect(res.APP.radios.length, 'APP : un seul vrai radio (À emporter) + un lien').toBe(1);
  expect(res.APP.lienUberEats && res.APP.lienUberEats.texte).toMatch(/Livraison.*Uber Eats/);
  expect(res.WT.radios.length, 'témoin WT : deux radios quand la livraison est active').toBe(2);
  expect(res.WT.radios.join(' ')).toMatch(/À emporter.*Livraison/);
  expect(res.WT.menuMentionneMode, 'le choix n\'est pas proposé avant le tunnel de paiement').toBe(false);

  // Le lien « Livraison -> Uber Eats » doit ouvrir le navigateur natif.
  // Témoin CTRL (pont corrigé) : un lien externe de l'accueil -> Browser.open. APP : le même lien ET le lien Uber Eats du tunnel.
  const clique = async (page, ctx, site, selecteur) => {
    await page.evaluate(() => { window.__dp = null; window.addEventListener('click', (e) => { window.__dp = e.defaultPrevented; }, false); });
    const lien = page.locator(selecteur).first();
    await lien.scrollIntoViewIfNeeded();
    const avant = (await appelsNatifs(page)).filter((n) => n === 'Browser.open').length;
    const atteignable = await lien.evaluate((a) => { const r = a.getBoundingClientRect(); const e = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { atteint: !!e && (e === a || a.contains(e)), couvertPar: e && !(e === a || a.contains(e)) ? String(e.className || e.tagName).slice(0, 60) : null }; });
    const [popup] = await Promise.all([ctx.waitForEvent('page', { timeout: 1500 }).catch(() => null), lien.dispatchEvent('click')]);
    await page.waitForTimeout(900);
    const apres = (await appelsNatifs(page)).filter((n) => n === 'Browser.open').length;
    const r = { atteignable, browserOpen: apres - avant, defaultPrevented: await page.evaluate(() => window.__dp), popup: !!popup, pageRemplacee: !page.url().startsWith(site) };
    if (popup) await popup.close();
    return r;
  };
  const liens = {};
  {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
    await ouvrir(page, CTRL, true);
    liens.CTRL_accueil = await clique(page, ctx, CTRL, 'a[href^="https://www.google.com/maps"]');
    await ctx.close();
  }
  {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
    await ouvrir(page, APP, true);
    liens.APP_accueil = await clique(page, ctx, APP, 'a[href^="https://www.google.com/maps"]');
    await versLeTunnel(page);
    liens.APP_tunnelUberEats = await clique(page, ctx, APP, '.lcf-card a[href*="ubereats"]');
    await ctx.close();
  }
  J.note('R1.lienUberEatsNatif', liens);
  expect(liens.CTRL_accueil.browserOpen, 'témoin : pont corrigé -> Browser.open').toBeGreaterThan(0);
  expect(liens.APP_accueil.browserOpen, 'APP : le clic est annulé puis RIEN').toBe(0);
  expect(liens.APP_tunnelUberEats.browserOpen, 'APP : lien « Livraison -> Uber Eats » mort').toBe(0);
  expect(liens.APP_tunnelUberEats.defaultPrevented).toBe(true);
  expect(liens.APP_tunnelUberEats.popup || liens.APP_tunnelUberEats.pageRemplacee).toBe(false);
});

// ───────────────────────────── R2 : adresse + suggestions ─────────────────────────────
test('A-R2 adresse (arbre de travail dans la coque) : datalist native alimentée par /delivery/suggest ; aucune adresse dans APP ; clavier simulé', async () => {
  // APP : aucun champ adresse
  const a = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  await ouvrir(a.page, APP, true);
  await versLeTunnel(a.page);
  const champsApp = await a.page.locator('input[aria-label="Adresse de livraison"]').count();
  await a.ctx.close();

  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  const trace = espionLivraison(page);
  await ouvrir(page, WT, true);
  await versLeTunnel(page);
  await page.locator('.lcf-card [role=radio]', { hasText: /Livraison/ }).first().click();
  await page.waitForTimeout(1000);
  const champ = page.locator('input[aria-label="Adresse de livraison"]');
  await expect(champ).toBeVisible();
  await champ.click();
  await champ.pressSequentially('12 rue jean jaures henin', { delay: 40 });
  await page.waitForTimeout(2500);
  const liste = await page.evaluate(() => {
    const i = document.querySelector('input[aria-label="Adresse de livraison"]');
    const dl = i.list;
    return {
      attrList: i.getAttribute('list'), listeResolue: !!dl, options: dl ? Array.from(dl.options).map((o) => o.value) : [],
      roleListbox: document.querySelectorAll('[role=listbox],[role=option]').length, autocomplete: i.autocomplete, autocompleteAria: i.getAttribute('aria-autocomplete'),
      type: i.type, inputmode: i.getAttribute('inputmode'), enterkeyhint: i.getAttribute('enterkeyhint'),
    };
  });
  const suggests = trace.filter((t) => /suggest/.test(t.u));
  // choix d'une suggestion = on pose la valeur d'une <option> (ce que fait le sélecteur natif), puis « Calculer »
  await champ.fill(liste.options[0] || '');
  await page.getByRole('button', { name: 'Calculer' }).click();
  await page.waitForTimeout(3000);
  const apres = await texteTunnel(page);
  await png(page, 'R2-WT-adresse-apres-calcul');

  // Clavier logiciel simulé (Keyboard.resize = native => la WebView rétrécit) : champ focus à 544 px de haut
  await page.setViewportSize({ width: 390, height: 544 });
  await champ.focus(); await champ.evaluate((e) => e.scrollIntoView({ block: 'center' })); await page.waitForTimeout(500);
  const geo = await page.evaluate(() => {
    const r = (e) => { if (!e) return null; const b = e.getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), left: Math.round(b.left), right: Math.round(b.right) }; };
    const i = document.querySelector('input[aria-label="Adresse de livraison"]');
    const calc = Array.from(document.querySelectorAll('button')).find((b) => b.innerText.trim() === 'Calculer');
    let bar = document.querySelector('.lcf-cta-bar-next');
    while (bar && getComputedStyle(bar).position !== 'fixed' && bar.parentElement) bar = bar.parentElement;
    if (bar && getComputedStyle(bar).position !== 'fixed') bar = null;
    const cs = bar ? getComputedStyle(bar) : null;
    return { vh: innerHeight, input: r(i), calculer: r(calc), bar: r(bar), barPos: cs && cs.position, barDisplay: cs && cs.display, barBtnVisible: !!document.querySelector('.lcf-cta-bar-next') && document.querySelector('.lcf-cta-bar-next').getBoundingClientRect().bottom <= innerHeight };
  });
  await png(page, 'R2-WT-clavier-544');
  const recouvre = (x, y) => !!(x && y && x.top < y.bottom && x.bottom > y.top);
  J.note('R2.adresse', {
    champsApp, liste, nbRequetesSuggest: suggests.length, statutsSuggest: [...new Set(suggests.map((t) => t.s))], apres: apres.split('\n').slice(0, 16),
    clavier544: { ...geo, barreRecouvreChamp: recouvre(geo.bar, geo.input), barreRecouvreCalculer: recouvre(geo.bar, geo.calculer) },
    aFaireSurAppareil: 'Android System WebView : rendu du popup <datalist> (non capturé par Playwright) ; iOS WKWebView : barre de suggestions du clavier',
  });
  expect(champsApp, 'APP : pas de champ adresse').toBe(0);
  expect(liste.attrList).toBe('lcf-delivery-suggest');
  expect(liste.options.length, 'suggestions reçues du serveur dans le datalist').toBeGreaterThan(0);
  expect(liste.roleListbox, 'aucune liste ARIA : seule la datalist native porte les suggestions').toBe(0);
  expect(suggests.length).toBeGreaterThan(5);
  expect(suggests.every((t) => t.s === 200), 'aucun 429 à la frappe : ' + JSON.stringify(suggests.map((t) => t.s))).toBe(true);
  expect(apres).toMatch(/Adresse comprise/);
  expect(geo.input.bottom, 'le champ reste dans la fenêtre rétrécie').toBeLessThanOrEqual(geo.vh);
  await ctx.close();
});

// ───────────────────────────── R3 : distance, barème, Uber ─────────────────────────────
test('A-R3 distance et barème (arbre de travail dans la coque) : tranches 3,90/4,90/5,90/6,90 ; >10 km = Uber Eats ; aucun devis Uber Direct, aucun « 7 km »', async () => {
  const adresses = [
    ['home', '437 rue elie gruyelle 62110 henin-beaumont'],
    ['carvin', 'Place Jean Jaurès 62220 Carvin'],
    ['lens', 'Place du Maréchal Foch 62300 Lens'],
    ['douai', 'Place d\'Armes 59500 Douai'],
  ];
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  const trace = espionLivraison(page);
  await ouvrir(page, WT, true);
  await versLeTunnel(page);
  await page.locator('.lcf-card [role=radio]', { hasText: /Livraison/ }).first().click();
  await page.waitForTimeout(800);
  const champ = page.locator('input[aria-label="Adresse de livraison"]');
  const res = {};
  for (const [cle, adresse] of adresses) {
    await champ.fill(adresse); await page.waitForTimeout(500);
    const rq = page.waitForResponse((r) => /delivery\/quote/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }).catch(() => null);
    await page.getByRole('button', { name: 'Calculer' }).click();
    await rq;
    await page.waitForTimeout(1500);
    const t = await texteTunnel(page);
    res[cle] = { extrait: t.split('\n').filter((l) => /km|Livraison|trop loin|Uber|Adresse comprise|📍/.test(l)).slice(0, 8), mentionUber: /uber/i.test(t.replace(/Hénin/g, '')), lienUberEats: await page.locator('.lcf-card a[href*="ubereats"]').count() };
    if (cle === 'carvin') await png(page, 'R3-WT-carvin-8km');
    if (cle === 'douai') await png(page, 'R3-WT-douai-hors-zone');
  }
  const quotes = trace.filter((t) => /quote/.test(t.u));
  const sept = await page.evaluate(() => /\b7\s?km|tarif calculé par Uber|à partir de 7/i.test(document.body.innerText));
  // le lien Uber Eats hors zone dans la coque : mort ?
  await page.evaluate(() => { window.__dp = null; window.addEventListener('click', (e) => { window.__dp = e.defaultPrevented; }, false); });
  const lien = page.locator('.lcf-card a[href*="ubereats"]').first();
  let lienMort = null;
  if (await lien.count()) {
    await lien.scrollIntoViewIfNeeded(); await page.waitForTimeout(400);
    const atteignable = await lien.evaluate((a) => { const r = a.getBoundingClientRect(); const e = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { atteint: !!e && (e === a || a.contains(e)), couvertPar: e && !(e === a || a.contains(e)) ? (e.className || e.tagName) : null, bas: Math.round(r.bottom), vh: innerHeight }; });
    await lien.dispatchEvent('click'); await page.waitForTimeout(800);
    lienMort = { atteignable, browserOpen: (await appelsNatifs(page)).filter((n) => n === 'Browser.open').length, defaultPrevented: await page.evaluate(() => window.__dp) };
  }
  // la source de la distance côté serveur (vol d'oiseau ?)
  const src = fs.readFileSync(path.resolve(__dirname, '../../../app/Services/Delivery/DeliveryFeeService.php'), 'utf8');
  const ligneVolOiseau = src.split('\n').findIndex((l) => /vol d'oiseau/.test(l)) + 1;
  J.note('R3.bareme', { res, statutsQuote: [...new Set(quotes.map((t) => t.s))], motifs7kmOuUberPresentsDansUI: sept, lienUberEatsHorsZone: lienMort, ligneVolOiseauDeliveryFeeService: ligneVolOiseau });
  expect(res.home.extrait.join(' ')).toMatch(/3,90/);
  expect(res.carvin.extrait.join(' ')).toMatch(/7,99 km.*5,90/);
  expect(res.carvin.mentionUber, 'à 7,99 km : pas de devis Uber Direct, notre barème').toBe(false);
  expect(res.lens.extrait.join(' ')).toMatch(/8,89 km.*6,90/);
  expect(res.douai.extrait.join(' ')).toMatch(/plus de 10 km/);
  expect(res.douai.lienUberEats).toBeGreaterThan(0);
  expect(sept, 'aucun « à partir de 7 km — tarif calculé par Uber » dans l\'UI').toBe(false);
  expect(ligneVolOiseau, 'distance serveur = « à vol d\'oiseau »').toBeGreaterThan(0);
  expect(lienMort && lienMort.browserOpen, 'lien Uber Eats hors zone mort dans la coque (arbre de travail)').toBe(0);
  expect(lienMort.defaultPrevented).toBe(true);
  await ctx.close();
});

// ───────────────────────────── R4 : annonce « Nouveau : la livraison ! » ─────────────────────────────
test('A-R4 annonce sur l\'accueil : absente (APP, WT) ; le détecteur voit bien « Nouveautés » du menu', async () => {
  const res = {};
  for (const [nom, site] of [['APP', APP], ['WT', WT]]) {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
    await ouvrir(page, site, true);
    const accueil = await page.evaluate(() => document.body.innerText);
    const html = await page.content();
    res[nom] = {
      annonce: /nouveau\s*:?\s*la livraison/i.test(accueil), motLivraison: (accueil.match(/livraison|livrer/gi) || []).length,
      bandeauAnnonce: await page.locator('[role=status],[class*=announce i],[class*=bandeau i],[class*=banner i]').count(),
      htmlContientNouveauLivraison: /nouveau\s*:?\s*(?:&nbsp;)?\s*la livraison/i.test(html),
    };
    if (nom === 'APP') await png(page, 'R4-APP-accueil');
    await onglet(page, 'Menu').click(); await page.waitForTimeout(900);
    res[nom].detecteurVoitNouveautes = /Nouveautés/.test(await page.evaluate(() => document.body.innerText));
    await ctx.close();
  }
  J.note('R4.annonce', res);
  for (const nom of ['APP', 'WT']) {
    expect(res[nom].detecteurVoitNouveautes, 'instrument : le texte du menu est lisible').toBe(true);
    expect(res[nom].annonce).toBe(false);
    expect(res[nom].motLivraison).toBe(0);
    expect(res[nom].htmlContientNouveauLivraison).toBe(false);
  }
});

// ───────────────────────────── R5 : vouvoiement ─────────────────────────────
test('A-R5 vouvoiement : tutoiement partout (écrans rendus dans la coque + chaînes embarquées)', async () => {
  // 1. Écrans rendus, APP
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  await ouvrir(page, APP, true);
  const ecrans = {};
  const scanner = async (nom) => {
    const dump = await page.evaluate(() => {
      const attrs = Array.from(document.querySelectorAll('[placeholder],[aria-label],[title],[alt]')).flatMap((e) => ['placeholder', 'aria-label', 'title', 'alt'].map((a) => e.getAttribute(a)).filter(Boolean));
      return document.body.innerText + '\n' + attrs.join('\n');
    });
    ecrans[nom] = [...new Set(tutoiements(dump))];
  };
  await scanner('accueil');
  await onglet(page, 'Menu').click(); await page.waitForTimeout(900); await scanner('menu');
  for (const lib of ['Avis', 'Réseaux']) { await onglet(page, lib).click({ timeout: 5000 }).catch(() => {}); await page.waitForTimeout(700); await scanner(lib.toLowerCase()); }
  await page.locator('button.lc-nav-btn-account').click({ timeout: 5000 }).catch(() => {}); await page.waitForTimeout(900); await scanner('compte');
  await page.goBack().catch(() => {}); await page.waitForTimeout(500);
  await onglet(page, 'Menu').click(); await page.waitForTimeout(700);
  await versLeTunnel(page);
  await scanner('tunnel-retrait-paiement');
  await png(page, 'R5-APP-tunnel-tutoiement');
  const tout = [...new Set(Object.values(ecrans).flat())];
  await ctx.close();

  // 2. Chaînes littérales embarquées dans app/www (code réellement exécuté)
  const www = path.join(SITE_REPO, 'app/www');
  const lit = (f) => {
    const src = fs.readFileSync(f, 'utf8'); const out = new Set();
    for (const m of src.matchAll(/"((?:[^"\\\n]|\\.)*)"|'((?:[^'\\\n]|\\.)*)'/g)) {
      let s = m[1] !== undefined ? m[1] : m[2];
      if (!s || s.length < 8 || !/\s/.test(s)) continue;
      s = s.replace(/\\x([0-9A-Fa-f]{2})/g, (_x, h) => String.fromCharCode(parseInt(h, 16))).replace(/\\u([0-9A-Fa-f]{4})/g, (_x, h) => String.fromCharCode(parseInt(h, 16))).replace(/\\n/g, ' ');
      if (tutoiements(s).length) out.add(s.slice(0, 140));
    }
    return [...out];
  };
  const parFichier = {};
  for (const f of ['compiled/funnel.js', 'compiled/screens.js', 'compiled/racine.js', 'compiled/components.js', 'compiled/wizard-v2.js', 'compiled/orders.js', 'compiled/account-v2.js', 'compiled/loyalty-v2.js', 'compiled/upsell.js', 'compiled/flows.js', 'compiled/screens-v3.js', 'api.js', 'app-native.js']) parFichier[f] = lit(path.join(www, f));
  const total = Object.values(parFichier).reduce((n, l) => n + l.length, 0);
  J.note('R5.vouvoiement', {
    ecransRendus: ecrans, nbPhrasesEcransRendus: tout.length, nbChainesEmbarquees: total,
    chainesParFichier: Object.fromEntries(Object.entries(parFichier).map(([f, l]) => [f, { n: l.length, exemples: l.slice(0, 6) }])),
  });
  // instrument : la regex mord sur du tutoiement connu et épargne du vouvoiement
  expect(tutoiements('Tu viens chercher')).toHaveLength(1);
  expect(tutoiements('TA COMMANDE ?')).toHaveLength(1);
  expect(tutoiements('ton plat t\'attend')).toHaveLength(2);
  expect(tutoiements('Vous venez chercher votre commande, mettez-la dans un sac')).toHaveLength(0);
  expect(tout.length, 'tutoiement visible à l\'écran').toBeGreaterThan(5);
  expect(ecrans['tunnel-retrait-paiement'].join(' ')).toMatch(/TA COMMANDE|Tu viens chercher|ton plat/i);
  expect(total, 'chaînes tutoyées embarquées').toBeGreaterThan(20);
});

// ───────────────────────────── R6 : mode de paiement après confirmation ─────────────────────────────
test('A-R6 paiement : choisi AVANT « Confirmer » ; seul « Payer sur place » ; pas de choix espèces/carte/titre-restaurant ; libellé « comptoir » même en livraison', async () => {
  const res = {};
  // APP, retrait : corps de la commande (annulé avant envoi, aucune écriture)
  {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
    await ouvrir(page, APP, true); await versLeTunnel(page);
    res.APP = { radiosPaiement: await page.evaluate(() => Array.from(document.querySelectorAll('[aria-label="Mode de paiement"] [role=radio]')).map((e) => e.innerText.replace(/\s+/g, ' '))) };
    res.APP.ctaAvant = ((await page.locator('button.lcf-cta-bar-next').innerText()) || '').replace(/\s+/g, ' ');
    res.APP.tunnel = await texteTunnel(page);
    let corps = null;
    await page.route('**/api/frontend/order', async (route) => { corps = route.request().postData(); await route.abort(); });
    await page.locator('button.lcf-cta-bar-next').click(); await page.waitForTimeout(2000);
    res.APP.corps = corps ? JSON.parse(corps) : null;
    await ctx.close();
  }
  // WT, livraison Carvin : même contrôle
  {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
    await ouvrir(page, WT, true); await versLeTunnel(page);
    await page.locator('.lcf-card [role=radio]', { hasText: /Livraison/ }).first().click(); await page.waitForTimeout(800);
    await page.locator('input[aria-label="Adresse de livraison"]').fill('Place Jean Jaurès 62220 Carvin');
    await page.getByRole('button', { name: 'Calculer' }).click(); await page.waitForTimeout(3500);
    res.WT = { radiosPaiement: await page.evaluate(() => Array.from(document.querySelectorAll('[aria-label="Mode de paiement"] [role=radio]')).map((e) => e.innerText.replace(/\s+/g, ' '))) };
    const t = await texteTunnel(page);
    res.WT.libelles = t.split('\n').filter((l) => /comptoir|sur place|plat t'attend|Titre|Espèces|carte|porte|livreur/i.test(l));
    await page.locator('[aria-label="Mode de paiement"]').scrollIntoViewIfNeeded().catch(() => {});
    await png(page, 'R6-WT-livraison-paiement-comptoir');
    let corps = null;
    await page.route('**/api/frontend/order', async (route) => { corps = route.request().postData(); await route.abort(); });
    await page.locator('button.lcf-cta-bar-next').click(); await page.waitForTimeout(2200);
    res.WT.corps = corps ? JSON.parse(corps) : null;
    await ctx.close();
  }
  // le code : les moyens de paiement de livraison existent-ils quelque part dans le bundle ?
  const sansCommentaires = (f) => fs.readFileSync(f, 'utf8').split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
  const www = sansCommentaires(path.join(SITE_REPO, 'app/www/compiled/funnel.js'));
  const wt = sansCommentaires(path.join(SITE_REPO, 'compiled/funnel.js'));
  const motif = /titre.?restaurant|ticket.?restaurant|pay_at_delivery|payer à la porte|à la livraison/i;
  res.codeTitreRestaurantOuALaLivraison = { www: motif.test(www), arbreTravail: motif.test(wt) };
  J.note('R6.paiement', res);
  expect(res.APP.radiosPaiement.length, 'APP : un seul moyen (Payer sur place)').toBe(1);
  expect(res.APP.corps && res.APP.corps.payment_method, 'mode enregistré à l\'envoi, choisi avant « Confirmer »').toBe(1);
  expect(res.APP.ctaAvant).toMatch(/Confirmer ma commande/);
  expect(res.WT.radiosPaiement.length, 'WT livraison : toujours un seul moyen').toBe(1);
  expect(res.WT.radiosPaiement.join(' ')).toMatch(/Payer sur place.*comptoir/);
  expect(res.WT.corps.order_type, 'livraison = order_type 5').toBe(5);
  expect(res.WT.corps.payment_method).toBe(1);
  expect(res.codeTitreRestaurantOuALaLivraison.www).toBe(false);
  expect(res.codeTitreRestaurantOuALaLivraison.arbreTravail).toBe(false);
});

// ───────────────────────────── R7 : reçu / QR d'avis ─────────────────────────────
test('A-R7 reçu et QR : confirmation APP (une commande de retrait E2E-APP-) ; QR = retrait ; aucun QR/lien d\'avis', async () => {
  test.skip(!L.jeton(), 'jeton E2E-APP- absent');
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  await ouvrir(page, APP, true); await versLeTunnel(page, 'Chicken Burger');
  const note = page.locator('textarea').first();
  if (await note.count()) await note.fill('E2E-APP- etat des lieux 2026-10-23 (lecture seule, retrait)');
  const attente = page.waitForResponse((r) => /\/api\/frontend\/order$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30_000 }).catch(() => null);
  await page.locator('button.lcf-cta-bar-next', { hasText: /Confirmer ma commande/ }).click();
  const rep = await attente; let corps = null;
  try { corps = rep ? await rep.json() : null; } catch (_e) { corps = null; }
  await page.waitForTimeout(3500);
  const info = await page.evaluate(() => ({
    texte: document.body.innerText,
    qr: Array.from(document.querySelectorAll('canvas,svg,img')).filter((e) => /qr/i.test((e.className && e.className.baseVal !== undefined ? e.className.baseVal : e.className) + ' ' + (e.getAttribute('alt') || '') + ' ' + (e.getAttribute('aria-label') || ''))).length,
    liens: Array.from(document.querySelectorAll('a[href]')).map((a) => a.href).filter((h) => !h.startsWith(location.origin)).slice(0, 12),
  }));
  await png(page, 'R7-APP-confirmation');
  const ids = corps && corps.data ? corps.data.id : null;
  const enBase = ids ? sql(`select id, total, order_type, payment_method, source from orders where id=${ids}`) : null;
  J.note('R7.confirmation', {
    statut: rep ? rep.status() : null, commandeId: ids, enBase,
    avisDansTexte: (info.texte.match(/.{0,30}\bavis\b.{0,30}/gi) || []).slice(0, 5), qrElements: info.qr, liensExternes: info.liens,
    extrait: info.texte.split('\n').filter((l) => /commande|retrait|QR|code|merci|suivi|avis/i.test(l)).slice(0, 14),
    sourceRecu: 'aucune page d\'avis dans app/www (ls *.html) : ' + fs.readdirSync(path.join(SITE_REPO, 'app/www')).filter((f) => /avis/i.test(f)).join(',') + '|',
  });
  expect(rep && rep.status(), JSON.stringify(corps).slice(0, 200)).toBe(201);
  expect(/QR|code de retrait|montrez|présentez|N°/i.test(info.texte), 'la confirmation affiche un code de retrait').toBe(true);
  expect(info.texte.match(/laisser un avis|donner votre avis|donnez votre avis|votre avis/i), 'aucune invitation à l\'avis sur la confirmation').toBeNull();
  expect(fs.readdirSync(path.join(SITE_REPO, 'app/www')).filter((f) => /avis/i.test(f))).toEqual([]);
  await ctx.close();
});

// ───────────────────────────── Défaut trouvé en route : le Cayenne (1er produit) ne peut pas être commandé dans app/www ─────────────────────────────
test('A-CAYENNE la fiche embarquée exige 2 viandes (data/menu.js non commité : viandes:2), le serveur n\'en accepte qu\'1 => 422', async () => {
  const lireViandes = (f) => { const m = fs.readFileSync(f, 'utf8').match(/mkItem\(101, 'cayenne'[\s\S]{0,900}?viandes: (\d)/); return m ? Number(m[1]) : null; };
  const git = (...a) => execFileSync('git', ['-C', SITE_REPO, ...a], { encoding: 'utf8' });
  const commite = (ref) => { const m = git('show', `${ref}:data/menu.js`).match(/mkItem\(101, 'cayenne'[\s\S]{0,900}?viandes: (\d)/); return m ? Number(m[1]) : null; };
  const statique = { www: lireViandes(path.join(SITE_REPO, 'app/www/data/menu.js')), arbreTravail: lireViandes(path.join(SITE_REPO, 'data/menu.js')), HEAD: commite('HEAD'), originMain: commite('origin/main') };
  const res = {};
  for (const [nom, site] of [['CTRL', CTRL], ['APP', APP]]) {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
    await ouvrir(page, site, true);
    await onglet(page, 'Menu').click(); await page.waitForTimeout(1000);
    await page.locator('button.lc-card-item-name:not([disabled])', { hasText: /^Cayenne$/ }).first().click(); await page.waitForTimeout(1000);
    await page.locator('button', { hasText: /^\s*Personnaliser/ }).first().click(); await page.waitForTimeout(1000);
    const etapes = [];
    for (let i = 0; i < 20; i++) {
      const cta = page.locator('.lc-wiz-foot-next').first();
      if (!(await cta.count())) break;
      const txt = ((await cta.innerText()) || '').replace(/\s+/g, ' ').trim();
      if ((await cta.getAttribute('aria-disabled')) === 'true') {
        const entete = await page.evaluate(() => (document.body.innerText.match(/\d+ sélectionnés?\s*·\s*\d+ minimum\s*·\s*\d+ maximum/) || [''])[0]);
        etapes.push('BLOQUÉ: ' + entete);
        const o = page.locator('button.lc-wiz-choice:not(.is-on):visible').first();
        if (!(await o.count())) break;
        await o.click(); await page.waitForTimeout(350); continue;
      }
      etapes.push(txt.slice(0, 30)); await cta.click(); await page.waitForTimeout(700);
      if (/Ajouter au panier/.test(txt)) break;
    }
    await nonMerci(page);
    const barre = page.locator('.lc-menu-cartbar');
    if (await barre.count()) await barre.click(); else await page.locator('.lc-nav-btn-cart').click();
    await page.waitForTimeout(800);
    await page.locator('button:visible', { hasText: /^\s*(Passer commande|Commander)/ }).first().click(); await page.waitForTimeout(1400); await nonMerci(page);
    if (nom === 'CTRL') await page.route('**/api/frontend/order', (route) => route.abort()); // témoin : on ne crée aucune commande
    const attente = page.waitForResponse((r) => /\/api\/frontend\/order$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }).catch(() => null);
    await page.locator('button.lcf-cta-bar-next', { hasText: /Confirmer ma commande/ }).click();
    const rep = await attente; let corps = null; try { corps = rep ? await rep.json() : null; } catch (_e) { corps = null; }
    await page.waitForTimeout(1500);
    await page.waitForTimeout(1200);
    const visible = await page.evaluate(() => ({
      alertes: Array.from(document.querySelectorAll('[role=alert],[role=status],[class*=toast],[class*=error]')).filter((e) => e.getBoundingClientRect().height > 0).map((e) => e.innerText.replace(/\s+/g, ' ').slice(0, 160)),
      texteErreurDansPage: /Sélectionnez|au maximum|comprend 1 viande|impossible|erreur/i.test(document.body.innerText), scrollY: Math.round(scrollY),
      cta: (document.querySelector('button.lcf-cta-bar-next') || { innerText: '' }).innerText.replace(/\s+/g, ' '),
    }));
    res[nom] = { visible, etapes, statut: rep ? rep.status() : null, message: corps && corps.message ? String(corps.message).slice(0, 160) : null, erreurs: corps && corps.errors ? JSON.stringify(corps.errors).slice(0, 300) : null, commandeId: corps && corps.data ? corps.data.id : null };
    if (nom === 'APP') await png(page, 'R7-APP-cayenne-422');
    await ctx.close();
  }
  J.note('CAYENNE.422', { statique, res });
  expect(statique.HEAD, 'commité : Cayenne mono-viande (viandes:0)').toBe(0);
  expect(statique.originMain).toBe(0);
  expect(statique.www, 'embarqué dans app/www : viandes:2').toBe(2);
  expect(res.APP.etapes.join(' ')).toMatch(/BLOQUÉ: .*2 minimum/);
  expect(res.APP.statut, JSON.stringify(res.APP)).toBe(422);
  expect(res.APP.erreurs).toMatch(/Viande/);
  expect(res.CTRL.etapes.join(' ')).not.toMatch(/BLOQUÉ/);
});

// ───────────────────────────── (c) permissions déclarées ─────────────────────────────
test('A-PERM permissions : ni localisation Android ni NSLocation iOS ; INTERNET + notifications ; pas de windowSoftInputMode', async () => {
  const man = fs.readFileSync(path.join(SITE_REPO, 'app/android/app/src/main/AndroidManifest.xml'), 'utf8');
  const plist = fs.readFileSync(path.join(SITE_REPO, 'app/ios/App/App/Info.plist'), 'utf8');
  const cap = JSON.parse(fs.readFileSync(path.join(SITE_REPO, 'app/capacitor.config.json'), 'utf8'));
  const wwwSrc = ['compiled/funnel.js', 'compiled/screens.js', 'compiled/racine.js', 'api.js', 'app-native.js'].map((f) => fs.readFileSync(path.join(SITE_REPO, 'app/www', f), 'utf8')).join('\n');
  const perms = [...man.matchAll(/<uses-permission android:name="([^"]+)"(?![^>]*tools:node="remove")/g)].map((m) => m[1]);
  const constat = {
    androidPermissions: perms, localisationAndroid: /ACCESS_(FINE|COARSE)_LOCATION/.test(man), windowSoftInputMode: /windowSoftInputMode/.test(man),
    iosNSLocation: /NSLocation/.test(plist), iosCles: [...plist.matchAll(/<key>(NS[A-Za-z]+)<\/key>/g)].map((m) => m[1]),
    clavierCapacitor: cap.plugins.Keyboard, usesGeolocationDansWww: /navigator\.geolocation/.test(wwwSrc),
  };
  J.note('PERM', constat);
  expect(perms).toEqual(expect.arrayContaining(['android.permission.INTERNET', 'android.permission.POST_NOTIFICATIONS']));
  expect(constat.localisationAndroid).toBe(false);
  expect(constat.iosNSLocation).toBe(false);
  expect(constat.usesGeolocationDansWww, 'le code n\'appelle pas la géolocalisation : cohérent avec les permissions').toBe(false);
  expect(cap.plugins.Keyboard.resize).toBe('native');
});
