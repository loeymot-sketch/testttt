// [SITE LIVRAISON-PAIEMENT 2026-10-24] Annonce « la livraison est lancée » + page de paiement conforme (à emporter / livraison)
// + trois règlements en livraison (en ligne, carte à la livraison, espèces à la livraison), de bout en bout jusqu'à la remise.
// Scénarios P-1 … P-9 (chaque vérité prouvée par DEUX moyens : écran + base/API/journal ; assertions bloquantes).
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/site-livraison-paiement-2026-10-24/P-paiement.spec.js --workers=1 --retries=0 --reporter=line
//
// Instrument : garde réseau (zéro requête production / Uber / Mollie), commandes de test « E2E-PAY-… » annulées (statut 16) en fin
// de passe SAUF celles livrées par le livreur de test ; disque contraint : ≤ 15 PNG, plein écran seulement pour la page de paiement.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');

const REPO = path.resolve(__dirname, '..', '..', '..');
const SITE = 'http://127.0.0.1:8013';
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const OUT = path.join(REPO, 'reports', 'test-e2e', 'site-livraison-paiement-2026-10-24', 'captures-P');
fs.mkdirSync(OUT, { recursive: true });
const SCRATCH = '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/2c3726ad-78a8-4013-b7aa-66c1e1619e24/scratchpad';
const SERVED = process.env.S_SITE_DIR || path.join(SCRATCH, 'site-work');
const WORKING_COPY = process.env.S_WORKING_COPY || '/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne';
const PROD_RX = /vps-418872ac|ovh\.net|(^|\.)lecayenne\.fr|auth\.uber\.com|api\.uber\.com/i;
const MOLLIE_RX = /js\.mollie\.com/i;
const PROD_ABORT_OK = (u) => !/^http:\/\/127\.0\.0\.1:(8013|8766|6001)\b/.test(u);

function envValue(key) {
  const m = fs.readFileSync(path.join(REPO, '.env'), 'utf8').match(new RegExp(`^${key}=(.*)$`, 'm'));
  return m ? m[1].trim().replace(/^"|"$/g, '') : '';
}
const API_KEY = envValue('MIX_API_KEY');
const NOW = Date.now();
const TAG = `E2E-PAY-${String(NOW).slice(-6)}`;
const PNG_CAP = 15;
let pngUsed = 0;
const HENIN = '12 rue Elie Gruyelle 62110 Henin-Beaumont';
const COURIER = { id: 10, email: 'livreur.e2e@lecayenne.test', password: 'Livreur123!', name: 'Livreur E2E' };
const POS = { email: 'pos@lecayenne.fr', password: '123456' };

test.describe.configure({ mode: 'serial' });
test.setTimeout(Number(process.env.S_TIMEOUT) || 600_000);
test.use({ video: 'off', trace: 'off', screenshot: 'off', locale: 'fr-FR', timezoneId: 'Europe/Paris',
  launchOptions: { args: ['--lang=fr-FR', '--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks'] } });

// ---------------------------------------------------------------- registre
const L = { tag: TAG, startedAt: new Date().toISOString(), states: [], checks: [], prodBloquees: [], orders: [], notes: [], textes: {}, defauts: [] };
const note = (s) => { L.notes.push(s); console.log('[PAY] ' + s); };
const writeLedger = () => fs.writeFileSync(path.join(OUT, 'ledger.json'), JSON.stringify(L, null, 2));
function check(id, ok, detail) { L.checks.push({ id, ok: !!ok, detail }); console.log(`[PAY CHECK] ${ok ? 'OK ' : 'KO '} ${id} ${detail !== undefined ? JSON.stringify(detail).slice(0, 300) : ''}`); writeLedger(); }
function mysql(sql) { return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], { encoding: 'utf8', timeout: 20_000 }).trim(); }
function php(code) {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: REPO, encoding: 'utf8', timeout: 90_000 });
  const lignes = out.trim().split('\n').filter(Boolean);
  for (let i = lignes.length - 1; i >= 0; i -= 1) { try { return JSON.parse(lignes[i]); } catch (_) { /* bruit */ } }
  throw new Error('tinker sans JSON : ' + out.slice(-400));
}
const norm = (s) => String(s || '').replace(/[  ]/g, ' ').replace(/\s+/g, ' ').trim();
const euros = (s) => [...norm(s).matchAll(/(\d+)[.,](\d{2})\s*€/g)].map((m) => Number(`${m[1]}.${m[2]}`));
const lastEuro = (s) => { const a = euros(s); return a.length ? a[a.length - 1] : NaN; };
const sha16 = (p) => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex').slice(0, 16);
const eur = (n) => Number(n).toFixed(2).replace('.', ',');
function resetLimiteurs() {
  execFileSync('php', ['artisan', 'tinker', '--execute', `$l = app(\\Illuminate\\Cache\\RateLimiter::class); $l->clear(sha1("|127.0.0.1")); $l->clear("otp-verify".sha1("|127.0.0.1")); $l->clear("signup-register".sha1("|127.0.0.1")); foreach (['delivery-suggest','delivery-quote','delivery-status','delivery-waves'] as $n) { foreach (['127.0.0.1','::1'] as $ip) { $l->clear(md5($n.$ip)); } } echo 'ok';`], { cwd: REPO, encoding: 'utf8', timeout: 30_000 });
}
function chaineFiscale() {
  const out = execFileSync('php', ['artisan', 'fiscal:verify-chain', '--all'], { cwd: REPO, encoding: 'utf8', timeout: 180_000 });
  return { ok: /CHAIN OK on every active branch/.test(out) && !/BROKEN|FAIL/i.test(out), fin: out.trim().split('\n').slice(-2).join(' | ') };
}

// ---------------------------------------------------------------- instruments
let AUTH = null;
async function newCtx(browser, { mobile = false, suspendue = false, android = false } = {}) {
  const base = mobile
    ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1, locale: 'fr-FR', timezoneId: 'Europe/Paris' }
    : { viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' };
  if (android) base.userAgent = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';
  const ctx = await browser.newContext(base);
  // Garde réseau : tout ce qui n'est pas la copie locale du site, l'API locale ou le diffuseur local est consigné ; la production,
  // Uber et Mollie sont BLOQUÉS. Le test final exige 0 requête vers l'un d'eux.
  await ctx.route((url) => PROD_RX.test(url.href), (route) => { L.prodBloquees.push(route.request().url().slice(0, 200)); writeLedger(); return route.abort('blockedbyclient'); });
  // Mollie : le banc ne charge JAMAIS le script tiers (tentative de la page avortée côté navigateur, aucune requête ne part).
  await ctx.route((url) => MOLLIE_RX.test(url.href), (route) => { L.mollieTentativesAvortees = (L.mollieTentativesAvortees || 0) + 1; return route.abort('blockedbyclient'); });
  if (suspendue) {
    await ctx.route((url) => /\/api\/frontend\/delivery\/status/.test(url.href), (route) => route.fulfill({
      status: 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify({ status: true, enabled: false, grid: null }),
    }));
  }
  if (AUTH) await ctx.addInitScript(([t, p]) => { try { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); } catch (_e) { /* storage */ } }, [AUTH.token, AUTH.phone]);
  return ctx;
}
function recorder(page) {
  const r = attachMegaAuditRecorder(page, OUT);
  return { ...r, snap: async (name, png = true, plein = false) => {
    if (png && pngUsed < PNG_CAP) {
      pngUsed += 1;
      if (plein) {
        const orig = page.screenshot.bind(page);
        page.screenshot = (o) => orig({ ...o, fullPage: true });
        try { return await r.snap(name); } finally { page.screenshot = orig; }
      }
      return r.snap(name);
    }
    const orig = page.screenshot.bind(page);
    page.screenshot = async () => Buffer.alloc(0);
    try { return await r.snap(name); } finally { page.screenshot = orig; }
  } };
}
async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: 3_500 }).catch(() => { /* polling */ });
  await page.waitForTimeout(900);
}
async function gotoSite(page, hash = '') {
  await page.goto('about:blank');
  await page.goto(`${SITE}/${hash}`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 60_000 });
  await settle(page);
}
const visibleText = (page) => page.evaluate(() => String(document.body.innerText || '').replace(/[  ]/g, ' '));
const tunnelText = async (page) => norm(await page.evaluate(() => String((document.querySelector('.lcf-page') || document.body).innerText || '')));
async function state(page, rec, id, extra = {}, png = true, plein = false) {
  await settle(page);
  const text = await visibleText(page);
  await rec.snap(id, png, plein);
  let net = []; let con = [];
  try { net = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.network.json`), 'utf8')); } catch (_e) { /* absent */ }
  try { con = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.console.json`), 'utf8')); } catch (_e) { /* absent */ }
  const tous4xx = net.filter((x) => x.status >= 400).map((x) => `${x.method} ${x.url.replace(BASE, '').replace(SITE, '').slice(0, 110)} -> ${x.status}`);
  // Bruit d'ENVIRONNEMENT documenté : en local, l'envoi du code e-mail échoue (422 sur guest-signup/email-login, pas de SMTP).
  const bruitEnv = tous4xx.filter((x) => /guest-signup\/email-login -> 422/.test(x));
  const http4xx = tous4xx.filter((x) => !bruitEnv.includes(x));
  let aEcarter = bruitEnv.length;
  // Bruit d'INSTRUMENT : js.mollie.com est volontairement bloqué par le banc (aucun appel tiers) → « net::ERR_BLOCKED_BY_CLIENT ».
  const conErr = con.filter((c) => /error|pageerror/.test(c.level)).map((c) => c.text.slice(0, 200))
    .filter((t) => !/net::ERR_BLOCKED_BY_CLIENT/.test(t))
    .filter((t) => { if (aEcarter > 0 && /Failed to load resource.*\b422\b/.test(t)) { aEcarter -= 1; return false; } return true; });
  const entry = { id, url: page.url().replace(SITE, ''), ...extra, http4xx, consoleErrors: conErr, bruitEnvironnement: bruitEnv };
  L.states.push(entry); writeLedger();
  return { entry, text };
}

// ---------------------------------------------------------------- pilotage du tunnel (gestes de site-livraison.spec.js)
async function readStep(page) {
  const r = await page.evaluate(() => {
    const t = (sel) => { const e = document.querySelector(sel); return e ? String(e.innerText || '').replace(/\s+/g, ' ').trim() : ''; };
    const next = document.querySelector('.lc-wiz-foot-next');
    return { title: t('.lc-wiz-title'), next: next ? String(next.innerText || '').replace(/\s+/g, ' ') : '', disabled: next ? (next.disabled || next.getAttribute('aria-disabled') === 'true') : null };
  });
  return { ...r, total: lastEuro(r.next) };
}
async function marcherWizard(page) {
  await page.locator('.lc-wiz-title').first().waitFor({ state: 'visible', timeout: 15_000 });
  for (let s = 0; s < 18; s++) {
    const step = await readStep(page);
    if (/Ajouter au panier/i.test(step.next)) return { recap: step };
    if (/sauce/i.test(step.title)) {
      const alg = page.locator('.lc-wiz-choice', { hasText: /Algérienne/ }).first();
      if (await alg.count() && !(await alg.evaluate((e) => e.classList.contains('is-on')))) { await alg.click(); await page.waitForTimeout(250); }
    }
    for (let k = 0; k < 3 && (await readStep(page)).disabled; k++) { await page.locator('.lc-wiz-choice:not(.is-on)').first().click(); await page.waitForTimeout(250); }
    await page.locator('.lc-wiz-foot-next').click({ timeout: 10_000 });
    await page.waitForTimeout(450);
  }
  return { recap: null };
}
async function ajouterAuPanier(page) {
  await page.locator('.lc-wiz-foot-next').click({ timeout: 10_000 });
  const t0 = Date.now();
  while (Date.now() - t0 < 25_000) {
    if (await page.locator('.lc-cart-drawer.is-open').count()) return { ok: true };
    if (!(await page.locator('.lc-wiz').count())) return { ok: true };
    await page.waitForTimeout(250);
  }
  return { ok: false };
}
async function ajouterProduit(page, nom) {
  await gotoSite(page, '#menu');
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30_000 });
  await page.locator(`[aria-label="Voir ${nom}"]`).first().click();
  await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 15_000 });
  await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
  await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15_000 });
  const w = await marcherWizard(page);
  expect(w.recap, `récapitulatif de l'assistant atteint pour ${nom}`).toBeTruthy();
  const ajout = await ajouterAuPanier(page);
  expect(ajout.ok, `${nom} ajouté au panier`).toBe(true);
}
async function panierVersCommande(page) {
  const drawer = page.locator('.lc-cart-drawer.is-open');
  if (!(await drawer.count())) { await page.waitForTimeout(800); await page.locator('.lc-nav-btn-cart').first().click(); }
  await expect(drawer).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(500);
  const panierTxt = norm(await drawer.innerText());
  await drawer.getByRole('button', { name: /Passer commande/ }).click();
  await page.waitForTimeout(1200);
  for (let i = 0; i < 6 && !(await page.locator('.lcf-page').count()); i++) {
    const b = page.getByRole('button', { name: /Non merci|Continuer|Régler ma commande/ }).first();
    if (await b.isVisible({ timeout: 1500 }).catch(() => false)) await b.click();
    await page.waitForTimeout(1200);
  }
  await expect(page.locator('.lcf-page')).toBeVisible({ timeout: 20_000 });
  await settle(page);
  return panierTxt;
}
async function versPaiement(page, nom = 'Cheese Burger') {
  await ajouterProduit(page, nom);
  const t = await panierVersCommande(page);
  return lastEuro(t);
}
async function choisirLivraison(page) {
  const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
  await expect(rg).toBeVisible({ timeout: 15_000 });
  await rg.getByRole('radio', { name: /Livraison/ }).click();
  await expect(page.locator('input[aria-label="Adresse de livraison"]')).toBeVisible({ timeout: 10_000 });
}
async function choisirEmporter(page) {
  const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
  await rg.getByRole('radio', { name: /emporter/i }).click();
  await page.waitForTimeout(500);
}
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
  await input.fill(choisi);
  await page.waitForTimeout(500);
  const quoteP = page.waitForResponse((r) => /\/delivery\/quote/.test(r.url()) && r.request().method() === 'POST', { timeout: 25_000 });
  await page.getByRole('button', { name: /^Calculer$/ }).click();
  const qr = await quoteP;
  const quote = await qr.json().catch(() => null);
  await page.waitForTimeout(900);
  return { options, choisi, quote, status: qr.status() };
}
const payGroup = (page) => page.locator('[role="radiogroup"][aria-label="Mode de paiement"]');
async function reglements(page) {
  return page.evaluate(() => Array.from(document.querySelectorAll('[role="radiogroup"][aria-label="Mode de paiement"] [role="radio"]')).map((b) => ({
    nom: (b.querySelector('.lcf-paymethod-name') || {}).innerText || '', meta: (b.querySelector('.lcf-paymethod-meta') || {}).innerText || '', on: b.getAttribute('aria-checked') === 'true',
  })));
}
async function choisirReglement(page, nom) {
  const r = payGroup(page).locator('[role="radio"]', { has: page.locator('.lcf-paymethod-name', { hasText: new RegExp(`^\\s*${nom}\\s*$`) }) });
  expect(await r.count(), `règlement « ${nom} » proposé`).toBe(1);
  if ((await r.getAttribute('aria-checked')) !== 'true') await r.click();
  await page.waitForTimeout(300);
  await expect(r).toHaveAttribute('aria-checked', 'true');
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
async function sIdentifier(page, rq, tag) {
  if (AUTH) { await page.waitForTimeout(300); return; }
  resetLimiteurs();
  await authentifier(page, rq, `${TAG.toLowerCase()}-${tag}@lecayenne-test.fr`, `0697${String(NOW).slice(-6)}`, `${TAG} Client`);
}
async function loginToken(rq, email, password) {
  const r = await rq.post(`${BASE}/api/auth/login`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { email, password } });
  const j = await r.json().catch(() => ({}));
  return j.token || (j.data && j.data.token) || null;
}
const ROW_COLS = ['id', 'order_type', 'payment_method', 'door_payment_mode', 'payment_status', 'status', 'pos_payment_method', 'fiscal_sequence_no', 'subtotal', 'delivery_charge', 'total', 'delivery_boy_id'];
function ligneBase(id) {
  const row = mysql(`SELECT ${ROW_COLS.map((c) => `IFNULL(${c},'NULL')`).join(', ')} FROM orders WHERE id=${Number(id)}`).split('\t');
  return Object.fromEntries(ROW_COLS.map((c, i) => [c, row[i]]));
}
/** Clique « Confirmer » et rend la réponse de POST /api/frontend/order + le corps ET la clé d'idempotence envoyés. */
async function confirmer(page, label, avant = null) {
  let envoye = null; let cle = null;
  const onReq = (req) => { if (req.method() === 'POST' && /\/api\/frontend\/order$/.test(req.url())) { try { envoye = JSON.parse(req.postData() || '{}'); } catch (_e) { envoye = null; } cle = req.headers()['x-idempotency-key'] || null; } };
  page.on('request', onReq);
  const orderResp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 90_000 });
  if (avant) await avant();
  await page.locator('.lcf-cta-bar-next').click();
  const resp = await orderResp;
  page.off('request', onReq);
  const j = await resp.json().catch(() => ({}));
  const d = (j && j.data) || j || {};
  const o = { label, http: resp.status(), id: d.id, serial: d.order_serial_no, totalApi: Number(d.total), corps: resp.status() >= 400 ? JSON.stringify(j).slice(0, 500) : undefined, envoye, cle };
  if (o.id) L.orders.push({ id: o.id, label });
  writeLedger();
  note(`POST /api/frontend/order (${label}) -> HTTP ${o.http} id=${o.id} total=${o.totalApi} ${o.corps || ''}`);
  return o;
}
async function suivre(page, rec, id, png) {
  await page.getByRole('button', { name: /Suivre ma commande/ }).first().click();
  await page.waitForTimeout(2500);
  return state(page, rec, id, {}, png);
}
const MOTS_RETRAIT = /retrait|QR|comptoir|Présente ce|Tu paies|Ticket retrait|Récupéré|chercher/i;
const MOTS_BRUTS = /Label\.|\bkiosk\.\w|\bundefined\b|\[object|NaN|\{\{|\bnull\b|payment\.\w+\.\w+/;
const TUTOIEMENT = /(^|[^\p{L}])(tu|ton|ta|tes|toi|te|t')(?![\p{L}])/iu;

// ============================================================================
let rq; let PRE_CHAINE = null;
const ORD = {}; // commandes de test par clé : D_cash, D_card, M_cash, M_card, D_emporter, M_emporter, ...
test.describe('Site — annonce livraison + paiement conforme (copie corrigée servie sur :8013)', () => {
  test.beforeAll(async ({ playwright }) => { rq = await playwright.request.newContext(); });
  test.afterAll(async () => {
    try {
      const tok = await loginToken(rq, 'admin@lecayenne.fr', '123456');
      for (const o of L.orders.filter((x) => x.id && !x.livree)) {
        const r = await rq.post(`${BASE}/api/admin/online-order/change-status/${o.id}`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', Authorization: `Bearer ${tok}`, 'X-Idempotency-Key': crypto.randomUUID() }, data: { status: 16, reason: `${TAG} test automatique` } });
        o.annulation = r.status(); if (r.status() >= 300) o.annulationCorps = (await r.text()).slice(0, 300);
        o.statutApres = mysql(`SELECT status FROM orders WHERE id=${Number(o.id)}`);
        note(`annulation commande de test ${o.id} (${o.label}) -> HTTP ${o.annulation}, status=${o.statutApres} ${o.annulationCorps || ''}`);
      }
    } catch (e) { note('annulation impossible : ' + String(e.message).slice(0, 160)); }
    L.finishedAt = new Date().toISOString(); L.pngUtilises = pngUsed;
    writeLedger();
    if (rq) await rq.dispose().catch(() => {});
  });

  // ------------------------------------------------------------------------
  test('0. instrument : copie corrigée servie, API locale, livraison active, chaîne fiscale AVANT', async () => {
    const idx = fs.readFileSync(path.join(SERVED, 'index.html'), 'utf8');
    const meta = (n) => (idx.match(new RegExp(`<meta name="${n}" content="([^"]*)"`)) || [null, null])[1];
    expect(meta('api-base-url'), 'le site servi parle à l’API LOCALE').toBe('http://127.0.0.1:8766');
    expect(meta('api-key') === API_KEY, 'clé d’API locale').toBe(true);
    const servi = await rq.get(`${SITE}/compiled/funnel.js`);
    expect(servi.status()).toBe(200);
    const shaServi = crypto.createHash('sha256').update(await servi.body()).digest('hex').slice(0, 16);
    const shaCopie = sha16(path.join(WORKING_COPY, 'compiled', 'funnel.js'));
    const servi2 = await rq.get(`${SITE}/compiled/racine.js`);
    const shaServi2 = crypto.createHash('sha256').update(await servi2.body()).digest('hex').slice(0, 16);
    L.instrument = { shaServi, shaCopie, shaRacineServi: shaServi2, shaRacineCopie: sha16(path.join(WORKING_COPY, 'compiled', 'racine.js')), balise: (idx.match(/compiled\/funnel\.js\?v=[^"']+/) || [null])[0] };
    L.instrument.mutant = !!process.env.S_MUTANT;
    if (!process.env.S_MUTANT) { // S_MUTANT=1 : preuve que les tests mordent (copie volontairement altérée)
      expect(shaServi, 'le funnel servi est le compilé de la copie de travail').toBe(shaCopie);
      expect(shaServi2, 'racine.js servi = compilé de la copie de travail').toBe(L.instrument.shaRacineCopie);
    }
    const st = await rq.get(`${BASE}/api/frontend/delivery/status`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } });
    const sj = await st.json();
    L.instrument.statutServeur = sj;
    expect(sj.enabled, 'précondition : livraison ACTIVE côté serveur').toBe(true);
    PRE_CHAINE = chaineFiscale();
    L.chaineFiscaleAvant = PRE_CHAINE;
    check('NF525 : fiscal:verify-chain --all OK AVANT', PRE_CHAINE.ok, PRE_CHAINE.fin);
    expect(PRE_CHAINE.ok, PRE_CHAINE.fin).toBe(true);
    writeLedger();
  });

  // ------------------------------------------------------------------------ P-1
  for (const mobile of [false, true]) {
    const vp = mobile ? 'M' : 'D';
    test(`P-1${mobile ? 'b' : 'a'}. ${vp} annonce « la livraison est lancée » : visible/absente selon le serveur, bouton, fermeture mémorisée, absente au paiement`, async ({ browser }) => {
      const ctx = await newCtx(browser, { mobile });
      const page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      const rec = recorder(page);
      await gotoSite(page);
      const annonce = page.locator('[data-testid="annonce-livraison"]');
      await expect(annonce, 'annonce visible sur l’accueil').toBeVisible({ timeout: 15_000 });
      const txt = norm(await annonce.innerText());
      const api = await (await rq.get(`${BASE}/api/frontend/delivery/status`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } })).json();
      check(`${vp} P-1 texte exact + serveur enabled (2 moyens)`, /La livraison est lancée !/.test(txt) && /À Hénin-Beaumont et jusqu'à 10 km, dès 3,90 €\./.test(txt) && api.enabled === true, { txt, api });
      expect(txt).toContain('La livraison est lancée !');
      expect(txt).toContain("À Hénin-Beaumont et jusqu'à 10 km, dès 3,90 €.");
      expect(api.enabled).toBe(true);
      // tout en haut : la bande est au-dessus de la barre de navigation
      const geo = await page.evaluate(() => {
        const a = document.querySelector('[data-testid="annonce-livraison"]').getBoundingClientRect();
        const n = document.querySelector('.lc-nav, nav, header');
        const nr = n ? n.getBoundingClientRect() : null;
        return { aTop: Math.round(a.top), aBottom: Math.round(a.bottom), aH: Math.round(a.height), navTop: nr ? Math.round(nr.top) : null, scrollW: document.documentElement.scrollWidth, vw: window.innerWidth };
      });
      check(`${vp} P-1 annonce tout en haut (top=0) au-dessus de la nav, pas de défilement horizontal`, geo.aTop === 0 && (geo.navTop === null || geo.navTop >= geo.aBottom - 1) && geo.scrollW <= geo.vw + 1, geo);
      expect(geo.aTop, 'annonce collée en haut de page').toBe(0);
      if (geo.navTop !== null) expect(geo.navTop).toBeGreaterThanOrEqual(geo.aBottom - 1);
      expect(geo.scrollW).toBeLessThanOrEqual(geo.vw + 1);
      // « petite » annonce : au plus 100 px de haut sur téléphone (127 px mesurés avant le correctif CSS), 60 px sur ordinateur
      check(`${vp} P-1 annonce « petite » : hauteur ${geo.aH} px ≤ ${mobile ? 100 : 60}`, geo.aH <= (mobile ? 100 : 60), geo.aH);
      expect(geo.aH, 'hauteur de l’annonce').toBeLessThanOrEqual(mobile ? 100 : 60);
      // croix ≥ 44 px
      const bx = await annonce.getByRole('button', { name: /Fermer l'annonce/ }).boundingBox();
      const cta = await annonce.getByRole('button', { name: 'Commander' }).boundingBox();
      check(`${vp} P-1 croix ≥ 44×44 px`, bx.width >= 44 && bx.height >= 44, { croix: bx, commander: { w: Math.round(cta.width), h: Math.round(cta.height) } });
      expect(bx.width).toBeGreaterThanOrEqual(44); expect(bx.height).toBeGreaterThanOrEqual(44);
      if (cta.height < 44) L.defauts.push({ id: 'P1-commander-36px', severite: 'P3', detail: `bouton « Commander » de l'annonce : ${Math.round(cta.width)}×${Math.round(cta.height)} px (< 44 px de cible tactile conseillée)`, ou: 'styles-v8-vitrine.css:733 (.lc-annonce-cta min-height:36px)' });
      const a1 = await state(page, rec, `${vp}-P1-annonce-accueil`, { annonce: txt, geo }, true);
      expect(a1.entry.consoleErrors, 'console propre (accueil)').toEqual([]);

      // bouton « Commander » → page menu
      await annonce.getByRole('button', { name: 'Commander' }).click();
      await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 20_000 });
      const hashMenu = await page.evaluate(() => window.location.hash);
      check(`${vp} P-1 « Commander » → page menu (hash + grille)`, /^#menu/.test(hashMenu), hashMenu);
      expect(hashMenu).toMatch(/^#menu/);
      await expect(annonce, 'annonce encore présente sur le menu').toBeVisible();

      // présente sur les autres pages listées
      const pages = [];
      for (const h of ['#avis', '#reseaux', '#loyalty', '#orders']) {
        await gotoSite(page, h);
        pages.push({ h, visible: await annonce.isVisible() });
      }
      check(`${vp} P-1 annonce sur avis/réseaux/fidélité/commandes`, pages.every((p) => p.visible), pages);
      expect(pages.every((p) => p.visible), JSON.stringify(pages)).toBe(true);

      // fermée (×) → reste fermée après rechargement ; mémorisée en stockage
      await gotoSite(page);
      await annonce.getByRole('button', { name: /Fermer l'annonce/ }).click();
      await expect(annonce).toHaveCount(0);
      const mem = await page.evaluate(() => localStorage.getItem('lc.annonce.livraison.v1'));
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 30_000 });
      await settle(page);
      const apresReload = await annonce.count();
      check(`${vp} P-1 fermée → reste fermée au rechargement (écran + localStorage='1')`, apresReload === 0 && mem === '1', { apresReload, mem });
      expect(mem).toBe('1'); expect(apresReload).toBe(0);
      rec.dispose(); await ctx.close();

      // suspendue côté serveur → ABSENTE (jamais de promesse non tenue)
      const ctx2 = await newCtx(browser, { mobile, suspendue: true });
      const p2 = await ctx2.newPage();
      await gotoSite(p2);
      const nSus = await p2.locator('[data-testid="annonce-livraison"]').count();
      check(`${vp} P-1 suspendue (delivery/status enabled:false) → annonce ABSENTE`, nSus === 0, nSus);
      expect(nSus).toBe(0);
      await ctx2.close();

      // ABSENTE sur la page de paiement (contexte neuf : l'annonce n'a PAS été fermée)
      const ctx3 = await newCtx(browser, { mobile });
      const p3 = await ctx3.newPage();
      await p3.emulateMedia({ reducedMotion: 'reduce' });
      await gotoSite(p3);
      const avantPaiement = await p3.locator('[data-testid="annonce-livraison"]').count();
      await versPaiement(p3);
      const surPaiement = await p3.locator('[data-testid="annonce-livraison"]').count();
      check(`${vp} P-1 ABSENTE sur la page de paiement (présente à l'accueil du même contexte)`, avantPaiement === 1 && surPaiement === 0, { avantPaiement, surPaiement });
      expect(avantPaiement).toBe(1); expect(surPaiement).toBe(0);
      await ctx3.close();
    });
  }

  // ------------------------------------------------------------------------ P-2
  for (const mobile of [false, true]) {
    const vp = mobile ? 'M' : 'D';
    test(`P-2${mobile ? 'b' : 'a'}. ${vp} paiement À EMPORTER : comportement à emporter, commande espèces 201`, async ({ browser }) => {
      const ctx = await newCtx(browser, { mobile });
      const page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      const rec = recorder(page);
      const sousTotal = await versPaiement(page);
      const regs = await reglements(page);
      const noms = regs.map((r) => norm(r.nom));
      const texte = await tunnelText(page);
      const nAdresse = await page.locator('input[aria-label="Adresse de livraison"]').count();
      const nTiming = await page.locator('[data-testid="delivery-timing"]').count();
      const cta = norm(await page.locator('.lcf-cta-bar-next').innerText());
      const p2 = await state(page, rec, `${vp}-P2-paiement-emporter`, { noms }, !mobile, true);
      check(`${vp} P-2 règlements à emporter = « Payer sur place » + carte en ligne ; rien de livraison`, noms.includes('Payer sur place') && noms.some((n) => /^Carte bancaire/.test(n))
        && !noms.some((n) => /livraison/i.test(n)) && nTiming === 0 && nAdresse === 0 && !/Carte à la livraison|Espèces à la livraison/.test(texte), { noms, nTiming, nAdresse });
      expect(noms).toContain('Payer sur place');
      expect(noms.some((n) => /^Carte bancaire/.test(n))).toBe(true);
      expect(noms.some((n) => /livraison/i.test(n))).toBe(false);
      expect(nTiming, 'aucun message de préparation/trajet à emporter').toBe(0);
      expect(nAdresse, 'pas de bloc adresse à emporter').toBe(0);
      expect(texte).not.toMatch(/Carte à la livraison|Espèces à la livraison/);
      expect(p2.entry.consoleErrors).toEqual([]);
      await choisirReglement(page, 'Payer sur place');
      await sIdentifier(page, rq, 'e' + vp);
      await page.locator('.lcf-cta-bar-next').scrollIntoViewIfNeeded();
      const o = await confirmer(page, `${vp} à emporter espèces`);
      expect(o.http, o.corps).toBe(201);
      const base = ligneBase(o.id);
      ORD[`${vp}_emporter`] = { ...o, base };
      check(`${vp} P-2 commande à emporter : 201, base order_type=10, door_payment_mode NULL, corps sans door_payment`,
        o.http === 201 && base.order_type === '10' && base.door_payment_mode === 'NULL' && !('door_payment' in o.envoye) && Math.abs(Number(base.total) - lastEuro(cta)) < 0.005, { base, cta, corps: Object.keys(o.envoye) });
      expect(base.order_type).toBe('10');
      expect(base.door_payment_mode).toBe('NULL');
      expect('door_payment' in o.envoye).toBe(false);
      expect(Number(base.delivery_charge)).toBe(0);
      expect(Math.abs(Number(base.total) - lastEuro(cta))).toBeLessThan(0.005);
      await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 45_000 });
      const conf = norm(await tunnelText(page));
      expect(conf, 'à emporter : le texte de retrait reste').toMatch(/Présente ce QR à la caisse/);
      expect(sousTotal).toBeGreaterThan(0);
      rec.dispose(); await ctx.close();
    });
  }

  // ------------------------------------------------------------------------ P-3 + P-4 / P-5
  for (const mobile of [false, true]) {
    const vp = mobile ? 'M' : 'D';
    for (const mode of ['cash', 'card']) {
      test(`P-${mode === 'cash' ? '3+4' : '5'}${mobile ? 'b' : 'a'}. ${vp} paiement EN LIVRAISON${mode === 'cash' ? ' (devis, frais, heure, trois règlements, Lille)' : ''} → ${mode === 'cash' ? 'ESPÈCES' : 'CARTE'} à la livraison : commande, base, confirmation, suivi`, async ({ browser }) => {
        const ctx = await newCtx(browser, { mobile });
        const page = await ctx.newPage();
        await page.emulateMedia({ reducedMotion: 'reduce' });
        const rec = recorder(page);
        const sousTotal = await versPaiement(page);
        await choisirLivraison(page);
        resetLimiteurs();
        const timingAvant = await page.locator('[data-testid="delivery-timing"]').count();
        const adr = await saisirAdresse(page, HENIN, /Gruyelle/i);
        expect(adr.status).toBe(200);
        expect(adr.quote.allowed).toBe(true);
        const fee = Number(adr.quote.fee);
        const section = page.locator('.lcf-section', { has: page.locator('input[aria-label="Adresse de livraison"]') });
        const secTxt = norm(await section.innerText());
        const recapTxt = norm(await page.locator('.lcf-summary').innerText());
        const cta = norm(await page.locator('.lcf-cta-bar-next').innerText());

        if (mode === 'cash') {
          // ----- P-3 : devis affiché == POST /delivery/quote == total du récap
          resetLimiteurs();
          const q2 = await (await rq.post(`${BASE}/api/frontend/delivery/quote`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { q: adr.choisi } })).json();
          check(`${vp} P-3 frais : écran (section+récap) == devis API == 3,90 € ; total récap/bouton == sous-total + frais`,
            fee === 3.9 && Number(q2.fee) === 3.9 && /Livraison\s*3,90\s*€/.test(secTxt) && /Livraison\s*3,90\s*€/.test(recapTxt) && Math.abs(lastEuro(cta) - (sousTotal + fee)) < 0.005,
            { feeUI: fee, feeApi: q2.fee, secTxt: secTxt.slice(0, 200), recapTxt: recapTxt.slice(0, 200), cta, sousTotal });
          expect(fee).toBe(3.9); expect(Number(q2.fee)).toBe(3.9);
          expect(secTxt).toMatch(/Livraison\s*3,90\s*€/);
          expect(recapTxt).toMatch(/Livraison\s*3,90\s*€/);
          expect(Math.abs(lastEuro(cta) - (sousTotal + fee))).toBeLessThan(0.005);
          // ----- préparation / trajet : 15 à 20 min == prep_minutes_* du devis ; jamais « 30 à 45 »
          const timing = page.locator('[data-testid="delivery-timing"]');
          await expect(timing, 'message de préparation visible après l’adresse confirmée').toBeVisible();
          const tTxt = norm(await timing.innerText());
          const pageTxt = await tunnelText(page);
          const html = await page.content();
          const attendu = `Préparation : ${q2.prep_minutes_low} à ${q2.prep_minutes_high} min`;
          const trajet = q2.travel_minutes ? `(environ ${q2.travel_minutes} min)` : '';
          check(`${vp} P-3 « ${attendu}, puis livraison selon le trajet ${trajet} » (== prep_minutes_* de l'API) ; aucun « 30 à 45 »`,
            timingAvant === 0 && tTxt.includes(attendu) && /puis livraison selon le trajet/.test(tTxt) && (!trajet || tTxt.includes(trajet)) && !/30\s*(à|-|–|—)\s*45/.test(pageTxt) && !/30\s*(à|-|–|—)\s*45/.test(html.replace(/<script[\s\S]*?<\/script>/g, '')),
            { tTxt, attendu, timingAvant, api: { low: q2.prep_minutes_low, high: q2.prep_minutes_high, travel: q2.travel_minutes } });
          expect(timingAvant, 'aucun message avant l’adresse confirmée').toBe(0);
          expect(q2.prep_minutes_low).toBe(15); expect(q2.prep_minutes_high).toBe(20);
          expect(tTxt).toContain(attendu);
          expect(tTxt).toMatch(/puis livraison selon le trajet/);
          if (trajet) expect(tTxt).toContain(trajet);
          expect(pageTxt).not.toMatch(/30\s*(à|-|–|—)\s*45/);
          expect(html.replace(/<script[\s\S]*?<\/script>/g, '')).not.toMatch(/30\s*(à|-|–|—)\s*45/);
          // ----- créneaux
          const slots = await page.locator('[role="radiogroup"][aria-label="Heure de départ de la livraison"] [role="radio"]').allInnerTexts();
          const apiSlots = (q2.waves || []).filter((w) => !w.full).slice(0, 8).map((w) => w.time);
          check(`${vp} P-3 créneaux : « Au plus tôt » + ${apiSlots.length} départs == API`, /Au plus tôt/.test(slots[0] || '') && apiSlots.length >= 1 && apiSlots.every((t, i) => (slots[i + 1] || '').includes(t)), { slots: slots.map(norm), apiSlots });
          expect(slots[0]).toMatch(/Au plus tôt/);
          expect(apiSlots.length).toBeGreaterThan(0);
          apiSlots.forEach((t, i) => expect(slots[i + 1] || '').toContain(t));
          // ----- règlements : exactement 3 (4-5 si portefeuille) ; défaut
          const regs = await reglements(page);
          const noms = regs.map((r) => norm(r.nom));
          L.reglementsLivraison = regs;
          check(`${vp} P-3 règlements en livraison = [Carte bancaire (en ligne), Carte à la livraison, Espèces à la livraison]`,
            JSON.stringify(noms) === JSON.stringify(['Carte bancaire (en ligne)', 'Carte à la livraison', 'Espèces à la livraison']), regs);
          expect(noms).toEqual(['Carte bancaire (en ligne)', 'Carte à la livraison', 'Espèces à la livraison']);
          expect(pageTxt).not.toMatch(/Payer sur place|Payer à la livraison/);
          // ----- ledger texte pour P-9
          L.textes[`${vp}-paiement-livraison`] = pageTxt;
          L.textes[`${vp}-bloc-livraison`] = norm(await section.innerText()) + ' || ' + norm(await payGroup(page).innerText());
          const p3 = await state(page, rec, `${vp}-P3-paiement-livraison-henin`, { regs, apiSlots }, true, true);
          expect(p3.entry.consoleErrors, 'console propre sur la page de paiement livraison').toEqual([]);
          // ----- Lille : refus, bouton bloqué, Uber Eats, plus de message de préparation
          resetLimiteurs();
          const lille = await saisirAdresse(page, '10 rue Nationale 59000 Lille', /Lille/i);
          expect(lille.quote.allowed).toBe(false);
          const secL = norm(await section.innerText());
          const ctaBtn = page.locator('.lcf-cta-bar-next');
          const dis = await ctaBtn.isDisabled();
          const uber = await section.locator('a[href*="ubereats"]').count();
          const timingL = await page.locator('[data-testid="delivery-timing"]').count();
          const lil = await state(page, rec, `${vp}-P3-lille-refus`, {}, !mobile);
          check(`${vp} P-3 Lille : refus + Uber Eats + bouton bloqué sans montant + plus de message de préparation`, /trop loin/i.test(secL) && uber >= 1 && dis && !/€/.test(norm(await ctaBtn.innerText())) && timingL === 0, { secL: secL.slice(0, 200), uber, dis, timingL });
          expect(secL).toMatch(/trop loin/i); expect(uber).toBeGreaterThanOrEqual(1); expect(dis).toBe(true); expect(timingL).toBe(0);
          expect(lil.entry.consoleErrors).toEqual([]);
          // retour à une adresse livrable pour commander
          resetLimiteurs();
          const retour = await saisirAdresse(page, HENIN, /Gruyelle/i);
          expect(retour.quote.allowed).toBe(true);
        }

        // ----- le règlement choisi, puis la commande
        const nomReg = mode === 'cash' ? 'Espèces à la livraison' : 'Carte à la livraison';
        await choisirReglement(page, nomReg);
        const ctaChoix = norm(await page.locator('.lcf-cta-bar-next').innerText());
        const bandeau = norm(await page.locator('.lcf-page').innerText());
        expect(ctaChoix, 'bouton « Confirmer ma commande » pour un règlement à la livraison').toMatch(/Confirmer ma commande/);
        await sIdentifier(page, rq, `${mode}${vp}`);
        await page.locator('.lcf-cta-bar-next').scrollIntoViewIfNeeded();
        const totalAvant = lastEuro(ctaChoix);
        const o = await confirmer(page, `${vp} livraison ${mode}`);
        expect(o.http, `commande de livraison (${mode}) : ${o.corps || ''}`).toBe(201);
        ORD[`${vp}_${mode}`] = o;
        expect(o.envoye.order_type).toBe(5);
        expect(o.envoye.payment_method).toBe(1);
        if (mode === 'card') expect(o.envoye.door_payment, 'corps envoyé porte door_payment:"card"').toBe('card');
        else expect('door_payment' in o.envoye, 'espèces : aucun door_payment envoyé').toBe(false);
        await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 45_000 });
        await page.waitForTimeout(1200);
        const conf = await state(page, rec, `${vp}-P${mode === 'cash' ? '4' : '5'}-confirmation-${mode}`, {}, !mobile && true);
        const confTxt = norm(await tunnelText(page));
        const base = ligneBase(o.id);
        o.base = base;
        const stop = mysql(`SELECT IFNULL(collect_method,'NULL'), amount_due_cents, id, IFNULL(wave_at,'NULL') FROM delivery_stops WHERE order_id=${Number(o.id)}`).split('\t');
        o.stop = { collect: stop[0], due: Number(stop[1]), id: Number(stop[2]), wave_at: stop[3] };
        const attendu = mode === 'cash' ? { dpm: 'NULL', libelle: /Espèces à la remise/, phrase: /en espèces à la remise/ } : { dpm: 'card', libelle: /Carte bancaire à la remise/, phrase: /par carte bancaire au livreur/ };
        check(`${vp} P-${mode === 'cash' ? 4 : 5} base : order_type=5, payment_method=1, door_payment_mode=${attendu.dpm}, non payé, frais 3,90, total == bouton == API`,
          base.order_type === '5' && base.payment_method === '1' && base.door_payment_mode === attendu.dpm && base.payment_status !== '5' && Number(base.delivery_charge) === 3.9
          && Math.abs(Number(base.total) - totalAvant) < 0.005 && Math.abs(o.totalApi - Number(base.total)) < 0.005 && base.fiscal_sequence_no === 'NULL', { base, totalAvant, api: o.totalApi });
        expect(base.order_type).toBe('5'); expect(base.payment_method).toBe('1');
        expect(base.door_payment_mode).toBe(attendu.dpm);
        expect(base.payment_status, 'jamais payé à la commande').not.toBe('5');
        expect(base.fiscal_sequence_no, 'pas de numéro fiscal à la commande').toBe('NULL');
        expect(Number(base.delivery_charge)).toBe(3.9);
        expect(Math.abs(Number(base.total) - totalAvant)).toBeLessThan(0.005);
        expect(Math.abs(o.totalApi - Number(base.total))).toBeLessThan(0.005);
        expect(Math.abs(Number(base.total) - (sousTotal + 3.9))).toBeLessThan(0.005);
        check(`${vp} P-${mode === 'cash' ? 4 : 5} confirmation : « ${attendu.libelle.source} » + phrase + adresse ; aucun texte de retrait/QR/comptoir`,
          attendu.libelle.test(confTxt) && attendu.phrase.test(confTxt) && /Elie Gruyelle/i.test(confTxt) && !MOTS_RETRAIT.test(confTxt) && /livrée à l'adresse indiquée/.test(confTxt), { confTxt: confTxt.slice(0, 420), retrait: (confTxt.match(MOTS_RETRAIT) || [null])[0] });
        expect(confTxt).toMatch(attendu.libelle);
        expect(confTxt).toMatch(attendu.phrase);
        expect(confTxt).toMatch(/Elie Gruyelle/i);
        expect(confTxt).toMatch(/livrée à l'adresse indiquée/);
        expect(confTxt, 'aucun mot de retrait/QR/comptoir dans la confirmation').not.toMatch(MOTS_RETRAIT);
        expect(await page.locator('.lcf-ticket-qr, .lcf-ticket-qr-mock, .lcf-ticket-qr-real').count()).toBe(0);
        expect(conf.entry.consoleErrors).toEqual([]);
        L.textes[`${vp}-confirmation-${mode}`] = confTxt;
        check(`${vp} P-${mode === 'cash' ? 4 : 5} arrêt de tournée : collect_method=${mode === 'cash' ? 'cash' : 'card'}, dû == total`, o.stop.collect === (mode === 'cash' ? 'cash' : 'card') && o.stop.due === Math.round(Number(base.total) * 100), o.stop);
        expect(o.stop.collect).toBe(mode === 'cash' ? 'cash' : 'card');
        expect(o.stop.due).toBe(Math.round(Number(base.total) * 100));
        // suivi
        const sv = await suivre(page, rec, `${vp}-P${mode === 'cash' ? '4' : '5'}-suivi-${mode}`, !mobile && mode === 'card');
        const svTxt = norm(await tunnelText(page));
        const attSuivi = mode === 'cash' ? /Règlement\s*:\s*Espèces à la remise/ : /Règlement\s*:\s*Carte bancaire à la remise/;
        const apiShow = await (await rq.get(`${BASE}/api/frontend/order/show/${o.id}`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', Authorization: `Bearer ${AUTH.token}` } })).json();
        const dShow = apiShow.data || apiShow;
        check(`${vp} P-${mode === 'cash' ? 4 : 5} suivi : « Règlement : … à la remise » (écran) == door_payment_mode de l'API (${dShow.door_payment_mode})`, attSuivi.test(svTxt) && !MOTS_RETRAIT.test(svTxt) && (dShow.door_payment_mode || null) === (mode === 'card' ? 'card' : null), { svTxt: svTxt.slice(0, 420), api: dShow.door_payment_mode });
        expect(svTxt).toMatch(attSuivi);
        expect(svTxt).not.toMatch(MOTS_RETRAIT);
        expect(dShow.door_payment_mode || null).toBe(mode === 'card' ? 'card' : null);
        expect(sv.entry.consoleErrors).toEqual([]);
        L.textes[`${vp}-suivi-${mode}`] = svTxt;
        expect(bandeau.length).toBeGreaterThan(0);
        rec.dispose(); await ctx.close();
      });
    }
  }

  // ------------------------------------------------------------------------ P-6
  test('P-6. remise par le livreur : carte (écrans caisse + /livreur, base, NF525) et espèces (régression)', async ({ browser }) => {
    test.setTimeout(900_000);
    const cardO = ORD.D_card; const cashO = ORD.D_cash;
    expect(cardO && cardO.id && cashO && cashO.id, 'commandes D_card et D_cash créées par les tests précédents').toBeTruthy();
    const adminTok = await loginToken(rq, 'admin@lecayenne.fr', '123456');
    const posTok = await loginToken(rq, POS.email, POS.password);
    let livTok = await loginToken(rq, COURIER.email, COURIER.password);
    expect(adminTok && posTok && livTok, 'jetons admin / caisse / livreur').toBeTruthy();
    const H = (tok, extra = {}) => ({ 'X-API-KEY': API_KEY, Accept: 'application/json', Authorization: `Bearer ${tok}`, ...extra });
    const idem = () => ({ 'X-Idempotency-Key': crypto.randomUUID() });
    const dbEtat = (id) => {
      const o = ligneBase(id);
      const nPay = mysql(`SELECT COUNT(*) FROM order_payments WHERE order_id=${id}`);
      const pay = mysql(`SELECT IFNULL(GROUP_CONCAT(CONCAT(mode,':',amount,':',IFNULL(reference,'')) SEPARATOR ';'),'') FROM order_payments WHERE order_id=${id}`);
      const nEscrow = Number(mysql(`SELECT (SELECT COUNT(*) FROM cash_movements WHERE order_id=${id}) + (SELECT COUNT(*) FROM delivery_boy_cash_movements WHERE order_id=${id})`));
      const nAuditCarte = Number(mysql(`SELECT COUNT(*) FROM audit_logs WHERE JSON_EXTRACT(payload,'$.order_id')=${id} AND CAST(payload AS CHAR) LIKE '%doorstep_card_collection%'`));
      const nAuditEscrow = Number(mysql(`SELECT COUNT(*) FROM audit_logs WHERE (resource_id=${id} OR JSON_EXTRACT(payload,'$.order_id')=${id}) AND (action LIKE '%cash_collected_escrow%' OR CAST(payload AS CHAR) LIKE '%cash_collected_escrow%')`));
      const nFiscal = o.fiscal_sequence_no === 'NULL' ? 0 : Number(mysql(`SELECT COUNT(*) FROM orders WHERE branch_id=(SELECT branch_id FROM orders WHERE id=${id}) AND fiscal_sequence_no=${Number(o.fiscal_sequence_no)}`));
      return { ...o, nPay: Number(nPay), pay, nEscrow, nAuditCarte, nAuditEscrow, nFiscal };
    };
    // --- 1. recul de l'arrêt dans un créneau passé LIBRE (réglage de test, comme C2), désignation du livreur par l'API de la caisse
    const slots = {};
    for (const [cle, o] of [['card', cardO], ['cash', cashO]]) {
      const r = php(`
        $now = now(); $slot = null;
        for ($k = 1; $k <= 96; $k++) { $c = $now->copy()->subMinutes(30 * $k)->setSecond(0); $c->setMinute($c->minute >= 30 ? 30 : 0);
          if (! $slot && $c->lt($now) && ! \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('wave_at', $c->toDateTimeString())->exists()) { $slot = $c; } }
        if ($slot) { \\App\\Models\\DeliveryStop::withoutGlobalScopes()->whereKey(${o.stop.id})->update(['wave_at' => $slot->toDateTimeString()]);
          app(\\App\\Services\\Delivery\\DeliveryWaveService::class)->resequence(1, $slot); }
        echo json_encode(['slot' => $slot ? $slot->toDateTimeString() : null]);`);
      expect(r.slot, `créneau passé libre pour ${cle}`).toBeTruthy();
      slots[cle] = r.slot;
      const as = await rq.post(`${BASE}/api/admin/pos-delivery/waves/assign`, { headers: H(posTok), data: { wave_at: r.slot, delivery_boy_id: COURIER.id } });
      expect(as.status(), await as.text()).toBeLessThan(300);
      for (const st of [4, 7, 8]) {
        const cur = Number(mysql(`SELECT status FROM orders WHERE id=${o.id}`));
        if (cur >= st) continue;
        const k = await rq.post(`${BASE}/api/admin/online-order/change-status/${o.id}`, { headers: H(adminTok, idem()), data: { status: st, preparation_time: 15 } });
        expect(k.status(), `cuisine → ${st} : ${await k.text()}`).toBeLessThan(300);
      }
    }
    L.slotsLivreur = slots;
    const avantCarte = dbEtat(cardO.id); const avantEsp = dbEtat(cashO.id);
    check('P-6 AVANT départ : carte PRÊTE (8), non payée, sans numéro fiscal, 0 ligne order_payments', avantCarte.status === '8' && avantCarte.payment_status !== '5' && avantCarte.fiscal_sequence_no === 'NULL' && avantCarte.nPay === 0, avantCarte);
    expect(avantCarte.status).toBe('8'); expect(avantCarte.payment_status).not.toBe('5'); expect(avantCarte.fiscal_sequence_no).toBe('NULL'); expect(avantCarte.nPay).toBe(0);
    expect(avantEsp.payment_status).not.toBe('5');
    expect(avantCarte.delivery_boy_id, 'livreur désigné sur la commande').toBe(String(COURIER.id));

    // --- 2. planche « Tournées » de la caisse : la rangée dit « Carte » (écran) == collect_method du serveur (API)
    const ctxP = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    await ctxP.route((url) => PROD_RX.test(url.href), (route) => { L.prodBloquees.push(route.request().url().slice(0, 200)); return route.abort('blockedbyclient'); });
    const pp = await ctxP.newPage();
    const recP = recorder(pp);
    await pp.goto(`${BASE}/login`);
    await expect(pp.locator('#formEmail')).toBeVisible({ timeout: 60_000 });
    await pp.locator('#formEmail').fill(POS.email);
    await pp.locator('#formPassword').fill(POS.password);
    await Promise.all([
      pp.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/auth\/login/.test(r.url()), { timeout: 60_000 }),
      pp.getByRole('button', { name: /^(login|connexion)$/i }).click(),
    ]);
    await pp.waitForURL((u) => !/\/login/.test(u.pathname), { timeout: 60_000 });
    await pp.goto(`${BASE}/admin/livraisons`);
    await settle(pp);
    const board = pp.locator('[data-testid="pos-delivery-board"]');
    await expect(board).toBeVisible({ timeout: 30_000 });
    const sD = slots.card.slice(0, 10); const sT = slots.card.slice(11, 16);
    const sec = board.locator(`[data-testid="pos-delivery-wave-${sD}-${sT}"]`);
    await expect(sec).toContainText(cardO.serial, { timeout: 30_000 });
    const ligne = sec.locator('li', { hasText: cardO.serial });
    await expect(ligne, 'la planche dit « Carte <montant> € »').toContainText(`Carte ${eur(cardO.base.total)} €`);
    await expect(ligne).not.toContainText(`Espèces ${eur(cardO.base.total)} €`);
    const boardApi = await (await rq.get(`${BASE}/api/admin/pos-delivery/board`, { headers: H(posTok) })).json();
    const wv = boardApi.data.find((x) => x.wave_at === slots.card);
    const stApi = wv && wv.stops.find((s) => String(s.order_number || s.order_serial_no || s.order_id).includes(String(cardO.serial)) || Number(s.order_id) === Number(cardO.id));
    check('P-6 planche « Tournées » : « Carte X € » (écran) == collect_method API', !!stApi && (stApi.collect_method === 'card'), stApi ? { collect: stApi.collect_method, due: stApi.amount_due } : 'arrêt introuvable');
    expect(stApi && stApi.collect_method).toBe('card');
    await sec.scrollIntoViewIfNeeded();
    await state(pp, recP, 'P6-planche-tournees-carte', {}, true);
    await ctxP.close();

    // --- 3. écran /livreur (390×844) : « Encaisser par carte » + montant ; départ, arrivée, remise par ses vraies API (boutons)
    const lat = 50.4215667; const lon = 2.954906;
    const ctxL = await browser.newContext({ baseURL: BASE, viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'fr-FR', timezoneId: 'Europe/Paris', permissions: ['geolocation'], geolocation: { latitude: lat, longitude: lon } });
    await ctxL.route((url) => PROD_RX.test(url.href), (route) => { L.prodBloquees.push(route.request().url().slice(0, 200)); return route.abort('blockedbyclient'); });
    const pl = await ctxL.newPage();
    const recL = recorder(pl);
    const dialogues = [];
    pl.on('dialog', (d) => { dialogues.push(d.message()); d.accept(); });
    await pl.goto('/livreur');
    await pl.locator('#email').fill(COURIER.email);
    await pl.locator('#password').fill(COURIER.password);
    await pl.locator('#login-btn').click();
    const arret = pl.locator('.stop', { hasText: cardO.serial });
    await expect(arret).toBeVisible({ timeout: 30_000 });
    await expect(arret, 'écran livreur : « Encaisser par carte : <montant> € »').toContainText(`Encaisser par carte : ${eur(cardO.base.total)} €`);
    await expect(arret).not.toContainText('Encaisser en espèces');
    const run = await pl.evaluate(async (k) => (await fetch('/api/frontend/delivery-run', { headers: { Accept: 'application/json', 'X-API-KEY': k, Authorization: 'Bearer ' + localStorage.getItem('lc_livreur_token') } })).json(), API_KEY);
    L.runLivreur = JSON.stringify(run).slice(0, 1500);
    const wl = (run.data || []).find((w) => w.wave_at === slots.card);
    const sl = wl && wl.stops.find((s) => Number(s.order_id) === Number(cardO.id));
    check('P-6 /livreur : « Encaisser par carte » (écran) == collect_method=card + montant (API delivery-run)', !!sl && sl.collect_method === 'card' && Number(sl.amount_due_cents) === Math.round(Number(cardO.base.total) * 100), sl && { m: sl.collect_method, label: sl.collect_label, due: sl.amount_due_cents });
    expect(sl.collect_method).toBe('card');
    expect(Number(sl.amount_due_cents)).toBe(Math.round(Number(cardO.base.total) * 100));
    await arret.scrollIntoViewIfNeeded();
    await settle(pl);
    await state(pl, recL, 'P6-livreur-encaisser-par-carte', {}, true);
    const departBtn = pl.locator(`button[data-depart="${slots.card}"]`);
    await expect(departBtn).toBeEnabled();
    await departBtn.click();
    await expect(arret).toContainText('En route', { timeout: 30_000 });
    const apresDepart = dbEtat(cardO.id);
    check('P-6 APRÈS « Je pars » (carte) : en route (10) mais JAMAIS payée, sans numéro fiscal, 0 paiement, 0 escrow', apresDepart.status === '10' && apresDepart.payment_status !== '5' && apresDepart.fiscal_sequence_no === 'NULL' && apresDepart.nPay === 0 && apresDepart.nEscrow === 0, apresDepart);
    expect(apresDepart.status).toBe('10'); expect(apresDepart.payment_status).not.toBe('5'); expect(apresDepart.fiscal_sequence_no).toBe('NULL'); expect(apresDepart.nPay).toBe(0); expect(apresDepart.nEscrow).toBe(0);
    await expect(arret, 'après le départ, l’écran dit toujours « Encaisser par carte »').toContainText(`Encaisser par carte : ${eur(cardO.base.total)} €`);
    // arrivée : photo JPEG + position
    const jpeg = await pl.evaluate(async () => {
      const c = document.createElement('canvas'); c.width = 320; c.height = 240;
      const g = c.getContext('2d'); g.fillStyle = '#F4501E'; g.fillRect(0, 0, 320, 240); g.fillStyle = '#fff'; g.font = '28px sans-serif'; g.fillText('E2E-PAY porte', 60, 130);
      const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.7));
      return Array.from(new Uint8Array(await blob.arrayBuffer()));
    });
    await arret.locator('input[data-arrive]').setInputFiles({ name: 'porte.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(jpeg) });
    await expect(arret).toContainText('Arrivé', { timeout: 30_000 });
    const apresArrivee = dbEtat(cardO.id);
    expect(apresArrivee.payment_status, 'arrivée : toujours non payée').not.toBe('5');
    expect(apresArrivee.fiscal_sequence_no).toBe('NULL');
    await settle(pl);
    await arret.locator('button[data-deliver]').click();
    await expect(arret).toContainText('✓ Remise terminée', { timeout: 30_000 });
    const apres = dbEtat(cardO.id);
    cardO.livree = true; (L.orders.find((x) => x.id === cardO.id) || {}).livree = true;
    const montantEcran = dialogues.find((m) => m.includes(`${eur(cardO.base.total)} €`));
    check('P-6 APRÈS remise (carte) : payée (5), livrée (13), numéro fiscal posé UNE fois, pos_payment_method=2, UNE ligne order_payments mode 2 = total, audit doorstep_card_collection, 0 escrow espèces',
      apres.payment_status === '5' && apres.status === '13' && apres.fiscal_sequence_no !== 'NULL' && apres.nFiscal === 1 && apres.pos_payment_method === '2' && apres.nPay === 1 && apres.pay.startsWith(`2:${Number(apres.total).toFixed(2)}:`) && apres.nAuditCarte >= 1 && apres.nEscrow === 0 && apres.nAuditEscrow === 0, apres);
    expect(apres.payment_status).toBe('5'); expect(apres.status).toBe('13');
    expect(apres.fiscal_sequence_no).not.toBe('NULL'); expect(apres.nFiscal).toBe(1);
    expect(apres.pos_payment_method).toBe('2');
    expect(apres.nPay).toBe(1);
    expect(apres.pay).toBe(`2:${Number(apres.total).toFixed(2)}:livraison`);
    expect(apres.nAuditCarte, 'audit doorstep_card_collection').toBe(1);
    expect(apres.nEscrow, 'aucune ligne cash_movements / delivery_boy_cash_movements pour cette commande').toBe(0);
    expect(apres.nAuditEscrow, 'aucun audit d’escrow espèces').toBe(0);
    expect(montantEcran, 'la confirmation du livreur demande le MÊME montant').toBeTruthy();
    L.dialoguesLivreur = dialogues;
    if (!/carte/i.test(montantEcran || '')) L.defauts.push({ id: 'P6-confirmation-livreur-sans-mode', severite: 'P3', detail: `la confirmation « ${montantEcran} » ne dit pas par quel moyen (carte) le livreur confirme avoir encaissé`, ou: 'resources/views/livreur.blade.php:220' });
    const rejeu = await pl.evaluate(async ([k, id]) => {
      const r = await fetch(`/api/frontend/delivery-run/stops/${id}/deliver`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-API-KEY': k, 'X-Idempotency-Key': crypto.randomUUID(), Authorization: 'Bearer ' + localStorage.getItem('lc_livreur_token') }, body: JSON.stringify({ collected_cents: 1 }) });
      return { status: r.status };
    }, [API_KEY, cardO.stop.id]);
    expect(rejeu.status, 'une 2ᵉ remise est refusée').toBe(409);
    const apresRejeu = dbEtat(cardO.id);
    expect(apresRejeu.nPay).toBe(1); expect(apresRejeu.fiscal_sequence_no).toBe(apres.fiscal_sequence_no);
    await settle(pl);
    await arret.scrollIntoViewIfNeeded();
    await ctxL.close();

    // --- 4. ESPÈCES (régression) : mêmes jalons par l'API du livreur
    livTok = await loginToken(rq, COURIER.email, COURIER.password); // la connexion de l'écran /livreur a remplacé le jeton précédent du même appareil
    const dep = await rq.post(`${BASE}/api/frontend/delivery-run/depart`, { headers: H(livTok, idem()), data: { wave_at: slots.cash } });
    expect(dep.status(), await dep.text()).toBe(200);
    const aprDepE = dbEtat(cashO.id);
    expect(aprDepE.payment_status, 'espèces : non payée après « Je pars »').not.toBe('5');
    expect(aprDepE.fiscal_sequence_no).toBe('NULL'); expect(aprDepE.nPay).toBe(0);
    const arrE = await rq.post(`${BASE}/api/frontend/delivery-run/stops/${cashO.stop.id}/arrive`, { headers: H(livTok, idem()), multipart: { lat: String(lat), lng: String(lon) } });
    expect(arrE.status(), await arrE.text()).toBeLessThan(300);
    const livE = await rq.post(`${BASE}/api/frontend/delivery-run/stops/${cashO.stop.id}/deliver`, { headers: H(livTok, idem()), data: { collected_cents: Math.round(Number(cashO.base.total) * 100) } });
    expect(livE.status(), await livE.text()).toBeLessThan(300);
    cashO.livree = true; (L.orders.find((x) => x.id === cashO.id) || {}).livree = true;
    const aprE = dbEtat(cashO.id);
    check('P-6 ESPÈCES (régression) : payée, livrée, numéro fiscal UNE fois, pos_payment_method=1, escrow espèces écrit (audit ou mouvement), PAS d\'audit carte',
      aprE.payment_status === '5' && aprE.status === '13' && aprE.nFiscal === 1 && aprE.pos_payment_method === '1' && (aprE.nAuditEscrow >= 1 || aprE.nEscrow >= 1) && aprE.nAuditCarte === 0, aprE);
    expect(aprE.payment_status).toBe('5'); expect(aprE.status).toBe('13');
    expect(aprE.nFiscal).toBe(1); expect(aprE.pos_payment_method).toBe('1');
    expect(aprE.nAuditEscrow + aprE.nEscrow, 'escrow espèces écrit').toBeGreaterThanOrEqual(1);
    expect(aprE.nAuditCarte, 'pas de trace carte sur une commande espèces').toBe(0);
    expect(aprE.door_payment_mode).toBe('NULL');
    // --- 5. NF525 APRÈS
    const post = chaineFiscale();
    L.chaineFiscaleApres = post;
    check('NF525 : fiscal:verify-chain --all OK APRÈS les remises', post.ok, post.fin);
    expect(post.ok, post.fin).toBe(true);
    L.p6 = { avantCarte, apresDepart, apresArrivee, apres, aprDepE, aprE };
    writeLedger();
  });

  // ------------------------------------------------------------------------ P-7
  for (const variante of ['D', 'M-android']) {
    test(`P-7${variante === 'D' ? 'a' : 'b'}. ${variante} règlement EN LIGNE en livraison : champs carte, bouton « Payer par carte »${variante === 'M-android' ? ', Google Pay (UA Android Chrome)' : ''}`, async ({ browser }) => {
      const mobile = variante !== 'D';
      const ctx = await newCtx(browser, { mobile, android: variante === 'M-android' });
      const page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      const rec = recorder(page);
      await versPaiement(page);
      await choisirLivraison(page);
      resetLimiteurs();
      const adr = await saisirAdresse(page, HENIN, /Gruyelle/i);
      expect(adr.quote.allowed).toBe(true);
      const regs = await reglements(page);
      const noms = regs.map((r) => norm(r.nom));
      if (variante === 'M-android') {
        check('P-7 Android Chrome : « Google Pay » proposé en livraison + carte en ligne + 2 règlements à la livraison', noms.includes('Google Pay') && noms.includes('Carte bancaire (en ligne)') && noms.includes('Carte à la livraison') && noms.includes('Espèces à la livraison'), regs);
        expect(noms).toEqual(['Google Pay', 'Carte bancaire (en ligne)', 'Carte à la livraison', 'Espèces à la livraison']);
        const gp = regs.find((r) => r.nom === 'Google Pay');
        expect(gp.meta).toMatch(/rien à régler à la remise/);
      } else {
        expect(noms).toEqual(['Carte bancaire (en ligne)', 'Carte à la livraison', 'Espèces à la livraison']);
      }
      await choisirReglement(page, 'Carte bancaire \\(en ligne\\)');
      const form = page.locator('.lcf-cardform');
      const visible = await form.isVisible();
      const cta = norm(await page.locator('.lcf-cta-bar-next').innerText());
      const formTxt = norm(await form.innerText().catch(() => ''));
      const champs = await page.evaluate(() => ({ inputs: document.querySelectorAll('.lcf-cardform input').length, iframes: document.querySelectorAll('.lcf-cardform iframe').length, mount: document.querySelectorAll('.lcf-cardform [id*="mollie"], .lcf-cardform [class*="mollie"], .lcf-cardform .lcf-card-field').length }));
      L.textes[`${variante}-paiement-livraison-en-ligne`] = await tunnelText(page);
      const p7 = await state(page, rec, `${variante}-P7-en-ligne`, { champs, formTxt: formTxt.slice(0, 300) }, variante === 'D', true);
      check(`${variante} P-7 « Carte bancaire (en ligne) » : champs de carte affichés (form visible), bouton « Payer par carte »`, visible && /^Payer par carte/.test(cta) && (champs.inputs + champs.iframes + champs.mount) >= 1, { visible, cta, champs, formTxt: formTxt.slice(0, 200) });
      expect(visible, 'bloc de carte visible').toBe(true);
      expect(cta).toMatch(/^Payer par carte/);
      expect(champs.inputs + champs.iframes + champs.mount, 'au moins un champ de carte').toBeGreaterThanOrEqual(1);
      expect(p7.entry.consoleErrors, 'console propre').toEqual([]);
      // changement de règlement : le bloc de carte se replie, le bouton redevient « Confirmer ma commande »
      await choisirReglement(page, 'Carte à la livraison');
      expect(await form.isVisible(), 'bloc carte replié pour la carte à la livraison').toBe(false);
      expect(norm(await page.locator('.lcf-cta-bar-next').innerText())).toMatch(/^Confirmer ma commande/);
      if (variante === 'D') note('Apple Pay : la détection exige https + www.lecayenne.fr (isApplePaySecureCheckout, funnel.jsx:828) : NON MESURÉ ici (aucune requête production permise) ; Google Pay mesuré en P-7b (UA Android Chrome émulé)');
      rec.dispose(); await ctx.close();
    });
  }

  // ------------------------------------------------------------------------ P-8
  test('P-8. bascule emporter ↔ livraison sans mode orphelin ; clé d’idempotence distincte espèces → carte (réponse perdue), 409 témoin, forgeries 422', async ({ browser }) => {
    const ctx = await newCtx(browser, { mobile: false });
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const rec = recorder(page);
    const sousTotal = await versPaiement(page);
    await choisirLivraison(page);
    resetLimiteurs();
    const adr = await saisirAdresse(page, HENIN, /Gruyelle/i);
    expect(adr.quote.allowed).toBe(true);
    await choisirReglement(page, 'Carte à la livraison');
    // --- retour à emporter
    await choisirEmporter(page);
    const regsE = await reglements(page);
    const nomsE = regsE.map((r) => norm(r.nom));
    const actifE = regsE.filter((r) => r.on).map((r) => norm(r.nom));
    const ctaE = norm(await page.locator('.lcf-cta-bar-next').innerText());
    const timingE = await page.locator('[data-testid="delivery-timing"]').count();
    const adrE = await page.locator('input[aria-label="Adresse de livraison"]').count();
    check('P-8 retour à emporter : « Payer sur place » sélectionné, aucun règlement de livraison, plus d\'adresse ni de message de préparation, total = sous-total',
      JSON.stringify(actifE) === JSON.stringify(['Payer sur place']) && !nomsE.some((n) => /livraison/i.test(n)) && timingE === 0 && adrE === 0 && Math.abs(lastEuro(ctaE) - sousTotal) < 0.005, { nomsE, actifE, ctaE, timingE, adrE, sousTotal });
    expect(actifE).toEqual(['Payer sur place']);
    expect(nomsE.some((n) => /livraison/i.test(n))).toBe(false);
    expect(timingE).toBe(0); expect(adrE).toBe(0);
    expect(Math.abs(lastEuro(ctaE) - sousTotal)).toBeLessThan(0.005);
    await state(page, rec, 'P8-retour-emporter', { actifE }, false);
    // --- re-livraison : état cohérent (devis conservé OU adresse à refaire, jamais un total faux / un bouton actif sans devis)
    await choisirLivraison(page);
    await page.waitForTimeout(600);
    const dq = await page.locator('[data-testid="delivery-timing"]').count();
    const ctaL = page.locator('.lcf-cta-bar-next');
    const ctaLtxt = norm(await ctaL.innerText());
    const dis = await ctaL.isDisabled();
    const regsL = (await reglements(page)).map((r) => ({ n: norm(r.nom), on: r.on }));
    const coherent = dq === 1 ? (!dis && Math.abs(lastEuro(ctaLtxt) - (sousTotal + 3.9)) < 0.005) : (dis && !/€/.test(ctaLtxt));
    check('P-8 re-livraison : état cohérent (devis conservé → total = sous-total + 3,90 ; sinon bouton bloqué sans montant) ; un seul règlement actif', coherent && regsL.filter((r) => r.on).length === 1, { dq, dis, ctaLtxt, regsL });
    expect(coherent, JSON.stringify({ dq, dis, ctaLtxt })).toBe(true);
    expect(regsL.filter((r) => r.on).length).toBe(1);
    L.p8 = { devisConserve: dq === 1, regsApresRetour: regsL };
    if (dq !== 1) { resetLimiteurs(); const a2 = await saisirAdresse(page, HENIN, /Gruyelle/i); expect(a2.quote.allowed).toBe(true); }

    // --- idempotence : 1) espèces, réponse PERDUE côté navigateur (la commande A existe bien côté serveur) ;
    //     2) bascule « carte à la livraison » ; 3) témoin : la clé de A rejouée avec le corps carte → 409 ; 4) clé du client → 201 (commande B)
    await sIdentifier(page, rq, 'p8');
    await choisirReglement(page, 'Espèces à la livraison');
    const vus = []; let mode = 'perdue'; let cleA = null; let idA = null;
    await page.route((u) => /\/api\/frontend\/order$/.test(u.pathname), async (route) => {
      const req = route.request();
      if (req.method() !== 'POST') return route.continue();
      const cle = req.headers()['x-idempotency-key'];
      const corps = JSON.parse(req.postData() || '{}');
      vus.push({ mode, cle, door: corps.door_payment || null });
      if (mode === 'perdue') {
        const r = await route.fetch(); // la requête ATTEINT le serveur : la commande A est créée
        const j = await r.json().catch(() => ({}));
        idA = (j.data || j).id; cleA = cle;
        return route.abort('connectionreset'); // …mais le navigateur ne voit jamais la réponse
      }
      if (mode === 'temoin') return route.continue({ headers: { ...req.headers(), 'x-idempotency-key': cleA } }); // clé de A rejouée avec un AUTRE corps
      return route.continue();
    });
    await page.locator('.lcf-cta-bar-next').click();
    await expect.poll(() => vus.length, { timeout: 60_000 }).toBeGreaterThanOrEqual(1);
    await page.waitForTimeout(1500);
    expect(idA, 'commande A (espèces) réellement créée côté serveur').toBeTruthy();
    L.orders.push({ id: idA, label: 'P-8 A espèces (réponse perdue)' });
    const baseA = ligneBase(idA);
    expect(baseA.door_payment_mode).toBe('NULL');
    // bascule carte à la livraison, puis le TÉMOIN : même clé que A, corps différent → le serveur refuse (409)
    await page.locator('.lcf-page').scrollIntoViewIfNeeded();
    await choisirReglement(page, 'Carte à la livraison');
    mode = 'temoin';
    const r409 = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 60_000 });
    await page.locator('.lcf-cta-bar-next').click();
    const t = await r409;
    check('P-8 témoin (l\'instrument mord) : la clé de A rejouée avec le corps « carte à la livraison » → 409 du serveur', t.status() === 409, { status: t.status() });
    expect(t.status(), 'le serveur refuse une clé réutilisée avec un autre corps').toBe(409);
    await page.waitForTimeout(800);
    mode = 'reel';
    const r201 = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 60_000 });
    await page.locator('.lcf-cta-bar-next').click();
    const rB = await r201;
    const jB = await rB.json().catch(() => ({}));
    const idB = (jB.data || jB).id;
    if (idB) L.orders.push({ id: idB, label: 'P-8 B carte à la livraison' });
    const cleB = vus[vus.length - 1].cle;
    check('P-8 la bascule produit une clé d\'idempotence DISTINCTE de celle de A ; 201 (pas 409) ; 2 commandes différentes (A espèces / B carte)', cleB !== cleA && rB.status() === 201 && idB && idB !== idA, { cleA, cleB, status: rB.status(), idA, idB });
    expect(cleB).not.toBe(cleA);
    expect(rB.status(), JSON.stringify(jB).slice(0, 300)).toBe(201);
    expect(idB).not.toBe(idA);
    const baseB = ligneBase(idB);
    expect(baseB.door_payment_mode).toBe('card');
    expect(baseB.payment_method).toBe('1');
    expect(mysql(`SELECT COUNT(*) FROM orders WHERE id IN (${idA},${idB})`)).toBe('2');
    L.p8.vus = vus; L.p8.baseA = baseA; L.p8.baseB = baseB;
    await page.unroute((u) => /\/api\/frontend\/order$/.test(u.pathname)).catch(() => {});
    // --- forgeries : corps d'une vraie commande de livraison, modifié → 422 sur door_payment, AUCUNE commande créée
    const modele = ORD.D_card && ORD.D_card.envoye;
    expect(modele, 'corps modèle (commande carte)').toBeTruthy();
    const nAvant = Number(mysql('SELECT COUNT(*) FROM orders'));
    const essais = [
      { nom: 'door_payment sur un order_type à emporter (10)', corps: { ...modele, order_type: 10 } },
      { nom: 'door_payment avec payment_method=4 (en ligne)', corps: { ...modele, payment_method: 4 } },
      { nom: 'door_payment="cash"', corps: { ...modele, door_payment: 'cash' } },
    ];
    const resForge = [];
    for (const e of essais) {
      const r = await rq.post(`${BASE}/api/frontend/order`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', Authorization: `Bearer ${AUTH.token}`, 'X-Idempotency-Key': crypto.randomUUID() }, data: e.corps });
      const txt = await r.text();
      resForge.push({ nom: e.nom, http: r.status(), door: /door_payment/.test(txt) });
    }
    const nApres = Number(mysql('SELECT COUNT(*) FROM orders'));
    check('P-8 forgeries door_payment refusées 422 (clé door_payment), 0 commande créée', resForge.every((x) => x.http === 422 && x.door) && nApres === nAvant, { resForge, nAvant, nApres });
    for (const x of resForge) { expect(x.http, x.nom).toBe(422); expect(x.door, `${x.nom} : erreur sur la clé door_payment`).toBe(true); }
    expect(nApres).toBe(nAvant);
    writeLedger();
    rec.dispose(); await ctx.close();
  });

  // ------------------------------------------------------------------------ P-9
  test('P-9. textes : page de paiement livraison, confirmation et suivi relus (clés brutes, vouvoiement, mots de retrait, libellés orphelins)', async () => {
    const tp = [L.textes['D-paiement-livraison'], L.textes['M-paiement-livraison']];
    expect(tp.every(Boolean), 'textes relevés en P-3').toBe(true);
    const bruts = tp.map((t) => (t.match(MOTS_BRUTS) || [null])[0]);
    check('P-9 page de paiement livraison (D et M) : aucune clé brute, aucun « undefined/null/NaN »', bruts.every((b) => b === null), bruts);
    expect(bruts).toEqual([null, null]);
    for (const t of tp) {
      expect(t).not.toMatch(/Payer à la livraison/);
      expect(t).not.toMatch(/Payer sur place/);
    }
    check('P-9 aucun « Payer à la livraison » orphelin', tp.every((t) => !/Payer à la livraison/.test(t)), null);
    // vouvoiement sur TOUTE la page de paiement en livraison (récap, règlements, bloc carte en ligne déplié) — pas seulement le bloc adresse
    const pagesLiv = ['D-paiement-livraison', 'M-paiement-livraison', 'D-paiement-livraison-en-ligne', 'M-android-paiement-livraison-en-ligne'];
    const tutPages = pagesLiv.map((k) => ({ k, mot: ((L.textes[k] || '').match(new RegExp(TUTOIEMENT.source + '.{0,40}', 'iu')) || [null])[0] }));
    check('P-9 vouvoiement sur TOUTE la page de paiement en livraison (4 relevés, bloc carte en ligne déplié compris)', tutPages.every((x) => x.mot === null), tutPages.filter((x) => x.mot));
    for (const x of tutPages) expect(x.mot, `${x.k} : tutoiement résiduel`).toBeNull();
    const blocs = [L.textes['D-bloc-livraison'], L.textes['M-bloc-livraison']];
    const tut = blocs.map((t) => (t.match(TUTOIEMENT) || [null])[0]);
    const tutPage = tp.map((t) => (t.match(new RegExp(TUTOIEMENT.source + '.{0,40}', 'iu')) || [null])[0]);
    L.tutoiementResiduPage = tutPage;
    check('P-9 vouvoiement dans le bloc livraison + règlements', tut.every((x) => x === null), { tut, residuPageEntiere: tutPage });
    expect(tut, 'tutoiement dans le bloc livraison / règlements').toEqual([null, null]);
    const conf = ['D-confirmation-cash', 'D-confirmation-card', 'M-confirmation-cash', 'M-confirmation-card', 'D-suivi-cash', 'D-suivi-card', 'M-suivi-cash', 'M-suivi-card'];
    const tutConf = conf.map((k) => ({ k, mot: (L.textes[k].match(new RegExp(TUTOIEMENT.source + '.{0,40}', 'iu')) || [null])[0] }));
    check('P-9 vouvoiement dans la confirmation et le suivi d\'une livraison (8 relevés)', tutConf.every((x) => x.mot === null), tutConf.filter((x) => x.mot));
    for (const x of tutConf) expect(x.mot, `${x.k} : tutoiement résiduel`).toBeNull();
    const retrait = conf.map((k) => ({ k, mot: (L.textes[k].match(MOTS_RETRAIT) || [null])[0], brut: (L.textes[k].match(MOTS_BRUTS) || [null])[0] }));
    check('P-9 confirmation et suivi (8 relevés) : aucun retrait/QR/comptoir, aucune clé brute', retrait.every((r) => !r.mot && !r.brut), retrait.filter((r) => r.mot || r.brut));
    for (const r of retrait) { expect(r.mot, `${r.k} : mot de retrait`).toBeNull(); expect(r.brut, `${r.k} : clé brute`).toBeNull(); }
    const aucun3045 = Object.entries(L.textes).filter(([, v]) => /30\s*(à|-|–|—)\s*45/.test(v)).map(([k]) => k);
    check('P-9 « 30 à 45 » absent de tous les textes relevés', aucun3045.length === 0, aucun3045);
    expect(aucun3045).toEqual([]);
  });

  // ------------------------------------------------------------------------ fin
  test('Z. réseau : aucune requête production/Uber/Mollie ; console propre ; états ; aucun 5xx', async () => {
    check('RÉSEAU : 0 requête vers production / Uber (les tentatives de la page vers js.mollie.com sont avortées côté navigateur : ' + (L.mollieTentativesAvortees || 0) + ')', L.prodBloquees.length === 0, L.prodBloquees.slice(0, 10));
    expect(L.prodBloquees, 'aucune requête vers la production').toEqual([]);
    const sansConsole = L.states.filter((s) => s.consoleErrors && s.consoleErrors.length).map((s) => ({ id: s.id, e: s.consoleErrors }));
    check('console propre sur tous les états capturés', sansConsole.length === 0, sansConsole);
    expect(sansConsole).toEqual([]);
    const h5 = L.states.flatMap((s) => (s.http4xx || []).map((x) => `${s.id}: ${x}`)).filter((x) => /-> 5\d\d$/.test(x));
    expect(h5).toEqual([]);
    L.http4xxParEtat = L.states.filter((s) => s.http4xx && s.http4xx.length).map((s) => ({ id: s.id, http4xx: s.http4xx }));
    writeLedger();
  });
});
