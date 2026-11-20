// [AUDIT E2E caisse-site-complet-2026-10-23 · VAGUE S · round 1] Site web client — VERSION EN LIGNE servie en miroir local.
// LECTURE SEULE côté produit : aucun fichier de produit modifié. Base locale foodking_e2e uniquement.
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/caisse-site-complet-2026-10-23/S-site.spec.js --workers=1 --retries=0 --reporter=line
//
// Instrument :
//  - :8012 = fichiers téléchargés ce soir depuis www.lecayenne.fr (empreinte de compiled/funnel.js comparée ci-dessous),
//    `api-base-url` → :8766 et `api-key` → clé locale DÉJÀ réécrits dans le miroir (non modifié par ce spec).
//  - Garde réseau : toute requête vers l'hôte de production (vps-418872ac…) ou une API lecayenne.fr est BLOQUÉE et consignée
//    (un tel appel serait un défaut d'instrument, pas une mesure).
//  - Variante « V » (test 4) : la balise <meta name="feature-delivery" content="1"> est injectée DANS LE NAVIGATEUR pour
//    mesurer le code de livraison DORMANT de la version en ligne. Ce n'est PAS ce que voit un client aujourd'hui.
//  - Chaque état = quatuor PNG + DOM + console + réseau (mega-audit-snap) ; ≤ 15 PNG (disque à 99 %).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');

const REPO = path.resolve(__dirname, '..', '..', '..');
const SITE = 'http://127.0.0.1:8012';
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const OUT = path.join(REPO, 'reports', 'test-e2e', 'caisse-site-complet-2026-10-23', 'captures-S');
fs.mkdirSync(OUT, { recursive: true });
const MIRROR = process.env.S_SITE_DIR || '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/2c3726ad-78a8-4013-b7aa-66c1e1619e24/scratchpad/site-live';
// SHA-256 (16 premiers hex) de https://www.lecayenne.fr/compiled/funnel.js mesuré le 2026-10-23 au soir (curl), = miroir.
const LIVE_FUNNEL_SHA_PREFIX = 'a263ecac49f6f500';
const PROD_RX = /vps-418872ac|ovh\.net|(^|\.)lecayenne\.fr\/api/i;

function envValue(key) {
  const m = fs.readFileSync(path.join(REPO, '.env'), 'utf8').match(new RegExp(`^${key}=(.*)$`, 'm'));
  return m ? m[1].trim().replace(/^"|"$/g, '') : '';
}
const API_KEY = envValue('MIX_API_KEY');
const NOW = Date.now();
const TAG = `E2E-S-${String(NOW).slice(-6)}`;
const PNG_CAP = 15;
let pngUsed = 0;

test.describe.configure({ mode: 'serial' });
test.setTimeout(420_000);
// Un document servi par route.fulfill (variante V) est soumis par Chromium aux contrôles « réseau local » : sans ces
// drapeaux, ses appels vers 127.0.0.1:8766 échouent (« Réseau indisponible ») — artefact d'instrument mesuré au round 1.
test.use({ video: 'off', trace: 'off', screenshot: 'off', locale: 'fr-FR', timezoneId: 'Europe/Paris',
  launchOptions: { args: ['--lang=fr-FR', '--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks'] } });

// ---------------------------------------------------------------- registre
const L = { tag: TAG, startedAt: new Date().toISOString(), instrument: {}, states: [], checks: [], prodBloquees: [], origines: {}, orders: [], quotes: [], notes: [] };
const note = (s) => { L.notes.push(s); console.log('[S] ' + s); };
const writeLedger = () => fs.writeFileSync(path.join(OUT, 'S-ledger.json'), JSON.stringify(L, null, 2));
function check(id, ok, detail) { L.checks.push({ id, ok: !!ok, detail }); console.log(`[S CHECK] ${ok ? 'OK ' : 'KO '} ${id} ${detail !== undefined ? JSON.stringify(detail).slice(0, 400) : ''}`); writeLedger(); }
function mysql(sql) { return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], { encoding: 'utf8', timeout: 20_000 }).trim(); }
const euros = (s) => [...String(s || '').replace(/[  ]/g, ' ').matchAll(/(\d+)[.,](\d{2})\s*€/g)].map((m) => Number(`${m[1]}.${m[2]}`));
const lastEuro = (s) => { const a = euros(s); return a.length ? a[a.length - 1] : NaN; };
const sha16 = (p) => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex').slice(0, 16);

// ---------------------------------------------------------------- instruments
async function newCtx(browser, { mobile = false, injectDelivery = false } = {}) {
  const ctx = await browser.newContext(mobile
    ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1, locale: 'fr-FR', timezoneId: 'Europe/Paris' }
    : { viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  // Garde production : bloque ET consigne.
  await ctx.route((url) => PROD_RX.test(url.href), (route) => { L.prodBloquees.push(route.request().url().slice(0, 200)); writeLedger(); return route.abort('blockedbyclient'); });
  ctx.on('request', (req) => { try { const o = new URL(req.url()).origin; L.origines[o] = (L.origines[o] || 0) + 1; } catch (_e) { /* data: */ } });
  if (injectDelivery) {
    await ctx.route((url) => url.origin === SITE && (url.pathname === '/' || url.pathname === '/index.html'), async (route) => {
      if (route.request().resourceType() !== 'document') return route.continue();
      const res = await route.fetch();
      const body = (await res.text()).replace(/(<meta name="api-key"[^>]*>)/, '$1\n<meta name="feature-delivery" content="1">');
      return route.fulfill({ response: res, body });
    });
  }
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
  await page.waitForLoadState('networkidle', { timeout: 60_000 }).catch(() => note('networkidle non atteint en 60 s (polling) — capture après 1,2 s'));
  await page.waitForTimeout(1200);
}
async function gotoSite(page, hash = '') {
  await page.goto(`${SITE}/${hash}`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 60_000 });
  await settle(page);
}
const visibleText = (page) => page.evaluate(() => String(document.body.innerText || '').replace(/[  ]/g, ' '));

/** Un état : texte visible + quatuor + erreurs console / HTTP ≥ 400 relues dans le quatuor. */
async function state(page, rec, id, extra = {}, png = true) {
  await settle(page);
  const text = await visibleText(page);
  await rec.snap(id, png);
  let net = []; let con = [];
  try { net = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.network.json`), 'utf8')); } catch (_e) { /* ok */ }
  try { con = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.console.json`), 'utf8')); } catch (_e) { /* ok */ }
  const http4xx = net.filter((x) => x.status >= 400).map((x) => `${x.method} ${x.url.replace(BASE, '').replace(SITE, '').slice(0, 110)} -> ${x.status}`);
  const conErr = con.filter((c) => /error|pageerror/.test(c.level)).map((c) => c.text.slice(0, 200));
  const alerts = await page.evaluate(() => Array.from(document.querySelectorAll('[role=alert]')).filter((e) => e.offsetParent).map((e) => (e.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 200)));
  const pngOk = fs.existsSync(path.join(OUT, `${id}.png`)) && fs.statSync(path.join(OUT, `${id}.png`)).size > 0;
  const entry = { id, url: page.url().replace(SITE, ''), ...extra, nbCaracteres: text.length, http4xx, consoleErrors: conErr, alertsVisibles: alerts, png: pngOk };
  L.states.push(entry); writeLedger();
  console.log(`[S STATE] ${id} 4xx=${http4xx.length} conErr=${conErr.length} alerts=${alerts.length} png=${pngOk}`);
  return { entry, text };
}

// ---------------------------------------------------------------- pilotage du tunnel
async function readStep(page) {
  const r = await page.evaluate(() => {
    const t = (sel) => { const e = document.querySelector(sel); return e ? String(e.innerText || '').replace(/\s+/g, ' ').trim() : ''; };
    const next = document.querySelector('.lc-wiz-foot-next');
    return { title: t('.lc-wiz-title'), next: next ? String(next.innerText || '').replace(/\s+/g, ' ') : '', disabled: next ? (next.disabled || next.getAttribute('aria-disabled') === 'true') : null,
      on: Array.from(document.querySelectorAll('.lc-wiz-choice.is-on')).map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 50)) };
  });
  return { ...r, total: lastEuro(r.next) };
}
/** Parcourt l'assistant ; choisit EXPLICITEMENT une sauce réelle (« Algérienne ») à l'étape sauce. Rend le journal des étapes. */
async function marcherWizard(page) {
  const journal = [];
  for (let s = 0; s < 18; s++) {
    const step = await readStep(page);
    if (/Ajouter au panier/i.test(step.next)) { journal.push({ title: step.title, next: step.next, total: step.total, on: step.on }); return { recap: step, journal }; }
    if (/sauce/i.test(step.title)) {
      const alg = page.locator('.lc-wiz-choice', { hasText: /Algérienne/ }).first();
      if (await alg.count() && !(await alg.evaluate((e) => e.classList.contains('is-on')))) { await alg.click(); await page.waitForTimeout(300); }
    }
    for (let k = 0; k < 3 && (await readStep(page)).disabled; k++) {
      await page.locator('.lc-wiz-choice:not(.is-on)').first().click();
      await page.waitForTimeout(300);
    }
    const after = await readStep(page);
    const consigne = await page.evaluate(() => { const m = String((document.querySelector('.lc-wiz') || document.body).innerText).match(/\d+ sélectionnée?s? · \d+ minimum · \d+ maximum/); return m ? m[0] : null; });
    journal.push({ title: after.title, next: after.next, total: after.total, on: after.on, consigne, desactiveAvantChoix: step.disabled });
    if (after.title !== step.title) continue;
    await page.locator('.lc-wiz-foot-next').click({ timeout: 10_000 });
    await page.waitForTimeout(450);
  }
  return { recap: null, journal };
}
async function ajouterAuPanier(page) {
  await page.locator('.lc-wiz-foot-next').click({ timeout: 10_000 });
  const t0 = Date.now();
  while (Date.now() - t0 < 25_000) {
    if (await page.locator('.lc-cart-drawer.is-open').count()) return true;
    if (!(await page.locator('.lc-wiz').count())) return true;
    await page.waitForTimeout(250);
  }
  return false;
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
const PRODUIT = 'Cayenne';
async function produitDansPanier(page) {
  await gotoSite(page, '#menu');
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30_000 });
  await page.locator(`[aria-label="Voir ${PRODUIT}"]`).first().click();
  await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 15_000 });
  const fiche = (await page.locator('.lc-detail').innerText()).replace(/\s+/g, ' ');
  await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
  await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15_000 });
  return fiche;
}
function resetLimiteurs() {
  execFileSync('php', ['artisan', 'tinker', '--execute', `$l = app(\\Illuminate\\Cache\\RateLimiter::class); $l->clear(sha1("|127.0.0.1")); foreach (['delivery-suggest','delivery-quote','delivery-status'] as $n) { foreach (['127.0.0.1','::1'] as $ip) { $l->clear(md5($n.$ip)); } } echo 'ok';`], { cwd: REPO, encoding: 'utf8', timeout: 30_000 });
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
  const er = await r;
  L.notes.push(`guest-signup/email-login -> HTTP ${er.status()}`);
  const token = mysql(`SELECT token FROM otps WHERE phone='${phone}' ORDER BY created_at DESC LIMIT 1`);
  expect(token, 'code OTP enregistré en base').toMatch(/^\d{4}$/);
  resetLimiteurs();
  const v = await rq.post(`${BASE}/api/auth/guest-signup/verify`, { headers: { 'X-API-Key': API_KEY, Accept: 'application/json' }, data: { token, phone, code: '+33', email, first_name: prenom } });
  const vj = await v.json().catch(() => ({}));
  expect(v.status(), JSON.stringify(vj).slice(0, 200)).toBeLessThan(300);
  await page.evaluate(([t, p]) => { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); }, [vj.token, phone]);
}
async function adminToken(rq) {
  const r = await rq.post(`${BASE}/api/auth/login`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { email: 'admin@lecayenne.fr', password: '123456' } });
  const j = await r.json().catch(() => ({}));
  return j.token || (j.data && j.data.token) || null;
}

// ============================================================================
let rq;
test.describe('Vague S — site EN LIGNE (miroir :8012) : accueil, carte, panier, emporter, livraison', () => {
  test.beforeAll(async ({ playwright }) => {
    rq = await playwright.request.newContext();
    const idx = fs.readFileSync(path.join(MIRROR, 'index.html'), 'utf8');
    const meta = (n) => (idx.match(new RegExp(`<meta name="${n}" content="([^"]*)"`)) || [null, null])[1];
    const st = await rq.get(`${BASE}/api/frontend/delivery/status`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } });
    L.instrument = {
      miroirFunnelSha16: sha16(path.join(MIRROR, 'compiled', 'funnel.js')), enLigneFunnelSha16: LIVE_FUNNEL_SHA_PREFIX,
      baliseFunnel: (idx.match(/compiled\/funnel\.js\?v=[^"']+/) || [null])[0],
      metas: { 'api-base-url': meta('api-base-url'), 'api-key-locale': meta('api-key') === API_KEY, 'feature-delivery': meta('feature-delivery'), 'feature-online-card': meta('feature-online-card'), 'mollie-testmode': meta('mollie-testmode'), 'uber-eats-url': meta('uber-eats-url') },
      serveurLivraison: await st.json().catch(() => null),
    };
    writeLedger();
    expect(L.instrument.miroirFunnelSha16, 'le miroir doit être la version EN LIGNE').toBe(LIVE_FUNNEL_SHA_PREFIX);
    expect(L.instrument.metas['api-base-url']).toBe('http://127.0.0.1:8766');
    expect(L.instrument.metas['api-key-locale']).toBe(true);
    note(`instrument : ${JSON.stringify(L.instrument).slice(0, 600)}`);
  });
  test.afterAll(async () => {
    // Annule les commandes de test créées (préfixe E2E-S-) pour ne pas encombrer la file de la caisse des autres vagues.
    try {
      const tok = await adminToken(rq);
      for (const o of L.orders.filter((x) => x.id)) {
        const r = await rq.post(`${BASE}/api/admin/online-order/change-status/${o.id}`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', Authorization: `Bearer ${tok}`, 'X-Idempotency-Key': crypto.randomUUID() }, data: { status: 16, reason: `${TAG} audit E2E site (commande de test)` } });
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
  for (const mobile of [false, true]) {
    test(`1${mobile ? 'b' : 'a'}. accueil ${mobile ? '390x844' : '1440x900'} : rendu, textes, images, horaires, console`, async ({ browser }) => {
      const vp = mobile ? 'M' : 'D';
      const ctx = await newCtx(browser, { mobile });
      const page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      const rec = recorder(page);
      await gotoSite(page);
      const { entry, text } = await state(page, rec, `${vp}-01-accueil`);
      const info = await page.evaluate(() => {
        const vis = (e) => { const r = e.getBoundingClientRect(); return e.offsetParent !== null && r.width > 0 && r.height > 0; };
        const imgs = Array.from(document.images);
        return {
          imagesCassees: imgs.filter((i) => i.complete && i.naturalWidth === 0 && i.getAttribute('src')).map((i) => i.getAttribute('src').slice(0, 100)),
          nbImages: imgs.length,
          boutons: Array.from(document.querySelectorAll('button, a[href]')).filter(vis).map((e) => (e.innerText || e.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 60),
          debordementX: document.documentElement.scrollWidth - window.innerWidth,
          titre: document.title,
          h1: Array.from(document.querySelectorAll('h1')).map((h) => h.innerText.replace(/\s+/g, ' ').trim()),
        };
      });
      const lignesLivraison = text.split('\n').filter((l) => /livr|uber|emporter|click/i.test(l)).map((l) => l.trim()).slice(0, 12);
      const horaires = text.split('\n').filter((l) => /\b(18|19|00|minuit)\s*h|\d{1,2}:\d{2}|ouvert|fermé|horaires/i.test(l)).map((l) => l.trim()).slice(0, 8);
      L[`accueil${vp}`] = { ...info, lignesLivraison, horaires };
      check(`${vp} accueil : texte rendu (> 400 car.)`, text.length > 400, text.length);
      check(`${vp} accueil : aucune image cassée`, info.imagesCassees.length === 0, info.imagesCassees);
      check(`${vp} accueil : pas de défilement horizontal`, info.debordementX <= 1, info.debordementX);
      check(`${vp} accueil : console sans erreur`, entry.consoleErrors.length === 0, entry.consoleErrors);
      check(`${vp} accueil : aucune réponse HTTP ≥ 400`, entry.http4xx.length === 0, entry.http4xx);
      check(`${vp} accueil : horaires affichés`, horaires.length > 0, horaires);
      check(`${vp} accueil : mentions livraison/emporter (lecture)`, true, lignesLivraison);
      expect(text.length).toBeGreaterThan(400);
      rec.dispose(); await ctx.close();
    });
  }

  // ------------------------------------------------------------------------
  test('2. desktop : carte → Cayenne → assistant (sauce) → panier → commande À EMPORTER → confirmation → base → suivi', async ({ browser }) => {
    const ctx = await newCtx(browser, { mobile: false });
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const rec = recorder(page);
    const deliveryCalls = [];
    page.on('request', (r) => { if (/\/delivery\//.test(r.url())) deliveryCalls.push(r.method() + ' ' + r.url().replace(BASE, '')); });

    // ---- carte
    await gotoSite(page, '#menu');
    await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30_000 });
    const carte = await state(page, rec, 'D-02-carte');
    const nbProduits = await page.locator('.lc-menu-grid [aria-label^="Voir "]').count();
    const nomsCarte = await page.locator('.lc-menu-grid [aria-label^="Voir "]').evaluateAll((es) => es.map((e) => e.getAttribute('aria-label').replace(/^Voir /, '')));
    const reels = JSON.parse(execFileSync('php', ['artisan', 'tinker', '--execute', "echo json_encode(DB::table('items')->whereNull('deleted_at')->where('status',5)->pluck('name')->all());"], { cwd: REPO, encoding: 'utf8', timeout: 30_000 }).trim().split('\n').pop());
    const inventes = nomsCarte.filter((n) => !reels.includes(n));
    L.carte = { nbProduits, nomsCarte, absentsDeLaBase: inventes, imagesCassees: await page.evaluate(() => Array.from(document.images).filter((i) => i.complete && i.naturalWidth === 0 && i.getAttribute('src')).map((i) => i.getAttribute('src').slice(0, 100))) };
    check('D carte : produits affichés', nbProduits > 20, nbProduits);
    check('D carte : chaque produit affiché existe en base (items actifs)', inventes.length === 0, inventes);
    check('D carte : aucune image cassée', L.carte.imagesCassees.length === 0, L.carte.imagesCassees);
    check('D carte : console sans erreur', carte.entry.consoleErrors.length === 0, carte.entry.consoleErrors);

    // ---- produit + assistant
    await page.locator(`[aria-label="Voir ${PRODUIT}"]`).first().click();
    await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 15_000 });
    const fiche = (await page.locator('.lc-detail').innerText()).replace(/\s+/g, ' ');
    const prixFiche = euros(fiche)[0];
    await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
    await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15_000 });
    const { recap, journal } = await marcherWizard(page);
    L.assistant = { fiche: fiche.slice(0, 300), prixFiche, journal };
    expect(recap, 'récapitulatif de l’assistant atteint').toBeTruthy();
    await state(page, rec, 'D-03-assistant-recap', { recap });
    const sauceChoisie = journal.some((j) => j.on.some((o) => /Algérienne/.test(o)));
    check('D assistant : une sauce réelle (Algérienne) a été choisie', sauceChoisie, journal.map((j) => `${j.title}: ${j.on.join(',')}`));
    check('D assistant : total du récap ≥ prix de la fiche', recap.total >= prixFiche, { recap: recap.total, fiche: prixFiche });
    expect(await ajouterAuPanier(page)).toBeTruthy();

    // ---- panier
    const drawer = await ouvrirPanier(page);
    await page.waitForTimeout(600);
    const panierTxt = (await drawer.innerText()).replace(/\s+/g, ' ');
    const panierEuros = euros(panierTxt);
    const panierDom = await drawer.evaluate((d) => ({ lignes: Array.from(d.querySelectorAll('[class*="line"], [class*="item"]')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter((t) => /€/.test(t)).slice(0, 8) }));
    await state(page, rec, 'D-04-panier', { panierEuros });
    const totalPanier = lastEuro(panierTxt);
    L.panier = { texte: panierTxt.slice(0, 600), euros: panierEuros, totalPanier, ...panierDom };
    check('D panier : total du panier == total du récap de l’assistant', Math.abs(totalPanier - recap.total) < 0.005, { panier: totalPanier, recap: recap.total, euros: panierEuros });
    expect(totalPanier).toBeGreaterThan(0);

    // ---- page de commande : modes (livraison ?)
    await drawer.getByRole('button', { name: /Passer commande/ }).click();
    await page.waitForTimeout(1200);
    await versPageCommande(page);
    const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
    await expect(rg).toBeVisible({ timeout: 15_000 });
    const radios = await rg.locator('[role="radio"]').allInnerTexts();
    const lienUber = await page.locator('a[aria-label*="Uber Eats"]').evaluateAll((as) => as.map((a) => ({ txt: a.innerText.replace(/\s+/g, ' ').trim(), href: a.getAttribute('href'), target: a.getAttribute('target') })));
    const champAdresse = await page.locator('input[aria-label="Adresse de livraison"]').count();
    const cmd = await state(page, rec, 'D-05-commande-mode', { radios, lienUber });
    L.modeCommande = { radios: radios.map((r) => r.replace(/\s+/g, ' ')), lienUber, champAdresse, deliveryCallsJusquIci: deliveryCalls.slice() };
    check('D LIVRAISON (en ligne) : une seule tuile radio (À emporter) — pas de livraison propre', radios.length === 1 && /emporter/i.test(radios[0]), radios);
    check('D LIVRAISON (en ligne) : la tuile « Livraison » est un lien vers Uber Eats', lienUber.length === 1 && /ubereats\.com/.test(lienUber[0].href), lienUber);
    check('D LIVRAISON (en ligne) : aucun champ adresse, aucun appel /delivery/* (status, suggest, quote)', champAdresse === 0 && deliveryCalls.length === 0, deliveryCalls);
    expect(radios.length).toBeGreaterThan(0);

    // ---- paiement (pickup)
    const payBloc = page.locator('[role="radiogroup"][aria-label="Mode de paiement"]');
    await payBloc.scrollIntoViewIfNeeded();
    const modes = (await payBloc.locator('[role="radio"]').allInnerTexts()).map((s) => s.replace(/\s+/g, ' '));
    const surPlace = payBloc.getByRole('radio', { name: /Payer sur place/ });
    if ((await surPlace.getAttribute('aria-checked')) !== 'true') await surPlace.click();
    const ctaAvant = (await page.locator('.lcf-cta-bar-next').innerText()).replace(/\s+/g, ' ');
    const recapPage = (await page.locator('.lcf-page').innerText()).replace(/\s+/g, ' ');
    const cmdText = cmd.text;
    L.paiement = { modes, ctaAvant, totalCta: lastEuro(ctaAvant), creneaux: await page.locator('[aria-label="Créneau de retrait"] [role=radio]').allInnerTexts().catch(() => []) };
    check('D paiement : total du bouton == total du panier', Math.abs(lastEuro(ctaAvant) - totalPanier) < 0.005, { bouton: ctaAvant, panier: totalPanier });
    check('D paiement : aucune ligne « Livraison/Offerte » dans le récap d’un retrait', !/Offerte/.test(recapPage), (recapPage.match(/.{0,40}Offerte.{0,20}/) || [null])[0]);

    // ---- identification + commande
    resetLimiteurs();
    const email = `${TAG.toLowerCase()}-d@lecayenne-test.fr`;
    const phone = `0698${String(NOW).slice(-6)}`;
    await authentifier(page, rq, email, phone, `${TAG} Client`);
    await page.locator('.lcf-cta-bar-next').scrollIntoViewIfNeeded();
    await state(page, rec, 'D-06-avant-confirmer', { ctaAvant }, false);
    const sink = {};
    page.on('request', (req) => { if (req.method() === 'POST' && /\/api\/frontend\/order$/.test(req.url())) { try { sink.body = JSON.parse(req.postData() || '{}'); } catch (_e) { sink.body = null; } } });
    async function confirmer(label) {
      const orderResp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 90_000 });
      await page.locator('.lcf-cta-bar-next').click();
      const resp = await orderResp;
      const j = await resp.json().catch(() => ({}));
      const d = (j && j.data) || j || {};
      const o = { essai: label, http: resp.status(), id: d.id, serial: d.order_serial_no, totalApi: Number(d.total), corps: resp.status() >= 400 ? JSON.stringify(j).slice(0, 600) : undefined,
        envoye: sink.body && { clefs: Object.keys(sink.body), payment_method: sink.body.payment_method, order_type: sink.body.order_type, expected_total: sink.body.expected_total, items: JSON.stringify(sink.body.items).slice(0, 700) } };
      L.orders.push(o); writeLedger();
      note(`POST /api/frontend/order (${label}) -> HTTP ${o.http} id=${o.id} total=${o.totalApi} corps=${o.corps || ''}`);
      return o;
    }
    let o = await confirmer(PRODUIT);
    let produitCommande = PRODUIT; let totalAttendu = totalPanier; let ctaFinal = ctaAvant;
    if (o.http >= 400) {
      // Le client voit-il pourquoi ? (état capturé AVANT de contourner)
      await page.waitForTimeout(1500);
      const alerteViande = page.locator('[role=alert]', { hasText: /Viande/ }).first();
      const posAlerte = (await alerteViande.count()) ? await alerteViande.evaluate((e) => { const r = e.getBoundingClientRect(); return { top: Math.round(r.top + window.scrollY), texte: e.innerText.trim() }; }) : null;
      const posCta = await page.locator('.lcf-cta-bar-next').evaluate((e) => { const r = e.getBoundingClientRect(); return { top: Math.round(r.top + window.scrollY), fixe: getComputedStyle(e.closest('[class*=cta-bar]') || e).position }; });
      const scrollAvant = await page.evaluate(() => Math.round(window.scrollY));
      L.refusPosition = { alerte: posAlerte, cta: posCta, scrollYAuMomentDuRefus: scrollAvant, hauteurVue: 900 };
      if (await alerteViande.count()) await alerteViande.scrollIntoViewIfNeeded();
      const refus = await state(page, rec, 'D-06-commande-refusee-cayenne', { corps: o.corps, position: L.refusPosition });
      const alertes = refus.entry.alertsVisibles;
      L.refusCayenne = { http: o.http, corps: o.corps, alertesVisibles: alertes, toujoursSurLaPage: await page.locator('.lcf-page').count() === 1, envoye: o.envoye };
      check(`PRODUIT « ${PRODUIT} » commandable sur le site en ligne (assistant : « Choisis 2 viandes »)`, false, L.refusCayenne);
      check('REFUS : un message compréhensible est montré au client', alertes.some((a) => /viande/i.test(a)), alertes);
      // Contournement d'instrument pour finir le parcours : retirer le Cayenne, prendre un produit à 1 viande (Cheese Burger).
      // Comme un client : bouton « Menu » de la navigation (pas de rechargement).
      await page.getByRole('button', { name: 'Menu', exact: true }).first().click();
      await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30_000 });
      await settle(page);
      await page.locator('[aria-label="Voir Cheese Burger"]').first().click();
      await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 15_000 });
      await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
      await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15_000 });
      await page.waitForTimeout(800);
      const ouverture = await readStep(page);
      L.assistant2Ouverture = { ...ouverture, corps: (await page.locator('.lc-wiz').innerText()).replace(/\s+/g, ' ').slice(0, 400) };
      check('APRÈS REFUS : l’assistant du Cheese Burger s’ouvre à sa 1re étape (viande), pas sur un récap hérité', /VIANDE/i.test(ouverture.title), L.assistant2Ouverture);
      if (!/VIANDE/i.test(ouverture.title)) {
        await state(page, rec, 'D-06b-assistant-apres-refus', L.assistant2Ouverture, false);
        // contournement d'instrument : rechargement complet de la page (le panier est conservé par le site)
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 60_000 });
        await page.evaluate(() => { window.location.hash = '#menu'; });
        await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30_000 });
        await settle(page);
        await page.locator('[aria-label="Voir Cheese Burger"]').first().click();
        await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 15_000 });
        await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
        await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15_000 });
        await page.waitForTimeout(800);
        L.assistant2ApresRechargement = await readStep(page);
      }
      const w2 = await marcherWizard(page);
      expect(w2.recap, 'récap Cheese Burger').toBeTruthy();
      L.assistant2 = w2;
      expect(await ajouterAuPanier(page)).toBeTruthy();
      const dr2 = await ouvrirPanier(page);
      await dr2.getByRole('button', { name: `Retirer ${PRODUIT} du panier` }).click();
      await page.waitForTimeout(800);
      const pan2 = (await dr2.innerText()).replace(/\s+/g, ' ');
      expect(pan2).not.toMatch(new RegExp(`\\b${PRODUIT} \\+`));
      totalAttendu = lastEuro(pan2);
      L.panier2 = { texte: pan2.slice(0, 400), total: totalAttendu, recap: w2.recap.total };
      check('D panier 2 : total == récap Cheese Burger', Math.abs(totalAttendu - w2.recap.total) < 0.005, L.panier2);
      await dr2.getByRole('button', { name: /Passer commande/ }).click();
      await page.waitForTimeout(1200);
      await versPageCommande(page);
      const pb2 = page.locator('[role="radiogroup"][aria-label="Mode de paiement"]');
      await pb2.scrollIntoViewIfNeeded();
      const sp2 = pb2.getByRole('radio', { name: /Payer sur place/ });
      if ((await sp2.getAttribute('aria-checked')) !== 'true') await sp2.click();
      ctaFinal = (await page.locator('.lcf-cta-bar-next').innerText()).replace(/\s+/g, ' ');
      produitCommande = 'Cheese Burger';
      o = await confirmer(produitCommande);
    }
    expect(o.http, `commande : ${o.corps || ''}`).toBeLessThan(300);
    await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 45_000 });
    const conf = await state(page, rec, 'D-07-confirmation');
    const confTxt = conf.text.replace(/\s+/g, ' ');
    const confInfo = await page.evaluate(() => ({
      qr: Array.from(document.querySelectorAll('.lcf-ticket-qr [aria-label]')).map((e) => e.getAttribute('aria-label')),
      boutons: Array.from(document.querySelectorAll('.lcf-confirm button')).map((b) => b.innerText.replace(/\s+/g, ' ').trim()),
      ticket: (document.querySelector('.lcf-confirm') || document.body).innerText.replace(/\s+/g, ' ').slice(0, 900),
    }));
    const mTot = confInfo.ticket.match(/Total[^€]*€/i);
    const totalConf = lastEuro(mTot ? mTot[0] : confInfo.ticket);
    // ---- base
    const row = mysql(`SELECT id, order_type, payment_method, payment_status, status, subtotal, discount, delivery_charge, total, source_surface FROM orders WHERE id=${Number(o.id)}`).split('\t');
    const cols = ['id', 'order_type', 'payment_method', 'payment_status', 'status', 'subtotal', 'discount', 'delivery_charge', 'total', 'source_surface'];
    o.base = Object.fromEntries(cols.map((c, i) => [c, row[i]]));
    o.lignesBase = mysql(`SELECT oi.item_id, i.name, oi.quantity, oi.price, oi.total_price, LEFT(oi.item_variations,200), LEFT(oi.item_extras,160), LEFT(oi.composition_snapshot,300) FROM order_items oi JOIN items i ON i.id=oi.item_id WHERE oi.order_id=${Number(o.id)}`).split('\n');
    const nomClient = mysql(`SELECT u.name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=${Number(o.id)}`);
    L.confirmation = { ...confInfo, totalConfirmation: totalConf, nomClientBase: nomClient, delaisAffiches: (confTxt.match(/~\s?\d+[\s–-]*\d*\s?min/g) || []) };
    check('TOTAUX : panier == bouton == confirmation == API == base (orders.total)',
      [lastEuro(ctaFinal), totalConf, o.totalApi, Number(o.base.total)].every((x) => Math.abs(x - totalAttendu) < 0.005),
      { produit: produitCommande, panier: totalAttendu, bouton: lastEuro(ctaFinal), confirmation: totalConf, api: o.totalApi, base: Number(o.base.total) });
    check('BASE : commande à emporter (order_type=5 retrait ?) payée au comptoir, sans frais de livraison', Number(o.base.delivery_charge) === 0, o.base);
    check('BASE : la ligne est bien le produit choisi', o.lignesBase.some((l) => l.includes(`\t${produitCommande}\t`)), o.lignesBase);
    check('CONFIRMATION : délai affiché plausible (≤ 120 min)', L.confirmation.delaisAffiches.every((s) => Math.max(...(s.match(/\d+/g) || [0]).map(Number)) <= 120), L.confirmation.delaisAffiches);
    expect(Math.abs(Number(o.base.total) - totalAttendu)).toBeLessThan(0.005);

    // ---- suivi
    const suivi = page.getByRole('button', { name: /Suivre ma commande/ }).first();
    await expect(suivi).toBeVisible({ timeout: 10_000 });
    await suivi.click();
    await page.waitForTimeout(2500);
    const sv = await state(page, rec, 'D-08-suivi');
    L.suivi = { texte: sv.text.replace(/\s+/g, ' ').slice(0, 900), delais: (sv.text.match(/~\s?\d+[\s–-]*\d*\s?min/g) || []) };
    check('SUIVI : délai plausible (≤ 120 min)', L.suivi.delais.every((s) => Math.max(...(s.match(/\d+/g) || [0]).map(Number)) <= 120), L.suivi.delais);
    check('D parcours complet : aucun appel /delivery/* sur la version en ligne', deliveryCalls.length === 0, deliveryCalls);
    rec.dispose(); await ctx.close();
  });

  // ------------------------------------------------------------------------
  test('3. mobile 390x844 : panier → page de commande (mode, paiement), sans commander', async ({ browser }) => {
    const ctx = await newCtx(browser, { mobile: true });
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const rec = recorder(page);
    await produitDansPanier(page);
    const { recap } = await marcherWizard(page);
    expect(recap).toBeTruthy();
    expect(await ajouterAuPanier(page)).toBeTruthy();
    const drawer = await ouvrirPanier(page);
    const panierTxt = (await drawer.innerText()).replace(/\s+/g, ' ');
    await state(page, rec, 'M-04-panier', {}, false);
    await drawer.getByRole('button', { name: /Passer commande/ }).click();
    await page.waitForTimeout(1200);
    await versPageCommande(page);
    const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
    const radios = await rg.locator('[role="radio"]').allInnerTexts();
    const lienUber = await page.locator('a[aria-label*="Uber Eats"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
    const debordementX = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    const m = await state(page, rec, 'M-05-commande-mode', { radios, lienUber });
    const cta = (await page.locator('.lcf-cta-bar-next').innerText()).replace(/\s+/g, ' ');
    L.mobileCommande = { radios, lienUber, debordementX, totalPanier: lastEuro(panierTxt), cta, recap: recap.total };
    check('M LIVRAISON (en ligne) : 1 tuile À emporter + lien Uber Eats', radios.length === 1 && lienUber.length === 1, { radios, lienUber });
    check('M commande : pas de défilement horizontal', debordementX <= 1, debordementX);
    check('M commande : bouton == panier == récap', Math.abs(lastEuro(cta) - lastEuro(panierTxt)) < 0.005 && Math.abs(lastEuro(cta) - recap.total) < 0.005, L.mobileCommande);
    check('M commande : console sans erreur', m.entry.consoleErrors.length === 0, m.entry.consoleErrors);
    rec.dispose(); await ctx.close();
  });

  // ------------------------------------------------------------------------
  test('4. VARIANTE (meta feature-delivery=1 injectée, PAS en ligne) : code de livraison dormant vs devis serveur', async ({ browser }) => {
    for (const mobile of [false, true]) {
      const vp = mobile ? 'M' : 'D';
      const ctx = await newCtx(browser, { mobile, injectDelivery: true });
      const page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      const rec = recorder(page);
      const appels = [];
      page.on('request', (r) => { if (/\/delivery\/|nominatim|api-adresse/.test(r.url())) appels.push(r.method() + ' ' + r.url().slice(0, 140)); });
      await produitDansPanier(page);
      const { recap } = await marcherWizard(page);
      expect(recap).toBeTruthy();
      expect(await ajouterAuPanier(page)).toBeTruthy();
      const drawer = await ouvrirPanier(page);
      await drawer.getByRole('button', { name: /Passer commande/ }).click();
      await page.waitForTimeout(1200);
      await versPageCommande(page);
      const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
      const radios = await rg.locator('[role="radio"]').allInnerTexts();
      check(`V${vp} instrument : l’injection de la meta fait apparaître la tuile Livraison (2 tuiles)`, radios.length === 2, radios);
      expect(radios.length, 'la variante doit exposer la tuile Livraison, sinon l’instrument ne mesure rien').toBe(2);
      await rg.getByRole('radio', { name: /Livraison/ }).click();
      await page.waitForTimeout(600);
      const input = page.locator('input[aria-label="Adresse de livraison"]');
      await expect(input).toBeVisible();
      const adresses = mobile
        ? [{ id: 'oignies-mobile', saisie: '1 rue Jean Jaures 62590 Oignies' }]
        : [{ id: 'henin', saisie: '12 rue Elie Gruyelle 62110 Henin-Beaumont' }, { id: 'lille', saisie: '10 rue Nationale 59000 Lille' }];
      for (const adr of adresses) {
        const nbAvant = appels.length;
        await input.fill('');
        await input.pressSequentially(adr.saisie, { delay: 20 });
        await page.waitForTimeout(800);
        const appelsFrappe = appels.length - nbAvant;
        const champ = await input.evaluate((e) => ({ scrollW: e.scrollWidth, clientW: e.clientWidth, w: Math.round(e.getBoundingClientRect().width) }));
        const geoP = page.waitForResponse((r) => /nominatim|\/delivery\/quote/.test(r.url()), { timeout: 30_000 }).catch(() => null);
        await page.getByRole('button', { name: /^Calculer$/ }).click();
        await geoP;
        await page.waitForTimeout(1500);
        const section = page.locator('.lcf-section', { has: input });
        const sectionTxt = (await section.innerText()).replace(/\s+/g, ' ');
        const recapTxt = (await page.locator('.lcf-page').innerText()).replace(/\s+/g, ' ');
        const cta = (await page.locator('.lcf-cta-bar-next').innerText()).replace(/\s+/g, ' ');
        resetLimiteurs();
        const qr = await rq.post(`${BASE}/api/frontend/delivery/quote`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { q: adr.saisie } });
        const qj = await qr.json().catch(() => ({}));
        const km = (sectionTxt.match(/(\d+(?:[.,]\d+)?)\s*km\s*·/) || [null, null])[1];
        const rec2 = { vp, adresse: adr.id, saisie: adr.saisie, appelsPendantFrappe: appelsFrappe, champ, siteAffiche: { section: sectionTxt.slice(0, 400), km: km && Number(km.replace(',', '.')), frais: lastEuro(sectionTxt), cta, livraisonRecap: (recapTxt.match(/Livraison\s*[0-9,]+\s*€|Livraison\s*Offerte/) || [null])[0] },
          serveur: { http: qr.status(), allowed: qj.allowed, fee: qj.fee, distance_km: qj.distance_km, travel_minutes: qj.travel_minutes, suggest_uber_eats: qj.suggest_uber_eats, message: qj.message } };
        L.quotes.push(rec2); writeLedger();
        if (adr.id === 'henin' && !mobile) {
          check('V devis Hénin : frais affichés par le site == frais du serveur (/delivery/quote)', Math.abs(rec2.siteAffiche.frais - qj.fee) < 0.005, { site: rec2.siteAffiche.frais, serveur: qj.fee });
          check('V devis Hénin : distance affichée == distance aller simple du serveur', rec2.siteAffiche.km != null && Math.abs(rec2.siteAffiche.km - qj.distance_km) < 0.05, { site: rec2.siteAffiche.km, serveur: qj.distance_km, travel_minutes: qj.travel_minutes });
          check('V devis Hénin : durée affichée', /min/.test(sectionTxt), sectionTxt.slice(0, 200));
          await state(page, rec, `V${vp}-01-adresse-henin`, rec2);
        } else if (adr.id === 'lille') {
          check('V Lille (hors zone) : refus + proposition Uber Eats, aucun prix', qj.allowed === false && !/\d+[.,]\d{2}\s*€/.test(sectionTxt) && /uber/i.test(sectionTxt), { site: sectionTxt.slice(0, 300), serveur: rec2.serveur });
          await state(page, rec, `V${vp}-02-adresse-lille`, rec2);
        } else {
          await input.scrollIntoViewIfNeeded();
          rec2.calculDeclencheSurMobile = appels.some((a) => /nominatim/.test(a));
          check('VM mobile : le champ adresse n’est pas tronqué (scrollWidth ≤ clientWidth)', champ.scrollW <= champ.clientW + 1, champ);
          await state(page, rec, `V${vp}-01-adresse-mobile`, rec2);
        }
      }
      L[`variante${vp}Appels`] = appels;
      rec.dispose(); await ctx.close();
    }
  });

  // ------------------------------------------------------------------------
  test('5. réseau : aucune requête vers la production', async () => {
    L.bilanReseau = { prodBloquees: L.prodBloquees, origines: L.origines };
    check('RÉSEAU : 0 requête vers l’hôte de production / API lecayenne.fr', L.prodBloquees.length === 0, L.prodBloquees.slice(0, 10));
    writeLedger();
    expect(Object.keys(L.origines).length).toBeGreaterThan(0);
  });
});
