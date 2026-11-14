/**
 * [ETAT-DES-LIEUX 2026-10-23 · VAGUE C · caisse] Mesure LECTURE SEULE de ce que la caisse fait aujourd'hui face aux
 * exigences livraison C1..C8. Aucune correction. Données de test préfixées « E2E-ETAT-C ».
 *
 *   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
 *   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
 *     npx playwright test tests/Playwright/etat-des-lieux-2026-10-23/C-caisse-livraison.spec.js --workers=1 --retries=0 --reporter=line
 */
const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsPosOperator } = require('../../e2e/helpers/login');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const ROOT = path.resolve(__dirname, '../../..');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const OUT = path.join(ROOT, 'reports/test-e2e/etat-des-lieux-2026-10-23');
const SHOTS = path.join(OUT, 'C-captures');
fs.mkdirSync(SHOTS, { recursive: true });
const RES = path.join(OUT, 'C-resultats-spec.json');
let R = {};
try { R = JSON.parse(fs.readFileSync(RES, 'utf8')); } catch (_) { R = {}; }
R.notes = R.notes || [];
const flush = () => fs.writeFileSync(RES, JSON.stringify(R, null, 2));
const note = (s) => { R.notes.push(s); console.log('[C] ' + s); flush(); };
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = env('MIX_API_KEY') || env('API_KEY');
const pub = { 'X-API-KEY': API_KEY, Accept: 'application/json', 'Content-Type': 'application/json' };
function sql(q) { return execFileSync('mysql', ['-uroot', '-h', 'localhost', 'foodking_e2e', '-N', '-B', '-e', q], { env: { ...process.env, MYSQL_PWD: env('DB_PASSWORD') }, encoding: 'utf8' }).trim().split('\n').filter(Boolean).map((l) => l.split('\t')); }
const shot = async (page, name) => { await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: false }); };
const settle = async (page, ms = 1200) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(ms); };
const toggleLoc = (page) => page.locator('[data-testid="pos-delivery-toggle"]');

let api; let tok;
async function apiLogin() {
  clearFoodKingRateLimits();
  const l = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, 'X-Device-Id': 'e2e-etat-c-' + Date.now() } });
  const r = await l.post('/api/auth/login', { data: { email: 'pos@lecayenne.fr', password: '123456' } });
  expect(r.status(), 'login API pos@').toBe(201);
  tok = (await r.json()).token;
  await l.dispose();
  return request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, Authorization: `Bearer ${tok}` } });
}
const statutCaisse = async () => { const r = await api.get('/api/admin/pos-delivery/status'); return { http: r.status(), body: await r.json().catch(() => null) }; };

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);
test.use({ actionTimeout: 20_000, viewport: { width: 1440, height: 900 } });

test.describe('C · caisse livraison (état des lieux)', () => {
  test.beforeAll(async () => {
    api = await apiLogin();
    R.initial = await statutCaisse();
    note('état initial livraison : ' + JSON.stringify(R.initial));
  });
  test.afterAll(async () => {
    try {
      const voulu = R.initial && R.initial.body && R.initial.body.enabled;
      const cur = (await statutCaisse()).body.enabled;
      if (voulu !== undefined && cur !== voulu) await api.post('/api/admin/pos-delivery/toggle', { data: { enabled: voulu } });
      R.final = await statutCaisse();
      note('état final livraison : ' + JSON.stringify(R.final));
    } catch (e) { note('restauration : ' + e.message); }
  });

  test('C0 instrument : la caisse charge, le bouton livraison et le segment Livraison existent', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    await expect(toggleLoc(page)).toBeVisible({ timeout: 20_000 });
    R.c0 = {
      bouton: (await toggleLoc(page).innerText()).replace(/\s+/g, ' ').trim(),
      segments: await page.locator('.pos-v5-segmented__item').allInnerTexts(),
      labelLivraison: await page.locator('label[for="delivery"]').count(),
      boutonTournees: await page.locator('[data-testid="pos-delivery-board-open"]').count(),
      panierVide: await page.locator('button[data-pos-item-id]').count(),
    };
    note('C0 ' + JSON.stringify(R.c0));
    await shot(page, '01-caisse-panier-vide');
    expect(R.c0.labelLivraison, 'le segment Livraison est dans le DOM').toBeGreaterThan(0);
  });
  async function ouvrirLivraison(page) {
    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    await expect(toggleLoc(page)).toBeVisible({ timeout: 20_000 });
    if (/suspendue/.test(await toggleLoc(page).innerText())) { await toggleLoc(page).click(); await toggleLoc(page).click(); await page.waitForTimeout(1500); }
    await expect(toggleLoc(page)).toContainText('active');
  }
  async function ajouterEauPlate(page) {
    await page.locator('button:visible', { hasText: /^\s*Boissons\s*$/ }).first().click({ timeout: 15000 });
    await page.locator('button[data-pos-item-id="58"]').click({ timeout: 15000 });
    await page.waitForTimeout(1000);
  }

  test('C1-C2-C4 parcours : livraison choisie au début, adresse, suggestions, frais, paiement, ticket', async ({ page }) => {
    test.setTimeout(200_000);
    const reseau = [];
    page.on('response', async (r) => {
      const m = r.request().method();
      if (/pos-delivery|\/api\/admin\/pos(\/quote)?$|\/api\/admin\/pos\/orders|ticket-bytes|escpos/.test(r.url())) {
        const e = `${m} ${new URL(r.url()).pathname}${new URL(r.url()).search} ${r.status()}`;
        reseau.push(e);
      }
    });
    await ouvrirLivraison(page);
    // C1 : le choix est faisable AVANT tout article (panier vide)
    await page.locator('label[for="delivery"]').click();
    await page.waitForTimeout(600);
    R.c1 = { avantArticle: true, formulaireVisible: await page.locator('#orderdelivery').isVisible(), champs: {
      nom: await page.locator('#pos-delivery-name').count(), tel: await page.locator('#pos-delivery-phone').count(), adresse: await page.locator('#pos-delivery-address').count() } };
    await shot(page, '02-livraison-choisie-panier-vide');
    expect(R.c1.formulaireVisible, 'formulaire livraison visible dès le choix, panier vide').toBe(true);

    const phone = '06' + String(Math.floor(10000000 + Math.random() * 89999999)).slice(0, 8);
    R.c2 = { phone };
    await page.locator('#pos-delivery-phone').fill(phone);
    await page.locator('#pos-delivery-name').fill('E2E-ETAT-C Client');
    await page.locator('#pos-delivery-address').fill('437 rue elie gruyelle henin');
    const li = page.locator('#orderdelivery ul li');
    await expect(li.first()).toBeVisible({ timeout: 15000 });
    R.c2.suggestions = await li.allInnerTexts();
    await shot(page, '03-suggestions-adresse');
    await li.first().dispatchEvent('mousedown');
    await expect(page.locator('[data-testid="pos-delivery-quote"]')).toBeVisible({ timeout: 15000 });
    R.c2.devis = (await page.locator('[data-testid="pos-delivery-quote"]').innerText()).replace(/\s+/g, ' ').trim();
    R.c2.depart = await page.locator('[data-testid="pos-delivery-departure"]').innerText().catch(() => null);
    note('C2 ' + JSON.stringify(R.c2));
    await ajouterEauPlate(page);
    await page.waitForTimeout(800);
    await shot(page, '04-devis-frais-affiches');
    R.c2.totalAffiche = (await page.locator('button:visible', { hasText: /Commande\s*·/ }).first().innerText()).replace(/\s+/g, ' ');
    R.c2.ligneLivraison = await page.locator('text=/Frais de livraison|Livraison\\s*:/i').allInnerTexts().catch(() => []);
    // C4 : bouton « Commande téléphone » en livraison ?
    R.c4 = { boutonsVisibles: (await page.locator('aside button:visible').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean) };
    await page.locator('button:visible', { hasText: /Commande\s*·/ }).first().click({ timeout: 15000 });
    await page.waitForTimeout(1500);
    await shot(page, '05-modale-paiement-livraison');
    R.c4.modale = (await page.locator('div[role="dialog"], .modal, .pos-payment, #payment-modal').first().innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 900);
    R.c4.boutonsModale = (await page.locator('button:visible').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean);
    note('C4 ' + JSON.stringify(R.c4));
    expect(R.c4.boutonsModale.length).toBeGreaterThan(3);
    await page.locator('button:visible', { hasText: /Espèces/ }).first().click({ timeout: 15000 });
    await page.locator('button:visible', { hasText: /^5$/ }).first().click();
    await shot(page, '06-paiement-especes-saisi');
    await page.locator('button:visible', { hasText: /Confirmer/ }).first().click();
    const dejaCree = await page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/admin\/pos$/.test(new URL(r.url()).pathname), { timeout: 25000 }).catch(() => null);
    expect(dejaCree, 'POST /api/admin/pos').not.toBeNull();
    R.c4.postStatut = dejaCree.status();
    const corps = await dejaCree.json().catch(() => null);
    R.c4.commande = corps && corps.data ? { id: corps.data.id, serial: corps.data.order_serial_no, type: corps.data.order_type, delivery_charge: corps.data.delivery_charge, total: corps.data.total, payment_method: corps.data.payment_method, payment_status: corps.data.payment_status } : corps;
    await page.waitForTimeout(2500);
    await shot(page, '07-apres-paiement-livraison');
    R.reseau = reseau;
    note('commande créée : ' + JSON.stringify(R.c4.commande));
    expect(R.c4.postStatut).toBe(201);
  });


  // ── décode un flux ESC/POS en texte lisible + détecte les primitives utiles (QR = GS ( k) ──────────────────────────
  function decodeEscPos(buf) {
    const hasQr = buf.includes(Buffer.from([0x1d, 0x28, 0x6b]));
    const lignes = []; let i = 0; let cur = [];
    const flush = () => { if (cur.length) { lignes.push(require('iconv-lite').decode(Buffer.from(cur), 'cp858').replace(/\s+$/g, '')); cur = []; } };
    while (i < buf.length) {
      const b = buf[i];
      if (b === 0x1b) {
        const c = buf[i + 1];
        if (c === 0x40 || c === 0x32) i += 2;
        else if (c === 0x70) i += 5;
        else i += 3; // ESC ! E a t d J - M { R …  n
      } else if (b === 0x1d) {
        const c = buf[i + 1];
        if (c === 0x28) { i += 5 + buf[i + 3] + buf[i + 4] * 256; }
        else if (c === 0x56) i += (buf[i + 2] === 65 || buf[i + 2] === 66) ? 4 : 3;
        else i += 3;
      } else if (b === 0x0a) { flush(); i += 1; }
      else if (b >= 0x20) { cur.push(b); i += 1; }
      else i += 1;
    }
    flush();
    return { hasQr, lignes };
  }

  test('C5 ticket client + cuisine d\'une livraison (octets ESC/POS réels du serveur) : éléments présents / absents', async () => {
    const id = R.c4 && R.c4.commande && R.c4.commande.id;
    expect(id, 'commande créée par C1-C2-C4').toBeTruthy();
    const get = async (t) => { const r = await api.get(`/api/admin/pos/orders/${id}/escpos?ticket=${t}`); return { http: r.status(), j: await r.json().catch(() => null) }; };
    const cli = await get('client'); const cui = await get('kitchen');
    expect(cli.http).toBe(200);
    expect(cli.j && cli.j.escpos_b64, 'octets du ticket client').toBeTruthy();
    const dc = decodeEscPos(Buffer.from(cli.j.escpos_b64, 'base64'));
    const dk = decodeEscPos(Buffer.from(cui.j.escpos_b64, 'base64'));
    R.c5 = { commande: id, client: { http: cli.http, qr_gs_k: dc.hasQr, lignes: dc.lignes }, cuisine: { http: cui.http, qr_gs_k: dk.hasQr, lignes: dk.lignes } };
    const txt = dc.lignes.join('\n');
    const txtK = dk.lignes.join('\n');
    R.c5.presence = {
      numero: /A\d{4}|2310\d+/.test(txt), frais: /LIVRAISON/.test(txt), total: /MONTANT TOTAL/.test(txt), type_livraison: /LIVRAISON \*\*\*|\*\*\* LIVRAISON/i.test(txt),
      nom_client: /^Client :/m.test(txt), telephone: /^Tel :/m.test(txt), adresse_client: (txt.match(/Gruyelle/gi) || []).length > 1, // 1 occurrence = l'en-tête du restaurant
      depart_prevu: /départ|depart|créneau/i.test(txt),
      qr: dc.hasQr, avis: /avis|review|100 points/i.test(txt), mode_paiement: /ESP[ÈE]CES/i.test(txt), caissier_ligne: (txt.match(/Caissier : .*/) || [''])[0],
      cuisine_adresse: /Gruyelle/i.test(txtK), cuisine_nom: /^Client :/m.test(txtK), cuisine_type: /LIVRAISON/i.test(txtK),
    };
    note('C5 ' + JSON.stringify(R.c5.presence));
    expect(txt.length).toBeGreaterThan(200);
    expect(R.c5.presence.total, 'ticket décodé : le total est bien là (instrument prouvé)').toBe(true);
    expect(R.c5.presence.frais, 'ticket décodé : la ligne LIVRAISON est là').toBe(true);
    expect(dc.hasQr, 'QR (GS ( k) sur le ticket client').toBe(false);
  });

  test('C2b liste de suggestions : saisie ambiguë → plusieurs propositions à départager', async ({ page }) => {
    await ouvrirLivraison(page);
    await page.locator('label[for="delivery"]').click();
    await page.locator('#pos-delivery-phone').fill('0600000077');
    await page.locator('#pos-delivery-address').fill('rue de la gare');
    const li = page.locator('#orderdelivery ul li');
    await expect(li.first()).toBeVisible({ timeout: 15000 });
    await page.waitForTimeout(800);
    R.c2b = { suggestions: await li.allInnerTexts() };
    note('C2b ' + JSON.stringify(R.c2b));
    await shot(page, '08-suggestions-ambigues');
    expect(R.c2b.suggestions.length, 'plusieurs propositions pour une saisie ambiguë').toBeGreaterThan(1);
  });

  test('C3 devis serveur à plusieurs distances (rayon à vol d\'oiseau ? seuil 7 km ? Uber ?)', async () => {
    const cas = [
      '437 rue elie gruyelle 62110 henin-beaumont',
      '1 rue jean jaures 62590 oignies',
      '10 rue pasteur 62220 carvin',
      '10 rue de paris 62300 lens',
      '10 rue de la gare 59500 douai',
      '10 rue nationale 59800 lille',
    ];
    R.c3 = [];
    for (const q of cas) {
      const r = await api.post('/api/admin/pos-delivery/quote', { data: { q } });
      const j = await r.json().catch(() => null);
      R.c3.push({ q, http: r.status(), reponse: j });
      await new Promise((ok) => setTimeout(ok, 400));
    }
    note('C3 ' + JSON.stringify(R.c3.map((c) => ({ q: c.q, http: c.http, allowed: c.reponse && c.reponse.allowed, fee: c.reponse && (c.reponse.fee ?? c.reponse.delivery_charge), km: c.reponse && c.reponse.distance_km, cle: c.reponse && Object.keys(c.reponse) }))));
    expect(R.c3.every((c) => c.http === 200), 'les 6 devis répondent 200').toBe(true);
    expect(R.c3[0].reponse.allowed, 'adresse du restaurant : livrable').toBe(true);
  });

  test('C4b « Commande téléphone » en mode Livraison : refus affiché (paiement à la porte non enregistrable)', async ({ page }) => {
    await ouvrirLivraison(page);
    await ajouterEauPlate(page);
    await page.locator('label[for="delivery"]').click();
    await page.locator('#pos-delivery-phone').fill('0600000078');
    await page.locator('#pos-delivery-name').fill('E2E-ETAT-C Tel');
    await page.locator('#pos-delivery-address').fill('437 rue elie gruyelle henin');
    const li = page.locator('#orderdelivery ul li');
    await expect(li.first()).toBeVisible({ timeout: 15000 });
    await li.first().dispatchEvent('mousedown');
    await expect(page.locator('[data-testid="pos-delivery-quote"]')).toBeVisible({ timeout: 15000 });
    const posts = [];
    page.on('request', (rq) => { if (rq.method() === 'POST' && new URL(rq.url()).pathname === '/api/admin/pos') posts.push(rq.url()); });
    const bouton = page.locator('button:visible', { hasText: /Commande téléphone/ }).first();
    R.c4b = { bouton_visible_en_livraison: await bouton.isVisible() };
    await bouton.click();
    await page.waitForTimeout(1500);
    R.c4b.alertes = await page.evaluate(() => [...document.querySelectorAll('[role="alert"], .Vue-Toastification__toast, .toast, .alert')].filter((e) => e.getBoundingClientRect().width > 0).map((e) => e.innerText.trim().slice(0, 300)));
    R.c4b.posts = posts;
    await shot(page, '09-commande-telephone-refusee-en-livraison');
    note('C4b ' + JSON.stringify(R.c4b));
    expect(R.c4b.bouton_visible_en_livraison, 'le bouton est proposé en livraison').toBe(true);
    expect(R.c4b.alertes.join(' '), 'refus explicite affiché').toMatch(/impossible en livraison/i);
    expect(posts.length, 'aucune commande créée').toBe(0);
  });

  test('C6 planche « Tournées » : tient toujours ; Uber indisponible : bouton grisé + raison, devis refusé proprement', async ({ page }) => {
    await ouvrirLivraison(page);
    const rep = page.waitForResponse((r) => /pos-delivery\/board/.test(r.url()), { timeout: 20000 }).catch(() => null);
    await page.locator('[data-testid="pos-delivery-board-open"]').click();
    const rb = await rep;
    await expect(page.locator('[data-testid="pos-delivery-board"]')).toBeVisible({ timeout: 15000 });
    await page.waitForTimeout(1200);
    const jb = rb ? await rb.json().catch(() => null) : null;
    R.c6 = { board_http: rb && rb.status(), uber_enabled: jb && jb.uber_enabled, nb_departs: jb && jb.data ? jb.data.length : null, nb_arrets: jb && jb.data ? jb.data.reduce((n, w) => n + w.stops.length, 0) : null, couriers: jb && jb.couriers ? jb.couriers.length : null };
    await shot(page, '10-planche-tournees');
    const stopId = jb && jb.data && jb.data.flatMap((w) => w.stops).find((st) => st.order_id === (R.c4 && R.c4.commande && R.c4.commande.id));
    R.c6.arret_test = stopId || null;
    R.c6.boutons_uber = await page.locator('[data-testid="pos-delivery-use-uber"]').evaluateAll((els) => els.map((e) => ({ disabled: e.disabled, title: e.title })));
    R.c6.raisons = await page.locator('[data-testid="pos-delivery-uber-reason"]').allInnerTexts();
    note('C6 ' + JSON.stringify(R.c6));
    expect(rb && rb.status()).toBe(200);
    expect(R.c6.nb_arrets, 'la planche liste des arrêts').toBeGreaterThan(0);
    // devis Uber demandé directement à l'API pour un arrêt payé : « pas activé », sans panne
    if (stopId) {
      const rq = await api.post('/api/admin/pos-delivery/uber/quote', { data: { stop_id: stopId.id } });
      R.c6.devis_uber = { http: rq.status(), corps: await rq.json().catch(() => null) };
      note('C6 devis Uber direct : ' + JSON.stringify(R.c6.devis_uber));
      expect(R.c6.devis_uber.http).toBeLessThan(500);
    }
  });


  test('C3b adresse hors zone (Lille, ~25 km) : ce que la caissière voit', async ({ page }) => {
    await ouvrirLivraison(page);
    await ajouterEauPlate(page);
    await page.locator('label[for="delivery"]').click();
    await page.locator('#pos-delivery-phone').fill('0600000079');
    await page.locator('#pos-delivery-address').fill('10 rue nationale 59800 lille');
    const li = page.locator('#orderdelivery ul li');
    await expect(li.first()).toBeVisible({ timeout: 15000 });
    await li.first().dispatchEvent('mousedown');
    await page.waitForTimeout(2500);
    const box = page.locator('[data-testid="pos-delivery-quote"]');
    const err = page.locator('#orderdelivery [role="alert"], #orderdelivery .text-red-700');
    R.c3b = { quote: (await box.innerText().catch(() => '')).replace(/\s+/g, ' ').trim(), erreur: (await err.allInnerTexts().catch(() => [])).map((t) => t.replace(/\s+/g, ' ').trim()), bouton_commande: (await page.locator('button:visible', { hasText: /Commande\s*·/ }).first().isDisabled().catch(() => null)) };
    await shot(page, '11-hors-zone-lille');
    note('C3b ' + JSON.stringify(R.c3b));
    expect((R.c3b.quote + ' ' + R.c3b.erreur.join(' ')).length, 'un message est affiché').toBeGreaterThan(10);
  });


  test('C3c adresse hors zone : le bouton « Commande » reste actif — que se passe-t-il au clic ?', async ({ page }) => {
    await ouvrirLivraison(page);
    await ajouterEauPlate(page);
    await page.locator('label[for="delivery"]').click();
    await page.locator('#pos-delivery-phone').fill('0600000080');
    await page.locator('#pos-delivery-address').fill('10 rue nationale 59800 lille');
    const li = page.locator('#orderdelivery ul li');
    await expect(li.first()).toBeVisible({ timeout: 15000 });
    await li.first().dispatchEvent('mousedown');
    await expect(page.locator('[data-testid="pos-delivery-quote"]')).toBeVisible({ timeout: 15000 });
    await page.waitForTimeout(1500);
    const posts = [];
    page.on('response', (r) => { if (r.request().method() === 'POST' && /\/api\/admin\/pos(\/quote)?$/.test(new URL(r.url()).pathname)) posts.push(`${new URL(r.url()).pathname} ${r.status()}`); });
    await page.locator('button:visible', { hasText: /Commande\s*·/ }).first().click();
    await page.waitForTimeout(2500);
    R.c3c = {
      modale_paiement_ouverte: await page.locator('button:visible', { hasText: /Confirmer/ }).count(),
      alertes: await page.evaluate(() => [...document.querySelectorAll('[role="alert"], .Vue-Toastification__toast, .toast')].filter((e) => e.getBoundingClientRect().width > 0).map((e) => e.innerText.trim().slice(0, 250))),
      appels: posts,
    };
    await shot(page, '12-hors-zone-clic-commande');
    note('C3c ' + JSON.stringify(R.c3c));
    expect(R.c3c.alertes.length + R.c3c.modale_paiement_ouverte, 'le clic produit un message OU la modale (instrument)').toBeGreaterThan(0);
  });

});
