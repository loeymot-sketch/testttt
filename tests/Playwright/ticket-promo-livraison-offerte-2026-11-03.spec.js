// [TICKET PROMO 2026-11-03 · propriétaire] Deuxième offre du ticket promo client Uber : « LIVRAISON OFFERTE ».
// Bout en bout, dans un vrai navigateur :
//   1. la caisse choisit l'offre et crée le code (fenêtre 🎟️ du suivi des commandes) ;
//   2. le site applique le code : livraison « Offerte », total du bouton = repas seul, commande acceptée,
//      base : delivery_charge = 0, order_coupons.delivery_waived = frais offerts ;
//   3. hors conditions (à emporter, < 10 €, > 7 km) le site le DIT, n'envoie pas le code, et le code reste valable.
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   # back-end de l'arbre principal sur :8766 (POS_COUPON_CODES_ENABLED=true), copie du site sur TPL_SITE (metas réécrites)
//   TPL_SITE=http://127.0.0.1:8972 TPL_OUT=/tmp/captures PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/ticket-promo-livraison-offerte-2026-11-03.spec.js --workers=1 --retries=0 --reporter=line
//
// Les commandes de test sont ANNULÉES en fin de passe. Toute requête vers la production est bloquée.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const REPO = path.resolve(__dirname, '..', '..');
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const SITE = (process.env.TPL_SITE || 'http://127.0.0.1:8972').replace(/\/$/, '');
const OUT = process.env.TPL_OUT || path.join(REPO, 'reports', 'test-e2e', 'ticket-promo-livraison-offerte-2026-11-03');
fs.mkdirSync(OUT, { recursive: true });
const PROD_RX = /vps-418872ac|ovh\.net|(^|\.)lecayenne\.fr\/api/i;
const envValue = (k) => { const m = fs.readFileSync(path.join(REPO, '.env'), 'utf8').match(new RegExp(`^${k}=(.*)$`, 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = envValue('MIX_API_KEY');
const NOW = Date.now();
const TAG = `E2E-TPL-${String(NOW).slice(-6)}`;
const mysql = (sql) => execFileSync('mysql', ['-u', 'root', envValue('DB_DATABASE') || 'foodking_e2e', '-N', '-B', '-e', sql], { encoding: 'utf8', timeout: 20_000 }).trim();
const norm = (s) => String(s || '').replace(/[  ]/g, ' ').replace(/\s+/g, ' ').trim();
const euros = (s) => [...norm(s).matchAll(/(\d+)[.,](\d{2})\s*€/g)].map((m) => Number(`${m[1]}.${m[2]}`));
const lastEuro = (s) => { const a = euros(s); return a.length ? a[a.length - 1] : NaN; };
const L = { tag: TAG, checks: [], orders: [], prodBloquees: [], notes: [] };
const writeLedger = () => fs.writeFileSync(path.join(OUT, 'ledger.json'), JSON.stringify(L, null, 2));
const check = (id, ok, detail) => { L.checks.push({ id, ok: !!ok, detail }); console.log(`[TPL] ${ok ? 'OK ' : 'KO '} ${id} ${detail !== undefined ? JSON.stringify(detail).slice(0, 260) : ''}`); writeLedger(); };
function resetLimiteurs() {
  execFileSync('php', ['artisan', 'tinker', '--execute', `$l = app(\\Illuminate\\Cache\\RateLimiter::class); $l->clear(sha1("|127.0.0.1")); $l->clear("otp-verify".sha1("|127.0.0.1")); $l->clear("signup-register".sha1("|127.0.0.1")); foreach (['delivery-suggest','delivery-quote','delivery-status','delivery-waves','coupon-checking'] as $n) { foreach (['127.0.0.1','::1'] as $ip) { $l->clear(md5($n.$ip)); } } echo 'ok';`], { cwd: REPO, encoding: 'utf8', timeout: 30_000 });
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(420_000);
test.use({ video: 'off', trace: 'off', screenshot: 'off', locale: 'fr-FR', timezoneId: 'Europe/Paris',
  launchOptions: { args: ['--lang=fr-FR', '--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks'] } });

let AUTH = null;
let rq;
async function snap(page, name) { await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false }); }
async function newSiteCtx(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  await ctx.route((u) => PROD_RX.test(u.href), (r) => { L.prodBloquees.push(r.request().url().slice(0, 160)); return r.abort('blockedbyclient'); });
  await ctx.route((u) => /js\.mollie\.com/.test(u.href), (r) => r.abort('blockedbyclient'));
  if (AUTH) await ctx.addInitScript(([t, p]) => { try { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); } catch (_e) { /* stockage */ } }, [AUTH.token, AUTH.phone]);
  return ctx;
}
async function settle(page) { await page.waitForLoadState('networkidle', { timeout: 3_500 }).catch(() => {}); await page.waitForTimeout(800); }
async function gotoSite(page, hash = '') {
  await page.goto('about:blank');
  await page.goto(`${SITE}/${hash}`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 60_000 });
  await settle(page);
}
async function readStep(page) {
  return page.evaluate(() => {
    const next = document.querySelector('.lc-wiz-foot-next');
    const t = document.querySelector('.lc-wiz-title');
    return { title: t ? t.innerText.trim() : '', next: next ? next.innerText.replace(/\s+/g, ' ') : '', disabled: next ? (next.disabled || next.getAttribute('aria-disabled') === 'true') : null };
  });
}
async function ajouterProduit(page, nom) {
  await gotoSite(page, '#menu');
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30_000 });
  await page.locator(`[aria-label="Voir ${nom}"]`).first().click();
  await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 15_000 });
  await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
  await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15_000 });
  await page.locator('.lc-wiz-title').first().waitFor({ state: 'visible', timeout: 15_000 });
  for (let s = 0; s < 18; s++) {
    const step = await readStep(page);
    if (/Ajouter au panier/i.test(step.next)) break;
    for (let k = 0; k < 3 && (await readStep(page)).disabled; k++) { await page.locator('.lc-wiz-choice:not(.is-on)').first().click(); await page.waitForTimeout(250); }
    await page.locator('.lc-wiz-foot-next').click({ timeout: 10_000 });
    await page.waitForTimeout(450);
  }
  await page.locator('.lc-wiz-foot-next').click({ timeout: 10_000 });
  const t0 = Date.now();
  while (Date.now() - t0 < 25_000) {
    if (await page.locator('.lc-cart-drawer.is-open').count() || !(await page.locator('.lc-wiz').count())) return;
    await page.waitForTimeout(250);
  }
  throw new Error(`ajout de ${nom} au panier : délai dépassé`);
}
async function versCommande(page) {
  const drawer = page.locator('.lc-cart-drawer.is-open');
  if (!(await drawer.count())) { await page.waitForTimeout(800); await page.locator('.lc-nav-btn-cart').first().click(); }
  await expect(drawer).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(400);
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
  return lastEuro(panierTxt);
}
async function choisirLivraison(page) {
  const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
  await expect(rg).toBeVisible({ timeout: 15_000 });
  await rg.getByRole('radio', { name: /Livraison/ }).click();
  await expect(page.locator('input[aria-label="Adresse de livraison"]')).toBeVisible({ timeout: 10_000 });
}
async function saisirAdresse(page, saisie, motif) {
  resetLimiteurs();
  const input = page.locator('input[aria-label="Adresse de livraison"]');
  await input.scrollIntoViewIfNeeded();
  await input.fill('');
  const sugg = page.waitForResponse((r) => /\/delivery\/suggest/.test(r.url()), { timeout: 20_000 });
  await input.pressSequentially(saisie, { delay: 25 });
  await sugg;
  await page.waitForTimeout(700);
  const options = await page.evaluate(() => Array.from(document.querySelectorAll('#lcf-delivery-suggest option')).map((o) => o.value));
  await input.fill(options.find((o) => motif.test(o)) || options[0] || saisie);
  await page.waitForTimeout(400);
  const quoteP = page.waitForResponse((r) => /\/delivery\/quote/.test(r.url()) && r.request().method() === 'POST', { timeout: 25_000 });
  await page.getByRole('button', { name: /^Calculer$/ }).click();
  const quote = await (await quoteP).json().catch(() => null);
  await page.waitForTimeout(900);
  return quote;
}
async function appliquerCode(page, code) {
  const champ = page.locator('input[aria-label="Code promo"]');
  await champ.scrollIntoViewIfNeeded();
  await champ.fill(code);
  const r = page.waitForResponse((x) => /coupon-checking/.test(x.url()), { timeout: 20_000 });
  await page.locator('.lc-promo button', { hasText: 'Appliquer' }).click();
  await r;
  await page.waitForTimeout(700);
}
async function choisirEspeces(page) {
  const pay = page.locator('[role="radiogroup"][aria-label="Mode de paiement"]');
  await pay.scrollIntoViewIfNeeded();
  const esp = pay.getByRole('radio', { name: /Espèces à la livraison/ });
  if ((await esp.getAttribute('aria-checked')) !== 'true') await esp.click();
  await page.waitForTimeout(300);
}
async function sIdentifier(page) {
  if (AUTH) return;
  resetLimiteurs();
  const email = `${TAG.toLowerCase()}@lecayenne-test.fr`; const phone = `0697${String(NOW).slice(-6)}`;
  await page.locator('#auth-email').fill(email);
  await page.getByRole('button', { name: /Recevoir le code par email/ }).click();
  await expect(page.locator('[data-testid="funnel-identite"]')).toBeVisible({ timeout: 20_000 });
  await page.locator('#auth-first').fill('Christophe');
  if (await page.locator('#auth-last').count()) await page.locator('#auth-last').fill('Test');
  await page.locator('#auth-phone').fill(phone);
  const r = page.waitForResponse((x) => x.request().method() === 'POST' && /guest-signup\/email-login/.test(x.url()), { timeout: 60_000 });
  await page.getByRole('button', { name: /Créer mon compte et recevoir le code/ }).click();
  await r;
  const token = mysql(`SELECT token FROM otps WHERE phone='${phone}' ORDER BY created_at DESC LIMIT 1`);
  resetLimiteurs();
  const v = await rq.post(`${BASE}/api/auth/guest-signup/verify`, { headers: { 'X-API-Key': API_KEY, Accept: 'application/json' }, data: { token, phone, code: '+33', email, first_name: 'Christophe' } });
  const vj = await v.json().catch(() => ({}));
  expect(v.status(), JSON.stringify(vj).slice(0, 200)).toBeLessThan(300);
  await page.evaluate(([t, p]) => { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); }, [vj.token, phone]);
  AUTH = { token: vj.token, phone };
}
async function confirmer(page, label) {
  let envoye = null;
  const onReq = (req) => { if (req.method() === 'POST' && /\/api\/frontend\/order$/.test(req.url())) { try { envoye = JSON.parse(req.postData() || '{}'); } catch (_e) { envoye = null; } } };
  page.on('request', onReq);
  const p = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 90_000 });
  await page.locator('.lcf-cta-bar-next').click();
  const resp = await p;
  page.off('request', onReq);
  const j = await resp.json().catch(() => ({}));
  const d = (j && j.data) || j || {};
  if (d.id) L.orders.push({ id: d.id, label });
  writeLedger();
  return { http: resp.status(), id: d.id, envoye, corps: resp.status() >= 400 ? JSON.stringify(j).slice(0, 300) : undefined };
}
async function adminToken() {
  const r = await rq.post(`${BASE}/api/auth/login`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { email: 'admin@lecayenne.fr', password: '123456' } });
  const j = await r.json().catch(() => ({}));
  return j.token || (j.data && j.data.token) || null;
}
async function creerCodeParApi(nom) {
  const tok = await adminToken();
  const r = await rq.post(`${BASE}/api/admin/promo-flyer`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', Authorization: `Bearer ${tok}` }, data: { customer_name: nom, offer: 'free_delivery', force: true } });
  const j = await r.json();
  expect(r.status(), JSON.stringify(j).slice(0, 200)).toBe(201);
  return j.flyer;
}
const usages = (code) => Number(mysql(`SELECT COUNT(*) FROM order_coupons oc JOIN coupons c ON c.id = oc.coupon_id WHERE c.code = '${code}'`));

let CODE = null;

test.describe('Ticket promo « Livraison offerte » — caisse → site → commande', () => {
  test.beforeAll(async ({ playwright }) => { rq = await playwright.request.newContext(); });
  test.afterAll(async () => {
    try {
      const tok = await adminToken();
      for (const o of L.orders.filter((x) => x.id)) {
        const r = await rq.post(`${BASE}/api/admin/online-order/change-status/${o.id}`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', Authorization: `Bearer ${tok}`, 'X-Idempotency-Key': crypto.randomUUID() }, data: { status: 16, reason: `${TAG} test automatique` } });
        o.annulation = r.status();
      }
    } catch (e) { L.notes.push('annulation impossible : ' + String(e.message).slice(0, 160)); }
    writeLedger();
    if (rq) await rq.dispose().catch(() => {});
  });

  test('1. caisse : le caissier choisit « Livraison offerte » et obtient un code unique au prénom', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await loginAsPosOperator(page);
    await page.goto(`${BASE}/admin/pos-orders-tracker`, { waitUntil: 'domcontentloaded' });
    const bouton = page.locator('[data-testid="pos-tracker-promo-flyer"]');
    await expect(bouton).toBeVisible({ timeout: 30_000 });
    await bouton.click();
    const fenetre = page.locator('.pfq-card');
    await expect(fenetre).toBeVisible({ timeout: 10_000 });
    const offres = fenetre.locator('.pfq-offer-btn');
    await expect(offres).toHaveCount(2);
    await fenetre.getByRole('radio', { name: 'Livraison offerte' }).click();
    await fenetre.locator('#pfq-name').fill('Christophe');
    await page.waitForTimeout(300);
    await snap(page, '01-caisse-choix-offre');
    const libelles = (await offres.allInnerTexts()).map(norm);
    check('caisse : deux offres proposées (−10 % et Livraison offerte)', libelles.length === 2 && /−\s?10\s?%|Remise/.test(libelles[0]) && libelles[1] === 'Livraison offerte', libelles);
    expect(await fenetre.getByRole('radio', { name: 'Livraison offerte' }).getAttribute('aria-checked')).toBe('true');
    const resp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/admin\/promo-flyer$/.test(r.url()), { timeout: 20_000 });
    await fenetre.locator('.pfq-submit').click();
    const r = await resp;
    const j = await r.json();
    let flyer = j.flyer;
    if (j.duplicate) { // un « Christophe » créé dans les 10 dernières minutes (passe précédente) : on confirme
      await fenetre.locator('.pfq-dup-force').click();
      await expect(fenetre.locator('.pfq-done')).toBeVisible({ timeout: 10_000 });
      flyer = { code: norm(await fenetre.locator('.pfq-code').innerText()), offer: 'free_delivery' };
    }
    await expect(fenetre.locator('.pfq-done')).toBeVisible({ timeout: 10_000 });
    await snap(page, '02-caisse-code-cree');
    CODE = flyer.code;
    const done = norm(await fenetre.locator('.pfq-done').innerText());
    check('caisse : code créé, unique au prénom, offre affichée', /^CHRISTOPHE-[A-Z0-9]{4}$/.test(CODE) && /Livraison offerte/.test(done) && flyer.offer === 'free_delivery', { CODE, done });
    expect(CODE).toMatch(/^CHRISTOPHE-[A-Z0-9]{4}$/);
    const c = mysql(`SELECT free_delivery, discount, minimum_order, free_delivery_max_km, max_uses_global FROM coupons WHERE code='${CODE}'`).split('\t').map(Number);
    check('base : coupon livraison offerte (0 € sur les articles, 10 € minimum, 7 km, usage unique)', c[0] === 1 && c[1] === 0 && c[2] === 10 && c[3] === 7 && c[4] === 1, c);
    expect(c).toEqual([1, 0, 10, 7, 1]);
  });

  test('2. site : code appliqué en livraison à Hénin → « Offerte », bouton = repas seul, commande à 0 € de livraison', async ({ browser }) => {
    expect(CODE, 'code créé à l’étape 1').toBeTruthy();
    const ctx = await newSiteCtx(browser);
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await ajouterProduit(page, 'Cayenne');
    await ajouterProduit(page, 'Cheese Burger');
    const sousTotal = await versCommande(page);
    expect(sousTotal, 'panier ≥ 10 €').toBeGreaterThanOrEqual(10);
    await choisirLivraison(page);
    const quote = await saisirAdresse(page, '12 rue Elie Gruyelle 62110 Henin-Beaumont', /Gruyelle/i);
    expect(quote && quote.allowed).toBe(true);
    const fee = Number(quote.fee);
    expect(fee).toBeGreaterThan(0);
    await choisirEspeces(page);
    const ctaAvant = lastEuro(await page.locator('.lcf-cta-bar-next').innerText());
    check('site : avant le code, bouton = repas + livraison', Math.abs(ctaAvant - (sousTotal + fee)) < 0.005, { ctaAvant, sousTotal, fee });
    await appliquerCode(page, CODE);
    const promo = norm(await page.locator('.lcf-promo-livraison').innerText());
    await page.locator('input[aria-label="Code promo"]').scrollIntoViewIfNeeded();
    await snap(page, '03-site-code-applique');
    const cta = lastEuro(await page.locator('.lcf-cta-bar-next').innerText());
    const recap = norm(await page.locator('.lcf-summary-totals-row', { hasText: 'Livraison' }).first().innerText().catch(() => ''));
    check('site : « ✓ CODE · Livraison offerte », récap « Offerte », bouton = repas seul',
      /✓/.test(promo) && /Livraison offerte/.test(promo) && /Offerte/.test(recap) && Math.abs(cta - sousTotal) < 0.005, { promo, recap, cta, sousTotal });
    expect(Math.abs(cta - sousTotal)).toBeLessThan(0.005);
    await page.locator('input[aria-label="Adresse de livraison"]').scrollIntoViewIfNeeded();
    await snap(page, '04-site-devis-offert');
    await sIdentifier(page);
    await choisirEspeces(page);
    const o = await confirmer(page, 'livraison offerte Hénin');
    expect(o.http, o.corps || '').toBe(201);
    check('site : le code est envoyé avec la commande', Number(o.envoye.coupon_id) > 0, { coupon_id: o.envoye.coupon_id, expected_total: o.envoye.expected_total });
    await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 45_000 });
    await page.waitForTimeout(1200);
    await snap(page, '05-site-confirmation');
    const row = mysql(`SELECT o.subtotal, o.discount, o.delivery_charge, o.total, oc.delivery_waived FROM orders o JOIN order_coupons oc ON oc.order_id = o.id WHERE o.id = ${Number(o.id)}`).split('\t').map(Number);
    check('base : livraison à 0 €, aucune remise sur les articles, total = repas, frais offerts tracés', row[1] === 0 && row[2] === 0 && Math.abs(row[3] - row[0]) < 0.005 && Math.abs(row[4] - fee) < 0.005, { subtotal: row[0], discount: row[1], delivery_charge: row[2], total: row[3], delivery_waived: row[4], fee });
    expect(row[2]).toBe(0);
    expect(Math.abs(row[4] - fee)).toBeLessThan(0.005);
    await ctx.close();
  });

  test('3. site : hors conditions, le site le dit, n’envoie pas le code, et le code reste valable', async ({ browser }) => {
    const flyer = await creerCodeParApi('Sarah');
    const ctx = await newSiteCtx(browser);
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });

    // (a) panier < 10 € : le serveur refuse le code dès la saisie (minimum de commande)
    await ajouterProduit(page, 'Cheese Burger');
    const petit = await versCommande(page);
    expect(petit).toBeLessThan(10);
    await appliquerCode(page, flyer.code);
    const errMin = norm(await page.locator('.lcf-field-error[role=alert]').first().innerText().catch(() => ''));
    check('(a) < 10 € : message « montant minimum »', /minimum/i.test(errMin), { errMin, petit });

    // (b) à emporter avec un panier ≥ 10 € : « choisissez Livraison »
    await ajouterProduit(page, 'Cayenne');
    const sousTotal = await versCommande(page);
    expect(sousTotal).toBeGreaterThanOrEqual(10);
    await appliquerCode(page, flyer.code);
    const aEmporter = norm(await page.locator('.lcf-promo-livraison').innerText());
    await page.locator('input[aria-label="Code promo"]').scrollIntoViewIfNeeded();
    await snap(page, '06-site-a-emporter');
    check('(b) à emporter : « choisissez Livraison », le code reste valable', /choisissez « Livraison »/.test(aEmporter) && /reste valable/.test(aEmporter), aEmporter);
    expect(aEmporter).toMatch(/choisissez « Livraison »/);

    // (c) livraison à plus de 7 km (Lens) : message, frais facturés, code NON envoyé, commande acceptée
    await choisirLivraison(page);
    const quote = await saisirAdresse(page, '1 place Jean Jaures 62300 Lens', /Lens/i);
    L.notes.push(`devis Lens : ${JSON.stringify({ allowed: quote && quote.allowed, km: quote && quote.distance_km, fee: quote && quote.fee })}`);
    expect(quote && quote.allowed, 'Lens est livrable par nous (≤ 10 km)').toBe(true);
    expect(Number(quote.distance_km)).toBeGreaterThan(7);
    const fee = Number(quote.fee);
    const loin = norm(await page.locator('.lcf-promo-livraison').innerText());
    await choisirEspeces(page);
    const cta = lastEuro(await page.locator('.lcf-cta-bar-next').innerText());
    await page.locator('input[aria-label="Code promo"]').scrollIntoViewIfNeeded();
    await snap(page, '07-site-plus-de-7-km');
    check('(c) > 7 km : « jusqu’à 7 km … reste valable », frais facturés', /jusqu'à 7 km/.test(loin) && /reste valable/.test(loin) && Math.abs(cta - (sousTotal + fee)) < 0.005, { loin, cta, sousTotal, fee });
    expect(loin).toMatch(/jusqu'à 7 km/);
    await sIdentifier(page);
    await choisirEspeces(page);
    const o = await confirmer(page, 'plus de 7 km sans code');
    expect(o.http, o.corps || '').toBe(201);
    check('(c) le code n’est PAS envoyé, la commande passe au prix normal', !o.envoye.coupon_id, { coupon_id: o.envoye.coupon_id });
    expect(o.envoye.coupon_id || null).toBeNull();
    check('(c) le code reste utilisable (0 utilisation)', usages(flyer.code) === 0, { usages: usages(flyer.code) });
    expect(usages(flyer.code)).toBe(0);
    await ctx.close();
  });

  test('4. administration : bilan offre par offre', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 1000 });
    await loginAsPosOperator(page);
    await page.goto(`${BASE}/admin/promo-flyer`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-offer="free_delivery"]')).toBeVisible({ timeout: 30_000 });
    await settle(page);
    await snap(page, '08-admin-bilan-par-offre');
    const bilan = norm(await page.locator('[data-offer="free_delivery"]').innerText());
    check('admin : bilan « Livraison offerte » avec au moins 1 utilisé', /Livraison offerte/.test(bilan) && /Codes utilisés : [1-9]/.test(bilan), bilan);
    check('aucune requête vers la production', L.prodBloquees.length === 0, L.prodBloquees);
    expect(L.prodBloquees).toEqual([]);
  });
});
