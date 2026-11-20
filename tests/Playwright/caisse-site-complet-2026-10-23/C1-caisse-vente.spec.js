/**
 * [AUDIT E2E caisse-site-complet-2026-10-23 · VAGUE C1 · round 1] Caisse : vente, encaissement, ticket.
 *
 * Mesure, ne corrige rien. Données créées : commandes de test dont le nom client commence par « E2E-C1- ».
 * Produits réels (lus en base, status=5) : « Cayenne » (id 22, assistant pain/viande/sauce) et
 * « Fanta Orange 33cl » (id 54). Coca-Cola 33cl est en rupture locale (bandeau « Article indisponible »).
 *
 * Sélecteurs lus dans le code (jamais devinés) :
 *   tuiles catégorie  [data-testid=pos-category-tile] (PosComponent.vue:1032)
 *   produit           [data-pos-item-id]
 *   assistant         #item-variation-modal · .pain-section .pain-btn · .viande-tile-add · .sauce-chip
 *                     · bouton VISIBLE .wizard-btn-cart[data-action=add-to-cart] (pos-wizard.js:3521) — jamais
 *                     le .pos-v5-item-add-cta caché de Vue (piège « deux boutons Ajouter au panier »)
 *   panier            article.pos-v5-cart-item · .pos-v5-cart-item__price · [data-testid=pos-grand-total]
 *   payer             [data-testid=pos-v5-pay] → #orderpayment · .pos-v5-payment-total-value
 *                     · [data-testid=pos-payment-mode-cash] · #cashInput · .pos-v5-payment-change-value
 *                     · [data-testid=pos-payment-confirm]
 *   ticket (aperçu)   #receiptModal #print-receipt-client · [data-testid=receipt-print-decision-no]
 *   téléphone         [data-testid=pos-phone-order] · [data-testid=pos-shortcut-encaisser-<id>]
 *                     · [data-testid=pos-counter-collect-modal|total|confirm]
 *                     · [data-testid=counter-collect-print-decision-no]
 *   livraison         label[for=delivery] · [data-testid=pos-delivery-order]
 *
 *   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
 *   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
 *     npx playwright test tests/Playwright/caisse-site-complet-2026-10-23/C1-caisse-vente.spec.js --workers=1 --retries=0 --reporter=line
 */
const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsPosOperator } = require('../../e2e/helpers/login');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');

const ROOT = path.resolve(__dirname, '../../..');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const OUT = path.join(ROOT, 'reports/test-e2e/caisse-site-complet-2026-10-23/captures-C1');
fs.mkdirSync(OUT, { recursive: true });
const RES = path.join(OUT, 'C1-mesures.json');
const R = { debut: new Date().toISOString(), notes: [] };
const flush = () => fs.writeFileSync(RES, JSON.stringify(R, null, 2));
const note = (s) => { R.notes.push(s); console.log('[C1] ' + s); flush(); };

const envVal = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = envVal('MIX_API_KEY') || envVal('API_KEY');

/** Lecture base via tinker (lecture seule). L'expression PHP doit produire une valeur JSON-encodable. */
function db(phpExpr) {
  const out = execFileSync('php', ['artisan', 'tinker', `--execute=echo '@@'.json_encode(${phpExpr}).'@@';`], { cwd: ROOT, encoding: 'utf8', timeout: 60_000 });
  const m = out.match(/@@([\s\S]*)@@/);
  if (!m) throw new Error('tinker sans sortie : ' + out.slice(0, 400));
  return JSON.parse(m[1]);
}
const euros = (s) => { const m = String(s || '').replace(/\s/g, '').replace(/ /g, '').match(/-?\d+(?:[.,]\d+)?/); return m ? Number(m[0].replace(',', '.')) : NaN; };
const settle = async (page, ms = 1200) => { await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {}); await page.waitForTimeout(ms); };

let api;
async function apiCtx() {
  clearFoodKingRateLimits();
  const pub = { 'X-API-KEY': API_KEY, Accept: 'application/json', 'Content-Type': 'application/json' };
  const l = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, 'X-Device-Id': 'e2e-c1-api-' + Date.now() } });
  const r = await l.post('/api/auth/login', { data: { email: 'pos@lecayenne.fr', password: '123456' } });
  expect(r.status(), 'login API pos@').toBe(201);
  const tok = (await r.json()).token;
  await l.dispose();
  return request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, Authorization: `Bearer ${tok}` } });
}

/** Décodeur ESC/POS minimal (texte CP858 + saut de ligne), connaît GS B / GS ! / GS ( k. */
function decodeEscPos(buf) {
  const lignes = []; let i = 0; let cur = [];
  const iconv = require('iconv-lite');
  const fl = () => { if (cur.length) { lignes.push(iconv.decode(Buffer.from(cur), 'cp858').replace(/\s+$/g, '')); cur = []; } };
  while (i < buf.length) {
    const b = buf[i];
    if (b === 0x1b) { const c = buf[i + 1]; if (c === 0x40 || c === 0x32) i += 2; else if (c === 0x70) i += 5; else i += 3; }
    else if (b === 0x1d) { const c = buf[i + 1]; if (c === 0x28) i += 5 + buf[i + 3] + buf[i + 4] * 256; else if (c === 0x56) i += (buf[i + 2] === 65 || buf[i + 2] === 66) ? 4 : 3; else i += 3; }
    else if (b === 0x0a) { fl(); i += 1; }
    else if (b >= 0x20) { cur.push(b); i += 1; }
    else i += 1;
  }
  fl();
  return lignes;
}

// ── gestes caisse ────────────────────────────────────────────────────────────────────────────────
async function viderPanier(page) {
  const cancelLast = page.getByTestId('pos-cancel-last-line');
  for (let k = 0; k < 10; k += 1) {
    if (!(await cancelLast.isVisible({ timeout: 800 }).catch(() => false))) break;
    await cancelLast.click();
    await page.waitForTimeout(300);
  }
  await expect(page.locator('article.pos-v5-cart-item')).toHaveCount(0, { timeout: 10_000 });
}
async function ouvrirCategorie(page, nom) {
  const tuile = page.getByTestId('pos-category-grid').getByTestId('pos-category-tile').filter({ hasText: new RegExp('^\\s*' + nom + '\\s*$') }).first();
  if (await tuile.isVisible({ timeout: 3000 }).catch(() => false)) { await tuile.click(); }
  else {
    // déjà dans une catégorie : revenir à la grille
    await page.getByTestId('pos-browse-back').click();
    await page.getByTestId('pos-category-grid').getByTestId('pos-category-tile').filter({ hasText: new RegExp('^\\s*' + nom + '\\s*$') }).first().click();
  }
  await page.waitForTimeout(600);
}
/** Termine l'assistant par le SEUL bouton visible (pos-wizard.js). */
async function validerAssistant(page) {
  const modal = page.locator('#item-variation-modal');
  const btn = modal.locator('button.wizard-btn-cart[data-action="add-to-cart"]');
  await expect(btn).toBeVisible({ timeout: 10_000 });
  await btn.click();
  await expect(modal).toBeHidden({ timeout: 15_000 });
}
async function choisirTypeEmporter(page) {
  await page.locator('label[for="takeway"]').click();
  await expect(page.locator('label[for="takeway"]')).toHaveClass(/is-active/);
}
async function ajouterFanta(page) {
  await ouvrirCategorie(page, 'Boissons');
  const fanta = page.locator('[data-pos-item-id="54"]:visible').first();
  await expect(fanta, 'Fanta Orange 33cl disponible').toBeEnabled({ timeout: 15_000 });
  await fanta.click();
  // Une boisson simple est ajoutée directement ; si l'assistant s'ouvre, on le termine par SON bouton.
  const modal = page.locator('#item-variation-modal');
  if (await modal.isVisible({ timeout: 2500 }).catch(() => false)) await validerAssistant(page);
  await page.waitForTimeout(600);
}
async function lirePanier(page) {
  return page.evaluate(() => ({
    lignes: [...document.querySelectorAll('article.pos-v5-cart-item')].map((a) => ({
      texte: a.innerText.replace(/\s+/g, ' ').trim().slice(0, 300),
      prix: (a.querySelector('.pos-v5-cart-item__price') || {}).innerText || null,
      detail: (a.querySelector('.pos-v5-cart-item__detail') || {}).innerText || null,
    })),
    total: (document.querySelector('[data-testid="pos-grand-total"]') || {}).innerText || null,
  }));
}

// ═════════════════════════════════════════════════════════════════════════════════════════════════
test.describe.configure({ mode: 'serial', timeout: 300_000 });
test.use({ actionTimeout: 30_000, navigationTimeout: 60_000 });

test.describe('C1 · caisse : vente, encaissement, ticket', () => {
  let rec;
  let page;
  test.beforeAll(async ({ browser }) => {
    api = await apiCtx();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 720 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    page = await ctx.newPage();
    page.setDefaultTimeout(60_000);
    rec = attachMegaAuditRecorder(page, OUT);
    R.posUser = db("DB::table('users')->where('email','pos@lecayenne.fr')->first(['id','name'])");
    note('opérateur pos@ = ' + JSON.stringify(R.posUser));
    await loginAsPosOperator(page);
  });
  test.afterAll(async () => {
    try { if (page) { await viderPanier(page).catch(() => {}); await choisirTypeEmporter(page).catch(() => {}); } } catch (_) { /* nettoyage */ }
    // Nettoyage : commandes téléphone « E2E-C1-Tel » restées à encaisser (tours précédents interrompus)
    try {
      const restes = db("DB::table('orders')->where('pos_customer_name','E2E-C1-Tel')->where('payment_status',15)->pluck('id')");
      for (const oid of restes) {
        const r = await api.post(`/api/admin/pos/counter-collect/${oid}/cancel`, { data: { reason: 'E2E-C1 nettoyage banc' }, headers: { 'X-Idempotency-Key': 'e2e-c1-cancel-' + oid + '-' + Date.now() } });
        R.notes.push(`nettoyage ${oid} → HTTP ${r.status()}`);
      }
    } catch (e) { R.notes.push('nettoyage : ' + e.message); }
    R.fin = new Date().toISOString();
    flush();
  });

  test('01 · caisse chargée 1280×720 puis 1920×1080 : catalogue, panier vide, en-tête, « Livraisons »', async () => {
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    await viderPanier(page);
    await choisirTypeEmporter(page);
    const mesure = async () => page.evaluate(() => {
      const r = (sel) => { const e = document.querySelector(sel); if (!e) return null; const b = e.getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), h: Math.round(b.height), w: Math.round(b.width) }; };
      const zoom = Number(getComputedStyle(document.body).zoom || 1);
      const header = document.querySelector('header') || document.querySelector('.db-header');
      const hb = header ? header.getBoundingClientRect() : null;
      const aside = document.querySelector('aside');
      const ab = aside ? aside.getBoundingClientRect() : null;
      return {
        inner: [innerWidth, innerHeight], zoom,
        docScrollH: document.documentElement.scrollHeight,
        // Bas réel (en px écran) de l'élément le plus bas du panier latéral
        asideBottomEcran: ab ? Math.round(ab.bottom * zoom) : null,
        headerH: hb ? Math.round(hb.height * zoom) : null,
        headerLignes: header ? [...header.querySelectorAll('button, a')].filter((x) => x.offsetParent).map((x) => Math.round(x.getBoundingClientRect().top)).filter((v, i, a) => a.indexOf(v) === i).length : null,
        catalogue: [...document.querySelectorAll('[data-testid="pos-category-tile"]')].filter((e) => e.offsetParent).map((e) => e.innerText.trim()),
        lignesPanier: document.querySelectorAll('article.pos-v5-cart-item').length,
        total: (document.querySelector('[data-testid="pos-grand-total"]') || {}).innerText || null,
        livraisonsLien: [...document.querySelectorAll('a[href="/admin/livraisons"]')].map((a) => ({ texte: a.innerText.trim(), visible: !!a.offsetParent })),
        segments: [...document.querySelectorAll('.pos-v5-segmented__item')].map((e) => e.innerText.replace(/\s+/g, ' ').trim()),
        bandeaux: [...document.querySelectorAll('[role="alert"]')].filter((e) => e.offsetParent).map((e) => e.innerText.trim().slice(0, 160)),
        grandTotalRect: r('[data-testid="pos-grand-total"]'),
      };
    });
    R.chargement1280 = await mesure();
    note('chargement 1280 : ' + JSON.stringify(R.chargement1280));
    await settle(page);
    await rec.snap('01-caisse-1280x720');

    await page.setViewportSize({ width: 1920, height: 1080 });
    await settle(page, 1500);
    R.chargement1920 = await mesure();
    note('chargement 1920 : ' + JSON.stringify(R.chargement1920));
    await rec.snap('02-caisse-1920x1080');

    for (const m of [R.chargement1280, R.chargement1920]) {
      expect(m.catalogue.length, 'catalogue : tuiles catégories visibles').toBeGreaterThanOrEqual(8);
      expect(m.lignesPanier, 'panier vide').toBe(0);
      expect(euros(m.total), 'total panier vide = 0').toBe(0);
      expect(m.livraisonsLien.length, 'lien « Livraisons » dans la navigation').toBeGreaterThan(0);
      expect(m.segments.join(' | ')).toMatch(/À emporter/);
    }
  });

  test('02-04 · deux produits par le VRAI assistant → panier → espèces → ticket → base', async () => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await choisirTypeEmporter(page);
    await viderPanier(page);
    // Nom client E2E-C1- : identifie la commande de test (imprimé sur le ticket cuisine)
    await page.locator('#pos-customer-name').fill('E2E-C1-Vente');

    // ── produit 1 : Cayenne par l'assistant (pain + viande + sauce Algérienne)
    await ouvrirCategorie(page, 'Sandwichs');
    await page.locator('[data-pos-item-id="22"]:visible').first().click();
    const modal = page.locator('#item-variation-modal');
    await expect(modal).toBeVisible({ timeout: 15_000 });
    await modal.locator('.pain-section button.pain-btn').filter({ hasText: /Pain/ }).first().click();
    await modal.locator('.viande-section .wizard-viande-tile').filter({ hasText: /poulet mariné/i }).first().locator('.viande-tile-add').click();
    const sauce = modal.locator('.sauce-section .sauce-chip').filter({ hasText: /Algérienne/ }).first();
    await sauce.click();
    await expect(sauce).toHaveClass(/selected/);
    await page.waitForTimeout(800);
    R.assistant = await modal.evaluate((m) => ({
      prix: [...m.querySelectorAll('[class*="price"], [class*="total"]')].filter((e) => e.offsetParent).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 8),
      sauceSelectionnee: [...m.querySelectorAll('.sauce-chip.selected')].map((e) => e.innerText.trim()),
      boutonsAjouter: [...document.querySelectorAll('button')].filter((b) => /Ajouter au panier/i.test(b.textContent || '')).map((b) => ({ cls: b.className.slice(0, 60), visible: !!b.offsetParent })),
    }));
    note('assistant Cayenne : ' + JSON.stringify(R.assistant));
    await rec.snap('03-assistant-cayenne-sauce');
    await validerAssistant(page);

    // ── produit 2 : Fanta Orange 33cl
    await ajouterFanta(page);
    await settle(page);
    R.panier = await lirePanier(page);
    R.panier.sommeLignes = Math.round(R.panier.lignes.reduce((s, l) => s + euros(l.prix), 0) * 100) / 100;
    R.panier.cartDisplay = await page.evaluate(() => [...document.querySelectorAll('article.pos-v5-cart-item')].map((a) => a.getAttribute('data-wizard-cart-display') || null));
    note('panier : ' + JSON.stringify(R.panier));
    await rec.snap('04-panier-cayenne-fanta');
    expect(R.panier.lignes.length, 'deux lignes au panier').toBe(2);
    expect(R.panier.lignes[0].texte + R.panier.lignes[1].texte).toMatch(/Cayenne/);
    expect(R.panier.lignes.map((l) => l.texte).join(' '), 'la sauce choisie est portée par la ligne (assistant réel, pas contourné)').toMatch(/Algérienne/i);
    expect(R.panier.lignes.map((l) => l.texte).join(' ')).toMatch(/Fanta/);
    const totalPanier = euros(R.panier.total);
    expect(totalPanier, 'total panier = somme des lignes').toBeCloseTo(R.panier.sommeLignes, 2);

    // ── fenêtre de paiement
    await page.getByTestId('pos-v5-pay').filter({ visible: true }).first().click();
    const pay = page.locator('#orderpayment');
    await expect(pay.locator('.pos-v5-payment-total-value')).toBeVisible({ timeout: 20_000 });
    await page.getByTestId('pos-payment-mode-cash').click();
    const totalPaiement = euros(await pay.locator('.pos-v5-payment-total-value').innerText());
    const recu = Math.ceil((totalPaiement + 0.01) / 10) * 10; // billet supérieur : 20 € pour ~ 11 €
    await page.locator('#cashInput').fill(String(recu));
    await page.waitForTimeout(600);
    const rendu = euros(await pay.locator('.pos-v5-payment-change-value').innerText());
    R.paiement = { totalPaiement, recu, rendu };
    note('paiement : ' + JSON.stringify(R.paiement));
    await settle(page);
    await rec.snap('05-paiement-especes-rendu');
    expect(totalPaiement, 'total fenêtre de paiement = total panier').toBeCloseTo(totalPanier, 2);
    expect(rendu, 'monnaie à rendre = reçu − total').toBeCloseTo(recu - totalPaiement, 2);

    const posResp = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/admin/pos', { timeout: 60_000 });
    await page.getByTestId('pos-payment-confirm').click();
    const resp = await posResp;
    const corps = await resp.json().catch(() => null);
    R.creation = { http: resp.status(), id: corps && corps.data && corps.data.id, total: corps && corps.data && corps.data.total, operator_name: corps && corps.data && corps.data.operator_name };
    note('POST /api/admin/pos : ' + JSON.stringify(R.creation));
    expect(resp.status(), 'commande créée').toBe(201);

    // ── aperçu ticket (fenêtre ticket + question « Imprimer le ticket client ? »)
    const ticket = page.locator('#receiptModal #print-receipt-client');
    await expect(ticket).toBeVisible({ timeout: 30_000 });
    await settle(page);
    R.apercuTicket = (await ticket.innerText()).split('\n').map((s) => s.trim()).filter(Boolean);
    note('aperçu ticket client : ' + JSON.stringify(R.apercuTicket));
    await rec.snap('06-succes-apercu-ticket');
    await page.getByTestId('receipt-print-decision-no').click();
    await page.waitForTimeout(800);

    // ── base
    const id = R.creation.id;
    R.base = db(`DB::table('orders')->where('id',${Number(id)})->first(['id','order_serial_no','total','subtotal','payment_status','payment_method','pos_payment_method','fiscal_sequence_no','creator_id','user_id','order_type','status','source'])`);
    R.base.client = db(`DB::table('users')->where('id',${Number(R.base.user_id)})->first(['id','name','email'])`);
    note('base : ' + JSON.stringify(R.base));

    // ── ticket réel côté serveur : octets ESC/POS du ticket client + API détail
    const esc = await api.get(`/api/admin/pos/orders/${id}/escpos?ticket=client`);
    const ej = await esc.json().catch(() => null);
    R.escpos = { http: esc.status(), lignes: ej && ej.escpos_b64 ? decodeEscPos(Buffer.from(ej.escpos_b64, 'base64')) : null };
    const show = await api.get(`/api/admin/pos-order/show/${id}`);
    const sj = await show.json().catch(() => null);
    R.apiShow = { http: show.status(), operator_name: sj && sj.data && sj.data.operator_name, total: sj && sj.data && sj.data.total, user: sj && sj.data && sj.data.user && sj.data.user.name };
    note('escpos : ' + JSON.stringify(R.escpos) + ' · show : ' + JSON.stringify(R.apiShow));

    // ── intégrité numérique
    const txtEsc = (R.escpos.lignes || []).join('\n');
    const mTotalEsc = txtEsc.match(/MONTANT TOTAL[^\d]*(\d+[.,]\d{2})/);
    const totalTicketEsc = mTotalEsc ? euros(mTotalEsc[1]) : NaN;
    const totalApercu = (() => { const i = R.apercuTicket.findIndex((l) => /^total\b/i.test(l) || /total/i.test(l)); return R.apercuTicket.filter((l) => /€/.test(l)).map(euros); })();
    R.integrite = { panier: totalPanier, paiement: totalPaiement, ticketEscPos: totalTicketEsc, montantsApercu: totalApercu, base: Number(R.base.total) };
    note('intégrité : ' + JSON.stringify(R.integrite));
    expect(Number(R.base.total), 'orders.total = total panier').toBeCloseTo(totalPanier, 2);
    expect(totalTicketEsc, 'total ticket ESC/POS = total base').toBeCloseTo(Number(R.base.total), 2);
    expect(totalApercu, 'aperçu ticket affiche le total').toContain(Number(Number(R.base.total).toFixed(2)));
    expect(String(R.base.payment_status), 'payée').toBe('5');
    expect(R.base.fiscal_sequence_no, 'numéro fiscal attribué').not.toBeNull();
    expect(Number(R.base.creator_id), 'creator_id = pos@').toBe(Number(R.posUser.id));

    // ── « Caissier » = opérateur, jamais le client
    const ligneCaissier = (txtEsc.match(/Caissier : .*/) || [null])[0];
    R.caissier = { escpos: ligneCaissier, apercu: R.apercuTicket.filter((l) => /Caissier|Opérateur|Operator/i.test(l)), api: R.apiShow.operator_name, client: R.base.client && R.base.client.name };
    note('caissier : ' + JSON.stringify(R.caissier));
    expect(R.apiShow.operator_name, 'operator_name API = nom de pos@').toBe(R.posUser.name);
    expect(ligneCaissier, 'ticket ESC/POS : Caissier = opérateur').toBe('Caissier : ' + R.posUser.name);
    if (R.base.client && R.base.client.name !== R.posUser.name) {
      expect(txtEsc, 'le nom du client n\'apparaît pas comme caissier').not.toContain('Caissier : ' + R.base.client.name);
    }
    expect(R.caissier.apercu.join(' '), 'aperçu écran : opérateur affiché').toContain(R.posUser.name);

    // fermer la fenêtre ticket
    const fermer = page.locator('#receiptModal button.modal-close').first();
    if (await fermer.isVisible().catch(() => false)) await fermer.click();
    await page.waitForTimeout(800);
  });

  test('06 · « Commande téléphone » à emporter → file « À encaisser » → encaissée, numéro fiscal unique', async () => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await choisirTypeEmporter(page);
    await viderPanier(page);
    await ajouterFanta(page);
    await page.locator('#pos-customer-name').fill('E2E-C1-Tel');
    await page.getByTestId('pos-customer-phone').fill('0600000101');
    await expect(page.getByTestId('pos-phone-order')).toBeVisible();
    const totalPanier = euros((await lirePanier(page)).total);
    const fileAvant = Number(((await page.getByTestId('pos-shortcuts-cash').locator('.pos-shortcuts__title').innerText()).match(/\((\d+)\)/) || [0, 0])[1]);
    const posResp = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/admin/pos', { timeout: 60_000 });
    await page.getByTestId('pos-phone-order').click();
    const resp = await posResp;
    const cj = await resp.json().catch(() => null);
    const id = cj && cj.data && cj.data.id;
    R.tel = { http: resp.status(), id, totalPanier, fileAvant };
    R.tel.toast = await page.evaluate(() => [...document.querySelectorAll('.Vue-Toastification__toast, [role="alert"]')].filter((e) => e.offsetParent).map((e) => e.innerText.trim().slice(0, 200)));
    expect(resp.status(), 'commande téléphone créée').toBe(201);
    // La file « À encaisser » de l'écran caisse n'affiche que les 4 plus anciennes (FIFO, PosComponent.vue:644) ;
    // au-delà, « Voir plus » ouvre le tiroir de contrôle (PosControlDrawer : pos-control-collect-<id>).
    const enc = page.getByTestId(`pos-shortcut-encaisser-${id}`);
    const plus = page.getByTestId('pos-shortcut-cash-more');
    // NB instrument : une carte du tiroir hors de la zone défilée est « non visible » pour Playwright (:visible = 0)
    // alors qu'elle est affichée et atteignable (offsetParent non nul, visibility visible) → présence par le DOM, puis défilement.
    const tiroirCta = page.getByTestId(`pos-control-collect-${id}`);
    let viaTiroir = false;
    const t0 = Date.now();
    // 1) l'écran caisse : soit la carte (si elle est parmi les 4 plus anciennes), soit le compteur « Voir plus » qui augmente
    const titreFile = page.getByTestId('pos-shortcuts-cash').locator('.pos-shortcuts__title');
    await expect.poll(async () => (await enc.isVisible()) || new RegExp('\\(' + (R.tel.fileAvant + 1) + '\\)').test(await titreFile.innerText()), { timeout: 60_000, message: 'file « À encaisser » de l\'écran caisse rafraîchie (+1)' }).toBe(true);
    R.tel.delaiEcranMs = Date.now() - t0;
    R.tel.titreFile = (await titreFile.innerText()).trim();
    R.tel.voirPlus = (await plus.isVisible()) ? (await plus.innerText()).trim() : null;
    if (!(await enc.isVisible())) {
      viaTiroir = true;
      await plus.click();
      await expect(page.getByTestId('pos-control-drawer')).toBeVisible({ timeout: 20_000 });
      const t1 = Date.now();
      const ongletCompte = page.getByTestId('pos-control-count-encaisser');
      R.tel.tiroirCompteOuverture = (await ongletCompte.innerText().catch(() => '')).trim();
      // le tiroir lit la liste « journée de service » (poll ~10-15 s) : on mesure le délai, rafraîchissement manuel à mi-course
      let rafraichi = false;
      const diag = async () => { await page.screenshot({ path: '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/2c3726ad-78a8-4013-b7aa-66c1e1619e24/scratchpad/diag-tiroir.png' }); R.tel.diagStyle = await page.evaluate((oid) => { const e = document.querySelector(`[data-testid="pos-control-collect-${oid}"]`); if (!e) return null; const b = e.getBoundingClientRect(); const d = document.querySelector('[data-testid="pos-control-drawer"]'); const cs = getComputedStyle(e); return { rect: [b.x, b.y, b.width, b.height], vis: cs.visibility, op: cs.opacity, drawerVis: d && getComputedStyle(d).visibility, drawerRect: d && d.getBoundingClientRect().toJSON(), ouvert: d && d.className }; }, id); R.tel.diagTiroir = await page.evaluate(() => [...document.querySelectorAll('[data-testid^="pos-control-collect-"], [data-testid^="pos-control-card-"]')].map((e) => e.getAttribute('data-testid') + (e.offsetParent ? '' : ' (caché)') + ' ' + (e.innerText || '').replace(/\s+/g, ' ').slice(0, 60))); flush(); };
      // Boucle manuelle (mesure du délai d'apparition dans le tiroir, en secondes)
      R.tel.trace = [];
      let present = false;
      for (let k = 0; k < 90 && !present; k += 1) {
        if (!rafraichi && Date.now() - t1 > 30_000) {
          rafraichi = true;
          await page.getByTestId('pos-control-refresh').click({ timeout: 5000 }).catch((e) => R.tel.trace.push('refresh KO ' + e.message.slice(0, 80)));
        }
        const nb = await tiroirCta.count();
        R.tel.trace.push(`${Math.round((Date.now() - t1) / 1000)}s:${nb}`);
        present = nb === 1;
        if (!present) await page.waitForTimeout(1000);
      }
      if (!present) await diag();
      expect(present, 'la commande téléphone apparaît dans le tiroir « À encaisser » (≤ 90 s)').toBe(true);
      R.tel.delaiTiroirMs = Date.now() - t1;
      R.tel.rafraichissementManuel = rafraichi;
      R.tel.tiroirCompte = (await ongletCompte.innerText().catch(() => '')).trim();
      await tiroirCta.scrollIntoViewIfNeeded();
    } else {
      await enc.scrollIntoViewIfNeeded();
    }
    R.tel.viaTiroir = viaTiroir;
    R.tel.avant = db(`DB::table('orders')->where('id',${Number(id)})->first(['id','total','payment_status','pos_payment_method','fiscal_sequence_no','creator_id','user_id','order_type','status','source_surface'])`);
    note('téléphone avant encaissement : ' + JSON.stringify(R.tel));
    expect(String(R.tel.avant.payment_status), 'différée : à encaisser au comptoir (PENDING_COUNTER)').toBe('15');
    expect(R.tel.avant.fiscal_sequence_no, 'pas encore de numéro fiscal avant encaissement').toBeNull();
    await settle(page);
    await rec.snap('07-telephone-dans-a-encaisser');

    await (viaTiroir ? tiroirCta : enc).click();
    const cc = page.getByTestId('pos-counter-collect-modal');
    await expect(cc).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(800);
    R.tel.totalModale = euros(await page.getByTestId('pos-counter-collect-total').innerText());
    await rec.snap('08-telephone-fenetre-encaissement');
    expect(R.tel.totalModale, 'total fenêtre d\'encaissement = total panier téléphone').toBeCloseTo(totalPanier, 2);
    const confResp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/admin\/pos\/counter-collect\/\d+\/confirm$/.test(new URL(r.url()).pathname), { timeout: 60_000 });
    await page.getByTestId('pos-counter-collect-confirm').click();
    const cr = await confResp;
    R.tel.confirmHttp = cr.status();
    await settle(page);
    await rec.snap('09-telephone-encaissee');
    const non = page.getByTestId('counter-collect-print-decision-no');
    if (await non.isVisible({ timeout: 5000 }).catch(() => false)) await non.click();
    R.tel.apres = db(`DB::table('orders')->where('id',${Number(id)})->first(['id','total','payment_status','pos_payment_method','payment_method','fiscal_sequence_no','creator_id','user_id','order_type','status'])`);
    R.tel.memeNumero = db(`DB::table('orders')->where('branch_id',1)->where('fiscal_sequence_no',${JSON.stringify(R.tel.apres.fiscal_sequence_no)})->count()`);
    const show = await api.get(`/api/admin/pos-order/show/${id}`);
    const sj = await show.json().catch(() => null);
    R.tel.operator_name = sj && sj.data && sj.data.operator_name;
    note('téléphone après : ' + JSON.stringify(R.tel));
    expect(cr.status(), 'confirmation d\'encaissement').toBeLessThan(300);
    expect(String(R.tel.apres.payment_status), 'payée après encaissement').toBe('5');
    expect(R.tel.apres.fiscal_sequence_no, 'numéro fiscal attribué').not.toBeNull();
    expect(R.tel.memeNumero, 'numéro fiscal attribué une seule fois').toBe(1);
    expect(Number(R.tel.apres.total)).toBeCloseTo(totalPanier, 2);
    expect(R.tel.operator_name, 'opérateur = pos@').toBe(R.posUser.name);
    await expect(page.getByTestId(`pos-shortcut-encaisser-${id}`), 'sortie de la file « À encaisser »').toHaveCount(0, { timeout: 20_000 });
    await expect(page.getByTestId(`pos-control-card-${id}`), 'sortie du tiroir « À encaisser »').toHaveCount(0, { timeout: 30_000 });
    const fermerTiroir = page.getByTestId('pos-control-close');
    if (await fermerTiroir.isVisible().catch(() => false)) await fermerTiroir.click();
  });

  test('07 · mode Livraison : « Commande téléphone » absent, « Commande livraison » présent', async () => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await viderPanier(page);
    await ajouterFanta(page);
    await page.locator('label[for="delivery"]').click();
    await expect(page.locator('label[for="delivery"]')).toHaveClass(/is-active/);
    await page.waitForTimeout(800);
    R.livraison = {
      telephone: await page.getByTestId('pos-phone-order').count(),
      livraison: await page.getByTestId('pos-delivery-order').isVisible(),
      libelle: (await page.getByTestId('pos-delivery-order').innerText()).replace(/\s+/g, ' ').trim(),
    };
    note('mode livraison : ' + JSON.stringify(R.livraison));
    await page.getByTestId('pos-delivery-order').scrollIntoViewIfNeeded();
    await settle(page);
    await rec.snap('10-mode-livraison-boutons');
    expect(R.livraison.telephone, '« Commande téléphone » absent en livraison').toBe(0);
    expect(R.livraison.livraison, '« Commande livraison » visible').toBe(true);
    // retour à l'état neutre, sans créer de livraison (vague C2)
    await choisirTypeEmporter(page);
    await viderPanier(page);
  });
});
