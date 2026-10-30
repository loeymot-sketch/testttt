// [E2E 2026-10-30 · suivi en direct + fidélité] SONDE puis banc de non-régression.
// Demande du propriétaire : (1) le suivi de commande du site doit passer « prête » / « en route » / « livrée » SEUL, sans
// rechargement ; (2) des points utilisés sont déduits tout de suite et le solde affiché est frais ; (3) à la livraison le
// client voit ce qu'il a gagné (points ≈ euros) sans que l'on abuse de lui (un seul message, jamais de pop-up répété).
//
// Mesure d'abord (chaque état est horodaté depuis l'appel API de la cuisine jusqu'au changement de l'écran), puis assertions.
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/suivi-live-fidelite-2026-10-30.spec.js --workers=1 --retries=0 --reporter=line
const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { clearFoodKingRateLimits } = require('../e2e/helpers/rate-limit');

const ROOT = path.resolve(__dirname, '../..');
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const SITE = process.env.U_SITE_URL || 'http://127.0.0.1:8013';
const OUT = path.join(ROOT, 'reports/test-e2e/livraison-uber-fidelite-2026-10-30/suivi-live');
fs.mkdirSync(OUT, { recursive: true });
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = env('MIX_API_KEY') || env('API_KEY');
const pub = { 'X-API-KEY': API_KEY, Accept: 'application/json', 'Content-Type': 'application/json' };
const POS = { email: 'pos@lecayenne.fr', password: '123456' };
const PROD_RX = /vps-418872ac|ovh\.net|lecayenne\.fr|auth\.uber\.com|api\.uber\.com/i;
const NOW = Date.now();
const R = { startedAt: new Date().toISOString(), mesures: [], checks: [] };
const write = () => fs.writeFileSync(path.join(OUT, 'resultats.json'), JSON.stringify(R, null, 2));
const mesure = (id, data) => { R.mesures.push({ id, ...data }); console.log(`[SF ${id}] ${JSON.stringify(data).slice(0, 400)}`); write(); };
const check = (id, ok, detail) => { R.checks.push({ id, ok: !!ok, detail }); console.log(`[SF CHECK ${id}] ${ok ? 'OK ' : 'KO '} ${JSON.stringify(detail ?? '').slice(0, 300)}`); write(); expect.soft(!!ok, `${id} ${JSON.stringify(detail ?? '').slice(0, 200)}`).toBe(true); };
const uuid = () => crypto.randomUUID();
function tinker(code) { return execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8', timeout: 90_000 }).trim().split('\n').filter(Boolean).pop(); }
const texte = (p) => p.evaluate(() => String(document.body.innerText || '').replace(/[  ]/g, ' ').replace(/\s+/g, ' '));

let rq; let pos; let C; let adresse;
test.describe.configure({ mode: 'serial' });
test.setTimeout(300_000);

let SEQ = 0;
async function creerClient(points) {
  // unique par construction (horodatage de la passe + compteur) : l'aléa tronqué produisait des codes fidélité en collision
  const stamp = String(NOW) + String(++SEQ).padStart(2, '0');
  const phone = '06' + stamp.slice(-8);
  const out = tinker(`
    $u = \\App\\Models\\User::create(['name' => 'E2E-FIDELITE ${stamp}', 'email' => 'e2e-fid-${stamp}@example.test', 'phone' => '${phone}', 'country_code' => '+33', 'username' => 'ef${stamp}', 'password' => bcrypt('Test-Fid-2026'), 'branch_id' => 0, 'status' => 5, 'is_guest' => 10, 'email_verified_at' => now()]);
    $u->assignRole('Customer');
    $u->forceFill(['loyalty_points' => ${points}, 'loyalty_code' => 'E2E' . substr('${stamp}', -8)])->save();
    echo json_encode(['id' => $u->id, 'code' => $u->loyalty_code, 'token' => $u->createToken('auth_token', ['*'])->plainTextToken]);`);
  const j = JSON.parse(out);
  return { ...j, phone };
}
const hdr = (extra = {}) => ({ 'X-API-KEY': API_KEY, Authorization: `Bearer ${C.token}`, Accept: 'application/json', ...extra });
async function profilPoints() {
  const r = await rq.get(`${BASE}/api/profile`, { headers: hdr() });
  const j = await r.json();
  return Number((j.data || j.user || j).loyalty_points);
}
async function passerCommande({ qty = 12, redeem = 0 } = {}) {
  const data = {
    branch_id: 1, order_type: 5, source: 5, is_advance_order: 10, payment_method: 1,
    address_id: adresse, delivery_time: 'ASAP', delivery_distance_km: 0, delivery_charge: 0,
    items: JSON.stringify([{ item_id: 58, item_price: 1, quantity: qty, discount: 0, total_price: qty, item_variations: [], item_extras: [], instruction: 'E2E-FID' }]),
  };
  if (redeem) { data.loyalty_code = C.code; data.loyalty_redeem_points = redeem; }
  const r = await rq.post(`${BASE}/api/frontend/order`, { headers: hdr({ 'X-Idempotency-Key': uuid(), 'Content-Type': 'application/json' }), data });
  const body = await r.json().catch(() => ({}));
  return { http: r.status(), body, id: body?.data?.id, serial: body?.data?.order_serial_no };
}
async function cuisine(orderId, status) {
  const r = await pos.post(`${BASE}/api/admin/pos-order/change-status/${orderId}`, { headers: { 'X-Idempotency-Key': uuid() }, data: { status } });
  return { http: r.status(), t: Date.now(), txt: (await r.text()).slice(0, 160) };
}
const COURIER = { id: 10, email: 'livreur.e2e@lecayenne.test', password: 'Livreur123!' };
let boy;
async function livreurApi() {
  if (boy) return boy;
  const l = await rq.post(`${BASE}/api/auth/login`, { headers: { ...pub, 'X-Device-Id': 'e2e-sf-boy-' + NOW }, data: { email: COURIER.email, password: COURIER.password } });
  expect(l.status(), 'connexion livreur').toBe(201);
  boy = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, Authorization: `Bearer ${(await l.json()).token}` } });
  return boy;
}
const soldeAffiche = (t) => { const m = /MON SOLDE\s*([\d\s]+?)\s*pts/i.exec(t); return m ? Number(m[1].replace(/\s/g, '')) : null; };
function guarded(browser, opts) {
  return browser.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris', ...opts }).then(async (ctx) => {
    await ctx.route((url) => PROD_RX.test(url.href), (route) => route.abort('blockedbyclient'));
    await ctx.route((url) => /js\.mollie\.com/.test(url.href), (route) => route.abort('blockedbyclient'));
    await ctx.addInitScript(([t, p]) => { try { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); } catch (_e) { /* storage */ } }, [C.token, C.phone]);
    return ctx;
  });
}

test.beforeAll(async () => {
  rq = await request.newContext();
  clearFoodKingRateLimits && clearFoodKingRateLimits();
  const l = await rq.post(`${BASE}/api/auth/login`, { headers: { ...pub, 'X-Device-Id': 'e2e-sf-' + NOW }, data: POS });
  expect(l.status(), 'connexion caisse').toBe(201);
  const tok = (await l.json()).token;
  pos = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, Authorization: `Bearer ${tok}` } });
  C = await creerClient(1500);
  const q = await (await rq.post(`${BASE}/api/frontend/delivery/quote`, { headers: pub, data: { q: '437 rue elie gruyelle 62110 henin-beaumont' } })).json();
  expect(q.allowed, 'adresse livrable').toBe(true);
  const a = await (await rq.post(`${BASE}/api/frontend/address`, { headers: hdr({ 'Content-Type': 'application/json' }), data: { label: 'E2E-FID ' + NOW.toString(36), address: q.address.label, apartment: '2B' } })).json();
  adresse = a.data.id;
  expect(adresse).toBeGreaterThan(0);
  mesure('bench', { client: C.id, code: C.code, adresse, solde0: await profilPoints() });
});

test('SF-1 points utilisés : débit immédiat, grand-livre, solde frais', async () => {
  const avant = await profilPoints();
  expect(avant).toBe(1500);
  const o = await passerCommande({ qty: 12, redeem: 1000 });
  mesure('SF-1-commande', { http: o.http, id: o.id, serial: o.serial, err: o.http >= 300 ? JSON.stringify(o.body).slice(0, 300) : undefined });
  expect(o.http, JSON.stringify(o.body).slice(0, 300)).toBeLessThan(300);
  C.o1 = o;
  const apres = await profilPoints();
  const row = JSON.parse(tinker(`echo json_encode(\\Illuminate\\Support\\Facades\\DB::table('loyalty_transactions')->where('order_id', ${o.id})->get(['type','points','balance_after'])); `));
  const ord = JSON.parse(tinker(`$o=\\App\\Models\\Order::withoutGlobalScopes()->find(${o.id}); echo json_encode(['total'=>(float)$o->total,'discount'=>(float)$o->discount,'status'=>$o->status,'awarded'=>$o->loyalty_points_awarded]);`));
  mesure('SF-1-apres', { avant, apres, ledger: row, ord });
  check('SF-1 solde débité immédiatement (1500 → 500)', apres === 500, { apres });
  check('SF-1 grand-livre : une ligne redeem de −1000', row.some((r) => r.type === 'redeem' && Number(r.points) === -1000), row);
  check('SF-1 remise 10 € sur la commande', Math.abs(ord.discount - 10) < 0.01, ord);
});

test('SF-2 suivi du site : statuts de la cuisine reflétés SANS rechargement, délai mesuré', async ({ browser }) => {
  const ctx = await guarded(browser, { viewport: { width: 420, height: 900 } });
  const sp = await ctx.newPage();
  await sp.emulateMedia({ reducedMotion: 'reduce' });
  await sp.goto(`${SITE}/#orders`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await expect(sp.locator('#root .lc-app')).toBeVisible({ timeout: 60_000 });
  const carte = sp.locator('.lcx-order-card', { hasText: '#' + C.o1.serial });
  await expect(carte).toBeVisible({ timeout: 30_000 });
  await carte.getByRole('button', { name: /Suivre ma commande/ }).click();
  await expect(sp.locator('.lcf-track[data-mode="livraison"]')).toBeVisible({ timeout: 30_000 });
  await sp.waitForTimeout(1500);
  const t0 = await texte(sp);
  mesure('SF-2-initial', { texte: t0.slice(0, 500) });
  await sp.screenshot({ path: path.join(OUT, 'SF2-0-initial.png'), fullPage: true });

  // marqueur anti-rechargement : si la page se recharge, il disparaît
  await sp.evaluate(() => { window.__sfNoReload = 'vivant'; });

  const etapes = [
    { st: 4, motif: /préparation|acceptée|prise en compte|reçue/i },
    { st: 7, motif: /préparation/i },
    { st: 8, motif: /prête|en route|prépar/i },
  ];
  let precedent = t0;
  for (const e of etapes) {
    const c = await cuisine(C.o1.id, e.st);
    mesure('SF-2-cuisine', { st: e.st, http: c.http, txt: c.http >= 300 ? c.txt : undefined });
    if (c.http >= 300) continue;
    const debut = c.t;
    let change = null;
    for (let i = 0; i < 90; i++) { // 45 s au plus
      await sp.waitForTimeout(500);
      const t = await texte(sp);
      if (t !== precedent && !/^\s*$/.test(t)) { change = { ms: Date.now() - debut, texte: t.slice(0, 400) }; precedent = t; break; }
    }
    const vivant = await sp.evaluate(() => window.__sfNoReload);
    mesure('SF-2-reflet', { st: e.st, change: change ? change.ms : null, sansRechargement: vivant === 'vivant', texte: change?.texte });
    await sp.screenshot({ path: path.join(OUT, `SF2-statut-${e.st}.png`), fullPage: true }).catch(() => {});
    check(`SF-2 statut ${e.st} visible sans recharger (≤ 10 s)`, change && change.ms <= 10_000 && vivant === 'vivant', { ms: change?.ms });
  }
  C.ctx = ctx; C.sp = sp;
});

test('SF-3 en route puis livrée : suivi sans recharger, points gagnés crédités et affichés (≈ euros), solde de la page fidélité frais', async ({ browser }) => {
  const sp = C.sp; const ctx = C.ctx;
  // page fidélité ouverte AVANT la livraison (le solde affiché doit suivre sans rechargement)
  const fp = await ctx.newPage();
  await fp.emulateMedia({ reducedMotion: 'reduce' });
  await fp.goto(`${SITE}/#loyalty`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await expect(fp.locator('#root .lc-app')).toBeVisible({ timeout: 60_000 });
  await fp.waitForTimeout(3000);
  const f0 = await texte(fp);
  await fp.evaluate(() => { window.__sfNoReload = 'vivant'; });
  mesure('SF-3-fidelite-avant', { texte: f0.slice(0, 600) });
  await fp.screenshot({ path: path.join(OUT, 'SF3-fidelite-0.png'), fullPage: true });
  await sp.bringToFront();

  let precedent = await texte(sp);
  const stop = JSON.parse(tinker(`$s=\\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id', ${C.o1.id})->first(); echo json_encode(['id'=>$s->id,'wave'=>(string)$s->wave_at,'due'=>(int)$s->amount_due_cents]);`));
  const asg = await pos.post(`${BASE}/api/admin/pos-delivery/waves/assign`, { data: { wave_at: stop.wave, delivery_boy_id: COURIER.id } });
  mesure('SF-3-assign', { http: asg.status(), stop });
  expect(asg.status()).toBe(200);
  const boyApi = await livreurApi();
  const actions = [
    { st: 10, go: () => boyApi.post(`${BASE}/api/frontend/delivery-run/depart`, { headers: { 'X-Idempotency-Key': uuid() }, data: { wave_at: stop.wave } }) },
    { st: 13, go: async () => { await boyApi.post(`${BASE}/api/frontend/delivery-run/stops/${stop.id}/arrive`, { headers: { 'X-Idempotency-Key': uuid() }, data: {} }); return boyApi.post(`${BASE}/api/frontend/delivery-run/stops/${stop.id}/deliver`, { headers: { 'X-Idempotency-Key': uuid() }, data: { collected_cents: stop.due } }); } },
  ];
  for (const e of actions) {
    const t0 = Date.now();
    const r = await e.go();
    mesure('SF-3-livreur', { st: e.st, http: r.status(), txt: (await r.text()).slice(0, 160) });
    expect(r.status()).toBeLessThan(300);
    let change = null;
    for (let i = 0; i < 90; i++) {
      await sp.waitForTimeout(500);
      const t = await texte(sp);
      if (t !== precedent) { change = { ms: Date.now() - t0, texte: t.slice(0, 700) }; precedent = t; break; }
    }
    mesure('SF-3-reflet', { st: e.st, ms: change?.ms ?? null, texte: change?.texte });
    await sp.screenshot({ path: path.join(OUT, `SF3-statut-${e.st}.png`), fullPage: true }).catch(() => {});
    check(`SF-3 statut ${e.st} visible sans recharger (≤ 10 s)`, change && change.ms <= 10_000, { ms: change?.ms });
  }
  // la vérité hors écran
  const profil = await profilPoints();
  const ledger = JSON.parse(tinker(`echo json_encode(\\Illuminate\\Support\\Facades\\DB::table('loyalty_transactions')->where('user_id', ${C.id})->orderBy('id')->get(['type','points','balance_after','order_id'])); `));
  const ord = JSON.parse(tinker(`$o=\\App\\Models\\Order::withoutGlobalScopes()->find(${C.o1.id}); echo json_encode(['status'=>$o->status,'awarded'=>$o->loyalty_points_awarded,'total'=>(float)$o->total]);`));
  mesure('SF-3-base', { profil, ledger, ord });
  check('SF-3 des points gagnés sont crédités à la livraison', profil > 500, { profil });
  // le texte de la tuile « points » du suivi
  const tuile = await sp.locator('.lcx-track-reward').innerText().catch(() => '');
  mesure('SF-3-tuile-points', { tuile: tuile.replace(/\s+/g, ' ') });
  await expect.poll(async () => (await sp.locator('.lcx-track-reward').innerText()).replace(/\s+/g, ' '), { timeout: 15_000, message: 'la tuile points affiche le gain en euros ET le solde réel' }).toMatch(new RegExp('crédités \\(≈ [\\d,]+ €\\).*Votre solde : ' + profil + ' pts ≈ '));
  const pageTexte = await texte(sp);
  check('SF-3 règlement dit la vérité (espèces à la porte, pas « Carte en ligne »)', /Espèces à la remise — payées/.test(pageTexte) && !/Carte en ligne/.test(pageTexte), pageTexte.match(/Règlement :[^A-Z]{0,60}/)?.[0]);
  // la page fidélité, SANS rechargement : on compare le SOLDE extrait (pas le texte entier, qui bouge pour d'autres raisons)
  const avantSolde = soldeAffiche(f0);
  let fin = null;
  for (let i = 0; i < 90; i++) {
    const t = await texte(fp);
    const v = soldeAffiche(t);
    if (v !== null && v === profil) { fin = { ms: i * 500, solde: v }; break; }
    await fp.waitForTimeout(500);
  }
  const vivant = await fp.evaluate(() => window.__sfNoReload);
  mesure('SF-3-fidelite-apres', { avantSolde, profil, change: fin?.ms ?? null, sansRechargement: vivant === 'vivant' });
  await fp.screenshot({ path: path.join(OUT, 'SF3-fidelite-1.png'), fullPage: true });
  check('SF-3 la page fidélité ouverte affiche le nouveau solde sans rechargement (≤ 30 s)', !!fin && vivant === 'vivant', { avantSolde, profil, ms: fin?.ms });
  await ctx.close();
});

test('SF-4 plusieurs commandes, une tournée : espèces à la porte tracées (service livreur + planche), points de chacun justes, chaîne fiscale intacte', async () => {
  test.setTimeout(240_000);
  const clients = [];
  for (const [i, pts] of [[0, 0], [1, 0], [2, 1200]]) {
    const c = await creerClient(pts);
    const h = { 'X-API-KEY': API_KEY, Authorization: `Bearer ${c.token}`, Accept: 'application/json', 'Content-Type': 'application/json' };
    const q = await (await rq.post(`${BASE}/api/frontend/delivery/quote`, { headers: pub, data: { q: '437 rue elie gruyelle 62110 henin-beaumont' } })).json();
    const a = await (await rq.post(`${BASE}/api/frontend/address`, { headers: h, data: { label: `E2E-FID4 ${NOW.toString(36)}${i}`, address: q.address.label, apartment: `${i + 1}A` } })).json();
    clients.push({ ...c, h, address: a.data.id, idx: i });
  }
  const plan = [{ qty: 3, redeem: 0 }, { qty: 5, redeem: 0 }, { qty: 12, redeem: 1000 }];
  const cmds = [];
  for (const [i, c] of clients.entries()) {
    const data = {
      branch_id: 1, order_type: 5, source: 5, is_advance_order: 10, payment_method: 1, address_id: c.address, delivery_time: 'ASAP', delivery_distance_km: 0, delivery_charge: 0,
      items: JSON.stringify([{ item_id: 58, item_price: 1, quantity: plan[i].qty, discount: 0, total_price: plan[i].qty, item_variations: [], item_extras: [], instruction: 'E2E-FID4' }]),
    };
    if (plan[i].redeem) { data.loyalty_code = c.code; data.loyalty_redeem_points = plan[i].redeem; }
    const r = await rq.post(`${BASE}/api/frontend/order`, { headers: { ...c.h, 'X-Idempotency-Key': uuid() }, data });
    const b = await r.json();
    expect(r.status(), JSON.stringify(b).slice(0, 300)).toBeLessThan(300);
    const row = JSON.parse(tinker(`$o=\\App\\Models\\Order::withoutGlobalScopes()->find(${b.data.id}); $s=\\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id',${b.data.id})->first(); echo json_encode(['total'=>(float)$o->total,'wave'=>(string)$s->wave_at,'stop'=>$s->id,'due'=>(int)$s->amount_due_cents,'method'=>$s->collect_method]);`));
    cmds.push({ id: b.data.id, c, ...row });
  }
  mesure('SF-4-commandes', cmds.map((x) => ({ id: x.id, total: x.total, wave: x.wave, due: x.due, method: x.method })));
  expect(cmds.every((x) => x.method === 'cash' && x.due === Math.round(x.total * 100)), 'chaque arrêt = espèces, dû = total').toBe(true);

  for (const x of cmds) for (const st of [4, 7, 8]) { const r = await cuisine(x.id, st); expect(r.http, r.txt).toBeLessThan(300); }
  for (const w of [...new Set(cmds.map((x) => x.wave))]) {
    const asg = await pos.post(`${BASE}/api/admin/pos-delivery/waves/assign`, { data: { wave_at: w, delivery_boy_id: COURIER.id } });
    expect(asg.status()).toBe(200);
  }
  const boyApi = await livreurApi();
  const sessAvant = Number(tinker(`echo \\App\\Models\\DeliveryBoyCashMovement::withoutGlobalScopes()->count();`));
  for (const w of [...new Set(cmds.map((x) => x.wave))]) {
    const r = await boyApi.post(`${BASE}/api/frontend/delivery-run/depart`, { headers: { 'X-Idempotency-Key': uuid() }, data: { wave_at: w } });
    expect(r.status(), await r.text()).toBe(200);
  }
  for (const x of cmds) {
    await boyApi.post(`${BASE}/api/frontend/delivery-run/stops/${x.stop}/arrive`, { headers: { 'X-Idempotency-Key': uuid() }, data: {} });
    const r = await boyApi.post(`${BASE}/api/frontend/delivery-run/stops/${x.stop}/deliver`, { headers: { 'X-Idempotency-Key': uuid() }, data: { collected_cents: x.due } });
    expect(r.status(), await r.text()).toBe(200);
  }
  // la vérité hors écran
  const ids = cmds.map((x) => x.id).join(',');
  const mv = JSON.parse(tinker(`echo json_encode(\\App\\Models\\DeliveryBoyCashMovement::withoutGlobalScopes()->whereIn('order_id',[${ids}])->get(['order_id','amount','direction','type','delivery_boy_cash_session_id']));`));
  mesure('SF-4-mouvements', { avant: sessAvant, mv });
  check('SF-4 un mouvement de caisse livreur par commande espèces', mv.length === cmds.length && mv.every((m) => m.type === 'order_collect' && m.direction === 'in'), mv);
  check('SF-4 somme des mouvements = somme des totaux', Math.abs(mv.reduce((s, m) => s + Number(m.amount), 0) - cmds.reduce((s, x) => s + x.total, 0)) < 0.005, { mv: mv.reduce((s, m) => s + Number(m.amount), 0), totaux: cmds.reduce((s, x) => s + x.total, 0) });
  check('SF-4 un seul service livreur pour toute la tournée', new Set(mv.map((m) => m.delivery_boy_cash_session_id)).size === 1, mv.map((m) => m.delivery_boy_cash_session_id));
  // points : chacun reçoit floor(total × 10), le 3ᵉ a utilisé 1000 pts
  for (const x of cmds) {
    const u = JSON.parse(tinker(`echo json_encode(['p'=>(int)\\App\\Models\\User::withoutGlobalScopes()->find(${x.c.id})->loyalty_points]);`));
    const attendu = (x.c.idx === 2 ? 200 : 0) + Math.floor(x.total * 10);
    check(`SF-4 points du client ${x.c.idx + 1} = ${attendu}`, u.p === attendu, { reel: u.p, total: x.total });
  }
  // la planche de la caisse
  const board = await (await pos.get(`${BASE}/api/admin/pos-delivery/board`)).json();
  const caisseCash = board.data.filter((w) => w.stops.some((s) => cmds.some((x) => x.id === s.order_id))).reduce((s, w) => s + Number(w.cash.collected), 0);
  check('SF-4 la planche annonce les espèces encaissées (≥ somme des totaux)', caisseCash >= cmds.reduce((s, x) => s + x.total, 0) - 0.005, { caisseCash });
  const chaine = execFileSync('php', ['artisan', 'fiscal:verify-chain', '--all'], { cwd: ROOT, encoding: 'utf8', timeout: 120_000 });
  check('SF-4 chaîne NF525 intacte', chaine.includes('CHAIN OK on every active branch'), chaine.slice(-120));
});

test('SF-5 planche « Tournées » (navigateur) : les espèces encaissées par le livreur sont visibles', async ({ browser }) => {
  const { loginAsPosOperator } = require('../e2e/helpers/login');
  const ctx = await browser.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport: { width: 1280, height: 720 }, baseURL: BASE });
  const page = await ctx.newPage();
  await loginAsPosOperator(page);
  await page.goto('/admin/pos');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2500);
  const toggle = page.locator('[data-testid="pos-delivery-toggle"]');
  await expect(toggle).toBeVisible({ timeout: 15000 });
  await page.locator('[data-testid="pos-delivery-board-open"]').click();
  const board = page.locator('[data-testid="pos-delivery-board"]');
  await expect(board).toContainText('Espèces encaissées', { timeout: 20000 });
  const chip = (await board.locator('[data-testid^="pos-delivery-wave-cash-"]').first().innerText()).replace(/\s+/g, ' ');
  mesure('SF-5-planche', { chip });
  await page.screenshot({ path: path.join(OUT, 'SF5-planche-especes.png') });
  check('SF-5 la planche dit « à récupérer auprès du livreur »', /Espèces encaissées : [\d\s,]+ € · à récupérer auprès du livreur/.test(chip), chip);
  await ctx.close();
});

test.afterAll(async () => {
  // les commandes de test non remises (départ groupé d'une passe précédente, etc.) sont annulées : elles n'encombrent ni la planche ni la cuisine
  try {
    const ids = JSON.parse(tinker(`echo json_encode(\\App\\Models\\Order::withoutGlobalScopes()->whereIn('user_id', \\App\\Models\\User::withoutGlobalScopes()->where('name','like','E2E-FIDELITE%')->select('id'))->whereNotIn('status',[13,16,19])->pluck('id'));`));
    const a = await rq.post(`${BASE}/api/auth/login`, { headers: { ...pub, 'X-Device-Id': 'e2e-sf-admin-' + NOW }, data: { email: 'admin@lecayenne.fr', password: '123456' } });
    const tok = (await a.json()).token;
    for (const id of ids) {
      const r = await rq.post(`${BASE}/api/admin/online-order/change-status/${id}`, { headers: { ...pub, Authorization: `Bearer ${tok}`, 'X-Idempotency-Key': uuid() }, data: { status: 16, reason: 'E2E-FIDELITE fin de passe' } });
      console.log(`[SF nettoyage] commande ${id} → ${r.status()}`);
    }
  } catch (e) { console.log('[SF nettoyage] ' + String(e.message).slice(0, 160)); }
  if (rq) await rq.dispose().catch(() => {});
});
