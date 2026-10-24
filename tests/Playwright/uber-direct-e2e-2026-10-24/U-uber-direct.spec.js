// [AUDIT E2E uber-direct-e2e-2026-10-24 · vague U] Livraison « via livreur Uber Direct » de bout en bout, contre le FAUX Uber
// local fidèle (`tests/e2e/helpers/uber-direct-stub.js` sur :8790). Le VRAI backend (UberDirectClient) lui parle en HTTP ; il
// pousse des webhooks SIGNÉS vers `/api/webhooks/uber-direct`. Aucune requête ne part vers Uber ni vers la production.
//
// Chaque scénario est vérifié par au moins DEUX moyens indépendants : l'écran (planche « Tournées », écran livreur, suivi du
// site, tableau « Suivi commandes ») ET une source de vérité hors écran (journal du faux Uber, lignes `uber_direct_deliveries` /
// `delivery_stops` / `orders` / `audit_logs`, codes HTTP). Aucune assertion n'est avalée par un `.catch`.
//
// Données : commandes de LIVRAISON créées par l'API caisse (comme B-L2), client = compte invité du site (téléphone valide,
// jeton pour le suivi), note « E2E-UBER-<scénario> ». Les commandes de test non livrées sont annulées en fin de passe (statut 16).
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/uber-direct-e2e-2026-10-24/U-uber-direct.spec.js --workers=1 --retries=0 --reporter=line
const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const ROOT = path.resolve(__dirname, '../../..');
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const STUB = process.env.UBER_STUB_URL || 'http://127.0.0.1:8790';
const SITE = process.env.U_SITE_URL || 'http://127.0.0.1:8013';
const OUT = path.join(ROOT, 'reports/test-e2e/uber-direct-e2e-2026-10-24/captures-U');
fs.mkdirSync(OUT, { recursive: true });
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = env('MIX_API_KEY') || env('API_KEY');
const CUSTOMER_ID = env('UBER_DIRECT_CUSTOMER_ID');
const pub = { 'X-API-KEY': API_KEY, Accept: 'application/json', 'Content-Type': 'application/json' };
const POS = { email: 'pos@lecayenne.fr', password: '123456', id: 3 };
const ADMIN = { email: 'admin@lecayenne.fr', password: '123456' };
const COURIER = { id: 10, email: 'livreur.e2e@lecayenne.test', password: 'Livreur123!', name: 'Livreur E2E' };
const PROD_RX = /vps-418872ac|ovh\.net|lecayenne\.fr|auth\.uber\.com|api\.uber\.com/i;
const NOW = Date.now();
const TAG = 'E2E-UBER';
const PNG_CAP = 15;
let pngUsed = 0;

// ---------------------------------------------------------------- outils hors écran
function sql(q) { return execFileSync('mysql', ['-uroot', '-h', 'localhost', 'foodking_e2e', '-N', '-B', '-e', q], { encoding: 'utf8', timeout: 30_000 }).trim().split('\n').filter(Boolean).map((l) => l.split('\t')); }
function sqlRow(q, cols) { const r = sql(q)[0]; return r ? Object.fromEntries(cols.map((c, i) => [c, r[i] === 'NULL' ? null : r[i]])) : null; }
function tinker(code) { return execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8', timeout: 90_000 }).trim(); }
function artisan(args) { return execFileSync('php', ['artisan', ...args], { cwd: ROOT, encoding: 'utf8', timeout: 180_000 }).trim(); }
function resetLimiteurs() {
  clearFoodKingRateLimits();
  tinker(`$l = app(\\Illuminate\\Cache\\RateLimiter::class);
    foreach (['pos-delivery-toggle','pos-delivery-suggest','pos-delivery-quote','pos-delivery-address','pos-delivery-uber-quote','pos-delivery-uber-act','pos-loyalty-lookup'] as $n) { foreach (['${POS.id}','1','127.0.0.1','::1'] as $k) { $l->clear(md5($n.$k)); } }
    $l->clear(sha1('|127.0.0.1')); $l->clear('otp-verify'.sha1('|127.0.0.1')); $l->clear('signup-register'.sha1('|127.0.0.1'));
    foreach (['delivery-suggest','delivery-quote','delivery-status','delivery-waves'] as $n) { foreach (['127.0.0.1','::1'] as $ip) { $l->clear(md5($n.$ip)); } }
    echo 'ok';`);
}
const ORDER_COLS = ['id', 'order_serial_no', 'status', 'payment_status', 'delivery_boy_id', 'total', 'fiscal_sequence_no', 'order_type', 'payment_method'];
const orderRow = (id) => sqlRow(`select ${ORDER_COLS.join(',')} from orders where id=${Number(id)}`, ORDER_COLS);
const STOP_COLS = ['id', 'order_id', 'wave_at', 'sequence', 'collect_method', 'amount_due_cents', 'delivery_boy_id', 'left_at', 'arrived_at', 'delivered_at', 'amount_collected_cents'];
const stopOf = (orderId) => sqlRow(`select ${STOP_COLS.join(',')} from delivery_stops where order_id=${Number(orderId)}`, STOP_COLS);
const UBER_COLS = ['id', 'order_id', 'quote_id', 'quote_fee_cents', 'eta_minutes', 'quote_expires_at', 'delivery_id', 'tracking_url', 'status', 'status_updated_at', 'failure_code', 'failure_message', 'create_attempts', 'dropoff_phone'];
const uberRow = (orderId) => sqlRow(`select ${UBER_COLS.join(',')} from uber_direct_deliveries where order_id=${Number(orderId)}`, UBER_COLS);
const audits = (orderId) => sql(`select id,action,user_id,payload from audit_logs where resource='order' and resource_id=${Number(orderId)} and action like 'delivery.uber%' order by id`).map((r) => ({ id: r[0], action: r[1], user_id: r[2], payload: r[3] }));
const chainVerify = () => { const out = artisan(['fiscal:verify-chain', '--all']); return { ok: /CHAIN OK on every active branch/.test(out) && !/MISMATCH|BROKEN|FAIL/i.test(out), tail: out.split('\n').slice(-3).join(' | ') }; };

// ---------------------------------------------------------------- registre de preuve
const L = { tag: TAG, startedAt: new Date().toISOString(), bench: { base: BASE, stub: STUB, site: SITE }, scenarios: {}, orders: {}, prodBloquees: [], notes: [], png: [] };
const ledgerPath = path.join(OUT, 'ledger.json');
const write = () => fs.writeFileSync(ledgerPath, JSON.stringify(L, null, 2));
const note = (s) => { L.notes.push(s); console.log('[U] ' + s); write(); };
const scn = (id) => (L.scenarios[id] ||= { checks: [], steps: {} });
function check(id, name, ok, detail) { scn(id).checks.push({ name, ok: !!ok, detail }); console.log(`[U CHECK ${id}] ${ok ? 'OK ' : 'KO '} ${name} ${detail !== undefined ? JSON.stringify(detail).slice(0, 240) : ''}`); write(); }
function step(id, name, data) { scn(id).steps[name] = data; write(); }

// ---------------------------------------------------------------- faux Uber
let rq; // contexte HTTP nu (faux Uber, site, API)
const stub = async (p, body) => { const r = body ? await rq.post(STUB + p, { data: body }) : await rq.get(STUB + p); return { http: r.status(), body: await r.json().catch(() => null) }; };
const stubLog = async () => (await stub('/__control/log')).body;
const stubMode = (m) => stub('/__control/mode', m);
const pushStatus = (delivery_id, status) => stub('/__control/status', { delivery_id, status, push: true });
const pushWebhook = (delivery_id, status, secret) => stub('/__control/webhook', secret ? { delivery_id, status, secret } : { delivery_id, status });
/** Entrées du journal du faux Uber reçues après l'index `since`, résumées (jamais de secret recopié). */
function uberCalls(log, since = 0) {
  return log.log.slice(since).map((e) => ({ at: e.at, method: e.method, path: e.path.replace(CUSTOMER_ID, '{customer}'), auth: e.auth, body: summarize(e.body) }));
}
function summarize(b) {
  if (!b || typeof b !== 'object') return b;
  const keep = ['grant_type', 'scope', 'quote_id', 'idempotency_key', 'external_id', 'pickup_address', 'dropoff_address', 'pickup_phone_number', 'dropoff_phone_number', 'pickup_ready_dt', 'manifest_items', 'manifest_reference', 'manifest_total_value', 'external_store_id', 'pickup_name', 'dropoff_name', 'dropoff_notes', 'pickup_latitude', 'pickup_longitude', 'dropoff_latitude', 'dropoff_longitude'];
  const o = {}; for (const k of keep) if (b[k] !== undefined) o[k] = b[k];
  if (b.client_id) o.client_id = '(présent)'; if (b.client_secret) o.client_secret = '(présent, non recopié)';
  return o;
}
const deliveriesPosts = (calls) => calls.filter((c) => c.method === 'POST' && /\/deliveries$/.test(c.path));
const cancelPosts = (calls, deliveryId) => calls.filter((c) => c.method === 'POST' && c.path.endsWith(`/deliveries/${deliveryId}/cancel`));

// ---------------------------------------------------------------- API caisse / admin
let api; let posToken; let adminTok;
async function posLogin() {
  resetLimiteurs();
  const l = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, 'X-Device-Id': 'e2e-uber-u-' + NOW } });
  const r = await l.post('/api/auth/login', { data: { email: POS.email, password: POS.password } });
  expect(r.status(), 'connexion API caisse').toBe(201);
  posToken = (await r.json()).token;
  await l.dispose();
  return request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, Authorization: `Bearer ${posToken}` } });
}
async function adminToken() {
  const r = await rq.post(`${BASE}/api/auth/login`, { headers: { ...pub, 'X-Device-Id': 'e2e-uber-u-admin-' + NOW }, data: { email: ADMIN.email, password: ADMIN.password } });
  const j = await r.json().catch(() => ({}));
  expect(r.status(), 'connexion API admin').toBe(201);
  return j.token;
}
const boardJson = async () => { const r = await api.get('/api/admin/pos-delivery/board'); expect(r.status(), 'GET board').toBe(200); return r.json(); };
const boardStop = (bj, orderId) => { for (const w of bj.data) for (const s of w.stops) if (String(s.order_id) === String(orderId)) return { wave: w.wave_at, courier_id: w.courier_id, ...s }; return null; };
async function apiQuote(stopId) { const r = await api.post('/api/admin/pos-delivery/uber/quote', { data: { stop_id: stopId } }); return { http: r.status(), body: await r.json().catch(() => null) }; }
async function apiDispatch(stopId, quoteId) { const r = await api.post('/api/admin/pos-delivery/uber/dispatch', { data: { stop_id: stopId, quote_id: quoteId } }); return { http: r.status(), body: await r.json().catch(() => null) }; }
async function apiRelease(stopId) { const r = await api.post('/api/admin/pos-delivery/uber/release', { data: { stop_id: stopId } }); return { http: r.status(), body: await r.json().catch(() => null) }; }
async function bump(orderId, statuses) {
  for (const status of statuses) {
    const cur = Number(orderRow(orderId).status);
    if (cur >= status) continue;
    const r = await api.post(`/api/admin/pos-order/change-status/${orderId}`, { headers: { 'X-Idempotency-Key': crypto.randomUUID() }, data: { status } });
    expect(r.status(), `cuisine → statut ${status} (commande ${orderId}) : ${await r.text()}`).toBeLessThan(300);
  }
}
async function cancelOrder(orderId, reason) {
  const r = await rq.post(`${BASE}/api/admin/online-order/change-status/${orderId}`, { headers: { ...pub, Authorization: `Bearer ${adminTok}`, 'X-Idempotency-Key': crypto.randomUUID() }, data: { status: 16, reason } });
  return { http: r.status(), body: (await r.text()).slice(0, 300) };
}

// ---------------------------------------------------------------- navigateur
let posCtx; let page; let board; let trk; let livreurCtx; let pl; let siteCtx; let sp; let rec; let recL; let recS;
async function guardedContext(browser, opts) {
  const ctx = await browser.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris', ...opts });
  await ctx.route((url) => PROD_RX.test(url.href), (route) => { L.prodBloquees.push(route.request().url().slice(0, 200)); write(); return route.abort('blockedbyclient'); });
  await ctx.route((url) => /js\.mollie\.com/.test(url.href), (route) => route.abort('blockedbyclient'));
  return ctx;
}
function recorder(p) {
  const r = attachMegaAuditRecorder(p, OUT);
  return { ...r, snap: async (name, png = true) => {
    if (png && pngUsed < PNG_CAP) { pngUsed += 1; L.png.push(name); write(); return r.snap(name); }
    const orig = p.screenshot.bind(p);
    p.screenshot = async () => Buffer.alloc(0);
    try { return await r.snap(name); } finally { p.screenshot = orig; }
  } };
}
const settle = async (p, ms = 900) => { await p.waitForLoadState('networkidle', { timeout: 4000 }).catch(() => {}); await p.waitForTimeout(ms); };
async function ouvrirCaisse() {
  await page.goto('/login');
  await expect(page.locator('#formEmail')).toBeVisible({ timeout: 60_000 });
  await page.locator('#formEmail').fill(POS.email);
  await page.locator('#formPassword').fill(POS.password);
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/auth\/login/.test(r.url()), { timeout: 60_000 }),
    page.getByRole('button', { name: /^(login|connexion)$/i }).click(),
  ]);
  expect(resp.status(), 'connexion caisse (navigateur)').toBe(201);
  await page.waitForURL((u) => !/\/login/.test(u.pathname), { timeout: 60_000 });
  await page.waitForTimeout(1200);
  if (!/\/admin\/pos(\/|$|\?)/.test(page.url())) await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await settle(page, 2000);
  const ov = page.locator('[data-testid="cash-session-overlay"]');
  if (await ov.isVisible().catch(() => false)) { await page.locator('[data-testid="cash-session-close"]').click(); await page.waitForTimeout(400); }
  await expect(page.locator('[data-testid="pos-delivery-board-open"]')).toBeVisible({ timeout: 30_000 });
}
/** Ouvre (ou ré-ouvre : fermeture + ouverture = rechargement complet) la planche « Tournées ». */
async function planche() {
  board = page.locator('[data-testid="pos-delivery-board"]');
  if (await board.isVisible().catch(() => false)) { await board.getByRole('button', { name: 'Fermer' }).click(); await page.waitForTimeout(300); }
  const rep = page.waitForResponse((r) => /pos-delivery\/board/.test(r.url()) && r.request().method() === 'GET', { timeout: 30_000 });
  await page.locator('[data-testid="pos-delivery-board-open"]').click();
  await expect(board).toBeVisible({ timeout: 20_000 });
  const r = await rep; expect(r.status(), 'GET board (planche)').toBe(200);
  await page.waitForTimeout(700);
  return board;
}
const zone = (stopId) => board.locator(`[data-testid="pos-delivery-dispatch-${stopId}"]`);
const bandeau = () => board.locator('[data-testid="pos-delivery-board-head"] [role="alert"]');
async function confier(stopId) {
  const z = zone(stopId); await z.scrollIntoViewIfNeeded();
  const rep = page.waitForResponse((r) => /pos-delivery\/uber\/quote/.test(r.url()), { timeout: 30_000 });
  await z.locator('[data-testid="pos-delivery-use-uber"]').click();
  const r = await rep; await page.waitForTimeout(500);
  return { http: r.status(), body: await r.json().catch(() => null) };
}
async function confirmerUber(stopId) {
  const z = zone(stopId); await z.scrollIntoViewIfNeeded();
  const rep = page.waitForResponse((r) => /pos-delivery\/uber\/dispatch/.test(r.url()), { timeout: 30_000 });
  await z.locator('[data-testid="pos-delivery-uber-confirm"]').click();
  const r = await rep;
  // après un succès la planche se recharge (GET board) : on l'attend pour lire l'état à jour
  if (r.status() === 200) await page.waitForResponse((x) => /pos-delivery\/board/.test(x.url()), { timeout: 30_000 }).catch(() => {});
  await page.waitForTimeout(700);
  return { http: r.status(), body: await r.json().catch(() => null) };
}
async function nosLivreurs(stopId) {
  const z = zone(stopId); await z.scrollIntoViewIfNeeded();
  const rep = page.waitForResponse((r) => /pos-delivery\/uber\/release/.test(r.url()), { timeout: 30_000 });
  await z.locator('[data-testid="pos-delivery-use-own"]').click();
  const r = await rep;
  if (r.status() === 200) await page.waitForResponse((x) => /pos-delivery\/board/.test(x.url()), { timeout: 30_000 }).catch(() => {});
  await page.waitForTimeout(700);
  return { http: r.status(), body: await r.json().catch(() => null) };
}
const zoneTexte = async (stopId) => (await zone(stopId).innerText()).replace(/\s+/g, ' ').trim();
const ligneTexte = async (orderSerial) => (await board.locator('li', { hasText: orderSerial }).first().innerText()).replace(/\s+/g, ' ').trim();

// écran livreur
async function ouvrirLivreur(browser) {
  livreurCtx = await guardedContext(browser, { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, baseURL: BASE });
  pl = await livreurCtx.newPage();
  recL = recorder(pl);
  await pl.goto('/livreur');
  await pl.locator('#email').fill(COURIER.email);
  await pl.locator('#password').fill(COURIER.password);
  await pl.locator('#login-btn').click();
  await expect(pl.locator('.stop, #app-err, .empty, .wave').first()).toBeVisible({ timeout: 30_000 }).catch(() => {});
  await pl.waitForTimeout(1500);
}
async function livreurRun() {
  return pl.evaluate(async (k) => (await fetch('/api/frontend/delivery-run', { headers: { Accept: 'application/json', 'X-API-KEY': k, Authorization: 'Bearer ' + localStorage.getItem('lc_livreur_token') } })).json(), API_KEY);
}
const livreurListe = async (serial) => { await pl.reload(); await pl.waitForTimeout(2000); const run = await livreurRun(); const ids = []; for (const w of run.data || []) for (const s of w.stops || []) ids.push(Number(s.order_id)); return { ecran: (await pl.locator('.stop', { hasText: serial }).count()) > 0, api: ids }; };

// site (suivi client)
let AUTH = null; // { token, phone, email, userId }
async function creerClientInvite() {
  resetLimiteurs();
  const phone = '0698' + String(NOW).slice(-6);
  const email = `e2e-uber-${String(NOW).slice(-6)}@lecayenne-test.fr`;
  const prenom = 'E2E-UBER Client';
  const r = await rq.post(`${BASE}/api/auth/guest-signup/email-login`, { headers: pub, data: { email, first_name: prenom, last_name: 'Test', phone } });
  const rb = (await r.text()).slice(0, 200);
  const token = sql(`SELECT token FROM otps WHERE phone='${phone}' ORDER BY created_at DESC LIMIT 1`)[0]?.[0];
  expect(token, `code OTP enregistré en base (email-login → ${r.status()} ${rb})`).toMatch(/^\d{4}$/);
  resetLimiteurs();
  const v = await rq.post(`${BASE}/api/auth/guest-signup/verify`, { headers: pub, data: { token, phone, code: '+33', email, first_name: prenom } });
  const vj = await v.json().catch(() => ({}));
  expect(v.status(), 'vérification OTP : ' + JSON.stringify(vj).slice(0, 200)).toBeLessThan(300);
  const look = await api.get(`/api/admin/pos-delivery/lookup?phone=${phone}`);
  const lj = await look.json();
  expect(lj.found, 'le client invité est retrouvé par la caisse (téléphone)').toBe(true);
  AUTH = { token: vj.token, phone, email, userId: lj.data.id, name: lj.data.name };
  note(`client invité ${AUTH.userId} (${phone}) créé, jeton site obtenu`);
}
async function ouvrirSite(browser) {
  siteCtx = await guardedContext(browser, { viewport: { width: 1440, height: 900 } });
  await siteCtx.addInitScript(([t, p]) => { try { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); } catch (_e) { /* storage */ } }, [AUTH.token, AUTH.phone]);
  sp = await siteCtx.newPage();
  await sp.emulateMedia({ reducedMotion: 'reduce' });
  recS = recorder(sp);
}
const texteVisible = (p) => p.evaluate(() => String(document.body.innerText || '').replace(/[  ]/g, ' ').replace(/\s+/g, ' '));

// ---------------------------------------------------------------- commandes de test
const O = {}; // A..I → { id, serial, stop, label }
let addressId; let fee;
async function creer(label, extra = {}) {
  const items = JSON.stringify([{ item_id: 58, item_price: 1, branch_id: 1, instruction: `${TAG}-${label}`, quantity: 1, discount: 0, total_price: 1, item_variation_total: 0, item_extra_total: 0, item_variations: [], item_extras: [], pos_line_addons: [] }]);
  const base = { branch_id: 1, subtotal: 1, token: `${TAG}-${label}-${NOW}`, customer_id: AUTH.userId, discount: 0, delivery_charge: fee, delivery_distance_km: 0, delivery_time: null, total: Math.round((1 + fee) * 100) / 100, order_type: 5, is_advance_order: 10, pos_payment_method: 1, pos_payment_note: `${TAG}-${label}`, pos_customer_name: null, pos_customer_phone: null, phone_order: false, scheduled_at: null, source: 15, address_id: addressId, coupon_id: null, items, dining_table_id: null, pos_received_amount: 5, loyalty_customer_code: null, loyalty_redeem_points: null, discount_reason: null, ...extra };
  const q = await api.post('/api/admin/pos/quote', { data: base });
  const qj = await q.json(); const qd = qj.data || qj;
  expect(q.status(), 'devis caisse : ' + JSON.stringify(qj).slice(0, 300)).toBe(200);
  const body = { ...base, quote_token: qd.quote_token, quote_signature: qd.signature, idempotency_key: `${NOW}_${Math.random().toString(36).slice(2, 10)}_1` };
  delete body.subtotal; delete body.total;
  const r = await api.post('/api/admin/pos', { data: body, headers: { 'X-Idempotency-Key': crypto.randomUUID() } });
  const rj = await r.json().catch(() => ({}));
  expect(r.status(), `création ${label} : ${JSON.stringify(rj).slice(0, 400)}`).toBe(201);
  const id = rj.data.id;
  const row = orderRow(id); const stop = stopOf(id);
  expect(stop, `un arrêt delivery_stops pour ${label}`).not.toBeNull();
  const o = { id, serial: row.order_serial_no, label, stop, order: row };
  L.orders[label] = o; write();
  return o;
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(420_000);
test.use({ video: 'off', trace: 'off', screenshot: 'off' });

test.describe('U · Uber Direct de bout en bout (faux Uber local)', () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(600_000);
    rq = await request.newContext();
    // Banc : faux Uber vivant, backend réglé sur lui (sans recopier les secrets), site servi.
    const ping = await stub('/__control/reset', {});
    expect(ping.http, 'faux Uber joignable sur :8790').toBe(200);
    expect(env('UBER_DIRECT_API_BASE'), '.env : UBER_DIRECT_API_BASE pointe sur le faux Uber').toBe(STUB);
    expect(env('UBER_DIRECT_TOKEN_URL'), '.env : UBER_DIRECT_TOKEN_URL pointe sur le faux Uber').toBe(STUB + '/oauth/v2/token');
    expect(env('UBER_DIRECT_ENABLED')).toBe('true');
    L.bench.site_http = (await rq.get(SITE + '/')).status();
    expect(L.bench.site_http, 'site servi sur :8013').toBe(200);
    // Jeton OAuth oublié côté backend : la première demande DOIT repasser par POST /oauth/v2/token du faux Uber.
    tinker("Illuminate\\Support\\Facades\\Cache::forget('uber_direct_access_token'); echo 'ok';");
    L.bench.chain_avant = chainVerify();
    expect(L.bench.chain_avant.ok, 'chaîne NF525 OK avant la passe').toBe(true);

    api = await posLogin();
    adminTok = await adminToken();
    const st = await (await api.get('/api/admin/pos-delivery/status')).json();
    if (!st.enabled) await api.post('/api/admin/pos-delivery/toggle', { data: { enabled: true } });
    expect((await (await api.get('/api/admin/pos-delivery/status')).json()).enabled, 'livraison active').toBe(true);

    await creerClientInvite();
    // Pas l'adresse du restaurant (437 rue Élie Gruyelle) : la course Uber doit avoir un vrai point de dépôt distinct (1 km).
    const ar = await api.post('/api/admin/pos-delivery/address', { data: { customer_id: AUTH.userId, address: 'boulevard albert schweitzer 62110 henin-beaumont', apartment: `${TAG} porte bleue` } });
    const aj = await ar.json();
    expect(ar.status(), 'adresse client : ' + JSON.stringify(aj).slice(0, 300)).toBe(200);
    expect(aj.data.allowed, 'adresse livrable').toBe(true);
    addressId = aj.data.id; fee = Number(aj.data.fee);
    L.bench.adresse = aj.data;
    note(`adresse ${addressId} (${aj.data.address}) frais ${fee} €`);

    // 8 livraisons PAYÉES (espèces au comptoir) + 1 À ENCAISSER à la porte.
    for (const k of ['A', 'B', 'C', 'E', 'F', 'G', 'H', 'I']) O[k] = await creer(k);
    O.D = await creer('D', { defer_to_counter: 1, pos_payment_method: 6, pos_received_amount: undefined });
    // La cuisine a fini A et C (PRÊTE) avant que la caisse ne les confie à Uber ; G reste « Acceptée » à dessein (mesure).
    await bump(O.A.id, [4, 7, 8]); await bump(O.C.id, [4, 7, 8]);
    for (const k of Object.keys(O)) { O[k].order = orderRow(O[k].id); O[k].stop = stopOf(O[k].id); L.orders[k] = O[k]; }
    // Nos livreurs : le livreur de test prend toutes les tournées concernées (plusieurs livreurs actifs ⇒ désignation explicite).
    const waves = [...new Set(Object.values(O).map((o) => o.stop.wave_at))];
    for (const w of waves) { const r = await api.post('/api/admin/pos-delivery/waves/assign', { data: { wave_at: w, delivery_boy_id: COURIER.id } }); expect(r.status(), 'désignation du livreur ' + w).toBe(200); }
    for (const k of Object.keys(O)) { O[k].stop = stopOf(O[k].id); L.orders[k] = O[k]; }
    note('commandes : ' + Object.values(O).map((o) => `${o.label}=${o.id}/${o.serial} stop ${o.stop.id} @${o.stop.wave_at} ${o.stop.collect_method} boy=${o.stop.delivery_boy_id} st=${o.order.status}`).join(' ; '));
    write();

    posCtx = await guardedContext(browser, { viewport: { width: 1280, height: 720 }, baseURL: BASE });
    page = await posCtx.newPage();
    rec = recorder(page);
    await ouvrirCaisse();
  });

  test.afterAll(async () => {
    test.setTimeout(300_000);
    try {
      L.cleanup = {};
      for (const k of Object.keys(O)) {
        const row = orderRow(O[k].id);
        if ([13, 16, 19].includes(Number(row.status))) { L.cleanup[k] = { skipped: `statut ${row.status}` }; continue; }
        const r = await cancelOrder(O[k].id, `${TAG} fin de passe`);
        L.cleanup[k] = { ...r, statut_apres: orderRow(O[k].id).status, arret_restant: !!stopOf(O[k].id) };
      }
      L.bench.chain_apres = chainVerify();
      L.bench.stub_mode_final = (await stubMode({ quote: 'ok', create: 'ok', cancel: 'ok' })).body;
      L.bench.uber_log_final = uberCalls(await stubLog());
      L.bench.webhooks_final = (await stubLog()).webhooks;
    } catch (e) { note('nettoyage : ' + String(e.message).slice(0, 200)); }
    L.finishedAt = new Date().toISOString(); L.pngUtilises = pngUsed; write();
    for (const c of [livreurCtx, siteCtx, posCtx]) if (c) await c.close().catch(() => {});
    if (rq) await rq.dispose().catch(() => {});
  });

  // ------------------------------------------------------------------------
  test('U-1 planche « Tournées » : Uber disponible, « Confier à Uber » actif sur un arrêt PAYÉ', async () => {
    const bj = await boardJson();
    const a = boardStop(bj, O.A.id); const d = boardStop(bj, O.D.id);
    step('U-1', 'board', { uber_enabled: bj.uber_enabled, A: a, D: d });
    expect(bj.uber_enabled, '/board annonce Uber disponible').toBe(true);
    expect(a, 'arrêt A dans la planche').not.toBeNull();
    expect(a.dispatch).toBe('own'); expect(a.uber_blocked).toBeNull(); expect(a.collect_method).toBe('paid_online');
    await planche();
    const zA = zone(O.A.stop.id); await zA.scrollIntoViewIfNeeded();
    const btn = zA.locator('[data-testid="pos-delivery-use-uber"]');
    await expect(btn).toBeVisible(); await expect(btn).toBeEnabled();
    await expect(zA.locator('[data-testid="pos-delivery-uber-reason"]')).toHaveCount(0);
    const ligne = await ligneTexte(O.A.serial);
    check('U-1', 'écran : bouton « Confier à Uber » actif, sans raison de blocage, arrêt « Payé »', /Payé/.test(ligne), ligne);
    expect(ligne).toMatch(/Payé/);
    await rec.snap('U1-planche-avant');
    // 1920×1080 : la planche tient, rien de recouvert
    await page.setViewportSize({ width: 1920, height: 1080 }); await page.waitForTimeout(600);
    const m = await page.evaluate(() => { const de = document.documentElement; const c = document.querySelector('[data-testid="pos-delivery-board"] > div'); const r = c && c.getBoundingClientRect(); return { debordeX: de.scrollWidth - de.clientWidth, dansEcran: r ? r.right <= innerWidth + 1 && r.left >= -1 : null }; });
    await rec.snap('U1-planche-1920');
    await page.setViewportSize({ width: 1280, height: 720 }); await page.waitForTimeout(400);
    step('U-1', 'layout_1920', m);
    expect(m.debordeX).toBeLessThanOrEqual(1);
  });

  // ------------------------------------------------------------------------
  test('U-2 livraison PAYÉE → « Confier à Uber » (7,42 € / 25 min) → « Confirmer Uber » : course créée chez le faux Uber', async ({ browser }) => {
    // avant : l'écran livreur liste l'arrêt A
    await ouvrirLivreur(browser);
    const avant = await livreurListe(O.A.serial);
    check('U-2', 'écran livreur : l’arrêt A est listé AVANT la bascule', avant.ecran && avant.api.includes(O.A.id), avant);
    expect(avant.ecran).toBe(true);
    await recL.snap('U2-livreur-avec-arret', false);

    await planche();
    const log0 = (await stubLog()).log.length;
    const q = await confier(O.A.stop.id);
    step('U-2', 'quote', q);
    expect(q.http, 'POST uber/quote').toBe(200);
    expect(q.body.fee).toBe(7.42); expect(q.body.eta_minutes).toBe(25); expect(q.body.quote_id).toMatch(/^dqt_/);
    const prix = zone(O.A.stop.id).locator('[data-testid="pos-delivery-uber-price"]');
    await expect(prix).toContainText('7,42 €'); await expect(prix).toContainText('25 min');
    await expect(zone(O.A.stop.id).locator('[data-testid="pos-delivery-uber-confirm"]')).toBeEnabled();
    await rec.snap('U2-prix-a-confirmer');
    // journal du faux Uber : OAuth puis devis, avec les bons corps
    const fullLog = await stubLog();
    let calls = uberCalls(fullLog, log0);
    step('U-2', 'uber_calls_quote', calls);
    // Le jeton est demandé UNE fois par le backend (jeton oublié au départ de la passe) : il peut l'avoir été dès le premier
    // relevé des courses actives (GET /board de U-1) ; on le cherche donc sur tout le journal, AVANT le premier devis.
    const tout = uberCalls(fullLog, 0);
    const oauth = tout.find((c) => c.path === '/oauth/v2/token');
    const quoteCall = calls.find((c) => /delivery_quotes$/.test(c.path));
    step('U-2', 'oauth', oauth);
    expect(oauth, 'le backend a demandé un jeton AU FAUX UBER (POST /oauth/v2/token)').toBeTruthy();
    expect(oauth.body.scope).toBe('eats.deliveries'); expect(oauth.body.grant_type).toBe('client_credentials');
    expect(quoteCall, 'POST /v1/customers/{customer}/delivery_quotes reçu par le faux Uber').toBeTruthy();
    expect(tout.indexOf(oauth)).toBeLessThan(tout.findIndex((c) => /delivery_quotes$/.test(c.path)));
    expect(quoteCall.auth).toBe(true);
    expect(String(quoteCall.body.pickup_address)).toContain('437 Rue Élie Gruyelle');
    expect(String(quoteCall.body.dropoff_address)).toMatch(/Schweitzer/i);
    expect(JSON.parse(quoteCall.body.dropoff_address).zip_code).toBe('62110');
    expect(quoteCall.body.pickup_phone_number).toBe('+33365678291');
    expect(quoteCall.body.dropoff_phone_number).toBe('+33' + AUTH.phone.slice(1));
    expect(quoteCall.body.pickup_ready_dt).toMatch(/^\d{4}-\d{2}-\d{2}T/);
    expect(quoteCall.body.external_store_id).toBe('branch-1');
    expect(quoteCall.body.manifest_total_value).toBe(Math.round(Number(O.A.order.total) * 100));
    const rowQ = uberRow(O.A.id);
    expect(rowQ.quote_id).toBe(q.body.quote_id); expect(Number(rowQ.quote_fee_cents)).toBe(742); expect(rowQ.delivery_id).toBeNull();

    // confirmer
    const log1 = (await stubLog()).log.length;
    const d = await confirmerUber(O.A.stop.id);
    step('U-2', 'dispatch', d);
    expect(d.http, 'POST uber/dispatch').toBe(200);
    expect(d.body.tracking_url).toMatch(/^https:\/\/stub\.uber\.local\/track\//);
    calls = uberCalls(await stubLog(), log1);
    step('U-2', 'uber_calls_dispatch', calls);
    const create = deliveriesPosts(calls)[0];
    expect(create, 'POST /v1/customers/{customer}/deliveries reçu').toBeTruthy();
    expect(create.body.quote_id).toBe(q.body.quote_id);
    // Clé liée au DEVIS (commande + quote_id) : un réessai garde la même clé, une nouvelle course après annulation en a une autre.
    expect(create.body.idempotency_key).toBe('lecayenne-order-' + O.A.id); // 1re course : clé de la commande (change seulement après une fin connue)
    expect(create.body.external_id).toBe('order-' + O.A.id);
    expect(create.body.manifest_reference).toBe('order-' + O.A.id);
    expect(Array.isArray(create.body.manifest_items) && create.body.manifest_items.length).toBe(1);
    expect(create.body.manifest_items[0].name).toBe('Commande Le Cayenne #' + O.A.serial);
    expect(create.body.pickup_name).toBe('Le Cayenne (principal)');
    expect(create.body.dropoff_name).toBe(AUTH.name);
    expect(String(create.body.dropoff_notes)).toContain('Commande #' + O.A.serial);
    expect(String(create.body.dropoff_notes)).toContain('porte bleue');
    expect(create.body.pickup_phone_number).toBe('+33365678291');
    expect(create.body.dropoff_phone_number).toBe('+33' + AUTH.phone.slice(1));
    // base
    const row = uberRow(O.A.id); const stop = stopOf(O.A.id); const ord = orderRow(O.A.id);
    step('U-2', 'base', { row, stop, ord, audits: audits(O.A.id) });
    expect(row.delivery_id).toMatch(/^del_/); expect(row.status).toBe('pending'); expect(row.tracking_url).toBe(d.body.tracking_url);
    expect(row.dropoff_phone).toBe('+33' + AUTH.phone.slice(1)); expect(row.failure_code).toBeNull();
    expect(stop.delivery_boy_id, 'arrêt sans livreur à nous').toBeNull(); expect(ord.delivery_boy_id).toBeNull();
    expect(audits(O.A.id).some((a) => a.action === 'delivery.uber_dispatched'), 'audit_logs delivery.uber_dispatched').toBe(true);
    O.A.deliveryId = row.delivery_id;
    // écran : badge Uber + suivi + « Nos livreurs » actif
    const zA = zone(O.A.stop.id); await zA.scrollIntoViewIfNeeded();
    await expect(zA.locator('[data-testid="pos-delivery-uber-badge"]')).toContainText('Uber');
    await expect(zA.locator('[data-testid="pos-delivery-uber-badge"]')).toContainText('Coursier en recherche');
    await expect(zA.locator('a', { hasText: 'Suivi' })).toHaveAttribute('href', row.tracking_url);
    await expect(zA.locator('[data-testid="pos-delivery-use-own"]')).toBeEnabled();
    await expect(zA.locator('[data-testid="pos-delivery-use-uber"]')).toHaveCount(0);
    await rec.snap('U2-confie-uber');
    const bj = await boardJson(); const bs = boardStop(bj, O.A.id);
    expect(bs.dispatch).toBe('uber'); expect(bs.uber.status).toBe('pending'); expect(bs.uber.fee).toBe(7.42); expect(bs.uber.bag_taken).toBe(false);
    // écran livreur : l'arrêt a disparu
    const apres = await livreurListe(O.A.serial);
    step('U-2', 'livreur', { avant, apres });
    check('U-2', 'écran livreur : l’arrêt A n’est plus listé (écran + API delivery-run)', !apres.ecran && !apres.api.includes(O.A.id), apres);
    expect(apres.ecran).toBe(false); expect(apres.api.includes(O.A.id)).toBe(false);
    await recL.snap('U2-livreur-sans-arret');
  });

  // ------------------------------------------------------------------------
  test('U-3 webhooks SIGNÉS pickup → pickup_complete → delivered : commande EN ROUTE puis LIVRÉE, caisse + suivi client', async ({ browser }) => {
    // suivi client ouvert AVANT (il interroge le serveur toutes les 20 s)
    await ouvrirSite(browser);
    await sp.goto('about:blank'); await sp.goto(`${SITE}/#orders`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await expect(sp.locator('#root .lc-app')).toBeVisible({ timeout: 60_000 });
    const carte = sp.locator('.lcx-order-card', { hasText: '#' + O.A.serial });
    await expect(carte, 'la commande A est dans « Mes commandes » du client').toBeVisible({ timeout: 30_000 });
    const carteTxt = (await carte.innerText()).replace(/\s+/g, ' ');
    await carte.getByRole('button', { name: /Suivre ma commande/ }).click();
    await expect(sp.locator('.lcf-track[data-mode="livraison"]')).toBeVisible({ timeout: 30_000 });
    await settle(sp, 1500);
    const t0 = await texteVisible(sp);
    step('U-3', 'site_avant', { carte: carteTxt.slice(0, 200), texte: t0.slice(0, 300) });
    expect(t0).toMatch(/Votre commande est en préparation/);
    expect(t0).not.toMatch(/Votre commande est en route|a été livrée/i);

    // pickup (signé)
    const w1 = await pushStatus(O.A.deliveryId, 'pickup');
    step('U-3', 'webhook_pickup', w1.body.webhook);
    expect(w1.body.webhook.http, 'webhook pickup signé → 200').toBe(200);
    expect(w1.body.webhook.signed_with_real_secret).toBe(true);
    expect(uberRow(O.A.id).status).toBe('pickup'); expect(Number(orderRow(O.A.id).status)).toBe(8);
    await planche();
    await expect(zone(O.A.stop.id).locator('[data-testid="pos-delivery-uber-badge"]')).toContainText('Coursier en route vers le restaurant');

    // pickup_complete (signé) → EN ROUTE
    const w2 = await pushStatus(O.A.deliveryId, 'pickup_complete');
    step('U-3', 'webhook_pickup_complete', w2.body.webhook);
    expect(w2.body.webhook.http).toBe(200);
    const r2 = uberRow(O.A.id); const s2 = stopOf(O.A.id); const o2 = orderRow(O.A.id);
    step('U-3', 'base_en_route', { row: r2, stop: s2, ord: o2 });
    expect(r2.status).toBe('pickup_complete');
    expect(Number(o2.status), 'commande EN ROUTE (10)').toBe(10);
    expect(s2.left_at, 'arrêt parti (left_at)').not.toBeNull();
    await planche();
    const zA = zone(O.A.stop.id); await zA.scrollIntoViewIfNeeded();
    await expect(zA.locator('[data-testid="pos-delivery-uber-badge"]')).toContainText('Coursier a la commande');
    await expect(zA.locator('[data-testid="pos-delivery-use-own"]')).toBeDisabled();
    await expect(zA.locator('[data-testid="pos-delivery-use-own"]')).toHaveAttribute('title', 'Le coursier Uber a déjà la commande');
    const ligne2 = await ligneTexte(O.A.serial);
    expect(ligne2).toMatch(/En route/);
    // Le départ du coursier Uber ne fait pas « partir » la tournée de NOS livreurs : livreur toujours affiché et désignable.
    const wd = O.A.stop.wave_at.slice(0, 10); const wt = O.A.stop.wave_at.slice(11, 16);
    const secA = board.locator(`[data-testid="pos-delivery-wave-${wd}-${wt}"]`);
    const etatTournee = (await secA.locator(`[data-testid="pos-delivery-wave-state-${wd}-${wt}"]`).innerText()).trim();
    step('U-3', 'tournee_apres_sac_pris', { etat: etatTournee, entete: (await secA.locator('header').innerText()).replace(/\s+/g, ' ').slice(0, 200) });
    expect(etatTournee, 'la tournée de nos livreurs n’est pas « partie » quand c’est Uber qui est parti').not.toBe('parti');
    await expect(secA, 'le livreur de la tournée reste affiché').toContainText(`Livreur : ${COURIER.name}`);
    await expect(secA.locator('select'), 'le livreur reste désignable').toHaveCount(1);
    await rec.snap('U3-en-route-planche');
    // tableau « Suivi commandes »
    trk = await posCtx.newPage();
    const recT = recorder(trk);
    await trk.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
    const cardA = trk.locator(`[data-testid="tracker-order-${O.A.id}"]`);
    await expect(cardA, 'commande A dans le tableau « Suivi commandes »').toBeVisible({ timeout: 45_000 });
    const colEnRoute = (await cardA.evaluate((e) => e.closest('.pos-tracker-col').querySelector('h2').innerText)).replace(/\s+/g, ' ').trim();
    step('U-3', 'tracker_en_route', colEnRoute);
    expect(colEnRoute, 'colonne « En livraison »').toMatch(/en livraison/i); // majuscules par CSS (text-transform)
    await cardA.scrollIntoViewIfNeeded();
    await recT.snap('U3-tracker-en-livraison', false);
    // suivi client : « en route »
    await sp.bringToFront();
    await sp.waitForFunction(() => /Votre commande est en route/i.test(document.body.innerText), null, { timeout: 60_000 });
    const etapes = await sp.evaluate(() => Array.from(document.querySelectorAll('.lcf-track-step')).map((e) => ({ l: e.innerText.replace(/\s+/g, ' ').trim(), c: e.className })));
    step('U-3', 'site_en_route', { etapes });
    expect(etapes.some((e) => /En route/i.test(e.l) && /is-current/.test(e.c)), 'étape « En route » courante sur le site').toBe(true);
    await recS.snap('U3-site-en-route');

    // delivered (signé) → LIVRÉE
    const w3 = await pushStatus(O.A.deliveryId, 'delivered');
    step('U-3', 'webhook_delivered', w3.body.webhook);
    expect(w3.body.webhook.http).toBe(200);
    const r3 = uberRow(O.A.id); const s3 = stopOf(O.A.id); const o3 = orderRow(O.A.id);
    step('U-3', 'base_livree', { row: r3, stop: s3, ord: o3, audits: audits(O.A.id), fiscal_unique: sql(`select count(*) from orders where branch_id=1 and fiscal_sequence_no=${Number(o3.fiscal_sequence_no) || -1}`)[0][0] });
    expect(r3.status).toBe('delivered');
    expect(Number(o3.status), 'commande LIVRÉE (13)').toBe(13);
    expect(s3.delivered_at).not.toBeNull(); expect(Number(s3.amount_collected_cents)).toBe(0);
    expect(o3.fiscal_sequence_no, 'numéro fiscal présent (payée au comptoir)').not.toBeNull();
    await planche();
    const ligne3 = await ligneTexte(O.A.serial);
    step('U-3', 'planche_livree', ligne3);
    expect(ligne3).toMatch(/Payé/); expect(ligne3).toMatch(/Remise \d{2}:\d{2}/);
    await expect(zone(O.A.stop.id)).toHaveCount(0); // plus de bouton Uber/livreur sur un arrêt remis
    await rec.snap('U3-planche-livree', false);
    await trk.reload(); await settle(trk, 1500);
    await expect(cardA).toBeVisible({ timeout: 45_000 });
    const colLivre = (await cardA.evaluate((e) => e.closest('.pos-tracker-col').querySelector('h2').innerText)).replace(/\s+/g, ' ').trim();
    step('U-3', 'tracker_livree', colLivre);
    expect(colLivre, 'colonne « Livrés »').toMatch(/livrés/i);
    await cardA.scrollIntoViewIfNeeded();
    await recT.snap('U3-tracker-livres');
    await sp.bringToFront();
    await sp.waitForFunction(() => /Votre commande a été livrée/i.test(document.body.innerText), null, { timeout: 60_000 });
    const etapes3 = await sp.evaluate(() => Array.from(document.querySelectorAll('.lcf-track-step')).map((e) => ({ l: e.innerText.replace(/\s+/g, ' ').trim(), c: e.className })));
    step('U-3', 'site_livree', { etapes: etapes3 });
    expect(etapes3.some((e) => /Livrée/i.test(e.l) && /is-current|is-done/.test(e.c)), 'étape « Livrée » atteinte sur le site').toBe(true);
    await recS.snap('U3-site-livree');
    L.bench.chain_apres_U3 = chainVerify();
    expect(L.bench.chain_apres_U3.ok, 'chaîne NF525 OK après la livraison Uber').toBe(true);
    await trk.close();
  });

  // ------------------------------------------------------------------------
  test('U-4 2ᵉ livraison : Confier → Confirmer → « Nos livreurs » avant la prise du sac : Uber reçoit cancel, arrêt rendu', async () => {
    await planche();
    const q = await confier(O.B.stop.id); expect(q.http).toBe(200);
    const d = await confirmerUber(O.B.stop.id); expect(d.http).toBe(200);
    const rowB = uberRow(O.B.id); O.B.deliveryId = rowB.delivery_id;
    expect(rowB.status).toBe('pending'); expect(stopOf(O.B.id).delivery_boy_id).toBeNull();
    const log0 = (await stubLog()).log.length;
    const rel = await nosLivreurs(O.B.stop.id);
    step('U-4', 'release', rel);
    expect(rel.http, 'POST uber/release').toBe(200);
    const calls = uberCalls(await stubLog(), log0);
    step('U-4', 'uber_calls', calls);
    expect(cancelPosts(calls, O.B.deliveryId).length, 'le faux Uber a reçu cancel').toBe(1);
    const row = uberRow(O.B.id); const stop = stopOf(O.B.id);
    step('U-4', 'base', { row, stop, audits: audits(O.B.id) });
    expect(row.status).toBe('canceled');
    expect(audits(O.B.id).some((a) => a.action === 'delivery.uber_released')).toBe(true);
    const zB = zone(O.B.stop.id); await zB.scrollIntoViewIfNeeded();
    await expect(zB.locator('[data-testid="pos-delivery-use-uber"]')).toBeEnabled();
    await expect(zB.locator('[data-testid="pos-delivery-uber-badge"]')).toHaveCount(0);
    await expect(zB).toContainText('Nos livreurs');
    // rendu : plusieurs livreurs actifs ⇒ pas de choix silencieux (delivery_boy_id null), le livreur se désigne depuis la planche.
    step('U-4', 'livreur_apres_rendu', { delivery_boy_id: stop.delivery_boy_id });
    const r = await api.post('/api/admin/pos-delivery/waves/assign', { data: { wave_at: O.B.stop.wave_at, delivery_boy_id: COURIER.id } });
    expect(r.status()).toBe(200);
    const stop2 = stopOf(O.B.id);
    expect(Number(stop2.delivery_boy_id), 'arrêt rendu : livreur désignable').toBe(COURIER.id);
    const liv = await livreurListe(O.B.serial);
    step('U-4', 'livreur', { stop2, liv });
    expect(liv.ecran && liv.api.includes(O.B.id), 'écran livreur : l’arrêt B est de nouveau listé').toBe(true);
    await planche(); await zone(O.B.stop.id).scrollIntoViewIfNeeded();
    await rec.snap('U4-rendu');
  });

  // ------------------------------------------------------------------------
  test('U-5 3ᵉ livraison : dispatch → pickup_complete → « Nos livreurs » refusé, aucune annulation envoyée', async () => {
    await planche();
    expect((await confier(O.C.stop.id)).http).toBe(200);
    expect((await confirmerUber(O.C.stop.id)).http).toBe(200);
    O.C.deliveryId = uberRow(O.C.id).delivery_id;
    const w = await pushStatus(O.C.deliveryId, 'pickup_complete'); expect(w.body.webhook.http).toBe(200);
    expect(Number(orderRow(O.C.id).status)).toBe(10);
    await planche();
    const zC = zone(O.C.stop.id); await zC.scrollIntoViewIfNeeded();
    const own = zC.locator('[data-testid="pos-delivery-use-own"]');
    await expect(own).toBeDisabled();
    const log0 = (await stubLog()).log.length;
    const net = []; const onReq = (r) => { if (/pos-delivery\/uber/.test(r.url())) net.push(r.url()); };
    page.on('request', onReq);
    await own.click({ force: true, timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(800);
    page.off('request', onReq);
    const rel = await apiRelease(O.C.stop.id);
    step('U-5', 'release_api', rel);
    expect(rel.http, 'release refusé (422)').toBe(422);
    expect(rel.body.message).toMatch(/Le coursier Uber a déjà la commande/);
    const calls = uberCalls(await stubLog(), log0);
    step('U-5', 'uber_calls', { calls, clics_bouton_grise: net });
    expect(cancelPosts(calls, O.C.deliveryId).length, 'aucune annulation envoyée à Uber').toBe(0);
    expect(net.length, 'un bouton grisé ne déclenche aucun appel').toBe(0);
    expect(uberRow(O.C.id).status).toBe('pickup_complete');
    await rec.snap('U5-refus-reprise', false);
    // la course se termine : LIVRÉE
    expect((await pushStatus(O.C.deliveryId, 'delivered')).body.webhook.http).toBe(200);
    expect(Number(orderRow(O.C.id).status)).toBe(13);
    // désigner un livreur à la tournée après coup ne touche pas les arrêts Uber (règle §6)
    const r = await api.post('/api/admin/pos-delivery/waves/assign', { data: { wave_at: O.C.stop.wave_at, delivery_boy_id: COURIER.id } });
    expect(r.status()).toBe(200);
    step('U-5', 'assign_apres', { C: stopOf(O.C.id).delivery_boy_id, A: stopOf(O.A.id).delivery_boy_id });
    expect(stopOf(O.C.id).delivery_boy_id, 'arrêt Uber C : toujours sans livreur à nous').toBeNull();
  });

  // ------------------------------------------------------------------------
  test('U-6 « Commande livraison » payée à la PORTE : bouton grisé avec la raison, AUCUN appel au faux Uber', async () => {
    const log0 = (await stubLog()).log.length;
    await planche();
    const zD = zone(O.D.stop.id); await zD.scrollIntoViewIfNeeded();
    const btn = zD.locator('[data-testid="pos-delivery-use-uber"]');
    await expect(btn).toBeDisabled();
    const raison = (await zD.locator('[data-testid="pos-delivery-uber-reason"]').innerText()).trim();
    expect(raison).toMatch(/À encaisser à la porte : Uber ne peut pas encaisser/);
    const ligne = await ligneTexte(O.D.serial);
    expect(ligne).toMatch(/Espèces \d+,\d{2} €/);
    const net = []; const onReq = (r) => { if (/pos-delivery\/uber/.test(r.url())) net.push(r.url()); };
    page.on('request', onReq);
    await btn.click({ force: true, timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(800);
    page.off('request', onReq);
    await rec.snap('U6-grise-porte');
    const q = await apiQuote(O.D.stop.id);
    const calls = uberCalls(await stubLog(), log0).filter((c) => !/\/deliveries\/del_/.test(c.path)); // relevés périodiques des courses actives exclus
    step('U-6', 'mesures', { raison, ligne, clics: net, api_quote: q, uber_calls: calls, row: uberRow(O.D.id) });
    expect(q.http, 'API quote refuse').toBe(422); expect(q.body.message).toMatch(/Uber ne peut pas encaisser/);
    expect(net.length).toBe(0);
    expect(calls.filter((c) => /delivery_quotes|deliveries$/.test(c.path)).length, 'aucun devis ni course demandé à Uber pour D').toBe(0);
    expect(uberRow(O.D.id)).toBeNull();
  });

  // ------------------------------------------------------------------------
  test('U-7 pannes : adresse non couverte, course non confirmée, double appui, devis périmé', async () => {
    // (a) devis « adresse non couverte »
    await stubMode({ quote: 'uncovered' });
    await planche();
    const log0 = (await stubLog()).log.length;
    const qa = await confier(O.E.stop.id);
    step('U-7', 'a_uncovered', qa);
    expect(qa.http).toBe(422);
    await expect(bandeau()).toContainText('Uber n\'a pas donné de prix');
    await expect(bandeau()).toContainText('La commande reste à nos livreurs');
    await expect(zone(O.E.stop.id).locator('[data-testid="pos-delivery-use-uber"]')).toBeEnabled();
    await expect(zone(O.E.stop.id).locator('[data-testid="pos-delivery-uber-price"]')).toHaveCount(0);
    await zone(O.E.stop.id).scrollIntoViewIfNeeded();
    await rec.snap('U7-panne-devis');
    let calls = uberCalls(await stubLog(), log0);
    expect(calls.some((c) => /delivery_quotes$/.test(c.path)), 'le devis a bien été demandé au faux Uber (qui a répondu 400)').toBe(true);
    expect(uberRow(O.E.id), 'aucune ligne uber_direct_deliveries sans devis').toBeNull();

    // (b) course non confirmée (500 à la création)
    await stubMode({ quote: 'ok', create: 'fail' });
    const log1 = (await stubLog()).log.length;
    const qb = await confier(O.E.stop.id); expect(qb.http).toBe(200);
    const db = await confirmerUber(O.E.stop.id);
    step('U-7', 'b_create_fail', db);
    expect(db.http).toBe(422);
    expect(db.body.message).toMatch(/Uber n'a pas confirmé la course — elle a peut-être été créée/);
    await expect(bandeau()).toContainText('Uber n\'a pas confirmé la course');
    calls = uberCalls(await stubLog(), log1);
    expect(deliveriesPosts(calls).length).toBe(1);
    const rowB = uberRow(O.E.id);
    step('U-7', 'b_base', rowB);
    expect(rowB.failure_code).toBe('uber_unconfirmed'); expect(rowB.delivery_id).toBeNull(); expect(Number(rowB.create_attempts)).toBe(1);
    await planche();
    const zE = zone(O.E.stop.id); await zE.scrollIntoViewIfNeeded();
    const alerte = board.locator('li', { hasText: O.E.serial }).locator('[data-testid="pos-delivery-uber-attention"]');
    await expect(alerte, 'la planche dit qu’Uber n’a pas confirmé (course peut-être créée)').toContainText('Uber n\'a pas confirmé la course');
    await expect(zE.locator('[data-testid="pos-delivery-use-uber"]')).toBeEnabled();
    await rec.snap('U7-create-fail', false);

    // (c) double appui « Confirmer » : UNE seule course (verrou + idempotency_key) — deux demandes simultanées sur le même devis
    await stubMode({ create: 'ok' });
    const qc = await apiQuote(O.E.stop.id); expect(qc.http).toBe(200);
    const log2 = (await stubLog()).log.length;
    const [d1, d2] = await Promise.all([apiDispatch(O.E.stop.id, qc.body.quote_id), apiDispatch(O.E.stop.id, qc.body.quote_id)]);
    calls = uberCalls(await stubLog(), log2);
    const rowC = uberRow(O.E.id);
    step('U-7', 'c_double', { d1, d2, deliveries_posts: deliveriesPosts(calls), row: rowC, lignes: sql(`select count(*) from uber_direct_deliveries where order_id=${O.E.id}`)[0][0] });
    expect([d1.http, d2.http].filter((h) => h === 200).length, 'au moins une des deux demandes aboutit').toBeGreaterThanOrEqual(1);
    expect(deliveriesPosts(calls).length, 'UNE seule création de course reçue par le faux Uber').toBe(1);
    expect(sql(`select count(*) from uber_direct_deliveries where order_id=${O.E.id}`)[0][0]).toBe('1');
    expect(rowC.delivery_id).toMatch(/^del_/); expect(rowC.status).toBe('pending'); expect(rowC.failure_code).toBeNull();
    O.E.deliveryId = rowC.delivery_id;
    await planche();
    await expect(zone(O.E.stop.id).locator('[data-testid="pos-delivery-uber-badge"]')).toBeVisible();
    await expect(board.locator('li', { hasText: O.E.serial }).locator('[data-testid="pos-delivery-uber-attention"]')).toHaveCount(0);

    // (d) devis périmé : nouveau prix à confirmer, pas de course
    const qd = await confier(O.F.stop.id); expect(qd.http).toBe(200);
    sql(`UPDATE uber_direct_deliveries SET quote_expires_at = NOW() - INTERVAL 1 MINUTE WHERE order_id=${O.F.id}`);
    const log3 = (await stubLog()).log.length;
    const dd = await confirmerUber(O.F.stop.id);
    step('U-7', 'd_expired', dd);
    expect(dd.http, 'dispatch sur devis périmé → 409').toBe(409);
    expect(dd.body.code).toBe('quote_changed'); expect(dd.body.quote.quote_id).not.toBe(qd.body.quote_id); expect(dd.body.quote.fee).toBe(7.42);
    await expect(bandeau()).toContainText('Le prix d\'Uber a changé');
    const zF = zone(O.F.stop.id); await zF.scrollIntoViewIfNeeded();
    await expect(zF.locator('[data-testid="pos-delivery-uber-price"]')).toContainText('7,42 €');
    await expect(zF.locator('[data-testid="pos-delivery-uber-confirm"]')).toBeVisible();
    await rec.snap('U7-devis-perime');
    calls = uberCalls(await stubLog(), log3);
    const rowF = uberRow(O.F.id);
    step('U-7', 'd_base', { calls, rowF });
    expect(deliveriesPosts(calls).length, 'aucune course créée sur un devis périmé').toBe(0);
    expect(calls.filter((c) => /delivery_quotes$/.test(c.path)).length, 'un nouveau devis a été demandé').toBe(1);
    expect(rowF.delivery_id).toBeNull(); expect(rowF.quote_id).toBe(dd.body.quote.quote_id);
    await zF.locator('[data-testid="pos-delivery-uber-cancel"]').click();
    await expect(zF.locator('[data-testid="pos-delivery-use-uber"]')).toBeEnabled();
  });

  // ------------------------------------------------------------------------
  test('U-8 webhook : signature fausse → 401 ; rejeu sans recul ; canceled avant le sac → rendu ; returned après le sac → alerte rouge', async () => {
    await planche();
    for (const k of ['G', 'H']) { expect((await confier(O[k].stop.id)).http).toBe(200); expect((await confirmerUber(O[k].stop.id)).http).toBe(200); O[k].deliveryId = uberRow(O[k].id).delivery_id; }
    // (a) signature fausse : 401, rien ne change — puis la MÊME charge signée passe (l'instrument mord)
    const faux = await pushWebhook(O.G.deliveryId, 'pickup', 'faux');
    const sansSig = await rq.post(`${BASE}/api/webhooks/uber-direct`, { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, data: { kind: 'event.delivery_status', status: 'pickup', delivery_id: O.G.deliveryId } });
    step('U-8', 'a_signature', { faux: faux.body.webhook, sans_signature_http: sansSig.status(), row: uberRow(O.G.id) });
    expect(faux.body.webhook.http, 'signature invalide → 401').toBe(401);
    expect(faux.body.webhook.signed_with_real_secret).toBe(false);
    expect(sansSig.status(), 'sans en-tête de signature → 401').toBe(401);
    expect(uberRow(O.G.id).status).toBe('pending');
    const vrai = await pushWebhook(O.G.deliveryId, 'pickup');
    expect(vrai.body.webhook.http, 'même événement signé → 200').toBe(200);
    expect(uberRow(O.G.id).status).toBe('pickup');
    // (b) pickup_complete puis rejeu de `pickup` : pas de recul
    const wpc = await pushStatus(O.G.deliveryId, 'pickup_complete'); expect(wpc.body.webhook.http).toBe(200);
    const sG = stopOf(O.G.id); const oG = orderRow(O.G.id);
    expect(uberRow(O.G.id).status).toBe('pickup_complete'); expect(sG.left_at).not.toBeNull();
    // Mesure (commande restée « Acceptée » en cuisine) : la commande suit-elle Uber vers EN ROUTE ?
    step('U-8', 'b_commande_acceptee_sac_pris', { statut_commande: oG.status, attendu_doc: 10, transition_journal: sql(`select count(*) from audit_logs where resource_id=${O.G.id} and action like '%uber%'`)[0][0] });
    const rejeu = await pushWebhook(O.G.deliveryId, 'pickup');
    expect(rejeu.body.webhook.http).toBe(200);
    const rowG = uberRow(O.G.id);
    step('U-8', 'b_rejeu', { row: rowG, stop: stopOf(O.G.id) });
    expect(rowG.status, 'un `pickup` rejoué ne fait pas reculer `pickup_complete`').toBe('pickup_complete');
    expect(stopOf(O.G.id).left_at).toBe(sG.left_at);
    // (c) canceled par Uber AVANT la prise du sac (H) → arrêt rendu à nos livreurs
    const wc = await pushStatus(O.H.deliveryId, 'canceled'); expect(wc.body.webhook.http).toBe(200);
    const rowH = uberRow(O.H.id); const sH = stopOf(O.H.id);
    step('U-8', 'c_canceled_avant_sac', { row: rowH, stop: sH });
    expect(rowH.status).toBe('canceled'); expect(rowH.failure_code).toBeNull(); expect(sH.left_at).toBeNull();
    await planche();
    const zH = zone(O.H.stop.id); await zH.scrollIntoViewIfNeeded();
    await expect(zH).toContainText('Nos livreurs');
    await expect(zH.locator('[data-testid="pos-delivery-use-uber"]')).toBeEnabled();
    await expect(board.locator('li', { hasText: O.H.serial }).locator('[data-testid="pos-delivery-uber-attention"]')).toHaveCount(0);
    const bH = boardStop(await boardJson(), O.H.id); expect(bH.dispatch).toBe('own'); expect(bH.uber_blocked).toBeNull();
    const r = await api.post('/api/admin/pos-delivery/waves/assign', { data: { wave_at: O.H.stop.wave_at, delivery_boy_id: COURIER.id } }); expect(r.status()).toBe(200);
    expect(Number(stopOf(O.H.id).delivery_boy_id)).toBe(COURIER.id);
    await rec.snap('U8-canceled-rendu', false);
    // (c') mesure, sans verdict : re-confier H à Uber après SON annulation — même idempotency_key ⇒ Uber (fidèle) renvoie la course annulée
    const q2 = await apiQuote(O.H.stop.id); const d2 = q2.http === 200 ? await apiDispatch(O.H.stop.id, q2.body.quote_id) : null;
    step('U-8', 'c2_redispatch_apres_annulation_uber', { quote: q2, dispatch: d2, row: uberRow(O.H.id), board: boardStop(await boardJson(), O.H.id) });
    // (d) returned APRÈS la prise du sac (G) → alerte rouge, commande non livrée
    const wr = await pushStatus(O.G.deliveryId, 'returned'); expect(wr.body.webhook.http).toBe(200);
    const rowR = uberRow(O.G.id); const oR = orderRow(O.G.id);
    step('U-8', 'd_returned_apres_sac', { row: rowR, ord: oR });
    expect(rowR.status).toBe('returned'); expect(rowR.failure_code).toBe('uber_returned_after_pickup');
    expect(Number(oR.status), 'commande NON livrée').not.toBe(13);
    await planche();
    const alerte = board.locator('li', { hasText: O.G.serial }).locator('[data-testid="pos-delivery-uber-attention"]');
    await alerte.scrollIntoViewIfNeeded();
    await expect(alerte).toContainText('Uber a ramené la commande au restaurant alors que le coursier avait le sac');
    await expect(alerte).toContainText('la commande n\'est pas livrée');
    const bG = boardStop(await boardJson(), O.G.id);
    step('U-8', 'd_board', bG);
    expect(bG.uber_attention).toMatch(/ramené la commande/);
    await rec.snap('U8-alerte-rouge');
  });

  // ------------------------------------------------------------------------
  test('U-9 commande annulée à la caisse pendant une course active : cancel chez Uber AVANT la disparition de l’arrêt', async () => {
    // (a) Uber confirme : la course est annulée, puis l'arrêt disparaît
    await planche();
    expect((await confier(O.I.stop.id)).http).toBe(200); expect((await confirmerUber(O.I.stop.id)).http).toBe(200);
    O.I.deliveryId = uberRow(O.I.id).delivery_id;
    const log0 = (await stubLog()).log.length;
    const c = await cancelOrder(O.I.id, `${TAG}-U9 annulation pendant course`);
    step('U-9', 'a_cancel_order', c);
    expect(c.http, 'annulation de la commande (admin)').toBeLessThan(300);
    const calls = uberCalls(await stubLog(), log0);
    const rowI = uberRow(O.I.id); const sI = stopOf(O.I.id); const oI = orderRow(O.I.id);
    step('U-9', 'a_base', { calls, row: rowI, stop: sI, ord: oI });
    expect(Number(oI.status)).toBe(16);
    expect(cancelPosts(calls, O.I.deliveryId).length, 'le faux Uber a reçu cancel').toBe(1);
    expect(rowI.status).toBe('canceled'); expect(rowI.failure_code).toBeNull();
    expect(sI, 'arrêt disparu (course annulée d’abord)').toBeNull();
    await planche();
    await expect(board.locator('li', { hasText: O.I.serial })).toHaveCount(0);
    await rec.snap('U9-annulee-disparue', false);

    // (b) Uber REFUSE l'annulation : l'arrêt RESTE visible avec la raison (jamais une course facturée sans trace)
    await stubMode({ cancel: 'refuse' });
    const log1 = (await stubLog()).log.length;
    const c2 = await cancelOrder(O.E.id, `${TAG}-U9 annulation, Uber refuse`);
    expect(c2.http).toBeLessThan(300);
    const calls2 = uberCalls(await stubLog(), log1);
    const rowE = uberRow(O.E.id); const sE = stopOf(O.E.id);
    step('U-9', 'b_refus', { calls: calls2, row: rowE, stop: sE, ord: orderRow(O.E.id) });
    expect(cancelPosts(calls2, O.E.deliveryId).length).toBeGreaterThanOrEqual(1);
    expect(calls2.some((cc) => cc.method === 'GET' && cc.path.endsWith('/deliveries/' + O.E.deliveryId)), 'l’état de la course est relu avant de conclure').toBe(true);
    expect(rowE.failure_code).toBe('order_closed_cancel_failed'); expect(rowE.status).toBe('pending');
    expect(sE, 'l’arrêt reste (Uber n’a pas confirmé)').not.toBeNull();
    await planche();
    const alerte = board.locator('li', { hasText: O.E.serial }).locator('[data-testid="pos-delivery-uber-attention"]');
    await alerte.scrollIntoViewIfNeeded();
    await expect(alerte).toContainText('Uber n\'a pas confirmé l\'annulation de la course');
    await rec.snap('U9-annulation-non-confirmee');
    // (c) rattrapage : Uber accepte de nouveau → le cron libère l'arrêt
    await stubMode({ cancel: 'ok' });
    const log2 = (await stubLog()).log.length;
    const out = artisan(['delivery:assign-pending']);
    const calls3 = uberCalls(await stubLog(), log2);
    step('U-9', 'c_rattrapage', { out: out.slice(-200), calls: calls3, row: uberRow(O.E.id), stop: stopOf(O.E.id) });
    expect(cancelPosts(calls3, O.E.deliveryId).length).toBe(1);
    expect(uberRow(O.E.id).status).toBe('canceled');
    expect(stopOf(O.E.id)).toBeNull();
    await planche();
    await expect(board.locator('li', { hasText: O.E.serial })).toHaveCount(0);
  });

  // ------------------------------------------------------------------------
  test('U-R réseau : aucune requête vers la production ni le vrai Uber ; webhooks du banc tous journalisés', async () => {
    const log = await stubLog();
    L.bench.webhooks = log.webhooks;
    step('U-R', 'prod_bloquees', L.prodBloquees);
    expect(L.prodBloquees, 'aucune requête vers la production / Uber réel').toEqual([]);
    expect(log.webhooks.filter((w) => w.signed_with_real_secret && w.http !== 200).length, 'tous les webhooks signés ont été acceptés (200)').toBe(0);
    expect(log.webhooks.filter((w) => !w.signed_with_real_secret).every((w) => w.http === 401), 'tous les webhooks mal signés refusés (401)').toBe(true);
    write();
  });
});
