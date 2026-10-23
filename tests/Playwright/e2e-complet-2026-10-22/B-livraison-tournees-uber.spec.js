/**
 * [E2E-COMPLET 2026-10-22 · VAGUE B · tour 1] Livraison côté caisse :
 *   L1  bouton « Livraison active — suspendre / remettre » : états ACTIF et SUSPENDU, relus côté serveur caisse
 *       (GET /api/admin/pos-delivery/status) ET côté site (GET /api/frontend/delivery/status) : une seule vérité ?
 *   L2  planche « Tournées » RÉELLE (aucune route simulée) avec de VRAIES livraisons créées par l'API :
 *       une payée (paid_online) et une « à encaisser à la porte » (cash) → bouton Uber grisé + raison.
 * Uber est volontairement ÉTEINT (UBER_DIRECT_ENABLED=false) : aucun appel sortant. Tour 1 = capture, aucune correction.
 * Données préfixées « E2E-CAISSE- ». L'état de la livraison est relu au départ et RESTAURÉ à la fin.
 *
 *   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
 *     npx playwright test tests/Playwright/e2e-complet-2026-10-22/B-livraison-tournees-uber.spec.js --workers=1 --retries=0 --reporter=line
 */
const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { loginAsPosOperator } = require('../../e2e/helpers/login');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const ROOT = path.resolve(__dirname, '../../..');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const SHOTS = path.join(ROOT, 'tests/e2e/__screenshots__/e2e-complet-B');
fs.mkdirSync(SHOTS, { recursive: true });
const RES = path.join(SHOTS, '_resultats-livraison.json');
const R = { notes: [], toggle: {}, planche: {}, layout: [] };
const flush = () => fs.writeFileSync(RES, JSON.stringify(R, null, 2));
const note = (s) => { R.notes.push(s); console.log('[B-LIV] ' + s); flush(); };
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = env('MIX_API_KEY') || env('API_KEY');
const pub = { 'X-API-KEY': API_KEY, Accept: 'application/json', 'Content-Type': 'application/json' };
const uuid = () => crypto.randomUUID();
const dbPass = () => env('DB_PASSWORD');
function sql(q) { return execFileSync('mysql', ['-uroot', '-h', 'localhost', 'foodking_e2e', '-N', '-B', '-e', q], { env: { ...process.env, MYSQL_PWD: dbPass() }, encoding: 'utf8' }).trim().split('\n').filter(Boolean).map((l) => l.split('\t')); }

let api; let tok;
async function apiLogin() {
  clearFoodKingRateLimits();
  const l = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, 'X-Device-Id': 'e2e-caisse-b-liv-' + Date.now() } });
  const r = await l.post('/api/auth/login', { data: { email: 'pos@lecayenne.fr', password: '123456' } });
  expect(r.status(), 'login API pos@').toBe(201);
  tok = (await r.json()).token;
  await l.dispose();
  return request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, Authorization: `Bearer ${tok}` } });
}
const statutCaisse = async () => { const r = await api.get('/api/admin/pos-delivery/status'); return { http: r.status(), body: await r.json().catch(() => null) }; };
async function statutSite() {
  const c = await request.newContext({ baseURL: BASE, extraHTTPHeaders: pub });
  const r = await c.get('/api/frontend/delivery/status');
  const out = { http: r.status(), body: await r.json().catch(() => null) };
  await c.dispose();
  return out;
}
const settle = async (page, ms = 1200) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(ms); };
const toggleLoc = (page) => page.locator('[data-testid="pos-delivery-toggle"]');
const etat = async (page) => (/suspendue/.test(await toggleLoc(page).innerText()) ? 'suspendue' : 'active');
async function basculer(page) {
  const rep = page.waitForResponse((r) => r.request().method() === 'POST' && /pos-delivery\/toggle/.test(r.url()), { timeout: 10_000 }).catch(() => null);
  await toggleLoc(page).click(); await page.waitForTimeout(300); await toggleLoc(page).click();
  const r = await rep;
  await page.waitForTimeout(1500);
  return r ? { http: r.status(), body: await r.json().catch(() => null) } : null;
}
async function lectureEcran(page) {
  return page.evaluate(() => ({
    alertes: [...document.querySelectorAll('[role="alert"], .Vue-Toastification__toast, .alert-danger')].filter((e) => e.getBoundingClientRect().width > 0).map((e) => e.innerText.trim().slice(0, 200)),
    brut: (() => { const rx = /^(label|button|menu|pos|kiosk|message|validation|error)\.[a-z_][a-z0-9_.]{2,}$/i; const o = []; const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) { const t = n.textContent.trim(); if (t && rx.test(t)) o.push(t); } return o.slice(0, 10); })(),
  }));
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(300_000);
test.use({ actionTimeout: 20_000, viewport: { width: 1440, height: 900 } });

test.describe('B · livraison caisse', () => {
  test.beforeAll(async () => {
    api = await apiLogin();
    R.initial = { caisse: await statutCaisse(), site: await statutSite() };
    note('état initial : ' + JSON.stringify(R.initial));
  });
  test.afterAll(async () => {
    try {
      const voulu = R.initial.caisse.body && R.initial.caisse.body.enabled;
      const cur = (await statutCaisse()).body.enabled;
      if (voulu !== undefined && cur !== voulu) await api.post('/api/admin/pos-delivery/toggle', { data: { enabled: voulu } });
      R.final = { caisse: await statutCaisse(), site: await statutSite() };
      note('état final : ' + JSON.stringify(R.final));
    } catch (e) { note('restauration : ' + e.message); }
    flush();
  });

  test('B-L1 bouton « Livraison active » : actif ↔ suspendu, caisse = serveur = site', async ({ page }) => {
    const { snap } = attachMegaAuditRecorder(page, SHOTS);
    const reseau = [];
    page.on('response', (r) => { if (/pos-delivery|delivery\/status/.test(r.url())) reseau.push(`${r.request().method()} ${new URL(r.url()).pathname} ${r.status()}`); });
    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    await expect(toggleLoc(page)).toBeVisible({ timeout: 20_000 });
    // on part de l'état ACTIF
    if ((await etat(page)) === 'suspendue') { await basculer(page); note('livraison trouvée suspendue : remise avant le test'); }
    expect(await etat(page)).toBe('active');
    R.toggle.actif = { bouton: (await toggleLoc(page).innerText()).trim(), caisse: await statutCaisse(), site: await statutSite(), option_livraison: await page.locator('label[for="delivery"]').count(), bouton_tournees: await page.locator('[data-testid="pos-delivery-board-open"]').count() };
    await snap('20-livraison-active');
    note('ACTIF : ' + JSON.stringify(R.toggle.actif));

    // un appui = armé (rien au serveur)
    await toggleLoc(page).click();
    R.toggle.arme = (await toggleLoc(page).innerText()).trim();
    await snap('21-livraison-armee-un-appui');
    await page.waitForTimeout(4600);
    R.toggle.desarme_apres_4s = !/Confirmer/.test(await toggleLoc(page).innerText());

    // suspendre (2 appuis)
    const rs = await basculer(page);
    R.toggle.post_suspendre = rs;
    R.toggle.suspendu = { bouton: (await toggleLoc(page).innerText()).trim(), caisse: await statutCaisse(), site: await statutSite(), option_livraison: await page.locator('label[for="delivery"]').count(), bouton_tournees: await page.locator('[data-testid="pos-delivery-board-open"]').count(), ecran: await lectureEcran(page) };
    await snap('22-livraison-suspendue');
    note('SUSPENDU : ' + JSON.stringify(R.toggle.suspendu));

    // le site, lui, doit refuser un devis de livraison quand c'est suspendu
    const c = await request.newContext({ baseURL: BASE, extraHTTPHeaders: pub });
    const dq = await c.post('/api/frontend/delivery/quote', { data: { q: '437 rue elie gruyelle 62110 henin-beaumont' } });
    R.toggle.site_devis_suspendu = { http: dq.status(), corps: (await dq.text()).slice(0, 300) };
    await c.dispose();
    note('devis site pendant suspension : ' + JSON.stringify(R.toggle.site_devis_suspendu));

    // remettre
    const rr = await basculer(page);
    R.toggle.post_remettre = rr;
    R.toggle.remis = { bouton: (await toggleLoc(page).innerText()).trim(), caisse: await statutCaisse(), site: await statutSite(), option_livraison: await page.locator('label[for="delivery"]').count(), bouton_tournees: await page.locator('[data-testid="pos-delivery-board-open"]').count() };
    await snap('23-livraison-remise');
    note('REMIS : ' + JSON.stringify(R.toggle.remis));
    R.toggle.reseau = reseau;
    // audit
    R.toggle.audit = sql("select id,action,user_id,created_at from audit_logs where action in ('delivery.suspended','delivery.resumed') order by id desc limit 4");
    flush();
    expect(R.toggle.actif.caisse.body.enabled).toBe(true);
    expect(R.toggle.suspendu.caisse.body.enabled, 'serveur caisse : suspendu').toBe(false);
    expect(R.toggle.remis.caisse.body.enabled, 'serveur caisse : remis').toBe(true);
  });

  test('B-L2 vraies livraisons par l\'API (payée + à encaisser) puis planche « Tournées » réelle', async ({ page }) => {
    test.setTimeout(420_000);
    const { snap } = attachMegaAuditRecorder(page, SHOTS);
    // livraison active exigée
    if (!(await statutCaisse()).body.enabled) await api.post('/api/admin/pos-delivery/toggle', { data: { enabled: true } });
    expect((await statutCaisse()).body.enabled).toBe(true);

    // client + adresse (API de la caisse)
    const phone = '06' + String(Date.now()).slice(-8);
    const cr = await api.post('/api/admin/pos-delivery/customer', { data: { phone, name: 'E2E-CAISSE-Client ' + phone.slice(-4) } });
    const cj = await cr.json();
    expect(cr.status(), JSON.stringify(cj).slice(0, 300)).toBe(200);
    const customerId = cj.data.id || cj.data.customer_id || (cj.data.customer && cj.data.customer.id);
    R.planche.client = { phone, customerId, http: cr.status(), cles: Object.keys(cj.data || {}) };
    const ar = await api.post('/api/admin/pos-delivery/address', { data: { customer_id: customerId, address: '437 rue elie gruyelle 62110 henin-beaumont', apartment: 'E2E-CAISSE porte verte' } });
    const aj = await ar.json();
    expect(ar.status(), JSON.stringify(aj).slice(0, 400)).toBe(200);
    const addressId = aj.data.id || aj.data.address_id;
    const fee = Number(aj.data.fee ?? aj.data.delivery_charge ?? aj.data.price ?? 3.9);
    R.planche.adresse = { id: addressId, http: ar.status(), data: aj.data };
    note('client ' + customerId + ' adresse ' + addressId + ' frais ' + fee);

    const items = JSON.stringify([{ item_id: 58, item_price: 1, branch_id: 1, instruction: '', quantity: 1, discount: 0, total_price: 1, item_variation_total: 0, item_extra_total: 0, item_variations: [], item_extras: [], pos_line_addons: [] }]);
    const creer = async (label, extra) => {
      const tokenCmd = 'E2E-CAISSE-' + label + '-' + Date.now();
      const base = { branch_id: 1, subtotal: 1, token: tokenCmd, customer_id: customerId, discount: 0, delivery_charge: fee, delivery_distance_km: 0, delivery_time: null, total: Math.round((1 + fee) * 100) / 100, order_type: 5, is_advance_order: 10, pos_payment_method: 1, pos_payment_note: '', pos_customer_name: null, pos_customer_phone: null, phone_order: false, scheduled_at: null, source: 15, address_id: addressId, coupon_id: null, items, dining_table_id: null, pos_received_amount: 5, loyalty_customer_code: null, loyalty_redeem_points: null, discount_reason: null, ...extra };
      const q = await api.post('/api/admin/pos/quote', { data: base });
      const qj = await q.json(); const qd = qj.data || qj;
      expect(q.status(), 'devis : ' + JSON.stringify(qj).slice(0, 300)).toBe(200);
      const body = { ...base, quote_token: qd.quote_token, quote_signature: qd.signature, idempotency_key: `${Date.now()}_${Math.random().toString(36).slice(2, 10)}_1` };
      delete body.subtotal; delete body.total;
      const r = await api.post('/api/admin/pos', { data: body, headers: { 'X-Idempotency-Key': uuid() } });
      const rj = await r.json().catch(() => ({}));
      note(`${label} : POST /admin/pos → ${r.status()} ${JSON.stringify(rj).slice(0, 200)}`);
      expect(r.status(), JSON.stringify(rj).slice(0, 400)).toBe(201);
      return rj.data;
    };
    const payee = await creer('Payee', {});
    const aEncaisser = await creer('AEncaisser', { defer_to_counter: 1, pos_payment_method: 6, pos_received_amount: undefined });
    const ids = [payee.id, aEncaisser.id];
    R.planche.commandes = ids.map((id) => ({
      id,
      order: sql(`select id,order_serial_no,total,subtotal,delivery_charge,payment_status,status,fiscal_sequence_no,pos_payment_method,source_surface from orders where id=${id}`)[0],
      arret: sql(`select id,wave_at,sequence,collect_method,amount_due_cents,left_at,delivery_boy_id from delivery_stops where order_id=${id}`)[0],
    }));
    note('commandes : ' + JSON.stringify(R.planche.commandes));

    // la planche, SANS aucune simulation
    const net = []; page.on('response', async (r) => { if (/pos-delivery\/(board|uber)/.test(r.url())) { let b = null; try { b = await r.text(); } catch (_) {} net.push({ m: r.request().method(), u: new URL(r.url()).pathname, s: r.status(), b }); } });
    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    const ov = page.locator('[data-testid="cash-session-overlay"]');
    if (await ov.isVisible().catch(() => false)) { await page.locator('[data-testid="cash-session-close"]').click(); await page.waitForTimeout(400); }
    await page.locator('[data-testid="pos-delivery-board-open"]').click({ timeout: 20_000 });
    const planche = page.locator('[data-testid="pos-delivery-board"]');
    await expect(planche).toBeVisible({ timeout: 20_000 });
    await settle(page, 1500);
    R.planche.texte = (await planche.innerText()).replace(/\s+/g, ' ').slice(0, 1800);
    R.planche.nb_boutons_uber = await planche.locator('[data-testid="pos-delivery-use-uber"]').count();
    await snap('24-planche-tournees-reelle');
    const board = net.filter((n) => /pos-delivery\/board$/.test(n.u)).pop();
    R.planche.board_http = board && board.s;
    let bj = null; try { bj = JSON.parse(board.b); } catch (_) {}
    R.planche.uber_enabled_serveur = bj && bj.uber_enabled;
    note('board : http ' + (board && board.s) + ' uber_enabled=' + (bj && bj.uber_enabled) + ' vagues=' + (bj && bj.data.length));
    // tournées « à venir » dont l'heure est déjà passée (affichage de la planche) — vérité base
    R.planche.vagues_passees_non_parties = sql("select wave_at, count(*) n from delivery_stops where wave_at < now() and left_at is null and delivered_at is null group by wave_at order by wave_at");
    R.planche.heure_serveur = sql('select now()')[0][0];
    R.planche.vagues_json_passees_non_parties = bj ? bj.data.filter((w) => !w.left && new Date(w.wave_at.replace(' ', 'T')) < new Date(R.planche.heure_serveur.replace(' ', 'T'))).map((w) => `${w.day_label} ${w.time} left=${w.left} stops=${w.stops.length}`) : null;
    R.planche.entetes_vagues = await planche.locator('header').allInnerTexts().then((a) => a.map((t) => t.replace(/\s+/g, ' ').trim()).slice(0, 8));

    // nos deux arrêts dans la planche (par numéro de commande)
    const lignes = [];
    for (const c of R.planche.commandes) {
      const serial = c.order[1];
      const carte = planche.locator(`xpath=.//*[contains(normalize-space(.), "${serial}")][.//*[@data-testid="pos-delivery-use-uber"]]`).last();
      const present = (await carte.count()) > 0;
      let btn = null, raison = null, desactive = null;
      if (present) {
        const b = carte.locator('[data-testid="pos-delivery-use-uber"]').first();
        desactive = await b.isDisabled();
        btn = (await b.innerText()).trim();
        raison = (await carte.locator('[data-testid="pos-delivery-uber-reason"]').first().innerText().catch(() => '')).trim();
      }
      // vérité serveur pour cet arrêt
      let srv = null;
      if (bj) for (const w of bj.data) for (const s of w.stops) if (String(s.order_id) === String(c.id)) srv = { collect_method: s.collect_method, amount_due: s.amount_due, dispatch: s.dispatch, uber_blocked: s.uber_blocked, wave: w.wave_at, day: w.day_label, time: w.time };
      lignes.push({ commande: serial, id: c.id, present_dans_la_planche: present, bouton: btn, desactive, raison_affichee: raison, serveur: srv });
    }
    R.planche.arrets = lignes;
    note('arrêts : ' + JSON.stringify(lignes));
    // clic sur « Confier à Uber » grisé : aucun appel /uber/* ne doit partir
    const avantUber = net.filter((n) => /uber/.test(n.u)).length;
    const b0 = planche.locator('[data-testid="pos-delivery-use-uber"]').first();
    if (await b0.count()) await b0.click({ force: true, timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1200);
    R.planche.appels_uber_apres_clic_grise = net.filter((n) => /uber/.test(n.u)).length - avantUber;
    // l'API refuse proprement, sans appeler Uber
    const stopId = (await Promise.all(R.planche.commandes.map((c) => c.arret && c.arret[0]))).filter(Boolean);
    R.planche.api_uber_quote = [];
    for (const sid of stopId) {
      const r = await api.post('/api/admin/pos-delivery/uber/quote', { data: { stop_id: Number(sid) } });
      R.planche.api_uber_quote.push({ stop: sid, http: r.status(), corps: (await r.text()).slice(0, 300) });
    }
    note('API uber/quote : ' + JSON.stringify(R.planche.api_uber_quote));
    R.planche.ecran = await lectureEcran(page);
    flush();
    // vérités
    expect(R.planche.arrets.every((a) => a.present_dans_la_planche), 'les 2 arrêts créés sont dans la planche').toBe(true);
    expect(R.planche.arrets.every((a) => a.desactive === true), 'bouton Uber grisé partout (Uber éteint)').toBe(true);
    expect(R.planche.arrets[1].raison_affichee, 'raison affichée').toMatch(/Uber Direct n.est pas activé/);
    expect(R.planche.appels_uber_apres_clic_grise).toBe(0);

    // tailles d'écran : boutons de la planche touchables
    for (const [w, h] of [[1280, 720], [1440, 900], [1920, 1080]]) {
      await page.setViewportSize({ width: w, height: h });
      await page.waitForTimeout(700);
      const m = await page.evaluate(() => {
        const out = [];
        const card = document.querySelector('[data-testid="pos-delivery-board"]');
        const de = document.documentElement;
        for (const el of card ? card.querySelectorAll('button, a') : []) {
          el.scrollIntoView({ block: 'nearest' });
          const r = el.getBoundingClientRect(); if (r.width === 0 || r.height === 0) continue;
          const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
          const top = document.elementFromPoint(cx, cy);
          const ok = !!top && (top === el || el.contains(top) || top.contains(el));
          out.push({ t: (el.innerText || el.getAttribute('aria-label') || '').trim().slice(0, 30), ok, dis: el.disabled === true });
        }
        const boite = card ? card.getBoundingClientRect() : null;
        return { n: out.length, recouverts: out.filter((o) => !o.ok).map((o) => o.t), debordeX: de.scrollWidth - de.clientWidth, carteDansEcran: boite ? boite.right <= innerWidth + 1 && boite.left >= -1 : null };
      });
      R.layout.push({ w, h, ...m });
      note(`planche ${w}x${h} : ${JSON.stringify(m)}`);
      if (w === 1280 || w === 1920) await snap(`25-planche-${w}x${h}`);
    }
    flush();
  });
});
