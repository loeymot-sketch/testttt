// [SITE LIVRAISON 2026-10-23] La livraison du SITE (tunnel de commande de lecayenne.fr) : présente ET correcte.
// Couvre S-101 (viandes du Cayenne/Classique/XL), S-001 (confirmation + suivi d'une livraison), S-002 (libellés de paiement),
// S-003 (adresse refusée), S-004 (anti-rafale des suggestions), S-006 (champ adresse mobile), S-102 (viandes réelles),
// S-103 (refus serveur visible). Chaque assertion critique est bloquante (aucun `.catch(() => {})` sur une assertion).
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   # servir la copie corrigée sur :8013 (cf. scratchpad/rebuild-site-work.sh : metas api-base-url + api-key réécrites)
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/site-livraison-2026-10-23/site-livraison.spec.js --workers=1 --retries=0 --reporter=line
//
// Variables : S_SITE_DIR = dossier servi sur :8013 (défaut : scratchpad/site-work) ; S_WORKING_COPY = dépôt du site (pour
// l'empreinte de compiled/funnel.js) ; S_MUTANT=1 désactive le garde d'instrument « le site servi est la copie corrigée »
// (utilisé pour prouver que chaque test MORD sur un fichier d'avant-correctif).
//
// Instrument :
//  - Garde réseau : toute requête vers la production (vps-418872ac, ovh.net, lecayenne.fr/api) est BLOQUÉE et consignée ; le test
//    final exige 0.
//  - Les commandes de test (préfixe E2E-SITE-LIV-) sont ANNULÉES en fin de passe (API admin, statut 16 + motif).
//  - Disque contraint : ≤ 15 PNG (les états sans PNG écrivent tout de même DOM + console + réseau).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');

const REPO = path.resolve(__dirname, '..', '..', '..');
const SITE = 'http://127.0.0.1:8013';
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const OUT = path.join(REPO, 'reports', 'test-e2e', 'site-livraison-2026-10-23', 'captures');
fs.mkdirSync(OUT, { recursive: true });
const SCRATCH = '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/2c3726ad-78a8-4013-b7aa-66c1e1619e24/scratchpad';
const SERVED = process.env.S_SITE_DIR || path.join(SCRATCH, 'site-work');
const WORKING_COPY = process.env.S_WORKING_COPY || '/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne';
const PROD_RX = /vps-418872ac|ovh\.net|(^|\.)lecayenne\.fr\/api/i;

function envValue(key) {
  const m = fs.readFileSync(path.join(REPO, '.env'), 'utf8').match(new RegExp(`^${key}=(.*)$`, 'm'));
  return m ? m[1].trim().replace(/^"|"$/g, '') : '';
}
const API_KEY = envValue('MIX_API_KEY');
const NOW = Date.now();
const TAG = `E2E-SITE-LIV-${String(NOW).slice(-6)}`;
const PNG_CAP = 15;
let pngUsed = 0;

test.describe.configure({ mode: 'serial' });
test.setTimeout(Number(process.env.S_TIMEOUT) || 420_000);
test.use({ video: 'off', trace: 'off', screenshot: 'off', locale: 'fr-FR', timezoneId: 'Europe/Paris',
  launchOptions: { args: ['--lang=fr-FR', '--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks'] } });

// ---------------------------------------------------------------- registre
const L = { tag: TAG, startedAt: new Date().toISOString(), states: [], checks: [], prodBloquees: [], orders: [], notes: [] };
const note = (s) => { L.notes.push(s); console.log('[SL] ' + s); };
const writeLedger = () => fs.writeFileSync(path.join(OUT, 'ledger.json'), JSON.stringify(L, null, 2));
function check(id, ok, detail) { L.checks.push({ id, ok: !!ok, detail }); console.log(`[SL CHECK] ${ok ? 'OK ' : 'KO '} ${id} ${detail !== undefined ? JSON.stringify(detail).slice(0, 300) : ''}`); writeLedger(); }
function mysql(sql) { return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], { encoding: 'utf8', timeout: 20_000 }).trim(); }
const norm = (s) => String(s || '').replace(/[  ]/g, ' ').replace(/\s+/g, ' ').trim();
const euros = (s) => [...norm(s).matchAll(/(\d+)[.,](\d{2})\s*€/g)].map((m) => Number(`${m[1]}.${m[2]}`));
const lastEuro = (s) => { const a = euros(s); return a.length ? a[a.length - 1] : NaN; };
const sha16 = (p) => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex').slice(0, 16);
function resetLimiteurs() {
  execFileSync('php', ['artisan', 'tinker', '--execute', `$l = app(\\Illuminate\\Cache\\RateLimiter::class); $l->clear(sha1("|127.0.0.1")); $l->clear("otp-verify".sha1("|127.0.0.1")); $l->clear("signup-register".sha1("|127.0.0.1")); foreach (['delivery-suggest','delivery-quote','delivery-status','delivery-waves'] as $n) { foreach (['127.0.0.1','::1'] as $ip) { $l->clear(md5($n.$ip)); } } echo 'ok';`], { cwd: REPO, encoding: 'utf8', timeout: 30_000 });
}

// ---------------------------------------------------------------- instruments
let AUTH = null; // { token, phone } : un seul compte invité pour toute la passe
async function newCtx(browser, { mobile = false, suspendue = false } = {}) {
  const ctx = await browser.newContext(mobile
    ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1, locale: 'fr-FR', timezoneId: 'Europe/Paris' }
    : { viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  await ctx.route((url) => PROD_RX.test(url.href), (route) => { L.prodBloquees.push(route.request().url().slice(0, 200)); writeLedger(); return route.abort('blockedbyclient'); });
  // Mollie : jamais chargé en test (le paiement carte en ligne n'est pas exercé ici).
  await ctx.route((url) => /js\.mollie\.com/.test(url.href), (route) => route.abort('blockedbyclient'));
  if (suspendue) {
    // Le serveur dit « suspendue » : on ne change JAMAIS le réglage réel, on intercepte la lecture du statut.
    await ctx.route((url) => /\/api\/frontend\/delivery\/status/.test(url.href), (route) => route.fulfill({
      status: 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify({ status: true, enabled: false, grid: null }),
    }));
  }
  if (AUTH) await ctx.addInitScript(([t, p]) => { try { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); } catch (_e) { /* storage */ } }, [AUTH.token, AUTH.phone]);
  return ctx;
}
function recorder(page) {
  const r = attachMegaAuditRecorder(page, OUT);
  return { ...r, snap: async (name, png = true) => {
    if (png && pngUsed < PNG_CAP) { pngUsed += 1; return r.snap(name); }
    const orig = page.screenshot.bind(page);
    page.screenshot = async () => Buffer.alloc(0);
    try { return await r.snap(name); } finally { page.screenshot = orig; }
  } };
}
async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: 3_500 }).catch(() => { /* polling : on continue après une pause */ });
  await page.waitForTimeout(900);
}
async function gotoSite(page, hash = '') {
  // Chargement COMPLET à chaque fois : un `goto` vers la même URL à ancre près est une navigation intra-document (aucun rechargement),
  // et l'assistant resterait dans l'état du produit précédent (défaut d'état hérité déjà relevé par l'audit précédent, hors périmètre ici).
  await page.goto('about:blank');
  await page.goto(`${SITE}/${hash}`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 60_000 });
  await settle(page);
}
const visibleText = (page) => page.evaluate(() => String(document.body.innerText || '').replace(/[  ]/g, ' '));
async function state(page, rec, id, extra = {}, png = true) {
  await settle(page);
  const text = await visibleText(page);
  await rec.snap(id, png);
  let net = []; let con = [];
  try { net = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.network.json`), 'utf8')); } catch (_e) { /* quatuor absent */ }
  try { con = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.console.json`), 'utf8')); } catch (_e) { /* quatuor absent */ }
  const tous4xx = net.filter((x) => x.status >= 400).map((x) => `${x.method} ${x.url.replace(BASE, '').replace(SITE, '').slice(0, 110)} -> ${x.status}`);
  // Bruit d'ENVIRONNEMENT, connu et documenté (reports/test-e2e/etat-des-lieux-2026-10-23/S-site.md §1) : en local, l'envoi du code
  // e-mail échoue (« Le code n'a pas pu être envoyé », 422 sur guest-signup/email-login — pas de SMTP). Le code est lu en base.
  // Ce 422 et sa ligne « Failed to load resource » sont écartés ; TOUT AUTRE 4xx / erreur de console reste compté.
  const bruitEnv = tous4xx.filter((x) => /guest-signup\/email-login -> 422/.test(x));
  const http4xx = tous4xx.filter((x) => !bruitEnv.includes(x));
  let aEcarter = bruitEnv.length;
  const conErr = con.filter((c) => /error|pageerror/.test(c.level)).map((c) => c.text.slice(0, 200))
    .filter((t) => { if (aEcarter > 0 && /Failed to load resource.*\b422\b/.test(t)) { aEcarter -= 1; return false; } return true; });
  const entry = { id, url: page.url().replace(SITE, ''), ...extra, http4xx, consoleErrors: conErr, bruitEnvironnement: bruitEnv };
  L.states.push(entry); writeLedger();
  return { entry, text };
}

// ---------------------------------------------------------------- pilotage du tunnel
async function readStep(page) {
  const r = await page.evaluate(() => {
    const t = (sel) => { const e = document.querySelector(sel); return e ? String(e.innerText || '').replace(/\s+/g, ' ').trim() : ''; };
    const next = document.querySelector('.lc-wiz-foot-next');
    return { title: t('.lc-wiz-title'), next: next ? String(next.innerText || '').replace(/\s+/g, ' ') : '', disabled: next ? (next.disabled || next.getAttribute('aria-disabled') === 'true') : null,
      choix: Array.from(document.querySelectorAll('.lc-wiz-choice')).map((e) => String(e.innerText || '').replace(/\s+/g, ' ').trim()),
      on: Array.from(document.querySelectorAll('.lc-wiz-choice.is-on')).map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 50)) };
  });
  return { ...r, total: lastEuro(r.next) };
}
/** Parcourt l'assistant. `viandes` : noms à cocher dans l'ordre à l'étape viande. Choisit « Algérienne » à l'étape sauce. */
async function marcherWizard(page, { viandes = null } = {}) {
  const journal = [];
  const titres = [];
  // l'assistant affiche « Chargement… » le temps de lire les choix réels du produit (≤ 2,5 s) : on attend sa première étape
  await page.locator('.lc-wiz-title').first().waitFor({ state: 'visible', timeout: 15_000 });
  for (let s = 0; s < 18; s++) {
    const step = await readStep(page);
    if (process.env.S_DEBUG) console.log('[WIZ]', s, step.title, '|', step.next, '| on:', step.on.join(','), '| nb choix:', step.choix.length);
    if (step.title && titres[titres.length - 1] !== step.title) titres.push(step.title);
    if (/Ajouter au panier/i.test(step.next)) { journal.push({ title: step.title, next: step.next, total: step.total }); return { recap: step, journal, titres }; }
    if (/viande/i.test(step.title) && !/suppl/i.test(step.title)) journal.push({ title: step.title, choix: step.choix });
    if (viandes && /viande/i.test(step.title) && !/suppl/i.test(step.title)) {
      for (const nom of viandes) {
        const c = page.locator('.lc-wiz-choice', { hasText: nom }).first();
        expect(await c.count(), `choix de viande « ${nom} » proposé par l'assistant`).toBeGreaterThan(0);
        if (!(await c.evaluate((e) => e.classList.contains('is-on')))) { await c.click(); await page.waitForTimeout(250); }
      }
    }
    if (/sauce/i.test(step.title)) {
      const alg = page.locator('.lc-wiz-choice', { hasText: /Algérienne/ }).first();
      if (await alg.count() && !(await alg.evaluate((e) => e.classList.contains('is-on')))) { await alg.click(); await page.waitForTimeout(250); }
    }
    for (let k = 0; k < 3 && (await readStep(page)).disabled; k++) {
      await page.locator('.lc-wiz-choice:not(.is-on)').first().click();
      await page.waitForTimeout(250);
    }
    const before = step.title;
    await page.locator('.lc-wiz-foot-next').click({ timeout: 10_000 });
    await page.waitForTimeout(400);
    const after = await readStep(page);
    if (after.title === before && /Ajouter au panier/i.test(after.next) === false) await page.waitForTimeout(400);
  }
  return { recap: null, journal, titres };
}
async function ajouterAuPanier(page) {
  await page.locator('.lc-wiz-foot-next').click({ timeout: 10_000 });
  const t0 = Date.now();
  while (Date.now() - t0 < 25_000) {
    if (await page.locator('.lc-cart-drawer.is-open').count()) return { ok: true };
    if (!(await page.locator('.lc-wiz').count())) return { ok: true };
    const alerte = page.locator('.lc-wiz [role=alert]');
    const err = (await alerte.count()) ? await alerte.first().innerText({ timeout: 1500 }).catch(() => '') : '';
    if (err && /plus disponible|pas proposée|combinaison|introuvable/i.test(err)) return { ok: false, erreur: norm(err) };
    await page.waitForTimeout(250);
  }
  return { ok: false, erreur: 'délai dépassé' };
}
async function ouvrirPanier(page) {
  const drawer = page.locator('.lc-cart-drawer.is-open');
  if (!(await drawer.count())) { await page.waitForTimeout(800); await page.locator('.lc-nav-btn-cart').first().click(); }
  await expect(drawer).toBeVisible({ timeout: 15_000 });
  return drawer;
}
async function versPageCommande(page) {
  for (let i = 0; i < 6 && !(await page.locator('.lcf-page').count()); i++) {
    const b = page.getByRole('button', { name: /Non merci|Continuer|Régler ma commande/ }).first();
    if (await b.isVisible({ timeout: 1500 }).catch(() => false)) await b.click();
    await page.waitForTimeout(1200);
  }
  await expect(page.locator('.lcf-page')).toBeVisible({ timeout: 20_000 });
  await settle(page);
}
/** Ajoute `nom` au panier (assistant complet). Rend { recap, journal }. */
async function ajouterProduit(page, nom, opts = {}) {
  await gotoSite(page, '#menu');
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30_000 });
  await page.locator(`[aria-label="Voir ${nom}"]`).first().click();
  await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 15_000 });
  await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
  await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15_000 });
  const w = await marcherWizard(page, opts);
  expect(w.recap, `récapitulatif de l'assistant atteint pour ${nom}`).toBeTruthy();
  const ajout = await ajouterAuPanier(page);
  return { ...w, ajout };
}
async function panierVersCommande(page) {
  const drawer = await ouvrirPanier(page);
  await page.waitForTimeout(500);
  const panierTxt = norm(await drawer.innerText());
  await drawer.getByRole('button', { name: /Passer commande/ }).click();
  await page.waitForTimeout(1200);
  await versPageCommande(page);
  return panierTxt;
}
async function choisirLivraison(page) {
  const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
  await expect(rg).toBeVisible({ timeout: 15_000 });
  await rg.getByRole('radio', { name: /Livraison/ }).click();
  await expect(page.locator('input[aria-label="Adresse de livraison"]')).toBeVisible({ timeout: 10_000 });
}
/** Saisit une adresse comme un client (frappe), choisit la suggestion qui correspond à `motif`, calcule. Rend {options, choisi, quote, status}. */
async function saisirAdresse(page, saisie, motif) {
  const input = page.locator('input[aria-label="Adresse de livraison"]');
  await input.scrollIntoViewIfNeeded();
  await input.fill('');
  await page.waitForTimeout(200);
  const sugg = page.waitForResponse((r) => /\/delivery\/suggest/.test(r.url()), { timeout: 20_000 });
  await input.pressSequentially(saisie, { delay: 25 });
  await sugg;
  await page.waitForTimeout(700);
  const options = await page.evaluate(() => Array.from(document.querySelectorAll('#lcf-delivery-suggest option')).map((o) => o.value));
  expect(options.length, `suggestions pour « ${saisie} »`).toBeGreaterThan(0);
  const choisi = options.find((o) => motif.test(o)) || options[0];
  await input.fill(choisi); // un <datalist> natif ne se pilote pas au clic : on pose la valeur exacte d'une option, comme le navigateur
  await page.waitForTimeout(500);
  const quoteP = page.waitForResponse((r) => /\/delivery\/quote/.test(r.url()) && r.request().method() === 'POST', { timeout: 25_000 });
  await page.getByRole('button', { name: /^Calculer$/ }).click();
  const qr = await quoteP;
  const quote = await qr.json().catch(() => null);
  await page.waitForTimeout(900);
  return { options, choisi, quote, status: qr.status() };
}
async function authentifier(page, rq, email, phone, prenom) {
  await page.locator('#auth-email').fill(email);
  await page.getByRole('button', { name: /Recevoir le code par email/ }).click();
  await expect(page.locator('[data-testid="funnel-identite"]')).toBeVisible({ timeout: 20_000 });
  await page.locator('#auth-first').fill(prenom);
  if (await page.locator('#auth-last').count()) await page.locator('#auth-last').fill('Test');
  await page.locator('#auth-phone').fill(phone);
  const r = page.waitForResponse((x) => x.request().method() === 'POST' && /guest-signup\/email-login/.test(x.url()), { timeout: 60_000 });
  await page.getByRole('button', { name: /Créer mon compte et recevoir le code/ }).click();
  await r;
  const token = mysql(`SELECT token FROM otps WHERE phone='${phone}' ORDER BY created_at DESC LIMIT 1`);
  expect(token, 'code OTP enregistré en base').toMatch(/^\d{4}$/);
  resetLimiteurs();
  const v = await rq.post(`${BASE}/api/auth/guest-signup/verify`, { headers: { 'X-API-Key': API_KEY, Accept: 'application/json' }, data: { token, phone, code: '+33', email, first_name: prenom } });
  const vj = await v.json().catch(() => ({}));
  expect(v.status(), JSON.stringify(vj).slice(0, 200)).toBeLessThan(300);
  await page.evaluate(([t, p]) => { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); }, [vj.token, phone]);
  AUTH = { token: vj.token, phone };
}
/** Identifie le client si nécessaire (un seul compte pour la passe). */
async function sIdentifier(page, rq, tag) {
  if (AUTH) { await page.waitForTimeout(300); return; }
  resetLimiteurs();
  await authentifier(page, rq, `${TAG.toLowerCase()}-${tag}@lecayenne-test.fr`, `0698${String(NOW).slice(-6)}`, `${TAG} Client`);
}
async function adminToken(rq) {
  const r = await rq.post(`${BASE}/api/auth/login`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { email: 'admin@lecayenne.fr', password: '123456' } });
  const j = await r.json().catch(() => ({}));
  return j.token || (j.data && j.data.token) || null;
}
const ROW_COLS = ['id', 'order_type', 'payment_method', 'payment_status', 'status', 'subtotal', 'discount', 'delivery_charge', 'total'];
function ligneBase(id) {
  const row = mysql(`SELECT ${ROW_COLS.join(', ')} FROM orders WHERE id=${Number(id)}`).split('\t');
  return Object.fromEntries(ROW_COLS.map((c, i) => [c, row[i]]));
}
/** Clique « Confirmer » et rend la réponse de POST /api/frontend/order + le corps envoyé. */
async function confirmer(page, label) {
  let envoye = null;
  const onReq = (req) => { if (req.method() === 'POST' && /\/api\/frontend\/order$/.test(req.url())) { try { envoye = JSON.parse(req.postData() || '{}'); } catch (_e) { envoye = null; } } };
  page.on('request', onReq);
  const orderResp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 90_000 });
  await page.locator('.lcf-cta-bar-next').click();
  const resp = await orderResp;
  page.off('request', onReq);
  const j = await resp.json().catch(() => ({}));
  const d = (j && j.data) || j || {};
  const o = { label, http: resp.status(), id: d.id, serial: d.order_serial_no, totalApi: Number(d.total), corps: resp.status() >= 400 ? JSON.stringify(j).slice(0, 500) : undefined, envoye };
  if (o.id) L.orders.push({ id: o.id, label });
  writeLedger();
  note(`POST /api/frontend/order (${label}) -> HTTP ${o.http} id=${o.id} total=${o.totalApi} ${o.corps || ''}`);
  return o;
}

const RETRAIT_RX = /Présente ce QR|Présente ton QR|Tu paies sur place|Ticket retrait|Prête dans|Récupéré|QR de retrait|Tu peux venir la chercher|au comptoir/i;

// ============================================================================
let rq;
test.describe('Site — livraison : présente et correcte (copie corrigée servie sur :8013)', () => {
  test.beforeAll(async ({ playwright }) => {
    rq = await playwright.request.newContext();
  });
  test.afterAll(async () => {
    try {
      const tok = await adminToken(rq);
      for (const o of L.orders.filter((x) => x.id)) {
        const r = await rq.post(`${BASE}/api/admin/online-order/change-status/${o.id}`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', Authorization: `Bearer ${tok}`, 'X-Idempotency-Key': crypto.randomUUID() }, data: { status: 16, reason: `${TAG} test automatique` } });
        o.annulation = r.status(); if (r.status() >= 300) o.annulationCorps = (await r.text()).slice(0, 300);
        o.statutApres = mysql(`SELECT status FROM orders WHERE id=${Number(o.id)}`);
        note(`annulation commande de test ${o.id} -> HTTP ${o.annulation}, status=${o.statutApres} ${o.annulationCorps || ''}`);
      }
    } catch (e) { note('annulation impossible : ' + String(e.message).slice(0, 160)); }
    L.finishedAt = new Date().toISOString(); L.pngUtilises = pngUsed;
    writeLedger();
    if (rq) await rq.dispose().catch(() => {});
  });

  // ------------------------------------------------------------------------
  test('0. instrument : le site servi sur :8013 est la copie corrigée, l’API locale répond, la livraison est active', async () => {
    const idx = fs.readFileSync(path.join(SERVED, 'index.html'), 'utf8');
    const meta = (n) => (idx.match(new RegExp(`<meta name="${n}" content="([^"]*)"`)) || [null, null])[1];
    expect(meta('api-base-url'), 'le site servi parle à l’API LOCALE').toBe('http://127.0.0.1:8766');
    expect(meta('api-key') === API_KEY, 'clé d’API locale').toBe(true);
    const servi = await rq.get(`${SITE}/compiled/funnel.js`);
    expect(servi.status()).toBe(200);
    const shaServi = crypto.createHash('sha256').update(await servi.body()).digest('hex').slice(0, 16);
    const shaCopie = sha16(path.join(WORKING_COPY, 'compiled', 'funnel.js'));
    L.instrument = { shaServi, shaCopie, balise: (idx.match(/compiled\/funnel\.js\?v=[^"']+/) || [null])[0], mutant: !!process.env.S_MUTANT };
    if (!process.env.S_MUTANT) expect(shaServi, 'le funnel servi est le compilé de la copie de travail').toBe(shaCopie);
    const st = await rq.get(`${BASE}/api/frontend/delivery/status`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } });
    const sj = await st.json();
    L.instrument.statutServeur = sj;
    expect(sj.enabled, 'précondition : le serveur annonce la livraison ACTIVE (réglage jamais modifié par ce spec)').toBe(true);
    writeLedger();
  });

  // ------------------------------------------------------------------------
  for (const mobile of [false, true]) {
    const vp = mobile ? 'M' : 'D';
    test(`1${mobile ? 'b' : 'a'}. ${vp} (a) la tuile « Livraison » suit le serveur : active → tuile ; suspendue → lien Uber Eats`, async ({ browser }) => {
      // --- livraison ACTIVE
      let ctx = await newCtx(browser, { mobile });
      let page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      let rec = recorder(page);
      await ajouterProduit(page, 'Cheese Burger');
      await panierVersCommande(page);
      const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
      await expect(rg).toBeVisible({ timeout: 15_000 });
      const radios = (await rg.locator('[role="radio"]').allInnerTexts()).map(norm);
      const act = await state(page, rec, `${vp}-01-livraison-active`, { radios }, !mobile);
      check(`${vp} (a) active : 2 tuiles (À emporter + Livraison « Dès 3,90 € »)`, radios.length === 2 && /Livraison/.test(radios[1]) && /3,90/.test(radios[1]), radios);
      expect(radios.length).toBe(2);
      expect(radios[1]).toMatch(/Livraison/);
      expect(radios[1]).toMatch(/3,90/);
      expect(radios[1], 'bloc livraison au vouvoiement').toMatch(/votre commune/);
      check(`${vp} (a) active : console propre`, act.entry.consoleErrors.length === 0, act.entry.consoleErrors);
      expect(act.entry.consoleErrors).toEqual([]);
      rec.dispose(); await ctx.close();

      // --- livraison SUSPENDUE (le serveur dit enabled:false)
      ctx = await newCtx(browser, { mobile, suspendue: true });
      page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      rec = recorder(page);
      await ajouterProduit(page, 'Cheese Burger');
      await panierVersCommande(page);
      const rg2 = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
      await expect(rg2).toBeVisible({ timeout: 15_000 });
      const radios2 = (await rg2.locator('[role="radio"]').allInnerTexts()).map(norm);
      const liens = await page.locator('a[aria-label*="Uber Eats"]').evaluateAll((as) => as.map((a) => ({ txt: a.innerText.replace(/\s+/g, ' ').trim(), href: a.getAttribute('href') })));
      const champ = await page.locator('input[aria-label="Adresse de livraison"]').count();
      const sus = await state(page, rec, `${vp}-02-livraison-suspendue`, { radios: radios2, liens }, !mobile);
      check(`${vp} (a) suspendue : 1 seule tuile radio + lien Uber Eats, aucun champ adresse`, radios2.length === 1 && liens.length === 1 && /ubereats\.com/.test(liens[0].href) && champ === 0, { radios2, liens, champ });
      expect(radios2.length).toBe(1);
      expect(liens.length).toBe(1);
      expect(liens[0].href).toMatch(/ubereats\.com/);
      expect(champ).toBe(0);
      expect(sus.entry.consoleErrors).toEqual([]);
      rec.dispose(); await ctx.close();
    });
  }

  // ------------------------------------------------------------------------
  for (const mobile of [false, true]) {
    const vp = mobile ? 'M' : 'D';
    test(`2${mobile ? 'b' : 'a'}. ${vp} (g)(b)(c)(S-006) adresse : saisie rapide sans rafale, devis Hénin cohérent, Lille refusé`, async ({ browser }) => {
      const ctx = await newCtx(browser, { mobile });
      const page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      const rec = recorder(page);
      const suggests = [];
      page.on('response', (r) => { if (/\/delivery\/suggest/.test(r.url())) suggests.push(r.status()); });
      await ajouterProduit(page, 'Cheese Burger');
      const panierTxt = await panierVersCommande(page);
      const sousTotal = lastEuro(panierTxt);
      await choisirLivraison(page);
      resetLimiteurs();

      // ---- (g) saisie rapide : pas de 429, requêtes limitées
      const input = page.locator('input[aria-label="Adresse de livraison"]');
      suggests.length = 0;
      await input.pressSequentially('12 rue Elie Gruyelle 62110 Henin-Beaumont', { delay: 25 });
      await page.waitForTimeout(1800);
      const nbSuggest = suggests.length;
      const nb429 = suggests.filter((s) => s === 429).length;
      check(`${vp} (g) saisie rapide de 42 caractères : ≤ 3 requêtes de suggestion, 0 réponse 429`, nbSuggest <= 3 && nb429 === 0, { nbSuggest, nb429 });
      expect(nb429, 'aucune réponse 429').toBe(0);
      expect(nbSuggest, 'nombre de requêtes de suggestion pour une saisie rapide').toBeLessThanOrEqual(3);
      const options = await page.evaluate(() => Array.from(document.querySelectorAll('#lcf-delivery-suggest option')).map((o) => o.value));
      expect(options.length, 'des suggestions sont bien proposées après la saisie').toBeGreaterThan(0);

      // ---- (S-006) champ lisible, non tronqué
      const champ = await input.evaluate((e) => { const cs = getComputedStyle(e); return { scrollW: e.scrollWidth, clientW: e.clientWidth, w: Math.round(e.getBoundingClientRect().width), casse: cs.textTransform }; });
      const largeurVue = await page.evaluate(() => window.innerWidth);
      if (mobile) {
        check('M (S-006) champ adresse : saisie de 42 caractères visible en entier (largeur ≥ 300 px, casse normale)', champ.w >= 300 && champ.casse === 'none', champ);
        expect(champ.w, 'largeur du champ adresse sur mobile').toBeGreaterThanOrEqual(300);
      }
      expect(champ.casse, 'adresse en casse normale').toBe('none');
      if (mobile) {
        // L'adresse de la plainte (S-006) : « 1 rue Jean Jaurès 62590 Oignies » doit tenir en entier dans le champ.
        await input.fill('1 rue Jean Jaures 62590 Oignies');
        const c2 = await input.evaluate((e) => ({ scrollW: e.scrollWidth, clientW: e.clientWidth }));
        check('M (S-006) « 1 rue Jean Jaures 62590 Oignies » tient en entier dans le champ (scrollWidth ≤ clientWidth)', c2.scrollW <= c2.clientW + 1, c2);
        expect(c2.scrollW, 'saisie non tronquée').toBeLessThanOrEqual(c2.clientW + 1);
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'pas de défilement horizontal').toBeLessThanOrEqual(1);
      if (mobile) await state(page, rec, `${vp}-03-champ-adresse`, { champ, largeurVue }, true);

      // ---- (b) Hénin-Beaumont : devis 3,90 € cohérent avec POST /delivery/quote
      const henin = await saisirAdresse(page, '12 rue Elie Gruyelle 62110 Henin-Beaumont', /Gruyelle/i);
      expect(henin.status).toBe(200);
      expect(henin.quote.allowed).toBe(true);
      const feeHenin = Number(henin.quote.fee);
      resetLimiteurs();
      const q2 = await rq.post(`${BASE}/api/frontend/delivery/quote`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { q: henin.choisi } });
      const q2j = await q2.json();
      const section = page.locator('.lcf-section', { has: input });
      const secTxt = norm(await section.innerText());
      const recapTxt = norm(await page.locator('.lcf-summary').innerText());
      const cta = norm(await page.locator('.lcf-cta-bar-next').innerText());
      const hen = await state(page, rec, `${vp}-04-devis-henin`, { fee: feeHenin }, !mobile);
      check(`${vp} (b) Hénin : frais serveur == 3,90 €`, feeHenin === 3.9 && Number(q2j.fee) === 3.9, { quoteUI: feeHenin, quoteApi: q2j.fee });
      check(`${vp} (b) Hénin : frais affichés (section, récap) == devis du serveur`, lastEuro(secTxt.match(/Livraison [^€]*€/)?.[0]) === feeHenin && /Livraison\s*3,90\s*€/.test(recapTxt), { secTxt: secTxt.slice(0, 260), recapTxt: recapTxt.slice(0, 200) });
      check(`${vp} (b) Hénin : bouton Confirmer == sous-total + frais`, Math.abs(lastEuro(cta) - (sousTotal + feeHenin)) < 0.005, { cta, sousTotal, feeHenin });
      expect(feeHenin).toBe(3.9);
      expect(Number(q2j.fee)).toBe(feeHenin);
      expect(secTxt).toMatch(/Livraison\s*3,90\s*€/);
      expect(recapTxt).toMatch(/Livraison\s*3,90\s*€/);
      expect(Math.abs(lastEuro(cta) - (sousTotal + feeHenin))).toBeLessThan(0.005);
      expect(hen.entry.consoleErrors).toEqual([]);

      // ---- (c) Lille : refus + Uber Eats, pas de « Offerte », bouton bloqué
      resetLimiteurs();
      const lille = await saisirAdresse(page, '10 rue Nationale 59000 Lille', /Lille/i);
      expect(lille.quote.allowed, 'Lille est hors zone (serveur)').toBe(false);
      const secL = norm(await section.innerText());
      const recapL = norm(await page.locator('.lcf-summary').innerText());
      const ctaBtn = page.locator('.lcf-cta-bar-next');
      const ctaL = norm(await ctaBtn.innerText());
      const disabledL = await ctaBtn.isDisabled();
      const lienUber = await section.locator('a[href*="ubereats"]').count();
      const lil = await state(page, rec, `${vp}-05-lille-refus`, { quote: { allowed: lille.quote.allowed, fee: lille.quote.fee } }, !mobile);
      const pageTxt = norm(await page.locator('.lcf-page').innerText());
      check(`${vp} (c) Lille : refus affiché + lien Uber Eats`, /trop loin/i.test(secL) && lienUber >= 1, { secL: secL.slice(0, 260), lienUber });
      check(`${vp} (c) Lille : aucun « Offerte » nulle part`, !/Offerte/i.test(pageTxt), (pageTxt.match(/.{0,30}Offerte.{0,20}/i) || [null])[0]);
      check(`${vp} (c) Lille : récap « Livraison à calculer » + total hors livraison ; bouton bloqué sans montant trompeur`, /Livraison\s*à calculer/.test(recapL) && /Total hors livraison/.test(recapL) && disabledL && !/€/.test(ctaL), { recapL: recapL.slice(0, 220), ctaL, disabledL });
      expect(secL).toMatch(/trop loin/i);
      expect(lienUber).toBeGreaterThanOrEqual(1);
      expect(pageTxt).not.toMatch(/Offerte/i);
      expect(recapL).toMatch(/Livraison\s*à calculer/);
      expect(recapL).toMatch(/Total hors livraison/);
      expect(disabledL, 'le bouton Confirmer est bloqué tant qu’aucune adresse n’est livrable').toBe(true);
      expect(ctaL, 'aucun montant trompeur sur le bouton bloqué').not.toMatch(/€/);
      expect(lil.entry.consoleErrors).toEqual([]);
      rec.dispose(); await ctx.close();
    });
  }

  // ------------------------------------------------------------------------
  test('3. D (g) refus de débit (429) et panne réseau des suggestions : message VISIBLE, jamais une liste vide muette', async ({ browser }) => {
    const ctx = await newCtx(browser, { mobile: false });
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const rec = recorder(page);
    let mode = '429';
    await ctx.route((url) => /\/delivery\/suggest/.test(url.href), (route) => {
      if (mode === '429') return route.fulfill({ status: 429, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify({ message: 'Too Many Attempts.' }) });
      if (mode === 'reseau') return route.abort('failed');
      return route.continue();
    });
    await ajouterProduit(page, 'Cheese Burger');
    await panierVersCommande(page);
    await choisirLivraison(page);
    const input = page.locator('input[aria-label="Adresse de livraison"]');
    await input.pressSequentially('12 rue de la Gare 62300 Lens', { delay: 25 });
    await page.waitForTimeout(1200);
    const errEl = page.locator('#lcf-delivery-suggest-err');
    const msg429 = (await errEl.count()) ? norm(await errEl.innerText({ timeout: 1500 }).catch(() => '')) : '';
    const opts429 = await page.evaluate(() => document.querySelectorAll('#lcf-delivery-suggest option').length);
    await state(page, rec, 'D-06-suggestions-429', { msg429 }, false);
    check('D (S-004) 429 : message visible « Trop de recherches, patientez… » et liste vide ASSUMÉE', /Trop de recherches/i.test(msg429) && opts429 === 0, { msg429, opts429 });
    expect(msg429).toMatch(/Trop de recherches, patientez/i);
    // panne réseau
    mode = 'reseau';
    await input.fill('');
    await input.pressSequentially('1 rue Jean Jaures 62590 Oignies', { delay: 25 });
    await page.waitForTimeout(1200);
    const msgNet = (await errEl.count()) ? norm(await errEl.innerText({ timeout: 1500 }).catch(() => '')) : '';
    check('D (S-004) panne réseau : message visible', /momentanément indisponibles/i.test(msgNet), msgNet);
    expect(msgNet).toMatch(/momentanément indisponibles/i);
    // retour à la normale : le message disparaît, les suggestions reviennent
    mode = 'normal';
    resetLimiteurs();
    await input.fill('');
    await input.pressSequentially('12 rue Elie Gruyelle 62110 Henin-Beaumont', { delay: 25 });
    await page.waitForTimeout(1800);
    const msgFin = await page.locator('#lcf-delivery-suggest-err').count();
    const optsFin = await page.evaluate(() => document.querySelectorAll('#lcf-delivery-suggest option').length);
    check('D (S-004) retour à la normale : plus de message, suggestions de retour', msgFin === 0 && optsFin > 0, { msgFin, optsFin });
    expect(msgFin).toBe(0);
    expect(optsFin).toBeGreaterThan(0);
    rec.dispose(); await ctx.close();
  });

  // ------------------------------------------------------------------------
  for (const mobile of [false, true]) {
    const vp = mobile ? 'M' : 'D';
    test(`4${mobile ? 'b' : 'a'}. ${vp} (d)(e) commande de LIVRAISON complète — Cayenne : 201, textes de livraison, aucun texte de retrait, totaux = base`, async ({ browser }) => {
      const ctx = await newCtx(browser, { mobile });
      const page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      const rec = recorder(page);
      const w = await ajouterProduit(page, 'Cayenne');
      expect(w.ajout.ok, `Cayenne ajouté au panier (assistant : ${JSON.stringify(w.journal.map((j) => j.title))})`).toBe(true);
      const panierTxt = await panierVersCommande(page);
      const sousTotal = lastEuro(panierTxt);
      expect(sousTotal).toBeGreaterThan(0);
      await choisirLivraison(page);
      resetLimiteurs();
      const adr = await saisirAdresse(page, '12 rue Elie Gruyelle 62110 Henin-Beaumont', /Gruyelle/i);
      expect(adr.quote.allowed).toBe(true);
      const fee = Number(adr.quote.fee);

      // ---- S-002 : libellés de paiement d'une LIVRAISON
      const payBloc = page.locator('[role="radiogroup"][aria-label="Mode de paiement"]');
      await payBloc.scrollIntoViewIfNeeded();
      const modes = (await payBloc.locator('[role="radio"]').allInnerTexts()).map(norm);
      const livraisonRadio = payBloc.getByRole('radio', { name: /Payer à la livraison/ });
      expect(await livraisonRadio.count(), 'option « Payer à la livraison »').toBe(1);
      if ((await livraisonRadio.getAttribute('aria-checked')) !== 'true') await livraisonRadio.click();
      const pagePay = norm(await page.locator('.lcf-page').innerText());
      check(`${vp} (S-002) paiement livraison : libellés de livraison, aucun libellé de comptoir`, /Payer à la livraison/.test(pagePay) && /Espèces à la remise/.test(pagePay) && /Paiement à la livraison/i.test(pagePay)
        && !/Payer sur place|PAIEMENT AU COMPTOIR|envoyée en direct à la caisse|ton plat t'attend/i.test(pagePay), { modes, extrait: (pagePay.match(/.{0,50}(sur place|comptoir|en direct).{0,40}/i) || [null])[0] });
      expect(pagePay).toMatch(/Payer à la livraison/);
      expect(pagePay).toMatch(/Espèces à la remise/);
      expect(pagePay).toMatch(/Paiement à la livraison/i);
      expect(pagePay).not.toMatch(/Payer sur place/i);
      expect(pagePay).not.toMatch(/Paiement au comptoir/i);
      expect(pagePay).not.toMatch(/envoyée en direct à la caisse/i);
      expect(pagePay).not.toMatch(/ton plat t'attend/i);
      const ctaAvant = norm(await page.locator('.lcf-cta-bar-next').innerText());
      expect(Math.abs(lastEuro(ctaAvant) - (sousTotal + fee)), 'bouton == panier + frais').toBeLessThan(0.005);

      // ---- identification + commande (corps envoyé = livraison, espèces)
      await sIdentifier(page, rq, mobile ? 'm' : 'd');
      await page.locator('.lcf-cta-bar-next').scrollIntoViewIfNeeded();
      const o = await confirmer(page, `${vp} Cayenne livraison`);
      expect(o.http, `commande de livraison : ${o.corps || ''}`).toBe(201);
      expect(o.envoye.order_type, 'order_type envoyé = 5 (livraison)').toBe(5);
      expect(o.envoye.payment_method, 'payment_method envoyé = 1 (espèces) — logique inchangée').toBe(1);
      await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 45_000 });
      await page.waitForTimeout(1500);
      const conf = await state(page, rec, `${vp}-07-confirmation-livraison`, {}, true);
      const confTxt = norm(conf.text);
      const confDom = await page.evaluate(() => ({
        qr: document.querySelectorAll('.lcf-ticket-qr, .lcf-ticket-qr-mock').length,
        qrAria: Array.from(document.querySelectorAll('[aria-label*="QR"]')).map((e) => e.getAttribute('aria-label')),
        boutons: Array.from(document.querySelectorAll('.lcf-confirm button')).map((b) => b.innerText.replace(/\s+/g, ' ').trim()),
        mode: (document.querySelector('.lcf-ticket') || {}).getAttribute ? document.querySelector('.lcf-ticket').getAttribute('data-mode') : null,
      }));
      const base = ligneBase(o.id);
      o.base = base;
      const totalConf = lastEuro((confTxt.match(/Total[^€]*€/i) || [''])[0]);
      check(`${vp} (d) confirmation : textes de livraison (adresse, règlement), aucun texte de retrait, aucun QR`,
        /Livraison/.test(confTxt) && /Elie Gruyelle/i.test(confTxt) && /Espèces à la remise/.test(confTxt) && !RETRAIT_RX.test(confTxt) && confDom.qr === 0 && confDom.qrAria.length === 0,
        { extrait: confTxt.slice(0, 520), retrait: (confTxt.match(RETRAIT_RX) || [null])[0], confDom });
      expect(confTxt).toMatch(/Livraison/);
      expect(confTxt).toMatch(/Elie Gruyelle/i);
      expect(confTxt).toMatch(/Espèces à la remise/);
      expect(confTxt).toMatch(/livrée à l'adresse indiquée/);
      expect(confTxt, 'aucun texte de retrait').not.toMatch(RETRAIT_RX);
      expect(confDom.qr, 'aucun QR de retrait').toBe(0);
      expect(confDom.qrAria, 'aucun QR (aria)').toEqual([]);
      check(`${vp} (d) base : order_type=5, delivery_charge=frais du devis, payment_method=1, total = panier + frais == API == confirmation`,
        Number(base.order_type) === 5 && Number(base.delivery_charge) === fee && Number(base.payment_method) === 1
        && Math.abs(Number(base.total) - (sousTotal + fee)) < 0.005 && Math.abs(o.totalApi - Number(base.total)) < 0.005 && Math.abs(totalConf - Number(base.total)) < 0.005,
        { base, sousTotal, fee, api: o.totalApi, confirmation: totalConf });
      expect(Number(base.order_type)).toBe(5);
      expect(Number(base.delivery_charge)).toBe(fee);
      expect(Number(base.payment_method)).toBe(1);
      expect(Math.abs(Number(base.total) - (sousTotal + fee))).toBeLessThan(0.005);
      expect(Math.abs(o.totalApi - Number(base.total))).toBeLessThan(0.005);
      expect(Math.abs(totalConf - Number(base.total))).toBeLessThan(0.005);
      expect(conf.entry.consoleErrors).toEqual([]);

      // ---- suivi
      await page.getByRole('button', { name: /Suivre ma commande/ }).first().click();
      await page.waitForTimeout(2500);
      const sv = await state(page, rec, `${vp}-08-suivi-livraison`, {}, true);
      const svTxt = norm(sv.text);
      const etapes = (await page.locator('.lcf-track-step-label').allInnerTexts()).map(norm);
      const svDom = await page.evaluate(() => ({ cta: Array.from(document.querySelectorAll('.lcf-track-cta')).map((b) => b.innerText.replace(/\s+/g, ' ').trim()), mode: (document.querySelector('.lcf-track') || {}).getAttribute ? document.querySelector('.lcf-track').getAttribute('data-mode') : null }));
      const delais = svTxt.match(/~\s?\d+[\s–-]*\d*\s?min/g) || [];
      check(`${vp} (d) suivi : étapes de livraison, adresse, règlement ; aucun texte de retrait, aucun délai en minutes`,
        etapes.join('|') === 'Reçue|En préparation|En route|Livrée' && /Elie Gruyelle/i.test(svTxt) && /Espèces à la remise/.test(svTxt) && !RETRAIT_RX.test(svTxt) && delais.length === 0 && svDom.cta.length === 0,
        { etapes, extrait: svTxt.slice(0, 520), delais, svDom });
      expect(etapes, 'étapes du suivi d’une livraison').toEqual(['Reçue', 'En préparation', 'En route', 'Livrée']);
      expect(svDom.mode).toBe('livraison');
      expect(svTxt).toMatch(/Elie Gruyelle/i);
      expect(svTxt).toMatch(/Espèces à la remise/);
      expect(svTxt, 'aucun texte de retrait au suivi').not.toMatch(RETRAIT_RX);
      expect(delais, 'aucun « ~N min » (le serveur compte jusqu’au départ de la tournée)').toEqual([]);
      expect(svDom.cta, 'pas de bouton « Voir le QR de retrait »').toEqual([]);
      // Lisibilité du bloc « Livraison » du suivi (la carte de suivi est sombre : texte blanc hérité → blanc sur crème = illisible,
      // défaut relevé à la lecture de la capture) : contraste WCAG du texte de l'adresse contre son fond réel ≥ 4,5.
      const contraste = await page.evaluate(() => {
        const bloc = document.querySelector('[data-bloc="livraison"]');
        if (!bloc) return null;
        const cible = bloc.querySelectorAll('div')[1];
        const rgb = (c) => { const m = String(c).match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(',').map((x) => parseFloat(x)); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
        const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
        let fond = null; let e = cible;
        while (e && !fond) { const b = rgb(getComputedStyle(e).backgroundColor); if (b && b.a > 0.5) fond = b; e = e.parentElement; }
        const txt = rgb(getComputedStyle(cible).color);
        if (!fond || !txt) return { ratio: 0 };
        const a = lum(txt), b = lum(fond);
        return { ratio: Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100, txt, fond };
      });
      check(`${vp} (S-001) suivi : bloc « Livraison » lisible (contraste ≥ 4,5)`, contraste && contraste.ratio >= 4.5, contraste);
      expect(contraste, 'bloc Livraison présent au suivi').toBeTruthy();
      expect(contraste.ratio, 'contraste du texte du bloc Livraison').toBeGreaterThanOrEqual(4.5);
      expect(sv.entry.consoleErrors).toEqual([]);

      // ---- le suivi suit le statut serveur : acceptée → en préparation → prête → en route (API admin, comme la caisse)
      if (!mobile) {
        const tok = await adminToken(rq);
        const hdr = { 'X-API-KEY': API_KEY, Accept: 'application/json', Authorization: `Bearer ${tok}` };
        const statuts = {};
        for (const st of [4, 7, 8, 10]) {
          const r = await rq.post(`${BASE}/api/admin/online-order/change-status/${o.id}`, { headers: { ...hdr, 'X-Idempotency-Key': crypto.randomUUID() }, data: { status: st, preparation_time: 15 } });
          statuts[st] = r.status();
          if (r.status() >= 300) { statuts[`${st}-corps`] = (await r.text()).slice(0, 200); break; }
        }
        L.progressionStatuts = statuts;
        note(`progression des statuts (admin) : ${JSON.stringify(statuts)}`);
        expect(statuts[10], `progression des statuts par l'API admin : ${JSON.stringify(statuts)}`).toBeLessThan(300);
        // Le suivi interroge le serveur toutes les 20 s : on attend qu'il reflète « en route » (statut 10).
        try {
          await page.waitForFunction(() => /Votre commande est en route/i.test(document.body.innerText), null, { timeout: 45_000 });
        } catch (e) {
          note(`suivi : « en route » non affiché ; statut en base=${mysql(`SELECT status FROM orders WHERE id=${Number(o.id)}`)} ; texte=${norm(await visibleText(page)).slice(0, 400)}`);
          throw e;
        }
        const t2 = norm(await visibleText(page));
        const etapes2 = await page.evaluate(() => Array.from(document.querySelectorAll('.lcf-track-step')).map((e) => ({ l: e.innerText.replace(/\s+/g, ' ').trim(), c: e.className })));
        await state(page, rec, `${vp}-09-suivi-en-route`, {}, false);
        check(`${vp} (d) suivi après départ (statut 10) : « Votre commande est en route », étape « En route » courante, rien de « prêt / retrait »`,
          /Votre commande est en route/i.test(t2) && !RETRAIT_RX.test(t2) && !/Ta commande est prête/i.test(t2) && etapes2.some((e) => /En route/i.test(e.l) && /is-current/.test(e.c)), { t2: t2.slice(0, 300), etapes2 });
        expect(t2).toMatch(/Votre commande est en route/i);
        expect(t2).not.toMatch(RETRAIT_RX);
        expect(t2).not.toMatch(/Ta commande est prête/i);
        expect(etapes2.some((e) => /En route/i.test(e.l) && /is-current/.test(e.c)), 'étape « En route » courante').toBe(true);
      }
      rec.dispose(); await ctx.close();
    });
  }

  // ------------------------------------------------------------------------
  test('5. D (e)(S-101)(S-102) commande À EMPORTER : Cayenne, Classique, Tacos XL, Tacos L (Mexicanos puis Mixte), Suprême, Méga, Terminator → 201 ; bols limités aux viandes du serveur', async ({ browser }) => {
    const ctx = await newCtx(browser, { mobile: false });
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const rec = recorder(page);
    // ---- S-102 : le Bol Riz ne propose que les 7 viandes que le serveur accepte sur « Viande 1 »
    await gotoSite(page, '#menu');
    await page.locator('[aria-label="Voir Bol Riz"]').first().click();
    await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
    await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(1200);
    let st = await readStep(page);
    for (let i = 0; i < 3 && !/viande/i.test(st.title); i++) { await page.locator('.lc-wiz-choice:not(.is-on)').first().click(); await page.locator('.lc-wiz-foot-next').click(); await page.waitForTimeout(500); st = await readStep(page); }
    const viandesBol = st.choix.map((c) => c.replace(/\s*Inclus.*$/i, '').trim());
    L.bolRiz = { title: st.title, viandes: viandesBol };
    check('D (S-102) Bol Riz : exactement les 7 viandes du serveur (ni curry, ni tandoori, ni crispy, ni Mixte)', st.choix.length === 7 && !st.choix.some((c) => /curry|tandoori|crispy|Mixte/i.test(c)), st.choix);
    expect(st.choix.length, 'nombre de viandes proposées pour le Bol Riz').toBe(7);
    expect(st.choix.some((c) => /curry|tandoori|crispy|Mixte/i.test(c))).toBe(false);

    // ---- les 4 lignes
    const lignes = [
      { nom: 'Cayenne', opts: {} },
      { nom: 'Sandwich Classique', opts: {} },
      { nom: 'Tacos XL', opts: { viandes: ['Mexicanos', 'Cordon Bleu', 'Viande Hachée'] } },
      { nom: 'Tacos L', opts: { viandes: ['Mexicanos', 'Mixte'] } },
      // Suprême, Méga, Terminator, Sandwich Classique : le site envoyait une variation « Pain » que le profil publié ne propose pas (refus 422).
      { nom: 'Suprême', opts: {} },
      { nom: 'Méga', opts: {} },
      { nom: 'Terminator', opts: {} },
    ];
    const journaux = {};
    for (const l of lignes) {
      const w = await ajouterProduit(page, l.nom, l.opts);
      journaux[l.nom] = w.titres;
      expect(w.ajout.ok, `${l.nom} ajouté au panier : ${w.ajout.erreur || ''}`).toBe(true);
      await page.waitForTimeout(600);
    }
    L.pickupJournaux = journaux;
    check('D (S-101) l’assistant ne propose « Pain ou galette ? » que là où le serveur le propose (Cayenne oui ; Classique, Suprême, Méga, Terminator non)',
      journaux.Cayenne.some((t) => /pain/i.test(t)) && ['Sandwich Classique', 'Suprême', 'Méga', 'Terminator'].every((n) => !journaux[n].some((t) => /pain/i.test(t))), journaux);
    expect(journaux.Cayenne.some((t) => /pain/i.test(t)), 'le Cayenne propose le choix du pain (profil publié)').toBe(true);
    for (const n of ['Sandwich Classique', 'Suprême', 'Méga', 'Terminator']) expect(journaux[n].some((t) => /pain/i.test(t)), `${n} : pas de choix de pain (le profil publié n'en a pas)`).toBe(false);
    const drawerTxt = await panierVersCommande(page);
    const sousTotal = lastEuro(drawerTxt);
    check('D (e) panier : 7 lignes', ['Cayenne', 'Sandwich Classique', 'Tacos XL', 'Tacos L', 'Suprême', 'Méga', 'Terminator'].every((n) => drawerTxt.includes(n)), drawerTxt.slice(0, 400));
    const payBloc = page.locator('[role="radiogroup"][aria-label="Mode de paiement"]');
    await payBloc.scrollIntoViewIfNeeded();
    const surPlace = payBloc.getByRole('radio', { name: /Payer sur place/ });
    expect(await surPlace.count(), 'à emporter : « Payer sur place » conservé').toBe(1);
    if ((await surPlace.getAttribute('aria-checked')) !== 'true') await surPlace.click();
    const ctaAvant = norm(await page.locator('.lcf-cta-bar-next').innerText());
    await sIdentifier(page, rq, 'e');
    await page.locator('.lcf-cta-bar-next').scrollIntoViewIfNeeded();
    const o = await confirmer(page, 'D 7 produits à emporter');
    expect(o.http, `commande à emporter (7 produits à viande) : ${o.corps || ''}`).toBe(201);
    expect(o.envoye.order_type, 'à emporter = 10').toBe(10);
    await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 45_000 });
    const conf = await state(page, rec, 'D-09-confirmation-emporter', {}, false);
    const confTxt = norm(conf.text);
    const base = ligneBase(o.id);
    const lignesBase = mysql(`SELECT i.name, oi.quantity FROM order_items oi JOIN items i ON i.id=oi.item_id WHERE oi.order_id=${Number(o.id)} ORDER BY oi.id`).split('\n');
    check('D (e) base : les 7 produits sont commandés, order_type=10, sans frais de livraison, total == bouton', Number(base.order_type) === 10 && Number(base.delivery_charge) === 0
      && Math.abs(Number(base.total) - lastEuro(ctaAvant)) < 0.005 && ['Cayenne', 'Sandwich Classique', 'Tacos XL', 'Tacos L', 'Suprême', 'Méga', 'Terminator'].every((n) => lignesBase.some((l) => l.startsWith(n + '\t'))), { base, lignesBase, ctaAvant, sousTotal });
    expect(Number(base.order_type)).toBe(10);
    expect(Number(base.delivery_charge)).toBe(0);
    expect(Math.abs(Number(base.total) - lastEuro(ctaAvant))).toBeLessThan(0.005);
    for (const n of ['Cayenne', 'Sandwich Classique', 'Tacos XL', 'Tacos L', 'Suprême', 'Méga', 'Terminator']) expect(lignesBase.some((l) => l.startsWith(n + '\t')), `ligne ${n} en base`).toBe(true);
    // à emporter : le texte de retrait reste, lui, correct
    expect(confTxt).toMatch(/Présente ce QR à la caisse/);
    expect(conf.entry.consoleErrors).toEqual([]);
    rec.dispose(); await ctx.close();
  });

  // ------------------------------------------------------------------------
  test('6. D (f) refus serveur FORCÉ (2 viandes sur « Viande 1 ») : l’alerte est VISIBLE dans la fenêtre, focalisée, et dit la première erreur métier', async ({ browser }) => {
    const ctx = await newCtx(browser, { mobile: false });
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const rec = recorder(page);
    // choix invalide FORCÉ par interception : on ajoute une 2ᵉ variation « Viande 1 » à la ligne Cayenne (le serveur refusera).
    const det = await (await rq.get(`${BASE}/api/frontend/item/details/22`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } })).json();
    const d = det.data || det;
    const viande1 = d.variations['1'];
    expect(viande1.length).toBeGreaterThan(2);
    await ctx.route((url) => /\/api\/frontend\/order$/.test(url.pathname) && true, async (route) => {
      const req = route.request();
      if (req.method() !== 'POST') return route.continue();
      const body = JSON.parse(req.postData() || '{}');
      const items = JSON.parse(body.items);
      const vars = items[0].item_variations || [];
      const deja = new Set(vars.map((v) => v.id));
      const autre = viande1.find((v) => !deja.has(v.id));
      if (vars.some((v) => viande1.some((x) => x.id === v.id))) vars.push({ id: autre.id, quantity: 1 });
      items[0].item_variations = vars;
      body.items = JSON.stringify(items);
      return route.continue({ postData: JSON.stringify(body) });
    });
    const w = await ajouterProduit(page, 'Cayenne');
    expect(w.ajout.ok).toBe(true);
    await panierVersCommande(page);
    await sIdentifier(page, rq, 'f');
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(400);
    const scrollAvant = await page.evaluate(() => Math.round(window.scrollY));
    const o = await confirmer(page, 'D refus forcé');
    await page.waitForTimeout(700);
    const pos = await page.evaluate(() => {
      const a = document.querySelector('#lc-api-error');
      if (!a) return null;
      const r = a.getBoundingClientRect();
      return { top: Math.round(r.top), bottom: Math.round(r.bottom), vh: window.innerHeight, texte: (a.innerText || '').replace(/\s+/g, ' ').trim(), focus: document.activeElement === a, scrollY: Math.round(window.scrollY) };
    });
    L.refusForce = { http: o.http, corps: o.corps, scrollAvant, pos };
    await state(page, rec, 'D-10-refus-visible', { pos }, true);
    check('D (f) refus : HTTP 4xx du serveur (forcé)', o.http >= 400 && o.http < 500, o.http);
    expect(o.http).toBeGreaterThanOrEqual(400);
    expect(pos, 'alerte #lc-api-error présente').toBeTruthy();
    check('D (f) alerte DANS la fenêtre au moment où elle survient (aucun défilement du test)', pos.top >= 0 && pos.bottom <= pos.vh, pos);
    expect(pos.top, 'haut de l’alerte dans la fenêtre').toBeGreaterThanOrEqual(0);
    expect(pos.bottom, 'bas de l’alerte dans la fenêtre').toBeLessThanOrEqual(pos.vh);
    check('D (f) alerte : focus posé dessus', pos.focus, pos);
    expect(pos.focus).toBe(true);
    check('D (f) texte : première erreur métier lisible, pas le compteur agrégé', /Viande/i.test(pos.texte) && !/autre erreur|more error/i.test(pos.texte), pos.texte);
    expect(pos.texte).toMatch(/Viande/i);
    expect(pos.texte).not.toMatch(/autre erreur|more error/i);
    rec.dispose(); await ctx.close();
  });

  // ------------------------------------------------------------------------
  // Diagnostic (désactivé par défaut) : S_DIAG="Suprême,Méga,..." → une commande à emporter PAR produit, HTTP + message de refus.
  test('D. diagnostic (S_DIAG) : quels produits à viande sont refusés par le serveur ?', async ({ browser }) => {
    test.skip(!process.env.S_DIAG, 'diagnostic : poser S_DIAG="Produit1,Produit2"');
    const ctx = await newCtx(browser, { mobile: false });
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const res = [];
    for (const nom of process.env.S_DIAG.split(',')) {
      const w = await ajouterProduit(page, nom.trim());
      if (!w.ajout.ok) { res.push({ nom, ajout: w.ajout }); continue; }
      await panierVersCommande(page);
      await sIdentifier(page, rq, 'diag');
      const payBloc = page.locator('[role="radiogroup"][aria-label="Mode de paiement"]');
      await payBloc.scrollIntoViewIfNeeded();
      const o = await confirmer(page, `diag ${nom}`);
      res.push({ nom, http: o.http, corps: o.corps });
      if (o.http >= 400) {
        // vider le panier pour le produit suivant
        await page.evaluate(() => { try { Object.keys(localStorage).filter((k) => /cart/i.test(k)).forEach((k) => localStorage.removeItem(k)); } catch (_e) { /* ok */ } });
      } else { await page.waitForTimeout(500); }
    }
    L.diag = res; writeLedger();
    console.log('[DIAG] ' + JSON.stringify(res));
    await ctx.close();
  });

  // ------------------------------------------------------------------------
  test('7. réseau : aucune requête vers la production', async () => {
    check('RÉSEAU : 0 requête vers l’hôte de production / API lecayenne.fr', L.prodBloquees.length === 0, L.prodBloquees.slice(0, 10));
    expect(L.prodBloquees, 'aucune requête vers la production').toEqual([]);
    // Les deux états où le TEST PROVOQUE lui-même une réponse 429 / 422 (le navigateur la journalise « Failed to load resource ») sont exclus.
    const PROVOQUES = ['D-06-suggestions-429', 'D-10-refus-visible'];
    const sansConsole = L.states.filter((s) => !PROVOQUES.includes(s.id) && s.consoleErrors && s.consoleErrors.length).map((s) => ({ id: s.id, e: s.consoleErrors }));
    check('(h) console propre sur tous les états capturés', sansConsole.length === 0, sansConsole);
    expect(sansConsole).toEqual([]);
    writeLedger();
  });
});
